# API reference: versions, endpoints, permissions, limits

Sources: the official documentation as read on **2026-11-06** (Amazon: `developer-docs.amazon.com/sp-api` and the `amzn/selling-partner-api-models` OpenAPI models; Trendyol: `developers.trendyol.com`, the *International (v3.0)* set for Gulf sellers). No endpoint or field here is guessed; where the documentation is silent or contradictory this document says **UNVERIFIED** and the code does not rely on it. Nothing in this module has been called against a live account.

The calls the code may make are an allow-list (`TrendyolOperations`, `AmazonOperations`): anything not registered is refused before it leaves the host.

## Amazon Selling Partner API

Auth: Login with Amazon refresh-token flow (`POST https://api.amazon.com/auth/o2/token`); a request carries `x-amz-access-token`, `x-amz-date` and a `User-Agent` of the form `Application/Version (Language=PHP/x; Platform=…)`. **No AWS signing** is involved any more. Regional host: `sellingpartnerapi-eu.amazon.com` for Saudi Arabia (marketplace `A17E79C6D8DWNP`); sandbox `sandbox.sellingpartnerapi-eu.amazon.com` (its EU list omits Saudi Arabia: use another EU marketplace id for sandbox runs). Refresh tokens live 365 days; a role added later needs a new authorisation. A 403 is answered with one token refresh and one retry; a second 403 is reported as a missing role. `invalid_grant` marks the account *revoked*, `invalid_client` marks it *auth expired*; both stop all work for the account until a person fixes the credentials.

| Capability | API / version | Endpoint(s) used | Roles (any one) | Built |
|---|---|---|---|---|
| Connection test | Sellers v1; probes | `GET /sellers/v1/marketplaceParticipations`, plus one harmless read per area below | per probe | yes |
| Orders | Orders **v2026-01-01** (no RDT; PII gated by roles) | `GET /orders/2026-01-01/orders` (`lastUpdatedAfter`, `marketplaceIds`, `includedData`, `paginationToken` with the identical query), `GET …/orders/{id}` | Inventory and Order Tracking, Amazon Fulfillment, Finance and Accounting, Pricing, Product Listing, … | yes |
| FBA vs FBM | the same | `fulfillment.fulfilledBy` + `salesChannel.channelName`: only `MERCHANT` on an `AMAZON` channel is FBM; `AMAZON` is FBA; anything else is **unknown** and never routed | | yes |
| Listings | Listings Items **2021-08-01** | `GET /listings/2021-08-01/items/{sellerId}` (search is capped at 1 000 results: windows are split in half), `PATCH …/items/{sellerId}/{sku}` (price: `purchasable_offer`; quantity: `fulfillment_availability` merge DEFAULT) preceded by `mode=VALIDATION_PREVIEW` (a PATCH with the real values that submits nothing; it goes through every write gate, so a rehearsal logs it and sends neither) | Inventory and Order Tracking OR Product Listing | yes |
| FBA stock | FBA Inventory v1 | `GET /fba/inventory/v1/summaries` (`details=true`) | Amazon Fulfillment OR Product Listing | yes |
| Fees and settlements | Finances **v2024-06-19** | `GET /finances/2024-06-19/transactions` (`postedBefore` ≥ 2 minutes ago) | Finance and Accounting | yes |
| Shipment confirmation | Orders **v0** (no equivalent in v2026-01-01) | `POST /orders/v0/orders/{id}/shipmentConfirmation` (204 = success) | Inventory and Order Tracking OR Direct to Consumer Shipping (Restricted) | yes |
| Catalog enrichment | Catalog Items 2022-04-01 | – | Product Listing | **no** |
| Notifications | Notifications v1 | delivered only to the seller's own AWS SQS/EventBridge, never to a URL; polling is the source of truth | | **no** |
| Reports / Feeds | Reports, Feeds 2021-06-30 | operations registered; `JSON_LISTINGS_FEED` is the only current listing feed (flat-file/XML listing feeds were removed by Amazon) | | **no** |

Rate limits (per account-app): searchOrders 0.0056 rps burst 20; listings 5 rps; FBA inventory 2 rps; listTransactions 0.5 rps burst 10; getMarketplaceParticipations 0.016 rps burst 15; confirmShipment 2 rps burst 10. The transport keeps to these with its own limiter and honours `Retry-After` on a 429.

