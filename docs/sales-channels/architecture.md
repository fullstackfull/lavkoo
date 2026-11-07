# Architecture

## Layers

```
Admin screens / artisan commands / webhooks
        |
  ChannelSyncService  StockPricePublisher  ChannelShipmentNotifier  ChannelConnectionTester   (orchestration, leases, back-off)
        |                    |                       |
  Routing engine       Connectors (Amazon, Trendyol)  -- ChannelHttp (the ONLY way out) --> channel
  ExternalShipmentLinker      |
        |              Mappers (order, listing, finance)      ChannelOrderIngestor / ListingSyncService / ChannelFeeImporter
        v
  external_order_mappings  sales_channel_product_mappings  inventory_*  fulfillment_routing_*  operational_cost_*  (our tables)
```

* **`ChannelHttp`** is the single transport. Before anything leaves the host it checks, in order: the call is a *registered* operation with an allowed method (fail closed); the global switch; the account is enabled and holds a capability the call belongs to; for a write: the account is in dry run (the call is logged and nothing is sent) or the environment allows writes, the account allows writes and a person approved that kind of write. The five switches involved (enabled, capabilities, dry run, write enabled, approvals) are read from the database before every call, so a person's "stop" reaches a run that is already going. Reads and writes that set an absolute value are retried on 429/5xx/dropped connections with exponential back-off; other writes are never retried. Every call is logged redacted (`sales_channel_api_logs`).
* **Connectors** own everything channel-specific (URLs, authentication, payload shapes, statuses, pagination) and implement only the abilities the official documentation allows (`ReadsListings`, `ReadsOrders`, `ReadsChannelInventory`, `ReadsFinances`, `PublishesStockAndPrices`, `ReportsPublishResults`, `ConfirmsShipments`). `capabilityMatrix()` states per feature whether the channel documents it and whether it is built.
* **Sync** is leased, resumable and honest: a run holds a lease, reads at most `sync.max_pages_per_run` pages and pauses with its place saved, re-reads from the watermark less an overlap, and reports ok / skipped / busy / rate-limited / failed. A failure backs the resource off and never advances the watermark. A rate limit saves the place reached before the back-off, so a backlog larger than one allowance is read over several runs; a window asked of a channel (a statement period, an order stream) is fixed when the sweep begins and reused when it goes on; a snapshot read whose page tokens expire in seconds (Amazon FBA stock) is never paused or resumed.

## The nine activation modes

Per account, default **Disabled**. The ladder (a mode needs the ones under it; a recent successful connection test is needed from Read-only Sync upward):

`Connection Test` → `Read-only Sync` → `Product Sync` / `Order Import` / `Financial Reconciliation` → `Inventory Publishing` / `Price Publishing` (need a recorded approval) → `Fulfillment Dispatch` (needs a recorded approval). A successful test never switches anything on. Writes additionally need *write enabled* and *dry run off*; dry run is on by default and only rehearses.

## Tables (all additive, `Schema::hasTable`-guarded, no foreign keys into core tables)

