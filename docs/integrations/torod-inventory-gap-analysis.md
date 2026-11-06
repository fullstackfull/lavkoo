# Torod Point warehouse inventory — requirement, evidence, gap

**Requirement:** store availability for Torod Point products follows the warehouse's real stock automatically, with
no person typing numbers. **Rule we keep:** no stock figure is ever invented, assumed or simulated; a number that is
not proven to be the available quantity is labelled and never used to decide availability.

## What the three LogesTechs documents give us (verbatim facts)

| Source | Fact |
|---|---|
| Fulfillment API v5, *Get Product List* | `GET /public/fulfillment/product?page=1&pageSize=10` — "Retrieves a list of products with pagination." **No response example, no field list.** |
| Fulfillment API v5, *Add/Update Products* (request only) | Product object has `externalId`, `name`, `sku`, `barcode`, `price`, `cost`, `description`, **`quantity`** (sample `0`), `isInfinite`, `isExpiryBlock`, `lessCapacity`, `maxCapacityPerLocation`, `storageType` (`BIN`), `pickingMethod` (`FIFO`), `areaZone`, `customerInfo`, `linkedProductId`, `productUnit`, `productUnitQuantity`. The response is `[{"feedBack": null, "externalId": "1", "id": 1421521, "status": "synced"}]`. |
| Fulfillment API v5, *Add Order* | Request only (`items[]` with `externalId` / `productId` / `sku` / `price` / `quantity`). **No response example, no stock/availability error documented.** |
| Fulfillment API v5, *Get Order List* | `GET /public/fulfillment/order?page=1&pageSize=10`; no response schema. |
| Fulfillment webhook | `status` ∈ CREATED / PICKED / PACKED, `barcode`, `packageBarcode`. No stock event. |
| Lastmile API v2 | Shipment-only (create, cities, AWB, cancel, status). No inventory. |

## Findings (what is missing, precisely)

1. **No documented response field carries available stock.** `quantity` appears only in the *request* to create/update
   a product (and `quantity` on order lines). Whether the product list returns `quantity`, an on-hand figure, a
   reserved figure or an available figure is **not stated anywhere**, and neither is the field name.
2. **No inventory endpoint is documented** (no `GET …/inventory`, no per-product stock, no stock-by-location, no
   stock movements, no "changed since" filter).
3. **No change notification for stock** (no webhook; the Fulfillment webhook only reports order picking/packing).
4. **Reservation semantics are unknown:** does *Add Order* reserve stock immediately? When does on-hand fall (order
   created, picked, shipped)? What does the API return when stock is insufficient (error code/body)?
5. **`quantity` in the bulk upsert is dangerous without a definition.** If it means "set stock to N" then omitting it
   might read as `0` for an existing product. We therefore **never send `quantity`**, and product push is off by
   default.
6. **`isInfinite` and `isExpiryBlock`/`lessCapacity`/`maxCapacityPerLocation`** change what "available" means
   (untracked stock; expiry-blocked batches; capacity-limited bins). Not defined.
7. **Pagination limits** (max `pageSize`, total count, ordering stability, rate limit) are unspecified, which decides
   whether a full-catalogue poll is acceptable and how often.
8. **Identifier precedence** between `externalId`, `id` (LogesTechs) and `sku` is unspecified for order lines.

## What the live read-only run showed (Hotfix 2)

The product list **does** return quantities, under several names: `quantity`, `originalQuantity`, `committedQuantity`,
`allocatedQuantity`, `onHandQuantity` and `sellableQuantity`. For three real products the run showed:

| Product | `onHandQuantity` | `sellableQuantity` | `quantity` · `originalQuantity` · `committedQuantity` · `allocatedQuantity` |
|---|---|---|---|
| AS9 | 15 | 0 | 0 · 0 · 0 · 0 |
| AS10 | 19 | 0 | 0 · 0 · 0 · 0 |
| AS8 | 2 | 0 | 0 · 0 · 0 · 0 |

Nothing in that table says which figure the storefront may sell from, or why units on hand are not sellable (a put-away or release
step? a different meaning?). Picking `sellableQuantity` would put every one of these products at zero; picking `onHandQuantity` may
count units that are already committed to orders. **That is LogesTechs' to answer** ([request, section F](vendor-requests/logestechs-technical-request.md)),
not ours to infer, so:

* no quantity field is proposed as the stock (the old first candidate, `quantity`, was filled in with a zero on every product and would
  have been proposed);
* the confirmation gate stays, and it now names the **path** it was made for;
* until it is cleared, **inventory sync stays off and the storefront keeps its manually maintained stock.**

## What the code does today (nothing is guessed)

* `fulfillment:sync` / *Sync warehouse products* reads the product list and **stages the raw rows**
  (`provider_catalog_items`). Any number that looks like stock is shown as **"Not confirmed as available quantity"**.
* A mapped product's stock is **manual** (typed by a person, labelled *Entered manually*) until both are true:
  an administrator has **verified the products field map** (which JSON path is the stock field) **and** ticked
  *"I confirm the stock field is the available quantity"* in the Torod Point settings (the moment is recorded;
  un-ticking withdraws it). The capability report says *Not verified* until then.
* When confirmed **for the exact path the map reads**, and only for products **assigned to Torod Point**,
  `InventorySyncService::applyToAssignedProducts` writes `stock − quantities not yet handed to the warehouse` into the
  storefront availability, so an order we have not yet sent is not counted twice. A figure that is missing, negative,
  fractional, not a number or contradictory is skipped and counted (never zero); a product the response does not mention is
  left alone and counted; the product form and the manual-stock entry stand aside for a product the warehouse controls.
  Details and the manual/warehouse modes: [torod-point.md](torod-point.md#stock).
* If LogesTechs provides a **dedicated inventory endpoint** instead, a small adapter addition reads it (the
  scheduling, mapping, labelling and storefront write already exist). Estimated work: the client call, a field map,
  tests — nothing else changes.

## What unlocks automatic synchronisation

| Needed from LogesTechs | Unlocks |
|---|---|
| Sample JSON of `GET /public/fulfillment/product` with at least one in-stock, one zero-stock and one `isInfinite` product, and which field is the **available** quantity | verify the products map + confirm the field → automatic sync every 30 min |
| …or the inventory endpoint (path, params, response) | build the inventory call, then as above |
| Reservation rules for *Add Order* and the insufficient-stock error | out-of-stock holds that match the warehouse, no oversell window |
| `updatedAfter` filter or an inventory webhook | near-real-time instead of full-catalogue polling |
| Rate limit and max `pageSize` | choose the poll interval safely |

The exact questions, with a proposed minimal contract, are in
[`vendor-requests/logestechs-technical-request.md`](vendor-requests/logestechs-technical-request.md).

## Interim operating mode (until answered)

This is what runs today, by decision: `inventory_sync_enabled` is off and stays off.

Torod Point products are sellable on **manual stock**: a person enters the warehouse count (labelled), and orders are
still sent automatically. Risk: the store can oversell if the manual figure is stale; the warehouse cannot fulfil what
it does not have, so such an order would be rejected or stall at the provider and surface as *Failed/Held*. This is
acceptable for a pilot, not for full automation. **Classified as a blocker for "automatic inventory synchronisation",
not for order dispatch.**
