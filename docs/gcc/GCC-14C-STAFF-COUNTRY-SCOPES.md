# GCC-14c: staff country scopes (slices 1 to 6: the product screens, the order, POS and refund screens, the shared layout, the people, the dashboard and the reports, the promotion and content screens, the marketplace screens, and the integrations and operational costs, of the admin panel)

**Status:** draft, behind a switch that ships **off**. Nothing here runs on the pilot server or on production. One additive migration, no change to what a Saudi
customer, seller or admin sees or is answered while the switch is off, and nothing narrows an employee until somebody assigns them a country.

Decision behind it: **GCC-14**, one staff login with multi-country scopes: keep the existing role and module authorization and add data and action scopes,
enforced in the backend (`docs/gcc/DECISIONS.md`). The owner's rule for every new GCC behaviour applies: default **off**, and the Saudi behaviour and the APIs stay
byte-identical while it is off.

Slice 1 enforces the scope on **the product screens of the admin panel**. Slice 2 enforces it on **the order, POS and refund screens of the admin panel**: an order follows
the countries of its lines, a refund request the country of the line it is about. Slice 3 enforces it on **everything else that reads orders in the admin panel**: the layout every
admin page shares, the screens of customers, delivery men and vendors, the dashboard, the reports, the tax reports and the search of the header. The promotions and the storefront
content (slice 4), the vendors' reviews and the marketplace (slice 5), the integrations and the costs (slice 6), the vendor panel, the seller API staff and the legal entities are
out of slices 1 to 3. They are listed below, with the rule that would apply, so that the next slice starts from the table.

## What the switch does

`gcc.staff.country_scopes` (env `GCC_STAFF_COUNTRY_SCOPES`, default `false`, in `config/gcc.php`).

- **Off (the default):** no table lookup, no schema probe and no extra query is made for any request, and every query and answer is the one of today.
- **On, and both the `admin_country_scopes` table and `products.storefront_country` exist:** an employee who has been given countries works with the products of those
  countries only, in the product screens of the admin panel, with the orders, POS sales and refund requests of those countries only, in their screens, and sees the orders of those
  countries only in the layout of every admin page, the screens of customers, delivery men and vendors, the dashboard and the reports.
- **On, and either is missing** (a migration has not run): the switch acts as off. Nothing mentions the missing table or column and nothing answers HTTP 500.

The switch does **not** depend on `gcc.storefronts.enabled`: the staff scope narrows what an employee sees in the panel, whether or not a storefront of that country
is open yet (a country's catalogue is prepared before its storefront opens, GCC-14a). While storefronts are off nothing offers a country when a product is added, so
what a restricted employee can add is covered in the table of the add form below.

`App\Services\Staff\StaffCountryScope` is the only place that knows the switch, the table and how rows become an employee's scope. The employee's countries are
resolved once per request, by the middleware of the covered screens (and, for the layout of a screen that none of them covers, by the layout itself), and **bound to that request** (its attributes): never to the container, a static or the cache, so
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

## What slice 1 enforces (the product screens)

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
anybody. The rules of the 45 routes:

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
| `GET admin/products/digital-file/{id}/{fileName?}` | `ProductController::downloadDigitalFile` | `route:id` | module:catalog |
| `GET admin/products/digital-variation-file/{id}/{fileName?}` | `ProductController::downloadDigitalVariationFile` | `variation:id` | module:catalog |
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
| `POST admin/products/copy-to-country/{id}` | `ProductController::copyToCountry` | `route:id` | module:catalog |
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
| `GET admin/shipping/destination-permissions` | `ProductDestinationPermissionController::index` | `input:product_id?` | module:catalog |
| `POST admin/shipping/destination-permissions` | `ProductDestinationPermissionController::record` | `input:product_id` | module:catalog |

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

## What slice 2 enforces (the order, POS and refund screens)

Seven controllers, 48 routes: `Admin\Order\OrderController` (17), `OrderEditController` (7), `DeliverySyriaDispatchController` (1), `RefundController` (4) and the POS
(`Admin\POS\POSController` 6, `CartController` 9, `POSOrderController` 4). They register one middleware, `ScopeOrdersToStaffCountries`, in their constructors, so it runs **after**
the sign-in check and the module permission of the route, as on the product screens (the invoice route has no module permission and is covered all the same). It binds the
employee's countries to the request, which is also what makes the product scope of slice 1 narrow every product these screens read (the POS grid and search, the product
pickers of the order edit), and then applies the rule of the action.

### What an order and a refund request are to a restricted employee

An order carries no country and no currency: it follows from its lines, and a line follows its product (`products.storefront_country`).

| The order | A restricted employee |
|---|---|
| has lines, and every line's product exists and belongs to one of their countries | sees it |
| holds two countries (a Saudi and a Kuwaiti line) and they hold one of them | does **not**: it needs an employee who holds both, or one with no country |
| has no line | does not: it is **unattributed** |
| has a line whose product was deleted | does not |
| has a line whose product has no country (the column is `NOT NULL`, so an empty or damaged value) | does not |
| (an employee restricted to nothing) | sees no order |

An unattributed order is hidden from every restricted employee, the one with all six countries included: **it fails closed**, and the master admin or an employee with no country
is who finds it. A **refund request follows the country of the order line it is about** (not the whole order): the refund of a Kuwaiti line of an order that also holds a Saudi
line is Kuwait's to decide, although Kuwait cannot open that order. A refund about a line that is gone, a deleted product or a product without a country is unattributed too.

Both rules are one correlated `EXISTS` / `NOT EXISTS` statement (`App\Services\Staff\StaffOrderVisibility`): *the order has a line* and *there is no line whose product is not
theirs*. It is written with `NOT EXISTS` and not `NOT IN`, so that a product with no country cannot be let through by a comparison with nothing. A whole list is one statement and
never a query per row (a test counts the queries of a list of two orders and of sixty), and the statement is plain SQL that MySQL 8, MariaDB 11.4 and SQLite plan alike. It uses
the `order_id` prefix of the existing indexes of `order_details` (`idx_order_details_order_id_delivery_status`, migration `2026_02_25_163645`) and the primary key of `products`:
**no migration**. Without an index on `order_details.order_id` the scope would read every line once per order, so the first step before the switch is to check it exists.

Measured once on MySQL 8 with 60,000 orders, 120,000 lines and 3,000 products, on a machine under a load of 12: a page of 25 orders, 5 ms; the counters by status, 1.4 s against
0.4 s for the same query without the scope (an index lookup of the lines of each order and a primary-key lookup of each of their products); the pending list of one country, 0.5 s.
The counters are the cost: a full scan that the scope makes about three times dearer, which the sidebar of the layout pays on every screen of slices 1 and 2 (and, from slice 3, on every admin screen) for a restricted
employee. Counting once a minute, or giving an order the country of its lines when it is placed, are the two ways out if the table grows; neither is needed to switch on.

### How a request is narrowed

`App\Models\Scopes\StaffCountryOrderScope` and `StaffCountryRefundScope` are Eloquent global scopes of `Order` and `RefundRequest`. They do nothing for a request that is not
narrowed (the same SQL, no extra query): while the switch is off, for the master admin, for an employee with no country, and for every request of the storefront, the vendor
panel, the seller API and the customer API, which never bind anything.

**A bound request is not narrowed to orders by default.** Products are narrowed as soon as a request is bound; an order is narrowed only where the rule of the action says that it
lists orders. The reason is that an action that changes an order must run on the real data, and a blanket scope would have silently changed money:

- the next POS order number is `100000 + the number of orders + 1`, and it is looked up by id to avoid a collision: narrowed to one country it would collide with an order of
  another country;
- the referral bonus is paid when the customer's count of delivered and paid orders is exactly one: narrowed to one country it would be paid again for a customer whose first order
  was in another country;
- a coupon counts how many times a customer has used it: narrowed, a limit would be bypassed by an order in another country.

So the middleware reads the rule of the action (`App\Services\Staff\StaffOrderRoutes`, a list of parts per action) and, for a restricted employee, does two things. It
answers **404**, with no message and the same body as for an id that does not exist, never 403, when a part names something outside the employee's countries; and it narrows what
the action **lists**.

| Part | Meaning |
|---|---|
| `none` | the action names no order, refund request or product and lists none (a session flag, a picker of customers, a cart kept in the session) |
| `list:orders` | it returns order rows (a list, a counter, an export, the neighbours of one order, the orders of its checkout and of its customer): the scope of the orders narrows them |
| `list:refunds` | it returns refund request rows |
| `order.route:n`, `order.input:n`, `order.inputs:n` | one order id in the path, one in the request, a list in the request (all must be visible) |
| `line.input:n` | one order **line** id in the request (the digital file after sale): the order it belongs to must be visible |
| `refund.route:n`, `refund.input:n` | one refund request id in the path or in the request |
| `product.input:n` | one product id in the request: it must be one of the employee's (the POS cart, the order edit) |
| `cart` | every product of the POS cart in use must be one of the employee's |
| `edit:n` | every product of the edit of the order named by input `n` must be one of the employee's |

The rules of the 49 routes:

| Route | Action | Rule | Permission |
|---|---|---|---|
| `GET admin/orders/list/{status}` | `OrderController::index` | `list:orders` | module:orders |
| `GET admin/orders/export-excel/{status}` | `OrderController::exportList` | `list:orders` | module:orders |
| `GET admin/orders/generate-invoice/{id}` | `OrderController::generateInvoice` | `order.route:id` | module:orders,pos,people |
| `GET admin/orders/details/{id}` | `OrderController::getView` | `order.route:id` + `list:orders` | module:orders |
| `POST admin/orders/address-update` | `OrderController::updateAddress` | `order.input:order_id` | module:orders |
| `POST admin/orders/update-deliver-info` | `OrderController::updateDeliverInfo` | `order.input:order_id` | module:orders |
| `GET admin/orders/add-delivery-man/{order_id}/{d_man_id}` | `OrderController::addDeliveryMan` | `order.route:order_id` | module:orders |
| `POST admin/orders/amount-date-update` | `OrderController::updateAmountDate` | `order.input:order_id` | module:orders |
| `GET admin/orders/customers` | `OrderController::getCustomers` | `none` | module:orders |
| `POST admin/orders/payment-status` | `OrderController::updatePaymentStatus` | `order.input:id` | module:orders |
| `GET admin/orders/inhouse-order-filter` | `OrderController::filterInHouseOrder` | `none` | module:orders |
| `POST admin/orders/digital-file-upload-after-sell` | `OrderController::uploadDigitalFileAfterSell` | `line.input:order_id` | module:orders |
| `GET admin/orders/digital-file/{id}/{fileName?}` | `OrderController::downloadDigitalFile` | `line.route:id` | module:orders |
| `POST admin/orders/status` | `OrderController::updateStatus` | `order.input:id` | module:orders |
| `POST admin/orders/bulk-status` | `OrderController::bulkUpdateStatus` | `order.inputs:ids` | module:orders |
| `POST admin/orders/customer-return-amount` | `OrderController::orderReturnAmountToCustomer` | `order.input:order_id` | module:orders |
| `POST admin/orders/customer-due-amount` | `OrderController::orderDueAmountSwitchToCOD` | `order.input:order_id` | module:orders |
| `POST admin/orders/customer-due-amount-mark-as-paid` | `OrderController::orderDueAmountMarkAsPaid` | `order.input:order_id` | module:orders |
| `GET admin/orders/search-for-edit-order-product` | `OrderEditController::getSearchEditOrderProductsView` | `order.input:order_id` | module:orders |
| `POST admin/orders/edit-order-product-modal-view` | `OrderEditController::getEditOrderProductModalView` | `order.input:order_id` + `product.input:product_id` | module:orders |
| `POST admin/orders/edit-order-product-add` | `OrderEditController::addEditOrderProduct` | `order.input:order_id` + `product.input:product_id` | module:orders |
| `POST admin/orders/edit-order-product-variant-price` | `OrderEditController::checkProductVariantPrice` | `order.input:order_id` + `product.input:product_id` | module:orders |
| `POST admin/orders/edit-order-product-list-update` | `OrderEditController::updateEditOrderProductList` | `order.input:order_id` | module:orders |
| `POST admin/orders/edit-order-product-remove` | `OrderEditController::removeEditOrderProduct` | `order.input:order_id` + `product.input:product_id` | module:orders |
| `POST admin/orders/edit-order-generate` | `OrderEditController::generateEditOrderByProductList` | `order.input:order_id` + `edit:order_id` | module:orders |
| `POST admin/orders/delivery-syria/dispatch` | `DeliverySyriaDispatchController::dispatchOrder` | `order.input:order_id` | module:orders |
| `GET admin/refund-section/refund/list/{status}` | `RefundController::index` | `list:refunds` | module:orders |
| `GET admin/refund-section/refund/export/{status}` | `RefundController::exportList` | `list:refunds` | module:orders |
| `GET admin/refund-section/refund/details/{id}` | `RefundController::getDetailsView` | `refund.route:id` | module:orders |
| `POST admin/refund-section/refund/refund-status-update` | `RefundController::updateRefundStatus` | `refund.input:id` | module:orders |
| `GET admin/pos` | `POSController::index` | `list:orders` | module:pos |
| `GET\|POST\|PUT\|PATCH\|DELETE\|OPTIONS admin/pos/change-customer` | `POSController::changeCustomer` | `none` | module:pos |
| `POST admin/pos/update-discount` | `POSController::updateDiscount` | `none` | module:pos |
| `POST admin/pos/coupon-discount` | `POSController::getCouponDiscount` | `none` | module:pos |
| `GET admin/pos/quick-view` | `POSController::getQuickView` | `product.input:product_id` | module:pos |
| `GET admin/pos/search-product` | `POSController::getSearchedProductsView` | `none` | module:pos |
| `POST admin/pos/get-variant-price` | `CartController::getVariantPrice` | `product.input:id` | module:pos |
| `POST admin/pos/quantity-update` | `CartController::updateQuantity` | `product.input:key` | module:pos |
| `GET admin/pos/get-cart-ids` | `CartController::getCartIds` | `none` | module:pos |
| `GET admin/pos/clear-cart-ids` | `CartController::clearSessionCartIds` | `none` | module:pos |
| `POST admin/pos/add-to-cart` | `CartController::addToCart` | `product.input:id` | module:pos |
| `POST admin/pos/cart-remove` | `CartController::removeCart` | `none` | module:pos |
| `GET\|POST\|PUT\|PATCH\|DELETE\|OPTIONS admin/pos/cart-empty` | `CartController::emptyCart` | `none` | module:pos |
| `GET\|POST\|PUT\|PATCH\|DELETE\|OPTIONS admin/pos/change-cart` | `CartController::changeCart` | `none` | module:pos |
| `GET admin/pos/new-cart-id` | `CartController::addNewCartId` | `none` | module:pos |
| `POST admin/pos/order-details/{id}` | `POSOrderController::index` | `order.route:id` | module:pos |
| `POST admin/pos/order-place` | `POSOrderController::placeOrder` | `cart` | module:pos |
| `GET\|POST\|PUT\|PATCH\|DELETE\|OPTIONS admin/pos/cancel-order` | `POSOrderController::cancelOrder` | `none` | module:pos |
| `GET\|POST\|PUT\|PATCH\|DELETE\|OPTIONS admin/pos/view-hold-orders` | `POSOrderController::getAllHoldOrdersView` | `none` | module:pos |

A value is an id only when it is digits without a leading zero (`StaffRouteIds`, shared with the product screens): `102abc`, `0102` and `1e2` mean the order 102 to MySQL and are
refused here. A list with one foreign id is refused whole, and nothing is touched (the bulk status change). An empty list names nothing, and the action refuses it itself. **A
route of a covered controller that has no rule is refused to a restricted employee**, and a test fails for it, so a screen added later cannot widen anybody. For everyone who is
not restricted an unknown id stays what the action always answered.

### What each screen does for a restricted employee

- **The order lists** (all, each status, the POS filter, the search, the filters) show the orders they can see, and the **counters** of the tabs count those. Opening the list marks
  as *seen* (`checked`) the orders they can see, and leaves the new-order flag of the others for whoever can see them.
- **The exports** hold those orders, and their status counts.
- **The details of an order** open when the order is visible, and its **previous and next order**, the orders of its checkout and the number of the customer's orders are the visible
  ones: the number of orders a customer has in other countries is not for them.
- **The actions** (status, bulk status, payment status, address, courier, expected date and charge, third-party delivery, return and due amounts, the digital file after sale, the
  invoice, the Delivery Syria dispatch) work on a visible order and on none other, whatever the module permission says: the invoice route has none.
- **The order edit**: the order is looked up by id, the picker offers their products, a product of another country is refused when it is viewed, priced or added, and an edit is
  saved only when every product of its edit (the session) is theirs.
- **The POS**: the grid and the search offer their products; a product of another country is refused when it is added to the cart, priced, previewed or counted (404, like an
  unknown product, and the cart is not touched); an order is **placed only when every product of the cart in use is theirs** (404 and no order, no stock taken, otherwise): a
  restricted employee cannot create an order whose lines are outside their countries. The order number counts every order, and the order is theirs afterwards.
- **The refund requests**: the lists, their exports, the details and the decision (approve, reject, refund) show and work on the requests about a line of their countries.
  These screens are not narrowed to orders: a refund about a Kuwaiti line of a mixed order is decided with the order it is about, which the employee cannot open.
- **The shared layout** of every admin page counts the orders and the refund requests by status in its sidebar and lists the latest pending orders, with their thumbnails, in its
  header. In slice 2 those read the employee's countries only on the screens where the request is bound (the product, order, POS and refund screens); slice 3 binds the layout on
  every admin page (below).

## What slice 3 enforces (the shared layout, the people, the dashboard, the reports and the header search)

Slice 3 adds no migration, no switch and no language key, and it does not change the rule of slice 2: an order follows the countries of its lines, a refund request the country of
the line it is about, and an order that cannot be attributed is hidden from every restricted employee. It extends **where** the rule is applied: to every admin page (its layout),
to the screens that list an order without managing it, and to every number that is made of orders. A restricted employee's figures are the figures an unrestricted request gives over
a shop that holds nothing but their orders (a test builds that shop and compares, for eleven screens).

### The shared layout of every admin page

The counters of the sidebar (orders and refund requests by status) and the latest pending orders of the header are read when the layout is rendered, after the screen. Until now they
were narrowed only on the screens whose controller registers the middleware of a scope. The composer of the layout now binds the request itself, once, when no middleware did
(`StaffCountryScope::narrowLayout`), so they are narrowed on **every** admin page of a restricted employee, and what the screen read in its own content is not touched (a screen
that no slice covers still shows its own data of every country, until its slice).

