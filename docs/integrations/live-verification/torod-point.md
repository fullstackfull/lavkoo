# Torod Point (LogesTechs, company 714) — live verification checklist

Two API families, strictly separated (see [torod-point.md](../torod-point.md)): **Fulfillment API v5** for warehouse
products and warehouse orders, **Lastmile API v2** for cities, package status, cancel and air waybill only. The Lastmile
*create shipment* call is never made (a warehouse order creates its own package; calling both ships the parcel twice).
Read the [ground rules](README.md) first: Level 1 = read-only, Level 2 = needs written authorization.

## What we need before starting

| Need | From | Notes |
|---|---|---|
| **Fulfillment API (v5)** username and password for company 714 | Store owner → *Integration Center → Torod Point* (write-only) | Sent as `company-id` / `username` / `password` headers |
| **Lastmile (v2)** e-mail and password | same | Used only by the helper calls (cancel needs it in the body) |
| Company ID `714` | prefilled | Never taken from an API example (the Lastmile docs show `136`) |
| A **sandbox / test environment** (base URLs + test credentials) | LogesTechs | Ask in the [request](../vendor-requests/logestechs-technical-request.md), item 18. Without it, every Level 2 call touches the real warehouse |
| **3 warehouse products whose stock the warehouse has physically counted**: one with stock, one at zero, one marked infinite | LogesTechs warehouse staff | For the stock protocol (T-1b) |
| The **webhook URLs** (shown once after *Generate webhook secret*) given to LogesTechs; *Accept webhooks* on | Store owner | `…/api/fulfillment-webhooks/torod-point/{secret}/lastmile` and `…/fulfillment` |
| A test receiver (staff phone/address) and a low-value in-stock product | Store owner | Level 2 only |

## Level 1 — read-only checks

