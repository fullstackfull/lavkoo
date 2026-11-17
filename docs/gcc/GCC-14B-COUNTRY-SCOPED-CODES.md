# GCC-14b: SKU and barcode unique per country (slice 1)

**Status:** draft, behind a switch that ships **off**. Nothing here runs on the pilot server or on production. No migration, no database index, no
change to what a Saudi customer, seller or admin sees while the switch is off.

Decisions behind it: GCC-02/03 (every country has its own product rows), GCC-08 and GCC-18 (catalog products stay independent per country). The owner's
rule: the same physical box carries the same EAN in Saudi Arabia and in Kuwait, so the same SKU or barcode may exist in two **countries**, never twice in
**one** country. The survey this work follows is `docs/gcc/audits/2026-11-16-sku-barcode-lookup-map.md` (on the survey branch, not yet merged), 35
sites, ids A1 to G9.

## What the switch does

`gcc.catalog.country_scoped_codes` (env `GCC_COUNTRY_SCOPED_CODES`, default `false`).

- **Off (the default):** every gate, lookup and answer is the one of today. A code and a barcode are unique across the whole table, by validation.
- **On, and `products.storefront_country` exists:** a code or a barcode is unique inside a country. The gates ask the country the product is saved in,
  the importers and the pickers that can know a country use it, and a lookup that finds more than one candidate answers an ambiguity or narrows by an
  explicit country: nothing picks the lowest id any more.
- **On, and the column does not exist yet** (the `2026_11_25_100000` migration has not run): the switch acts as off. No query mentions the missing
  column and nothing answers HTTP 500. The column probe is `StorefrontCatalog::columnReady()`, remembered for the process, with a "no" forgotten after
  30 seconds, so a worker started before the migration notices it without a restart.

The switch does **not** depend on `gcc.storefronts.enabled`. The admin can only choose a country for a new product while storefronts are on (GCC-14a), so
with storefronts off every new product is saved in the table default `SA` and the per-country rule equals the global one until a product of another
country is made by other means.

`App\Services\Catalog\ProductCodeScope` is the only place that knows the switch and the column. It caches nothing. The decisions it answers:

| Question | Method |
|---|---|
| Does the scope apply? | `enabled()` (switch on and column present) |
| Is a code or barcode taken in a country (ignoring one product)? | `isCodeTaken`, `isBarcodeTaken`, `generatedCodeIsFree` |
| The validation rule for a code or a barcode | `uniqueRuleForCode`, `uniqueRuleForBarcode` (the global rule when off; `Rule::unique(...)->where('storefront_country', country)` when on) |
| Which country is a new product saved in? | `countryOfNewProduct(addedBy, requestedCountry)`: it asks the same code that builds the row |
| Which products answer to a scanned value (the seller POS)? | `scan` (models with their relations; a caller that gets more than one has an ambiguity to answer) |
| Narrow a products query to a country | `restrictToCountry` (no-op with no country or when off) |
| Provider, order and routing questions | `countryOfProviderProducts`, `countryOfOrder`, `countriesOfProducts`, `skuRuleAppliesTo` |
| A label for a picker row | `countryTag` |

A product whose country is not known (an empty `storefront_country`) is checked against **every** country: refusing a code that is free is safe,
accepting one that is held is not. The readiness command reports such rows and refuses to call the catalogue ready while one exists.

## Where a product's country comes from

| Path | Country the product is saved in, and therefore the one checked |
|---|---|
| Admin add form, storefronts on and column present | the admin's choice; the default storefront's country when the choice is missing or unusable |
| Admin add form otherwise, vendor add form, seller apps v2 and v3, bulk import (admin and vendor), M5AZN import | the table default, `SA` (`ProductCodeScope::TABLE_DEFAULT_COUNTRY`, pinned to the migration by a test) |
| Any edit | the product's own country. GCC-14a writes the country on create only, so no update moves a product; a country in an edit request is ignored by the save, and therefore by the check (a crafted request cannot slip a duplicate into the product's country by naming another one) |

A future move or copy-to-country tool must pass the **new** country to `uniqueRuleForCode`, `uniqueRuleForBarcode` or `isCodeTaken`; `ProductCodeScopeTest`
pins that a move into a country that already holds the value is refused.

## The sites

