# Operational costs and profitability

No price lives in code or configuration. Every price is a **contract rule** entered by an administrator, versioned and kept in the database; a service nobody priced is reported as **missing**, never as free.

## Contracts

A contract belongs to one company (`provider_code`: `torod_point`, `m5azn`, `trendyol`, `amazon`, a gateway code …) of one party type (fulfilment provider, carrier, marketplace, payment gateway, supplier, other), in one currency, with an effective-from/to window, a reference number and notes.

* **Versioned, never overwritten.** `draft → active → superseded/archived`. Only a draft is edited. An active contract changes only through *start a new version* (effective from a date, with a reason); the previous version stays as it was and keeps pricing everything dated inside its window. `contractFor(company, date)` returns the version in force on a date. Revisions are logged.
* **Rules** price one service each: `service_type` (a `CostComponent`), `calc_method`, parameters, optional conditions (zone, country, city, payment method, channel, service level), priority and validity.
* **Expected components** (contract setting): a component the contract is expected to price but has no rule for is **MISSING** (visible), not silently skipped.

### Fee types (`CostComponent`)

Product cost: `cogs`, `supplier_cost`, `inbound_freight`. Fulfilment: `receiving`, `picking`, `packing`, `order_processing`, `packaging_material`, `stickers`, `printing`, `shipping`, `extra_weight`, `zone_surcharge`, `return_fee`, `cancellation_fee`, `cod_collection`, `special_fee`. Storage: `storage_daily`, `storage_monthly`, `storage_per_unit`. Payment: `payment_gateway`. Marketplace: `marketplace_commission`, `fba_fulfillment`, `fba_storage`, `fba_return`, `marketplace_service_fee`, `marketplace_shipping`, `marketplace_refund`, `marketplace_adjustment`. `other`.

### Calculation methods (`CalcMethod`)

`fixed`, `percent`, `percent_plus_fixed` (with min/max), `per_unit`, `per_shipment`, `per_order`, `per_day`, `by_weight`, `by_volume`, `by_zone` (zone lists in Arabic and English spellings are matched after normalisation), `tiered_quantity`, `tiered_value`. Tiers must not overlap; a percentage cannot exceed 100; numbers are numbers. The validator refuses a malformed rule and never supplies a figure. A fact the rule needs and the caller does not know (a missing weight, an unknown city) makes the cost **missing with that reason**, not zero.

## The ledger (`operational_cost_entries`) — one writer: `CostLedger`

* **States:** `estimated`, `actual`, `reconciled`, `missing`, `disputed`. A missing entry has a NULL amount.
* **One effective entry per service of a subject** (the *slot*): a better-known figure supersedes a weaker one (actual over estimate over missing) and a weaker one never displaces a better one, so an estimate and the real charge are never both counted. Superseded entries stay as history.
* **Idempotent:** the same computation or document is one row (`dedupe_key`).
* **Approved and reconciled entries are frozen.** A different newer figure is stored beside them as *disputed*, outside the totals, for a person to resolve. A mistake is reversed with an equal and opposite entry, never edited or deleted.
* **Disputes.** Only a charge (actual or reconciled) can be contested; it leaves every total until settled and remembers the state it had. *The charge stands*: it counts again as what it was (nothing is added). *It does not stand*: it counts again only to be cancelled by one reversal, so the two net to zero and the total is never driven negative (the charge was already outside it while contested). For a newer figure kept beside an approved one: *it stands* — it replaces the approved figure for the slot (which is superseded, not edited); *it does not stand* — it is set aside and the approved figure remains. Every outcome is audited.
* **Historical costs never change when a contract changes:** an entry keeps the contract, version and rule that priced it, its calculation inputs, and (for another currency) the exchange rate and the base amount at the time.
* **Money** is exact decimal throughout (`Money`, brick/math): six decimals stored, half-up, two shown; shared charges are split with the largest-remainder method so the parts add up exactly. FX conversion uses a recorded rate; with none, the amount stays in its own currency and the report says the total is incomplete.

## Product cost (what the goods cost)

* **Inventory cost layers (FIFO).** A purchase creates a layer at a location (idempotent by source reference). Buying stock is an inventory movement, **not** an expense. The cost of goods is recognised **at the sale** (`operational_costs.cogs_recognition`: storefront at delivery, Amazon and Trendyol at shipment), consuming the oldest layers; cancelled-before-shipping sales reverse the consumption.
* A sale for which the layers hold less than the quantity records the shortfall (reported, never costed at zero).
* A sale line that is not linked to a product has a **missing** cost of goods (reason `product_not_linked`), not no cost at all: the order's profit is provisional until the line is linked, and linking supersedes the missing entry with the estimate or the actual cost.
* **Dropshipping (M5AZN):** the supplier cost is the cost of goods, taken from the supplier's price at the time.
* **Transfers are not sales.** Moving stock from Torod Point to Amazon FBA moves layers between locations (`transfer`), keeps the unit cost and records no revenue and no COGS.
* **Amazon FBA** stock, storage and fees are kept apart from Torod's (own location; fees from the Finances API only).

## Shipments, storage, marketplace and payment costs

* **Shipments:** the fee for one shipment is priced once per shipment (never multiplied by the number of items) and allocated to its items by the basis the contract names (default by component: shipping/extra weight by weight, picking/receiving by quantity, otherwise value). A fee that cannot be allocated is kept apart as *unallocated*.
* **Storage** is accrued per day per location from the stock figure the location vouches for; a day with no trustworthy figure is a missing cost with that reason, never a free day. Amazon FBA storage is Amazon's real fee, not priced here.
* **Marketplace fees:** commission estimated from the channel's contract when one exists (otherwise missing); the statement's real charge replaces it. Postings for one order (a commission, then part of it returned with a refund) are netted into one actual. FBA fees can only come from the statement and are missing until it arrives. Payouts are **not revenue**; a commission invoice that bills commission already carried on the sale rows is a duplicate and is not counted again.
* **Payment gateway** fees are priced from the gateway's contract (cash and wallet carry none).

## Invoices and reconciliation

A company's invoice (number unique per company and seller; duplicates are detected and refused until a person says it is not one) is entered with its lines; each line is linked to a shipment/order where possible; **approval** records the lines as actual costs (idempotent); **reconciliation** compares each billed line with the estimate for the same service within a tolerance (`operational_costs.reconciliation.tolerance_percent`, default 2 %), lists billed-versus-estimated differences and estimates that were never billed, and each difference is resolved by a person (accept the invoice, keep the estimate, dispute). Voiding requires a reason. Everything is audited.

## Profit

For each sale: revenue (storefront orders; channel orders — mapped internal orders are excluded so nothing is counted twice) less product cost, fulfilment, storage, marketplace and payment costs. **Estimated and actual profit are separate**, each with a completeness flag and the counts of missing, pending and disputed costs; a missing cost is never treated as 0. Reports group by product, order, channel, provider, and date; refunds reduce revenue; Trendyol/Amazon payouts are not revenue.
