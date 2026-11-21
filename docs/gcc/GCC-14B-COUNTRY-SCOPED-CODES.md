# GCC-14b: SKU and barcode unique per country (slices 1 and 2)

**Status:** draft, behind a switch that ships **off**. Nothing here runs on the pilot server or on production. No migration, no database index, no
change to what a Saudi customer, seller or admin sees while the switch is off. Slice 1 made the gates and the lookups country-aware; slice 2 closes the
sites slice 1 left partial or uncovered (the pickers, the exports, the gallery, the Amazon accounts) and proves the isolation between the six countries.

Decisions behind it: GCC-02/03 (every country has its own product rows), GCC-08 and GCC-18 (catalog products stay independent per country). The owner's
rule: the same physical box carries the same EAN in Saudi Arabia and in Kuwait, so the same SKU or barcode may exist in two **countries**, never twice in
**one** country. The survey this work follows is `docs/gcc/audits/2026-11-16-sku-barcode-lookup-map.md` (on the survey branch, not yet merged), 35
sites and display groups, ids A1 to G9 (44 rows below).

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

Status of every site of the survey.

- **covered**: the site is behind `ProductCodeScope` (a gate asks the country, a lookup is narrowed to one, an ambiguity is answered) and a test pins both
  the off and the on behaviour.
- **labelled**: a person picks a product from a list that can hold the products of two countries, and while the switch is on every row, card, chip or
  export row names its country (` · KW`, `ProductCodeScope::countryTag()`). Nothing is narrowed, because what is picked (a promotion, a cost layer, a
  listing link) has no country; it is saved by the picked id, and the person sees which twin they pick. With the switch off nothing is drawn.
- **not needed (id-keyed)**: nothing resolves a product from the text there: the row or the link is keyed by an id, or it is a list a person reads.
- **partial**, **NOT covered**, **deferred (owner)**: still open. **These are what the owner has to read before turning the switch on:** a partial or NOT
  covered site still treats a code as if it named one product, wholly or in part; a deferred one is safe as it is and waits for a feature the owner has
  not approved.

