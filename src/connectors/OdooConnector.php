<?php

namespace justinholtweb\erpyodoo\connectors;

use Craft;
use DateTime;
use DateTimeInterface;
use justinholtweb\erpy\auth\NoAuth;
use justinholtweb\erpy\base\AuthInterface;
use justinholtweb\erpy\base\Capabilities;
use justinholtweb\erpy\base\Connector;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\FetchCriteria;
use justinholtweb\erpy\base\Field;
use justinholtweb\erpy\base\HealthResult;
use justinholtweb\erpy\base\Page;
use justinholtweb\erpy\base\PushResult;
use justinholtweb\erpy\base\Transport;
use justinholtweb\erpy\models\canonical\ErpAddress;
use justinholtweb\erpy\models\canonical\ErpCredit;
use justinholtweb\erpy\models\canonical\ErpCustomer;
use justinholtweb\erpy\models\canonical\ErpInvoice;
use justinholtweb\erpy\models\canonical\ErpOrder;
use justinholtweb\erpy\models\canonical\ErpOrderStatus;
use justinholtweb\erpy\models\canonical\ErpPrice;
use justinholtweb\erpy\models\canonical\ErpProduct;
use justinholtweb\erpy\models\canonical\ErpShipment;
use justinholtweb\erpy\models\canonical\ErpStock;

/**
 * Odoo, through the external JSON-RPC API.
 *
 * Odoo is not a REST API and pretending otherwise is where most integrations go wrong. There are
 * no endpoints, only models and methods: everything here is `search_read` against a model with a
 * domain, or `create` on one. Two consequences worth knowing:
 *
 * **Errors arrive as HTTP 200.** A missing model, a permission failure and a Python traceback all
 * come back with a success status and an `error` object in the body, so this connector checks the
 * body rather than the status — a transport that only looked at the status would report every
 * failure as a silent empty page.
 *
 * **Relations arrive as `[id, "Display Name"]`.** A many-to-one field is a two-element array, not
 * a scalar, and reading it as a string gives you "Array".
 */
class OdooConnector extends Connector
{
    /** Odoo's session, established once per run. */
    private ?int $uid = null;

    public static function handle(): string
    {
        return 'odoo';
    }

    public static function displayName(): string
    {
        return 'Odoo';
    }

    public static function vendor(): string
    {
        return 'Odoo';
    }

    public static function description(): string
    {
        return 'Odoo Community or Enterprise through the external JSON-RPC API, using an API key rather than a password.';
    }

    public static function setupUrl(): ?string
    {
        return 'https://www.odoo.com/documentation/master/developer/reference/external_api.html';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            // `write_date` is indexed on every Odoo model, which makes delta syncing cheap and
            // reliable here in a way it is not on several of the older ERPs.
            ->supports(Entity::CUSTOMER, Direction::PULL, delta: true, pageSize: 200)
            ->supports(Entity::PRODUCT, Direction::PULL, delta: true, pageSize: 200)
            ->supports(Entity::PRICE, Direction::PULL, delta: true, pageSize: 200)
            ->supports(Entity::INVENTORY, Direction::PULL, delta: true, pageSize: 500)
            ->supports(Entity::ORDER, Direction::PUSH)
            ->supports(Entity::ORDER_STATUS, Direction::PULL, delta: true, pageSize: 200)
            ->supports(Entity::SHIPMENT, Direction::PULL, delta: true, pageSize: 200)
            ->supports(Entity::INVOICE, Direction::PULL, delta: true, pageSize: 200)
            ->supports(Entity::CREDIT, Direction::PULL, pageSize: 200)
            ->withMultiCompany()
            ->withSandbox();
    }