Status of every site of the survey. "Covered" means the site is behind `ProductCodeScope` and a test pins both the off and the on behaviour. "Not needed
(id-keyed)" means nothing resolves a product from the text there: the row or the link is keyed by an id, or it is a list a person reads. **The sites
marked partial or NOT covered are what the owner has to read before turning the switch on:** each of them still treats a code as if it named one product.

| # | Where | Status | What it does now |
|---|---|---|---|
| A1 | `ProductAddRequest: code` | covered | Unique inside the country the add action saves the product in (the admin's choice, the table default for a vendor). |
| A2 | `ProductAddRequest: barcode` | covered | Unique inside the same country. |
| A3 | `ProductUpdateRequest: code` | covered | Unique inside the product's own country; the product itself is ignored. |
| A4 | `ProductUpdateRequest: barcode` | covered | Unique inside the product's own country; the product itself is ignored. |
| A5 | `RestAPI v2 seller ProductController::add_new` | covered | Unique inside the table default country, where a seller product is saved. |
| A6 | `RestAPI v2 seller ProductController::update` | covered | Unique inside the product's own country; the product itself is ignored. |
| A7 | `RestAPI v3 seller ProductController::add_new` | covered | Unique inside the table default country, where a seller product is saved. |
| A8 | `RestAPI v3 seller ProductController::updateProduct` | covered | Unique inside the product's own country; the product itself is ignored. |
| A9 | `ProductService::getUniqueProductSKUCode` | covered | Free in the country the imported products take (the table default: the sheet has no country column). |
| A10 | `M5aznProductImporter::clashesWithExistingProduct` | covered | Parks an item only when its SKU or barcode is used in the country it is imported into (the table default, written explicitly while the switch is on). |
| B1 | `ProductService::getImportBulkProductData ($productsTax)` | covered | The tax ids are listed in the order of the sheet rows, not keyed by code. |
| B2 | `Admin ProductController::importBulkProduct` | covered | The tax rows go to the ids of the rows the import just created; no product is found by its code. |
| C1 | `RestAPI v3 seller POSController::get_product_by_barcode` | covered | Looks at the code and the barcode of the seller's own products, narrowed to the country of the request (`country`, else the default storefront); more than one match answers 409 ambiguous_code. Switch off: the first product with the code, as before. |
| C2 | `ProductRepository::getListWithScope (search_from=pos)` | partial | The admin POS search rows name the country in the SKU line. The vendor POS search rows have no SKU line and do not. |
| C3 | `ProductRepository::getWebListWithScope` | partial | The admin POS grid cards name the country after the title. The vendor POS grid, the exports and the gallery do not (the admin product list has its country column since GCC-14a). |
| C4 | `ProductRepository::getListWithScope (searchValue)` | labelled | The admin and vendor order-edit pickers offer the products of the order's country (it follows from the order lines), and the admin picker also names the country in each row, for an order whose lines do not name one. The admin clearance-sale picker offers every country (a promotion has no country) and names it in each row and chip. The vendor pickers list the seller's own products, all in the table default country. |
| C5 | `ProductRepository::getListWhere (code filter)` | labelled | The code filter of the admin product searches (the reviews filter and the promotion pickers behind them) offers every country and names it in each row and chip. The vendor search lists the seller's own products. |
| C6 | `Flash deal and deal of the day pickers` | labelled | The promotion pickers offer every country (a promotion has no country to narrow to) and name it in each row and chip; the discount is written by the picked id. |
| C7 | `ProductRepository stock-limit list` | not needed (id-keyed) | A list a person reads (code LIKE); every action on a row is keyed by its id. The rows do not name the country. |
| C8 | `RestockProductRepository` | not needed (id-keyed) | A list a person reads (code LIKE); every action on a row is keyed by its id. The rows do not name the country. |
| C9 | `SellerCenter ProductList` | not needed (id-keyed) | A list a person reads (code LIKE); every action on a row is keyed by its id. |
| C10 | `SellerCenter InventoryList` | not needed (id-keyed) | A list a person reads (code LIKE); every action on a row is keyed by its id. |
| C11 | `SellerCenter Search` | not needed (id-keyed) | A list a person reads (code LIKE); every action on a row is keyed by its id. |
| D1 | `SalesChannels ProductMatcher` | partial | A listing is proposed only the products of the country its Trendyol storefront or noon country names. An Amazon account (a region, not a country), the Turkish Trendyol marketplace and any account of no storefront country are not narrowed: a code two countries share stays a conflict and nothing links on a guess. |
| D2 | `ChannelOrderIngestor` | not needed (id-keyed) | The mapping is found by the account and the channel's own listing key; a code never picks it. |
| D3 | `ListingDraftService / ListingPublisher` | covered | The default outbound key is the product code; a key a listing of the account already holds for another product refuses the second publish. Pinned across two countries, switch on and off. |
| D4 | `ChannelInventoryWriter` | not needed (id-keyed) | Keyed by the account's listing key. |
| D5 | `StockPricePublisher` | not needed (id-keyed) | Keyed by the account's listing key. |
| D6 | `RoutingRuleMatcher` | covered | A SKU condition also needs the line's product to be in the country the rule names, or in the default storefront's when it names none; an unknown product country matches no SKU rule. A rule keyed by a product id is untouched. |
| D7 | `Admin SalesChannels ProductDirectory` | NOT covered | The listing-link picker shows no country; the link is saved by the picked id. |
| D8 | `LocationStockPage` | not needed (id-keyed) | A stock page filter (code LIKE); a list a person reads. |
| E1 | `ProviderMappingService::suggestionsFor` | covered | Suggests only the products of the provider's country: the default storefront's, until provider connections have a country of their own. |
| E2 | `TorodMatchingController::productSearch` | covered | Offers only the products of the provider's country (the default storefront's). |
| E3 | `TorodChecks` | not needed (id-keyed) | A diagnostic that reads warehouse items, not store products. |
| F1 | `Admin ProductCostController picker` | NOT covered | The cost-layer picker shows no country. |
| G1 | `Printed labels and seller PDFs` | not needed (id-keyed) | Display only: the label prints the code of a product chosen by id. |
| G2 | `Product screens and exports` | not needed (id-keyed) | Display only (the admin list has a country column since GCC-14a). |
| G3 | `Storefront structured data (sku, gtin)` | not needed (id-keyed) | Display only, and a storefront shows its own country's products. |
| G4 | `Order-line snapshots` | not needed (id-keyed) | A copy of the code beside the product id. |
| G5 | `Purchase orders and cost ledgers` | not needed (id-keyed) | A SKU label beside product_id; pool keys are location, product id and variant. |
| G6 | `Stock rows` | not needed (id-keyed) | Display only. |
| G7 | `Outbound attributes (Torod externalId, M5AZN)` | not needed (id-keyed) | The product id is the external id; SKU and barcode are only attributes. |
| G8 | `CatalogIntegrityProducer` | not needed (id-keyed) | A diagnostic that flags the same barcode inside one seller: a false positive for twins once sellers have markets. |
| G9 | `Product gallery copy` | NOT covered | The copy blanks the code and barcode so it passes the old global rule; a copy-to-country flow keeps them. |

