# Ownership sweep: routes that act on an order, a cart or a coupon the caller does not own (2026-11-18)

Sanitized. No data of a person, no secret and no production system is named; every case below was run on a test database built by the tests, with made-up customers, guests and vendors. **Nothing here is fixed in `main`, and nothing was run against a live server.** The tracker requirements that depend on it are I1-11 (a seller or vendor sees only their own coupons), the security set of axis M, and the P0 hotfix branches named below. A fix that is only in this document is not a fix: each item says where its fix exists, or that it does not.

How these were found: three independent reviews of tests that were written for something else (the stop-journey tests of an order placed before a stop, the coupon pin tests, the due-payment scope fix) each stopped at a route that answered for someone else's data. The agents that found them left the application alone and listed the route, the case, the status and the body.

## A. Edit-order due payment (the four `POST /api/v1/edit-order/due-payment-*` routes, `OrderEditController`)

**Status: fixed on `gcc/p0-api-edit-order-due-payment-ownership` (lavkoo #21, `2fa52614`, 21 tests), not in `main`.** The group is `apiGuestCheck`, which lets through any request that carries a guest id; the actions loaded `Order::where('id', request)` with no owner check. A second fix with 53 tests that follows the rule of the neighbouring order routes (a customer by id and not a guest, a guest by the guest id and `is_guest = 1`) was written on `gcc/p1-security-due-payment-scope` and is **not pushed**, because #21 already holds the fix; compare the two before merging either.

Found while checking whose wallet pays, **not fixed anywhere**:

| | Severity | What |
|---|---|---|
| F1 | high | `POST /api/v1/edit-order/due-payment-by-wallet` takes the payer from `Helpers::getCustomerInformation()`, which returns the request **input** `user` when no guard user exists. A request with no token, a guest id and a body `{"user": {"id": 22, "wallet_balance": 99999}}` answers 200 "Payment Successful": **customer 22's real wallet is debited**, the order is marked paid, a wallet transaction is written for 22, the admin's pending amount moves. The balance check runs on the forged array. Suggested: take the payer from `auth('api')->user()` only. The same helper feeds the delivery-man reviews, `CustomerController.php:505`, `OrderController.php` (three places), `CartManager.php:802`, `CartController.php:195` and the web `Customer/PaymentController.php:582`; none was checked one by one. |
| F2 | medium | The same route marks an order **paid for nothing** when nothing is due (the owner calls it with a due amount of 0: 200, `payment_status = paid`, a wallet transaction with debit 0). A cash-on-delivery order can be shown as paid. A live financial rule: left alone. |
| F3 | limit | Guest ownership is only the guest id, a sequential integer. Anyone can send any number, so the fix removes "any order for anyone", not "any guest order for anyone who guesses the number". A real guard needs a secret or a signed token (a design decision). |

## B. Other routes that act on an object by its id, found by the sweep (not fixed)

All confirmed by running them, except B4 to B6 which were read.

| | Route | What |
|---|---|---|
| B1 | `POST /api/v1/customer/order/again` (`auth:api`) | `OrderManager::generateOrderAgain` reads `OrderDetail::where(order_id, request)` with no owner: customer 21 puts **what customer 22 bought** into their own cart (for a pharmacy: medication purchases). |
| B2 | `POST /api/v1/cart/select-cart-items` (`apiGuestCheck`) | `Cart::whereIn(id, ids)->update(is_checked)`: a guest unchecks a line of someone else's cart; the victim's checkout loses the item. |
| B3 | `POST /api/v1/shipping-method/choose-for-order` (`apiGuestCheck`) | upserts `cart_shippings` by the request's cart group id with no check that the group is the caller's: a guest writes a shipping cost into someone else's cart group. |
| B4 | `POST /api/v1/cart/get-merge-guest-cart` | the guest id comes from the request: any signed-in customer moves any guest's cart into their own (F3 applies). |
| B5 | `GET /api/v1/customer/order/digital-product-download/{id}`, `...-otp-verify`, `POST ...-otp-resend` (`apiGuestCheck`) | the order line is not tied to the caller: anyone with a guest id can make the system send a one-time code by SMS or e-mail to the owner of any paid order line, and the code is `rand(1000, 9999)` with no attempt limit in the code, so the paid file of another customer can be reached by brute force (9,000 values). |
| B6 | web `POST /customer/customer-order-edit-pay-amount` (no auth middleware) | the web twin of A: `Order::find($order_id)` and the cash, offline, wallet and digital branches with no ownership check. Branch `gcc/p0-web-due-payment-authorization` exists; not read in this sweep. |
| B7 | `POST /api/v1/order/track-order` | uses the phone number as the proof by design (tested); its order-details branch filters by the web guard, which is null on the API. Not verified. |
| B8 | the neighbours' customer branch lacks `is_guest = 0` (track, cancel, the refund routes, the invoice, `getOrderById`, the web profile pages) | a guest number and a customer number are both small integers and collide. Fixed on `gcc/p0-order-owner-is-guest-scope` (`637196e1`), not in `main`. |

## C. Coupons: vendor and seller ownership (I1-11.3), found by the coupon pin tests

These four were measured on a tree that holds more than `main`; the fix task re-verifies each on `main` first. **No fix is pushed.**

| | Route | What |
|---|---|---|
| C1 | `GET /api/v3/seller/coupon/list` with a search term | the `orWhere` on the code and the discount type is not grouped with the shop filter: `search=THEIRS` returns **another vendor's coupon**, `search=amount` returns every coupon including the platform's. Without a search term the list is right. |
| C2 | `PUT /api/v3/seller/coupon/update/{id}` and `.../status-update/{id}` | the seller app edits and switches an **admin coupon made for every vendor and borne by the vendors** (`seller_id` 0, bearer seller): 200 and the coupon is rewritten. The vendor panel refuses the same coupon (404 and an error), and the seller app refuses its platform-borne twin (403). |
| C3 | vendor panel `GET /vendor/coupon/quick-view?id=` | opens any coupon, another vendor's or the platform's, with its code, discount, minimum and bearer: no owner check, unlike the edit page and the switch beside it. |
| C4 | vendor panel `DELETE /vendor/coupon/delete/{id}` for a platform coupon | answers 500 (`Trying to access array offset on null`) because `in_array($coupon['seller_id'], [vendor id, 0])` runs on a null coupon; were the warning not an exception, `null == 0` would make it true and the delete would run on the id alone. Another vendor's coupon is refused as intended. |

## D. Not a security finding, but found in the same pass

- **The website customer login never counts a wrong password** (`CustomerAuthController::loginByEmailOrPhone` returns at the hash check before the `login_hit_count` increment): only the API locks an account after repeated failures; the website is limited by the route throttle alone.
- **The admin coupon edit form shows a stored amount floored to the decimals setting**, so some typed amounts come back one minor unit low and saving the form again lowers the coupon (single currency, 2 decimals: 4.35 is shown as 4.34, 8.2 as 8.19; about 4.6% of the amounts from 0.01 to 1000.00 by the helper's own arithmetic). With the decimals setting at 2 a three-decimal amount cannot round-trip: 1.505 is shown as 1.5. Storage and redemption are not rounded (`decimal(40,20)`), only what is shown back.
- **A coupon the checkout refuses is silent**: the order is placed with no discount and `coupon_code` stored as 0; a free-delivery coupon never compares its minimum purchase; the applicable-list and the apply route disagree about a percentage coupon with a minimum and about `limit = 0`.
- **The theme delivery cache key holds no channel** (`ThemeDelivery::fingerprint`): two clients that differ only in `X-UI-Channel` share one cached page, so a section limited to the seller app can be sent to the customer app, whoever asks first decides. **Fixed on `gcc/p1-theme-publish-per-storefront` (lavkoo #112, `7a351c8a`):** the channel joins the key (one line) and a test asks in both orders; it is a defect of `main`, and the change makes every cached page be built once again.
- **The theme preview**: `GET /api/v1/theme/home?preview[]=x` answers 500 (a list where a string is expected), and the API's preview responses carry no `X-Robots-Tag` (and `/api/v1/theme/sections?theme_preview=` serves a draft with `Cache-Control: no-cache, private`, not `no-store`). **Fixed on the same branch (lavkoo #112, `e2b21b89`):** a value that is not a string is no token (the published page is served), and a draft served by `/theme/home` or `/theme/sections` is sent with `Cache-Control: no-store, private` and `X-Robots-Tag: noindex, nofollow` from one place; each is pinned by a test that fails without it.
- **The logo width and height never reach the app** (`ThemeDelivery::tokens` mapped every branding value that was not a non-empty string to null, so the numbers a merchant sets answered `null` in `GET /api/v1/theme/home`, the defaults 140 and 40 included). A defect of `main`. **Fixed on `gcc/p1-theme-publish-per-storefront` (lavkoo #112, `eaa04e2f`):** a number is kept; a test asks for a theme that sets both sizes and one that sets none.

What is needed from the owner: a decision on A (merge #21 and the guest-ownership design), on F1 (the payer of a wallet payment), on B1 to B5 (a fix pull request each, or one for the whole group), and on C (fix pull request `gcc/p1-security-coupon-ownership`, when it exists).