    public static function settingsFields(): array
    {
        return [
            Field::url('url', Craft::t('erpy', 'Odoo URL'), [
                'required' => true,
                'placeholder' => 'https://example.odoo.com',
            ]),
            Field::text('database', Craft::t('erpy', 'Database'), [
                'required' => true,
                'instructions' => Craft::t('erpy', 'On Odoo Online this is usually the subdomain.'),
            ]),
            Field::text('username', Craft::t('erpy', 'Login'), [
                'required' => true,
                'instructions' => Craft::t('erpy', 'The email address of a dedicated integration user.'),
            ]),
            Field::secret('apiKey', Craft::t('erpy', 'API key'), [
                'required' => true,
                'instructions' => Craft::t('erpy', 'Generate one under the user’s Preferences → Account Security. An API key works everywhere a password does and can be revoked on its own.'),
            ]),

            Field::heading(Craft::t('erpy', 'Behaviour')),
            Field::text('warehouseId', Craft::t('erpy', 'Warehouse ID'), [
                'instructions' => Craft::t('erpy', 'The numeric id of the stock location stock is read from. Leave blank for all internal locations.'),
            ]),
            Field::text('pricelistId', Craft::t('erpy', 'Base pricelist ID'), [
                'instructions' => Craft::t('erpy', 'Usually your public pricelist. Its items are left out of contract pricing — the Commerce base price comes from each product’s Sales Price. Every other pricelist becomes contract pricing.'),
            ]),
            Field::text('salesTeamId', Craft::t('erpy', 'Sales team ID'), [
                'instructions' => Craft::t('erpy', 'Optional. Web orders are often given their own team so they can be reported on separately.'),
            ]),
            Field::boolean('confirmOrders', Craft::t('erpy', 'Confirm orders on arrival'), [
                'instructions' => Craft::t('erpy', 'Off leaves them as quotations for someone to review. On confirms them, which reserves stock immediately.'),
                'default' => false,
            ]),
        ];
    }

    protected function buildAuth(): ?AuthInterface
    {
        // Odoo authenticates inside the RPC payload rather than in a header, so there is no
        // strategy that could express it.
        return new NoAuth();
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri(rtrim((string)$this->setting('url'), '/'))
            ->setDefaultHeaders(['Content-Type' => 'application/json'])
            ->setRateLimit(6)
            ->setTimeout(120);
    }