| Who | What the layout asks of the database beyond what it always asked |
|---|---|
| The switch is off | nothing: the same statements |
| The master admin | nothing: the role is read from the signed-in employee, and neither the schema nor the table of countries is probed (slices 1 and 2 probed twice on a covered screen) |
| An employee with no country (switch on) | the probe of the table and of the column, and the read of their rows: three small reads, once per page |
| A restricted employee | the same three reads, and the counters and the header query carry the narrowing condition |

The layout binds the request **after** the screen has run, so nothing the screen did, and nothing that fills a shared cache, is affected. The layout reads no product, so binding the
products with the orders changes nothing in it. The classic header (`layouts/admin/partials/_header.blade.php`) is not included by any layout today; both headers read the latest pending
orders through the `Order` model, and a test keeps it so.

Cost (measured once on MySQL 8, 60,000 orders, 120,000 lines, 3,000 products, `Measured once on MySQL 8.0.46 with 60,000 orders, 120,000 lines and 3,000 products (one order in twenty mixes a country with another), on a machine under a load of 7: the counters by
status took 39 ms without the scope and 553 ms for one country (9,607 of the orders are theirs), 539 ms for two and 629 ms for all six; the latest five pending orders 1 ms; a sum over the
delivered orders 11 ms against 64 ms; the sales per product over the order lines, which the dashboard and the product reports ask, 49 ms against 1,016 ms. The plan is a loose index scan
of the lines by order id (the existing index `idx_order_details_order_id_delivery_status`), a primary-key lookup of each order and, for each, an index probe of its lines and a
primary-key probe of their products. So the layout costs a restricted employee about half a second a page at this size, and the cost grows with the number of orders; nothing needs an
index or a migration to switch on, and caching the counters for a minute is the owner decision 12 below. The numbers of an unrestricted employee are the unscoped ones: the statement is
the one of today.`).

### The rows that hang off an order

A screen names, in its rule, the kinds of rows it reads. Each is a global scope of its model (the order scope and the refund scope of slice 2, given the subject and the column that
holds the key), and does nothing for a request that is not narrowed for that subject, so the screens of slice 2 ask what they always asked.

| Subject | Model | Follows |
|---|---|---|
| `orders` | `Order` | the rule of the order (every line is a product of the employee's countries) |
| `refunds` | `RefundRequest` | the country of the order line it is about |
| `order_lines` | `OrderDetail` | the order it belongs to |
| `order_transactions` | `OrderTransaction` | the order it belongs to |
| `refund_transactions` | `RefundTransaction` | the country of the order line it is about |

The rule grammar of slice 2 gains these parts: `list:order_lines`, `list:order_transactions` and `list:refund_transactions`, and **`unavailable`**: the action shows what cannot be told
apart by country, and a restricted employee is answered like for something that does not exist (a 404, with no message).

### What each screen does for a restricted employee

- **Customers, delivery men and vendors.** The list of customers, of delivery men and of vendors is not narrowed (they belong to no country), and what each counts about orders is:
  the orders per customer and per vendor, the delivered orders of a delivery man. The screen of one customer lists their orders and counts their refund requests, and its export holds
  their orders; the screens of a delivery man (the orders and earnings per order, the filtered list, the history log and its export) and of a vendor (its orders, their export and its
  money records) list the employee's orders only. An order read **by its number** outside the order screens (`vendors/order-details/{order_id}/{vendor_id}`,
  `delivery-man/ajax-order-status-history/{order}` and the two PDFs of one order's transaction and expense, `transaction/pdf-order-wise-transaction` and
  `transaction/pdf-order-wise-expense-transaction`) is answered 404, with the same body as an unknown number, when it is not the employee's (`StaffReadRoutesTest` walks the four).
- **The dashboard.** The counters by status, the earnings and the graphs, the top customers, delivery men and products, and the poller of the header (the new orders and the restock
  requests) count the employee's orders and products. The admin wallet, the top vendors by earning and the auction wallet cannot be told apart by country: they are not read for a
  restricted employee, and the admin wallet card of the view is skipped.
- **The reports.** The order report, the earning reports (and the export by vendor), the order transaction report, the expense report, the refund transaction report, the vendor sales
  report, the product, stock and wishlist reports and the in-house sales report, with their exports, read the employee's orders, lines, money records and refunds. The vendor earning
  report is made of the wallets of the vendors and is **unavailable**; the wallet bonus tab of the transaction report lists the bonuses of customers' wallets and is not narrowed.
- **The tax reports** are narrowed like the others, and the one sum over the table of orders that is not made through the model (the vendor tax report) goes through
  `StaffCountryScope::narrowRawOrders`, which applies the same rule to a query builder.
- **The search of the header** finds an order or a refund request by its number only when it is the employee's, and a product only in their countries (below).
- **The analytics screens** show visits, orders and revenue from rollups that carry no country of the shop: they are **unavailable** to a restricted employee.
- **The wallets.** The wallet cards of the earning page of a delivery man and of the page of a vendor are left out of the page; the wallet overview and the ratings of a delivery man
  (a review names its order) are unavailable.

### The search of the header

The search of the admin header reads whole tables once and keeps them in the cache every employee shares, for two days. Narrowing the query would let the first employee to search decide
what the others find, and binding the request would narrow the products that fill the cache. So the two search controllers register **no** middleware: the search narrows what it hands
back, with one statement for the hits of each kind (`App\Services\Staff\StaffSearchHits`, by the same rule as the scopes), and a test searches as a restricted employee first and as the master
admin second to prove that the cache holds every country's. The employee's countries are resolved without binding the request (`StaffCountryScope::countriesOf`).

### The rules of the screens of slice 3

Every action of these controllers has a rule, and a route of one of them without a rule is refused to a restricted employee, as in slice 2. The same middleware is registered by their
constructors, after the sign-in check and the module permission of the route (the dashboard has no module permission).

<!-- slice-3-rules-start -->
| Route | Action | Rule | Permission |
|---|---|---|---|
| `GET admin/dashboard` | `DashboardController::index` | `list:orders` + `list:order_lines` + `list:order_transactions` + `list:refunds` | none |
| `POST admin/dashboard/order-status` | `DashboardController::getOrderStatus` | `list:orders` | module:dashboard |
| `GET admin/dashboard/earning-statistics` | `DashboardController::getEarningStatistics` | `list:order_transactions` | module:dashboard |
| `GET admin/dashboard/order-statistics` | `DashboardController::getOrderStatistics` | `list:orders` | module:dashboard |
| `GET admin/dashboard/real-time-activities` | `DashboardController::getRealTimeActivities` | `list:orders` | none |
| `GET admin/analytics` | `AnalyticsController::index` | `unavailable` | module:reports |
| `GET admin/analytics/live-feed` | `AnalyticsController::live` | `unavailable` | module:reports |
| `GET admin/analytics/export/{dimension}` | `AnalyticsController::export` | `unavailable` | module:reports |
| `GET admin/analytics/campaign/{id}/qr` | `AnalyticsController::campaignQr` | `unavailable` | module:reports |
| `POST admin/analytics/campaign` | `AnalyticsController::storeCampaign` | `unavailable` | module:reports |
| `POST admin/analytics/campaign/{id}/toggle` | `AnalyticsController::toggleCampaign` | `unavailable` | module:reports |
| `GET admin/analytics/{section}` | `AnalyticsController::index` | `unavailable` | module:reports |
| `GET admin/customer/list` | `CustomerController::index` | `list:orders` | module:people |
| `GET admin/customer/view/{user_id}` | `CustomerController::getView` | `list:orders` + `list:refunds` | module:people |
| `GET admin/customer/order-list-export/{user_id}` | `CustomerController::exportOrderList` | `list:orders` | module:people |
| `POST admin/customer/status-update` | `CustomerController::updateStatus` | `none` | module:people |
| `POST admin/customer/bulk-status` | `CustomerController::bulkUpdateStatus` | `none` | module:people |
| `DELETE admin/customer/delete/{id}` | `CustomerController::deleteCustomer` | `none` | module:people |
| `GET admin/customer/subscriber-list` | `CustomerController::getSubscriberListView` | `none` | module:people |
| `GET admin/customer/subscriber-list/export` | `CustomerController::exportSubscribersList` | `none` | module:people |
| `GET admin/customer/export` | `CustomerController::exportList` | `list:orders` | module:people |
| `GET admin/customer/customer-list-search` | `CustomerController::getCustomerList` | `none` | module:people |
| `GET admin/customer/customer-list-without-all-customer` | `CustomerController::getCustomerListWithoutAllCustomerName` | `none` | module:people |
| `POST admin/customer/add` | `CustomerController::add` | `none` | module:people |
| `POST admin/customer/profile-update` | `CustomerController::updateProfile` | `none` | module:people |
| `GET admin/customer/wallet/report` | `CustomerWalletController::index` | `list:wallet_transactions` | module:people |
| `POST admin/customer/wallet/add-fund` | `CustomerWalletController::addFund` | `none` | module:people |
| `GET admin/customer/wallet/export` | `CustomerWalletController::exportList` | `list:wallet_transactions` | module:people |
| `GET admin/customer/wallet/bonus-setup` | `CustomerWalletController::getBonusSetupView` | `none` | module:people |
| `POST admin/customer/wallet/bonus-setup` | `CustomerWalletController::addBonusSetup` | `none` | module:people |
| `POST admin/customer/wallet/bonus-setup-update` | `CustomerWalletController::update` | `none` | module:people |
| `POST admin/customer/wallet/bonus-setup-status` | `CustomerWalletController::updateStatus` | `none` | module:people |
| `GET admin/customer/wallet/bonus-setup/edit/{id}` | `CustomerWalletController::getUpdateView` | `none` | module:people |
| `DELETE admin/customer/wallet/bonus-setup-delete` | `CustomerWalletController::deleteBonus` | `none` | module:people |
| `GET admin/customer/loyalty/report` | `CustomerLoyaltyController::index` | `list:loyalty_point_transactions` | module:people |
| `GET admin/customer/loyalty/export` | `CustomerLoyaltyController::exportList` | `list:loyalty_point_transactions` | module:people |
| `GET admin/report/inhouse-product-sale` | `InhouseProductSaleController::index` | `list:order_lines` | module:reports |
| `GET admin/vendors/list` | `VendorController::index` | `list:orders` | module:people |
| `GET admin/vendors/add` | `VendorController::getAddView` | `none` | module:people |
| `POST admin/vendors/add` | `VendorController::add` | `none` | module:people |
| `GET admin/vendors/order-list-export/{vendor_id}` | `VendorController::exportOrderList` | `list:orders` | module:people |
| `POST admin/vendors/status` | `VendorController::updateStatus` | `none` | module:people |
| `POST admin/vendors/bulk-status` | `VendorController::bulkUpdateStatus` | `none` | module:people |
| `GET admin/vendors/export` | `VendorController::exportList` | `list:orders` | module:people |
| `POST admin/vendors/sales-commission-update/{id}` | `VendorController::updateSalesCommission` | `none` | module:people |
| `GET admin/vendors/order-details/{order_id}/{vendor_id}` | `VendorController::getOrderDetailsView` | `order.route:order_id` + `list:orders` | module:people |
| `GET admin/vendors/view/{id}/{tab?}` | `VendorController::getView` | `list:orders` + `list:order_transactions` | module:people |
| `POST admin/vendors/update_setting/{id}` | `VendorController::updateSetting` | `none` | module:people |
| `GET admin/vendors/withdraw-list` | `VendorController::getWithdrawListView` | `none` | module:people |
| `GET admin/vendors/withdraw-list-export-excel` | `VendorController::exportWithdrawList` | `none` | module:people |
| `GET admin/vendors/withdraw-view/{withdrawId}/{vendorId}` | `VendorController::getWithdrawView` | `none` | module:people |
| `POST admin/vendors/withdraw-status/{id}` | `VendorController::withdrawStatus` | `none` | module:people |
| `POST admin/vendors/load-more-stores` | `VendorController::loadMoreStores` | `none` | module:people |
| `DELETE admin/vendors/{id}` | `VendorController::destroy` | `none` | module:people |
| `GET admin/report/transaction/refund-transaction-list` | `RefundTransactionController::index` | `list:refund_transactions` | module:reports |
| `GET admin/report/transaction/refund-transaction-export` | `RefundTransactionController::exportRefundTransaction` | `list:refund_transactions` | module:reports |
| `GET admin/report/transaction/refund-transaction-summary-pdf` | `RefundTransactionController::getRefundTransactionPDF` | `list:refund_transactions` | module:reports |
| `GET admin/report/admin-earning` | `ReportController::admin_earning` | `list:orders` + `list:order_transactions` + `list:refund_transactions` | module:reports |
| `GET admin/report/admin-earning-excel-export` | `ReportController::exportAdminEarning` | `list:orders` + `list:order_transactions` + `list:refund_transactions` | module:reports |
| `POST admin/report/admin-earning-duration-download-pdf` | `ReportController::admin_earning_duration_download_pdf` | `list:orders` + `list:order_transactions` + `list:refund_transactions` | module:reports |
| `GET admin/report/vendor-earning` | `ReportController::vendorEarning` | `unavailable` | module:reports |
| `GET admin/report/vendor-earning-excel-export` | `ReportController::exportVendorEarning` | `list:orders` + `list:order_transactions` + `list:refund_transactions` | module:reports |
| `GET\|POST\|PUT\|PATCH\|DELETE\|OPTIONS admin/report/set-date` | `ReportController::set_date` | `none` | module:reports |
| `GET admin/report/order` | `OrderReportController::order_list` | `list:orders` + `list:order_transactions` | module:reports |
| `GET admin/report/order-report-excel` | `OrderReportController::orderReportExportExcel` | `list:orders` + `list:order_transactions` | module:reports |
| `GET admin/report/order-report-pdf` | `OrderReportController::exportOrderReportInPDF` | `list:orders` + `list:order_transactions` | module:reports |
| `GET admin/report/all-product` | `ProductReportController::all_product` | `list:order_lines` | module:reports |
| `GET admin/report/all-product-excel` | `ProductReportController::allProductExportExcel` | `list:order_lines` | module:reports |
| `GET admin/report/vendor-report` | `VendorProductSaleReportController::vendorReport` | `list:orders` + `list:refund_transactions` | module:reports |
| `GET admin/report/vendor-report-export` | `VendorProductSaleReportController::exportVendorReport` | `list:orders` + `list:refund_transactions` | module:reports |
| `GET admin/transaction/order-transaction-list` | `TransactionReportController::order_transaction_list` | `list:orders` + `list:order_lines` + `list:order_transactions` | module:reports |
| `GET admin/transaction/pdf-order-wise-transaction` | `TransactionReportController::pdf_order_wise_transaction` | `order.input:order_id` + `list:orders` + `list:order_lines` + `list:order_transactions` | module:reports |
| `GET admin/transaction/order-transaction-export-excel` | `TransactionReportController::orderTransactionExportExcel` | `list:orders` + `list:order_lines` + `list:order_transactions` | module:reports |
| `GET admin/transaction/order-transaction-summary-pdf` | `TransactionReportController::order_transaction_summary_pdf` | `list:orders` + `list:order_lines` + `list:order_transactions` | module:reports |
| `GET admin/transaction/wallet-bonus` | `TransactionReportController::wallet_bonus` | `none` | module:reports |
| `GET admin/transaction/expense-transaction-list` | `ExpenseTransactionReportController::getExpenseTransactionList` | `list:orders` + `list:order_transactions` | module:reports |
| `GET admin/transaction/pdf-order-wise-expense-transaction` | `ExpenseTransactionReportController::generateOrderWiseExpenseTransactionPdf` | `order.input:id` + `list:orders` + `list:order_transactions` | module:reports |
| `GET admin/transaction/expense-transaction-export-excel` | `ExpenseTransactionReportController::expenseTransactionExportExcel` | `list:orders` + `list:order_transactions` | module:reports |
| `GET admin/transaction/expense-transaction-summary-pdf` | `ExpenseTransactionReportController::generateExpenseTransactionSummaryPDF` | `list:orders` + `list:order_transactions` | module:reports |
| `GET admin/stock/product-stock` | `ProductStockReportController::index` | `none` | module:reports |
| `GET admin/stock/product-stock-export` | `ProductStockReportController::export` | `none` | module:reports |
| `GET admin/stock/product-in-wishlist` | `ProductWishlistReportController::index` | `none` | module:reports |
| `GET admin/stock/wishlist-product-export` | `ProductWishlistReportController::export` | `none` | module:reports |
| `GET admin/delivery-man/list` | `DeliveryManController::index` | `list:orders` | module:people |
| `GET admin/delivery-man/add` | `DeliveryManController::getAddView` | `none` | module:people |
| `POST admin/delivery-man/add` | `DeliveryManController::add` | `none` | module:people |
| `POST admin/delivery-man/status-update` | `DeliveryManController::updateStatus` | `none` | module:people |
| `GET admin/delivery-man/export` | `DeliveryManController::exportList` | `list:orders` | module:people |
| `GET admin/delivery-man/update/{id}` | `DeliveryManController::getUpdateView` | `none` | module:people |
| `POST admin/delivery-man/update/{id}` | `DeliveryManController::update` | `none` | module:people |
| `DELETE admin/delivery-man/delete/{id}` | `DeliveryManController::delete` | `none` | module:people |
| `GET admin/delivery-man/earning-statement-overview/{id}` | `DeliveryManController::getEarningOverview` | `unavailable` | module:people |
| `GET admin/delivery-man/order-wise-earning/{id}` | `DeliveryManController::getOrderWiseEarningView` | `list:orders` | module:people |
| `GET admin/delivery-man/order-list-by-filer/{id}` | `DeliveryManController::getOrderWiseEarningListByFilter` | `list:orders` | module:people |
| `GET admin/delivery-man/order-history-log/{id}` | `DeliveryManController::getOrderHistoryList` | `list:orders` | module:people |
| `GET admin/delivery-man/order-history-log-export/{id}` | `DeliveryManController::getOrderHistoryListExport` | `list:orders` | module:people |
| `GET admin/delivery-man/rating/{id}` | `DeliveryManController::getRatingView` | `unavailable` | module:people |
| `GET admin/delivery-man/ajax-order-status-history/{order}` | `DeliveryManController::getOrderStatusHistory` | `order.route:order` | module:people |
| `GET admin/business-settings/customer-settings` | `CustomerController::getCustomerSettingsView` | `none` | module:business_settings |
| `POST admin/business-settings/customer-settings` | `CustomerController::updateCustomer` | `none` | module:business_settings |
| `GET admin/report/get-tax-report` | `AdminTaxReportController::getTaxReport` | `list:orders` + `list:order_transactions` | module:reports |
| `GET admin/report/get-tax-details` | `AdminTaxReportController::getTaxDetails` | `list:orders` + `list:order_transactions` | module:reports |
| `GET admin/report/tax-details-report-export` | `AdminTaxReportController::adminTaxDetailsExport` | `list:orders` + `list:order_transactions` | module:reports |
| `GET admin/report/admin-tax-report-export` | `AdminTaxReportController::adminTaxReportExport` | `list:orders` + `list:order_transactions` | module:reports |
| `GET admin/report/vendor-wise-taxes` | `VendorTaxReportController::vendorWiseTaxes` | `list:orders` + `list:order_transactions` | module:reports |
| `GET admin/report/vendor-wise-taxes-export` | `VendorTaxReportController::vendorWiseTaxExport` | `list:orders` + `list:order_transactions` | module:reports |
| `GET admin/report/vendor-tax-report` | `VendorTaxReportController::vendorTax` | `list:orders` + `list:order_transactions` | module:reports |
| `GET admin/report/vendor-tax-export` | `VendorTaxReportController::vendorTaxExport` | `list:orders` + `list:order_transactions` | module:reports |
<!-- slice-3-rules-end -->

