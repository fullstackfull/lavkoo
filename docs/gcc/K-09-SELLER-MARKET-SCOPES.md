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

**The shop's own grant is the ceiling of everyone who works in it.** "No row means unrestricted" is read of the shop and never of a
staff member inside a shop that is itself limited. A staff member with no row of their own takes the shop's markets; one with rows
takes the overlap of theirs and the shop's. Reading a staff member's rows alone made a staff member outrank the owner who created
them: the owner of a Saudi-only shop was refused Kuwait while their employee was answered every order in it, `me` told that
employee their scope was `unrestricted`, and the same absence of a row carried them through the all-markets gate on staff
management, payouts, bank details and account deletion that the owner was refused. The idiom was copied from `StaffCountryScope`,
where an employee of the platform has no shop above them; here there is one.

## 3. What a seller sees

| Surface | Bound to the grant by |
|---|---|
| The order list | `OrderStorefront::narrowToGrant()`, applied **before** the market filter, so a filter can only narrow inside the grant. The grant also keeps the orders with no record when it holds the default market, as the platform reads such an order. |
| One order by its id — the detail, the financial breakdown, the invoice, and forcing a status on it | `SellerMarketScope::mayReadOrder()`, the list's own bound asked of one id, answering the not-found the route already gives for an order of another shop. |
| The product list, the two catalogue lists a shop reaches by naming its own id (`products/{seller_id}/all-products` and `edit-order-all-products`), and one product by its id — the detail, the edit form, the review list, the image list, the update and the delete | `SellerMarketScope::narrowQuery()` on the one column they read, through a single finder in the controller, so a detail cannot answer what the list refuses. `products.storefront_country` is NOT NULL with a default, so the `whereIn` is complete: there is no product whose market is unknown. |
| The four report screens and the four exports | The same two narrowings, inside `SellerReportService`, which the panel reads too: `scopedOrders()` takes the order bound, `scopedProducts()`, `stockQuery()` and `topProducts()` take the product bound. The exports read those same queries, so a file carries the report's rows and no more. A market named in the request narrows further inside the grant, as it does on the list. |
| Stock | The same narrowing on the inventory query, so the overview, the movement filter and every figure drawn from it hold one line. |
| Returns — the list and one return by its id, and advancing, receiving or rejecting it | `SellerMarketScope::narrowByOrder()`. A return carries no market of its own, so it is held to the market of the sale it reverses. |
| Refunds — the list, one request by its id, its details, and **the status decision that approves or rejects it** | `SellerMarketScope::narrowByOrder()`, for the same reason as a return: a refund request carries no market of its own, the order it reverses does. This was the sharpest hole in the unit. A token granted Saudi Arabia alone was answered **404 on `orders/103`** and then **200 "refund status updated successfully!"** on that order's refund: `refund_requests.status` went pending to `approved`, `change_by` to `seller`, a `refund_statuses` row was written, and `refund/list` handed back the order id, the amount and the note. A money decision and a full record, on a market the token cannot read. |
| The eight order writers that key off an order id — the payment status, the third-party courier, the delivery man, the whole-detail update, the delivery charge and date, the address, the digital file attached to a line, and forcing a status | `SellerMarketScope::narrowOrders()` through one finder, `OrderController::narrowOrdersOf()`, and `narrowByOrder()` for the one that finds an order *line*. Only the status writer had the bound; the other seven found by seller alone, so the same 404/200 split as the refund above: `orders/update-payment-status` moved the Emirati and Kuwaiti orders from `unpaid` to **`paid`**, and the rest stored a courier name, a tracking id, a delivery man, `confirmed`, a charge and an address. `assign-delivery-man` also gained the not-found answer it never had: an unmatched find used to die on a null and answer 500. |
| The till — its product list, a product scanned by its barcode, and a receipt read by its order id | `SellerMarketScope::narrowQuery()` on the two catalogue reads and `narrowOrders()` on the receipt. `pos/product-list` served the ungranted market's product in full, price and stock included, and `pos/products?code=` is a product id by another name. |
| Bulk jobs — the receipt, its failure file, the list, and queueing one | The products the job names: a receipt's input is a list of product ids and its failure list their names, so `anyProductOutsideTheGrant()` decides it. Queueing is refused **before** the job exists, because the work runs in a queue where the caller's grant is no longer readable. |
| What an automation rule may touch — its preview, and the catalogue a rule is written against | `SellerMarketScope::narrowQuery()` inside `SelectsSimpleProducts::sellerProducts()`, the one place every trigger builds its product query. |
| One delivery man's orders — their order list and their earning history | `SellerMarketScope::narrowOrders()` through one finder, `DeliveryManController::ordersOfTheShopsDeliveryMan()`. Found by survey rather than by a defect report: both return order rows and were held to the shop's own courier and not to a market, so a Saudi-only token read the Kuwaiti orders its own courier had carried, past the list that refuses them. |
| The seller's own reconciliation — the delivered lines it walks, the commission rows and the gap samples | `SellerMarketScope::grantedOrderIds()`, the order list's bound as a subquery, because this reads `order_details` joined to `orders` through the query builder. `gross: 111111` was once composed **entirely** of two markets the token had been refused. The ledger side carries no market and is not narrowed, as the statement is not. |
| Payout and bank details, staff management, the audit trail, account deletion | `seller_all_markets`, declared **beside** `seller_owner` and not instead of it: these cover every market at once, so a principal granted some of the platform's markets holds authority in those markets and not over the shop. Only the platform owner sees every market, and they do not come through this API. |

