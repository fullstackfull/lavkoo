# Sales channels and operational costs

A multi-channel layer on top of the storefront: **Amazon** (Selling Partner API) and **Trendyol** (Marketplace Integration API) accounts, listings mapped to our products, channel orders followed and routed, stock and price publishing, and an **operational cost ledger** that turns every sale into an honest estimated-versus-actual profit. Odoo is deferred: there is an extension point and nothing more (and when it is built, [odoo-accounting.md](odoo-accounting.md) governs it). **Noon** is not built at all; [gap-matrix.md](gap-matrix.md) says exactly what is and is not.

**Everything ships OFF.** Nothing leaves the server until `SALES_CHANNELS_ENABLED` is on, an account is enabled and holds the capability, and (for writes) `SALES_CHANNELS_EXTERNAL_WRITES_ENABLED` is on, the account allows writes, dry-run is off and a person has recorded an approval. Credentials are entered in the admin panel and stored encrypted; they are never in code, config, environment files, logs or responses. The cost module has switches of its own (`OPERATIONAL_COSTS_ENABLED`, then `OPERATIONAL_COSTS_SCHEDULE_ENABLED`, both off): running the migrations starts no FIFO consumption, no cost entry and no storage charge, and nothing is scheduled until the owner switches them on (see [operations.md](operations.md)).

| Read this | For |
|---|---|
| [architecture.md](architecture.md) | layers, tables, flows, the safety model, the decisions made and the assumptions they rest on |
| [api-reference.md](api-reference.md) | which official API versions and endpoints are used, the permissions each needs, rate limits, what is documented but not built, what could not be verified |
| [costs.md](costs.md) | the operational-cost ledger: contracts, fee types and calculation methods, product cost layers, the same fee told by the API, a statement file and an invoice, profit, refunds and returns |
| [gap-matrix.md](gap-matrix.md) | what the code does today against the owner's plan for Amazon, Trendyol, **Noon**, the real-time shared stock and Odoo: built, registered-but-unused, or not built, each with the file that proves it |
| [listings.md](listings.md) | putting a product of the store ON a channel: the draft, what the channel says it requires, the nine gates, the one call that creates the listing, the pharmacy gate, and what is not built yet |
| [odoo-accounting.md](odoo-accounting.md) | the binding rule before any Odoo code: the cost module stays the only cost engine and Odoo is the accounting record |
| [dispatch.md](dispatch.md) | sending a channel order to Torod Point / M5AZN (built, off): the switches, the chain, the buyer's data and how long it is kept, what is protected against a duplicate and what is not, the discovery of shipments Trendyol creates directly, the owner's open decisions |
| [operations.md](operations.md) | deployment (exact migrations), switches, scheduler, runbooks (connect, sync, link, route, publish, returns, roll back) |
| [rehearsal-on-a-copy.md](rehearsal-on-a-copy.md) | the rehearsal on a restored copy of the real database that the owner runs before anything is switched on |
| [shared-file-changes.md](shared-file-changes.md) | every change to a file that existed before this work, for the other workstream |
| [final-report.md](final-report.md) | the delivery report, in Arabic, in the 20 points the task asked for: what exists, what was tested and how, what is not built, the open risks |

## What this is not

* It sends nothing to a provider on its own. Dispatching a channel order to Torod Point / M5AZN is built and tested but **off** (three environment switches, a master administrator's approval, two account settings and the provider's own gates; see [dispatch.md](dispatch.md)); the existing storefront-order fulfilment is unchanged, and the routing engine decides channel orders only. Nothing was run against a real provider.
* It has not been run against a live Amazon, Trendyol, Torod Point or M5AZN account. Every connector and provider test uses fixtures shaped after the official models or the providers' documentation; they prove how the code behaves against those shapes, not that a seller account works.
* Its tests run daily on SQLite, and on MySQL 8.0, MariaDB 10.11 and MariaDB 11.4.2 (the version the pilot server reports; not checked on the server itself), with the connection in strict mode and with several real PHP processes at once where a race matters, locally and in GitHub Actions (results in [final-report.md](final-report.md), section 16). It has not been run on the production server or on a copy of its database.
* The tests build the store's own tables (`products`, `orders`, `order_details`, …) and the platform's purchasing and stock tables (`purchase_orders`, `suppliers`, `stock_movements`, …) from a schema reconstructed from the code, not from the installation dump, which is not in the repository: the first run of the modules should be the rehearsal on a copy of the existing server's database ([rehearsal-on-a-copy.md](rehearsal-on-a-copy.md); the project documents a single pilot/production server and no staging server).