Totals after slice 3 (a test keeps this line equal to the registry): **18 covered, 7 labelled, 18 not needed (id-keyed), 1 open** (D1 partial).

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
| C2 | `ProductRepository::getListWithScope (search_from=pos)` | labelled | The admin POS search rows name the country at the end of the SKU line. The vendor POS search rows list the seller's own products, which are all in the table default country (no seller path writes another), so they stay as they are; a test pins it. |
| C3 | `ProductRepository::getWebListWithScope` | labelled | The admin POS grid cards, the admin product gallery cards and their details panels name the country after the name, and the admin product export has a country column after its last one (the admin product list has had its country column since GCC-14a). The vendor POS grid, export and gallery list the seller's own products, all in the table default country, and stay as they are; a test pins it. |
| C4 | `ProductRepository::getListWithScope (searchValue)` | labelled | The admin and vendor order-edit pickers offer the products of the order's country (it follows from the order lines), and the admin picker also names the country in each row, for an order whose lines do not name one. The admin clearance-sale picker offers every country (a promotion has no country) and names it in each row and chip. The vendor pickers list the seller's own products, all in the table default country. |
| C5 | `ProductRepository::getListWhere (code filter)` | labelled | The code filter of the admin product searches (the reviews filter and the promotion pickers behind them) offers every country and names it in each row and chip. The vendor search lists the seller's own products. |
| C6 | `Flash deal and deal of the day pickers` | labelled | The promotion pickers offer every country (a promotion has no country to narrow to) and name it in each row and chip; the discount is written by the picked id. |
| C7 | `ProductRepository stock-limit list` | not needed (id-keyed) | A list a person reads (code LIKE); every action on a row is keyed by its id. The rows do not name the country. |
| C8 | `RestockProductRepository` | not needed (id-keyed) | A list a person reads (code LIKE); every action on a row is keyed by its id. The rows do not name the country. |
| C9 | `SellerCenter ProductList` | not needed (id-keyed) | A list a person reads (code LIKE); every action on a row is keyed by its id. |
| C10 | `SellerCenter InventoryList` | not needed (id-keyed) | A list a person reads (code LIKE); every action on a row is keyed by its id. |
| C11 | `SellerCenter Search` | not needed (id-keyed) | A list a person reads (code LIKE); every action on a row is keyed by its id. |
| D1 | `SalesChannels ProductMatcher` | partial | A listing is proposed only the products of the country its account sells in: a Trendyol storefront, a noon country, or an Amazon account whose marketplaces are all the Saudi (A17E79C6D8DWNP) or all the UAE (A2VIGQ35RCS4UG) one. An Amazon account of any other marketplace or of marketplaces of two countries, the Turkish Trendyol marketplace and any account of no configured country are not narrowed: a code two countries share stays a conflict and nothing links on a guess. |
| D2 | `ChannelOrderIngestor` | not needed (id-keyed) | The mapping is found by the account and the channel's own listing key; a code never picks it. |
| D3 | `ListingDraftService / ListingPublisher` | covered | The default outbound key is the product code; a key a listing of the account already holds for another product refuses the second publish. Pinned across two countries, switch on and off. |
| D4 | `ChannelInventoryWriter` | not needed (id-keyed) | Keyed by the account's listing key. |
| D5 | `StockPricePublisher` | not needed (id-keyed) | Keyed by the account's listing key. |
| D6 | `RoutingRuleMatcher` | covered | A SKU condition also needs the line's product to be in the country the rule names, or in the default storefront's when it names none; an unknown product country matches no SKU rule. A rule keyed by a product id is untouched. |
| D7 | `Admin SalesChannels ProductDirectory` | labelled | The listing-link picker (and the routing rule's product picker, which shares it) offers every country and names the country at the end of each row; the link is saved by the picked id. |
| D8 | `LocationStockPage` | not needed (id-keyed) | A stock page filter (code LIKE); a list a person reads. |
| E1 | `ProviderMappingService::suggestionsFor` | covered | Suggests only the products of the provider's country: the default storefront's, until provider connections have a country of their own. |
| E2 | `TorodMatchingController::productSearch` | covered | Offers only the products of the provider's country (the default storefront's). |
| E3 | `TorodChecks` | not needed (id-keyed) | A diagnostic that reads warehouse items, not store products. |
| F1 | `Admin ProductCostController picker` | labelled | The cost-layer picker offers every country and names the country at the end of each row and of the chosen product; the layer is written by the picked product id. |
| G1 | `Printed labels and seller PDFs` | not needed (id-keyed) | Display only: the label prints the code of a product chosen by id. |
| G2 | `Product screens and exports` | not needed (id-keyed) | Display only (the admin list has a country column since GCC-14a). |
| G3 | `Storefront structured data (sku, gtin)` | not needed (id-keyed) | Display only, and a storefront shows its own country's products. |
| G4 | `Order-line snapshots` | not needed (id-keyed) | A copy of the code beside the product id. |
| G5 | `Purchase orders and cost ledgers` | not needed (id-keyed) | A SKU label beside product_id; pool keys are location, product id and variant. |
| G6 | `Stock rows` | not needed (id-keyed) | Display only. |
| G7 | `Outbound attributes (Torod externalId, M5AZN)` | not needed (id-keyed) | The product id is the external id; SKU and barcode are only attributes. |
| G8 | `CatalogIntegrityProducer` | not needed (id-keyed) | A diagnostic that flags the same barcode inside one seller: a false positive for twins once sellers have markets. |
| G9 | `Product gallery copy` | covered | The gallery's "use this product info" is unchanged (it opens the add form with the code and the barcode blank). Beside it, a copy-to-country action makes the copy itself: an explicit target that is never the source's country, the code and the barcode kept only when free there, image files of the copy's own, no stock and no price carried, created unpublished, and an audit line written with the row or neither. See "The copy to another country (slice 3)". |

`php artisan gcc:catalog:code-readiness` prints the count of every status and the open rows (partial, NOT covered, deferred) from the same list
(`App\Services\Catalog\ProductCodeSites`), and a test keeps this table and that list in step.

### The labelled sites (slice 2)

Where the label is drawn, and what is deliberately not:

