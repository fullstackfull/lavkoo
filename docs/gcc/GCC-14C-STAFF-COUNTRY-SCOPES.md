# GCC-14c: staff country scopes (slices 1 and 2: the product screens, and the order, POS and refund screens, of the admin panel)

**Status:** draft, behind a switch that ships **off**. Nothing here runs on the pilot server or on production. One additive migration, no change to what a Saudi
customer, seller or admin sees or is answered while the switch is off, and nothing narrows an employee until somebody assigns them a country.

Decision behind it: **GCC-14**, one staff login with multi-country scopes: keep the existing role and module authorization and add data and action scopes,
enforced in the backend (`docs/gcc/DECISIONS.md`). The owner's rule for every new GCC behaviour applies: default **off**, and the Saudi behaviour and the APIs stay
byte-identical while it is off.

Slice 1 enforces the scope on **the product screens of the admin panel**. Slice 2 enforces it on **the order, POS and refund screens of the admin panel**: an order follows
the countries of its lines, a refund request the country of the line it is about. Customers, delivery men, reports, the dashboard, the vendor panel, the seller API staff and
the legal entities are out of slices 1 and 2. They are listed below, with the rule that would apply, so that the next slice starts from the table.

## What the switch does

`gcc.staff.country_scopes` (env `GCC_STAFF_COUNTRY_SCOPES`, default `false`, in `config/gcc.php`).

- **Off (the default):** no table lookup, no schema probe and no extra query is made for any request, and every query and answer is the one of today.
- **On, and both the `admin_country_scopes` table and `products.storefront_country` exist:** an employee who has been given countries works with the products of those
  countries only, in the product screens of the admin panel, and with the orders, POS sales and refund requests of those countries only, in their screens.
- **On, and either is missing** (a migration has not run): the switch acts as off. Nothing mentions the missing table or column and nothing answers HTTP 500.

