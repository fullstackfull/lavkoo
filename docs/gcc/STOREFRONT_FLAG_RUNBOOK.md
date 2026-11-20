# Storefront flag runbook (GCC-02/03, GCC-11)

What an operator needs to know before `GCC_STOREFRONTS_ENABLED` is turned on, and how a country is switched off afterwards. Written from the
code of the draft stack #47, #48, #49, #50 and #53, and from the second and third independent reviews of it (every finding reproduced by
running it). Nothing here has been run on the pilot server or on production.

## What the flag is

`gcc.storefronts.enabled` (env `GCC_STOREFRONTS_ENABLED`, default `false`). While it is false every request is the Saudi storefront, no query
mentions `storefront_country`, no cache key changes, and the code does not read the storefront configuration: a missing, stale or wrong
`gcc` config falls back to the built-in Saudi storefront instead of failing. While it is true the same configuration is validated, and a
broken one fails the request loudly.

Each product row belongs to one country (`products.storefront_country`, default `SA`). Price, stock and availability live on the row, so they
are that country's own. A storefront request is narrowed to its country's products and cart rows; the admin, vendor and seller panels, the
seller APIs (`api/v2`, `api/v3`), queues and the console are never narrowed and see every country.

## Before turning it on

1. **Migrations have run**: #38, #43 and #47. The products migration adds one column, `storefront_country CHAR(2) CHARACTER SET ascii
   COLLATE ascii_bin NOT NULL DEFAULT 'SA'`, and no index (see "The column has no index" below). Measured on MySQL 8.0.46 with 200,000
   products (62 columns, 15 indexes): the column was added instantly. **MariaDB 11.4.2 (the pilot's engine) has not been run with this
   migration.** The storefront tests now run on it in CI. Before scheduling the ALTER on the pilot, read `@@version`, `@@lock_wait_timeout`
   and `@@alter_algorithm`, and rehearse it on a staging copy.
2. **The stack and the search fix are merged in order**: #46 first (translated-name searches and the search category filter must go through
   `Product::active()`, which is what narrows them), then #28, #37, #38, #39, #42, #43, #47, #48, #49, #50, #53. The independent fixes #54
   (cart row ownership), #55 (referral first order) and #56 (payment hook state) do not depend on the stack.
3. **The admin and seller tools are country-aware (GCC-14).** They are unbound and list every country's products together, and nothing sets a
   product's country yet. Do not enable the flag with a non-Saudi product present before that work exists.
4. **The legal texts of every country that will sell are approved** (#42, #49). The sale routes refuse a storefront whose texts are not
   approved; the Saudi storefront is grandfathered.
5. **Currency is decided (GCC-05).** This stack separates products, prices and stock by country; it does not change how an amount is shown.
   Prices are stored in a base currency and converted for display with the session's currency, which is the system default currency unless the
   visitor picks another one, and no price code reads the storefront's currency. Until GCC-05 decides how a storefront's currency reaches the
   converters, a Kuwaiti product shows the system default currency's symbol and rate, and an amount handed to the converters twice would be
   converted twice. Orders, invoices, emails and exports carry no currency of their own either (see `docs/gcc/audits/2026-11-16-price-stock-map/`).
6. **Run `php artisan cache:clear` once**, after the migration is deployed and again every time the flag is flipped, in either direction.
   Products cached by the previous release do not hide the new column from JSON (`$hidden` is part of the cached object), and the
   per-storefront copies written while the flag was on are not cleared by a product edit made while it was off, so they would be served again
   after the next flip.

## The column has no index

The first versions of #47 added an index on the column. The third review measured the one that was kept, `(storefront_country, id)`, on
MySQL 8.0.46 with 200,000 products: with the flag on the optimizer merged it with the selective seller and category indexes, even while every
product was Saudi, so a vendor's product list went from 7 ms to 102 ms, a category list became 5 to 6 times slower, and every count behind a
paginated list 1.6 times slower. With `index_merge_intersection=off` the plans were the flag-off plans again, which is why it was the index
and not the filter. MariaDB 11.4.2 was not measured. The migration therefore adds the column only.

What that leaves: an id-ordered public list (`ORDER BY id DESC LIMIT 20`) of a storefront whose products are older than another country's
import steps over the newer foreign rows before it finds its own. Measured earlier on the same table: 1.9 ms became 281 ms, and page 100 of the
list 8 ms became 337 ms, when most of the newer rows were another country's. Nothing is lost while every product is Saudi.

When the first non-Saudi catalogue is about to be imported, read the plans on a copy of the real data (the shop page, a vendor page, a
category page, the search page, and the count behind each). Add an index only if a list is slow, and read the plans again afterwards:

```sql
ALTER TABLE products ADD INDEX products_storefront_id_idx (storefront_country, id), ALGORITHM=INPLACE, LOCK=NONE;
```

Rolling the products migration back drops an index of that name if one exists.

## Switching a country off

**Set the country inactive** (`gcc.storefronts.countries.<CC>.active` false). Every storefront stays narrowed: the Saudi storefront still
shows none of that country's products, and a visitor who asks for it is served the default storefront. A payment that was started in that
country is still finished there, also if its entry was deleted from the configuration in the meantime (the recorded code is bound without
the entry). Deactivate rather than delete while the country has products, so that its path and currency stay on record and the products it
owns can still be told from a typo (the query under "What to watch" lists the codes that are not configured).

**Do not turn the flag off once another country has products.** Without the flag nothing is narrowed, so every country's products appear in
every list, the cart accepts them and they are priced in the wrong currency. A payment started in another country and finished after the flag
is off is built from every checked cart row, so before the order-time rule its order could exceed what was paid (reproduced: paid 275, order
375). It no longer can: an order whose checked rows belong to two countries is refused before anything is written (`OneSellingCompanyPerOrder`,
always on), because no company may invoice another company's sale. The customer is then left paid with no order, which a refund settles and
which is why this is still not a kill switch. The catalogue behaviour is pinned by `StorefrontKillSwitchTest` and the order-time rule by
`StorefrontCheckoutSingleCompanyTest`.

The rule holds at every writer, not only at the checkout, because four of them never call `OrderManager::generateOrder()`:

| writer | what its lines come from | how it refuses |
| --- | --- | --- |
| `Admin\POS\POSOrderController::placeOrder` | a session cart | the screen's own error toast, nothing written |
| `Vendor\POS\POSOrderController::placeOrder` | a session cart | the screen's own error toast, nothing written |
| `RestAPI\v3\seller\POSController::place_order` | a request payload | `422 order_mixes_selling_companies` |
| `SalesChannels\Dispatch\ChannelOrderMaterializer` | a channel order's lines | the dispatch is blocked with `reason_code = order_mixes_selling_companies` and is **not** retried |

A point-of-sale sale is a sale, so a till holding two countries' products sells neither of them on one receipt; each country's products can
still be rung up on a receipt of their own. A channel order that mixes two countries is not retried, because it will never stop mixing them:
the dispatch row carries the reason and waits for a person. Pinned by `PosSingleCompanyTest` and `ChannelOrderSingleCompanyTest`.

Still open at the three tills: a sale paid from the customer's wallet is debited before the products are read, so a refused mixed sale leaves
the wallet debited with no order. That ordering is the tills' own and predates the rule — their existing out-of-stock refusal sits after the
same debit — and moving a payment debit is not part of the rule.

The rule reads the two halves of the selling scope apart — country against country, company against company — because a row says nothing
about a half it leaves null. That is the only state a backfill filling `legal_entity_id` row by row can be observed in: one product carrying
a company, the next not yet reached. Reading the halves as one joined string refused those two Saudi products as if they were two selling
companies', which would have refused live Saudi orders at every till, at the customer's checkout and at the channel dispatcher (where a
blocked dispatch is never retried) for the whole length of the migration. The half-backfilled state is pinned as allowed at all three tills,
at the customer API, on the checkout page and at the materializer.
Rolling the products migration back is refused while a product of another country exists, because dropping the column would merge the
catalogues for good.

## What to watch

```sql
-- how many products each country has
SELECT storefront_country, COUNT(*) FROM products GROUP BY storefront_country;

