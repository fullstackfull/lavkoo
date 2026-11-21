# K-09: the markets a seller and a seller staff member may use (with K-04 and K-V2)

The grant of markets for the seller API, the refusal of anything outside it, per-market permissions, the market a product of the
seller app is saved in, and the money rule for a view that spans markets. Everything ships off: while `gcc.seller.market_scopes`
(env `GCC_SELLER_MARKET_SCOPES`) is false, or the grant table has not been created, the seller API answers exactly what it
answered before, and a principal with no grant row is unrestricted whatever the switch says. Nothing here is MET until CI has run
on the pushed head. The seller app's version, package id, bundle id and Firebase settings are untouched.

Base: `gcc/p1-seller-market-contract` (the `me` endpoint and the order market filter) merged with
`gcc/p1-catalogue-visible-per-storefront` (the storefront context, the product country column, the attribute endpoint that takes a
country). The app half stacks on `gcc/p1-seller-market-selector`, which already sends `X-Storefront` and lets a seller pick a
market; this adds only the grant.

## 1. The grant is a row, and a market a request names is a request

`seller_market_scopes`: one row per principal and market, with the role that applies to a staff member in that market.

| Column | Meaning |
|---|---|
| `seller_id` | The shop. |
| `staff_id` | The staff member, or `0` for the shop's own account — not NULL, so the unique index holds on MySQL and MariaDB, which count two NULLs as different. |
| `country` | `SA`, `AE`, `KW`, `QA`, `BH` or `OM`, two characters compared byte for byte (`ascii_bin`), as `products.storefront_country` is. |
| `seller_role_id` | The role that applies to that staff member in that market. NULL means the role on `seller_staff`, which is what every staff member has today. |

No route creates or widens a row. `EnforceSellerMarketScope`, declared on the authenticated seller group so a route cannot forget
it, reads the market a request names (`X-Storefront` first, then the `storefront` field the order list already read), compares it
with the rows, and answers **403 with a code and no data** when they do not hold it. So the only way to widen a grant is to change
the grant: a caller who can set a header cannot reach a market they were not granted.

```json
{"errors": [{"code": "market_scope", "message": "Your account does not work in that market"}]}
```

A client that did not ask for JSON is served the seller panel's Access-denied page instead, with no market data in it.

## 2. Different permissions in different markets is one nullable column

`seller_role_id` on the grant row. The permission machinery is unchanged: `SellerPermissionService::permissionsOfRole()` reads a
second role **of the same shop**, and `SellerPrincipal::withPermissions()` returns a copy of the principal holding that market's
set, so `seller_can`, the audit actor and every query that scopes on `sellerId()` keep meaning what they meant. A role of another
shop, a role switched off and a role that is gone all grant nothing.

A request that names no single market works across the grant, and is then held to what the staff member may do in **every** market
of it: a clerk who may refund in Riyadh and only read in Dubai may not refund a list that holds both.

## 3. What a seller sees

| Surface | Bound to the grant by |
|---|---|
| Orders | `OrderStorefront::narrowToGrant()`, applied **before** the market filter, so a filter can only narrow inside the grant. The grant also keeps the orders with no record when it holds the default market, as the platform reads such an order. |
| Products, their reports, their exports, their bulk jobs, the rules that change them | `SellerMarketScope::narrowQuery()` — one narrowing on the column they all read, so the surfaces cannot disagree. |
| Stock | The same narrowing on the inventory query, so the overview, the movement filter and every figure drawn from it hold one line. |
| Payout and bank details, staff management, account deletion | `seller_all_markets`, declared **beside** `seller_owner` and not instead of it: these cover every market at once, so a principal granted some of the platform's markets holds authority in those markets and not over the shop. Only the platform owner sees every market, and they do not come through this API. |

`me` gains the grant: `market_scope` becomes `limited`, `allowed_markets` the list, and `permissions_by_market` what the caller may
do in each. The ninth key is present **only while a grant is**, so a principal with no grant — every principal while the switch is
off — is answered the eight keys of before, in the same order.

## 4. A product of the seller app is saved in the market the seller works in

`ProductService::getAddProductData()` gave a seller product no country at all, so it took the column's own default (`SA`) whatever
the platform's default storefront was. The seller app's `POST products/add` builds its own insert, and that is where the market now
goes: `SellerMarketScope::newProductAttributes()`, which is empty for a principal with no grant, so the insert is byte-identical
for every seller while the switch is off.