## What slice 4 enforces (the promotion and storefront-content screens)

The flash deals, the deal of the day, the clearance sale, the most-demanded entries, the banners, the in-house shop page, the theme builder, the two SEO screens, the site map
and the collection preview. `ScopePromotionsToStaffCountries` is registered by the constructors of these eleven controllers, after the sign-in check and the module permission
of the route, and `App\Services\Staff\StaffPromotionRoutes` holds the rule of every one of their actions: a route of one of them without a rule is REFUSED to a restricted
employee, as in slices 2 and 3.

**What a promotion has no country for.** A flash deal, a banner, the clearance setup and the most-demanded section are platform rows: nothing on them says which market they
belong to, and GCC-17 has to decide that before it can. So editing a deal's name or its dates, publishing a banner or saving the clearance discount is judged by the module
permission alone, exactly as before, and this document records that rather than pretending otherwise.

**What these screens DO narrow** is the only thing on them that can be told apart by country -- the products:

1. Every picker, search and list of products holds the employee's countries' products (the product scope of slice 1, which every bound request carries).
2. Every row that BELONGS to a product is narrowed through it (`App\Models\Scopes\StaffCountryProductRowScope` on `FlashDealProduct`, `DealOfTheDay`, `MostDemanded` and
   `StockClearanceProduct`). That closes three things the product scope alone does not: the list of a deal's products, the COUNT of them that the deal list prints beside each
   deal, and a write that names its row by `product_id` rather than by its own id -- which is how the clearance screens are written, the delete of every admin clearance offer
   at once included.
3. Every action that names a product is judged by it: adding products to a deal, removing one, setting a clearance discount or a clearance status, deleting one clearance
   offer, choosing the deal of the day, and a banner form that says `resource_type=product`.
4. An edit that moves a product is judged at BOTH ends -- the row's current product and the product it is being changed to -- because either one alone would let a restricted
   employee move a foreign product in, or one of theirs out.
5. The in-house shop page also counts ORDERS, which are a different subject: its rule names `list:orders`, so the order scope of slice 2 narrows that count. One middleware
   carries both; the page is NOT also given `ScopeOrdersToStaffCountries`, because two middlewares would each read a rule out of a registry that does not hold the other's and
   the first 404 would win.

**And the one thing on these screens that HAS a market of its own: a theme.** `themes.storefront_country` exists since the `2026_12_04` migration (F-01), so unlike a
promotion the theme builder does not wait for GCC-17. Every version, section, block and asset the builder names is followed to its theme -- a block hangs off a section, a
section off a version, a version and an asset off the theme -- and the theme's market must be one the employee holds. The rule of which market a theme belongs to is
`StorefrontMarketRows`': a theme that names none is the DEFAULT storefront's, which is every theme that exists today, and before the column exists every theme is that
storefront's, which is the same answer rather than a special case. A row that is gone, or hangs off one that is, belongs to no theme and so to no market, and is refused.

**The two SEO screens and the site map.** The SEO translations screen is polymorphic: `entity_type` says what kind of row `entity_id` is, and only `product` makes it a
product id, so a category or a page override is not judged by a product it does not name. Its list of the twenty most recent overrides is narrowed through the product each
hangs off, leaving the other kinds alone. The SEO health audit reads its products with the model, so the product scope holds all of it. The SITE MAP is settled by a refusal
rather than a narrowing: the bare file lists the default storefront's product addresses and the rest is one file per live market, so there is no part of it a restricted
employee owns -- generating it rewrites every market's file and downloading it hands over another market's product addresses. Every action of that screen is `unavailable` to
them. Generating one file per storefront, for the markets an employee holds, is the storefront lane's to build.

A product out of the employee's countries is answered like a product that does not exist (a 404), for an id of the path, of the request and of a list, so that belonging to a
country is not something an employee can find out by asking. The rules of the 87 routes:

<!-- slice-4-rules-start -->
| Route | Action | Rule | Permission |
|---|---|---|---|
| `GET admin/banner/list` | `BannerController::index` | `list` | module:marketing |
| `GET admin/banner/placement-guide` | `BannerController::getPlacementGuide` | `none` | module:marketing |
| `GET admin/banner/theme-layout` | `BannerController::getThemeLayoutView` | `none` | module:marketing |
| `POST admin/banner/add` | `BannerController::add` | `banner_form` | module:marketing |
| `POST admin/banner/delete` | `BannerController::delete` | `none` | module:marketing |
| `POST admin/banner/status` | `BannerController::updateStatus` | `none` | module:marketing |
| `GET admin/banner/update/{id}` | `BannerController::getUpdateView` | `list` | module:marketing |
| `POST admin/banner/update/{id}` | `BannerController::update` | `banner_form` | module:marketing |
| `GET admin/deal/flash` | `FlashDealController::index` | `list` | module:marketing |
| `GET admin/deal/flash/add` | `FlashDealController::getAddView` | `none` | module:marketing |
| `POST admin/deal/flash` | `FlashDealController::add` | `none` | module:marketing |
| `GET admin/deal/update/{id}` | `FlashDealController::getUpdateView` | `none` | module:marketing |
| `POST admin/deal/update/{id}` | `FlashDealController::update` | `none` | module:marketing |
| `POST admin/deal/status-update` | `FlashDealController::updateStatus` | `none` | module:marketing |
| `POST admin/deal/delete-product` | `FlashDealController::delete` | `product.input:id` | module:marketing |
| `GET admin/deal/add-product/{deal_id}` | `FlashDealController::getAddProductView` | `list` | module:marketing |
| `POST admin/deal/add-product/{deal_id}` | `FlashDealController::addProduct` | `product.inputs:product_id` | module:marketing |
| `GET\|POST\|PUT\|PATCH\|DELETE\|OPTIONS admin/deal/search-product` | `FlashDealController::search` | `list` | module:marketing |
| `GET admin/deal/day` | `DealOfTheDayController::index` | `list` | module:marketing |
| `POST admin/deal/day` | `DealOfTheDayController::add` | `product.input:product_id` | module:marketing |
| `POST admin/deal/day-status-update` | `DealOfTheDayController::updateStatus` | `deal_of_the_day.input:id` | module:marketing |
| `GET admin/deal/day-update/{id}` | `DealOfTheDayController::getUpdateView` | `deal_of_the_day.route:id` | module:marketing |
| `POST admin/deal/day-update/{id}` | `DealOfTheDayController::update` | `deal_of_the_day.route:id` + `product.input:product_id` | module:marketing |
| `POST admin/deal/day-delete` | `DealOfTheDayController::delete` | `deal_of_the_day.input:id` | module:marketing |
| `GET admin/deal/clearance-sale` | `ClearanceSaleController::index` | `list` | module:marketing |
| `POST admin/deal/clearance-sale/status-update` | `ClearanceSaleController::updateStatus` | `none` | module:marketing |
| `POST admin/deal/clearance-sale/update-config` | `ClearanceSaleController::updateClearanceConfig` | `none` | module:marketing |
| `POST admin/deal/clearance-sale/update-seo-meta` | `ClearanceSaleController::updateClearanceSeoConfig` | `none` | module:marketing |
| `GET admin/deal/clearance-sale/search` | `ClearanceSaleController::getSearchedProductsView` | `list` | module:marketing |
| `GET admin/deal/clearance-sale/multiple-product-details` | `ClearanceSaleController::getMultipleProductDetailsView` | `list` | module:marketing |
| `POST admin/deal/clearance-sale/add-clearance-product` | `ClearanceSaleController::addClearanceProduct` | `product.inputs:productIds` | module:marketing |
| `POST admin/deal/clearance-sale/clearance-product-status-update` | `ClearanceSaleController::updateProductStatus` | `product.input:product_id` | module:marketing |
| `DELETE admin/deal/clearance-sale/clearance-delete/{product_id}` | `ClearanceSaleController::deleteClearanceProduct` | `product.route:product_id` | module:marketing |
| `DELETE admin/deal/clearance-sale/clearance-products-delete` | `ClearanceSaleController::deleteClearanceAllProduct` | `list` | module:marketing |
| `POST admin/deal/clearance-sale/update-discount` | `ClearanceSaleController::updateDiscountAmount` | `product.input:product_id` | module:marketing |
| `GET admin/most-demanded` | `MostDemandedController::getListView` | `none` | module:marketing |
| `POST admin/most-demanded/store` | `MostDemandedController::add` | `product.input:product_id` | module:marketing |
| `GET admin/most-demanded/update/{id}` | `MostDemandedController::getUpdateView` | `most_demanded.route:id` | module:marketing |
| `POST admin/most-demanded/update/{id}` | `MostDemandedController::update` | `most_demanded.route:id` + `product.input:product_id` | module:marketing |
| `POST admin/most-demanded/delete` | `MostDemandedController::delete` | `most_demanded.input:id` | module:marketing |
| `POST admin/most-demanded/status` | `MostDemandedController::updateStatus` | `most_demanded.input:id` | module:marketing |
| `GET admin/business-settings/inhouse-shop` | `InhouseShopController::index` | `list` + `list:orders` | module:business_settings |
| `POST admin/business-settings/inhouse-shop` | `InhouseShopController::update` | `none` | module:business_settings |
| `POST admin/business-settings/inhouse-shop-temporary-close` | `InhouseShopController::getTemporaryClose` | `none` | module:business_settings |
| `POST admin/business-settings/vacation-update` | `InhouseShopController::updateVacation` | `none` | module:business_settings |
| `GET admin/business-settings/inhouse-shop/setup` | `InhouseShopController::getSetupView` | `none` | module:business_settings |
| `POST admin/business-settings/inhouse-shop/setup` | `InhouseShopController::updateSetup` | `none` | module:business_settings |
| `GET admin/commerce/collections` | `CollectionController::index` | `none` | module:themes_and_addons |
| `POST admin/commerce/collections/store` | `CollectionController::store` | `none` | module:themes_and_addons |
| `POST admin/commerce/collections/update` | `CollectionController::update` | `none` | module:themes_and_addons |
| `POST admin/commerce/collections/delete` | `CollectionController::delete` | `none` | module:themes_and_addons |
| `GET admin/commerce/collections/preview` | `CollectionController::preview` | `list` | module:themes_and_addons |
| `GET admin/theme/builder` | `ThemeBuilderController::index` | `theme_version.input:version?` | module:themes_and_addons |
| `GET admin/theme/builder/section-schema` | `ThemeBuilderController::sectionSchema` | `theme_section.input:section_id?` | module:themes_and_addons |
| `GET admin/theme/builder/resources` | `ThemeBuilderController::resources` | `list` | module:themes_and_addons |
| `GET admin/theme/builder/resource-labels` | `ThemeBuilderController::resourceLabels` | `list` | module:themes_and_addons |
| `POST admin/theme/builder/link/compose` | `ThemeBuilderController::composeLink` | `link_form` | module:themes_and_addons |
| `POST admin/theme/builder/preview/start` | `ThemeBuilderController::startPreview` | `theme_version.input:version_id` | module:themes_and_addons |
| `POST admin/theme/builder/preview/link` | `ThemeBuilderController::previewLink` | `theme_version.input:version_id` | module:themes_and_addons |
| `GET admin/theme/builder/preview/stop` | `ThemeBuilderController::stopPreview` | `none` | module:themes_and_addons |
| `POST admin/theme/builder/section/add` | `ThemeBuilderController::addSection` | `theme_version.input:version_id` | module:themes_and_addons |
| `POST admin/theme/builder/section/update` | `ThemeBuilderController::updateSection` | `theme_section.input:section_id` | module:themes_and_addons |
| `POST admin/theme/builder/section/reorder` | `ThemeBuilderController::reorderSections` | `theme_version.input:version_id` | module:themes_and_addons |
| `POST admin/theme/builder/section/toggle` | `ThemeBuilderController::toggleSection` | `theme_section.input:section_id` | module:themes_and_addons |
| `POST admin/theme/builder/section/duplicate` | `ThemeBuilderController::duplicateSection` | `theme_section.input:section_id` | module:themes_and_addons |
| `POST admin/theme/builder/section/delete` | `ThemeBuilderController::deleteSection` | `theme_section.input:section_id` | module:themes_and_addons |
| `POST admin/theme/builder/section/delivery-rules` | `ThemeBuilderController::updateDeliveryRules` | `theme_section.input:section_id` | module:themes_and_addons |
| `GET admin/theme/builder/block-schema` | `ThemeBuilderController::blockSchema` | `theme_block.input:block_id` | module:themes_and_addons |
| `POST admin/theme/builder/block/add` | `ThemeBuilderController::addBlock` | `theme_section.input:section_id` | module:themes_and_addons |
| `POST admin/theme/builder/block/update` | `ThemeBuilderController::updateBlock` | `theme_block.input:block_id` | module:themes_and_addons |
| `POST admin/theme/builder/block/reorder` | `ThemeBuilderController::reorderBlocks` | `theme_section.input:section_id` | module:themes_and_addons |
| `POST admin/theme/builder/block/toggle` | `ThemeBuilderController::toggleBlock` | `theme_block.input:block_id` | module:themes_and_addons |
| `POST admin/theme/builder/block/duplicate` | `ThemeBuilderController::duplicateBlock` | `theme_block.input:block_id` | module:themes_and_addons |
| `POST admin/theme/builder/block/delete` | `ThemeBuilderController::deleteBlock` | `theme_block.input:block_id` | module:themes_and_addons |
| `POST admin/theme/builder/media/upload` | `ThemeBuilderController::uploadMedia` | `theme_version.input:version_id` | module:themes_and_addons |
| `POST admin/theme/builder/media/delete` | `ThemeBuilderController::deleteMedia` | `theme_asset.input:id` | module:themes_and_addons |
| `GET admin/theme/builder/media/library` | `ThemeBuilderController::mediaLibrary` | `theme_version.input:version_id` | module:themes_and_addons |
| `GET admin/seo-settings/translations` | `SeoTranslationController::index` | `seo_entity:entity_type,entity_id` + `list` | module:business_settings |
| `POST admin/seo-settings/translations/save` | `SeoTranslationController::save` | `seo_entity:entity_type,entity_id` | module:business_settings |
| `GET admin/seo-settings/translations/delete` | `SeoTranslationController::delete` | `seo_entity:entity_type,entity_id` | module:business_settings |
| `GET admin/seo-settings/health` | `SeoHealthController::index` | `list` | module:business_settings |
| `GET admin/seo-settings/sitemap` | `SiteMapController::index` | `unavailable` | module:business_settings |
| `GET admin/seo-settings/sitemap-generate-download` | `SiteMapController::getGenerateAndDownload` | `unavailable` | module:business_settings |
| `GET admin/seo-settings/sitemap-generate-upload` | `SiteMapController::getGenerateAndUpload` | `unavailable` | module:business_settings |
| `POST admin/seo-settings/sitemap-manual-upload` | `SiteMapController::getUpload` | `unavailable` | module:business_settings |
| `GET admin/seo-settings/sitemap-download` | `SiteMapController::getDownload` | `unavailable` | module:business_settings |
| `GET admin/seo-settings/sitemap-delete` | `SiteMapController::getDelete` | `unavailable` | module:business_settings |
<!-- slice-4-rules-end -->

## What slice 5 enforces (the marketplace screens and the reviews)

The warehouses, the inventory adjustments, the batches and the purchase orders -- the four screens that MOVE stock or buy it -- and then the
rest of the slice, which is made of ORDERS rather than of products: the reviews, the returns, the fulfilments, the seller scorecard, the
settlements, the brand registry and the commission rules -- and the ledger PAYOUTS, which came inside the regime with the money switch of
G-14's second criterion. `ScopeMarketplaceToStaffCountries` is registered by their constructors, after the sign-in check and the module
permission, and `App\Services\Staff\StaffMarketplaceRoutes` holds the rule of each of their 60 actions; a route of one of them without a rule
is REFUSED to a restricted employee.

**These are writes, which is why they come before the rest of slice 5.** A restricted employee could place another market's product on a
shelf, adjust its balance, write off its batch and receive a purchase order of it -- and every one of those changes a figure the other market
sells against. The listing is the lesser half here.

1. Every action that names a product is judged by it: placing stock, transferring it between shelves, adjusting a balance, adding a batch.
2. A BATCH is followed to the product it is a batch of, so writing one off is judged by that product and not by the batch's own id.
3. A PURCHASE ORDER is judged by EVERY line's product, the rule an order follows (`StaffOrderVisibility`) and for the same reason: an order
   can buy two markets' goods, so only an employee who holds both may open, place, receive or cancel it. One that buys nothing from the
   catalogue -- every line a description -- belongs to no market and is refused rather than guessed at. Creating one is judged by every line
   that names a catalogue product, and a line that names none is not a refusal, because a purchase order may buy a service or a pallet.
4. The rows that belong to a product are narrowed through it (`StaffCountryProductRowScope` on `StockMovement`, `ProductBatch`,
   `WarehouseStock` and `PurchaseOrderItem`), so the movement history, the batch list, the per-shelf breakdown and a purchase order's lines
   hold only the employee's products.