| Site | Where the country is named | How |
|---|---|---|
| C2 | the SKU line of an admin POS search row | slice 1 (`_search-product`) |
| C3 | the title of an admin POS grid card (slice 1); the name on each admin product gallery card and in its details panel; a `Country` column at the end of the admin product export | the blades call `countryTag()`; the export view adds the column after the last one it had (S, or T beside the tax column), so no column moves |
| C4 | the rows and chips of the admin clearance-sale picker; the rows of the admin order-edit picker | blades; the order-edit picker is also narrowed to the order's country when its lines name one |
| C5, C6 | the rows and chips of the promotion pickers (flash deal, deal of the day) and of the reviews filter, which share one partial | blades |
| D7 | each row of the listing-link picker (and of the routing rule's product picker) | the JSON row carries `country_tag` and the script appends it as text |
| F1 | each row of the cost-layer picker and the chosen product | the same |

The label is text added inside the element that already holds the product's name (or the SKU line): no markup, no layout, no class changes. The
separator and the code come from one place. While the switch is off, or before the column exists, the answer, the HTML and the JSON are the old ones
(tests compare the rendered output with the tags stripped out of the switch-on rendering). A product whose country is empty is not tagged.

**Not labelled, on purpose: the seller's own surfaces.** The vendor POS rows and cards, the vendor export, the vendor gallery, and the vendor reviews,
order-edit and clearance-sale pickers list the seller's own products. No seller path writes a country (the seller apps, the vendor form, the bulk import
and the M5AZN import all take the table default, `SA`; only the admin add lets a request choose one), so two countries cannot hold a product of one code
in a seller's list. `SellerSurfacesCountryTest` pins both the reason and the result; the day a seller can hold products in two countries it fails and
names the views that need the label. Lists of products already chosen (the products of a deal, the lines of a clearance sale) are display and are not
labelled either: only the rows and chips of a picker are.

### The copy to another country (slice 3, G9)

The gallery's "use this product info" is unchanged: it opens the add form with the product's data and the code and the barcode blank, and the
admin types new ones. Beside it the gallery card now carries a **copy-to-country** control, and that control is the whole of the "copy form" —
which is the point, because it holds no stock field and no price field, so neither can be carried across unflagged. It renders **zero bytes**
while the markets are off (`ProductCopyControlTest`), so the card is byte for byte the card of today.

`App\Services\Catalog\ProductCopyToCountry` makes the copy. What it does:

| Behaviour | What happens |
|---|---|
| An explicit target | the select offers every configured country **except the product's own**, and the service refuses a target that is missing, empty, not a configured code, or of the wrong case. |
| Refuses the source country | a copy into the product's own country is refused by name, and nothing is created. |
| The code only when free there | the code and the barcode are carried only when `isCodeTaken` / `isBarcodeTaken` say the target country holds neither; when one is taken the copy is created without it, for the admin to type, exactly as the add form behaves. |
| Audited | one `catalog.product.copied_to_country` line through `AuditLogger::recordOrFail`, naming the source product, both countries, whether the code and the barcode were kept, that no stock or price was carried, and any image whose file could not be read. **The row and the line are written in one transaction**: a copy nobody can see a record of must not exist. |
| Its own image files | every image — thumbnail, gallery list, colour images, meta image, preview file, digital file — is copied byte for byte to a new name through `FileManagerTrait::copyStoredFile`, which finds the source wherever it is (the public disk, then s3: the stored `*_storage_type` is a hint, not the answer) and writes the copy to whichever disk this installation stores on now. Two records must never point at one file: deleting the source's image would otherwise break the copy, and one country's catalogue would depend on another's storage. A source file that cannot be read leaves the copy's field empty and is named in the audit line — never shared. |

What a copy never carries, because catalogue products stay independent per country (GCC-08, GCC-18): **stock** (the product's and every variant's
quantity start at zero), **price** (the unit price, the purchase price, the discount and every variant's price start at zero, because the source's
price is in the source country's currency and carrying it would read as "this is the price here"), the published state (the copy is created
**unpublished**, so a product priced at zero cannot be sold before an admin prices it, and the admin lands on its edit form), and anything a person
did — reviews, wishlists, carts and order history belong to the row they were made against. Channel listings, routing rules and provider mappings
are keyed by the product id and are not copied, and **no link between the twins is written**: there is nothing to sync, now or later.

