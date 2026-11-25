# The ten integrations, classified

**What this is.** One current map of every external integration this platform talks to, each with the five-way classification the plan asks
for and the three-way split between *code in this repository*, *legacy pull-request state* and *production*. It replaces the table in
Discovery report `06-git-lineage-legacy-prs.md` section 5 (2026-11-12), which predates the 86 drafts and names no row for Torod Point or
M5AZN (H2-18).

**Read it with the dates.** Every row below was read on branch `wt/acgh` at `5935f19b` (a worktree of `gcc/development`) on **2026-11-25**.
Nothing here is a reading of the live server: the production column is UNKNOWN in every row and will stay UNKNOWN until an authorised
administrator reads it, which is not something this repository can do for itself. Saying UNKNOWN is the point of the column.

## The five classifications

| Label | What it means here |
|---|---|
| **Implemented** | A client, an adapter or a connector exists in this repository, with tests, and nothing about it is declared unbuilt. |
| **Partially implemented** | Some of it exists and a named part does not. The part is named in the row, not left to the reader. |
| **Open PR** | The change is on an open pull request and not on `main`. |
| **Planned** | Named in the plan or the decisions, with no code. |
| **Unverified** | Code exists and nobody has seen it work against the real service from this platform. |

An integration can be Implemented *and* Unverified, and most of these are: `FULFILLMENT_INTEGRATIONS_ENABLED` and
`SALES_CHANNELS_ENABLED` both ship **off**, so none of this code has run against a real service on this platform. That is two different
facts and the table keeps them in two columns.

## The map

| # | Integration | Kind | Classification | Code in this repository | Legacy PR | Production |
|---|---|---|---|---|---|---|
| 1 | **Torod Point** | Carrier / fulfilment | Implemented, Unverified | `app/Services/Fulfillment/Providers/TorodPoint/` (adapter, payload builder, city resolver, webhooks), declared in `config/fulfillment.php` under `providers.torod_point`; `ProviderCode::TorodPoint`. Tests: the whole `tests/Feature/Fulfillment` group, `TorodCityResolutionTest`, `ProviderRouteSupportTest` | #12-#16 family (fulfilment), superseded | UNKNOWN |
| 2 | **M5AZN** | Dropshipping supplier / fulfilment | Implemented, Unverified | `app/Services/Fulfillment/Providers/M5azn/`, declared under `providers.m5azn`; `ProviderCode::M5azn`. Tests: `DispatchedOrderReachesM5aznTest`, `PilotServerMoneyTest`, the fulfilment group | same | UNKNOWN |
| 3 | **MyFatoorah** | Payment gateway | Implemented, Unverified | `app/Services/MyFatoorah/` (15 classes: client, initiation, verification, settlement, refunds, webhook signature, reconciliation). Registered in `PaymentGatewayCatalog` as `fatoorah` and `my_fatoorah` | — | UNKNOWN |
| 4 | **Paymob** | Payment gateway | Partially implemented, Unverified | `app/Services/Payments/PaymobTransactionInquiry.php` only — a transaction inquiry against the four regional hosts. Registered in `PaymentGatewayCatalog` as `paymob_accept`. **Not built:** nothing else of Paymob is a service of its own; it goes through the platform's generic gateway path | — | UNKNOWN |
| 5 | **Tabby** | Buy-now-pay-later | Planned | **Nothing.** `grep -rli tabby app/ config/ Modules/` returns no file on this branch | the Discovery table lists it as MISSING-in-lavkoo | UNKNOWN |
| 6 | **Tamara** | Buy-now-pay-later | Planned | **Nothing**, by the same grep | same | UNKNOWN |
| 7 | **Amazon** (SP-API) | Marketplace | Implemented, Unverified | `app/Services/SalesChannels/Amazon/` (24 classes: LWA auth, listings, feeds, reports, finances, orders, catalogue). Implements `ReadsListings`, `PublishesStockAndPrices`, `ConfirmsShipments`, `ReadsFinances`, `DescribesListingRequirements` | — | UNKNOWN |
| 8 | **Trendyol** | Marketplace | Implemented, Unverified | `app/Services/SalesChannels/Trendyol/` (12 classes incl. webhook manager and ingestor). Implements `ReadsListings`, `ResolvesChannelBrands`, the publish and shipment contracts | — | UNKNOWN |
| 9 | **noon** | Marketplace | Partially implemented, Unverified | `app/Services/SalesChannels/Noon/` (8 classes: client, connector, order mapper, parent SKU, shipment check, product groups). **Not built:** `NoonConnector` implements no `ReadsListings`, so noon listings are never read into the product-mapping table; `AccountForm::SECRET_FIELDS` has no `noon` entry and `account-create.blade.php` offers Amazon and Trendyol only, so **no noon account can be created from the admin panel at all** (H2-02). Its country is validated as of this batch (H2-07) | the Discovery table: present-partially | UNKNOWN |
| 10 | **Odoo** (XML-RPC) | Accounting | Partially implemented, Unverified | `app/Services/Accounting/Odoo/` (23 classes: XML-RPC transport, preflight, draft builder, posting run, undo links, three-way reconciliation). Four `ODOO_*` switches, all off. **Not built:** nothing in `app/` calls `OdooOutbox::enqueue()` (the drain is a POST button), `app/Console/ScheduleDefinition.php` has no Odoo entry, one company per connection, and amounts are written at two decimals (H2-04, H2-10, H2-11) | the Discovery table: VERIFIED-in-main, posting not run | UNKNOWN |