5. The rest of the slice follows the ORDER rather than the product, which is slice 2's rule. A REVIEW is the exception and is followed to
   its PRODUCT, because that is the one key a review always has -- a review may name no order -- and the market of the reviewed product is
   the market of the review. A RETURN names a product, an order, a line or several of those, and is judged by whichever it names; a
   FULFILMENT ships one order and is judged by it. Both queues are narrowed through that order (`StaffCountryOrderScope` on
   `ReturnShipment` and `OrderFulfillment`), and only where the screen ASKED for it (the subjects `returns` and `fulfillments`), so the
   service that opens a fulfilment still sees the ones an order already has, whatever market they are in -- or it would open a second one.
6. A COMMISSION RULE is narrowed the way a SEO override is, through a polymorphic pair: `scope_id` is read as a product id only where
   `scope_type` says `product`. A global, category or vendor rule names no product, so it stays on the list -- it has no market to be
   hidden by -- and every write to it is refused to a restricted employee, because it changes what the sellers of every market are charged.

**Three screens of slice 5 are settled by a REFUSAL rather than a narrowing, and the reason is written down for each.** A restricted
employee is answered like for a page that does not exist (`unavailable`, the vocabulary slices 3 and 4 use for the vendor earning report,
the analytics and the site map):

- the SELLER SCORECARD counts a seller's orders, refunds, reviews and strikes through `SellerScorecardService`, which reads them with the
  QUERY BUILDER so no scope reaches them, and the tier it derives is a figure about the seller on the whole platform. Which market's orders
  count towards a seller's tier is a question nobody has answered;
- a SETTLEMENT is a seller's money across every market: the vendor ledger is keyed by seller, currency and legal entity and not by market
  (E-17.2 ships off), so a settlement cannot be split by country at all. The two platform controls on that page -- the approver-is-not-the-
  payer separation of duties and the payment terms the marketplace promises its sellers -- change what happens in every market;
- a BRAND decision crosses every market by construction: approving an ownership claim says who may sell under that brand everywhere, and
  the exposure counts beside it are platform totals read with the query builder.

**What slice 5 does NOT reach: a warehouse has no market.** `inventory_locations` gained one (`2026_12_27_100000`, G1-05) and the
marketplace's own `warehouses` table did not, so what can be told apart on these screens is the product whose units are moving, never the
shelf. A restricted employee still SEES every warehouse on the list, with its code and its name; giving that table a market belongs with the
inventory lane. Nor does it reach the vendor panel, the seller API staff or a legal-entity scope, which M2-03 names and which are not slice
5's to build.

<!-- slice-5-rules-start -->
| Route | Action | Rule | Permission |
|---|---|---|---|
| `GET admin/marketplace/purchase-orders` | `PurchaseOrderController::index` | `list` | module:marketplace |
| `GET admin/marketplace/purchase-orders/create` | `PurchaseOrderController::create` | `none` | module:marketplace |
| `POST admin/marketplace/purchase-orders` | `PurchaseOrderController::store` | `product.items:items` | module:marketplace |
| `GET admin/marketplace/purchase-orders/{id}` | `PurchaseOrderController::show` | `purchase_order.route:id` + `list` | module:marketplace |
| `POST admin/marketplace/purchase-orders/{id}/place` | `PurchaseOrderController::place` | `purchase_order.route:id` | module:marketplace |
| `POST admin/marketplace/purchase-orders/{id}/receive` | `PurchaseOrderController::receive` | `purchase_order.route:id` | module:marketplace |
| `POST admin/marketplace/purchase-orders/{id}/cancel` | `PurchaseOrderController::cancel` | `purchase_order.route:id` | module:marketplace |
| `GET admin/marketplace/inventory-adjustments` | `InventoryAdjustmentController::index` | `product.input:product_id?` + `list` | module:marketplace |
| `POST admin/marketplace/inventory-adjustments/adjust` | `InventoryAdjustmentController::adjust` | `product.input:product_id` | module:marketplace |
| `GET admin/marketplace/batches` | `BatchController::index` | `product.input:product_id?` + `list` | module:marketplace |
| `POST admin/marketplace/batches` | `BatchController::store` | `product.input:product_id` | module:marketplace |
| `POST admin/marketplace/batches/{id}/write-off` | `BatchController::writeOff` | `batch.route:id` | module:marketplace |
| `GET admin/marketplace/warehouses` | `WarehouseController::index` | `product.input:product_id?` + `list` | module:marketplace |
| `POST admin/marketplace/warehouses` | `WarehouseController::store` | `none` | module:marketplace |
| `PUT admin/marketplace/warehouses/{id}` | `WarehouseController::update` | `none` | module:marketplace |
| `POST admin/marketplace/warehouses/place` | `WarehouseController::place` | `product.input:product_id` | module:marketplace |
| `POST admin/marketplace/warehouses/transfer` | `WarehouseController::transfer` | `product.input:product_id` | module:marketplace |
| `POST admin/marketplace/settlements/terms` | `SettlementController::updateTerms` | `unavailable` | module:marketplace |
| `GET admin/marketplace/settlements` | `SettlementController::index` | `unavailable` | module:marketplace |
| `POST admin/marketplace/settlements/calculate` | `SettlementController::calculate` | `unavailable` | module:marketplace |
| `POST admin/marketplace/settlements/toggle-maker-checker` | `SettlementController::toggleMakerChecker` | `unavailable` | module:marketplace |
| `GET admin/marketplace/settlements/{id}` | `SettlementController::show` | `unavailable` | module:marketplace |
| `POST admin/marketplace/settlements/{id}/approve` | `SettlementController::approve` | `unavailable` | module:marketplace |
| `POST admin/marketplace/settlements/{id}/mark-paid` | `SettlementController::markPaid` | `unavailable` | module:marketplace |
| `POST admin/marketplace/settlements/{id}/cancel` | `SettlementController::cancel` | `unavailable` | module:marketplace |
| `GET admin/marketplace/ledger/{sellerId}` | `SettlementController::ledger` | `unavailable` | module:marketplace |
| `GET admin/marketplace/brand-registry` | `BrandRegistryController::index` | `unavailable` | module:marketplace |
| `GET admin/marketplace/brand-registry/document/{id}` | `BrandRegistryController::document` | `unavailable` | module:marketplace |
| `POST admin/marketplace/brand-registry/approve` | `BrandRegistryController::approve` | `unavailable` | module:marketplace |
| `POST admin/marketplace/brand-registry/reject` | `BrandRegistryController::reject` | `unavailable` | module:marketplace |
| `POST admin/marketplace/brand-registry/revoke` | `BrandRegistryController::revoke` | `unavailable` | module:marketplace |
| `POST admin/marketplace/brand-registry/enforcement` | `BrandRegistryController::updateEnforcement` | `unavailable` | module:marketplace |
| `GET admin/marketplace/seller-scorecard` | `SellerScorecardController::index` | `unavailable` | module:marketplace |
| `GET admin/marketplace/payouts` | `PayoutController::index` | `none` | module:marketplace |
| `POST admin/marketplace/payouts/{id}/approve` | `PayoutController::approve` | `none` | module:marketplace |
| `POST admin/marketplace/payouts/{id}/mark-paid` | `PayoutController::markPaid` | `none` | module:marketplace |
| `POST admin/marketplace/payouts/{id}/mark-failed` | `PayoutController::markFailed` | `none` | module:marketplace |
| `POST admin/marketplace/payouts/{id}/reissue` | `PayoutController::reissue` | `none` | module:marketplace |
| `POST admin/marketplace/payouts/{id}/reject` | `PayoutController::reject` | `none` | module:marketplace |
| `GET admin/marketplace/returns` | `ReturnLogisticsController::index` | `list:returns` + `list` | module:marketplace |
| `POST admin/marketplace/returns` | `ReturnLogisticsController::store` | `return.posted` | module:marketplace |
| `POST admin/marketplace/returns/{id}/transit` | `ReturnLogisticsController::transit` | `return.route:id` | module:marketplace |
| `POST admin/marketplace/returns/{id}/receive` | `ReturnLogisticsController::receive` | `return.route:id` | module:marketplace |
| `POST admin/marketplace/returns/{id}/reject` | `ReturnLogisticsController::reject` | `return.route:id` | module:marketplace |
| `GET admin/marketplace/fulfillments` | `FulfillmentController::index` | `list:fulfillments` + `order.input:order_id?` | module:marketplace |
| `POST admin/marketplace/fulfillments` | `FulfillmentController::store` | `order.input:order_id` | module:marketplace |
| `POST admin/marketplace/fulfillments/{id}/advance` | `FulfillmentController::advance` | `fulfillment.route:id` | module:marketplace |
| `POST admin/marketplace/fulfillments/{id}/cancel` | `FulfillmentController::cancel` | `fulfillment.route:id` | module:marketplace |
| `GET admin/marketplace/commission-rules` | `CommissionRuleController::index` | `product.input:preview_product_id?` + `list` | module:marketplace |
| `POST admin/marketplace/commission-rules` | `CommissionRuleController::store` | `commission_rule.posted` | module:marketplace |
| `POST admin/marketplace/commission-rules/{id}/toggle` | `CommissionRuleController::toggle` | `commission_rule.route:id` | module:marketplace |
| `PUT admin/marketplace/commission-rules/{id}` | `CommissionRuleController::update` | `commission_rule.route:id` + `commission_rule.posted` | module:marketplace |
| `DELETE admin/marketplace/commission-rules/{id}` | `CommissionRuleController::destroy` | `commission_rule.route:id` | module:marketplace |
| `GET admin/reviews/list` | `ReviewController::index` | `product.input:product_id?` + `list` | module:people |
| `POST admin/reviews/status` | `ReviewController::updateStatus` | `review.input:id` | module:people |
| `GET admin/reviews/export` | `ReviewController::exportList` | `product.input:product_id?` + `list` | module:people |
| `GET admin/reviews/customer-list-search` | `ReviewController::getCustomerList` | `none` | module:people |
| `GET\|POST\|PUT\|PATCH\|DELETE\|OPTIONS admin/reviews/search-product` | `ReviewController::search` | `none` | module:people |
| `POST admin/reviews/add-review-reply` | `ReviewController::addReviewReply` | `review.input:review_id` | module:people |
| `GET\|POST\|PUT\|PATCH\|DELETE\|OPTIONS admin/reviews/search-vendor` | `ReviewController::searchVendor` | `list` | module:people |
<!-- slice-5-rules-end -->

## What slice 6 enforces (the integrations and the operational costs)

The sales channels, the fulfilment providers, the payment-gateway monitor and the operational costs.
`ScopeIntegrationsToStaffCountries` is registered by the constructors of all fourteen controllers, after the sign-in check and the module
permission of the route, and two registries hold the rules. `App\Services\Staff\StaffIntegrationRoutes` holds
the rule of each of their 68 actions, and `App\Services\Staff\StaffCostRoutes` holds the rule of each of their 10 cost actions.
A route of one of them without a rule is
REFUSED to a restricted employee, so an action added to one of these screens is unreachable to such an employee until somebody decides what
market it belongs to.

**One middleware for both registries**, for the reason slice 4 gives: two would each read a rule out of a registry that does not hold the
other's and refuse the route the other covers.

**What made this slice wait, and what the enabling change was.** These are the first screens on the project to use ROUTE MODEL BINDING
(`SalesChannelAccount $account`, `ChannelListingDraft $draft`, `InventoryLocation $location`, `FulfillmentRoutingRule $rule`), and
`SubstituteBindings` runs in the `web` group BEFORE a controller's own middleware -- so `$request->route('account')` hands back the MODEL and
not the digits, and `StaffRouteIds::one()` answered null for it. Reading a bound model's key is the whole of the enabling change; without it
the guard would have refused every one of these screens to every restricted employee while looking like a working guard.

**Which thing carries the market is not the same on every screen, and the product is the wrong answer on the ones that look most like it.**

1. A CHANNEL LISTING belongs to its ACCOUNT. `sales_channel_product_mappings.product_id` is nullable and is null for exactly the rows the
   screen exists for -- an unlinked listing the channel sent us, waiting for somebody to say which of our products it is -- so a product rule
   would hide the whole job from every restricted employee. An account sells in one market (`ChannelAccountCountry`: Trendyol names its
   storefront, noon its country, an Amazon account its marketplace ids), and one whose settings name NO country belongs to no market and is
   refused, which is the answer an order with no line gets.
2. LINKING a listing to a product is judged by BOTH -- the listing's account and the posted product. That is the security property of that
   screen: a Kuwait employee must not be able to attach a Saudi product to a Kuwait channel listing and start publishing its price and stock.
3. A CHANNEL ORDER (`external_order_mappings`) belongs to its account too: its `order_id` stays null until we take responsibility for
   fulfilling it. A LINE of one (`external_order_lines`) reaches its account through the mapping, which is how the cost return queue is
   narrowed.
4. A LISTING DRAFT (`channel_listing_drafts.product_id`, not nullable) is one of OUR products going out, so it follows the product.
5. A PROVIDER SHIPMENT (`provider_fulfillments.order_id`) follows the order it carries, as slice 5's own fulfilments do, through the new
   subject `provider_fulfillments` -- narrowed only where a screen asked for it, so an action that CHANGES one still runs on the real data.
6. A WAREHOUSE is judged by its own market (`inventory_locations.storefront_country`), which is what
   `StaffCountryScope::coversEveryInventoryLocation()` was built for in slice 5 and had no caller for until here. Its LIST is narrowed by
   `StaffCountryMarketRowScope`, the rule the shipping scope already applied, under a name that is not about shipping.
7. A COST LAYER is the purchase price of one product in one warehouse, so it follows the product -- the mirror of a channel listing, which is
   the sale price of one, and the reason the integrations and the costs are one slice. RECORDING a cost is judged by the product AND the
   warehouse: writing a purchase cost onto another market's product changes the margin that market sells on.
8. A ROUTING RULE is judged by the product or the country it names. One that names NEITHER decides where every market's parcels go, so it
   stays on the list -- it has no market to be hidden by -- and every write of it is refused. That is the rule a global commission rule
   follows.
9. A PROFIT page of one sale names an order of ours or a channel order and is judged by whichever it names.

**Five things are settled by a REFUSAL (`unavailable`, or the market of the unmarked rows), and each is a fact about the data rather than a
preference:**

- the M5AZN catalogue and the TOROD point-of-sale matching are one provider's own catalogue with no market column at all, and what they
  import or match becomes a product of the DEFAULT storefront; M5azn's screen also joins `products` with the query builder, so no scope
  reaches it. They belong to the default market and to no other (`default_market`), which is a refusal for every employee who does not hold
  it rather than a narrowing. Torod is declared SA:SA until Q-33 is answered;
- the PAYMENT-GATEWAY MONITOR (MyFatoorah) lists payment ATTEMPTS, and an attempt names a `payment_request_id` and not an order -- the order
  does not exist yet when the attempt is made. So no row of it can be told apart by market, while the actions on that screen REFUND money and
  re-run webhooks. Every action is refused rather than letting an employee of one market take money back on another market's payment;
- the COST OVERVIEW and the PROFIT LIST with its export are the same figures at two sizes, read by `SaleDocumentReader` with the query
  builder, and the rollup has no market dimension at all. Narrowing the page while the export of the same page hands over every market is the
  shape G-10 exists to stop, so both are refused together until the rollup has a market;
- the PROVIDER API LOGS are the provider's traffic for the whole platform.

**What this slice does NOT cover**, and is written here rather than implied: a sales-channel ACCOUNT still derives its market from its own
settings rather than holding one (H2-04 asks for an explicit column and a validation, which is a different lane); the stock POOLS read their
market through the product, not through a key of their own; and the `legal_entity_id` of a warehouse is written by nothing.

## The survey: every admin route that reads products or names one

`tests/Feature/StaffScope/StaffProductRouteInventoryTest.php` walks the real route table of the admin panel and fails for an admin controller that reads products or names
one and is neither scoped nor listed in `tests/Support/StaffProductInventory.php` with a reason (and, when it is out of slice 3, the slice that takes it). The table is
exact: an entry whose controller no longer reads products fails, so does an entry without a reason, every route of a scoped controller must have a rule in the registry of its
slice, and the scoped controllers are exactly the ones that have rules. A second test keeps this document in step with the table and the routes. The scan reads the source of
each action, its path and its parameters, and the file of each controller (so a route that delegates to a helper of its controller is not missed): the repository, the model, the
product manager, the products table, and a product id in a request.

**50 controllers: 3 scoped in slice 1, 5 scoped in slice 2, 9 scoped in slice 3, 10 scoped in slice 4, 8 scoped in slice 5, 9 scoped in slice 6, 6 not needed, 0 out of slice 6.**
**158 routes read or name a product: 47 scoped in slice 1, 16 scoped in slice 2, 13 scoped in slice 3, 22 scoped in slice 4, 13 scoped in slice 5, 25 scoped in slice 6, 22 not needed, 0 out of slice 6.**

The slices that follow slice 3 (the people, the dashboard, the reports and the shared layout), in the order proposed:

| Slice | What it takes |
|---|---|
| slice 4 | promotions and storefront content: deals, banners, themes, the SEO pages and the site map |
| slice 5 | vendors, reviews and the marketplace operations |
| slice 6 | integrations and costs: sales channels, fulfilment providers and the operational costs |

