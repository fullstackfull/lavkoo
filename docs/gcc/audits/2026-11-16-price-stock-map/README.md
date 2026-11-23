# Price and stock characterization map (2026-11-16)

Status: static inventory with independent verification, plus two checks run by hand (marked below). Nothing in it changes application code.

## What it is

A workflow of 404 agents (about 84 million tokens, 20.6 hours; run id `wf_23eaef91-56d`) mapped every read and write of a product's price and
stock in the Saudi storefront's code (web, customer API, seller APIs, POS, admin, jobs, marketplace and provider sync) and classified each
site. Four sweep lenses (price, stock, totals with tax and discount, provider sync and holds) produced **399 sites**; a separate agent then
verified each site against the code and said whether the proposed place for a change was right.

Files here: `sites.csv` (one row per site: where it is, what quantity it touches, read or write, which surface, whether it matters to a
storefront, whether the verifier agreed with the proposed seam, and the owner the verifier named instead) and `gaps.md` (the 29 areas the
critic found missing or thin).

## Counts

- 399 sites: 272 reads, 127 writes.
- By surface: web 213, admin 48, customer API 36, provider sync 26, seller API 19, POS 18, internal 33, jobs 6.
- By quantity: stock read 63, stock write 58, unit price 75, total 63, discount 49, tax 36, shipping 19, stock hold 14, stock sync 11, other 11.
- 332 sites matter to a storefront, 67 do not.
- The verifiers agreed with the sweep's proposed seam for 10 sites and named a different owner for the other 389.

## How it relates to what was built

The sweep assumed a per-country **price table** next to the product and proposed a seam for each site. The design that was built is different
and simpler (GCC-02/03, PRs 47 to 53): a product row belongs to one country, so price and stock live on the row and none of these sites has to
change. That makes most of the proposed seams unnecessary. What stays useful is the inventory, the surfaces, and what the verifiers found
about **units and currency**, which no price-table design avoids. The owners the verifiers named most often are the places where one change
reaches many sites: `getProductPriceByType` (about 30 sites), `VatTaxManagement::getTaxSystemType` (about 16), `InventoryService` (10),
`InventorySyncService` (about 11), `OrderManager::processOrderGenerateData` (7), `CouponService` (5), `LocationStockReader` (5),
`ProductManager::updateProductPriceInCartList` (4), `CartManager::product_stock_check` (4).

## What it changed in our understanding

**1. Currency is not solved by a country row (GCC-05). Checked by reading `app/Utils/currency.php`.** Under the multi-currency model the entered
price is converted to a base currency (USD) at save time (`currencyConverter`) and converted back at display time (`webCurrencyConverter`,
`usdToDefaultCurrency`) with the session's currency, which is the system default currency unless the visitor picks another one. Under the
single-currency model the stored number is shown as it is. `StorefrontContext::currency` is read by no price code. So a Kuwaiti product row
shows the system default currency's symbol and rate until GCC-05 decides how a storefront's currency reaches the converters; handing a
country's amount to `webCurrencyConverter` would convert it twice. An earlier line in the draft PR descriptions ("prices in a country's catalog
are in that country's currency by definition") was true of the design, not of the code; it is corrected in PR 47 and in the runbook of PR 53.

**2. Orders carry no currency of their own.** The map reports that nothing but the MyFatoorah attempt stores one, and that invoices, the
order-placed email, the abandoned-cart email, the PDF and Excel exports and the transaction reports re-compute totals from base amounts and
print them in the default currency (gaps 6 to 9, 17 to 21). I found no migration in the repository that adds an order currency (the base
`orders` table comes from the installation dump, which is not in the repository, so that is not proof). A Kuwaiti order cannot be shown later in
the currency it was sold in until it records one.

**3. The cost price is sent to customers. Run by hand.** `Product::$hidden` lists only `storefront_country`, and no customer-facing code strips
`purchase_price`. A request to `GET /api/v1/products/reviews/{slug}` for a product with `purchase_price = 17.5` returned the embedded product
with `"purchase_price": 17.5`. The customer app's `product_model.dart` declares the field, which suggests the other product responses carry it
too. `order_details.product_details` is `json_encode($product)` (`app/Utils/OrderManager.php:1258`), the whole product at the time of the
sale, and the customer's order-details API returns it, so a customer's own orders carry it as well. This is the template's behaviour and
unrelated to storefronts. It is an owner decision: strip it from customer-facing serialization (API v1 and the web JSON) and keep it for the seller and
admin tools, which need it. It cannot be hidden on the model, because the seller apps read it from the same model through API v2 and v3.

**4. The writers that touch the base value are many, and none knows a storefront.** Bulk price changes (`BulkPriceOperation`, run by
`seller:run-stuck-bulk-jobs`), seller automation rules (`SetDiscountAction`, every 15 minutes), the normal product save, the seller v2 and v3
saves, the AI pricing suggestions and the price-history observer all write `unit_price`, `discount` or `discount_type` of one row. In the
row-per-country design each acts on one country's row, which is correct. Two things follow: the price history records no country or
currency, and a copy of a product to another country (GCC-14) has to copy its price deliberately and in the right unit.

**5. Provider and marketplace stock is keyed by the row.** The sync and hold sites (26 provider-sync sites) read and write the row's own
`current_stock`, so two copies of a product in two countries keep separate stock. The SKU rule (the product `code` and `barcode` unique per
country, not globally) is still open for GCC-14.

**6. Console and queue contexts render money.** The abandoned-cart reminder (`cart:remind-abandoned`), the queued order-placed email and the
invoice PDFs run without a storefront request, so they use the default currency and, with `StorefrontCatalog`, see every country's rows. An
email for a Kuwaiti order would show Saudi currency. Part of GCC-05.

## What to do with it

1. GCC-05 first: decide how a storefront's currency reaches the converters and what an order records (a currency and the rate used), before any
   non-Saudi storefront sells.
2. The tax country: `VatTaxManagement::getTaxSystemType` and `TaxController` derive the tax setup without a country (the verifiers name the
   TaxModule as the owner).
3. Decide about the cost price in customer JSON (finding 3).
4. GCC-14 (admin and seller tools) with the price history, the SKU rule and the copy-to-country tool in mind.

## Limits

The inventory was produced by agents reading code and verified by other agents reading code; it is not an execution trace. Only findings 1
(by reading the helper) and 3 (by running the request) were checked by hand. The line numbers are those of the checkout the sweep read
(`gcc/p1-storefront-country-context`, before the stack of PRs 47 to 53), so they can be a few lines off at a later commit.
