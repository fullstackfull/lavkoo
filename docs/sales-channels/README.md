# Sales channels and operational costs

A multi-channel layer on top of the storefront: **Amazon** (Selling Partner API) and **Trendyol** (Marketplace Integration API) accounts, listings mapped to our products, channel orders followed and routed, stock and price publishing, and an **operational cost ledger** that turns every sale into an honest estimated-versus-actual profit. Odoo is deferred: there is an extension point and nothing more.

**Everything ships OFF.** Nothing leaves the server until `SALES_CHANNELS_ENABLED` is on, an account is enabled and holds the capability, and (for writes) `SALES_CHANNELS_EXTERNAL_WRITES_ENABLED` is on, the account allows writes, dry-run is off and a person has recorded an approval. Credentials are entered in the admin panel and stored encrypted; they are never in code, config, environment files, logs or responses.

| Read this | For |
|---|---|
| [architecture.md](architecture.md) | layers, tables, flows, the safety model, the decisions made and the assumptions they rest on |
| [api-reference.md](api-reference.md) | which official API versions and endpoints are used, the permissions each needs, rate limits, what is documented but not built, what could not be verified |
| [costs.md](costs.md) | the operational-cost ledger: contracts, fee types and calculation methods, product cost layers, profit |
| [operations.md](operations.md) | deployment (exact migrations), switches, scheduler, runbooks (connect, sync, link, route, publish, roll back) |
| [shared-file-changes.md](shared-file-changes.md) | every change to a file that existed before this work, for the other workstream |
| [final-report.md](final-report.md) | the delivery report: what exists, what was tested and how, what is not built, the open risks |

## What this is not

* It does not create provider (Torod Point / M5AZN) shipments for channel orders. The existing storefront-order fulfilment is untouched and unchanged. See *Not built* in [architecture.md](architecture.md).
* It has not been run against a live Amazon or Trendyol account. Every connector test uses fixtures shaped after the official models; they prove how the code behaves against those shapes, not that a seller account works.
* It has not been run on MySQL in this environment (the sandbox has SQLite only). The CI job that runs on MySQL 8 has been extended to cover these tests.