| Controller | Admin routes | Routes that read or name a product | Status | Why |
|---|---:|---:|---|---|
| `Admin\Product\ProductController` | 41 | 41 | scoped in slice 1 | The product screens: lists, counters, pickers, exports, the add and edit forms, and every action on a product. Every action has a rule in StaffProductRoutes. |
| `Admin\Marketplace\ProductModerationController` | 4 | 4 | scoped in slice 1 | Approve, reject or suspend a seller product by its id, singly or in bulk, and read its history: an id route like the product screens. |
| `Admin\Shipping\ProductDestinationPermissionController` | 2 | 2 | scoped in slice 1 | Which countries one product may be shipped to, and on whose authority (H1-03, H1-04). Reads the product by the id in the request and writes or withdraws its permissions, so both actions are judged by that id: granting another country's product permission to leave it is not a restricted employee's to do. |
| `Admin\Product\BrandController` | 9 | 1 | not needed | Reads the products of a brand only to refuse deleting a brand that is in use. The answer must count every country's products, or a brand a product of another country uses could be deleted. |
| `Admin\Product\CategoryController` | 7 | 2 | not needed | As the brands: the products of a category are read only to refuse deleting or moving a category that is in use, and that count must include every country. |
| `Admin\Settings\BusinessSettingsController` | 15 | 2 | not needed | The product settings page is a setting of the shop (brands, digital products, approval). It reads no product row. |
| `Modules\AI\Admin\AIProductController` | 8 | 8 | not needed | Writes a title, a description or the SEO text of a product from what is typed in the form. It reads no product row. |
| `Modules\AI\API\V3\AIAuctionProductController` | 8 | 8 | not needed | As the product text generation, for the auction add-on, whose products are not in the products table. |
| `Admin\SalesChannels\ChannelAccountOperationsController` | 7 | 1 | not needed | The only route that mentions a product is the Noon product group of a channel account, a setting of the account. It reads no product row. |
| `Admin\Settings\SeoTranslationController` | 3 | 0 | scoped in slice 4 | Lists the products whose SEO text can be translated. Narrowed in slice 4: the screen is polymorphic, so `entity_id` is judged as a product id only when `entity_type` says `product`, and the twenty most recent overrides are narrowed through the product each hangs off (StaffCountryProductRowScope on SeoMetaTranslation, which leaves the overrides of a category, a brand, a shop or a page alone -- they have no market). |
| `Admin\Promotion\FlashDealController` | 10 | 4 | scoped in slice 4 | The deal pages pick products and list the ones in a deal. Narrowed in slice 4: the pickers and the search list the employee's countries' products, a deal's product list and the count the deal list prints beside each deal hold only theirs (StaffCountryProductRowScope on FlashDealProduct), and adding or removing a product is judged by its id. The DEAL row itself still has no country (GCC-17), so its name, dates and status are the module permission's business. |
| `Admin\Promotion\DealOfTheDayController` | 6 | 4 | scoped in slice 4 | Picks the product of the day. Narrowed in slice 4: the picker lists the employee's countries' products, the deal rows are narrowed through the product each names (StaffCountryProductRowScope), and an edit is judged at both ends -- the row's product and the product it is being changed to. |
| `Admin\Promotion\ClearanceSaleController` | 11 | 8 | scoped in slice 4 | Picks, lists and removes clearance products. Narrowed in slice 4: every picker, search and list holds the employee's countries' products, and because these screens name their rows by `product_id` rather than by the row's own id -- the delete of every admin offer at once included -- what keeps the writes inside their countries is StaffCountryProductRowScope on the offers. The clearance SETUP and its SEO text are platform settings and stay so. |
| `Admin\Promotion\MostDemandedController` | 6 | 0 | scoped in slice 4 | The most-demanded screens were retired with the fashion theme and redirect to the dashboard; the writes still name a product, and in slice 4 they are judged by it at both ends, the row and the posted product. |
| `Admin\Promotion\BannerController` | 8 | 2 | scoped in slice 4 | A banner can point at a product. Narrowed in slice 4: the product picker of the list and of the edit form holds the employee's countries' products, and a form that says `resource_type=product` is judged by the product id it posts. The BANNER row has no country (GCC-17), so publishing or deleting one is the module permission's business. |
| `Admin\Settings\InhouseShopController` | 6 | 1 | scoped in slice 4 | The in-house shop page counts the in-house products, the reviews of those products and the in-house ORDERS. Narrowed in slice 4: the product and review counts by the product scope, the order count by the order subject the rule names (`list:orders`). |
| `Admin\Settings\ThemeBuilderController` | 25 | 2 | scoped in slice 4 | The pickers of the theme builder list and name products. Narrowed in slice 4 twice over: the pickers hold the employee's countries' products, and every version, section, block and asset the builder names is judged by the MARKET of the theme it belongs to -- a theme has one of its own (`themes.storefront_country`, F-01), so unlike a promotion it does not wait for GCC-17. |
| `Admin\Settings\SeoHealthController` | 1 | 1 | scoped in slice 4 | Audits the SEO of the products a storefront shows. Narrowed in slice 4: it reads them with the model, so the product scope holds the whole audit. |
| `Admin\Settings\SiteMapController` | 6 | 0 | scoped in slice 4 | The site map lists the active products of every country, and generating it rewrites every market's file. Settled in slice 4 by a REFUSAL, not a narrowing: a site map is a platform-wide artefact with no part a restricted employee owns, so every action of the screen is `unavailable` to them. Generating one file per storefront for the markets an employee holds is the storefront lane's to build. |
| `Admin\Order\OrderController` | 18 | 1 | scoped in slice 2 | Every order screen: the lists per status with their filters and counters, the exports, the details with the neighbours of an order, and every action on an order (status, payment, address, courier, invoice, bulk change). Every action has a rule in StaffOrderRoutes. |
| `Admin\Order\OrderEditController` | 7 | 6 | scoped in slice 2 | The order edit: the product picker is narrowed by the product scope, the order is looked up by id and a product of another country is refused. Every action has a rule in StaffOrderRoutes. |
| `Admin\POS\POSController` | 6 | 5 | scoped in slice 2 | The POS grid and search offer the products of the employee's countries (the product scope), and a product of another country is refused by id. Every action has a rule in StaffOrderRoutes. |
| `Admin\POS\CartController` | 9 | 3 | scoped in slice 2 | The POS cart takes, prices and counts a product by id: a product of another country is refused. Every action has a rule in StaffOrderRoutes. |
| `Admin\POS\POSOrderController` | 4 | 1 | scoped in slice 2 | Places a POS order only when every product of the cart is the employee's, and reads a POS order by id. Every action has a rule in StaffOrderRoutes. |
| `Admin\DashboardController` | 5 | 2 | scoped in slice 3 | The counters of products, stock and restock requests, and the top lists of products, narrowed to the employee's countries (the request is bound). Every action has a rule in StaffOrderRoutes. |
| `Admin\ReportController` | 6 | 1 | scoped in slice 3 | The earning and product counts of the reports are narrowed to the employee's countries. Every action has a rule in StaffOrderRoutes. |
| `Admin\ProductReportController` | 2 | 2 | scoped in slice 3 | The all-products report and its export list the products of the employee's countries. Every action has a rule in StaffOrderRoutes. |
| `Admin\ProductStockReportController` | 2 | 2 | scoped in slice 3 | The stock report and its export list the products of the employee's countries. Every action has a rule in StaffOrderRoutes. |
| `Admin\ProductWishlistReportController` | 2 | 2 | scoped in slice 3 | The wishlist report and its export list the products of the employee's countries. Every action has a rule in StaffOrderRoutes. |
| `Admin\InhouseProductSaleController` | 1 | 1 | scoped in slice 3 | The in-house sales report lists the in-house products of the employee's countries. Every action has a rule in StaffOrderRoutes. |
| `Admin\TransactionReportController` | 5 | 1 | scoped in slice 3 | The transaction report counts the products of the employee's countries. Every action has a rule in StaffOrderRoutes. |
| `Admin\VendorProductSaleReportController` | 2 | 1 | scoped in slice 3 | The vendor sales report lists the products of the employee's countries. Every action has a rule in StaffOrderRoutes. |
| `Admin\Product\ReviewController` | 7 | 3 | scoped in slice 5 | The reviews of the catalogue. Narrowed in slice 5 through the product each review is about (StaffCountryProductRowScope on Review): the list, its export and the per-shop averages of the vendor picker hold one market, and publishing or replying to a review is judged by the product of the review the form names. Every action has a rule in StaffMarketplaceRoutes. |
| `Admin\Vendor\VendorController` | 17 | 1 | scoped in slice 3 | The request is bound for the whole controller (it lists orders), so a vendor's product tab lists the products of the employee's countries only. Every action has a rule in StaffOrderRoutes. |
| `Admin\Marketplace\BatchController` | 3 | 2 | scoped in slice 5 | Batches of a marketplace product. Narrowed in slice 5: adding one is judged by the product id it posts, writing one off is judged by the product the BATCH is of, and the list is narrowed through that product (StaffCountryProductRowScope on ProductBatch). |
| `Admin\Marketplace\BrandRegistryController` | 6 | 0 | scoped in slice 5 | Settled in slice 5 by a REFUSAL rather than a narrowing, and the difference is written out: a brand decision crosses every market by construction (approving a claim says who may sell under that brand everywhere) and the exposure counts beside it are platform totals read with the query builder. Every action is `unavailable` in StaffMarketplaceRoutes, so a restricted employee is answered like for a page that does not exist. |
| `Admin\Marketplace\CommissionRuleController` | 5 | 1 | scoped in slice 5 | A commission rule can name a product. Narrowed in slice 5 the way a SEO override is, through the polymorphic pair (StaffCountryProductRowScope on scope_id where scope_type is product): a restricted employee lists their own countries' product rules, and writing one is judged by the product it names. A global, category or vendor rule names no product, so it is left on the list -- it has no market to be hidden by -- and is refused to that employee for every write, because it changes what the sellers of every market are charged. Every action has a rule in StaffMarketplaceRoutes. |
| `Admin\Marketplace\InventoryAdjustmentController` | 2 | 2 | scoped in slice 5 | Adjusts the stock of a product by id. Narrowed in slice 5: the adjustment is judged by the product id it posts, and the movement history the screen lists is narrowed through the product of each movement (StaffCountryProductRowScope on StockMovement). |
| `Admin\Marketplace\PurchaseOrderController` | 7 | 1 | scoped in slice 5 | A purchase order line names a product. Narrowed in slice 5: creating one is judged by every line that names a catalogue product, and opening, placing, receiving or cancelling one is judged by EVERY line's product -- the rule an order follows, because a purchase order can buy two markets' goods. One that buys nothing from the catalogue belongs to no market and is refused rather than guessed at. |
| `Admin\Marketplace\ReturnLogisticsController` | 5 | 1 | scoped in slice 5 | A return names a product, an order, a line, or several of those. Narrowed in slice 5 by whichever it names -- the product directly, or every product of the order or line it points at -- and the queue is narrowed through the order each return is of (StaffCountryOrderScope on ReturnShipment, subject `returns`). A return that names neither a product nor an order belongs to no market and fails closed. Every action has a rule in StaffMarketplaceRoutes. |
| `Admin\Marketplace\WarehouseController` | 5 | 3 | scoped in slice 5 | Places and transfers the stock of a product by id. Narrowed in slice 5: both writes are judged by the product id, and the per-shelf breakdown is narrowed through the product of each balance (StaffCountryProductRowScope on WarehouseStock). The WAREHOUSE itself carries no market -- inventory_locations gained one and the marketplace's own warehouses table did not -- so a restricted employee still sees every shelf on the list, which the survey says rather than implies. |
| `Admin\Fulfillment\M5aznProductsController` | 3 | 3 | scoped in slice 6 | M5azn's own catalogue has no market column, and what it imports becomes a product of the DEFAULT storefront -- while its rows join products with the QUERY BUILDER, so no scope reaches them. Settled in slice 6 by the market the unmarked rows belong to: `default_market` in StaffIntegrationRoutes, so only an employee who holds the default storefront's market reaches the screen. |
| `Admin\Fulfillment\TorodMatchingController` | 13 | 4 | scoped in slice 6 | The Torod point-of-sale catalogue and its city decisions are the default storefront's, and the provider is declared SA:SA until Q-33 is answered. Settled in slice 6 the same way as M5azn's: `default_market` for all thirteen actions. |
| `Admin\SalesChannels\ChannelProductController` | 8 | 8 | scoped in slice 6 | A channel listing belongs to the market its ACCOUNT sells in, not to the product it points at -- product_id is NULL for exactly the rows this screen exists for. Narrowed in slice 6 by the account (StaffCountryChannelAccountScope), and LINKING one is judged by the listing's account AND the posted product. Every action has a rule in StaffIntegrationRoutes. |
| `Admin\SalesChannels\ChannelListingController` | 9 | 2 | scoped in slice 6 | One of OUR products going out to a channel: judged by the product at the start and by the draft's product from then on (StaffCountryProductRowScope on ChannelListingDraft). Every action has a rule in StaffIntegrationRoutes. |
| `Admin\SalesChannels\ChannelInventoryController` | 2 | 1 | scoped in slice 6 | The stock a channel holds, by warehouse. Narrowed in slice 6 by the market of the WAREHOUSE (inventory_locations.storefront_country, StaffCountryMarketRowScope and StaffCountryScope::coversEveryInventoryLocation), which is the first caller that question ever had. Every action has a rule in StaffIntegrationRoutes. |
| `Admin\SalesChannels\ChannelOrderController` | 7 | 1 | scoped in slice 6 | A channel order belongs to its ACCOUNT: order_id stays NULL until we take responsibility for fulfilling it. Narrowed in slice 6 by the account. Every action has a rule in StaffIntegrationRoutes. |
| `Admin\SalesChannels\ChannelRoutingController` | 4 | 1 | scoped in slice 6 | A routing rule is judged by the product or the country it names; one that names neither decides where every market's parcels go, so it stays on the list and every write of it is refused -- the rule a global commission rule follows. Every action has a rule in StaffIntegrationRoutes. |
| `Admin\OperationalCosts\ProductCostController` | 4 | 4 | scoped in slice 6 | A cost layer is what one product cost us in one warehouse, so it follows the product (StaffCountryProductRowScope on InventoryCostLayer), and recording one is judged by the product AND the warehouse. Every action has a rule in StaffCostRoutes. |
| `Admin\OperationalCosts\ReturnController` | 2 | 1 | scoped in slice 6 | A return decision is about a line of a channel order, so it follows that order's account (StaffCountryChannelAccountScope through external_order_mappings). Every action has a rule in StaffCostRoutes. |

### Routes that read or name a product, of the controllers that are not needed

| Controller | Routes that read or name a product |
|---|---|
| `Admin\Product\BrandController` | `POST admin/brand/delete` |
| `Admin\Product\CategoryController` | `POST admin/category/update`<br>`POST admin/category/delete` |
| `Admin\Settings\BusinessSettingsController` | `GET admin/business-settings/product-settings`<br>`POST admin/business-settings/product-settings` |
| `Modules\AI\Admin\AIProductController` | `GET admin/product/title-auto-fill`<br>`GET admin/product/description-auto-fill`<br>`GET admin/product/general-setup-auto-fill`<br>`GET admin/product/price-others-auto-fill`<br>`GET admin/product/seo-section-auto-fill`<br>`GET admin/product/variation-setup-auto-fill`<br>`POST admin/product/analyze-image-auto-fill`<br>`POST admin/product/generate-title-suggestions` |
| `Modules\AI\API\V3\AIAuctionProductController` | `GET admin/auction/product/title-auto-fill`<br>`GET admin/auction/product/description-auto-fill`<br>`GET admin/auction/product/general-setup-auto-fill`<br>`GET admin/auction/product/shipping-policy-auto-fill`<br>`GET admin/auction/product/auction-info-auto-fill`<br>`GET admin/auction/product/seo-section-auto-fill`<br>`POST admin/auction/product/analyze-image-auto-fill`<br>`POST admin/auction/product/generate-title-suggestions` |
| `Admin\SalesChannels\ChannelAccountOperationsController` | `POST admin/sales-channels/accounts/{account}/product-group/members` |

### Routes that read or name a product, of the controllers scoped in slice 2

| Controller | Routes that read or name a product |
|---|---|
| `Admin\Order\OrderController` | `GET admin/orders/details/{id}` |
| `Admin\Order\OrderEditController` | `GET admin/orders/search-for-edit-order-product`<br>`POST admin/orders/edit-order-product-modal-view`<br>`POST admin/orders/edit-order-product-add`<br>`POST admin/orders/edit-order-product-variant-price`<br>`POST admin/orders/edit-order-product-list-update`<br>`POST admin/orders/edit-order-product-remove` |
| `Admin\POS\POSController` | `GET admin/pos`<br>`POST admin/pos/update-discount`<br>`POST admin/pos/coupon-discount`<br>`GET admin/pos/quick-view`<br>`GET admin/pos/search-product` |
| `Admin\POS\CartController` | `POST admin/pos/get-variant-price`<br>`POST admin/pos/quantity-update`<br>`POST admin/pos/add-to-cart` |
| `Admin\POS\POSOrderController` | `POST admin/pos/order-place` |

### Routes that read or name a product, of the controllers scoped in slice 3

| Controller | Routes that read or name a product |
|---|---|
| `Admin\DashboardController` | `GET admin/dashboard`<br>`GET admin/dashboard/real-time-activities` |
| `Admin\ReportController` | `GET admin/report/vendor-earning` |
| `Admin\ProductReportController` | `GET admin/report/all-product`<br>`GET admin/report/all-product-excel` |
| `Admin\ProductStockReportController` | `GET admin/stock/product-stock`<br>`GET admin/stock/product-stock-export` |
| `Admin\ProductWishlistReportController` | `GET admin/stock/product-in-wishlist`<br>`GET admin/stock/wishlist-product-export` |
| `Admin\InhouseProductSaleController` | `GET admin/report/inhouse-product-sale` |
| `Admin\TransactionReportController` | `GET admin/transaction/order-transaction-list` |
| `Admin\VendorProductSaleReportController` | `GET admin/report/vendor-report` |
| `Admin\Vendor\VendorController` | `GET admin/vendors/order-details/{order_id}/{vendor_id}` |

### Routes that read or name a product, of the controllers scoped in slice 4

| Controller | Routes that read or name a product |
|---|---|
| `Admin\Settings\SeoTranslationController` |  |
| `Admin\Promotion\FlashDealController` | `POST admin/deal/delete-product`<br>`GET admin/deal/add-product/{deal_id}`<br>`POST admin/deal/add-product/{deal_id}`<br>`GET\|POST\|PUT\|PATCH\|DELETE\|OPTIONS admin/deal/search-product` |
| `Admin\Promotion\DealOfTheDayController` | `GET admin/deal/day`<br>`POST admin/deal/day`<br>`GET admin/deal/day-update/{id}`<br>`POST admin/deal/day-update/{id}` |
| `Admin\Promotion\ClearanceSaleController` | `GET admin/deal/clearance-sale`<br>`GET admin/deal/clearance-sale/search`<br>`GET admin/deal/clearance-sale/multiple-product-details`<br>`POST admin/deal/clearance-sale/add-clearance-product`<br>`POST admin/deal/clearance-sale/clearance-product-status-update`<br>`DELETE admin/deal/clearance-sale/clearance-delete/{product_id}`<br>`DELETE admin/deal/clearance-sale/clearance-products-delete`<br>`POST admin/deal/clearance-sale/update-discount` |
| `Admin\Promotion\MostDemandedController` |  |
| `Admin\Promotion\BannerController` | `GET admin/banner/list`<br>`GET admin/banner/update/{id}` |
| `Admin\Settings\InhouseShopController` | `GET admin/business-settings/inhouse-shop` |
| `Admin\Settings\ThemeBuilderController` | `GET admin/theme/builder/resources`<br>`GET admin/theme/builder/resource-labels` |
| `Admin\Settings\SeoHealthController` | `GET admin/seo-settings/health` |
| `Admin\Settings\SiteMapController` |  |

