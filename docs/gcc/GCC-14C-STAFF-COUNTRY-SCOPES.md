# GCC-14c: staff country scopes (slice 1: the product screens of the admin panel)

**Status:** draft, behind a switch that ships **off**. Nothing here runs on the pilot server or on production. One additive migration, no change to what a Saudi
customer, seller or admin sees or is answered while the switch is off, and nothing narrows an employee until somebody assigns them a country.

Decision behind it: **GCC-14**, one staff login with multi-country scopes: keep the existing role and module authorization and add data and action scopes,
enforced in the backend (`docs/gcc/DECISIONS.md`). The owner's rule for every new GCC behaviour applies: default **off**, and the Saudi behaviour and the APIs stay
byte-identical while it is off.

Slice 1 enforces the scope on **the product screens of the admin panel** and nothing else. Orders, refunds, customers, reports, the vendor panel, the seller API
staff and the legal entities are out of slice 1. They are listed below, with the rule that would apply, so that the next slice starts from the table.

## What the switch does

`gcc.staff.country_scopes` (env `GCC_STAFF_COUNTRY_SCOPES`, default `false`, in `config/gcc.php`).

- **Off (the default):** no table lookup, no schema probe and no extra query is made for any request, and every query and answer is the one of today.
- **On, and both the `admin_country_scopes` table and `products.storefront_country` exist:** an employee who has been given countries works with the products of those
  countries only, in the product screens of the admin panel.
- **On, and either is missing** (a migration has not run): the switch acts as off. Nothing mentions the missing table or column and nothing answers HTTP 500.