A seller granted several markets who names none has no market to save the product in, and the table default would put it in a
market they may not hold, so the create is refused and says which market is missing — the shape `StaffCountryScope::whyItMayNotImport()`
already uses for a restricted employee's bulk import.

## 5. The money rule: a sum of riyals and dinars is not a number

`SellerMarketTotals` carries the rule, which is the one `SettlementCurrency` already applies to the seller ledger ("the net is kept
by currency so the page never adds two", E-17.1) and `ABalanceCannotBeLeftUnknown` applies to a settlement of a mixed ledger.

- **The statement** answers the five keys of before while the account is in one currency — every seller today. Where it holds two,
  it answers **no** single `range`, `buckets` or `withdrawable` figure: the real figures per currency, and `totals_refused_because`
  where the reader is. Printing the sum silently is the one outcome that can mislead an operator into a wrong decision.
- **The stock screen** counts per market and adds the counts, because a unit in Riyadh and a unit in Dubai are two units, and values
  the stock per currency, because money is not a count. `stock_value_combined` is null with a stated reason when the markets differ
  in currency.

## 6. The app half

The selector offered every GCC market the build knows; it now offers what the token carries, read from `allowed_markets` of `me`
and re-read with the rest of Home. Narrowing the picker is a courtesy — the boundary is the server, so an app that offered an
ungranted market would be refused rather than served, and failing open on an unreadable answer widens nothing. A seller working in
a market the grant does not hold is moved to one it does and the screens are reloaded for it.

## 7. What this makes available, and does not claim

- **B-12.1** (an attribute offered on that country's product forms only): the seller product now has a country, which a reviewer
  named as the second half of that criterion. The backend half already exists — `AttributeController::getList()` answers a named
  `storefront_country` through `AttributeRepository::getListOfferedIn()`. What is left is the one Dart line that sends the market
  with `/api/v1/attributes`. **Not claimed here.**
- **B-09** (seller and vendor products belong to a market): the mechanism is here — the grant, the picker narrowed to it, and the
  insert that saves the market. The criteria's own wording about the vendor web panel is **not claimed here**.

## 8. What stays open

- `Modules/TaxModule` and `Modules/AI` declare `seller_api_auth` in their own route files and therefore do not carry `seller_market`.
  Both serve reference data rather than a market's data; adding the alias is one line in each if a market scope should bound them.
- The refusal page is the generic Access-denied page; it does not yet name the market as the reason.
- K-08 in the app (hiding a screen the role cannot use) and the staff matrix over the 53 `seller_can` declarations.
- `updateProduct` keeps a saved product's market, as `ProductCountry` requires; moving a product between markets is still a copy.

## 9. Tests

`tests/Feature/Security/SellerMarketScopeTest.php` (38), `tests/Feature/Storefront/SellerAppProductMarketTest.php` (6) and
`tests/Feature/Storefront/SellerMarketDormancyTest.php` (13). Every request is made against `/api/v3/seller` with a bearer token,
so `api_lang`, `seller_api_auth`, `seller_market`, `seller_can` and `seller_all_markets` all run in the order the routing table
declares. No middleware is invoked by hand, no principal is constructed and no request attribute is set by a test.

Fourteen surfaces are refused twice each — once naming the market in the header, once in the field — then the lever test: the same
request is 403, a row is written, and the same request is 200. Dormancy is measured against the base: each surface is called on a
database that has never run the grant migration and again with the table created and a grant written while the switch is off, and
the two answers must be identical, status and body. Both measurements are warm calls, because this world's first request to a
surface warms caches the panel fills at boot.

Eight mutations, each reverted: the refusal removed (25 red), the grant read from the request (25 red), the shop-wide guard taken
off the staff group (1 red), the per-market role ignored (3 red), the currencies added together (2 red), the market taken off the
product insert (2 red), the unresolved-market guard removed (1 red), and the app's narrowing removed (5 red in Dart).

These directories are the ones CI runs on SQLite, MySQL 8 and MariaDB 11.4.2. A local MariaDB pass was **not** run: the server on
127.0.0.1:3308 refuses `root` with an empty password over TCP (error 1698), and reconfiguring it would disturb other lanes.