| Migration | Tables |
|---|---|
| `2026_11_07_100000_create_operational_cost_tables` | `operational_cost_contracts`, `…_contract_revisions`, `…_rules`, `…_entries`, `…_allocations`, `…_invoices`, `…_invoice_items`, `…_reconciliations`, `inventory_cost_layers`, `inventory_cost_consumptions` |
| `2026_11_07_100100_create_sales_channel_tables` | `sales_channel_accounts`, `sales_channel_product_mappings`, `external_order_mappings`, `external_order_lines`, `sales_channel_sync_states`, `sales_channel_api_logs`, `sales_channel_events` |
| `2026_11_07_100200_create_inventory_and_routing_tables` | `inventory_locations`, `inventory_location_balances`, `inventory_source_policies`, `inventory_reservations`, `fulfillment_routing_rules`, `fulfillment_routing_decisions` |
| `2026_11_08_100000_add_state_before_dispute_to_operational_cost_entries` | one nullable column on `operational_cost_entries` (this module's own table): the state a contested cost had, so that a dispute settled in its favour restores it |

`seller_id` is `0` for the platform and the seller's id otherwise (never NULL, so the unique keys hold). No table or column of the platform that existed before this work is altered.

## Channel orders are not storefront orders

A channel order is recorded in `external_order_mappings` / `external_order_lines` **without** creating an internal order, a customer, a stock deduction or a provider fulfilment. Buyer names, addresses, phones, e-mails, identity and tax numbers are never stored (only the city and country, to price a delivery). The storefront's own orders keep flowing through the existing, untouched fulfilment code; channel orders that were ever matched to an internal order are excluded from the channel totals so nothing is counted twice.

Identity: `account + the channel's order id + its package id` (`''` for Amazon). Trendyol's shipment package is the unit; a split or partial cancellation gives the remainder a new package id and the replaced package is terminal (its lines count as cancelled).

## Stock and price

* A location's quantity is **never copied from a provider**: it is read where the integration keeps it (Torod/M5AZN mapping stock) or from a balance a channel/person reported, with a *quality*: `confirmed` (the source reported it recently), `manual`, `stale`, `unknown`. Only confirmed and manual are ever published.
* Published quantity = sellable (reported less units already promised to open orders and not yet off the location's own count) − safety buffer, capped. `ChannelOrderReservations` keeps those promises: each open channel order that is ours to fulfil holds its active units at the location its listing takes stock from (reserved), a linked or shipped order is *committed* (it counts only until the location reports a figure taken after that moment), a cancelled, failed or returned order releases its units, and an order the channel ships itself (FBA, channel-managed) or of unknown mode holds nothing. Amazon FBA stock lives in its own location (`storefront_sellable = false`, no publishable channels): it is shown on its own (*Stock by location*, read only, one location at a time, each figure with how far it can be trusted) and never sold from the storefront or offered as ours; its storage is Amazon's own fee, which comes from the statement, not from a rule here. The default locations (Torod Point, M5AZN, the own warehouse) are created, if missing, the first time a module page is opened or a scheduled command starts after the migrations (`SalesChannelSetup`). FBA listings never receive pushed quantities.
* Price: only a price a person set for the listing is published (`price_source = manual`). Taking the storefront's own price is **not built**: it depends on variations, clearance sales and currency conversion and needs an owner-approved design first.
* A source policy (an account's, or one product's in an account) supplies the source location, the safety buffer and the cap. **Whether anything is pushed is decided by the listing's own opt-in and by nothing else**: a policy can not switch publishing on for listings nobody opted in, so a listing synced tomorrow never starts receiving stock because a default exists.
* No loops: what was last pushed is remembered; a repeat is skipped unless a read-back shows something different; reading a channel never writes our stock; a value the channel refused three times (at once, in the batch result it gave later, or by accepting it and never applying it) stops until a person looks, while a request that never arrived or met a busy or failing channel says nothing against the value and is tried again.
* Accepted ≠ applied: Trendyol returns a batch id and the batch result is read later; Amazon is confirmed by reading the listing back. Zero or negative prices are refused (Trendyol treats a zero price as delisting).

## Routing

`DynamicFulfillmentRoutingEngine` decides each channel order, stores the decision (versioned, with reason code, the inputs, the rule as it was, per-line decisions) and applies it to the order record. Hard invariants no rule can override: a channel-shipped order (FBA, channel-managed) is never given to a provider; unknown fulfilment mode, unreleased or closed orders, cash on delivery (unless the account allows it) and lines not linked to a product wait for a person; a package whose carrier the channel arranges and pays is only followed (a shipment of ours would be a second shipment); an order with a linked or dispatched shipment is never decided again. Then the first matching administrator rule (priority, then specificity, then age). **With no matching rule the account's default decides**: *manual review* (the standard — nothing is routed unless someone said so) or *managed by someone else* (the order is only followed; used for an account whose orders are shipped by another integration, for example a Trendyol store linked directly to Torod). An account default can not be "Managed": creating a shipment needs a rule that names where the goods ship from. The invariants above still come first.

Execution modes: **Managed** (the application would create the fulfilment), **Externally managed** (someone else arranged the shipment: the application follows it, counts its cost, sends nothing), **Manual review**. "Trendyol linked directly to Torod" is Externally managed: the shipment is *found and linked*, never created again (one provider shipment can belong to one order — service check and unique index).

## Accounts

An account's *identity* is the seller id, the environment, the market (storefront / region) and the marketplaces. Everything that was proven or approved — the last connection test, the recorded approvals, the permission to write, every step above the connection test — was proven or approved for one identity. When a save changes any part of it, `ChannelAccountService` withdraws all of that at once (connection result forgotten, approvals cleared, writes forbidden, dry run on, only *Connection Test* left on) and audits `sales_channel.identity_changed`; new credentials withdraw only the connection result. The environment and the Amazon marketplaces are fixed once the account exists on the form (a different one is a different account). The columns the connectors read (`market`, `marketplace_ids`) are kept equal to the settings on every save. What was read for the old seller (watermarks, saved places, back-offs) is cleared with the approvals, so the new identity starts from its own backfill. A switch-off of a capability withdraws the separate approvals that belong to it (webhook and notification management with Order Import, product writes with Product Sync). Credentials that cannot be decrypted with the server's application key read as "not set" but are never overwritten by a rename or by a partial set: only a complete new set replaces them, and changing a secret that identifies the authorisation drops the tokens obtained with the old one.

## Monitoring

Old call logs and the stored text of old webhook deliveries are removed daily by `channels:prune` (60 days by default); statement postings are kept whole. The hub lists what needs a person, each with the link that resolves it: accounts whose authorisation was lost, resources that are backing off, orders that need review, listings whose last publish failed, and finance events that need review; every account card also counts its unlinked listings and orders held. `php artisan channels:status` prints the account facts for the console. The scheduled commands are in `ScheduleDefinition`, so the platform's scheduler monitor shows them with the rest; a resource that keeps failing backs off and says why on its card.

## Not built (stated plainly)

* **App-driven creation of a Torod/M5AZN shipment for a channel order** ("Managed" for channel orders). It needs the buyer's address — deliberately never stored — and an internal order, which would also enter the store's reports and stock. It is a decision for the owner (data-protection and reporting impact) and for the Torod/M5AZN workstream. A rule asking for it is recorded as *wanted* and the order waits with the reason `managed_dispatch_not_available`. The switch `sales_channels.managed_dispatch_enabled` exists and is off; turning it on without that work changes only the recorded decision.
* Automatic discovery of an existing Torod shipment for a Trendyol order: the Torod adapter has no search call. `ExternalShipmentLookup` is the extension point; the default finds nothing and guesses nothing; linking is manual.
* Trendyol product create/update/cancel/split and approving or rejecting a return; Amazon Catalog enrichment, Amazon returns, Notifications (SQS), Reports. Registered operations exist for some; none is built. See each connector's `capabilityMatrix()` and [api-reference.md](api-reference.md).
* Odoo / any accounting destination: `AccountingDestination` + a null implementation only.

## Assumptions to verify before relying on the numbers

1. Trendyol `orderDate` is documented as GMT+3 while other timestamps are UTC; the mapper subtracts `sales_channels.trendyol.order_date_offset_milliseconds` (10 800 000). Verify on a stage order.
2. A Trendyol-funded discount (`lineTyDiscount`) is treated as reimbursed to the seller (not deducted from revenue) and kept in the snapshot; the statement's `sellerRevenue` is the actual and replaces the estimate. Verify against a real settlement.
3. Trendyol and Amazon KSA consumer prices are VAT-inclusive (`prices_include_tax` default true on Trendyol); the included tax is derived `amount × rate ÷ (100 + rate)`.
4. Trendyol statement rows carry no currency; the account currency is assumed (marked `currency_assumed` in the fact). Gulf payouts may be settled in another currency per Trendyol's FAQ.
5. `is4P`, `3pByTrendyol` and `micro` have no documented meaning for a Gulf local seller: a package carrying one is `unknown` and is never routed.
6. Amazon: Saudi carrier codes for `confirmShipment` are not published, so `Other` + carrier name is sent; `value_with_tax` for KSA price patches and the FeeType list are not verified.
7. Amazon Finances: a transaction carries breakdowns of its own and of each item; the mapper reads only the transaction's own when present (they add up the items') and the items' otherwise. A `nextToken` is documented as needing the arguments of the call that produced it, which is why a paused statement sweep reuses its window; whether Amazon actually refuses a changed one could not be settled offline.
8. Trendyol settlements: the sign of `commissionAmount` is printed negative in the Turkish sign table but positive in the international samples; the mapper does not depend on it (a Sale row's commission is a cost of its size, a Return row's is given back). The statement rows' other signs and the claims date window are also unverified.

## Timezones

Channels report UTC. Eloquent writes a date as the wall-clock of the Carbon instance it is given and reads it back in the application's timezone, so every model that stores a channel instant uses `StoresLocalTime` (converts to the application's timezone on write). A regression test round-trips an instant under UTC, Asia/Riyadh and America/New_York.