-- products no storefront can show: the code is not a configured country (an import, a typo, a copy made by hand)
SELECT id, storefront_country FROM products WHERE storefront_country NOT IN ('SA','AE','KW','QA','BH','OM');
```

The column is compared byte for byte on MySQL and MariaDB (`sa` is not `SA`), as it is in PHP. Nothing in the application writes it yet, so a
value outside the six codes can only come from a direct write or from the future copy-to-country tool, which has to validate against
`gcc.storefronts.countries`.

The order-time rule reads the same column the same way, so a hand-written `sa` or an empty string makes **two** selling scopes out of one
catalogue, and a checkout holding both is refused — a live Saudi checkout among them. Keep the column NOT NULL as the migration creates it
(`char(2)` default `SA`): a row whose code is null names no scope and is not judged at all, so making it nullable would quietly stop the rule
judging those rows. Both readings are pinned by `StorefrontCheckoutSingleCompanyTest`.

## How a request is served

The storefront comes from, in order: an explicit `?storefront=`, the `X-Storefront` header (exactly one value), the first path segment, the
default country. The customer app sends the header on every API call while its own flag is on, including `POST /api/v1/digital-payment` and
`/api/v1/add-to-fund`, so a payment records its storefront without any change to the WebView it opens afterwards. A gateway callback carries
no storefront at all; the order is built in the storefront recorded with the payment (`PaymentStorefront`). Every route of
`routes/rest_api/v1/api.php` answers with `Vary: X-Storefront` while the flag is on (the module routes registered under the same prefix, the AI
auction auto-fill and the tax module, read no catalog and say nothing).

## Known limits (not defects of the stack)

- No storefront picker exists in the website or the apps. Path prefixes and the header are the only way in.
- Orders carry no country. It follows from their lines (`order_details` to `products.storefront_country`); a product never changes country.
- Wishlist and compare adds accept another country's product id and store a row for the customer's own list; they are not shown in another
  storefront. The quick view answers HTTP 500 for an id the storefront cannot see (it did so for hidden products already).
- The seller and admin POS, the marketplace order materializer and the sales-channel webhooks are not behind the sale gate. Whether a
  point-of-sale sale is a storefront sale is an owner decision.
- Currency display, an order's own currency, tax by country and three-decimal currencies (KWD, BHD, OMR) are separate work (GCC-05); see the
  first step above. A product row is not converted from one country's price to another's, but nothing yet makes a country's amount display in
  that country's currency.
- The link in an abandoned-cart email carries the storefront the cart was made in, and the first page it opens is served from it. The website
  has no storefront picker yet, so the next click goes back to the default storefront.
- The auction module's products carry no country.
- `storage/logs/order_generate_test.txt` is written by the payment hook on every paid order (customer id, guest id, address id, order ids).
  It is debugging that was never removed and should be.