The variants keep their shapes and their SKUs. A variant SKU is checked by the B-06 gate in the country the copy is saved in, so a SKU the target
country already holds is caught the moment the admin saves the copy's form.

Only a column the source row already has is ever written, so an installation whose products table is older than one of these columns is not given
a column by a copy.

### The site guard

`tests/Feature/Catalog/ProductCodeLookupGuardTest.php` reads every PHP file under `app/` and `Modules/` and fails for any place that looks a product up,
filters it or validates it by `code` or `barcode` (where-style calls, repository filters, `unique:products`, `Rule::unique('products', ...)`) that is not
listed in its table with a survey id and a reason. The table is exact: a different count, or a file that no longer holds a lookup, fails too. It lists the
covered files as well (the lookup stays in the file, and the country comes from `ProductCodeScope`), which is stricter than "a file that uses the scope
passes". A lookup the scanner cannot read the subject of is treated like a product lookup; a lookup it reads as being about a colour, a currency or a coupon
needs no entry. Probe tests prove it finds each way of writing a lookup and fails for a new unlisted one. It is a pattern check over source text: a lookup
built from a variable column name, or a raw SQL string, is not seen. A second table lists, for every **labelled** site, the files that draw its label and the
text each must hold (`countryTag(`, `tagPickerRows(`, `country_tag`, `countryColumn`): a labelled site that names none, or a file that loses its call, fails
the guard, so a label cannot be dropped without the registry saying so.

### The six-country isolation matrix

`tests/Feature/Catalog/SixCountry*Test.php` (with `tests/Support/BuildsCountryMatrix.php`) prove the isolation between the countries from
`config('gcc.storefronts.countries')` (SA, AE, KW, QA, BH, OM today; a country added to the configuration is covered by the next run). For **every ordered
pair of different countries** the same code and the same barcode can exist in both and be saved; inside one country they are refused; each case has its
switch-off twin that must be the global rule of today. The gates covered: the admin add form (the admin choosing the country, choosing none, choosing a
country that is not configured), the vendor add form, the admin edit form, the seller apps v2 and v3 (add and edit), the bulk import, the M5AZN importer, the
seller POS scan (the default storefront's product when no country is named, each of the six when named, 409 for a code held twice inside one country and
only there, 422 for a country that is not configured, nothing for a missing or empty code), the channel product matcher (accounts of known and of unknown
countries), the routing rule by SKU (rule country, no rule country, product country, destination), the Torod suggestions and picker for each default
storefront, the readiness report of a catalogue in which every pair shares a code, and the country of an order. A seller add, an import and an M5AZN item
are saved in the table default country, so their pairs reduce to the country that holds the code; each is also run with another default storefront to pin
that the default storefront does not move them.

## Turning it on

1. Deploy the slice with the switch off. Nothing changes.
2. Run the `2026_11_25_100000_add_storefront_country_to_products` migration (see `docs/gcc/STOREFRONT_FLAG_RUNBOOK.md` for its measured cost and the MariaDB
   caveat).
3. Run `php artisan gcc:catalog:code-readiness`. It is read only. It prints the products per country, the products with an empty country, the codes and
   barcodes held **twice inside one country**, the ones **shared by countries**, the country codes that are not configured, the count of every site
   status (the totals above) and the sites that are still open. **Exit 1** when the migration has not run, a product has an empty country, or a code or barcode is held twice inside one
   country (the switch would refuse those from then on, and every lookup would meet an ambiguity). A code shared by two countries is listed and does not
   block: it is what the switch allows, and none is expected before it is on.
4. Decide the open points below, in particular which of the open sites the owner accepts (D1 partial, G9 deferred).
5. Set `GCC_COUNTRY_SCOPED_CODES=true`, run `php artisan config:clear` and `php artisan cache:clear` (product caches).
6. Check: add a product with a code that exists in another country, once on the admin form and once through the seller app; scan a code that two countries
   hold with and without `country=`.