The switch does **not** depend on `gcc.storefronts.enabled`: the staff scope narrows what an employee sees in the panel, whether or not a storefront of that country
is open yet (a country's catalogue is prepared before its storefront opens, GCC-14a). While storefronts are off nothing offers a country when a product is added, so
what a restricted employee can add is covered in the table of the add form below.

`App\Services\Staff\StaffCountryScope` is the only place that knows the switch, the table and how rows become an employee's scope. The employee's countries are
resolved once per request, by the middleware of the product screens, and **bound to that request** (its attributes): never to the container, a static or the cache, so
the next request of the same process, a queue worker and a console command start unbound, and a scope assigned between two requests applies to the second.

## Who is restricted

The scope of an employee is made of the rows of `admin_country_scopes` (`admin_id`, `country`, unique pair).

| Employee | Rows | Scope |
|---|---|---|
| The master admin (`admins.admin_role_id` = 1) | any | **never restricted**; the rows are ignored (the commands refuse to write them) |
| Any other employee | none | **unrestricted**: turning the switch on changes nothing until somebody assigns a country |
| Any other employee | `KW`, `SA` | restricted to Kuwait and Saudi Arabia |
| Any other employee | a value that is not a configured country (`zz`, `sa`, an empty string) | the value **grants nothing**; the rows still exist, so the employee stays restricted. If every value is like that, the employee is restricted to **nothing**: sees no product and adds none |
| Any other employee | a country that was taken out of `gcc.storefronts.countries` since | it stops granting, on the next request |
| Any other employee | a country that is configured but not open (`AE`) | grantable: the catalogue of a country is prepared before it opens |

The country is two characters compared byte for byte (`ascii_bin` on MySQL), as `products.storefront_country` is, so `sa` is not `SA`. An unknown or damaged value can
only narrow an employee, never widen them. An employee is an employee even when their role was never set.

## The commands

No HTTP route creates, widens or reads the table (a test reads the routes, the controllers, the modules and the views for it). Only an operator on the server can:

```
php artisan gcc:staff-country:assign {admin_id} {countries}        e.g. 4 SA,KW   adds the countries named; running it again changes nothing
php artisan gcc:staff-country:revoke {admin_id} {countries}        takes the countries named back
php artisan gcc:staff-country:revoke {admin_id} --all              takes every country back: the employee is unrestricted again
php artisan gcc:staff-country:list [admin_id]                      read only; says what is stored, what it comes to, and whether it is enforced
```

- Codes are checked against `gcc.storefronts.countries` (case does not matter: `kw` is stored as `KW`). One unknown code refuses the whole command and writes nothing.
- `assign` refuses an admin that does not exist and the master admin. It says when `GCC_STAFF_COUNTRY_SCOPES` is off that nothing is enforced yet.
- **`revoke` refuses to take back the last country**, because an employee with none is unrestricted: taking the last one back would widen them silently. `--all` says
  that is what the operator means. A damaged row stays (it keeps the employee restricted) until `--all`.
- Every command says first when `admin_country_scopes` does not exist yet.

## What slice 1 enforces

The two controllers of the product screens (`Admin\Product\ProductController`, 38 routes, and `Admin\Marketplace\ProductModerationController`, 4 routes, which approves
or rejects a seller product by its id) register one middleware, `ScopeProductsToStaffCountries`. Because the controllers register it, it runs **after** the sign-in check
and the module permission of the route: an employee who may not use a screen is told so, whatever the product, before the scope looks at one. It binds the employee's
countries to the request, and for a restricted employee it does two things.

### (a) Lists, counters, pickers, filters and exports show their countries only

`App\Models\Scopes\StaffCountryProductScope` is an Eloquent global scope of `Product`. While a request is bound, **every** product query of it, written with the
repository, the model or a relation, reaches only the products of those countries: the lists of the in-house and vendor products, the stock-limit list and its count,
the list of updated shipping costs, the product gallery, the restock requests (they are read through their product) and their count and export, the product export, the
search pickers, the product form pickers (their labels leave out the ids of other countries as unknown ids are), the selected products of a deal page, and the lookup by
id of every action. It is an Eloquent scope on purpose: Eloquent wraps what a query already holds in parentheses before it adds its own condition, and the product
search ends in an `or`, where a condition appended by hand could let another country's product through.

`ProductRepository::getFirstWhereWithoutGlobalScope` used to drop **every** scope of the model, this one included; it drops the `translate` scope only, as the other
repositories do (the SQL is the same). `ProductCountry::options()`, which the add form, the list filter and the validation read, is cut to the employee's countries.
A query that must see every country (the check that a brand is in use) simply is not made by a request that is bound: only the two covered controllers bind.

### (b) A route that names a product answers like an unknown product

For a restricted employee the middleware reads the rule of the action (`App\Services\Staff\StaffProductRoutes`) and answers **404, with no message, the same body for a
product of another country and for one that does not exist**, never 403:

- one id (in the path or the input) that is another country's, unknown, or not an id at all; a missing id when the route needs one;
- a **list** of ids (the bulk status change, the bulk moderation) with one such id: the whole request is refused and nothing is touched; an empty list names nothing and the
  action refuses it itself;
- a restock request (`restock-delete/{id}`) whose product is of another country or gone.

A value is an id only when it is digits without a leading zero: `10abc`, `010` and `1e1` mean the product 10 to MySQL and are refused here. An optional id (the
combination views of the add form have no product yet) passes when it is missing. For everyone who is not restricted an unknown id stays what the action always answered
(a redirect, an error page): only the restricted employee's answers are uniform, because only theirs can leak.

**A route of a covered controller that has no rule is refused to a restricted employee**, never let through, and a test fails for it, so a screen added later cannot widen
anybody. The rules of the 42 routes:

| Rule | Meaning |
|---|---|
| `list` | the action returns product rows (a list, a counter, a picker, an export): the query scope narrows them |
| `none` | it names no existing product (a blank form, the categories) |
| `new` | it makes new products: the form request and the importer check their country |
| `route:name` | one product id in the path |
| `input:name` | one product id in the request (`name?` when it may be missing) |
| `inputs:name` | a list of product ids in the request: all of them must be in scope |
| `restock:name` | one restock request id in the path: the product it asks for must be in scope |

| Route | Action | Rule | Permission |
|---|---|---|---|
| `GET admin/products/list/{type}` | `ProductController::index` | `list` | module:catalog |
| `GET admin/products/add` | `ProductController::getAddView` | `none` | module:catalog |
| `POST admin/products/add` | `ProductController::add` | `new` | module:catalog |
| `GET admin/products/view/{addedBy}/{id}` | `ProductController::getView` | `route:id` | module:catalog |
| `POST admin/products/sku-combination` | `ProductController::getSkuCombinationView` | `input:product_id?` | module:catalog |
| `POST admin/products/digital-variation-combination` | `ProductController::getDigitalVariationCombinationView` | `input:product_id?` | module:catalog |
| `POST admin/products/digital-variation-file-delete` | `ProductController::deleteDigitalVariationFile` | `input:product_id` | module:catalog |
| `POST admin/products/featured-status` | `ProductController::updateFeaturedStatus` | `input:id` | module:catalog |
| `GET admin/products/get-categories` | `ProductController::getCategories` | `none` | module:catalog |
| `POST admin/products/status-update` | `ProductController::updateStatus` | `input:id` | module:catalog |
| `POST admin/products/bulk-product-status` | `ProductController::bulkUpdateStatus` | `inputs:ids` | module:catalog |
| `GET admin/products/barcode/{id}` | `ProductController::getBarcodeView` | `route:id` | module:catalog |
| `GET admin/products/export-excel/{type}` | `ProductController::exportList` | `list` | module:catalog |
| `GET admin/products/stock-limit-list/{type}` | `ProductController::getStockLimitListView` | `list` | module:catalog |
| `DELETE admin/products/delete/{id}` | `ProductController::delete` | `route:id` | module:catalog |
| `GET admin/products/update/{id}` | `ProductController::getUpdateView` | `route:id` | module:catalog |
| `POST admin/products/update/{id}` | `ProductController::update` | `route:id` | module:catalog |
| `POST admin/products/update-product-images/{id}` | `ProductController::updateProductImages` | `route:id` | module:catalog |
| `GET admin/products/delete-image` | `ProductController::deleteImage` | `input:id` | module:catalog |
| `GET admin/products/get-variations` | `ProductController::getVariations` | `input:id` | module:catalog |
| `POST admin/products/update-quantity` | `ProductController::updateQuantity` | `input:product_id` | module:catalog |
| `GET admin/products/bulk-import` | `ProductController::getBulkImportView` | `none` | module:catalog |
| `POST admin/products/bulk-import` | `ProductController::importBulkProduct` | `new` | module:catalog |
| `GET admin/products/updated-product-list/{type}` | `ProductController::updatedProductList` | `list` | module:catalog |
| `POST admin/products/updated-shipping` | `ProductController::updatedShipping` | `input:id` | module:catalog |
| `POST admin/products/deny` | `ProductController::deny` | `input:id` | module:catalog |
| `POST admin/products/approve-status` | `ProductController::approveStatus` | `input:id` | module:catalog |
| `GET admin/products/product-gallery` | `ProductController::getProductGalleryView` | `list` | module:catalog |
| `GET admin/products/stock-limit-status/{type}` | `ProductController::getStockLimitStatus` | `list` | module:catalog |
| `POST admin/products/delete-preview-file` | `ProductController::deletePreviewFile` | `input:product_id` | module:catalog |
| `GET admin/products/request-restock-list` | `ProductController::getRequestRestockListView` | `list` | module:catalog |
| `GET admin/products/export-restock` | `ProductController::exportRestockList` | `list` | module:catalog |
| `DELETE admin/products/restock-delete/{id}` | `ProductController::deleteRestock` | `restock:id` | module:catalog |
| `GET admin/products/multiple-product-details` | `ProductController::getMultipleProductDetailsView` | `list` | none |
| `GET admin/products/search` | `ProductController::getSearchedProductsView` | `list` | none |
| `GET admin/products/search-all-product` | `ProductController::getSearchedAllProductsView` | `list` | none |
| `GET admin/products/picker-options` | `ProductController::getProductPickerOptions` | `list` | none |
| `GET admin/products/picker-labels` | `ProductController::getProductPickerLabels` | `list` | none |
| `GET admin/marketplace/product-moderation` | `ProductModerationController::index` | `none` | module:marketplace |
| `GET admin/marketplace/product-moderation/history/{productId}` | `ProductModerationController::history` | `route:productId` | module:marketplace |
| `POST admin/marketplace/product-moderation/moderate` | `ProductModerationController::moderate` | `input:product_id` | module:marketplace |
| `POST admin/marketplace/product-moderation/bulk` | `ProductModerationController::bulk` | `inputs:product_ids` | module:marketplace |

### (c) Adding a product and importing a sheet

**The add form.** The country a new product is saved in must be one of the employee's, or the add is refused with a validation message on the `storefront_country`
field. `App\Rules\NewProductCountryInStaffScope` asks the code that builds the row (`ProductCountry::newProductAttributes`), so what is checked is what is stored. It runs
when the field is missing too, because a missing choice is still a country, and it comes first (`bail`) so that the employee reads one clear message. For everyone who is
not restricted the rules are exactly what they were.

| Employee works with | Countries tracked (storefronts on) | Countries not tracked (storefronts off: the form has no country, the row takes `SA`) |
|---|---|---|
| `KW` | `KW` passes; `SA`, `AE`, a missing choice (the default storefront's country, `SA`), an invalid value: refused | refused: the row would take `SA` |
| `KW`, `SA` | `KW` and `SA` pass; a missing choice passes (`SA`); `AE` is refused | passes: the row takes `SA` |
| nothing (every value damaged) | refused | refused |

The form's select and the list filter offer the employee's countries only. A foreign choice is refused by this rule and never silently replaced by the default.

**The bulk import.** The sheet has no country column, so every row takes one country. Decision, with the reason that it is the only reading that never saves a product
somewhere its owner cannot see it:

| Employee | Countries tracked | Countries not tracked |
|---|---|---|
| exactly one country | the rows take it (the column is written, so the tax rows that look the new products up find them) | the rows take `SA`, as always, if it is that country; otherwise the sheet is refused |
| several countries | the sheet is **refused whole, before it is read**, with a message that says why (nothing is written) | the rows take `SA`, as always, if it is one of theirs; otherwise the sheet is refused |
| none usable | refused | refused |
| the master admin, an employee with no country, the vendor import, the switch off | unchanged: the rows carry no country and the table gives them its default | unchanged |

An employee with several countries adds the products one by one, or an administrator who is not restricted imports the sheet.

## The survey: every admin route that reads products or names one

`tests/Feature/StaffScope/StaffProductRouteInventoryTest.php` walks the real route table of the admin panel and fails for an admin controller that reads products or names
one and is neither scoped nor listed in `tests/Support/StaffProductInventory.php` with a reason (and, when it is out of slice 1, the slice that takes it). The table is
exact: an entry whose controller no longer reads products fails, so does an entry without a reason, and every route of a scoped controller must have a rule. A second test
keeps this document in step with the table and the routes. The scan reads the source of each action, its path and its parameters, and the file of each controller (so a
route that delegates to a helper of its controller is not missed): the repository, the model, the product manager, the products table, and a product id in a request.

**49 controllers: 2 scoped in slice 1, 6 not needed, 41 out of slice 1.**
**153 routes read or name a product: 42 scoped in slice 1, 22 not needed, 89 out of slice 1.**

The slices that follow, in the order proposed:

| Slice | What it takes |
|---|---|
| slice 2 | promotions and storefront content: deals, banners, themes, the SEO pages and the site map |
| slice 3 | orders, POS and refunds |
| slice 4 | reports and the dashboard counters |
| slice 5 | vendors, reviews and the marketplace operations |
| slice 6 | integrations and costs: sales channels, fulfilment providers and the operational costs |

| Controller | Admin routes | Routes that read or name a product | Status | Why |
|---|---:|---:|---|---|
| `Admin\Marketplace\ProductModerationController` | 4 | 4 | scoped in slice 1 | Approve, reject or suspend a seller product by its id, singly or in bulk, and read its history: an id route like the product screens. |
| `Admin\Product\ProductController` | 38 | 38 | scoped in slice 1 | The product screens: lists, counters, pickers, exports, the add and edit forms, and every action on a product. Every action has a rule in StaffProductRoutes. |
| `Admin\Product\BrandController` | 9 | 1 | not needed | Reads the products of a brand only to refuse deleting a brand that is in use. The answer must count every country's products, or a brand a product of another country uses could be deleted. |
| `Admin\Product\CategoryController` | 7 | 2 | not needed | As the brands: the products of a category are read only to refuse deleting or moving a category that is in use, and that count must include every country. |
| `Admin\SalesChannels\ChannelAccountOperationsController` | 7 | 1 | not needed | The only route that mentions a product is the Noon product group of a channel account, a setting of the account. It reads no product row. |
| `Admin\Settings\BusinessSettingsController` | 15 | 2 | not needed | The product settings page is a setting of the shop (brands, digital products, approval). It reads no product row. |
| `Modules\AI\API\V3\AIAuctionProductController` | 8 | 8 | not needed | As the product text generation, for the auction add-on, whose products are not in the products table. |
| `Modules\AI\Admin\AIProductController` | 8 | 8 | not needed | Writes a title, a description or the SEO text of a product from what is typed in the form. It reads no product row. |
| `Admin\Promotion\BannerController` | 8 | 2 | out of slice 1 (slice 2) | A banner can point at a product, picked among every country's. |
| `Admin\Promotion\ClearanceSaleController` | 11 | 8 | out of slice 1 (slice 2) | Picks, lists and removes clearance products of every country. |
| `Admin\Promotion\DealOfTheDayController` | 6 | 4 | out of slice 1 (slice 2) | Picks the product of the day among every country's products. |
| `Admin\Promotion\FlashDealController` | 10 | 4 | out of slice 1 (slice 2) | The deal pages pick products and list the ones in a deal: every country's. A promotion has no country yet (GCC-17). |
| `Admin\Promotion\MostDemandedController` | 6 | 0 | out of slice 1 (slice 2) | Picks the products of the most demanded section among every country's. |
| `Admin\Settings\InhouseShopController` | 6 | 1 | out of slice 1 (slice 2) | The in-house shop page lists the in-house products of every country. |
| `Admin\Settings\SeoHealthController` | 1 | 1 | out of slice 1 (slice 2) | Audits the SEO of the products a storefront shows, of every country. |
| `Admin\Settings\SeoTranslationController` | 3 | 0 | out of slice 1 (slice 2) | Lists the products whose SEO text can be translated: every country's. |
| `Admin\Settings\SiteMapController` | 6 | 0 | out of slice 1 (slice 2) | The site map lists the active products of every country. |
| `Admin\Settings\ThemeBuilderController` | 25 | 2 | out of slice 1 (slice 2) | The pickers of the theme builder list and name products of every country. |
| `Admin\Order\OrderController` | 17 | 1 | out of slice 1 (slice 3) | An order carries no country: it follows from its lines. The rule is that an employee works with an order only when all its lines are in their countries. |
| `Admin\Order\OrderEditController` | 7 | 6 | out of slice 1 (slice 3) | The product picker of the order edit offers every country's products. It follows the country of the order (all its lines are in one country). |
| `Admin\POS\CartController` | 9 | 3 | out of slice 1 (slice 3) | The POS cart takes a product by id from any country. |
| `Admin\POS\POSController` | 6 | 5 | out of slice 1 (slice 3) | The POS grid and search list every country's products. |
| `Admin\POS\POSOrderController` | 4 | 1 | out of slice 1 (slice 3) | Places a POS order from the lines of the cart, of any country. |
| `Admin\DashboardController` | 5 | 2 | out of slice 1 (slice 4) | Counters of products, stock and restock requests across every country. |
| `Admin\InhouseProductSaleController` | 1 | 1 | out of slice 1 (slice 4) | The in-house sales report lists every country's products. |
| `Admin\ProductReportController` | 2 | 2 | out of slice 1 (slice 4) | The all-products report and its export list every country's products. |
| `Admin\ProductStockReportController` | 2 | 2 | out of slice 1 (slice 4) | The stock report and its export list every country's products. |
| `Admin\ProductWishlistReportController` | 2 | 2 | out of slice 1 (slice 4) | The wishlist report and its export list every country's products. |
| `Admin\ReportController` | 6 | 1 | out of slice 1 (slice 4) | The earning and product counts of the reports are not split by country. |
| `Admin\TransactionReportController` | 5 | 1 | out of slice 1 (slice 4) | The transaction report counts the products of every country. |
| `Admin\VendorProductSaleReportController` | 2 | 1 | out of slice 1 (slice 4) | The vendor sales report lists the products of every country. |
| `Admin\Marketplace\BatchController` | 3 | 2 | out of slice 1 (slice 5) | Batches of a marketplace product, picked by id from any country. |
| `Admin\Marketplace\BrandRegistryController` | 6 | 0 | out of slice 1 (slice 5) | Counts the products of each brand across every country. |
| `Admin\Marketplace\CommissionRuleController` | 5 | 1 | out of slice 1 (slice 5) | A commission rule can name a product of any country. |
| `Admin\Marketplace\InventoryAdjustmentController` | 2 | 2 | out of slice 1 (slice 5) | Adjusts the stock of a product by id, of any country. |
| `Admin\Marketplace\PurchaseOrderController` | 7 | 1 | out of slice 1 (slice 5) | A purchase order line names a product of any country. |
| `Admin\Marketplace\ReturnLogisticsController` | 5 | 1 | out of slice 1 (slice 5) | A return names a product of any country. |
| `Admin\Marketplace\WarehouseController` | 5 | 3 | out of slice 1 (slice 5) | Places and transfers the stock of a product by id, of any country. |
| `Admin\Product\ReviewController` | 7 | 3 | out of slice 1 (slice 5) | The reviews list and its product filter show the reviews of every country's products. |
| `Admin\Vendor\VendorController` | 17 | 1 | out of slice 1 (slice 5) | A vendor's product tab lists its products of every country. |
| `Admin\Fulfillment\M5aznProductsController` | 3 | 3 | out of slice 1 (slice 6) | Imports provider items as products in the default country, and lists the ones already mapped. |
| `Admin\Fulfillment\TorodMatchingController` | 13 | 4 | out of slice 1 (slice 6) | Matches provider items to the products of the default storefront's country when codes are per country; otherwise every country's. |
| `Admin\OperationalCosts\ProductCostController` | 4 | 4 | out of slice 1 (slice 6) | The cost layer picker offers the products of every country. |
| `Admin\OperationalCosts\ReturnController` | 2 | 1 | out of slice 1 (slice 6) | The returns of the operational costs name products of any country. |
| `Admin\SalesChannels\ChannelInventoryController` | 2 | 1 | out of slice 1 (slice 6) | Lists the stock a channel holds for the products of every country. |
| `Admin\SalesChannels\ChannelListingController` | 9 | 2 | out of slice 1 (slice 6) | Drafts and publishes the listing of a product by id, of any country. |
| `Admin\SalesChannels\ChannelOrderController` | 7 | 1 | out of slice 1 (slice 6) | A channel order's lines are matched to products of any country. |
| `Admin\SalesChannels\ChannelProductController` | 8 | 8 | out of slice 1 (slice 6) | Links a channel listing to a product picked among every country's. |
| `Admin\SalesChannels\ChannelRoutingController` | 4 | 1 | out of slice 1 (slice 6) | A routing rule can name a product of any country. |

### Routes that read or name a product, of the controllers that are not needed

| Controller | Routes that read or name a product |
|---|---|
| `Admin\Product\BrandController` | `POST admin/brand/delete` |
| `Admin\Product\CategoryController` | `POST admin/category/update`<br>`POST admin/category/delete` |
| `Admin\SalesChannels\ChannelAccountOperationsController` | `POST admin/sales-channels/accounts/{account}/product-group/members` |
| `Admin\Settings\BusinessSettingsController` | `GET admin/business-settings/product-settings`<br>`POST admin/business-settings/product-settings` |
| `Modules\AI\API\V3\AIAuctionProductController` | `GET admin/auction/product/title-auto-fill`<br>`GET admin/auction/product/description-auto-fill`<br>`GET admin/auction/product/general-setup-auto-fill`<br>`GET admin/auction/product/shipping-policy-auto-fill`<br>`GET admin/auction/product/auction-info-auto-fill`<br>`GET admin/auction/product/seo-section-auto-fill`<br>`POST admin/auction/product/analyze-image-auto-fill`<br>`POST admin/auction/product/generate-title-suggestions` |
| `Modules\AI\Admin\AIProductController` | `GET admin/product/title-auto-fill`<br>`GET admin/product/description-auto-fill`<br>`GET admin/product/general-setup-auto-fill`<br>`GET admin/product/price-others-auto-fill`<br>`GET admin/product/seo-section-auto-fill`<br>`GET admin/product/variation-setup-auto-fill`<br>`POST admin/product/analyze-image-auto-fill`<br>`POST admin/product/generate-title-suggestions` |

### Routes that read or name a product, of the controllers that are out of slice 1

A restricted employee who has the permission of one of these screens still sees every country's products there. Each is named for the slice that takes it.

| Controller | Routes that read or name a product |
|---|---|
| `Admin\Promotion\BannerController` | `GET admin/banner/list`<br>`GET admin/banner/update/{id}` |
| `Admin\Promotion\ClearanceSaleController` | `GET admin/deal/clearance-sale`<br>`GET admin/deal/clearance-sale/search`<br>`GET admin/deal/clearance-sale/multiple-product-details`<br>`POST admin/deal/clearance-sale/add-clearance-product`<br>`POST admin/deal/clearance-sale/clearance-product-status-update`<br>`DELETE admin/deal/clearance-sale/clearance-delete/{product_id}`<br>`DELETE admin/deal/clearance-sale/clearance-products-delete`<br>`POST admin/deal/clearance-sale/update-discount` |
| `Admin\Promotion\DealOfTheDayController` | `GET admin/deal/day`<br>`POST admin/deal/day`<br>`GET admin/deal/day-update/{id}`<br>`POST admin/deal/day-update/{id}` |
| `Admin\Promotion\FlashDealController` | `POST admin/deal/delete-product`<br>`GET admin/deal/add-product/{deal_id}`<br>`POST admin/deal/add-product/{deal_id}`<br>`GET\|POST\|PUT\|PATCH\|DELETE\|OPTIONS admin/deal/search-product` |
| `Admin\Promotion\MostDemandedController` |  |
| `Admin\Settings\InhouseShopController` | `GET admin/business-settings/inhouse-shop` |
| `Admin\Settings\SeoHealthController` | `GET admin/seo-settings/health` |
| `Admin\Settings\SeoTranslationController` |  |
| `Admin\Settings\SiteMapController` |  |
| `Admin\Settings\ThemeBuilderController` | `GET admin/theme/builder/resources`<br>`GET admin/theme/builder/resource-labels` |
| `Admin\Order\OrderController` | `GET admin/orders/details/{id}` |
| `Admin\Order\OrderEditController` | `GET admin/orders/search-for-edit-order-product`<br>`POST admin/orders/edit-order-product-modal-view`<br>`POST admin/orders/edit-order-product-add`<br>`POST admin/orders/edit-order-product-variant-price`<br>`POST admin/orders/edit-order-product-list-update`<br>`POST admin/orders/edit-order-product-remove` |
| `Admin\POS\CartController` | `POST admin/pos/get-variant-price`<br>`POST admin/pos/quantity-update`<br>`POST admin/pos/add-to-cart` |
| `Admin\POS\POSController` | `GET admin/pos`<br>`POST admin/pos/update-discount`<br>`POST admin/pos/coupon-discount`<br>`GET admin/pos/quick-view`<br>`GET admin/pos/search-product` |
| `Admin\POS\POSOrderController` | `POST admin/pos/order-place` |
| `Admin\DashboardController` | `GET admin/dashboard`<br>`GET admin/dashboard/real-time-activities` |
| `Admin\InhouseProductSaleController` | `GET admin/report/inhouse-product-sale` |
| `Admin\ProductReportController` | `GET admin/report/all-product`<br>`GET admin/report/all-product-excel` |
| `Admin\ProductStockReportController` | `GET admin/stock/product-stock`<br>`GET admin/stock/product-stock-export` |
| `Admin\ProductWishlistReportController` | `GET admin/stock/product-in-wishlist`<br>`GET admin/stock/wishlist-product-export` |
| `Admin\ReportController` | `GET admin/report/vendor-earning` |
| `Admin\TransactionReportController` | `GET admin/transaction/order-transaction-list` |
| `Admin\VendorProductSaleReportController` | `GET admin/report/vendor-report` |
| `Admin\Marketplace\BatchController` | `GET admin/marketplace/batches`<br>`POST admin/marketplace/batches` |
| `Admin\Marketplace\BrandRegistryController` |  |
| `Admin\Marketplace\CommissionRuleController` | `GET admin/marketplace/commission-rules` |
| `Admin\Marketplace\InventoryAdjustmentController` | `GET admin/marketplace/inventory-adjustments`<br>`POST admin/marketplace/inventory-adjustments/adjust` |
| `Admin\Marketplace\PurchaseOrderController` | `POST admin/marketplace/purchase-orders` |
| `Admin\Marketplace\ReturnLogisticsController` | `POST admin/marketplace/returns` |
| `Admin\Marketplace\WarehouseController` | `GET admin/marketplace/warehouses`<br>`POST admin/marketplace/warehouses/place`<br>`POST admin/marketplace/warehouses/transfer` |
| `Admin\Product\ReviewController` | `GET admin/reviews/list`<br>`GET admin/reviews/export`<br>`GET\|POST\|PUT\|PATCH\|DELETE\|OPTIONS admin/reviews/search-product` |
| `Admin\Vendor\VendorController` | `GET admin/vendors/order-details/{order_id}/{vendor_id}` |
| `Admin\Fulfillment\M5aznProductsController` | `GET admin/fulfillment-providers/m5azn/products`<br>`POST admin/fulfillment-providers/m5azn/products/import-all`<br>`POST admin/fulfillment-providers/m5azn/products/{item}/import` |
| `Admin\Fulfillment\TorodMatchingController` | `GET admin/fulfillment-providers/torod-point/matching`<br>`POST admin/fulfillment-providers/torod-point/matching/approve`<br>`POST admin/fulfillment-providers/torod-point/matching/push`<br>`GET admin/fulfillment-providers/torod-point/products/search` |
| `Admin\OperationalCosts\ProductCostController` | `GET admin/operational-costs/product-costs`<br>`POST admin/operational-costs/product-costs`<br>`GET admin/operational-costs/product-costs/products/search`<br>`GET admin/operational-costs/product-costs/{layer}` |
| `Admin\OperationalCosts\ReturnController` | `GET admin/operational-costs/returns` |
| `Admin\SalesChannels\ChannelInventoryController` | `GET admin/sales-channels/inventory` |
| `Admin\SalesChannels\ChannelListingController` | `GET admin/sales-channels/listings/{product}`<br>`POST admin/sales-channels/listings/{product}/accounts/{account}` |
| `Admin\SalesChannels\ChannelOrderController` | `GET admin/sales-channels/orders/{mapping}` |
| `Admin\SalesChannels\ChannelProductController` | `GET admin/sales-channels/products`<br>`GET admin/sales-channels/products/product-search`<br>`POST admin/sales-channels/products/{mapping}/link`<br>`POST admin/sales-channels/products/{mapping}/ignore`<br>`POST admin/sales-channels/products/{mapping}/unignore`<br>`POST admin/sales-channels/products/{mapping}/accept-suggestion`<br>`POST admin/sales-channels/products/{mapping}/settings`<br>`POST admin/sales-channels/products/{mapping}/fulfilment-mode` |
| `Admin\SalesChannels\ChannelRoutingController` | `GET admin/sales-channels/routing` |

### Blind spots of the scan, found by hand

The scan does not follow a call into a service. These admin controllers reach products only through one, and are listed by hand (the guard checks that each is still an
admin controller, nothing more):

| Controller | Slice | Why it is not seen by the scan |
|---|---|---|
| `Admin\AdvancedSearchController` | slice 4 | The header search of the classic layout finds products of every country through AdvancedSearchService. |
| `Admin\V2AdvancedSearchController` | slice 4 | The header search of the new layout finds products of every country through the same service. |
| `Admin\Commerce\CollectionController` | slice 2 | The preview of a collection resolves its products through CollectionResolver. |
| `Admin\Order\RefundController` | slice 3 | A refund names the product of an order line, which belongs to an order of any country. |
| `Admin\Telemetry\AnalyticsController` | slice 4 | The analytics export by product counts the products of every country. |

### Routes added by other open branches

`security-digital-files-private-disk` adds `GET admin/products/digital-file/{id}/{fileName?}` and `GET admin/products/digital-variation-file/{id}/{fileName?}` to the product
controller. Merged with this branch they have no rule, so a restricted employee is refused them and the guard fails until each gets `route:id`: that is the guard doing its
job, and a one-line rule each.

## Out of slice 1: the rule that would apply

| Area | Rule for the slice that takes it |
|---|---|
| **Orders** | An order carries no country: it follows from its lines. A restricted employee works with an order only when **all its lines are products of their countries**; an order whose lines are in two countries needs an employee who has both (or none). A line whose product is gone has no country and counts as outside. The order lists, counters and exports narrow the same way. |
| **Refunds and returns** | They follow the order they belong to. |
| **Customers** | One identity across countries (GCC-13): a customer belongs to no country. What belongs to a country is their orders, wallets and addresses, which follow the rule above; a customer's list is not narrowed, their transactions are. |
| **Reports and the dashboard** | Every figure is split by the country of the order or the product, and a restricted employee sees only the figures of their countries. Counters that cannot be split are hidden from them. |
| **The vendor panel and seller API staff** | A different principal, with their own staff tables. Sellers have no markets yet (a seller product takes the default of the table), so there is nothing to scope until they do. |
| **Legal entities** | GCC-14 asks for scopes by country **and entity**. Entities have no table yet; an entity scope is a second dimension beside the country and needs its own decision. |
| **The platform admin (GCC-20)** | A separate principal with its own tables, untouched by this branch. **Owner decision recorded: a platform admin's grant country list is to be intersected with the staff scope once both are merged**, so that an employee who holds both never sees more than the narrower of the two. |

## Assumptions and decisions

1. **An employee with no row is unrestricted**, so turning the switch on changes nothing until somebody assigns a country. The alternative (everybody restricted to
   nothing until assigned) would lock every employee out on the day it is turned on.
2. **The master admin is `admin_role_id` = 1**, as in the module permission check. The admin middleware tests the id 1 for the status check; the scope does not.
3. **Unknown, damaged and removed values restrict, never widen.** The commands refuse to write them; a manual edit can only narrow.
4. **The scope is the middleware's, not a flag on the user.** It is resolved from the table on every request (3 small reads on a covered screen for a non-master employee
   while the switch is on, none while it is off), so a scope assigned or revoked applies to the next request without a deploy or a cache to clear.
5. **A restricted employee is answered 404 for an unknown id too**, where today the answer varies (a redirect to the list, an error page): the answers have to be the same
   for the scope not to be an oracle. Unrestricted employees keep the old answers.
6. **The controllers are built before the sign-in check of their routes**: the framework gathers the middleware a controller registers in its constructor before it runs the
   route's middleware. The constructors only store what they are given.
7. **A seller product takes the default country of the table (`SA`)**, so a restricted employee who does not work with `SA` neither sees, approves nor moderates seller
   products until sellers have markets.
8. **The product gallery copies a product by opening its edit form** (`products/update/{id}`): an employee can copy only a product of their own countries, into any of
   their countries.
9. **A list of companion products ("frequently bought together") is saved as the ids typed or picked** and is not checked against the scope. The form's picker offers the
   employee's products and the labels route leaves other countries' out; a crafted request can still store the id of another country's product. It exposes nothing to the
   employee, and the storefront shows a country's own products only. Checking the ids in `ProductService::getBoughtTogetherIds` is the fix, and slice 2's.
10. **The counters and the lists of the shared layout and of the dashboard** are not on the product screens and are not narrowed (slice 4).
    **No product screen fills a shared cache from a product query**: the caches of product lists are built by storefront requests, which are never bound, so a narrowed
    answer cannot be served to someone else.
11. **Three language keys** were added in the middle of `en`, `sa` and `ae` `new-messages.php`; the Arabic text is the author's and should be read by a native speaker.

### What changed shape while the answer did not (switch off)

- `Product` registers one more global scope, which reads the request attributes and adds nothing; the SQL is the same.
- `ProductRepository::getFirstWhereWithoutGlobalScope` drops the `translate` scope instead of all scopes: the same SQL.
- `ProductCountry::options()` and `newProductRules()` ask the staff scope first, which answers unbound without touching the database.
- The two controllers hold one more middleware in their pipeline, which lets the request through after one configuration read.

## Open owner decisions

1. Whether a restricted employee whose scope has more than one country should get a country choice on the import upload (and the sheet a country), instead of being
   refused. The refusal is the safe default.
2. Which of the out-of-slice screens are acceptable on the day the switch is turned on: until slices 2 to 6, a restricted employee with the permission of a promotion,
   order, POS, report or vendor screen still sees every country's products there. The survey above lists them.
3. Whether the master admin should be able to restrict **itself** (it cannot today: the commands refuse it).
4. Whether moderating seller products needs `SA` in the scope until sellers have markets, or a separate permission (assumption 7).
5. Whether an employee of one country may copy a product from the gallery into a country they do not work with (they cannot today: they cannot see it).
6. The entity dimension of GCC-14 (legal entities) and the intersection with the platform admin grants: recorded above, not designed here.

## Before the switch is turned on

1. Deploy with the switch off. Nothing changes.
2. Run the `2026_11_28_100000_create_admin_country_scopes_table` migration (additive; it never fails a deploy: the foreign key to `admins` is added only when `admins.id` is
   `BIGINT UNSIGNED` on InnoDB, and otherwise the table is created without it and one warning says why) and the products migration of GCC-14a.
3. Assign the countries: `gcc:staff-country:assign`, then check with `gcc:staff-country:list`. Nobody is restricted before then.
4. Read the survey above and accept, per slice, what still shows every country.
5. Set `GCC_STAFF_COUNTRY_SCOPES=true`, run `php artisan config:clear`.
6. Check as a restricted employee: the product list, a product of another country by its address (a 404 like an unknown id), a product add in another country (refused),
   and as the master admin that nothing changed.

Turning it off again leaves the rows in place and removes every restriction at once; turning it on restores them.

## The tests

`tests/Feature/StaffScope`: the service and the switch (including nothing asked of the database while off), the migration on every shape of `admins` MySQL can have, the three
commands, the lists and pickers and exports through the real route table, the id routes from the real route table (every route, every kind of id, foreign, unknown, junk,
lists, restock requests, the bytes of the answers), the real controllers' writes, the add form and the import, and the guard of the survey with probes that prove the scan finds
a new controller, a path and a parameter and ignores a route outside the admin group.
