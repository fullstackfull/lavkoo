# Technical request to LogesTechs — Fulfillment API v5 (company ID 714)

**From:** Pharmacy KSA (pharmacyksa.com) · **Company ID:** 714 · **Subject:** inventory visibility and response schemas for
the Fulfillment API (v5) so that our store can keep warehouse stock in sync automatically.

Thank you for the Fulfillment API v5 document. We have integrated order creation and product matching against it. To
finish the integration safely we need the items below. Please answer with **real JSON samples** (credentials and
personal data redacted) wherever a sample is requested, or an OpenAPI/Postman collection that contains them.

## A. Inventory (our main blocker)

1. **`GET /public/fulfillment/product`** — please send a complete sample response (headers, pagination envelope,
   and at least 3 products: one with stock, one with zero stock, one with `isInfinite: true`).
   * Which field(s) report stock? Please define each: **on-hand**, **reserved/allocated**, **available-to-sell**,
     incoming, damaged/expired/blocked.
   * Do these fields already exclude `isExpiryBlock` batches and respect `lessCapacity` / `maxCapacityPerLocation`?
2. If the product list does **not** return stock, **which endpoint does?** Please document path, parameters,
   response, and whether it is per product, per location, or both. We need *available-to-sell per product* (by `id`,
   `externalId`, `sku`, `barcode`).
3. **Change detection:** is there an `updatedAfter` (or similar) filter, or a webhook when stock changes (for
   example `INVENTORY_CHANGED` with `id`, `externalId`, `sku`, `available`, `occurredAt`)? Without it we poll the
   whole catalogue.
4. **Reservation semantics for *Add Order*:** is stock reserved the moment the order is accepted? When does
   on-hand decrease (created / picked / packed / dispatched)? What happens to reserved stock when an order is cancelled?
5. **Insufficient stock:** what exactly does *Add Order* return (HTTP status, error code, message format, partial
   acceptance)? Is a back-order possible?
6. **`quantity` in *Add/Update Products*:** is it an opening/absolute stock level, ignored for existing products, or
   an adjustment? If we **omit** `quantity` when updating an existing product, is the stock left untouched? (We do not
   send `quantity` today because we do not want to overwrite warehouse stock.)
7. **Limits:** maximum `pageSize`, whether a total/count is returned, ordering stability between pages, rate limit
   (requests per minute), and any request budget we should respect when polling.
8. Do we have more than one warehouse/location? Is availability aggregated for the API?

## B. Orders and identifiers

9. **`POST /public/fulfillment/order` response:** sample body for a success and for a validation failure. Which field is
   the **order id** and which is the **order barcode** (the `O…` value that appears in the Fulfillment webhook)?
10. **Duplicate protection:** what happens if the same `invoiceNumber` is submitted twice (rejected, or a second order
    created)? Is there a filter on `GET /public/fulfillment/order` such as `?invoiceNumber=` so we can look an order up
    before ever re-sending after a timeout? Please also send a sample response for that list.
11. **Item identity:** when an order line carries `externalId`, `productId` and `sku` together, which one wins? Must
    `sku` be unique per company? Does a product with variants need one SKU per variant?
12. **`receiverAddress`:** is `villageId` mandatory? (our checkout has city and region but no district). What is
    returned when `cityId` and `regionId` do not belong together?
13. **`codCollectionMethod`:** the sample shows `PREPAID`. What are the exact values for cash on delivery? What does
    `cost` mean (shipping fee? declared value? something else) and is it required? What are the units of `weight`?
14. Can a **warehouse order be cancelled** (before pick / after pick)? Please give the endpoint. Is there a status
    lookup by order id or barcode?

## C. Webhooks

15. Please add **`invoiceNumber`** to the **Fulfillment** webhook payload (today only `status`, `barcode`,
    `packageBarcode`), so each event identifies our order without a lookup.
16. Are webhooks **signed** (HMAC header) or sent from **fixed source IP addresses**? Delivery guarantees: retries,
    ordering, maximum delay? What are *all* possible Fulfillment statuses (beyond CREATED / PICKED / PACKED), e.g.
    cancelled, out of stock, partially fulfilled?
17. For the Lastmile webhook, please confirm the status list is final (the document lists
    `SCANNED_BY_DRIVER_AND_IN_CAR` twice with different labels and several `FAILED` reasons under one code) and
    whether `FAILED` reasons arrive in `Notes`.

## D. Environment and support

18. A **sandbox/test environment** (base URL + test credentials for company 714) where we may create and cancel test
    orders without any physical movement, and a way to mark such orders as test.
19. The **error response format** for the v5 API (envelope, codes), and your versioning/deprecation policy.
20. A technical contact for integration issues and expected response time.

## What we will do with the answers

* Verify the response field maps and switch on **automatic stock synchronisation** (every 30 minutes, or faster if
  you offer a change filter/webhook).
* Until stock is confirmed, **we do not use any warehouse number to decide availability**; stock stays manual and
  labelled. We do not simulate inventory.

## Proposed minimal contract (only if it saves you work)

```
GET /public/fulfillment/inventory?page=1&pageSize=100&updatedAfter=2026-11-05T00:00:00Z
200 {
  "page": 1, "pageSize": 100, "total": 1234,
  "items": [ { "id": 1421521, "externalId": "1", "sku": "13222", "barcode": "13222",
               "onHand": 120, "reserved": 8, "available": 112, "updatedAt": "2026-11-05T09:30:12Z" } ]
}
```

Webhook (optional): `POST <our url>` `{ "type": "INVENTORY_CHANGED", "id": 1421521, "externalId": "1", "sku": "13222",
"available": 112, "occurredAt": "2026-11-05T09:30:12Z" }`.

## For your reference — what we send today

`POST /public/fulfillment/order` with headers `company-id: 714`, `username`, `password`, body:
`receiverName`, `receiverPhone` (`00966…`), `receiverPhone2` (empty), `receiverAddress {cityId, regionId, addressLine1}`,
`notes`, `weight` (integer), `receiverBusinessName` (empty), `shipmentType` (`REGULAR` | `COD`),
`codCollectionMethod` (`PREPAID` | value you confirm), `cod` (string, e.g. `"0.00"`), `items[] {productId, sku, price, quantity}`,
`invoiceNumber` (e.g. `100123-TP1`). Please tell us if anything above is wrong or missing.