| ID | Call | Capture | Pass when | Unlocks |
|---|---|---|---|---|
| **T-1 Product list** | `GET /public/fulfillment/product?page=1&pageSize=100` (v5) | **Complete** JSON of a page: envelope, pagination, **every field** of at least 3 products incl. the three counted ones | Items are readable and ids are stable | **Products field map** (`id`, `name`, `sku`, `barcode`, `stock`, `price` …) → matching screen. *The response schema is not in the document* |
| **T-1b Stock protocol** | Compare T-1 with the physical counts; then (authorized) place one test order and re-read after each warehouse stage | Which field equals **on-hand**, which **reserved**, which **available-to-sell**; when each changes (accepted / picked / packed / dispatched / cancelled) | One field provably equals *available-to-sell* **and falls when an order is accepted**, **confirmed in writing by LogesTechs**. The list carries several quantities (`onHandQuantity`, `sellableQuantity`, `committedQuantity`, …) and **no field is proposed** by the screen | The *"I confirm the stock field is the available quantity"* tick → **automatic stock sync**. Until then stock is manual and labelled. This is the **blocker for automatic inventory** (see the [gap analysis](../torod-inventory-gap-analysis.md)) |
| **T-2 Limits** | Walk pages until empty; time it | max `pageSize`, total/count field, ordering stability, rate limit | Documented | Poll cadence (≥ 5 min) and the 50-page ceiling the reader applies (it reports *truncated* if hit) |
| **T-3 Warehouse order list** | `GET /public/fulfillment/order` | Envelope; per order: **id**, **barcode** (`O…`), **invoiceNumber**, status; is `invoiceNumber` filterable? | Can find an order by our invoice number | **Orders field map** → reconciliation of an ambiguous submission (otherwise it waits for an operator, never re-sends). Historical orders show `invoiceNumber: null`: the map verifies for its **shape** (key present, may be empty; the barcode stays strict) and says so. That is **not** the round-trip proof: record it on the provider page (*Invoice number round trip*, master administrator, with evidence) before any real order, see [torod-point.md](../torod-point.md#real-orders-wait-for-one-confirmation-the-invoice-number-comes-back) |
| **T-4 Cities** | `GET /addresses/cities` (v2) | `id`, Arabic/English names, region id for a sample of 20 cities incl. ones with variant spellings | The same `cityId`/`regionId` are accepted by the v5 order API (T-9) | City matching (exact match after Arabic/English normalisation + aliases; unknown → held, never guessed) |
| **T-5 Villages** | `GET /addresses/villages?search=` | Is a `villageId` required by Add Order? | Answer recorded | If required: a district step is needed at checkout (design change) — otherwise orders are rejected |
| **T-6 Package status** | `GET /guests/packages/status?barcode=` (v2) for a real package | Fields: `status`, **`cod`**, `cost`, `notes`, `id`; does it include the invoice number? all `status` words | `cod` equals the cash we asked for (0 when prepaid) | **Webhook verification**: a "delivered"/"cancelled" claim is checked against *our* package's status **and its cash**; a package reporting different cash is rejected as not ours. If `cod` is *not* equal to what we send (fees added?) the claim is rejected (the 15-minute poll still applies the status) — record the rule |
| **T-8 Webhooks (receive)** | LogesTechs sends test events to both URLs | **Exact payload** (key casing!), `cod`/`invoiceNumber`/`barcode`/`time` presence and format (epoch ms, UTC?), retry/duplicate/ordering behaviour, source IPs, signature | Our endpoint answers `200 {success:true}`; events appear in *Shipments → status history* with the right outcome | Webhook parsing (keys are read case-insensitively), **IP allow-list** (addresses or CIDR, v4/v6) if LogesTechs publishes ranges, the clamp for skewed clocks |

## Level 2 — requires written authorization (template in the [README](README.md))

| ID | Call | Procedure | Capture | Pass when |
|---|---|---|---|---|
| **T-9 Create warehouse order** | `POST /public/fulfillment/order` (v5): `receiverName`, `receiverPhone` (`00966…`), `receiverPhone2`, `receiverAddress{cityId,regionId,addressLine1}`, `notes`, `weight`, `receiverBusinessName`, `shipmentType` (`REGULAR`\|`COD`), `codCollectionMethod`, `cod`, `items[{productId,sku,price,quantity}]`, `invoiceNumber` | **One** order, `invoiceNumber = VERIFY-<date>-1`, a staff receiver, one in-stock low-value item; vendor informed so nothing is picked | Complete success body (**which field is the order id, which the `O…` barcode**), a validation-error body (separate authorized call), whether the order then appears in T-3 | Reference readable → **Order response reference path** set (until then a 2xx without a reference is *outcome unknown*, never acceptance) |
| **T-9b Duplicate invoice** | Same `invoiceNumber` again | Authorized separately, only after T-9 is cancelled/finished | Rejected? returns the existing order? creates another? | **Documented**: decides whether our reconcile-before-resend rule is enough |
| **T-9c COD** | `shipmentType=COD` with `codCollectionMethod` / `cod` | Authorized separately; small amount | The **exact collection-method values**, what `cost` means (fee? declared value?), units of `weight` | COD collection method entered in settings; COD for Torod stays **held** until then |
| **T-9d Insufficient stock** | Order above stock | Authorized separately | Error shape | Parseable error; our `stock_unavailable` hold is only advisory until stock is confirmed |
| **T-10 Cancel warehouse order** | *No documented call* — ask LogesTechs | — | The endpoint (before pick / after pick) | Until it exists the app reports cancel as **unsupported** before the package exists; the operator cancels in the dashboard and marks it cancelled |
| **T-7 Air waybill host** | `POST /guests/714/packages/pdf` (v2) | On the T-9 order *after* its package exists, with `FULFILLMENT_EXTERNAL_WRITES_ENABLED=true`. It asks for a URL and is documented as changing nothing, but it is a POST whose effect has not been observed, so the transport holds it with the writes (it is not part of the read-only run) | The URL returned and its host | Host ends with `amazonaws.com` or `logestechs.com`: the *Download AWB* button only redirects to https URLs on those hosts; any other host is refused |
| **T-11 Cancel package** | `PUT /guests/714/packages/{barcode}/cancel` (v2; e-mail/password in the body, `company-id` header) | On the T-9 order *after* its package exists | Response; whether the warehouse order is affected; time to `CANCELLED` webhook | "Requested" is recorded only when acknowledged; the shipment becomes cancelled only when Torod reports it |
| **T-12 Product push** | `POST /public/fulfillment/product/bulk` | Only if *Allow pushing products* will be used; one test product | Response; **meaning of `quantity`** (we never send it) | Quantity semantics recorded; push stays off by default |
| **T-13 End to end** | T-9 → webhooks → package → delivery (or cancel) | Timestamps of: order accepted, `CREATED`, `PICKED`, `PACKED`, package barcode, `OUT_FOR_DELIVERY`, final | Delays and the order of events | Realistic expectations; poll fallback validated (webhook deliberately withheld for one run) |

## What each result changes in the application

* **Order dispatch for Torod Point can go live** once T-1, T-3, T-4, T-5, T-6, T-8, T-9, T-9b (and T-9c if COD) are recorded, the
  maps verified **and the invoice-number round trip confirmed** (no real order is created before that). Stock stays manual.
* **Automatic inventory** needs T-1b **and** LogesTechs' written statement of the available-to-sell field (or an inventory
  endpoint). Nothing in this repository simulates warehouse stock; if LogesTechs cannot provide it, stock remains manual
  and labelled permanently — a *non-blocker for orders*, a *blocker for the "automatic inventory" requirement*.
* **Webhook trust**: with T-6/T-8 the confirmation rule is proven. If LogesTechs can add `invoiceNumber` to the Fulfillment
  webhook (request item 15) and publish source IPs or a signature (item 16), the endpoint can be tightened further.

## Evidence to send back to the project

The completed evidence records (README format), the verified field-map screenshots, the physical-count comparison for T-1b,
and LogesTechs' written answers to the [technical request](../vendor-requests/logestechs-technical-request.md).
