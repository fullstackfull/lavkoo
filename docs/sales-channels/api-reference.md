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

Source: the official partner documentation at `noon-docs.noonpartners.dev`, read on **2026-11-08**. Hosts: production `https://noon-api-gateway.noon.partners`, static sandbox `https://noon-sandbox-api-gateway.noon.partners`.

**What is verified and what is not.** The HTTP method and the exact path of every operation below are quoted from noon's own reference pages. **The request and response SHAPES are not published in a readable form**: on every reference page the Request and Responses sections are rendered in the browser and come back empty to anything that fetches the page. So apart from the sign-in, no field name is known, and a path being registered in `NoonOperations` means only that the transport would not refuse it — not that a call exists.

Auth: a JWT signed **RS256** with the service account's private key (claims `sub` = `key_id`, `iat` = now in seconds, `jti` = a fresh uuid) is POSTed as `{token, default_project_code}` to `POST /identity/public/v1/api/login`; noon answers with `Set-Cookie` and the session lasts **30 days**. A token more than five minutes from noon's clock is refused, so one is made per attempt. The cookie is **not named** in the documentation, so the whole cookie string is kept and replayed. Every request must carry a `User-Agent`. The key file (`key_id`, `private_key`, `project_code`, `channel_identifier`) is downloaded once from noon's Access app. **The sign-in always goes to the production host, even for a sandbox account** — the documentation says to point only the other calls at the sandbox.

| Capability | Endpoint(s) | Notes | Built |
|---|---|---|---|
| Sign-in | `POST /identity/public/v1/api/login` | the one call whose request body noon publishes | yes |
| Identity | `GET /identity/v1/whoami` | the connection test: proves key file, project code, address allow-list and cookie in one read. Response shape not published, so no field of it is trusted | yes |
| Static sandbox | same paths, sandbox host | a mock: persists nothing, emits no events, never answers 5xx, builds answers from the request — so it cannot test stock or pricing logic. Its traffic still counts against the project quota | yes |
| OAuth (integrators) | `POST /identity/oauth/v1/token/create`, `POST /identity/oauth/v1/token/exchange`, consent at `https://oauth.noon.partners/` | hands an integrator a service-account credential scoped to a seller's project. Access token 1 h and single use; code 10 min and single use. Not built: this application holds one seller's own key file | no |
| Warehouses | `POST /v1/warehouses/list` | needed first, because stock is addressed by a (warehouse, `partner_sku`) **pair**. Rate limit 30 per 60 s (burst 15 per 30 s), unlike everything else | no |
| Stock | `POST /v1/stock-list` (read), `POST /v1/stock-update` (write) | by (warehouse, `partner_sku`). Bodies not published | no |
| Price | `POST /v1/pricing/get`, `POST /v1/pricing/upsert` | by partner SKU; a batch upsert does exist. Bodies not published | no |
| Catalogue | `POST /v1/product/upsert` | noon decides create from amend by our `partner_sku`; an item cannot enter quality review below 100% completeness. **FBPI's own page says an item must be listed in Seller Lab and cannot be created through the API**; the Content API names no fulfilment model, so whether an FBPI seller may call this is **UNVERIFIED** | no |
| Barcode | `POST /v1/barcode/map` | ties our `partner_sku` to a barcode, creating the barcode if noon lacks it | no |
| FBPI orders | `GET /v1/fbpi-order/:fbpi_order_nr/get`, `POST /v1/fbpi-orders/list` | a webhook announces an order and it is then fetched by number. Pages of at most 50, continued with the `next_token` the previous answer gave, filtered by `created_after` / `created_before` in UTC. The buyer's details are a separate call | no |
| Shipments | `POST /v1/shipment/create` | unique `integration_shipment_nr` and an AWB, noon's or our courier's | no |
| Event destinations | `POST /v1/destination/https-destination/create` | where noon sends events | no |
| Returns | — | a Returns service exists with one operation (listing return references); neither method nor path was readable, so nothing is registered | no |
| Settlements / fees | — | **none found in the reference.** noon's commissions cannot be read automatically, so `OperationalCosts` has nothing to ingest for this channel yet | no |

Rate limits: per **project code**, 1 500 per 60 s with a burst of 1 500 per 60 s on every operation except listing warehouses. Sandbox traffic counts against the same quota. Exceeded requests answer HTTP 429.

Fulfilment models, in noon's own words: **FBPI** — our warehouse, we fulfil, we handle returns, no inbound shipment. **FBN** — noon's fulfilment centre, noon fulfils and handles returns, inbound via an ASN API; **FBN stock is noon's, not ours, and must never enter the shared pool**, exactly as Amazon FBA does not. **FBPO** — purchase-order driven, for B2B2C. **FBP does not appear anywhere in noon's documentation**, so nothing here assumes it exists.

UNVERIFIED for noon: every request and response body but the sign-in's; pagination beyond FBPI's `next_token`; whether a sandbox needs its own account; the session cookie's name; whether `UpsertProduct` is available to an FBPI seller or to a seller's own service account rather than only an integrator's.

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