`php artisan gcc:catalog:code-readiness` prints the partial and NOT covered rows from the same list (`App\Services\Catalog\ProductCodeSites`), and a test
keeps this table and that list in step.

### The site guard

`tests/Feature/Catalog/ProductCodeLookupGuardTest.php` reads every PHP file under `app/` and `Modules/` and fails for any place that looks a product up,
filters it or validates it by `code` or `barcode` (where-style calls, repository filters, `unique:products`, `Rule::unique('products', ...)`) that is not
listed in its table with a survey id and a reason. The table is exact: a different count, or a file that no longer holds a lookup, fails too. It lists the
covered files as well (the lookup stays in the file, and the country comes from `ProductCodeScope`), which is stricter than "a file that uses the scope
passes". A lookup the scanner cannot read the subject of is treated like a product lookup; a lookup it reads as being about a colour, a currency or a coupon
needs no entry. Probe tests prove it finds each way of writing a lookup and fails for a new unlisted one. It is a pattern check over source text: a lookup
built from a variable column name, or a raw SQL string, is not seen.

## Turning it on

1. Deploy the slice with the switch off. Nothing changes.
2. Run the `2026_11_25_100000_add_storefront_country_to_products` migration (see `docs/gcc/STOREFRONT_FLAG_RUNBOOK.md` for its measured cost and the MariaDB
   caveat).