    protected function probe(): HealthResult
    {
        $uid = $this->authenticate();

        if ($uid === null) {
            return HealthResult::fail(
                Craft::t('erpy', 'Odoo would not accept those credentials.'),
                [
                    Craft::t('erpy', 'Check the database name — Odoo answers a wrong database exactly as it answers a wrong password.'),
                    Craft::t('erpy', 'Use an API key rather than the account password; some Odoo Online instances refuse password logins over RPC entirely.'),
                ],
            );
        }

        $version = $this->rpc('common', 'version', []);
        $products = $this->count('product.product', [['sale_ok', '=', true]]);

        return HealthResult::pass(Craft::t('erpy', 'Connected to Odoo.'), [
            Craft::t('erpy', 'Database') => (string)$this->setting('database'),
            Craft::t('erpy', 'Version') => (string)($version['server_version'] ?? '—'),
            Craft::t('erpy', 'Sellable products') => (string)$products,
            Craft::t('erpy', 'User ID') => (string)$uid,
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // Pull
    // ---------------------------------------------------------------------------------------

    protected function fetchProducts(FetchCriteria $criteria): Page
    {
        $fields = [
            'id', 'default_code', 'name', 'description_sale', 'active', 'sale_ok', 'type',
            'list_price', 'standard_price', 'weight', 'volume', 'barcode', 'uom_id',
            'categ_id', 'write_date', 'product_template_attribute_value_ids',
        ];

        return $this->searchPage('product.product', $fields, [['sale_ok', '=', true]], $criteria, Entity::PRODUCT, function(array $row): ErpProduct {
            return new ErpProduct([
                // `default_code` is Odoo's internal reference and the only field that behaves
                // like a SKU. A product without one cannot be matched to Commerce at all.
                'sku' => (string)($row['default_code'] ?: ''),
                'name' => (string)($row['name'] ?? ''),
                'description' => $row['description_sale'] ?: null,
                'enabled' => !empty($row['active']) && !empty($row['sale_ok']),
                'blocked' => empty($row['active']),
                'category' => $this->relationName($row['categ_id'] ?? null),
                'unitOfMeasure' => $this->relationName($row['uom_id'] ?? null),
                'price' => isset($row['list_price']) ? (float)$row['list_price'] : null,
                'cost' => isset($row['standard_price']) ? (float)$row['standard_price'] : null,
                'weight' => isset($row['weight']) ? (float)$row['weight'] : null,
                'weightUnit' => 'kg',
                'barcode' => $row['barcode'] ?: null,
                // Odoo's `type` is `product` (storable), `consu` (consumable) or `service`; only
                // storable products have stock worth reading.
                'tracksInventory' => (string)($row['type'] ?? '') === 'product',
                'remoteId' => (string)($row['id'] ?? ''),
                'remoteKey' => (string)($row['default_code'] ?: $row['id'] ?? ''),
                'modifiedAt' => $this->date($row['write_date'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchInventory(FetchCriteria $criteria): Page
    {
        $domain = [['location_id.usage', '=', 'internal']];
        $warehouse = (string)$this->setting('warehouseId', '');

        if ($warehouse !== '') {
            $domain[] = ['location_id', 'child_of', (int)$warehouse];
        }

        // `stock.quant` is the physical stock table. `available_quantity` is Odoo's own figure
        // for what is not already reserved for somebody else's picking, which is the number a
        // storefront should be showing.
        return $this->searchPage('stock.quant', [
            'id', 'product_id', 'quantity', 'reserved_quantity', 'available_quantity',
            'location_id', 'write_date',
        ], $domain, $criteria, Entity::INVENTORY, function(array $row): ErpStock {
            return new ErpStock([
                'sku' => $this->productCode($row['product_id'] ?? null),
                'warehouse' => $this->relationName($row['location_id'] ?? null),
                'onHand' => (float)($row['quantity'] ?? 0),
                'allocated' => (float)($row['reserved_quantity'] ?? 0),
                'available' => isset($row['available_quantity']) ? (float)$row['available_quantity'] : null,
                'remoteId' => (string)($row['id'] ?? ''),
                'modifiedAt' => $this->date($row['write_date'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchPrices(FetchCriteria $criteria): Page
    {
        $basePricelist = (string)$this->setting('pricelistId', '');

        // Odoo pricelist items express themselves three ways: a fixed price, a percentage off, or
        // a formula. Only two can be honoured without re-implementing Odoo's pricing engine:
        //
        // - `fixed` carries its own price.
        // - `percentage` is honoured only when it is a percentage off the product's Sales Price
        //   (`base = list_price`), because that is the price the product sync writes to Commerce
        //   and the one Erpy discounts at cart time. A percentage off cost or off another
        //   pricelist has no Commerce equivalent.
        //
        // Everything else — every `formula` rule, and a percentage on any other base — is left
        // out in the domain rather than skipped per row. Skipping per row would emit a price of
        // 0 (Odoo's `fixed_price` on a non-fixed rule) that becomes a zero contract price at
        // checkout, and filtering in PHP could leave a whole page empty, which ends the run.
        $domain = [
            ['product_id', '!=', false],
            '|',
            ['compute_price', '=', 'fixed'],
            '&',
            ['compute_price', '=', 'percentage'],
            ['base', '=', 'list_price'],
        ];

        if ($basePricelist !== '') {
            // The public pricelist's prices already reach Commerce as each product's Sales
            // Price, so its items are not contract pricing.
            $domain[] = ['pricelist_id', '!=', (int)$basePricelist];
        }

        return $this->searchPage('product.pricelist.item', [
            'id', 'product_id', 'pricelist_id', 'min_quantity', 'fixed_price', 'base',
            'percent_price', 'compute_price', 'date_start', 'date_end', 'currency_id', 'write_date',
        ], $domain, $criteria, Entity::PRICE, function(array $row): ErpPrice {
            // A percentage row carries a unit price of 0 and a discount; Erpy reads that pair as
            // "this much off the catalogue price", never as a price of 0.
            $isPercentage = (string)($row['compute_price'] ?? '') === 'percentage';

            return new ErpPrice([
                'sku' => $this->productCode($row['product_id'] ?? null),
                'priceListCode' => (string)$this->relationId($row['pricelist_id'] ?? null),
                'currency' => $this->relationName($row['currency_id'] ?? null),
                'unitPrice' => $isPercentage ? 0.0 : (float)($row['fixed_price'] ?? 0),
                'discountPercent' => $isPercentage ? (float)($row['percent_price'] ?? 0) : null,
                'minQuantity' => (float)($row['min_quantity'] ?? 0) ?: 1.0,
                'startsAt' => $this->date($row['date_start'] ?? null),
                'endsAt' => $this->date($row['date_end'] ?? null),
                'remoteId' => (string)($row['id'] ?? ''),
                'modifiedAt' => $this->date($row['write_date'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchCustomers(FetchCriteria $criteria): Page
    {
        return $this->searchPage('res.partner', [
            'id', 'ref', 'name', 'email', 'phone', 'website', 'active', 'vat',
            'property_product_pricelist', 'property_payment_term_id', 'credit_limit',
            'credit', 'customer_rank', 'street', 'street2', 'city', 'state_id', 'zip',
            'country_code', 'category_id', 'write_date',
        ], [['customer_rank', '>', 0]], $criteria, Entity::CUSTOMER, function(array $row): ErpCustomer {
            $address = new ErpAddress([
                'type' => ErpAddress::TYPE_BILLING,
                'fullName' => (string)($row['name'] ?? ''),
                'addressLine1' => $row['street'] ?: null,
                'addressLine2' => $row['street2'] ?: null,
                'locality' => $row['city'] ?: null,
                'administrativeArea' => $this->relationName($row['state_id'] ?? null),
                'postalCode' => $row['zip'] ?: null,
                'countryCode' => $row['country_code'] ?: null,
                'isDefault' => true,
            ]);

            return new ErpCustomer([
                'code' => $this->partnerCode($row),
                'name' => (string)($row['name'] ?? ''),
                'email' => $row['email'] ?: null,
                'phone' => $row['phone'] ?: null,
                'website' => $row['website'] ?: null,
                'enabled' => !empty($row['active']),
                'taxId' => $row['vat'] ?: null,
                'priceListCode' => (string)$this->relationId($row['property_product_pricelist'] ?? null) ?: null,
                'paymentTermsCode' => (string)$this->relationId($row['property_payment_term_id'] ?? null) ?: null,
                'creditLimit' => isset($row['credit_limit']) ? (float)$row['credit_limit'] : null,
                'balance' => isset($row['credit']) ? (float)$row['credit'] : null,
                'addresses' => $address->isEmpty() ? [] : [$address],
                'remoteId' => (string)($row['id'] ?? ''),
                'remoteKey' => $this->partnerRef($row) ?? '',
                'modifiedAt' => $this->date($row['write_date'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchCredit(FetchCriteria $criteria): Page
    {
        return $this->searchPage('res.partner', [
            'id', 'ref', 'credit_limit', 'credit', 'currency_id', 'property_payment_term_id',
        ], [['customer_rank', '>', 0]], $criteria, Entity::CREDIT, function(array $row): ErpCredit {
            return new ErpCredit([
                'customerCode' => $this->partnerCode($row),
                'currency' => (string)($this->relationName($row['currency_id'] ?? null) ?: 'USD'),
                // Odoo stores 0 for "no limit", which is not at all the same as a limit of zero.
                'creditLimit' => ((float)($row['credit_limit'] ?? 0)) > 0 ? (float)$row['credit_limit'] : null,
                'balance' => (float)($row['credit'] ?? 0),
                'paymentTermsCode' => (string)$this->relationId($row['property_payment_term_id'] ?? null) ?: null,
                'raw' => $row,
            ]);
            // No delta: `credit` is computed from journal items, so a partner's balance moves
            // without its `write_date` ever changing. A watermark here would miss every payment.
        }, delta: false);
    }

    protected function fetchOrderStatuses(FetchCriteria $criteria): Page
    {
        return $this->searchPage('sale.order', [
            'id', 'name', 'client_order_ref', 'state', 'delivery_status', 'invoice_status', 'write_date',
        ], [['client_order_ref', '!=', false]], $criteria, Entity::ORDER_STATUS, function(array $row): ErpOrderStatus {
            $state = (string)($row['state'] ?? '');
            $delivery = (string)($row['delivery_status'] ?? '');

            return new ErpOrderStatus([
                'orderNumber' => (string)($row['client_order_ref'] ?? ''),
                'status' => $state,
                'statusCode' => $state,
                'isCancelled' => $state === 'cancel',
                'isOnHold' => in_array($state, ['draft', 'sent'], true),
                'isPicking' => $state === 'sale' && $delivery !== 'full',
                'isShipped' => $delivery === 'full',
                'isPartiallyShipped' => $delivery === 'partial',
                'isInvoiced' => (string)($row['invoice_status'] ?? '') === 'invoiced',
                'isClosed' => $state === 'done',
                'remoteId' => (string)($row['id'] ?? ''),
                'remoteKey' => (string)($row['name'] ?? ''),
                'modifiedAt' => $this->date($row['write_date'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchShipments(FetchCriteria $criteria): Page
    {
        return $this->searchPage('stock.picking', [
            'id', 'name', 'origin', 'state', 'date_done', 'carrier_id',
            'carrier_tracking_ref', 'sale_id', 'write_date',
        ], [['state', '=', 'done'], ['picking_type_code', '=', 'outgoing']], $criteria, Entity::SHIPMENT, function(array $row): ErpShipment {
            // `origin` carries the sales order name; the Commerce order number is on the order
            // itself, so it is resolved rather than guessed at.
            $orderNumber = $this->orderReferenceFor($row['sale_id'] ?? null);

            return new ErpShipment([
                'orderNumber' => $orderNumber ?? (string)($row['origin'] ?? ''),
                'shipmentNumber' => (string)($row['name'] ?? ''),
                'trackingNumber' => $row['carrier_tracking_ref'] ?: null,
                'carrier' => $this->relationName($row['carrier_id'] ?? null),
                'shippedAt' => $this->date($row['date_done'] ?? null),
                'remoteId' => (string)($row['id'] ?? ''),
                'modifiedAt' => $this->date($row['write_date'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchInvoices(FetchCriteria $criteria): Page
    {
        return $this->searchPage('account.move', [
            'id', 'name', 'invoice_origin', 'partner_id', 'invoice_date', 'invoice_date_due',
            'amount_untaxed', 'amount_tax', 'amount_total', 'amount_residual', 'currency_id',
            'state', 'payment_state', 'move_type', 'write_date',
        ], [['move_type', 'in', ['out_invoice', 'out_refund']], ['state', '=', 'posted']], $criteria, Entity::INVOICE, function(array $row): ErpInvoice {
            $total = (float)($row['amount_total'] ?? 0);
            $residual = (float)($row['amount_residual'] ?? 0);

            return new ErpInvoice([
                'invoiceNumber' => (string)($row['name'] ?? ''),
                'orderNumber' => (string)($row['invoice_origin'] ?? ''),
                // The partner's reference, not its id, so an invoice lands on the same account
                // the customer sync created.
                'customerCode' => $this->partnerCodeFor($row['partner_id'] ?? null),
                'issuedAt' => $this->date($row['invoice_date'] ?? null),
                'dueAt' => $this->date($row['invoice_date_due'] ?? null),
                'currency' => (string)($this->relationName($row['currency_id'] ?? null) ?: 'USD'),
                'subtotal' => (float)($row['amount_untaxed'] ?? 0),
                'taxTotal' => (float)($row['amount_tax'] ?? 0),
                'total' => $total,
                'balance' => $residual,
                'amountPaid' => $total - $residual,
                'isPaid' => (string)($row['payment_state'] ?? '') === 'paid',
                'isCreditNote' => (string)($row['move_type'] ?? '') === 'out_refund',
                'status' => (string)($row['state'] ?? ''),
                'remoteId' => (string)($row['id'] ?? ''),
                'modifiedAt' => $this->date($row['write_date'] ?? null),
                'raw' => $row,
            ]);
        }, fn(array $rows) => $this->primePartnerCodes(array_map(
            fn($row) => is_array($row) ? $this->relationId($row['partner_id'] ?? null) : null,
            $rows,
        )));
    }

    // ---------------------------------------------------------------------------------------
    // Push
    // ---------------------------------------------------------------------------------------

    protected function pushOrder(ErpOrder $document, ?string $remoteId = null): PushResult
    {
        try {
            return $this->createOrder($document, $remoteId);
        } catch (\Throwable $e) {
            // A lookup that fails mid-push must come back as a result the queue can classify,
            // not as an exception that reaches the merchant as an unexplained job failure. A 4xx
            // means Odoo understood us and refused; anything else is worth another go.
            $refused = $this->lastStatus >= 400
                && $this->lastStatus < 500
                && !in_array($this->lastStatus, [408, 429], true);

            return $refused
                ? PushResult::rejected($e->getMessage())
                : PushResult::failed($e->getMessage());
        }
    }

    private function createOrder(ErpOrder $document, ?string $remoteId = null): PushResult
    {
        $partnerId = $this->resolvePartner($document);

        if ($partnerId === null) {
            return PushResult::rejected(Craft::t('erpy', 'No Odoo partner matches this order’s customer, and Odoo will not take an order without one.'));
        }

        // `client_order_ref` carries the Commerce order number, so a retried job can ask before
        // it creates rather than leaving two quotations behind. Each row is compared as well as
        // searched for: a domain a custom module or a proxy mis-applies must not make every
        // order after the first look delivered.
        if ($remoteId === null) {
            $existing = $this->searchRead('sale.order', [['client_order_ref', '=', $document->orderNumber]], ['id', 'name', 'client_order_ref'], 20);

            foreach ($existing as $row) {
                if (is_array($row) && (string)($row['client_order_ref'] ?? '') === $document->orderNumber) {
                    return PushResult::alreadyExists((string)($row['id'] ?? ''), (string)($row['name'] ?? ''));
                }
            }
        }

        $lines = [];

        foreach ($document->lines as $line) {
            $productId = $this->resolveProduct($line->sku);

            if ($productId === null) {
                return PushResult::rejected(Craft::t('erpy', 'No Odoo product has the internal reference “{sku}”.', ['sku' => $line->sku]));
            }

            // Odoo's one-to-many write format: (0, 0, values) means "create a new line".
            $lines[] = [0, 0, array_filter([
                'product_id' => $productId,
                'product_uom_qty' => $line->quantity,
                'price_unit' => $line->unitPrice,
                'discount' => $line->discountPercent,
                'name' => $line->description,
            ], static fn($value) => $value !== null && $value !== '')];
        }

        $values = array_filter([
            'partner_id' => $partnerId,
            'client_order_ref' => $document->orderNumber,
            'date_order' => ($document->orderedAt ?? new DateTime())->format('Y-m-d H:i:s'),
            'note' => $document->customerNote,
            'team_id' => $this->setting('salesTeamId') ? (int)$this->setting('salesTeamId') : null,
            'order_line' => $lines,
        ], static fn($value) => $value !== null && $value !== '');

        $id = $this->rpc('object', 'execute_kw', [
            $this->setting('database'),
            $this->authenticate(),
            $this->setting('apiKey'),
            'sale.order',
            'create',
            [$values],
        ]);

        if (!is_int($id) && !is_numeric($id)) {
            return PushResult::rejected($this->lastError ?? Craft::t('erpy', 'Odoo refused the order.'));
        }

        if ($this->boolSetting('confirmOrders')) {
            // Confirming reserves stock, which is why it is opt-in: a merchant who confirms
            // automatically has decided that a web order is as good as a signed one.
            $this->rpc('object', 'execute_kw', [
                $this->setting('database'),
                $this->authenticate(),
                $this->setting('apiKey'),
                'sale.order',
                'action_confirm',
                [[(int)$id]],
            ]);
        }

        $created = $this->searchRead('sale.order', [['id', '=', (int)$id]], ['name'], 1);

        return PushResult::ok((string)$id, (string)($created[0]['name'] ?? ''), ['id' => $id]);
    }

    private function resolvePartner(ErpOrder $document): ?int
    {
        if ($document->customerRemoteId !== null && is_numeric($document->customerRemoteId)) {
            return (int)$document->customerRemoteId;
        }

        if ($document->customerCode) {
            $found = $this->findPartnerByCode($document->customerCode);

            if ($found !== null) {
                return $found;
            }
        }

        if ($document->email) {
            $found = $this->searchRead('res.partner', [['email', '=', $document->email]], ['id'], 1);

            if ($found !== []) {
                return (int)$found[0]['id'];
            }
        }

        return null;
    }

    /**
     * The partner a customer code names — the inverse of {@see partnerCode()}.
     *
     * A code is a partner's `ref`, or its id when it has none, so the lookup asks the same two
     * questions in the same order. The id fallback only matches a partner with no reference:
     * one that has a reference is not called by its id anywhere else, and "42" naming partner 42
     * when partner 42 is known everywhere as "ACME" would put the order on the wrong account.
     */
    private function findPartnerByCode(string $code): ?int
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        $found = $this->searchRead('res.partner', [['ref', '=', $code]], ['id'], 1);

        if ($found !== []) {
            return (int)$found[0]['id'];
        }

        if (ctype_digit($code)) {
            $found = $this->searchRead('res.partner', [['id', '=', (int)$code], ['ref', '=', false]], ['id'], 1);

            if ($found !== []) {
                return (int)$found[0]['id'];
            }
        }

        return null;
    }

    /** @var array<string,int|null> */
    private array $productCache = [];

    private function resolveProduct(string $sku): ?int
    {
        if (array_key_exists($sku, $this->productCache)) {
            return $this->productCache[$sku];
        }

        $found = $this->searchRead('product.product', [['default_code', '=', $sku]], ['id'], 1);

        return $this->productCache[$sku] = $found !== [] ? (int)$found[0]['id'] : null;
    }

    private function orderReferenceFor(mixed $saleId): ?string
    {
        $id = $this->relationId($saleId);

        if ($id === null) {
            return null;
        }

        $found = $this->searchRead('sale.order', [['id', '=', (int)$id]], ['client_order_ref'], 1);

        return $found !== [] ? ((string)($found[0]['client_order_ref'] ?: '') ?: null) : null;
    }

    // ---------------------------------------------------------------------------------------
    // RPC
    // ---------------------------------------------------------------------------------------

    private ?string $lastError = null;

    /** The HTTP status of the last RPC, which is the only thing that separates "the ERP said no"
     *  from "the ERP was not there". Odoo's own errors all arrive as 200. */
    private int $lastStatus = 0;

    /**
     * @param callable|null $prime called with the page's raw rows before any is mapped, so a
     *     lookup the mapper needs can be made once per page rather than once per row
     * @param bool $delta false for an entity whose capabilities declare no delta, so the
     *     watermark is never applied to it
     */
    private function searchPage(string $model, array $fields, array $domain, FetchCriteria $criteria, string $entity, callable $make, ?callable $prime = null, bool $delta = true): Page
    {
        $limit = $this->pageSize($entity, $criteria);
        $offset = (int)($criteria->cursor ?? 0);

        if ($delta && $criteria->since instanceof DateTimeInterface) {
            // Odoo stores write_date in UTC without a timezone marker, so the comparison value
            // has to be converted rather than formatted as-is — otherwise a site running in a
            // negative offset silently skips several hours of changes every sync.
            $since = (clone $criteria->since)->setTimezone(new \DateTimeZone('UTC'));
            $domain[] = ['write_date', '>=', $since->format('Y-m-d H:i:s')];
        }

        if ($criteria->ids !== []) {
            $domain[] = ['id', 'in', array_map('intval', $criteria->ids)];
        }

        foreach ($criteria->filters as $field => $value) {
            $domain[] = [$field, '=', $value];
        }

        $rows = $this->searchRead($model, $domain, $fields, $limit, $offset);
        $items = [];

        if ($prime !== null) {
            $prime($rows);
        }

        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = $make($row);
            }
        }

        return new Page($items, count($rows) >= $limit ? (string)($offset + $limit) : null);
    }

    private function searchRead(string $model, array $domain, array $fields, int $limit, int $offset = 0): array
    {
        $result = $this->rpc('object', 'execute_kw', [
            $this->setting('database'),
            $this->authenticate(),
            $this->setting('apiKey'),
            $model,
            'search_read',
            [$domain],
            ['fields' => $fields, 'limit' => $limit, 'offset' => $offset, 'order' => 'write_date asc, id asc'],
        ]);

        if (!is_array($result)) {
            throw new \RuntimeException(sprintf(
                'Odoo refused to read %s: %s',
                $model,
                $this->lastError ?? 'no reason given',
            ));
        }

        return $result;
    }

    private function count(string $model, array $domain): int
    {
        $result = $this->rpc('object', 'execute_kw', [
            $this->setting('database'),
            $this->authenticate(),
            $this->setting('apiKey'),
            $model,
            'search_count',
            [$domain],
        ]);

        return is_numeric($result) ? (int)$result : 0;
    }

    private function authenticate(): ?int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }

        $uid = $this->rpc('common', 'authenticate', [
            $this->setting('database'),
            $this->setting('username'),
            $this->setting('apiKey'),
            new \stdClass(),
        ]);

        // Odoo answers a failed login with `false` and HTTP 200 rather than with an error.
        return $this->uid = (is_int($uid) || is_numeric($uid)) && (int)$uid > 0 ? (int)$uid : null;
    }

    /**
     * One JSON-RPC call.
     *
     * Odoo answers *everything* with HTTP 200 — a missing model, a permission failure, a Python
     * traceback — and puts the problem in an `error` object. So the body is what decides, not the
     * status.
     */
    private function rpc(string $service, string $method, array $args): mixed
    {
        $this->lastError = null;
        $this->lastStatus = 0;

        $response = $this->transport()->post('/jsonrpc', [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => ['service' => $service, 'method' => $method, 'args' => $args],
            'id' => random_int(1, PHP_INT_MAX),
        ]);

        $this->lastStatus = $response->status;

        if (!$response->ok()) {
            $this->lastError = $response->errorMessage();

            return null;
        }

        $body = $response->json_();

        if (isset($body['error'])) {
            $this->lastError = (string)(
                $body['error']['data']['message']
                ?? $body['error']['message']
                ?? 'Odoo returned an error with no message.'
            );

            return null;
        }

        return $body['result'] ?? null;
    }

    // ---------------------------------------------------------------------------------------
    // Plumbing
    // ---------------------------------------------------------------------------------------

    /**
     * A many-to-one field arrives as `[id, "Display Name"]`. Reading it as a string gives "Array".
     */
    private function relationName(mixed $value): ?string
    {
        if (is_array($value)) {
            return isset($value[1]) ? (string)$value[1] : null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function relationId(mixed $value): ?int
    {
        if (is_array($value)) {
            return isset($value[0]) ? (int)$value[0] : null;
        }

        return is_numeric($value) ? (int)$value : null;
    }

    /**
     * A partner's customer code: its `ref` (Odoo's **Reference**), or its id when it has never
     * been given one, so the partner stays addressable rather than being skipped.
     *
     * This is the only derivation. Customers, credit and invoices all go through it so every
     * document about one partner lands on the same Erpy account, and {@see findPartnerByCode()}
     * is its inverse for the order push.
     */
    private function partnerCode(array $row): string
    {
        return $this->partnerRef($row) ?? (string)($row['id'] ?? '');
    }

    /** A partner's `ref`, or null — Odoo sends an empty char field as `false`, not ''. */
    private function partnerRef(array $row): ?string
    {
        $ref = $row['ref'] ?? null;

        return is_string($ref) && trim($ref) !== '' ? trim($ref) : null;
    }

    /** @var array<int,string> partner id => customer code */
    private array $partnerCodes = [];

    /**
     * Looks up the codes of the partners a page of documents refers to, in one read.
     *
     * @param array<int|null> $ids
     */
    private function primePartnerCodes(array $ids): void
    {
        $missing = array_values(array_unique(array_filter(
            $ids,
            fn($id) => $id !== null && !isset($this->partnerCodes[$id]),
        )));

        if ($missing === []) {
            return;
        }

        // `active in (true, false)`: an invoice for a partner that has since been archived still
        // belongs to that partner's account, and Odoo hides archived records by default.
        $rows = $this->searchRead('res.partner', [
            ['id', 'in', $missing],
            ['active', 'in', [true, false]],
        ], ['id', 'ref'], count($missing));

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'])) {
                $this->partnerCodes[(int)$row['id']] = $this->partnerCode($row);
            }
        }

        // A partner the integration user cannot read still has an id, which is its code when it
        // has no reference — better than dropping the invoice's customer altogether, and cached
        // so it is not asked for again on every row.
        foreach ($missing as $id) {
            $this->partnerCodes[$id] ??= (string)$id;
        }
    }

    /** The customer code for a partner relation, `[id, "Name"]`. */
    private function partnerCodeFor(mixed $relation): ?string
    {
        $id = $this->relationId($relation);

        if ($id === null) {
            return null;
        }

        if (!isset($this->partnerCodes[$id])) {
            $this->primePartnerCodes([$id]);
        }

        return $this->partnerCodes[$id] ?? (string)$id;
    }

    /**
     * A product relation's display name is `[ITEM01] Widget`; the SKU is the part in brackets.
     */
    private function productCode(mixed $value): string
    {
        $name = $this->relationName($value) ?? '';

        if (preg_match('/^\[([^\]]+)\]/', $name, $matches)) {
            return trim($matches[1]);
        }

        return $name;
    }

    private function date(mixed $value): ?DateTime
    {
        if (!is_string($value) || trim($value) === '' || $value === 'false') {
            return null;
        }

        try {
            // Odoo's datetimes are UTC with no marker; reading one as site-local shifts every
            // watermark comparison by the site's offset.
            return new DateTime($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