### Routes that read or name a product, of the controllers scoped in slice 5

| Controller | Routes that read or name a product |
|---|---|
| `Admin\Marketplace\BatchController` | `GET admin/marketplace/batches`<br>`POST admin/marketplace/batches` |
| `Admin\Marketplace\InventoryAdjustmentController` | `GET admin/marketplace/inventory-adjustments`<br>`POST admin/marketplace/inventory-adjustments/adjust` |
| `Admin\Marketplace\PurchaseOrderController` | `POST admin/marketplace/purchase-orders` |
| `Admin\Marketplace\WarehouseController` | `GET admin/marketplace/warehouses`<br>`POST admin/marketplace/warehouses/place`<br>`POST admin/marketplace/warehouses/transfer` |

### Routes that read or name a product, of the controllers that are out of slice 6

A restricted employee who has the permission of one of these screens still sees every country's products there. Each is named for the slice that takes it.

| Controller | Routes that read or name a product |
|---|---|
| `Admin\Product\ReviewController` | `GET admin/reviews/list`<br>`GET admin/reviews/export`<br>`GET\|POST\|PUT\|PATCH\|DELETE\|OPTIONS admin/reviews/search-product` |
| `Admin\Marketplace\BrandRegistryController` |  |
| `Admin\Marketplace\CommissionRuleController` | `GET admin/marketplace/commission-rules` |
| `Admin\Marketplace\ReturnLogisticsController` | `POST admin/marketplace/returns` |
| `Admin\Fulfillment\M5aznProductsController` | `GET admin/fulfillment-providers/m5azn/products`<br>`POST admin/fulfillment-providers/m5azn/products/import-all`<br>`POST admin/fulfillment-providers/m5azn/products/{item}/import` |
| `Admin\Fulfillment\TorodMatchingController` | `GET admin/fulfillment-providers/torod-point/matching`<br>`POST admin/fulfillment-providers/torod-point/matching/approve`<br>`POST admin/fulfillment-providers/torod-point/matching/push`<br>`GET admin/fulfillment-providers/torod-point/products/search` |
| `Admin\SalesChannels\ChannelProductController` | `GET admin/sales-channels/products`<br>`GET admin/sales-channels/products/product-search`<br>`POST admin/sales-channels/products/{mapping}/link`<br>`POST admin/sales-channels/products/{mapping}/ignore`<br>`POST admin/sales-channels/products/{mapping}/unignore`<br>`POST admin/sales-channels/products/{mapping}/accept-suggestion`<br>`POST admin/sales-channels/products/{mapping}/settings`<br>`POST admin/sales-channels/products/{mapping}/fulfilment-mode` |
| `Admin\SalesChannels\ChannelListingController` | `GET admin/sales-channels/listings/{product}`<br>`POST admin/sales-channels/listings/{product}/accounts/{account}` |
| `Admin\SalesChannels\ChannelInventoryController` | `GET admin/sales-channels/inventory` |
| `Admin\SalesChannels\ChannelOrderController` | `GET admin/sales-channels/orders/{mapping}` |
| `Admin\SalesChannels\ChannelRoutingController` | `GET admin/sales-channels/routing` |
| `Admin\OperationalCosts\ProductCostController` | `GET admin/operational-costs/product-costs`<br>`POST admin/operational-costs/product-costs`<br>`GET admin/operational-costs/product-costs/products/search`<br>`GET admin/operational-costs/product-costs/{layer}` |
| `Admin\OperationalCosts\ReturnController` | `GET admin/operational-costs/returns` |

### Blind spots of the scan, found by hand

The scan does not follow a call into a service, and an order screen names an order and no product. These admin controllers are listed by hand (the guard checks that each is
still an admin controller, nothing more), with what is done about them:

| Controller | Status | Why it is not seen by the scan |
|---|---|---|
| `Admin\AdvancedSearchController` | scoped in slice 3 | The header search of the classic layout finds a product only when it is one of the employee's countries' (StaffSearchHits), with no middleware: it fills a cache every employee shares. |
| `Admin\V2AdvancedSearchController` | scoped in slice 3 | The header search of the new layout is the same search (StaffSearchHits). |
| `Admin\Commerce\CollectionController` | scoped in slice 4 | The preview of a collection resolves its products through CollectionResolver, which reads them with the model, so the product scope narrows the preview as soon as the request is bound. Scoped in slice 4; a collection itself has no market. |
| `Admin\Order\RefundController` | scoped in slice 2 | A refund request follows the country of the product of the order line it is about. The refund lists, their exports, the details and the decision on a request go through the scope of the refund requests and the rules of StaffOrderRoutes. |
| `Admin\Telemetry\AnalyticsController` | scoped in slice 3 | The analytics export by product counts the products of every country, so it is unavailable to a restricted employee (StaffOrderRoutes). |
| `Admin\Order\DeliverySyriaDispatchController` | scoped in slice 2 | Dispatches an order to the Delivery Syria courier by its id: an order route like the order screens (StaffOrderRoutes). It reads no product. |
| `Admin\Customer\CustomerController` | scoped in slice 3 | Lists the orders and refund requests of a customer and counts the orders of each customer (StaffOrderRoutes). It reads no product. |
| `Admin\Customer\CustomerWalletController` | scoped in slice 3 | The wallet report and a customer's wallet movements, narrowed by the market each ledger row records (StaffCountryLedgerScope; StaffOrderRoutes). It reads no product. |
| `Admin\Customer\CustomerLoyaltyController` | scoped in slice 3 | A customer's loyalty movements, narrowed the same way (StaffCountryLedgerScope; StaffOrderRoutes). It reads no product. |
| `Admin\Deliveryman\DeliveryManController` | scoped in slice 3 | Lists the orders, the earnings and the status history of a delivery man (StaffOrderRoutes). It reads no product. |
| `Modules\TaxModule\Admin\Reports\AdminTaxReportController` | scoped in slice 3 | The tax report sums the taxes of the orders of the employee's countries (StaffOrderRoutes). It reads no product. |
| `Modules\TaxModule\Admin\Reports\VendorTaxReportController` | scoped in slice 3 | The vendor tax reports sum the orders and taxes of the employee's countries (StaffOrderRoutes). They read no product. |
| `Admin\OrderReportController` | scoped in slice 3 | The order report sums the orders of the employee's countries (StaffOrderRoutes). It reads no product. |
| `Admin\ExpenseTransactionReportController` | scoped in slice 3 | The expense transaction report sums the discounts and delivery expenses of the orders of the employee's countries (StaffOrderRoutes). It reads no product. |
| `Admin\Report\RefundTransactionController` | scoped in slice 3 | The refund transaction report lists the money records of the refunds about a line of the employee's countries (StaffOrderRoutes). It reads no product. |
| `Admin\Marketplace\FulfillmentController` | scoped in slice 5 | The pick, pack and ship queue of the marketplace: a fulfilment names an ORDER and reads no product of its own (StaffMarketplaceRoutes). |
| `Admin\Marketplace\SellerScorecardController` | scoped in slice 5 | The seller scorecard, refused rather than narrowed: its figures are a seller's across the whole platform and it reads no product of its own (StaffMarketplaceRoutes, every action `unavailable`). |
| `Admin\Marketplace\SettlementController` | scoped in slice 5 | The settlements, refused rather than narrowed: a seller's money is not split by market and the page reads no product (StaffMarketplaceRoutes, every action `unavailable`). |

## The survey: every admin route that reads orders or names one

`tests/Feature/StaffScope/StaffOrderRouteInventoryTest.php` is the same guard for orders, their lines, their money records, refunds and the earnings and counters made of them.
It walks the real route table and fails for an admin controller that reads any of them, or names an order in its path or its parameters, and is neither scoped nor listed in
`tests/Support/StaffOrderInventory.php` with a reason (and, when it is out of slice 3, the slice that takes it, or `owner` when what it shows cannot be told apart by country).
The scan reads the models, the repositories, the tables, the relations, the services that hold the sums, the wallets, and an order id in a request, in the source of each action and
in the file of each controller. The scoped controllers are exactly the ones that have rules in `StaffOrderRoutes`, and a test keeps this document in step.

**32 controllers: 6 scoped in slice 2, 14 scoped in slice 3, 1 scoped in slice 4, 3 scoped in slice 5, 4 scoped in slice 6, 4 not needed, 0 out of slice 6.**
**114 routes read or name an order: 36 scoped in slice 2, 49 scoped in slice 3, 1 scoped in slice 4, 4 scoped in slice 5, 11 scoped in slice 6, 13 not needed, 0 out of slice 6.**

| Slice | What it takes |
|---|---|
| slice 3 | the rest of the order reads, the shared layout, the dashboard and the reports |
| slice 4 | promotions and storefront content: deals, banners, themes, the SEO pages and the site map |
| slice 5 | vendors, reviews and the marketplace operations |
| slice 6 | integrations and costs: sales channels, fulfilment providers and the operational costs |
| owner | figures that cannot be told apart by country, until the owner says what an employee may see |

| Controller | Admin routes | Routes that read or name an order | Status | Why |
|---|---:|---:|---|---|
| `Admin\Order\OrderController` | 18 | 18 | scoped in slice 2 | Every order screen: the lists per status with their filters and counters, the exports, the details with the neighbours of an order, and every action on an order. Every action has a rule in StaffOrderRoutes. |
| `Admin\Order\OrderEditController` | 7 | 7 | scoped in slice 2 | The order edit: the order is looked up by id, the product picker is narrowed by the product scope and a product of another country is refused. Every action has a rule in StaffOrderRoutes. |
| `Admin\Order\DeliverySyriaDispatchController` | 1 | 1 | scoped in slice 2 | Dispatches an order to the Delivery Syria courier by its id: an order route like the order screens. Every action has a rule in StaffOrderRoutes. |
| `Admin\Order\RefundController` | 4 | 4 | scoped in slice 2 | The refund requests: the lists, their exports, the details and the decision follow the order line each request is about. Every action has a rule in StaffOrderRoutes. |
| `Admin\POS\POSController` | 6 | 2 | scoped in slice 2 | The POS grid lists the products of the employee's countries and the filter of the POS orders reads the orders of the scope. Every action has a rule in StaffOrderRoutes. |
| `Admin\POS\POSOrderController` | 4 | 4 | scoped in slice 2 | Places a POS order only when every product of the cart is the employee's, and reads a POS order by id. Every action has a rule in StaffOrderRoutes. |
| `Admin\Customer\CustomerController` | 15 | 2 | scoped in slice 3 | The list of customers counts the orders of each, the screen of one customer lists their orders and counts their refund requests, and the export holds their orders: all in the employee's countries. A customer belongs to no country, so the list of customers is not narrowed. Every action has a rule in StaffOrderRoutes. |
| `Admin\Deliveryman\DeliveryManController` | 15 | 8 | scoped in slice 3 | The orders, the earnings per order and the status history of a delivery man, and the status history of an order by its number, in the employee's countries. The wallet overview and the ratings of a delivery man are unavailable to a restricted employee. Every action has a rule in StaffOrderRoutes. |
| `Admin\Vendor\VendorController` | 17 | 7 | scoped in slice 3 | The orders of a vendor and their export, an order read by its number, the money records of a vendor and the order counts of the list of vendors, in the employee's countries. The request is bound for the whole controller, so the product tab of a vendor lists the employee's countries too. Every action has a rule in StaffOrderRoutes. |
| `Admin\DashboardController` | 5 | 5 | scoped in slice 3 | The counters, earnings and graphs, the top lists and the real-time poller of the dashboard read the orders, lines, money records and refunds of the employee's countries. The wallets (the admin wallet, the top vendors by earning, the auction wallet) cannot be told apart by country and are not shown to a restricted employee. Every action has a rule in StaffOrderRoutes. |
| `Admin\OrderReportController` | 3 | 3 | scoped in slice 3 | The order report, its totals and its charts sum the orders of the employee's countries. Every action has a rule in StaffOrderRoutes. |
| `Admin\ReportController` | 6 | 5 | scoped in slice 3 | The earning reports sum the orders, their money records and refunds of the employee's countries, and its export by vendor does the same. The vendor earning report is made of the wallets of the vendors and is unavailable to a restricted employee. Every action has a rule in StaffOrderRoutes. |
| `Admin\TransactionReportController` | 5 | 5 | scoped in slice 3 | The order transaction report lists and sums the money records of the orders of the employee's countries. The wallet bonus tab lists the bonuses of customers' wallets, which carry no order and no country, and is not narrowed. Every action has a rule in StaffOrderRoutes. |
| `Admin\ExpenseTransactionReportController` | 4 | 4 | scoped in slice 3 | The expense transaction report lists and sums the discounts and delivery expenses of the orders of the employee's countries. Every action has a rule in StaffOrderRoutes. |
| `Admin\Report\RefundTransactionController` | 3 | 3 | scoped in slice 3 | The refund transaction report lists the money records of the refunds about a line of the employee's countries. Every action has a rule in StaffOrderRoutes. |
| `Admin\VendorProductSaleReportController` | 2 | 2 | scoped in slice 3 | The vendor sales report sums the orders, lines and refund money records of the employee's countries. Every action has a rule in StaffOrderRoutes. |
| `Admin\ProductReportController` | 2 | 2 | scoped in slice 3 | The all-products report sums the sales of the order lines of the employee's countries, for the products of their countries. Every action has a rule in StaffOrderRoutes. |
| `Admin\Telemetry\AnalyticsController` | 7 | 1 | scoped in slice 3 | The analytics screens count visits, orders and revenue from rollups that carry no country of the shop, so they cannot be told apart by country: every action is unavailable to a restricted employee until the owner decides what they may see. Every action has a rule in StaffOrderRoutes. |
| `Modules\TaxModule\Admin\Reports\AdminTaxReportController` | 4 | 0 | scoped in slice 3 | The tax report and its details and exports sum the taxes of the money records of the orders of the employee's countries. Every action has a rule in StaffOrderRoutes. |
| `Modules\TaxModule\Admin\Reports\VendorTaxReportController` | 4 | 2 | scoped in slice 3 | The vendor tax reports and their exports sum the orders and taxes of the employee's countries; the raw sum over the orders table is narrowed by the same rule. Every action has a rule in StaffOrderRoutes. |
| `Admin\Product\ProductController` | 41 | 2 | not needed | Counts the order lines of a product the employee works with: the lines of their own products, narrowed by the product scope of slice 1. It lists no order. |
| `Admin\Settings\BusinessSettingsController` | 15 | 2 | not needed | The refund setup page is a setting of the shop. It reads no order. |
| `Admin\Settings\OrderSettingsController` | 2 | 2 | not needed | The order settings page is a setting of the shop. It reads no order. |
| `Admin\Marketplace\PurchaseOrderController` | 7 | 7 | not needed | Purchase orders to suppliers are not customer orders: they have no order, no order line and no country of their own. |
| `Admin\Settings\InhouseShopController` | 6 | 1 | scoped in slice 4 | The in-house shop page counts the in-house orders. Narrowed in slice 4 by the `list:orders` part of its rule in StaffPromotionRoutes, which is the slice that took the page: an order and a product are two subjects, and one middleware carries both. |
| `Admin\Marketplace\FulfillmentController` | 4 | 2 | scoped in slice 5 | The marketplace fulfilments are shipments of orders. Narrowed in slice 5 through the order each one ships (StaffCountryOrderScope on OrderFulfillment, subject `fulfillments`), and opening, advancing or cancelling one is judged by that order. Only the queue is narrowed: the service that opens one has to see the fulfilments an order already has, whatever market they are in. Every action has a rule in StaffMarketplaceRoutes. |
| `Admin\Marketplace\ReturnLogisticsController` | 5 | 1 | scoped in slice 5 | A marketplace return names an order, a line, a product or several of those, and is judged by whichever it names; the queue is narrowed through the order each return is of. Every action has a rule in StaffMarketplaceRoutes. |
| `Admin\Product\ReviewController` | 7 | 1 | scoped in slice 5 | The reviews list loads the order behind a review, and the review itself is narrowed by the market of its PRODUCT -- which is the market of the review, and the one key a review always has, since a review may name no order. Every action has a rule in StaffMarketplaceRoutes. |
| `Admin\Fulfillment\ProviderFulfillmentController` | 5 | 1 | scoped in slice 6 | A provider shipment is a shipment of one order, so it follows that order where a screen asked to be narrowed (StaffCountryOrderScope on ProviderFulfillment, subject `provider_fulfillments`), and an action on one is judged by the order it ships. The provider API logs are the provider's traffic for the whole platform and are refused. Every action has a rule in StaffIntegrationRoutes. |
| `Admin\SalesChannels\ChannelOrderController` | 7 | 7 | scoped in slice 6 | A channel order belongs to its ACCOUNT, not to an order of ours: order_id stays NULL until we take responsibility for fulfilling it, so the account is the key the row always has. Narrowed in slice 6 by the account (StaffCountryChannelAccountScope). Every action has a rule in StaffIntegrationRoutes. |
| `Admin\OperationalCosts\ProfitabilityController` | 3 | 1 | scoped in slice 6 | The profit page of ONE sale names an order of ours or a channel order and is judged by whichever it names. The LIST and its export are read by SaleDocumentReader with the query builder and the rollup has no market dimension, so both are refused together rather than narrowing a page whose export hands over every market. Every action has a rule in StaffCostRoutes. |
| `Admin\MyFatoorah\SettingsController` | 10 | 2 | scoped in slice 6 | A payment ATTEMPT names a payment_request_id and not an order -- the order does not exist yet when the attempt is made -- so no row of this monitor can be told apart by market, while its actions refund money and re-run webhooks. Settled in slice 6 by a REFUSAL: every action is `unavailable` in StaffIntegrationRoutes. |

### Routes that read or name an order, of the controllers scoped in slice 3