3. Run `php artisan gcc:catalog:code-readiness`. It is read only. It prints the products per country, the products with an empty country, the codes and
   barcodes held **twice inside one country**, the ones **shared by countries**, the country codes that are not configured, and the sites above that are
   still partial or NOT covered. **Exit 1** when the migration has not run, a product has an empty country, or a code or barcode is held twice inside one
   country (the switch would refuse those from then on, and every lookup would meet an ambiguity). A code shared by two countries is listed and does not
   block: it is what the switch allows, and none is expected before it is on.
4. Decide the open points below, in particular which of the NOT covered sites the owner accepts.
5. Set `GCC_COUNTRY_SCOPED_CODES=true`, run `php artisan config:clear` and `php artisan cache:clear` (product caches).
6. Check: add a product with a code that exists in another country, once on the admin form and once through the seller app; scan a code that two countries
   hold with and without `country=`.

**Do not turn it off again once two countries hold a product of the same code.** Off, the un-narrowed sites above pick one of the twins again, silently
(the seller POS scan takes the lowest id), and the global gates refuse to edit either of them. The readiness command's "shared by countries" list is the
list of what would be exposed.

### No database index in this slice

Uniqueness stays a validation rule, as it is today: there is no unique index on `products.code` or `products.barcode`. A composite unique index
`(storefront_country, code)` (and one for the barcode) is a **later, owner-approved step**: existing data may hold duplicates inside a country (the
readiness command finds them), the column is `CHAR(2) ascii_bin NOT NULL` while `code` carries the table's collation, and an index needs a rollback plan
(drop the index; nothing else depends on it) and a rehearsal on a copy of the real data. A test pins that no migration of this tree adds a unique index on a product's code or barcode.

## The seller POS scan (C1)

`GET api/v3/seller/pos/products?code=`

- Switch off: the first product of the seller whose **code** is the value (never the barcode), else `[]`. A `country` in the request is ignored, even
  a nonsense one. Pinned by tests.
- Switch on: the seller's own products whose code **or barcode** is the value, narrowed to `country` (a query value: one of the configured country codes,
  open or not), or to the default storefront's country when it names none, so a Saudi seller sees no change. One match is answered exactly as before;
  none is `[]` (so is a `code` that is missing, empty or not text, which read as null would match every product that has no barcode); more than one is
  `409 {"errors": [{"code": "ambiguous_code", "message": "...", "candidates": [{"id": 1, "name": "...", "country": "SA"}]}]}`. A `country` that is not a
  configured code is `422 {"errors": [{"code": "country", "message": "..."}]}`: a typo must not fall back to Saudi and sell the wrong twin.
- `X-Storefront` does not reach `api/v3` routes (the storefront middleware is not applied there), which is why the country is an explicit query value.

### What the Flutter apps may do with `scan_code` (open question)

The apps are not in this repository and cannot be audited here. `Product::getScanCodeAttribute()` appends `scan_code` (the barcode when there is one, else
the SKU) to every product payload; if an app matches a scanned value against products it already holds, it matches on text that two countries may now share.
To decide with the app team before the switch is on:

1. Does the seller app match scans locally against `scan_code`, or always call `pos/products`? A local match must also look at the product's country or
   id, or only be used when one product matches.
2. A non-Saudi seller needs to send `country`; the app does not today. Until it does, a scan answers for the Saudi storefront.
3. The app must handle `409` and `422` from `pos/products` (it knows `200` with a product or `[]`). Nothing sends them while the switch is off.
4. The customer app never resolves a product by code (survey, "Facts"): nothing to change there.

## Assumptions

1. **The switch is independent of the storefront switch** (see above).
2. **The table default `SA` is the country of everything that does not choose one.** If the default storefront is ever changed, imports, seller products and
   the M5AZN import still take `SA`, not the default storefront; `countryOfNewProduct` for an admin who chose nothing takes the default storefront, as the form does.
3. **An empty or unknown country is checked against every country** (fail safe), and reported by the readiness command.
4. **Edits are checked in the product's own country** and ignore a requested one (no update path moves a product).
5. **Imports take the table default.** The sheet has no country column. While the switch is on the M5AZN import writes that country explicitly, so what
   was checked is what is stored; while off it adds nothing. A provider connection with a country of its own is a later decision.
6. **Provider connections have no country**, so with the switch on the Torod suggestions and picker (E1, E2) serve the **default storefront's** country
   (approving a match is still by product id and is not checked).