The switch does **not** depend on `gcc.storefronts.enabled`: the staff scope narrows what an employee sees in the panel, whether or not a storefront of that country
is open yet (a country's catalogue is prepared before its storefront opens, GCC-14a). While storefronts are off nothing offers a country when a product is added, so
what a restricted employee can add is covered in the table of the add form below.

`App\Services\Staff\StaffCountryScope` is the only place that knows the switch, the table and how rows become an employee's scope. The employee's countries are
resolved once per request, by the middleware of the product screens and of the order screens, and **bound to that request** (its attributes): never to the container, a static or the cache, so
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
The counters are the cost: a full scan that the scope makes about three times dearer, which the sidebar of the layout pays on every screen of slices 1 and 2 for a restricted
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

The rules of the 48 routes:

| Route | Action | Rule | Permission |
|---|---|---|---|
| `GET admin/orders/list/{status}` | `OrderController::index` | `list:orders` | module:orders |
| `GET admin/orders/export-excel/{status}` | `OrderController::exportList` | `list:orders` | module:orders |
| `GET admin/orders/generate-invoice/{id}` | `OrderController::generateInvoice` | `order.route:id` | none |
| `GET admin/orders/details/{id}` | `OrderController::getView` | `order.route:id` + `list:orders` | module:orders |
| `POST admin/orders/address-update` | `OrderController::updateAddress` | `order.input:order_id` | module:orders |
| `POST admin/orders/update-deliver-info` | `OrderController::updateDeliverInfo` | `order.input:order_id` | module:orders |
| `GET admin/orders/add-delivery-man/{order_id}/{d_man_id}` | `OrderController::addDeliveryMan` | `order.route:order_id` | module:orders |
| `POST admin/orders/amount-date-update` | `OrderController::updateAmountDate` | `order.input:order_id` | module:orders |
| `GET admin/orders/customers` | `OrderController::getCustomers` | `none` | module:orders |
| `POST admin/orders/payment-status` | `OrderController::updatePaymentStatus` | `order.input:id` | module:orders |
| `GET admin/orders/inhouse-order-filter` | `OrderController::filterInHouseOrder` | `none` | module:orders |
| `POST admin/orders/digital-file-upload-after-sell` | `OrderController::uploadDigitalFileAfterSell` | `line.input:order_id` | module:orders |
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
  header. On every screen where the request is bound (the product, order, POS and refund screens) those read the employee's countries only. On the screens that are out of both
  slices nothing is bound, so they still show every country's counts: binding the layout on every admin page is the first step of slice 3.

## What slice 3 enforces (the shared layout, the people, the dashboard and the reports)

The rules of the screens of slice 3:

<!-- slice-3-rules-start -->
| Route | Action | Rule | Permission |
|---|---|---|---|
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
<!-- slice-3-rules-end -->

## The survey: every admin route that reads products or names one

`tests/Feature/StaffScope/StaffProductRouteInventoryTest.php` walks the real route table of the admin panel and fails for an admin controller that reads products or names
one and is neither scoped nor listed in `tests/Support/StaffProductInventory.php` with a reason (and, when it is out of slice 3, the slice that takes it). The table is
exact: an entry whose controller no longer reads products fails, so does an entry without a reason, every route of a scoped controller must have a rule in the registry of its
slice, and the scoped controllers are exactly the ones that have rules. A second test keeps this document in step with the table and the routes. The scan reads the source of
each action, its path and its parameters, and the file of each controller (so a route that delegates to a helper of its controller is not missed): the repository, the model, the
product manager, the products table, and a product id in a request.

**49 controllers: 2 scoped in slice 1, 5 scoped in slice 2, 1 scoped in slice 3, 6 not needed, 35 out of slice 3.**
**153 routes read or name a product: 42 scoped in slice 1, 16 scoped in slice 2, 1 scoped in slice 3, 22 not needed, 72 out of slice 3.**

The slices that follow slice 3 (the people, the dashboard, the reports and the shared layout), in the order proposed:

| Slice | What it takes |
|---|---|
| slice 4 | promotions and storefront content: deals, banners, themes, the SEO pages and the site map |
| slice 5 | vendors, reviews and the marketplace operations |
| slice 6 | integrations and costs: sales channels, fulfilment providers and the operational costs |

| Controller | Admin routes | Routes that read or name a product | Status | Why |
|---|---:|---:|---|---|
| `Admin\Product\ProductController` | 38 | 38 | scoped in slice 1 | The product screens: lists, counters, pickers, exports, the add and edit forms, and every action on a product. Every action has a rule in StaffProductRoutes. |
| `Admin\Marketplace\ProductModerationController` | 4 | 4 | scoped in slice 1 | Approve, reject or suspend a seller product by its id, singly or in bulk, and read its history: an id route like the product screens. |
| `Admin\Product\BrandController` | 9 | 1 | not needed | Reads the products of a brand only to refuse deleting a brand that is in use. The answer must count every country's products, or a brand a product of another country uses could be deleted. |
| `Admin\Product\CategoryController` | 7 | 2 | not needed | As the brands: the products of a category are read only to refuse deleting or moving a category that is in use, and that count must include every country. |
| `Admin\Settings\BusinessSettingsController` | 15 | 2 | not needed | The product settings page is a setting of the shop (brands, digital products, approval). It reads no product row. |
| `Modules\AI\Admin\AIProductController` | 8 | 8 | not needed | Writes a title, a description or the SEO text of a product from what is typed in the form. It reads no product row. |
| `Modules\AI\API\V3\AIAuctionProductController` | 8 | 8 | not needed | As the product text generation, for the auction add-on, whose products are not in the products table. |
| `Admin\SalesChannels\ChannelAccountOperationsController` | 7 | 1 | not needed | The only route that mentions a product is the Noon product group of a channel account, a setting of the account. It reads no product row. |
| `Admin\Settings\SeoTranslationController` | 3 | 0 | out of slice 3 (slice 4) | Lists the products whose SEO text can be translated: every country's. |
| `Admin\Promotion\FlashDealController` | 10 | 4 | out of slice 3 (slice 4) | The deal pages pick products and list the ones in a deal: every country's. A promotion has no country yet (GCC-17). |
| `Admin\Promotion\DealOfTheDayController` | 6 | 4 | out of slice 3 (slice 4) | Picks the product of the day among every country's products. |
| `Admin\Promotion\ClearanceSaleController` | 11 | 8 | out of slice 3 (slice 4) | Picks, lists and removes clearance products of every country. |
| `Admin\Promotion\MostDemandedController` | 6 | 0 | out of slice 3 (slice 4) | Picks the products of the most demanded section among every country's. |
| `Admin\Promotion\BannerController` | 8 | 2 | out of slice 3 (slice 4) | A banner can point at a product, picked among every country's. |
| `Admin\Settings\InhouseShopController` | 6 | 1 | out of slice 3 (slice 4) | The in-house shop page lists the in-house products of every country. |
| `Admin\Settings\ThemeBuilderController` | 25 | 2 | out of slice 3 (slice 4) | The pickers of the theme builder list and name products of every country. |
| `Admin\Settings\SeoHealthController` | 1 | 1 | out of slice 3 (slice 4) | Audits the SEO of the products a storefront shows, of every country. |
| `Admin\Settings\SiteMapController` | 6 | 0 | out of slice 3 (slice 4) | The site map lists the active products of every country. |
| `Admin\Order\OrderController` | 17 | 1 | scoped in slice 2 | Every order screen: the lists per status with their filters and counters, the exports, the details with the neighbours of an order, and every action on an order (status, payment, address, courier, invoice, bulk change). Every action has a rule in StaffOrderRoutes. |
| `Admin\Order\OrderEditController` | 7 | 6 | scoped in slice 2 | The order edit: the product picker is narrowed by the product scope, the order is looked up by id and a product of another country is refused. Every action has a rule in StaffOrderRoutes. |
| `Admin\POS\POSController` | 6 | 5 | scoped in slice 2 | The POS grid and search offer the products of the employee's countries (the product scope), and a product of another country is refused by id. Every action has a rule in StaffOrderRoutes. |
| `Admin\POS\CartController` | 9 | 3 | scoped in slice 2 | The POS cart takes, prices and counts a product by id: a product of another country is refused. Every action has a rule in StaffOrderRoutes. |
| `Admin\POS\POSOrderController` | 4 | 1 | scoped in slice 2 | Places a POS order only when every product of the cart is the employee's, and reads a POS order by id. Every action has a rule in StaffOrderRoutes. |
| `Admin\DashboardController` | 5 | 2 | out of slice 3 (slice 3) | Counters of products, stock and restock requests across every country. |
| `Admin\ReportController` | 6 | 1 | out of slice 3 (slice 3) | The earning and product counts of the reports are not split by country. |
| `Admin\ProductReportController` | 2 | 2 | out of slice 3 (slice 3) | The all-products report and its export list every country's products. |
| `Admin\ProductStockReportController` | 2 | 2 | out of slice 3 (slice 3) | The stock report and its export list every country's products. |
| `Admin\ProductWishlistReportController` | 2 | 2 | out of slice 3 (slice 3) | The wishlist report and its export list every country's products. |
| `Admin\InhouseProductSaleController` | 1 | 1 | out of slice 3 (slice 3) | The in-house sales report lists every country's products. |
| `Admin\TransactionReportController` | 5 | 1 | out of slice 3 (slice 3) | The transaction report counts the products of every country. |
| `Admin\VendorProductSaleReportController` | 2 | 1 | out of slice 3 (slice 3) | The vendor sales report lists the products of every country. |
| `Admin\Product\ReviewController` | 7 | 3 | out of slice 3 (slice 5) | The reviews list and its product filter show the reviews of every country's products. |
| `Admin\Vendor\VendorController` | 17 | 1 | scoped in slice 3 | The request is bound for the whole controller (it lists orders), so a vendor's product tab lists the products of the employee's countries only. Every action has a rule in StaffOrderRoutes. |
| `Admin\Marketplace\BatchController` | 3 | 2 | out of slice 3 (slice 5) | Batches of a marketplace product, picked by id from any country. |
| `Admin\Marketplace\BrandRegistryController` | 6 | 0 | out of slice 3 (slice 5) | Counts the products of each brand across every country. |
| `Admin\Marketplace\CommissionRuleController` | 5 | 1 | out of slice 3 (slice 5) | A commission rule can name a product of any country. |
| `Admin\Marketplace\InventoryAdjustmentController` | 2 | 2 | out of slice 3 (slice 5) | Adjusts the stock of a product by id, of any country. |
| `Admin\Marketplace\PurchaseOrderController` | 7 | 1 | out of slice 3 (slice 5) | A purchase order line names a product of any country. |
| `Admin\Marketplace\ReturnLogisticsController` | 5 | 1 | out of slice 3 (slice 5) | A return names a product of any country. |
| `Admin\Marketplace\WarehouseController` | 5 | 3 | out of slice 3 (slice 5) | Places and transfers the stock of a product by id, of any country. |
| `Admin\Fulfillment\M5aznProductsController` | 3 | 3 | out of slice 3 (slice 6) | Imports provider items as products in the default country, and lists the ones already mapped. |
| `Admin\Fulfillment\TorodMatchingController` | 13 | 4 | out of slice 3 (slice 6) | Matches provider items to the products of the default storefront's country when codes are per country; otherwise every country's. |
| `Admin\SalesChannels\ChannelProductController` | 8 | 8 | out of slice 3 (slice 6) | Links a channel listing to a product picked among every country's. |
| `Admin\SalesChannels\ChannelListingController` | 9 | 2 | out of slice 3 (slice 6) | Drafts and publishes the listing of a product by id, of any country. |
| `Admin\SalesChannels\ChannelInventoryController` | 2 | 1 | out of slice 3 (slice 6) | Lists the stock a channel holds for the products of every country. |
| `Admin\SalesChannels\ChannelOrderController` | 7 | 1 | out of slice 3 (slice 6) | A channel order's lines are matched to products of any country. |
| `Admin\SalesChannels\ChannelRoutingController` | 4 | 1 | out of slice 3 (slice 6) | A routing rule can name a product of any country. |
| `Admin\OperationalCosts\ProductCostController` | 4 | 4 | out of slice 3 (slice 6) | The cost layer picker offers the products of every country. |
| `Admin\OperationalCosts\ReturnController` | 2 | 1 | out of slice 3 (slice 6) | The returns of the operational costs name products of any country. |

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
| `Admin\Vendor\VendorController` | `GET admin/vendors/order-details/{order_id}/{vendor_id}` |

### Routes that read or name a product, of the controllers that are out of slice 3

A restricted employee who has the permission of one of these screens still sees every country's products there. Each is named for the slice that takes it.

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
| `Admin\DashboardController` | `GET admin/dashboard`<br>`GET admin/dashboard/real-time-activities` |
| `Admin\ReportController` | `GET admin/report/vendor-earning` |
| `Admin\ProductReportController` | `GET admin/report/all-product`<br>`GET admin/report/all-product-excel` |
| `Admin\ProductStockReportController` | `GET admin/stock/product-stock`<br>`GET admin/stock/product-stock-export` |
| `Admin\ProductWishlistReportController` | `GET admin/stock/product-in-wishlist`<br>`GET admin/stock/wishlist-product-export` |
| `Admin\InhouseProductSaleController` | `GET admin/report/inhouse-product-sale` |
| `Admin\TransactionReportController` | `GET admin/transaction/order-transaction-list` |
| `Admin\VendorProductSaleReportController` | `GET admin/report/vendor-report` |
| `Admin\Product\ReviewController` | `GET admin/reviews/list`<br>`GET admin/reviews/export`<br>`GET\|POST\|PUT\|PATCH\|DELETE\|OPTIONS admin/reviews/search-product` |
| `Admin\Marketplace\BatchController` | `GET admin/marketplace/batches`<br>`POST admin/marketplace/batches` |
| `Admin\Marketplace\BrandRegistryController` |  |
| `Admin\Marketplace\CommissionRuleController` | `GET admin/marketplace/commission-rules` |
| `Admin\Marketplace\InventoryAdjustmentController` | `GET admin/marketplace/inventory-adjustments`<br>`POST admin/marketplace/inventory-adjustments/adjust` |
| `Admin\Marketplace\PurchaseOrderController` | `POST admin/marketplace/purchase-orders` |
| `Admin\Marketplace\ReturnLogisticsController` | `POST admin/marketplace/returns` |
| `Admin\Marketplace\WarehouseController` | `GET admin/marketplace/warehouses`<br>`POST admin/marketplace/warehouses/place`<br>`POST admin/marketplace/warehouses/transfer` |
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
| `Admin\AdvancedSearchController` | out of slice 3 (slice 3) | The header search of the classic layout finds products of every country through AdvancedSearchService. |
| `Admin\V2AdvancedSearchController` | out of slice 3 (slice 3) | The header search of the new layout finds products of every country through the same service. |
| `Admin\Commerce\CollectionController` | out of slice 3 (slice 4) | The preview of a collection resolves its products through CollectionResolver. |
| `Admin\Order\RefundController` | scoped in slice 2 | A refund request follows the country of the product of the order line it is about. The refund lists, their exports, the details and the decision on a request go through the scope of the refund requests and the rules of StaffOrderRoutes. |
| `Admin\Telemetry\AnalyticsController` | out of slice 3 (slice 3) | The analytics export by product counts the products of every country. |
| `Admin\Order\DeliverySyriaDispatchController` | scoped in slice 2 | Dispatches an order to the Delivery Syria courier by its id: an order route like the order screens (StaffOrderRoutes). It reads no product. |
| `Admin\Customer\CustomerController` | scoped in slice 3 | Lists the orders and refund requests of a customer and counts the orders of each customer (StaffOrderRoutes). It reads no product. |
| `Admin\Deliveryman\DeliveryManController` | scoped in slice 3 | Lists the orders, the earnings and the status history of a delivery man (StaffOrderRoutes). It reads no product. |

## The survey: every admin route that reads orders or names one

`tests/Feature/StaffScope/StaffOrderRouteInventoryTest.php` is the same guard for orders, their lines, their money records, refunds and the earnings and counters made of them.
It walks the real route table and fails for an admin controller that reads any of them, or names an order in its path or its parameters, and is neither scoped nor listed in
`tests/Support/StaffOrderInventory.php` with a reason (and, when it is out of slice 3, the slice that takes it, or `owner` when what it shows cannot be told apart by country).
The scan reads the models, the repositories, the tables, the relations, the services that hold the sums, the wallets, and an order id in a request, in the source of each action and
in the file of each controller. The scoped controllers are exactly the ones that have rules in `StaffOrderRoutes`, and a test keeps this document in step.

**33 controllers: 6 scoped in slice 2, 3 scoped in slice 3, 4 not needed, 20 out of slice 3.**
**113 routes read or name an order: 35 scoped in slice 2, 17 scoped in slice 3, 13 not needed, 48 out of slice 3.**

| Slice | What it takes |
|---|---|
| slice 3 | the rest of the order reads, the shared layout, the dashboard and the reports |
| slice 4 | promotions and storefront content: deals, banners, themes, the SEO pages and the site map |
| slice 5 | vendors, reviews and the marketplace operations |
| slice 6 | integrations and costs: sales channels, fulfilment providers and the operational costs |
| owner | figures that cannot be told apart by country, until the owner says what an employee may see |

| Controller | Admin routes | Routes that read or name an order | Status | Why |
|---|---:|---:|---|---|
| `Admin\Order\OrderController` | 17 | 17 | scoped in slice 2 | Every order screen: the lists per status with their filters and counters, the exports, the details with the neighbours of an order, and every action on an order. Every action has a rule in StaffOrderRoutes. |
| `Admin\Order\OrderEditController` | 7 | 7 | scoped in slice 2 | The order edit: the order is looked up by id, the product picker is narrowed by the product scope and a product of another country is refused. Every action has a rule in StaffOrderRoutes. |
| `Admin\Order\DeliverySyriaDispatchController` | 1 | 1 | scoped in slice 2 | Dispatches an order to the Delivery Syria courier by its id: an order route like the order screens. Every action has a rule in StaffOrderRoutes. |
| `Admin\Order\RefundController` | 4 | 4 | scoped in slice 2 | The refund requests: the lists, their exports, the details and the decision follow the order line each request is about. Every action has a rule in StaffOrderRoutes. |
| `Admin\POS\POSController` | 6 | 2 | scoped in slice 2 | The POS grid lists the products of the employee's countries and the filter of the POS orders reads the orders of the scope. Every action has a rule in StaffOrderRoutes. |
| `Admin\POS\POSOrderController` | 4 | 4 | scoped in slice 2 | Places a POS order only when every product of the cart is the employee's, and reads a POS order by id. Every action has a rule in StaffOrderRoutes. |
| `Admin\Customer\CustomerController` | 15 | 2 | scoped in slice 3 | The list of customers counts the orders of each, the screen of one customer lists their orders and counts their refund requests, and the export holds their orders: all in the employee's countries. A customer belongs to no country, so the list of customers is not narrowed. Every action has a rule in StaffOrderRoutes. |
| `Admin\Deliveryman\DeliveryManController` | 15 | 8 | scoped in slice 3 | The orders, the earnings per order and the status history of a delivery man, and the status history of an order by its number, in the employee's countries. The wallet overview and the ratings of a delivery man are unavailable to a restricted employee. Every action has a rule in StaffOrderRoutes. |
| `Admin\Vendor\VendorController` | 17 | 7 | scoped in slice 3 | The orders of a vendor and their export, an order read by its number, the money records of a vendor and the order counts of the list of vendors, in the employee's countries. The request is bound for the whole controller, so the product tab of a vendor lists the employee's countries too. Every action has a rule in StaffOrderRoutes. |
| `Admin\DashboardController` | 5 | 5 | out of slice 3 (slice 3) | The dashboard counters, earnings and graphs, the top lists and the real-time poller read orders, their money records and wallets of every country. |
| `Admin\OrderReportController` | 3 | 3 | out of slice 3 (slice 3) | The order report, its totals and its charts sum the orders of every country. |
| `Admin\ReportController` | 6 | 5 | out of slice 3 (slice 3) | The earning reports sum the orders, their money records and refunds of every country, and the vendor earning report reads the wallets of the vendors. |
| `Admin\TransactionReportController` | 5 | 5 | out of slice 3 (slice 3) | The order transaction report lists and sums the money records of the orders of every country. |
| `Admin\ExpenseTransactionReportController` | 4 | 4 | out of slice 3 (slice 3) | The expense transaction report lists and sums the discounts and delivery expenses of the orders of every country. |
| `Admin\Report\RefundTransactionController` | 3 | 3 | out of slice 3 (slice 3) | The refund transaction report lists the money records of the refunds of every country. |
| `Admin\VendorProductSaleReportController` | 2 | 2 | out of slice 3 (slice 3) | The vendor sales report sums the orders and refunds of every country. |
| `Admin\ProductReportController` | 2 | 2 | out of slice 3 (slice 3) | The all-products report sums the sales of the order lines of every country. |
| `Admin\Telemetry\AnalyticsController` | 7 | 1 | out of slice 3 (slice 3) | The analytics export by product counts the revenue of the order lines of every country. |
| `Modules\TaxModule\Admin\Reports\AdminTaxReportController` | 4 | 0 | out of slice 3 (slice 3) | The tax report sums the taxes of the orders of every country. |
| `Modules\TaxModule\Admin\Reports\VendorTaxReportController` | 4 | 2 | out of slice 3 (slice 3) | The vendor tax reports sum the orders and taxes of every country. |
| `Admin\V2AdvancedSearchController` | 1 | 0 | out of slice 3 (slice 3) | The header search of the new layout finds an order by its number, whichever country it is in. |
| `Admin\Product\ProductController` | 38 | 2 | not needed | Counts the order lines of a product the employee works with: the lines of their own products, narrowed by the product scope of slice 1. It lists no order. |
| `Admin\Settings\BusinessSettingsController` | 15 | 2 | not needed | The refund setup page is a setting of the shop. It reads no order. |
| `Admin\Settings\OrderSettingsController` | 2 | 2 | not needed | The order settings page is a setting of the shop. It reads no order. |
| `Admin\Marketplace\PurchaseOrderController` | 7 | 7 | not needed | Purchase orders to suppliers are not customer orders: they have no order, no order line and no country of their own. |
| `Admin\Settings\InhouseShopController` | 6 | 1 | out of slice 3 (slice 4) | The in-house shop page counts the in-house orders of every country. |
| `Admin\Marketplace\FulfillmentController` | 4 | 2 | out of slice 3 (slice 5) | The marketplace fulfilments are shipments of orders of every country, picked by order number. |
| `Admin\Marketplace\ReturnLogisticsController` | 5 | 1 | out of slice 3 (slice 5) | A marketplace return names an order of any country. |
| `Admin\Product\ReviewController` | 7 | 1 | out of slice 3 (slice 5) | The reviews list reads the order behind a review, of any country. |
| `Admin\Fulfillment\ProviderFulfillmentController` | 5 | 1 | out of slice 3 (slice 6) | The provider fulfilments are shipments of orders of every country. |
| `Admin\SalesChannels\ChannelOrderController` | 7 | 7 | out of slice 3 (slice 6) | A channel order is mapped to an order of any country. |
| `Admin\OperationalCosts\ProfitabilityController` | 3 | 1 | out of slice 3 (slice 6) | The profitability of an order of any country, with its costs. |
| `Admin\MyFatoorah\SettingsController` | 10 | 2 | out of slice 3 (slice 6) | Lists the payment attempts and refunds of the gateway, which belong to orders of every country, and acts on an attempt by its id. |

### Routes that read or name an order, of the controllers scoped in slice 3

| Controller | Routes that read or name an order |
|---|---|
| `Admin\Customer\CustomerController` | `GET admin/customer/view/{user_id}`<br>`GET admin/customer/order-list-export/{user_id}` |
| `Admin\Deliveryman\DeliveryManController` | `GET admin/delivery-man/list`<br>`GET admin/delivery-man/export`<br>`GET admin/delivery-man/earning-statement-overview/{id}`<br>`GET admin/delivery-man/order-wise-earning/{id}`<br>`GET admin/delivery-man/order-list-by-filer/{id}`<br>`GET admin/delivery-man/order-history-log/{id}`<br>`GET admin/delivery-man/order-history-log-export/{id}`<br>`GET admin/delivery-man/ajax-order-status-history/{order}` |
| `Admin\Vendor\VendorController` | `GET admin/vendors/list`<br>`POST admin/vendors/add`<br>`GET admin/vendors/order-list-export/{vendor_id}`<br>`GET admin/vendors/export`<br>`GET admin/vendors/order-details/{order_id}/{vendor_id}`<br>`GET admin/vendors/view/{id}/{tab?}`<br>`POST admin/vendors/withdraw-status/{id}` |

### Routes that read or name an order, of the controllers that are not needed

| Controller | Routes that read or name an order |
|---|---|
| `Admin\Product\ProductController` | `GET admin/products/view/{addedBy}/{id}`<br>`GET admin/products/export-excel/{type}` |
| `Admin\Settings\BusinessSettingsController` | `GET admin/business-settings/refund-setup`<br>`POST admin/business-settings/refund-setup` |
| `Admin\Settings\OrderSettingsController` | `GET admin/business-settings/order-settings/index`<br>`POST admin/business-settings/order-settings/update-order-settings` |
| `Admin\Marketplace\PurchaseOrderController` | `GET admin/marketplace/purchase-orders`<br>`GET admin/marketplace/purchase-orders/create`<br>`POST admin/marketplace/purchase-orders`<br>`GET admin/marketplace/purchase-orders/{id}`<br>`POST admin/marketplace/purchase-orders/{id}/place`<br>`POST admin/marketplace/purchase-orders/{id}/receive`<br>`POST admin/marketplace/purchase-orders/{id}/cancel` |

### Routes that read or name an order, of the controllers that are out of slice 3

A restricted employee who has the permission of one of these screens still sees the orders of every country there. Each is named for the slice that takes it, or for the owner.

| Controller | Routes that read or name an order |
|---|---|
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
| `Admin\V2AdvancedSearchController` |  |
| `Admin\Settings\InhouseShopController` | `GET admin/business-settings/inhouse-shop` |
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
| `Admin\AdvancedSearchController` | out of slice 3 (slice 3) | The header search of the classic layout finds an order by its number through AdvancedSearchService, whichever country it is in. |
| `Admin\InhouseProductSaleController` | out of slice 3 (slice 3) | The in-house sales report counts the sales of the order lines of every country through the product repository. |
| `Admin\Customer\CustomerWalletController` | out of slice 3 (owner) | The wallet report and the wallet transactions of the customers carry no order and no country, so they cannot be told apart by country. |
| `Admin\Customer\CustomerLoyaltyController` | out of slice 3 (owner) | The loyalty point transactions carry no order and no country, so they cannot be told apart by country. |
| `Admin\Marketplace\SellerScorecardController` | out of slice 3 (slice 5) | The scorecard of a seller is made of its orders of every country, through SellerScorecardService. |
| `Admin\Marketplace\SettlementController` | out of slice 3 (slice 5) | The settlements of the sellers are made of their orders of every country, through the vendor ledger. |
| `Admin\OperationalCosts\OverviewController` | out of slice 3 (slice 6) | The overview of the operational costs aggregates the profit of the orders of every country. |
| `Admin\SalesChannels\ChannelAccountOperationsController` | out of slice 3 (slice 6) | Synchronises the orders of a sales channel account, which map to orders of any country. |
| `Admin\POS\CartController` | scoped in slice 2 | The POS cart takes, prices and counts a product by id and reads no order (StaffOrderRoutes). |

## Out of slices 1 and 2: the rule that would apply

| Area | Rule for the slice that takes it |
|---|---|
| **Orders** | Done in slice 2 on the order, POS and refund screens (above). The same rule applies wherever another screen lists orders. |
| **Refunds and returns** | The refund requests follow the order line they are about: done in slice 2. The returns of the marketplace (`ReturnLogisticsController`) are slice 5. |
| **Customers** | One identity across countries (GCC-13): a customer belongs to no country. What belongs to a country is their orders, wallets and addresses, which follow the rule above; a customer's list is not narrowed, their transactions are. The screen of one customer lists their orders: see the open decisions. |
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
   employee, and the storefront shows a country's own products only. Checking the ids in `ProductService::getBoughtTogetherIds` is the fix, and slice 4's.
10. **The counters and the lists of the dashboard** are not narrowed (slice 3). The shared layout's are narrowed wherever the request is bound (slices 1 and 2).
    **No product screen fills a shared cache from a product query**: the caches of product lists are built by storefront requests, which are never bound, so a narrowed
    answer cannot be served to someone else.
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

### What changed shape while the answer did not (switch off)

- `Product` registers one more global scope, which reads the request attributes and adds nothing; the SQL is the same.
- `ProductRepository::getFirstWhereWithoutGlobalScope` drops the `translate` scope instead of all scopes: the same SQL.
- `ProductCountry::options()` and `newProductRules()` ask the staff scope first, which answers unbound without touching the database.
- The two controllers hold one more middleware in their pipeline, which lets the request through after one configuration read.
- `Order` and `RefundRequest` register one more global scope each, which reads the request attributes and adds nothing; the SQL is the same (a test compares the statements).
- The seven order, POS and refund controllers hold one more middleware, which lets the request through after one configuration read.
- The composer of the admin layout asks the staff scope once; for a request that is not bound it does nothing.
- `StaffProductRoutes` reads an id with `StaffRouteIds` (the code that was in it, moved, so that the two registries cannot disagree).

## Open owner decisions

1. Whether a restricted employee whose scope has more than one country should get a country choice on the import upload (and the sheet a country), instead of being
   refused. The refusal is the safe default.
2. Which of the out-of-slice screens are acceptable on the day the switch is turned on: until slices 3 to 6, a restricted employee with the permission of a promotion, report,
   dashboard, customer, delivery man or vendor screen still sees every country's products and orders there. The survey above lists them.
3. Whether the master admin should be able to restrict **itself** (it cannot today: the commands refuse it).
4. Whether moderating seller products needs `SA` in the scope until sellers have markets, or a separate permission (assumption 7).
5. Whether an employee of one country may copy a product from the gallery into a country they do not work with (they cannot today: they cannot see it).
6. The entity dimension of GCC-14 (legal entities) and the intersection with the platform admin grants: recorded above, not designed here.
7. **Unattributed orders** (no line, a deleted product, a product without a country) are hidden from every restricted employee (assumption 12). Whether some role should see them
   (a finance or audit role that is not the master admin) is the owner's: it needs a permission, not a country.
8. **Mixed-country orders** are visible only to an employee who holds all their countries (assumption 13). Whether the admin POS should refuse to build one for everyone, not only
   for a restricted employee, is the owner's.
9. **Refund requests about a line of a mixed order** are decided by the country of the line (assumption 14). Whether they should wait for an employee who can open the order is the
   owner's: it changes one condition in `StaffCountryRefundScope`.
10. **The screens of a customer and of a delivery man list their orders** and are not covered by slice 2 (the survey above). Narrowing them is the same scope on a list (the orders of
    one customer, or of one delivery man, in the employee's countries), with a rule per route; the customer and the delivery man themselves belong to no country (GCC-13), so
    their own list is not narrowed. Whether to do it in slice 3 or to accept it until then is the owner's.
11. The answer when a POS cart holds a product of another country (assumption 17): a plain 404 today. A message that tells the cashier to empty the cart is possible and tells
    them that the product belongs to another country.
12. Whether the counters of the shared layout of a restricted employee, which cost two index probes per order on every admin page, should be cached for a minute, or counted per
    country in a table the order screens keep up to date.

## Before the switch is turned on

1. Deploy with the switch off. Nothing changes.
2. Run the `2026_11_28_100000_create_admin_country_scopes_table` migration (additive; it never fails a deploy: the foreign key to `admins` is added only when `admins.id` is
   `BIGINT UNSIGNED` on InnoDB, and otherwise the table is created without it and one warning says why) and the products migration of GCC-14a.
3. Check that `order_details` has an index that starts with `order_id` (migration `2026_02_25_163645_add_indexes_to_order_details_table`), and that `products` has its primary key:
   the order scope probes both for every order of a list.
4. Assign the countries: `gcc:staff-country:assign`, then check with `gcc:staff-country:list`. Nobody is restricted before then.
5. Read the survey above and accept, per slice, what still shows every country.
6. Set `GCC_STAFF_COUNTRY_SCOPES=true`, run `php artisan config:clear`.
7. Check as a restricted employee: the product list, a product of another country by its address (a 404 like an unknown id), a product add in another country (refused),
   the order list and its counters (only orders whose every line is theirs), an order of another country by its number and a refund request about another country by its number
   (a 404 like an unknown id), a POS sale with a product of another country (refused), and as the master admin that nothing changed.
8. Find the unattributed orders (`orders` with no line, or a line whose product is gone): only the master admin and an employee with no country see them (assumption 12).

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