## The open drafts that touch these integrations

As of 2026-11-25 the integration work is on `gcc/development` rather than on open pull requests; what the Discovery table could not name, because it
predates them, is:

| Draft | What it touches | State |
|---|---|---|
| [#72](https://github.com/fullstackfull/lavkoo/pull/72) `1c779d2` | `ChannelAccountCountry`: reads a Trendyol storefront and a noon country, narrows product matching to the account's country | Open PR; merged into the `gcc/development` line |
| [#76](https://github.com/fullstackfull/lavkoo/pull/76) `0ad49d2` | Amazon Gulf marketplace ids (SA, AE) for product matching | Open PR; merged into the `gcc/development` line |
| [#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410` | CI evidence only; changes no integration | Open PR |

No open pull request exists for Amazon, Trendyol, noon or Odoo themselves: they are code on `main`. `OWNER_PR_GUIDE.md` sections 4 and 8 hold
the pairwise conflicts and the merge order of the drafts, and none of the four conflicting pairs involves a channel or an Odoo file.

## Documentation that was out of date

The map is only as good as the prose around it, and five lines in this repository said the opposite of what the code does. Corrected in the
same batch as this file:

| File | What it said | What is true |
|---|---|---|
| `docs/sales-channels/README.md:3` | Odoo is only an extension point; noon is not built at all | both have connectors and tests; noon's gaps are named in row 9 above |
| `docs/sales-channels/odoo-accounting.md:3` | 'not built' | 23 classes, four switches, a posting run and a reconciliation |
| `docs/sales-channels/api-reference.md:52` | claim approve/reject is not built | `ChannelReturnDecisions` builds it |
| `docs/sales-channels/account-handoff.md:5` | nothing is merged to main | the channels and Odoo are code on `main` |
| `DynamicFulfillmentRoutingEngine` docblock | managed dispatch 'not available in this version' | `DispatchGate` and `ChannelOrderMaterializer` exist, behind `SALES_CHANNELS_MANAGED_DISPATCH_ENABLED` (off) |

## How to keep it current

This file is a reading of a tree at a SHA, so it goes stale the way every such reading does. Re-read it when an integration is added, when a
connector gains or loses a contract, and before any release that claims an integration works. The cheap checks that produce most of the rows:

```bash
grep -rli 'tabby\|tamara' app/ config/ Modules/            # row 5 and 6: still nothing?
grep -rn 'implements' app/Services/SalesChannels/*/??*Connector.php   # which contracts each connector really has
grep -rn 'OdooOutbox::\|->enqueue(' app/ routes/ Modules/   # row 10: does anything enqueue yet?
php artisan route:list | grep sales-channels                # which screens exist
```