7. **A channel account's country is read from its own settings:** Trendyol `storefront_code` (SA, AE, KW), noon `country_code`. An Amazon account names a
   region and marketplace ids, which are not countries, so it has none; neither has the Turkish Trendyol marketplace or an unconfigured code. An
   account with no country is never narrowed (a shared code stays a conflict, nothing links on a guess).
8. **The routing rule (D6):** with the switch on a SKU condition needs the line's product to be in the country the rule names, or in the default storefront's
   when it names none (every rule written before the switch was written when there was one country). The rule's `country` still also means the order's
   **destination**; both must hold. A line whose product country is unknown matches no SKU rule. Product-id rules and every other condition are untouched.
   Decisions recorded for a line carry `product_country` only when it was read, so a decision made with the switch off reads as before.
9. **An order's country follows from its lines** (all in one country, no unknown product); otherwise the order-edit picker is not narrowed.
10. **The vendor POS** (search rows, grid) and the product lists are not labelled: every seller product is in the table default country until sellers have
    markets, so a seller's own list cannot hold twins today.
11. **A text field is used, never layout:** the admin POS search row ends its SKU line with the country (` · KW`) and a grid card its title, only while the
    switch is on; the rendered HTML with the switch off is the old one.
12. **The two dead v3 request classes** (`API/v3/ProductAddRequest`, `ProductUpdateRequest`, imported and never type-hinted) keep their repeated global rule;
    the live rules are the inline ones in the controller. The guard lists them as dead code.
13. **Comparison is the database's.** `code` and `barcode` compare with the column collation (case-insensitive on a default MySQL collation), the country
    column byte for byte (`ascii_bin`), so `kw` is not `KW`. Per-country uniqueness inherits both.
14. **The site table lives in the application** (`ProductCodeSites`), not only in the test, because the readiness command prints it. The guard's table in the
    test is the occurrence table.
15. **`StorefrontCatalog::columnReady()` became public** (one word) so the column probe and its memo stay in one place.
16. **`candidatesForCode`, listed in the first design, does not exist.** The one caller that needs candidates is the POS scan, which needs the models with
    their relations, so it is `scan()`; a lookup that needs rows only narrows its own query with `restrictToCountry`.
17. **Two language keys** (`the_scanned_code_matches_more_than_one_product`, `the_country_must_be_one_of_the_configured_storefront_countries`) were added in the
    middle of `en`, `sa` and `ae` `new-messages.php`; the Arabic text is mine and should be reviewed.

### Queries that changed shape while the answer did not (switch off)

The answers are the same; the SQL text is not, in these places: `getUniqueProductSKUCode` asks `DB::table('products')` instead of the model (same
`exists`); the bulk import inserts its rows one by one inside one transaction (all or nothing, as the single insert was) so it knows their ids, and no longer
queries products by code; the M5AZN clash check is two `exists` questions instead of one OR-ed query; the seller POS scan splits one nested `where([...])`
into two conditions (`limit 1`, as `first()`); a rule is a `Unique` object instead of the string (`unique:products,code,...` parses to the same thing); the
admin and vendor order-edit pickers pass a `storefront_country` filter that is empty (and then ignored) while off; the routing context asks nothing about a
product's country while off. The vendor import is unchanged except for the generated code.

## Open owner decisions

1. Which of the partial and NOT covered sites are acceptable on the day the switch is turned on (the readiness command lists them).
2. The seller app: send `country` (or the product id) when scanning; handle 409 and 422. Who changes it, and when.
3. A country for a provider connection (Torod, M5AZN), so that E1 and E2 serve the right country instead of the default storefront, and so that an import
   can land in a country other than `SA`.
4. A country for an Amazon channel account (a setting, or a mapping of marketplace ids to countries), so that D1 can narrow it.
5. Whether a promotion (flash deal, deal of the day, clearance sale) belongs to a country (GCC-17): C5 and C6 cannot be narrowed until it does.
6. The copy-to-country flow (G9): the gallery copy blanks the code and barcode today; a copy for another country should keep them and be checked in the
   target country.
7. The composite unique index and the clean-up of any duplicate the readiness command finds first.
8. Whether the routing-rule reading in assumption 8 is the one the owner wants (the alternative is a product-id key or a country column on the rule, which
   needs a migration).
