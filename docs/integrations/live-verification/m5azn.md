# M5AZN — live verification checklist

Our store is a **seller account** on M5AZN. The documented API (m5azn-api.stoplight.io, retrieved 2026-11-05) lists nine
operations and **publishes no response schemas**, no webhook, no cancel/return call and no margin call. This checklist
turns every one of those unknowns into a call to make and an answer to record. Read the
[ground rules](README.md) first: Level 1 = read-only, Level 2 = needs written authorization.

## What we need before starting

| Need | From | Notes |
|---|---|---|
| Seller **e-mail and password** of the store's M5AZN seller account (API access enabled) | Store owner, entered into *Integration Center → M5AZN* (write-only) | Never pasted anywhere else. If M5AZN offers a **sandbox/test seller account**, use it for every Level 2 call. |
| The base URL for **KSA** (default `https://m5azn.com`; M5AZN's docs list three regional servers) | Store owner / M5AZN | The settings form accepts only `m5azn.com`, `m5azn.ae`, `m5azn.om` hosts over https. |
| Platform name, country (`SA`), category the imported products go into | Store owner | Settings screen. |
| **At least 3 products selected** in the M5AZN account, including one with variants (sizes/colours) and one with zero stock | Store owner, in M5AZN's own UI | See M-3. |
| A **test receiver** (staff phone and address in a KSA city) | Store owner | For the Level 2 order only. |

## Level 1 — read-only checks

| ID | Call (as the app makes it) | Capture | Pass when | Unlocks |
|---|---|---|---|---|
| **M-1 Login** | `POST /api/v1/auth/login` (email, password) | status, the **field that carries the token**, token lifetime (`expires_in`/`exp`), what a wrong password returns, whether login is rate-limited | A bearer token is returned and works on M-2 | Connection test; token caching/refresh (the app refreshes 24 h before expiry — confirm the real lifetime is longer than that) |
| **M-2 My products** | `GET /api/v1/seller/myproductlist` (optionally `category_id`, `brand_id`, `product_type` = `local` (default) / `ali_express` / `global` / `digital`) | The **complete JSON** of one page: envelope (`data`? `products`? `items`?), pagination fields (`page`, `per_page`, `total`, `next`?), and per product: id, name (Arabic/English), SKU, barcode, **description (is it HTML?)**, stock, cost, price, image URLs (host names!), variants (structure) | Every row has a stable id and a name; pagination is understood (or proven absent) | **Catalogue field map** (`id`, `name` required; `sku`, `barcode`, `description`, `stock`, `cost`, `retail_price`, `thumbnail`, `images`, `variants` + variant fields). Without it nothing is imported |
| **M-3 Selection semantics** (the "Supply / Import" experience) | In M5AZN's UI select a *new* product (the button the business calls *Supply/Import*), then immediately re-call **M-2** every 30 s for 10 min | Whether and **how fast** it appears; whether it appears in the default `product_type=local` list or only under another type; whether un-selecting removes it; whether there is an *approval* state | The product appears in M-2 under a known `product_type`, within a bounded time | The truth behind "select on M5AZN → appears in Pharmacy KSA": delay = M5AZN's own latency + our poll interval (default 30 min, tick every 5, *Sync now* is immediate). If it appears only under `global` or `ali_express`, set *Catalogue product types* accordingly |
| **M-4 Product details** | `GET /api/v1/product/details/{id}` | Full JSON for a simple product and for one with variants | Matches M-2 plus variant detail | Variant mapping (each variant needs its **exact SKU** — orders are sent with it) |
| **M-5 Stock-change feed** | `GET /api/v1/products/qty-changed` with `days`/`hours` | Parameters that work, response rows (id, quantity, variant?), how far back it goes, maximum window | A change made on M5AZN shows up with the right quantity | **Inventory field map** (`id`, `stock`, `variant_sku`) → *Follow M5AZN stock levels*. Until verified, M5AZN stock is not used |
| **M-6a Order list** | `GET /api/v1/orders` | Envelope, pagination, per order: **reference field**, **status field**, **our order id field** (what we sent as `id`), items | Can find an order by our id | **Orders field map** (`reference`, `status` required) → reconciliation of ambiguous submissions and status polling |
| **M-6b Order details** | `GET /api/orders/{id}` | Same | Status and reference readable | Polling (`fulfillment:poll`, every 15 min) |
| **M-7 Status vocabulary** | Collect every distinct `status` value seen on real orders (ask M5AZN for the complete list too) | The words, their meaning, which are final | A complete mapping table exists | **Status map** (JSON in the settings): word → `submitted / processing / in_transit / out_for_delivery / delivered / cancelled / returned / delivery_failed …`. An unmapped word changes nothing and is shown on the shipment |
| **M-8 Categories** | `GET /api/v1/catalog/categories` | ids and names | Matches M5AZN's UI | Optional category filter |
| **M-9 Limits** | Ask / observe | rate limit, timeouts, maintenance windows, max page size | Documented | Poll intervals (minimum 5 min enforced by the settings form) |

**Setting the maps** — after capturing M-2/M-5/M-6a: *Integration Center → M5AZN → Field map → Fetch sample*, choose the
paths, *Verify*. Then *Sync products now*; the staged rows appear on **M5AZN products**. Enable *Import selected products into
the store automatically* only after a person has looked at a few staged rows. Imported products are **unpublished** (turn
on *Publish imported products* only deliberately) and an item with no price is never published.

## Level 2 — requires written authorization (template in the [README](README.md))

| ID | Call | Why it needs authorization | Procedure | Capture | Pass when |
|---|---|---|---|---|---|
| **M-10 Create order** | `POST /api/v1/orders` with the documented body: `id`, `total`, `payment_method` (`cod`\|`card`), `cod_amount`, `order_created_at`, `platform_name`, `shipping_info{address,country,country_code,city}`, `customer_info{first_name,last_name,email,phone}`, `items[{id,product_id,sku,name,qty_ordered}]` | Creates a real supplier order | **One** order, test receiver, one low-value in-stock item, `id` = a number reserved for testing (e.g. `9000001`); tell M5AZN in advance; cancel in M5AZN's dashboard immediately | Status code, **complete success body**, the field holding M5AZN's order reference, validation-error body for a deliberately bad field (in a *separate* authorized call) | A reference is returned and matches what M-6a shows |
| **M-11 Duplicate behaviour** | The *same* `id` submitted a second time (only if M-10 was cancelled/finished and M5AZN agrees) | Could create a second order | Authorized separately | Response (rejected? returns the existing order? creates another?) | **Documented**. This decides whether our "never blind-resend; reconcile first" rule is enough or M5AZN also protects us |
| **M-12 Cash on delivery** | Same order with `payment_method=cod`, `cod_amount` | A COD order obliges the courier | Authorized separately; amount small | How M5AZN treats `cod_amount` vs `total`; who collects; how/when settled | Written confirmation from M5AZN of the settlement terms |
| **M-13 Insufficient stock** | Order for more than the stock | Possible back-order | Authorized separately | Error shape / code | A distinct, parseable error |

Set from M-10: **Order reference path** (*Settings → Order response reference path*). Until it is set, a 2xx response with no
readable reference is treated as *outcome unknown*, never as acceptance (and never re-sent).

## What to ask M5AZN in writing (also in the [request](../vendor-requests/m5azn-technical-request.md))

Response schemas for M-2/M-5/M-6; the complete status list; a status webhook (or a documented polling rate); a cancel /
return call; cash-on-delivery settlement; margin read/write; sandbox; rate limits.

## Gate for "M5AZN can go live"

All of: M-1, M-2, M-3, M-4 (if variants), M-6a/b, M-7 done and maps verified; M-10 and M-11 recorded under authorization;
M-12 recorded **if COD will be taken for M5AZN items**; a status map covering every status word seen. If the status words
cannot be established, the integration can still dispatch but delivery must be closed by an operator — decide whether that
is acceptable (it is a *non-blocker* with a manual process, a *blocker* if the business expects automatic delivery status).