| Controller | Routes that read or name an order |
|---|---|
| `Admin\Customer\CustomerController` | `GET admin/customer/view/{user_id}`<br>`GET admin/customer/order-list-export/{user_id}` |
| `Admin\Deliveryman\DeliveryManController` | `GET admin/delivery-man/list`<br>`GET admin/delivery-man/export`<br>`GET admin/delivery-man/earning-statement-overview/{id}`<br>`GET admin/delivery-man/order-wise-earning/{id}`<br>`GET admin/delivery-man/order-list-by-filer/{id}`<br>`GET admin/delivery-man/order-history-log/{id}`<br>`GET admin/delivery-man/order-history-log-export/{id}`<br>`GET admin/delivery-man/ajax-order-status-history/{order}` |
| `Admin\Vendor\VendorController` | `GET admin/vendors/list`<br>`POST admin/vendors/add`<br>`GET admin/vendors/order-list-export/{vendor_id}`<br>`GET admin/vendors/export`<br>`GET admin/vendors/order-details/{order_id}/{vendor_id}`<br>`GET admin/vendors/view/{id}/{tab?}`<br>`POST admin/vendors/withdraw-status/{id}` |
| `Admin\DashboardController` | `GET admin/dashboard`<br>`POST admin/dashboard/order-status`<br>`GET admin/dashboard/earning-statistics`<br>`GET admin/dashboard/order-statistics`<br>`GET admin/dashboard/real-time-activities` |
| `Admin\OrderReportController` | `GET admin/report/order`<br>`GET admin/report/order-report-excel`<br>`GET admin/report/order-report-pdf` |
| `Admin\ReportController` | `GET admin/report/admin-earning`<br>`GET admin/report/admin-earning-excel-export`<br>`POST admin/report/admin-earning-duration-download-pdf`<br>`GET admin/report/vendor-earning`<br>`GET admin/report/vendor-earning-excel-export` |
| `Admin\TransactionReportController` | `GET admin/transaction/order-transaction-list`<br>`GET admin/transaction/pdf-order-wise-transaction`<br>`GET admin/transaction/order-transaction-export-excel`<br>`GET admin/transaction/order-transaction-summary-pdf`<br>`GET admin/transaction/wallet-bonus` |
| `Admin\ExpenseTransactionReportController` | `GET admin/transaction/expense-transaction-list`<br>`GET admin/transaction/pdf-order-wise-expense-transaction`<br>`GET admin/transaction/expense-transaction-export-excel`<br>`GET admin/transaction/expense-transaction-summary-pdf` |
| `Admin\Report\RefundTransactionController` | `GET admin/report/transaction/refund-transaction-list`<br>`GET admin/report/transaction/refund-transaction-export`<br>`GET admin/report/transaction/refund-transaction-summary-pdf` |
| `Admin\VendorProductSaleReportController` | `GET admin/report/vendor-report`<br>`GET admin/report/vendor-report-export` |
| `Admin\ProductReportController` | `GET admin/report/all-product`<br>`GET admin/report/all-product-excel` |
| `Admin\Telemetry\AnalyticsController` | `GET admin/analytics/export/{dimension}` |
| `Modules\TaxModule\Admin\Reports\AdminTaxReportController` |  |
| `Modules\TaxModule\Admin\Reports\VendorTaxReportController` | `GET admin/report/vendor-tax-report`<br>`GET admin/report/vendor-tax-export` |

### Routes that read or name an order, of the controllers that are not needed

| Controller | Routes that read or name an order |
|---|---|
| `Admin\Product\ProductController` | `GET admin/products/view/{addedBy}/{id}`<br>`GET admin/products/export-excel/{type}` |
| `Admin\Settings\BusinessSettingsController` | `GET admin/business-settings/refund-setup`<br>`POST admin/business-settings/refund-setup` |
| `Admin\Settings\OrderSettingsController` | `GET admin/business-settings/order-settings/index`<br>`POST admin/business-settings/order-settings/update-order-settings` |
| `Admin\Marketplace\PurchaseOrderController` | `GET admin/marketplace/purchase-orders`<br>`GET admin/marketplace/purchase-orders/create`<br>`POST admin/marketplace/purchase-orders`<br>`GET admin/marketplace/purchase-orders/{id}`<br>`POST admin/marketplace/purchase-orders/{id}/place`<br>`POST admin/marketplace/purchase-orders/{id}/receive`<br>`POST admin/marketplace/purchase-orders/{id}/cancel` |

### Routes that read or name an order, of the controllers scoped in slice 4

| Controller | Routes that read or name an order |
|---|---|
| `Admin\Settings\InhouseShopController` | `GET admin/business-settings/inhouse-shop` |

### Routes that read or name an order, of the controllers that are out of slice 6

A restricted employee who has the permission of one of these screens still sees the orders of every country there. Each is named for the slice that takes it, or for the owner.

| Controller | Routes that read or name an order |
|---|---|
| `Admin\Marketplace\FulfillmentController` | `GET admin/marketplace/fulfillments`<br>`POST admin/marketplace/fulfillments` |
| `Admin\Marketplace\ReturnLogisticsController` | `POST admin/marketplace/returns` |
| `Admin\Product\ReviewController` | `GET admin/reviews/list` |
| `Admin\Fulfillment\ProviderFulfillmentController` | `GET admin/fulfillment-providers/fulfillments` |
| `Admin\SalesChannels\ChannelOrderController` | `GET admin/sales-channels/orders`<br>`GET admin/sales-channels/orders/{mapping}`<br>`POST admin/sales-channels/orders/{mapping}/route`<br>`POST admin/sales-channels/orders/{mapping}/dispatch`<br>`POST admin/sales-channels/orders/{mapping}/link-shipment`<br>`POST admin/sales-channels/orders/{mapping}/unlink-shipment`<br>`POST admin/sales-channels/orders/{mapping}/notify` |
| `Admin\OperationalCosts\ProfitabilityController` | `GET admin/operational-costs/profit/orders/{type}/{id}` |
| `Admin\MyFatoorah\SettingsController` | `POST admin/third-party/payment-method/myfatoorah/attempts/{attempt}/refund`<br>`POST admin/third-party/payment-method/myfatoorah/refunds/{refund}/executed` |

### Blind spots of the order scan, found by hand

These admin controllers reach orders or their money through a service or a trait the scan does not read, or read none themselves. They are listed by hand (the guard checks that each
is still an admin controller, nothing more). `App\Http\Controllers\Admin\TransactionController`, which reads order transactions with no scope, has no admin route at all: a test
fails the day one reaches it.

| Controller | Status | Why it is not seen by the scan |
|---|---|---|
| `Admin\V2AdvancedSearchController` | scoped in slice 3 | The header search of the new layout finds an order or a refund request by its number only when it is the employee's, and a product only when it is one of their countries'. It narrows what it hands back, with no middleware: it fills a cache every employee shares (App\Services\Staff\StaffSearchHits). |
| `Admin\AdvancedSearchController` | scoped in slice 3 | The header search of the classic layout is the same search as the new one (StaffSearchHits): an order or a refund request is found only when it is the employee's, with no middleware. |
| `Admin\InhouseProductSaleController` | scoped in slice 3 | The in-house sales report counts the sales of the order lines of the employee's countries through the product repository, for their products. Every action has a rule in StaffOrderRoutes. |
| `Admin\Customer\CustomerWalletController` | scoped in slice 3 | The wallet report and a customer's wallet movements. The reason they were the owner's to decide -- "they carry no order and no country" -- stopped being true when wallet_transactions gained storefront_country: each row records its own market, so a restricted employee is shown the movements of their markets through StaffCountryLedgerScope. The bonus setup is the platform's own configuration and lists nothing. Every action has a rule in StaffOrderRoutes. |
| `Admin\Customer\CustomerLoyaltyController` | scoped in slice 3 | A customer's loyalty movements, narrowed the same way through loyalty_point_transactions.storefront_country. Every action has a rule in StaffOrderRoutes. |
| `Admin\Marketplace\SellerScorecardController` | scoped in slice 5 | The scorecard of a seller is made of its orders of every country, through SellerScorecardService, which reads them with the QUERY BUILDER so no scope reaches them -- and which market's orders count towards a seller's tier is a question nobody has answered. Settled in slice 5 by a REFUSAL: `unavailable` in StaffMarketplaceRoutes, so a restricted employee is answered like for a page that does not exist rather than shown a platform total. |
| `Admin\Marketplace\SettlementController` | scoped in slice 5 | The settlements of the sellers are made of their orders of every country, through a vendor ledger keyed by seller, currency and legal entity and not by market (E-17.2 ships off), so a settlement cannot be split by country at all; and the separation of duties and the payment terms on that page change what happens in every market. Settled in slice 5 by a REFUSAL: every action is `unavailable` in StaffMarketplaceRoutes. |
| `Admin\OperationalCosts\OverviewController` | scoped in slice 6 | The overview of the operational costs aggregates the profit of the orders of every country, and the rollup has no market dimension to narrow. Settled in slice 6 by a REFUSAL: `unavailable` in StaffCostRoutes, so an employee of one market is not shown a platform total as though it were theirs. |
| `Admin\SalesChannels\ChannelAccountOperationsController` | scoped in slice 6 | Everything that CALLS a marketplace is an account's connection, so each action is judged by the market that account sells in (StaffCountryScope::coversEveryChannelAccount). An account whose settings name no country belongs to no market and is refused. Every action has a rule in StaffIntegrationRoutes. |
| `Admin\POS\CartController` | scoped in slice 2 | The POS cart takes, prices and counts a product by id and reads no order (StaffOrderRoutes). |
| `Admin\ProductStockReportController` | scoped in slice 3 | The stock report and its export list the products of the employee's countries and read no order (StaffOrderRoutes). |
| `Admin\ProductWishlistReportController` | scoped in slice 3 | The wishlist report and its export list the products of the employee's countries and read no order (StaffOrderRoutes). |

## The survey: every admin route that reads the panel's own governance

`tests/Feature/StaffScope/StaffGovernanceScreensTest.php` is the THIRD scan, and it asks a different question from the other two. Those ask which market a row of the shop
belongs to; this one asks who may change who may see it: the employees, their roles, the countries they work with, the audit trail, and the credentials of the integrations.
These screens were in neither survey, so a screen that hands an employee the power to widen their own reach could have been added with nobody deciding so.

It walks the real route table and fails for an admin controller that reads any of those and is not in `tests/Support/StaffGovernanceInventory.php` with a reason. Writing an
audit line is NOT part of it -- forty controllers record one, and that is G1-08's business rather than a screen that shows one.

**10 controllers: 2 master-only, 1 scoped, 5 not needed, 2 out.**

| Controller | Admin routes | Routes that read it | Status |
|---|---:|---:|---|
| `Admin\Employee\EmployeeController` | 8 | 8 | master only |
| `Admin\Employee\CustomRoleController` | 7 | 7 | master only |
| `Admin\Marketplace\AuditLogController` | 1 | 1 | scoped |
| `Admin\ProfileController` | 3 | 3 | not needed |
| `Admin\Vendor\VendorController` | 17 | 1 | not needed |
| `Admin\Settings\InhouseShopController` | 6 | 1 | not needed |
| `Admin\OperationalCosts\ContractController` | 9 | 1 | not needed |
| `Admin\OperationalCosts\CostEntryController` | 6 | 1 | not needed |
| `Admin\ThirdParty\PaymentMethodController` | 3 | 3 | out (owner) |
| `Admin\ThirdParty\SMSModuleController` | 3 | 2 | out (owner) |

**`master_only` is a real answer, not an omission.** The employee and role groups carry `EnsureMasterAdmin`, so no restricted employee reaches them at all, and the three
questions a delegated people manager would raise -- not reaching the master account, not creating an employee wider than oneself, not handing out a role one does not hold --
are answered by that gate rather than by a country scope. It is only true while the middleware is there, so the guard asserts it on every one of those 15 routes: a route of
such a controller that lost the gate would hand a restricted employee the one screen that can widen their own reach. `AdminService::isOutOfReachOfStaffManagement()` and
`::assignableRoleId()` guard the actions underneath it, so the master ACCOUNT and the master ROLE stay out of reach even of the first admin's own screens
(`tests/Feature/Security/MasterAccountInStaffManagementTest.php`).

**The audit centre was the defect this survey found.** `AuditLogController` narrows both of its reads -- the listing and the module list of its filter -- by
`StaffCountryScope::boundCountries()`, and NOTHING on its route bound anything. The shared admin layout binds a request that nothing else did, but it does so in a view
composer, which runs after the controller has already read and paginated its rows. So the narrowing was dead code and a restricted employee with the system-settings module
read every market's trail. `ScopeGovernanceToStaffCountries`, registered by the controller, is what binds it, and `App\Services\Staff\StaffGovernanceRoutes` holds the rule
of its one action so a second action cannot be added unconsidered. The general lesson is the one slice 3 already learnt in another form: a screen that reads a market-owned
table needs a middleware of its own, and a narrowing that reads the bound countries proves nothing until something binds them.

| Route | Action | Rule | Permission |
|---|---|---|---|
| `GET admin/marketplace/audit-log` | `AuditLogController::index` | `list:audit` | module:system_settings |

A line that names no market is nobody's: `AuditLogger` refuses to file a panel action as the default market's, so a reading that handed those lines to whoever holds the
default market would invent the same fact at the other end. Every line written before `2026_12_29_100000` names no market, so a restricted employee sees none of them.

**What is still out, and the question it waits on.** The payment-gateway and SMS credentials live in one global settings row each (`live_values`), so a restricted employee
with the third-party setup module reads and writes every market's. Whether a gateway belongs to a market at all is the owner's to say (G1-13); the gateway ACCOUNTS of
GCC-06 are the per-market design and these two screens are the legacy global ones.

## The panel's money actions, behind a switch of their own (G-14, second criterion)

`gcc.staff.money_actions.scoped`, from `GCC_STAFF_MONEY_ACTIONS_SCOPED`, **off as it ships**. Three actions of the screens listed above
carried the rule `none` -- which in this registry means "names nothing that belongs to a market" -- while each of them moves money, and for
a money action that was a false reassurance rather than a fact:

| Action | Rule while the switch is ON | What it is checked against |
|---|---|---|
| `VendorController::withdrawStatus` | `seller_payout.route:id` | the market of the SHOP whose withdrawal it approves or denies |
| `VendorController::getWithdrawView` | `seller_payout.route:withdrawId` | the same, so the amount and the bank details do not open for another market |
| `PayoutController::approve` | `seller_payout.route:id` | the market of the SHOP whose ledger payout it approves |
| `PayoutController::markPaid` | `seller_payout.route:id` | the same, for the transfer that takes the money out of the platform |
| `PayoutController::markFailed` | `seller_payout.route:id` | the same, for the transfer the bank sent back and the reservation it puts back |
| `PayoutController::reissue` | `seller_payout.route:id` | the same, for the fresh request a failed transfer opens |
| `PayoutController::reject` | `seller_payout.route:id` | the same, for the refusal that releases the reservation |
| `PayoutController::index` | `list:payouts` | the markets of the shops whose payouts it lists (`StaffCountryPayoutScope`) |
| `CustomerWalletController::addFund` | `unavailable` | nothing: a hand-made wallet credit names no market and is refused |

With the switch OFF `StaffOrderRoutes::rulesOf()` returns the rules above unchanged, so every guard, inventory and row of this document
reads exactly what it read before and not one extra query is made.

**Which market a seller's money belongs to** is `SellerMarketScope`'s rule, read by `StaffCountryScope::coversEverySeller()` and never
restated. Two states, and the difference is the whole of the method: while the seller market grants are not in force -- every installation
today -- no shop names a market, so a shop's money is the **default storefront's**, by the one rule the platform follows for a row naming
none, and a one-market platform keeps working. With the grants in force a shop granted exactly one market belongs to it, and a shop granted
several, or granted none and so unrestricted, belongs to no single market: its money is **refused** rather than filed under whichever market
the employee happens to hold. Reading `soleMarketOfShop()` alone would refuse every payout to every restricted employee, which is safe and
useless.

**Why the wallet credit is a refusal and not a narrowing.** `users.wallet_balance` is ONE global balance, a customer belongs to no market in
this schema (GCC-13), and the per-market accounts of GCC-15 are dormant. So there is no market a hand-made credit could be checked against,
and a platform-wide money write is not the act of one market. That is the same answer this registry already gives a restricted employee for a
platform total they cannot be shown a share of. The per-market accounts are what would change it.

**The ledger payouts are now inside the regime too, and they are where the restructure was spent.** `Admin\Marketplace\PayoutController`
had no scope middleware at all and was in no registry, so a restricted employee reached all six of its routes on the module permission
alone -- five of them money-moving POSTs that approve a transfer, mark it paid, mark it failed, send it again, or reject it and release the
reservation. Each is now judged by the market of the shop whose money it moves, by the same `coversEverySeller()` rule as a seller's
withdrawal. What it cost, since this was measured before it was done: `StaffMarketplaceRoutes::isCovered()` resolved EVERY rule to PRODUCT
ids, and a payout names a seller and no product, so a part is now resolved to the ids of its own SUBJECT and the subject decides which
question the scope is asked. Only `seller_payout.*` answers sellers; every other part answers products exactly as before, through the same
`productIdsOf()` the stock screens' tests drive directly. The rules live in a `MONEY_RULES` table merged in only while the money switch is
on, the same shape `StaffOrderRoutes` uses, so with the switch off every action keeps the rule `none` it has always effectively had.

**The payouts LIST is narrowed as well as its actions**, through the same rule (`App\Models\Scopes\StaffCountryPayoutScope`, subject
`payouts`, resolved by `StaffCountryScope::sellerIdsOfPayoutsOfTheRequest()`): a screen whose five buttons refuse another market's payout
while the page beside them prints its amount, its bank details and its reference is a half-closed gate that reads as closed. Only the GRANTED
shops are read rather than every seller, because with the grants in force a shop outside that table belongs to no market and can never be
listed -- the same trade `channelAccountIdsOfTheRequest()` makes, and for the same reason: a shop's market is derived rather than stored, so
no SQL can ask the question. The scope narrows only a request the LIST narrowed, which is deliberate: each of the five actions has to run on
the real row -- `PayoutService` releases a reservation, writes a ledger entry and marks a transfer failed -- so whether an employee may act
is the guard's answer and never the model quietly failing to find a row.

**What is still NOT covered when the switch is on**, said plainly because a half-closed money gate read as closed is worse than an open one:

- the withdraw LIST and its export (`getWithdrawListView`, `exportWithdrawList`) are **not narrowed**, so a restricted employee still sees
  every market's withdrawal requests and can only decide their own;