**What is not narrowed, and why.** Saying it here because a doc that claims a control which does not exist is how the unbound
single-resource reads shipped in the first place:

- **The statement and its CSV export** carry every line of the shop's ledger whatever currency it is in. `vendor_ledger_entries`
  has no market column: the ledger is the shop's account, not a market's. What the statement does refuse is the single *total*
  across currencies (section 5).
- **The audit trail** is withheld rather than narrowed: it sits behind `seller_all_markets`, so a limited principal gets 403 and
  not a narrowed trail. That is a real consequence to know about — the moment any grant exists in a shop, an existing
  `staff.manage` holder who is not granted every market loses the trail entirely. Narrowing it would need a market on the line,
  which `audit_logs` does not have.
- **A bulk job row and an automation rule row** are the shop's records and carry no market. The *products* they name and touch are
  bound, as the table above says, but the row itself is readable by any token of the shop that is not refused by its products.
- **The vendor web report pages** (`vendor-views.report.*`) still print the combined money figure across currencies. The pages'
  figures, their chart and their `data-text` spans are entangled, and changing them is its own slice; the API half is fixed and
  measured. Listed in section 8.
- **Coupons**, which carry `storefront_country` like a product but **nullable**, where NULL means the default storefront. The list
  and the three single-coupon writes (`coupon/update/{id}`, `status-update`, `delete/{id}`) are scoped by seller and not by
  market. `narrowQuery()` cannot be used as it stands: its `whereIn` would hide every coupon that exists today from a granted
  seller, because every one of them has NULL. It needs the NULL-is-the-default-market rule `OrderStorefront::narrowToGrant()` has
  for orders — a decision rather than a line, so it is named open rather than half-done. In section 8.
- **A payout request**, which carries no market: `vendor_payout_requests` has a currency and no country. The payout list is gated
  on `finance.view` alone, so a principal granted one market reads the shop's payout requests and may cancel one. Whether that
  belongs behind `seller_all_markets` with the bank details is a decision about an existing permission, not a narrowing. In
  section 8.

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

The **vendor web panel** reaches the same insert and carries no grant: `seller_market` is declared on the seller API group, and the
middleware resolves a principal the API's token middleware put on the request, which a session request never has. So a seller
granted Dubai alone added a product through the panel and it landed on the column's own default. `newProductAttributes()` now falls
back to the grant of the seller signed into that panel when the request carries none, and takes its market when the shop holds
exactly one; with several and no market named, the same refusal applies. Measured at the service the panel calls
(`ProductService::getAddProductData`), which is how the admin side of this column is measured too — **not** through a web form
post, and the panel's form still has no market picker, so a web seller of two markets cannot name one. In section 8.

## 5. The money rule: a sum of riyals and dinars is not a number

