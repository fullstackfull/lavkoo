# Storefront flag runbook (GCC-02/03, GCC-11)

What an operator needs to know before `GCC_STOREFRONTS_ENABLED` is turned on, and how a country is switched off afterwards. Written from the
code of the draft stack #47, #48, #49, #50 and #53, and from the second independent review of it (37 agents, every finding reproduced by
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

1. **Migrations have run**: #38, #43 and #47. The products migration adds `storefront_country CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT
   NULL DEFAULT 'SA'` and the index `products_storefront_id_idx (storefront_country, id)`. Measured on MySQL 8.0.46 with 200,000 products
   (62 columns, 15 indexes): both statements about 1 s together, the column added instantly, the index online (`ALGORITHM=INPLACE,
   LOCK=NONE` accepted), a concurrent writer saw no error and a worst stall of 83 ms. **MariaDB 11.4.2 (the pilot's engine) has not been run
   with this migration.** The storefront tests now run on it in CI. Before scheduling the ALTER on the pilot, read `@@version`,
   `@@lock_wait_timeout`, `@@alter_algorithm`, `@@old_alter_table` and `@@innodb_online_alter_log_max_size`, and rehearse it on a staging
   copy.
2. **The stack and the search fix are merged in order**: #46 first (translated-name searches and the search category filter must go through
   `Product::active()`, which is what narrows them), then #28, #37, #38, #39, #42, #43, #47, #48, #49, #50, #53. The independent fixes #54
   (cart row ownership), #55 (referral first order) and #56 (payment hook state) do not depend on the stack.
3. **The admin and seller tools are country-aware (GCC-14).** They are unbound and list every country's products together, and nothing sets a
   product's country yet. Do not enable the flag with a non-Saudi product present before that work exists.
4. **The legal texts of every country that will sell are approved** (#42, #49). The sale routes refuse a storefront whose texts are not
   approved; the Saudi storefront is grandfathered.
5. **Run `php artisan cache:clear` once**, after the migration is deployed and again every time the flag is flipped, in either direction.
   Products cached by the previous release do not hide the new column from JSON (`$hidden` is part of the cached object), and the
   per-storefront copies written while the flag was on are not cleared by a product edit made while it was off, so they would be served again
   after the next flip.

## Switching a country off

**Set the country inactive** (`gcc.storefronts.countries.<CC>.active` false). Every storefront stays narrowed: the Saudi storefront still
shows none of that country's products, and a visitor who asks for it is served the default storefront. A payment that was started in that
country is still finished there.

**Do not turn the flag off once another country has products.** Without the flag nothing is narrowed, so every country's products appear in
every list, the cart accepts them and they are priced in the wrong currency. A payment started in another country and finished after the flag
is off is built from every checked cart row, and its order can exceed what was paid (reproduced: paid 275, order 375). Both behaviours are
pinned by `StorefrontKillSwitchTest`.

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

## How a request is served

The storefront comes from, in order: an explicit `?storefront=`, the `X-Storefront` header (exactly one value), the first path segment, the
default country. The customer app sends the header on every API call while its own flag is on, including `POST /api/v1/digital-payment` and
`/api/v1/add-to-fund`, so a payment records its storefront without any change to the WebView it opens afterwards. A gateway callback carries
no storefront at all; the order is built in the storefront recorded with the payment (`PaymentStorefront`). Every API v1 answer says
`Vary: X-Storefront` while the flag is on.

## Known limits (not defects of the stack)

- No storefront picker exists in the website or the apps. Path prefixes and the header are the only way in.
- Orders carry no country. It follows from their lines (`order_details` to `products.storefront_country`); a product never changes country.
- Wishlist and compare adds accept another country's product id and store a row for the customer's own list; they are not shown in another
  storefront. The quick view answers HTTP 500 for an id the storefront cannot see (it did so for hidden products already).
- The seller and admin POS, the marketplace order materializer and the sales-channel webhooks are not behind the sale gate. Whether a
  point-of-sale sale is a storefront sale is an owner decision.
- Currency display, tax and three-decimal currencies (KWD, BHD, OMR) are separate work (GCC-05).
- The auction module's products carry no country.
- `storage/logs/order_generate_test.txt` is written by the payment hook on every paid order (customer id, guest id, address id, order ids).
  It is debugging that was never removed and should be.