- `Admin\Deliveryman\DeliverymanWithdrawController` has **no scope middleware at all** and is in no registry, so a restricted employee
  reaches its list, its filter, its export, its one request's screen and its status POST on the module permission (`module:people`) alone.
  **This one is the owner's and is not guessed at:** a delivery man belongs to no market in this schema -- `delivery_men` carries no country
  and no storefront, and a delivery man is not a shop, so `SellerMarketScope` has nothing to say about one. There is no rule to READ here,
  only a rule somebody decides.

Neither is left as prose. `StaffMoneyActionsTest::test_the_money_screens_that_no_staff_country_scope_covers_are_exactly_these` walks the real
route table and pins the five routes still outside -- `admin/delivery-man/withdraw-list` and its four others -- so a money screen that gains
a scope has to come off the list and one that loses a scope has to go on it. Neither can happen silently. Its twin asserts that the screens
already inside really are inside, the six payout routes among them.

## Out of slices 1 to 3: the rule that would apply

| Area | Rule for the slice that takes it |
|---|---|
| **Orders** | Done in slice 2 on the order, POS and refund screens and in slice 3 on every other admin screen that lists them but the ones the surveys name for a later slice. The same rule applies wherever another screen lists orders. |
| **Refunds and returns** | The refund requests follow the order line they are about: done in slice 2. The returns of the marketplace (`ReturnLogisticsController`) are slice 5. |
| **Customers** | One identity across countries (GCC-13): a customer belongs to no country. What belongs to a country is their orders, wallets and addresses, which follow the rule above; a customer's list is not narrowed. Done in slice 3 for what a customer's screens say about orders; the wallet and loyalty reports carry no order and no country (open decisions). |
| **Reports and the dashboard** | Done in slice 3: every figure counts the orders of the employee's countries, and what cannot be split (a wallet) is hidden or unavailable (above). |
| **The vendor panel and seller API staff** | A different principal, with their own staff tables. Sellers have no markets yet (a seller product takes the default of the table), so there is nothing to scope until they do. |
| **Legal entities** | GCC-14 asks for scopes by country **and entity**. Entities have no table yet; an entity scope is a second dimension beside the country and needs its own decision. |
| **The platform admin (GCC-20)** | A separate principal with its own tables. **Owner decision recorded and built: a platform admin's grant country list is intersected with the staff scope**, so that an employee who holds both never sees more than the narrower of the two: see the next section. |

## The platform admin API is a consumer of the staff scope (GCC-20)

The decision recorded above is built: **the markets an admin reaches through the platform admin API are the markets of their grant that their staff scope also leaves them.** An employee who holds a
grant never reaches, through a platform token, a market that this scope forbids them in the admin panel. The API's own side is in `docs/gcc/GCC-20-PLATFORM-ADMIN-API.md`, section 12; it has no switch of its
own, so `GCC_STAFF_COUNTRY_SCOPES` decides for both.

- **The seam.** `StaffCountryScope::countriesOfAdmin(Admin)` is the rule of `bind()` without the binding: the countries the rows of an admin restrict them to, read from the rows as they are now and kept
  nowhere, or null when the switch is off or not enforced, for the master admin and for an employee with no row. `PlatformEffectiveMarkets` is its only caller outside the panel, and the one place of the
  platform API that applies it: every reader of the principal's markets reads what it gives.
- **The rule.** The grant's markets that the staff countries also hold, in the order of the configuration; a grant for every market held by a restricted employee comes to their countries; no market in
  common is a principal that sees nothing, not a refusal to sign in. A country that is not configured, or a value that is none, grants nothing, as here.
- **A restricted employee is no platform owner there.** The API shows them no bucket of orders that belong to no market, and only their own lines of its audit trail, as slice 2 hides the unattributed orders
  from every restricted employee, the one with all six countries included.
- **Dormant.** With the switch off, for the master admin, for an employee with no row, and while the table or the products column is missing, the platform API asks nothing about the staff scope and answers
  exactly what it answered. The rows are read on every request and never kept, so a scope that changes applies to the next request with the same token.
- **Read only.** The API never writes the table; `gcc:platform-admin:list` and `gcc:platform-admin:grant` show and warn about what the staff scope leaves of a grant, and change nothing.

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
   employee, and the storefront shows a country's own products only. Checking the ids in `ProductService::getBoughtTogetherIds` is the fix, and slice 4's.
10. **The counters and the lists of the dashboard** are narrowed in slice 3, and the layout's on every admin page. **No covered screen fills a shared cache from a product or an order
    query**: the caches of product lists are built by storefront requests, which are never bound, and the one cache an admin request fills from them (the search of the header) is
    filled with every country's and narrowed on the way out (assumption 26), so a narrowed answer cannot be served to someone else.
11. **Three language keys** were added in the middle of `en`, `sa` and `ae` `new-messages.php`; the Arabic text is the author's and should be read by a native speaker.

12. **An order is attributed by its lines, and an order that cannot be attributed is hidden from every restricted employee** (no line, a deleted product, a product without a
    country): it fails closed, never open. Owner decision recorded, with the safest reversible default; the opposite (show it to everyone) would let an employee read an order
    that no country owns.
13. **A mixed-country order is visible only to an employee whose scope holds all its countries.** The storefront cart is per country, so such an order can only come from the
    admin POS or an old order; a restricted employee cannot create one (the cart is refused), and the master admin can.
14. **A refund request follows the order line it is about, not the whole order.** The refund of a Kuwaiti line of a mixed order is decided by Kuwait. The decision runs on the real
    order, so the amounts, the wallet and the vendor ledger are the ones of the whole order. The opposite reading (a refund follows its order) is one condition away in the scope.
15. **A bound request is narrowed to orders only where the rule of the action lists them.** The actions that change an order run on the real data (the order number, the referral
    bonus, a coupon's uses): a blanket scope on `Order` would have been simpler and wrong.
16. **A restricted employee is answered 404, with the same body, for an order, line, refund request or product outside their countries and for one that does not exist**; for
    everyone else the answers are the old ones (assumption 5).
17. **A POS order is refused whole when the cart in use holds a product of another country**, with the same 404, and no stock is taken. The cart lives in the session, so a product
    of another country can only be in it when the scope was assigned or changed after it was filled; the products of another country are already hidden from that cart, and the
    employee empties or refills it.
18. **Opening the order list marks as seen only the orders the employee can see**, so the new-order flag of an order of another country is left for the employees who can see it.
19. **The number of a customer's orders on the order page is the number of the visible ones.**
20. **Delivery Syria** dispatch is an order action and is covered (`order.input:order_id`); the invoice route has no module permission and is covered all the same.
21. **No language key was added by slice 2**: the refusals are plain 404s with no message.

22. **The layout binds itself, once, when nothing has**: it is rendered after the screen, so what the screen read is not touched, and the products are narrowed with the orders for
    the rest of the request, which reads none. Every admin request of a restricted employee is narrowed in its layout; the master admin and the switch off cost nothing.
23. **A covered controller binds the request for all its actions**, so the products are narrowed there too. The product tab of a vendor lists the employee's countries, and its review
    tab names no product of another country (the rows of the reviews themselves are slice 5's).
24. **A figure is a figure of the orders the employee can open.** The sales of a product count its lines in those orders, so the Kuwaiti line of an order that also holds a Saudi
    line is not in the sales a Kuwaiti employee sees. Counting the lines of the employee's products whatever the order holds is the other reading (open decisions).
25. **Money that cannot be told apart by country is not shown.** The admin wallet, the top vendors by earning and the auction wallet of the dashboard are not read for a restricted
    employee; the wallet cards of a delivery man and of a vendor are left out of their pages; the vendor earning report, the wallet overview and the ratings of a delivery man, and the
    analytics screens are unavailable (a 404 like an unknown page). The wallet bonus tab and the wallet and loyalty reports of the customers are not narrowed (open decisions).
26. **The header search narrows what it hands back**, never the query that fills the cache every employee shares, and registers no middleware.
27. **The one raw sum over the table of orders** among the covered screens (the vendor tax report) is narrowed by the rule of the model (`narrowRawOrders`), and a test fails for any
    other read of an order table that does not carry the narrowing condition.
28. **`App\Http\Controllers\Admin\TransactionController` is dead code**: it reads order transactions with no scope and no admin route reaches it. A test fails the day one does.
29. **The MySQL-only statement in the constructor of the vendor tax controller** (`SET sql_mode`) is run on MySQL and MariaDB only, so that the controller, which the framework builds
    before the sign-in check of its routes, can be built on another engine. Nothing changes on MySQL and MariaDB.
30. **No language key was added by slice 3**: the refusals are plain 404s, and what is left out of a page leaves no text behind.

### What changed shape while the answer did not (switch off)

- `Product` registers one more global scope, which reads the request attributes and adds nothing; the SQL is the same.
- `ProductRepository::getFirstWhereWithoutGlobalScope` drops the `translate` scope instead of all scopes: the same SQL.
- `ProductCountry::options()` and `newProductRules()` ask the staff scope first, which answers unbound without touching the database.
- The two controllers hold one more middleware in their pipeline, which lets the request through after one configuration read.
- `Order` and `RefundRequest` register one more global scope each, which reads the request attributes and adds nothing; the SQL is the same (a test compares the statements).
- The seven order, POS and refund controllers hold one more middleware, which lets the request through after one configuration read.
- The composer of the admin layout asks the staff scope once; for a request that is not bound it does nothing.
- `StaffProductRoutes` reads an id with `StaffRouteIds` (the code that was in it, moved, so that the two registries cannot disagree).
- `OrderDetail`, `OrderTransaction` and `RefundTransaction` register one more global scope each, which reads the request attributes and adds nothing for a request that is not narrowed
  for its subject; the SQL is the same (a test compares the statements).
- The composer of the admin layout binds the request when nothing has: for the master admin and the switch off it asks nothing more; for an employee with no country, three small reads.
- `StaffCountryScope::bind` no longer probes the schema for the master admin (slices 1 and 2 probed twice on a covered screen with the switch on): fewer reads, the same answers.
- Seventeen controllers of the people, the dashboard, the reports and the tax reports hold one more middleware, which lets the request through after one configuration read.
- The dashboard hands its page one more key (`wallet_figures_hidden`), false for everyone but a restricted employee, and reads the wallets only when it is false.
- Three pages carry a condition that leaves a wallet card out for a restricted employee (the dashboard, the earning page of a delivery man, the page of a vendor).
- The search of the header calls a filter that keeps every hit when the employee is not restricted, and asks the database nothing then.

## Open owner decisions

1. Whether a restricted employee whose scope has more than one country should get a country choice on the import upload (and the sheet a country), instead of being
   refused. The refusal is the safe default.
2. Which of the out-of-slice screens are acceptable on the day the switch is turned on: until slices 4 to 6, a restricted employee with the permission of a promotion, storefront
   content, review, marketplace, integration or cost screen still sees every country's products and orders there. The surveys above list them.
3. Whether the master admin should be able to restrict **itself** (it cannot today: the commands refuse it).
4. Whether moderating seller products needs `SA` in the scope until sellers have markets, or a separate permission (assumption 7).
5. Whether an employee of one country may copy a product from the gallery into a country they do not work with (they cannot today: they cannot see it).
6. The entity dimension of GCC-14 (legal entities): recorded above, not designed here. (The intersection with the platform admin grants is built: see the section on the platform admin API.)
7. **Unattributed orders** (no line, a deleted product, a product without a country) are hidden from every restricted employee (assumption 12). Whether some role should see them
   (a finance or audit role that is not the master admin) is the owner's: it needs a permission, not a country.
8. **Mixed-country orders** are visible only to an employee who holds all their countries (assumption 13). Whether the admin POS should refuse to build one for everyone, not only
   for a restricted employee, is the owner's.
9. **Refund requests about a line of a mixed order** are decided by the country of the line (assumption 14). Whether they should wait for an employee who can open the order is the
   owner's: it changes one condition in `StaffCountryRefundScope`.
10. **The screens of a customer and of a delivery man list their orders**: decided for slice 3 and done (the orders of one customer, or of one delivery man, in the employee's
    countries, with a rule per route; the customer and the delivery man themselves belong to no country (GCC-13), so their own list is not narrowed).
11. The answer when a POS cart holds a product of another country (assumption 17): a plain 404 today. A message that tells the cashier to empty the cart is possible and tells
    them that the product belongs to another country.
12. Whether the counters of the shared layout of a restricted employee, which cost two index probes per order on every admin page (slice 3 binds the layout on every admin request),
    should be cached for a minute, or counted per country in a table the order screens keep up to date. The measure is in the section of the layout above.
13. **What a restricted employee may see of the money that cannot be told apart by country**: the admin wallet, the top vendors by earning and the auction wallet of the dashboard, the
    wallet cards of a delivery man and of a vendor, the vendor earning report, the wallet overview and the ratings of a delivery man (a review names its order), and the analytics.
    The safe default, done here, is to leave them out or answer 404. The options are: keep that; show them to an employee who holds every country; or split them by the share of the
    lines (the wallets keep no order, so there is nothing to split from).
14. **The sales of a product in a mixed order.** A figure counts the lines of the orders the employee can open (assumption 24). Counting the lines of the employee's products whatever
    the order holds would show a Kuwaiti employee the Kuwaiti line of an order that also holds a Saudi line, in a sum, and not the order.
15. **The wallet and loyalty reports and the wallet bonus tab** list customers' money, which carries no order and no country. They are not narrowed, so a restricted employee with the
    permission of a customer screen still sees every country's there.
16. **The ratings of a delivery man** are unavailable to a restricted employee until slice 5 (a review names its order, which may be another country's).
17. **The recent searches** of the header keep the hits an employee found before they were restricted. They are not filtered.

## Before the switch is turned on

1. Deploy with the switch off. Nothing changes.
2. Run the `2026_11_28_100000_create_admin_country_scopes_table` migration (additive; it never fails a deploy: the foreign key to `admins` is added only when `admins.id` is
   `BIGINT UNSIGNED` on InnoDB, and otherwise the table is created without it and one warning says why) and the products migration of GCC-14a.
3. Check that `order_details` has an index that starts with `order_id` (migration `2026_02_25_163645_add_indexes_to_order_details_table`), and that `products` has its primary key:
   the order scope probes both for every order of a list.
4. Assign the countries: `gcc:staff-country:assign`, then check with `gcc:staff-country:list`. Nobody is restricted before then.
5. Read the surveys above and accept, per slice, what still shows every country, and the open decisions about wallets (13), the sales of a mixed order (14) and the customers' wallet
   reports (15).
6. Set `GCC_STAFF_COUNTRY_SCOPES=true`, run `php artisan config:clear`.
7. Check as a restricted employee: the product list, a product of another country by its address (a 404 like an unknown id), a product add in another country (refused),
   the order list and its counters (only orders whose every line is theirs), an order of another country by its number and a refund request about another country by its number
   (a 404 like an unknown id), a POS sale with a product of another country (refused), and as the master admin that nothing changed.
   Then, as a restricted employee, in slice 3: the counters of the sidebar and the latest orders of the header on a screen outside every slice (the brand list, say), the dashboard
   (counters, earnings, no wallet card), the order report, a delivery man's earning page (no wallet cards), a vendor's page (no wallet card) and its order by number
   (`vendors/order-details/{order}/{vendor}`, a 404 for another country's), the status history of another country's order by its number (a 404), the vendor earning report and the
   analytics (404), and the search of the header for the number of another country's order (not found).
8. Find the unattributed orders (`orders` with no line, or a line whose product is gone): only the master admin and an employee with no country see them (assumption 12).
9. Measure the layout of a restricted employee on the real data (the section of the layout above shows what it cost on 60,000 orders).

Turning it off again leaves the rows in place and removes every restriction at once; turning it on restores them.

## The tests

`tests/Feature/StaffScope`: the service and the switch (including nothing asked of the database while off), the migration on every shape of `admins` MySQL can have, the three
commands, the lists and pickers and exports through the real route table, the id routes from the real route table (every route, every kind of id, foreign, unknown, junk,
lists, restock requests, the bytes of the answers), the real controllers' writes, the add form and the import, and the guard of the survey with probes that prove the scan finds
a new controller, a path and a parameter and ignores a route outside the admin group.

Slice 2: the scopes of the orders and the refund requests on the models (`StaffOrderScopeTest`: which order and refund each country sees, the unattributed ones, the SQL and the
queries a list costs), every route of the seven controllers from the real route table (`StaffOrderRoutesTest`: every kind of part, foreign, unknown, junk, lists, the cart, the
edit, the bytes of the answers, the order of the middleware, the controllers that register it), the screens through the real controllers (`StaffOrderScreensTest`,
`StaffPosAndRefundScreensTest`: lists, counters, exports, neighbours, writes, the POS order, the shared layout), and the six-country matrix
(`StaffSixCountryMatrixTest`: 34 orders and 42 refund requests built from `gcc.storefronts.countries`, and 23 scopes, the six single countries, the fifteen pairs, one triple and
all six, each checked for lists, counters, details by id, status actions, exports and the POS, with the expected answers worked out from the description of the world, plus the
master admin, an employee with no country and the switch off).

Slice 3: the layout (`StaffLayoutTest`: a screen no scope covers, who is narrowed, the statements for the master admin, an employee with no country and the switch off), the rows
that hang off an order (`StaffOrderRowScopesTest`: lines, money records and refund money records, each subject on its own, the statements of slice 2 unchanged), the people screens
(`StaffPeopleScreensTest`: the real controllers with the pages rendered to nothing, the orders of a customer, a delivery man and a vendor, an order by its number, the answers that
are the same for another country's order and for one that does not exist), the dashboard and the reports (`StaffReportScreensTest`: every statement of every covered screen that reads a
table of orders must carry the narrowing condition, **and** the figures of a restricted employee must equal the figures an unrestricted request gives over a shop pruned to their
orders, for eleven screens, and each comparison first proves that the unpruned figures differ), the header search (`StaffSearchHitsTest`, with the shared cache), the raw sum and the
analytics (`StaffTaxAndAnalyticsTest`), the matrix (`StaffSixCountryReportMatrixTest`: 34 orders, 23 scopes, 11 figures per scope, worked out as set logic), and the guard of the order
survey (`StaffOrderRouteInventoryTest`, with probes that prove the scan finds a new controller, a path and a parameter). The detector of a read that is not narrowed lets through the
rows that hang off a row a screen has already read (`where order_details.order_id in (102, 103)`, `where orders.id in (101, 102)`), which are as narrow as the rows they come from.
The pages of the real layout and of most real views are not rendered by these tests (they render to nothing), so a test reads the source of the three views that carry a condition.