`SellerMarketTotals` carries the rule, which is the one `SettlementCurrency` already applies to the seller ledger ("the net is kept
by currency so the page never adds two", E-17.1) and `ABalanceCannotBeLeftUnknown` applies to a settlement of a mixed ledger.

- **The statement** answers the five keys of before while the account is in one currency — every seller today. Where it holds two,
  it answers **no** single `range`, `buckets` or `withdrawable` figure: the real figures per currency, and `totals_refused_because`
  where the reader is. Printing the sum silently is the one outcome that can mislead an operator into a wrong decision.
- **The stock screen** counts per market and adds the counts, because a unit in Riyadh and a unit in Dubai are two units, and values
  the stock per currency, because money is not a count. `stock_value_combined` is null with a stated reason when the markets differ
  in currency.
- **The order report** was the surface this rule was written for and not applied to: `amounts.due` was the sum of every order in
  the period whatever currency it was recorded in — 1015 for riyals plus dirhams plus dinars — with no currency key anywhere in
  the answer. It now goes through the same `SellerMarketTotals::money()`: one currency and the three blocks are the figures the
  screen always printed, byte for byte; two and `amounts`, `payments` and `chart.values` are null, with `amounts_by_currency`,
  `chart_by_currency` and `totals_refused_because` beside them. An order recorded before the storefront snapshot names no currency
  and is read as the store's base currency, as the ledger reads an entry that names none, so the orders of before do not become a
  currency of their own.
- **The product report** was left out of the rule entirely, while the controller's own docblock asserted that no screen there
  adds two currencies: `totals.sold_amount` answered **1200** for a thousand riyals plus two hundred dirhams and
  `discount_given` **10** for 7 plus 3. It now goes through the same `SellerMarketTotals::money()`, grouped by the currency of
  the order each delivered line belongs to — the currency is on the order, not on the line. `sold_quantity` is **not** refused:
  a unit sold in Riyadh and a unit sold in Dubai are two units, the same distinction `counts()` draws for the stock screen.
- **The three ledger screens** each printed one number over two currencies, because `VendorLedger::balances()` sums every entry
  whatever currency it names. For 100 riyals and 40 dirhams: `seller-center/overview` answered `available = balance =
  withdrawable = 140`, `seller-center/payouts` the same 140 on the screen a seller asks for money from, and
  `control-tower/briefing` the worst of the three — `{"currency":"SAR","pending":0,"withdrawable":140}`, a riyal-plus-dirham
  total printed **under the riyal's own code**, which reads as a checked figure rather than an unlabelled one. The per-currency
  figures now come from one place, `VendorLedger::balancesByCurrency()`, which the statement reads too, each currency carrying
  its own `withdrawable`.
- **The seller's own reconciliation** compared `recorded.net` with `credited.amount` and published `reconciles` — a verdict on
  whether one mixed-currency sum matched another. Over more than one currency the three money figures and the verdict are
  withheld with the reason, and the two sides that can be split are given per currency. `recorded` cannot be: neither
  `order_item_commissions` nor `order_transactions` carries a currency column, so the middle hand-off of the chain has no
  currency to group by. Named in section 8 rather than guessed at.
- **The printed report** is refused outright (409, with the reason) when the period holds two currencies: a PDF carries totals at
  its foot and has no way to say a figure is missing. The seller names a market and gets that market's report.
- **The app** reads the refusal. `StatementSummaryModel` turned the null `withdrawable` into `0` and the null `range` and `buckets`
  into empty maps, so a two-currency seller saw every balance as 0.00 with nothing to say why — and a zero is worse than a wrong
  sum, because a seller reads it as "there is nothing to withdraw" and acts on it. The combined figures are nullable now and the
  screen shows the reason and each currency's own figures: `fullstackfull/lavkooappseller` PR 20.
- **And the order report screen in the app, which was the other half of the same defect.** `OrderReportModel` read the refused
  `amounts` and `payments` through `_toDouble` on an empty map, so a seller owed 1015 across three currencies was shown
  `0.00 / 0.00 / 0.00`. **This case does not need the switch on**: `currenciesInWindow()` reads the currencies an installation
  actually has, so an installation with the switch off and no grant table at all — the dormant case — is refused the single
  figure the moment its orders carry two currency codes. That is the money rule working as written, not a dormancy break; the
  break was the app reading the refusal as nothing. The gate was deliberately **not** moved onto `markets->enabled()`, because
  that would have the dormant installation go back to printing 1015 as if it were money. The app half is in PR 20.

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
- The **vendor web report pages** still print one money figure across currencies. The API half obeys the rule; the pages' figures,
  their chart series and the `data-text` spans their JavaScript reads are entangled, and untangling them is its own slice. This
  is the money gap that is left: an earlier version of this document said there was one gap left while five single figures over
  two currencies were still being published by the API (the product report and the four screens above), which was wrong.
- The **vendor web panel has no market binding of its own** beyond the product insert above: its lists, reports and exports are
  scoped by seller and not by market, because no principal is resolved on a session request. A grant bounds the API, not the panel.
- The **audit trail is withheld, not narrowed**, for a limited principal — see section 3.
- **Coupons** are not bound to a market; the shape of the fix is in section 3.
- **A payout request** carries no market, and reading or cancelling one is open to any principal holding `finance.view` /
  `payouts.request` however few markets they hold — see section 3.
- **`seller-center/analytics` counts the shop's orders without a market bound** (`Order::where(seller_is, seller_id, checked)`).
  It is a count of the shop's own unread orders rather than a set of rows, in the same class as the scorecard and the audit
  trail; no order id, amount or customer travels in it. Named rather than bound, because a count per market is a different
  answer shape and the screens that read it ask about the shop.
- **The payout *guard* still compares a request against `VendorLedger::withdrawable()`**, which is the mixed-currency sum the payout
  *screen* now refuses to print. The screen is fixed because that figure is read and acted on; the guard is `PayoutService`'s own
  arithmetic, with its own currency snapshot and conversion (E-12.5), and changing what a payout may draw against is a decision
  in that lane rather than a presentation fix here. Named rather than quietly left: a shop holding two currencies can still ask
  for a payout sized against the sum of them.
- **`recorded.net` in the seller's reconciliation cannot be split by currency**, because neither `order_item_commissions` nor
  `order_transactions` has a currency column. The screen therefore withholds that figure over two currencies rather than
  publishing a mixed sum, and the two sides that can be split are given. Splitting it needs a currency on the commission
  snapshot, which is a schema decision in the ledger's own lane and not a line here.
- **`top_products` and the per-product `sold_amount` of the product report are each in the money of that product's own market.**
  A product carries exactly one `storefront_country`, so a per-product figure is one currency by construction; what was refused
  above is the figure that added *across* products of different markets. Recorded because it rests on products not being sold
  through another market's storefront, which is true of the platform as it stands and is not enforced by a constraint.
- `products/details/{id}` and `products/get-product-images/{id}` answer **200 with an empty body** for a product the token cannot
  reach, which is the answer they already gave for a product of another shop. No record travels; the status was left as it was
  rather than changed for an existing case.

## 9. Tests

`tests/Feature/Security/SellerMarketScopeTest.php` (38), `tests/Feature/Security/SellerMarketRowBoundTest.php` (51),
`tests/Feature/Storefront/SellerAppProductMarketTest.php` (6), `tests/Feature/Storefront/VendorPanelProductMarketTest.php` (4) and
`tests/Feature/Storefront/SellerMarketDormancyTest.php` (14). Every request is made against `/api/v3/seller` with a bearer token,
so `api_lang`, `seller_api_auth`, `seller_market`, `seller_can` and `seller_all_markets` all run in the order the routing table
declares. No middleware is invoked by hand, no principal is constructed and no request attribute is set by a test.

Fourteen surfaces are refused twice each — once naming the market in the header, once in the field — then the lever test: the same
request is 403, a row is written, and the same request is 200. Dormancy is measured against the base: each surface is called on a
database that has never run the grant migration and again with the table created and a grant written while the switch is off, and
the two answers must be identical, status and body. Both measurements are warm calls, because this world's first request to a
surface warms caches the panel fills at boot.

**That matrix is a test of the refusal and not of the narrowing, and reading it as both is how the holes above shipped.** All
twenty-eight cases name a market outside the grant, so all twenty-eight are exercises of one group middleware: with the narrowing
made a no-op they stay green, and a surface that answers every market passes identically to one that is bound. Five of the
fourteen also answered 500 on a missing table the moment a request was allowed through, so the matrix could not have detected a
narrowing failure even in principle.

`tests/Feature/Security/SellerMarketRowBoundTest.php` (35) is the narrowing test, and every assertion in it is about rows. Each
names an id, a count or a figure that exists only in a market the token does not hold; the order one asserts that the set of
orders openable by id **equals** the set the list offers, computed rather than written down; the export ones assert the rendered
contents of the file the request downloaded. The seller-centre tables those screens read are built with the columns the
controllers actually read, so each screen runs to its answer and the rows in it can be counted.

### Mutations

Fourteen, each applied alone and reverted, each run against the test that is supposed to catch it:

| Mutation | Red |
|---|---|
| `mayReadOrder()` always true | 4 |
| the report's order bound removed | 3 failures + 1 error |
| the report's product bound removed | 2 |
| the stock report's bound removed | 2 |
| the product single-read bound removed | 3 |
| the return bound removed | 4 |
| the bulk-job and receipt bound removed | 2 |
| the automation product bound removed | 1 |
| the shop's grant no longer a ceiling on its staff | 4 |
| the report adds the currencies again | 1 |
| the order status write unbound | 1 |
| the vendor panel's product market not resolved | 1 (of 4) |
| the two catalogue lists reached by the shop's own id unbound | 2 (of 2) |
| the refusal itself removed | 25 in the matrix, and 2 more in the row test |

The last row is the measurement that matters most about the two files together: removing the refusal reddens the matrix, and
removing any single narrowing leaves the matrix green and reddens the row test. Neither file can stand in for the other.

### Engines

| | Tests | Assertions | Failures |
|---|---|---|---|
| SQLite in memory | 1392 | 6349 | 0 (3 skipped) |
| MariaDB 10.11.14 on 127.0.0.1:3308 | 1392 | 6355 | 0 |

Both over `tests/Feature/Security` and `tests/Feature/Storefront`, which are the directories CI runs on SQLite, MySQL 8 and
MariaDB 11.4.2.

The previous round reported that no local MariaDB pass could be run, which was true of the credentials it had. With the TCP
account, **these two directories were red on MariaDB at this branch's own head**: six failures before any of this round's changes,
every one a column missing from an inferred fixture table that a strict engine refuses a query without. They are fixed here, and
the six are named in the commit that fixes them. A suite green on SQLite and red on MariaDB was not evidence on MariaDB.