**Do not turn it off again once two countries hold a product of the same code.** Off, the un-narrowed sites above pick one of the twins again, silently
(the seller POS scan takes the lowest id), and the global gates refuse to edit either of them. The readiness command's "shared by countries" list is the
list of what would be exposed.

### The database index (slice 3)

The gates of slices 1 and 2 are the first refusal. The index is the last one, and it holds for a path that never reaches a form — a console
command, a queue job, an import written next year — and for two requests that pass validation at the same instant.

`App\Services\Catalog\ProductCodeIndex` owns it. Two indexes, `products_country_code_unique` and `products_country_barcode_unique`, each over
`(storefront_country, <the code>)`.

**The rule for an absent code: uniqueness holds among non-empty values only.** Most of a real catalogue has no barcode, and a plain unique index
reads every one of those rows as the same product and refuses all but the first. NULL and `''` are both "this product has no barcode", so both are
left out of the index:

| Engine | How the rule is written |
|---|---|
| MariaDB, MySQL | no partial index exists, so the index is laid over a VIRTUAL generated column, `NULLIF(code, '')`. Adding a virtual column rewrites no rows, and a unique index skips its NULLs. |
| SQLite, PostgreSQL | the same rule written directly: `CREATE UNIQUE INDEX ... WHERE code IS NOT NULL AND code <> ''`. |

Measured on MariaDB 10.11.14 and on SQLite: many products of one country may have no code and no barcode at all, or an empty one, or one of each;
a real value repeated inside one country is refused; the same value in two countries is accepted; and the code index and the barcode index do not
constrain each other (`ProductCodeIndexTest`).

**The index compares as the column's collation says, not as PHP does.** On a table whose collation is case-insensitive (`latin1_swedish_ci` in the
shipped dump) the index reads `A1` and `a1` as one value while the gates, which compare in PHP, read them as two. The index is therefore the
stricter of the pair, never the looser. `ProductCodeReadiness` groups with the same collation, so a pair the index would refuse is counted by the
report before anything is applied.

**Nothing is applied by deploying the code.** `php artisan gcc:catalog:code-readiness` is read only and now names each index, whether it is applied,
how many values block it, and how many rows are outside it because they have no value. The `2026_12_12_100000_add_country_unique_codes_to_products`
migration creates nothing unless `products.storefront_country` is there, and `ProductCodeIndex::create()` **refuses — writing nothing — when the live
catalogue holds a value twice inside one country**. No row is ever deleted, merged or edited to make an index fit: which of a pair of duplicates to
keep is the owner's decision, taken with the report in hand. An operator who meets the refusal has lost nothing, because the gates refuse the same
duplicates whether or not the index exists.

Rollback: `down()` drops the indexes (and the generated columns on MySQL and MariaDB). Nothing depends on either, and the gates go on refusing what
they refused. A rehearsal on a copy of the real data is still the right order of work: the report says whether the index *can* be created, not how
long creating it will take on a table of that size.

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
7. **A channel account's country is read from its own settings:** Trendyol `storefront_code` (SA, AE, KW), noon `country_code`, and for Amazon the
   marketplace ids the connectors read (the account's `marketplace_ids`, which the account service keeps in step with the form's setting; the setting
   is read when the column is empty). Amazon sells in two Gulf countries, Saudi Arabia (`A17E79C6D8DWNP`) and the United Arab Emirates
   (`A2VIGQ35RCS4UG`); an account has a country when **every** one of its marketplaces is one of those two and they name the same country. An account
   with no marketplace, one this list does not know (Egypt, Turkey, Europe, India...), or marketplaces of two countries has none, like the Turkish
   Trendyol marketplace or an unconfigured code. An account with no country is never narrowed (a shared code stays a conflict, nothing links on a
   guess).
8. **The routing rule (D6):** with the switch on a SKU condition needs the line's product to be in the country the rule names, or in the default storefront's
   when it names none (every rule written before the switch was written when there was one country). The rule's `country` still also means the order's
   **destination**; both must hold. A line whose product country is unknown matches no SKU rule. Product-id rules and every other condition are untouched.
   Decisions recorded for a line carry `product_country` only when it was read, so a decision made with the switch off reads as before.