UNVERIFIED for Saudi Arabia: carrier codes accepted by `confirmShipment` (the list is not published: `Other` + carrier name is sent); whether `value_with_tax` is the right price attribute for the KSA product types (read the Product Type Definition before enabling price publishing); the complete FeeType list (an open set: unknown types are kept for a person, never counted); how Multi-Channel Fulfilment orders appear (an order on a non-Amazon channel fulfilled through Amazon is `unknown`, not FBM); whether an order carrying `programs` (for example Easy Ship) is FBM for routing (the code reads `fulfilledBy` and `channelName` only and stores `programs` unused).

## Trendyol Marketplace Integration API (International / Gulf, `storeFrontCode: SA`)

Hosts: production `https://apigw.trendyol.com/integration`, stage `https://stageapigw.trendyol.com/integration` (stage needs the server's IP allow-listed: a 503 on stage means it is not). Auth: HTTP **Basic** (API key : API secret, different for stage and production). Mandatory `User-Agent: {sellerId} - {integration name}` (a request without it is a 403) and the `storeFrontCode` header (without it the request is processed as Turkey). `Accept-Language` ar|en. Global limit: 50 requests per 10 seconds per endpoint (429, no `Retry-After`), plus per-service tiers; price updates 30 per minute per barcode.

**Product V1 and Order V1 are retired and are not used.**

| Capability | Endpoint(s) used | Notes | Built |
|---|---|---|---|
| Connection test | approved products (size 1), order stream (1 day), statement `settlements` (Sale, 3 days) | a 401 = wrong key/secret; 403 = User-Agent / endpoint / IP | yes |
| Products (V2) | `GET /product/sellers/{id}/products/approved` and `/unapproved` (page ≤ 100; token beyond 10 000 records) | the barcode is the key of every stock/price call; approval state = which endpoint answered | yes (read) |
| Price and stock | `POST /inventory/sellers/{id}/products/price-and-inventory` (≤ 1 000 items, ≤ 20 000 units; an identical body within 15 minutes is rejected → deltas only), `GET /product/sellers/{id}/products/batch-requests/{batchId}` (kept 4 hours) | returns a batch id: HTTP 200 ≠ applied. A zero price is refused locally (Trendyol reads 0 as delisting) | yes |
| Orders (V2 package model) | `GET /order/sellers/{id}/orders/stream` (lastModified windows ≤ 14 days, history 3 months, `nextCursor`), `GET /order/sellers/{id}/v2/orders` (lookups by `orderNumber` / `shipmentPackageIds`) | the stream is used for sync because its filter is documented as *package last-modified*; `/v2/orders` does not say which timestamp `startDate` filters | yes |
| Picking and tracking | `PUT /order/sellers/{id}/shipment-packages/{pkg}` (`Picking`), `PUT …/{pkg}/tracking-details` (`cargoSenderNumber`, `providerCode`) | tracking only for packages on the seller-pays agreement (`whoPays = 1`); a Trendyol-pays package gets Picking only. Trendyol accepts only its own carrier codes (it lists neither Torod Point nor Torod's aggregator): map the carrier that really collects the parcel in the account's *carrier codes* | yes |
| Statement | `GET /finance/che/sellers/{id}/settlements` (`Sale`, `Return`; one type per request; window ≤ 15 days), `…/otherfinancials` (`DeductionInvoices`, `CreditNote`, `CommissionInvoice`, `PaymentOrder`) | row ids were re-issued in Aug 2026: identity is built from the row's content. No currency on the rows | yes |
| Webhooks | `POST/GET/PUT/DELETE /webhook/sellers/{id}/webhooks[/…]` | authentication `API_KEY` (header `x-api-key`), at-least-once, out-of-order; max 15; the address must not contain *Trendyol*, *Dolap* or *localhost* | yes |
| Product create/update, archive, unlock | `POST …/v2/products`, `…/*-bulk-update`, … | operations registered, **not built** | no |
| Unsupplied (cancel lines), splits, box info, carrier change, invoice link/file, ADEL | registered or not, **not built** | cancelling lines cancels a customer order and locks the product | no |
| Claims (returns) | `GET /order/sellers/{id}/claims` (window on last-modified, page ≤ 200) | read-only: the units a claim item has **Accepted** back are recorded on the order lines (set per claim, summed per line, capped at the units sold). Approve / reject (`…/claims/{id}/items/approve`, `…/issue`) is **not built**. UNVERIFIED: the plain path vs the `-gulf` path the Gulf OAS prints | read only |

UNVERIFIED: whether Order V1 still answers after its 15 Oct 2026 sunset (not used); what exactly `is4P` / `3pByTrendyol` mean for a Gulf local seller; the header Trendyol sends for `BASIC_AUTHENTICATION` webhooks (API key is used); whether a webhook body is wrapped in `content[]` or bare (both accepted); the currency of statement rows; stage rate limits and data retention; the offset of `orderDate` (verify on stage).

## noon Partner API

Source: the official partner documentation at `noon-docs.noonpartners.dev`, including the Markdown copy of each page (`.md`) and the quickstarts and guides, read on **2026-11-08**. Hosts: production `https://noon-api-gateway.noon.partners`, static sandbox `https://noon-sandbox-api-gateway.noon.partners`.

**THE SERVICE SEGMENT IS PART OF THE PATH.** `/stock/v1/stock-update`, not `/v1/stock-update`. An earlier version of this file recorded the operation paths without it, which would have been a 404 against every call. The reference pages document one full URL on one gateway host; they are not paths relative to a per-service base. Note the two identity prefixes differ and neither is a typo: `/identity/public/v1/api/login` and `/identity/v1/whoami`.

**The request and response bodies ARE published** — in the Markdown copies, the quickstarts and the guides — even though the reference pages render their Request and Responses sections in the browser. An earlier version of this file wrongly called them unpublished.

Auth: a JWT signed **RS256** with the service account's private key (claims `sub` = `key_id`, `iat`, `jti`) is POSTed as `{token, default_project_code}` to `POST /identity/public/v1/api/login`; noon answers with `Set-Cookie`. A token more than five minutes from noon's clock is refused, so one is made per attempt. The cookie is **not named**, so the whole cookie string is kept and replayed. Every request must carry a `User-Agent`. **The sign-in always goes to the production host, even for a sandbox account.** Session lifetime is stated as 30 days on the credentials page; expiry, renewal and what a 401 means are **not stated**, so a 401 is treated as a lapsed session, signed in for once more, and the call retried once.

| Capability | Method and full path | Body / notes | Built |
|---|---|---|---|
| Sign-in | `POST /identity/public/v1/api/login` | `{token, default_project_code}` | yes |
| Identity | `GET /identity/v1/whoami` | answers `user_code`, `username`. The connection test | yes |
| Warehouses | `POST /warehouse-platform/v1/warehouses/list` | `{next_token?, filters.fulfillment_system_code?}` → `{next_token, warehouses[{warehouse_code, display_name, fulfillment_system_code, is_active}]}`. **Rate limit 30 per 60 s** (burst 15 per 30 s), far tighter than everything else | yes |
| Stock, read | `POST /stock/v1/stock-list` | `{items[{warehouse_code, partner_sku}]}` → items with `qty`, `processing_time`, `stock_updated_at` and a per-item `status` | yes |
| Stock, write | `POST /stock/v1/stock-update` | `{items[{warehouse_code, partner_sku, qty, processing_time?}]}`. **`qty` is ABSOLUTE**: it becomes the new available quantity and is never added to what noon holds | yes |
| Price, read | `POST /pricing/v1/pricing/get` | `{items[{partner_sku, country_code}]}` → items with `price`, `msrp`, `is_active` and a per-item `status` | yes |
| Price, write | `POST /pricing/v1/pricing/upsert` | `{items[{partner_sku, country_code, price?, msrp?, is_active?}]}`. Omitting a field or sending `null` **leaves the stored value unchanged and does not clear it** | yes |
| Categories | `POST /content/v1/categories/list` | empty object body → `{categories[]}`, each a complete `family-product_type-product_subtype` path. A code outside this list is refused by the attribute and upsert calls alike | yes |
| Category attributes | `POST /content/v1/categories/attributes/list` | `{category_code}` → `{attributes[{attribute_code, attribute_type, is_mandatory, is_localizable, is_multivalued, attribute_options}]}` | yes |
| Catalogue upsert | `POST /content/v1/product/upsert` | `{skus[{partner_sku, size?}], brand, category, images[{url, sort}], attributes{}}` → `{sku_parent, variants[], status}`. noon decides create from amend by our `partner_sku`; an item cannot enter quality review below 100% completeness | no |
| Catalogue state | `POST /content/v1/product/content/get` | `{sku_parent}` → attributes, images (with `review_status`, `issues`) and `statuses[]` with completeness, missing and invalid attributes, QC status and errors. This is how acceptance and refusal are followed | no |
| Product groups | `POST /content/v1/product/group/upsert` | `{partner_group_ref, category?, brand?, attribute_codes[], sku_parents[]}`; the lists are full replacements | no |
| Barcode | `POST /catplat/v1/barcode/map` | `{items[{barcode, partner_sku, force_map?}]}`; creates the barcode if noon lacks it | no |
| FBPI orders | `POST /fbpi/v1/fbpi-orders/list` | `{warehouse_code, created_after?, created_before?}` with `next_token` as a **query** parameter → `{next_token, orders[{fbpi_order_nr, mp_code, mp_order_nr, currency_code, warehouse_code, items[], order_created_at}]}`. Pages of up to 50 | no |
| Shipment | `POST /fbpi/v1/shipment/create` | `{warehouse_code, integration_shipment_nr, fbpi_order_nr, awbs[{courier, awb_nr}], items[{mp_item_nr}]}`. The reference gives **no 200 body** | no |
| Event destinations, read | `GET /event-notifications/v1/destination/list` | → destinations with `url`, `event_types[]` and credentials redacted on read | no |
| Settlements, fees, commissions | — | **NONE anywhere in noon's index.** noon's commissions cannot be read automatically, `OperationalCosts` has nothing to ingest for this channel, and no financial figure for noon is invented. A Reports/export service exists whose pages were not read | no |

**A 200 IS NOT A SUCCESS.** Every batch call answers one `items[]` entry per item submitted, each with its own `status`. Success is `status.status_code == "OK"` **and** `status_id == 0`; anything else means that item failed and its value did not change. noon's pricing guide says it outright: *"a `200` response does not mean every price was set."* An item we sent and noon did not answer about is a failure too. A non-2xx means the whole request failed, with an `rpcStatus` body.

Rate limits: per **project code**, 1 500 per 60 s with a burst of 1 500 per 60 s on every operation except listing warehouses. Sandbox traffic counts against the same quota. The sandbox is a mock: it persists nothing, emits no events, never answers 5xx, and builds answers from the request, so it cannot test stock or pricing logic.

Fulfilment models: **FBPI** — our warehouse, we fulfil, we create shipments. **FBN** — a noon fulfilment centre, noon fulfils and handles returns, inbound by ASN; **FBN stock is noon's, not ours, and never enters the shared pool**, as Amazon FBA does not. **FBPO** — purchase-order driven, for B2B2C. **FBP** — described in noon's help centre, not on the reference or guide pages read here; nothing is concluded from that silence and nothing is built for it until it is read.

UNRESOLVED (recorded, not guessed): whether an **FBPI seller may create products through the Content API** — `what-is-fbpi` says items must be listed in Seller Lab and cannot be created via API, the Content API overview names no fulfilment model, and no page states a role model. **Who handles an FBPI return** — noon's comparison table says the seller, its prose on the same page says noon after pickup. Both recorded, neither chosen.

UNVERIFIED: the paths of every operation whose reference page was not read (OAuth, API user, the single FBPI order read, buyer data, order update, shipment cancel/read/AWBs, invoices, the sandbox order, creating an event destination, returns, FBPO, Offer, the Reports exports, the remaining catalogue operations). They are **not** guessed from their neighbours' pattern and are not registered; `NoonOperations::documentedButUnread()` names them so the gap is one page's reading away. Also unverified: an `X-Project` header that appears in a quickstart's error text but is documented nowhere.

## Statuses: how the channel's words become ours

| Trendyol package status | Ours | Amazon `fulfillmentStatus` | Ours |
|---|---|---|---|
| Awaiting, Verified | pending (never fulfilled) | PENDING, PENDING_AVAILABILITY | pending |
| Created | new | UNSHIPPED | new |
| Picking, Invoiced | processing | PARTIALLY_SHIPPED | processing |
| Shipped, AtCollectionPoint | shipped | SHIPPED | shipped (delivered when every package is) |
| Delivered | delivered | CANCELLED | cancelled |
| Returned | returned | UNFULFILLABLE | failed |
| UnDelivered | failed | anything else | awaiting review |
| Cancelled, UnSupplied, UnPacked | cancelled (terminal, lines not sold) | | |
