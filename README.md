# Erpy for Odoo

An **[Erpy](https://justinholt.com/plugins/craft-erpy)** connector for Odoo Community and Enterprise.

Free. Erpy itself is the paid part — it owns the sync engine, the identity map, the field
mapping, the queue, the dead letters and the log. This package's whole job is to translate one
vendor's API into Erpy's canonical documents.

## Installing

```sh
composer require justinholtweb/craft-erpy-odoo
php craft plugin/install erpy-odoo
```

Then add a connection under **Erpy → Connections** and pick it from the ERP list.

## What you need to know

### Authentication

An API key generated under the user’s Preferences → Account Security. Use one rather than an account password; some Odoo Online instances refuse password logins over RPC entirely.

### It is not a REST API

There are no endpoints, only models and methods. Everything here is `search_read` against a model with a domain. Errors arrive as **HTTP 200** with an `error` object in the body, so a transport that only checked the status would report every failure as a silent empty page.

### SKUs are `default_code`

Odoo's internal reference is the only field that behaves like a SKU. A product without one cannot be matched to Commerce.

## What it syncs

The connection screen shows exactly which entities and directions this connector supports —
it is generated from the connector's own declaration, so it can never advertise a flow it has
not implemented.

## A field is wrong

Correct it on the mapping screen: a rule whose target is a canonical field (`sku`, `unitPrice`,
`customerCode`) overrides what the connector read, before anything reaches Commerce. No fork,
no wait for a release.

## Documentation

The full documentation for this add-on is at
https://justinholt.com/plugins/craft-erpy/docs/odoo, and Erpy's own is at
https://justinholt.com/plugins/craft-erpy/docs.

## Requirements

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+, and Erpy 5.0+.

## Support

justin@justinholt.com

## License

The Craft License. See `LICENSE.md`. Erpy for Odoo is free: no editions and no licence key of its own.
It needs a licensed copy of [Erpy](https://justinholt.com/plugins/craft-erpy), which is the paid part.