9. **An order's country follows from its lines** (all in one country, no unknown product); otherwise the order-edit picker is not narrowed.
10. **The seller's own surfaces are not labelled** (the vendor POS rows and grid, the vendor export and gallery, the vendor pickers): every seller product
    is in the table default country until sellers have markets, so a seller's own list cannot hold twins. Pinned by `SellerSurfacesCountryTest` (see "The
    labelled sites").
11. **A text field is used, never layout:** every label is text added inside the element that already holds the name (or the SKU line), only while the
    switch is on; the rendered HTML with the switch off is the old one. The label of a product whose country is empty is empty. The only new column is the
    one at the end of the admin export.
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
    middle of `en`, `sa` and `ae` `new-messages.php`; the Arabic text is mine and should be reviewed. Slice 2 adds no key: the export header reuses
    `product_country`.
18. **Only pickers are labelled**, not the lists a person reads: the products already in a deal or a clearance sale, the stock-limit and restock lists and the
    seller-centre lists show names and ids and every action on them is keyed by the id (C7 to C11, D8).
19. **A picker that answers JSON hands the script the label, not the country:** `country_tag` is the exact text to append (` · KW`), so the separator is
    written once, and the `storefront_country` column is not exposed by a new payload. The seller-facing responses and the seller app's `Product`
    payloads are unchanged: the column stays in the model's `$hidden`.
20. **The export column is the country code** (`KW`), not the label, so it can be filtered in a spreadsheet; it is added only to the admin export. A scan,
    a form or an import never reads the export.
21. **The default storefront never decides where a seller product, an import or an M5AZN item is saved:** the table default does. The matrix runs each of
    those gates with another default storefront to pin it.

### Queries that changed shape while the answer did not (switch off)

The answers are the same; the SQL text is not, in these places: `getUniqueProductSKUCode` asks `DB::table('products')` instead of the model (same
`exists`); the bulk import inserts its rows one by one inside one transaction (all or nothing, as the single insert was) so it knows their ids, and no longer
queries products by code; the M5AZN clash check is two `exists` questions instead of one OR-ed query; the seller POS scan splits one nested `where([...])`
into two conditions (`limit 1`, as `first()`); a rule is a `Unique` object instead of the string (`unique:products,code,...` parses to the same thing); the
admin and vendor order-edit pickers pass a `storefront_country` filter that is empty (and then ignored) while off; the routing context asks nothing about a
product's country while off. The vendor import is unchanged except for the generated code.

## Open owner decisions

1. Which of the open sites are acceptable on the day the switch is turned on (the readiness command lists them): D1 (partial) and G9 (deferred).
2. The seller app: send `country` (or the product id) when scanning; handle 409 and 422. Who changes it, and when.
3. A country for a provider connection (Torod, M5AZN), so that E1 and E2 serve the right country instead of the default storefront, and so that an import
   can land in a country other than `SA`.
4. Amazon accounts of marketplaces outside the two Gulf ones (a country setting, or more ids in the mapping), so that D1 can narrow them: the list
   knows no marketplace of Kuwait, Qatar, Bahrain or Oman, and an account of two marketplaces sells in two countries, so neither is narrowed.
5. Whether a promotion (flash deal, deal of the day, clearance sale) belongs to a country (GCC-17): C4 (clearance sale), C5 and C6 are **labelled**, not
   narrowed, until it does. Until then an admin can put the products of two countries in one promotion and sees which is which.
6. **Who may copy a product into another country.** The action is behind the admin product module as the rest of the product screens are; whether it needs a
   permission of its own is the owner's call. The tax set-up of a copy is carried as the source's and is the owner's call too (the copy is created unpublished,
   so nothing is sold on it before an admin has looked).
7. **Whether the live data can take the unique index**, and if it cannot, which of each pair of duplicates to keep. `php artisan gcc:catalog:code-readiness`
   answers the first question and lists the pairs; nothing in this branch deletes or merges a row, and the migration refuses rather than force one through.
8. Whether the routing-rule reading in assumption 8 is the one the owner wants (the alternative is a product-id key or a country column on the rule, which
   needs a migration).
