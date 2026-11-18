# K-SELLER-MARKET-CONTRACT: what the seller app can ask and rely on (K-17.1, K-08.3, K-04.0)

The backend half of three acceptance criteria of axis K (`docs/gcc/tracker/axis-k-flutter-seller-app.md`): the app can ask who it is (K-17 criterion 1, and K-08
criterion 3), and the seller order list and detail carry the storefront and the currency of each order and can be filtered by market (K-04 criterion 0).
Both are additive and ship off: while `gcc.storefronts.enabled` (env `GCC_STOREFRONTS_ENABLED`) is false, the seller API answers exactly what it answered before.
Nothing here is MET until CI has run on the pushed head. The Flutter app, the seller version gate and the token lifecycle are not touched.

## 1. Who am I: `GET /api/v3/seller/seller-center/security/me`

Asked by whoever holds a credential of the shop: it sits in the middleware chain of `seller-info` (`api_lang`, `seller_api_auth`) and names no permission, because an app that
cannot ask who it is cannot know which of its screens to draw. A token that is unknown, a staff member who was switched off and a shop that is no longer approved get the 401
of every other route (`{"auth-001": "..."}`: sign the user out); an issued integration key gets the 403 `api_key` it gets on every route that names no permission. Only `GET`.

```json
{
    "principal_type": "owner",
    "seller_id": 1,
    "staff_id": null,
    "role": null,
    "permissions": ["products.view", "products.manage", "orders.view", "orders.manage", "inventory.manage", "promotions.manage",
                    "finance.view", "payouts.request", "reviews.view", "shop_settings.manage", "staff.manage"],
    "market_scope": "unrestricted",
    "allowed_markets": null,
    "storefronts_enabled": false
}
```

```json
{
    "principal_type": "staff",
    "seller_id": 1,
    "staff_id": 7,
    "role": "Order clerk",
    "permissions": ["products.view", "orders.view"],
    "market_scope": "unrestricted",
    "allowed_markets": null,
    "storefronts_enabled": false
}
```

| Field | Meaning |
|---|---|
| `principal_type` | `owner` or `staff`. Nothing else is ever answered: a key is no person. |
| `seller_id` | The shop. For a staff member, their employer. |
| `staff_id` | The staff member, `null` for the owner. |
| `role` | The name of the staff member's role; `null` for the owner and for a staff member with no role. A role that was switched off is still named and grants nothing. |
| `permissions` | The keys the caller holds now, once each, in the order of the catalogue (`SellerPermissionService::catalog()`). The owner holds all eleven; a staff member what their role grants. Read from the role on every request, so a change shows on the next call. Never a key the platform does not know. |
| `market_scope` | `unrestricted`: no grant of markets exists yet (K-09), so the caller is not limited to any market. |
| `allowed_markets` | `null`, always, until K-09 exists. Never a list, never a country code: a list would claim a grant that does not exist. The key is always present. |
| `storefronts_enabled` | The platform switch (`StorefrontResolver::fromConfig()->isEnabled()`), and nothing else: an active market does not switch it on. While it is `false` the app shows no market choice and sends no `storefront`: the answers are what they always were. |

What the app can rely on: the eight keys, in this order, in every answer; `permissions` is what the server enforces (`seller_can` on each route; the owner is never permission-checked, and
owner-only actions such as `seller-update`, `account-delete`, payment information and verification are for `principal_type == owner` whatever the list says); no token, e-mail or password is
in it. What it must not assume: that `allowed_markets == null` will stay `null` (K-09 turns it into a list and `market_scope` into something else, so read `null` as "no limit"), or
that a permission it does not know is a permission it lacks (the catalogue can grow).

## 2. Orders carry their market: `POST /api/v3/seller/orders/list` and `GET /api/v3/seller/orders/{id}`

While storefronts are on, every order in the list (`orders[]`) and every `order` inside a line of the detail carries two keys, whatever the state of the database:

```json
{ "id": 102, "seller_id": 1, "order_status": "pending", "order_amount": 202, "storefront_country": "AE", "currency_code": "AED", "...": "..." }
```

| Key | Meaning |
|---|---|
| `storefront_country` | The storefront the order was sold in, as `OrderStorefrontSnapshot` recorded it (GCC-05 slice 2): `SA`, `AE`, `KW`, `QA`, `BH` or `OM`. |
| `currency_code` | The currency its amounts are in (`SAR`, `AED`, `KWD`, `QAR`, `BHD`, `OMR`). |

`null` means no record, and is never filled in: an order from before the snapshot, from a time the switches were off, one whose lines span two countries, or, for `currency_code` only, an order of the
default storefront on a multi-currency platform (it records its storefront and no currency). An order with no record belongs to the default storefront, in the platform's own currency settings (what
`GET /api/v1/config` answers), as the platform reads every such order. Before the orders migration has run, both keys are `null` on every order.

The list accepts an optional `storefront` (a JSON body field or a query parameter) and narrows the seller's own orders to one market. It only ever narrows: the filter is added to the seller's scope, which is
`orders.seller_id` and `seller_is = 'seller'` as before, and a market in which the seller sold nothing answers an empty list.

| `storefront` | Answer |
|---|---|
| a code of a market with orders (`AE`) | those orders |
| lower case or with spaces (`ae`, ` AE `) | as the upper-case code |
| the default market (`SA`) | its orders and also the orders with no record; before the migration, every order |
| a market in which the seller sold nothing (`QA`) | an empty list, `total_size` 0 |
| a code that is no market (`ZZ`), a text that is no code (`SAU`), a list, a number | an empty list, never all orders: the other filters of this list never answer 422 for a value they do not know, and a filter must not widen |
| missing, `null` or an empty text | no filter |
| any value while storefronts are off | ignored, as an unknown field always was |

`total_size`, `limit`, `offset` and the paging follow the narrowed orders. The filter reads what the orders recorded, not the configuration of today: the orders of a market that was switched off, or taken out of the
configuration, stay in that market. The default market is the one the platform names (`gcc.storefronts.default_country`), not always `SA`. It is a filter on a list, not a storefront context: it does not narrow
products, prices or stock (K-17.0 is open), and the header `X-Storefront` is still not read on `api/v3/seller`.

While storefronts are off nothing is read, added or filtered, and both answers are what they were, key for key and in the same order. That is pinned by the answers as recorded from the controller before it was touched, in
two states of the database (`tests/Support/Fixtures/SellerMarket/`). Once the orders migration has run those answers already carry `storefront_country`, `currency_code` and `currency_rate` as raw columns of the order (see
the second finding below), and while storefronts are on the answer adds nothing else to them: the two keys are made visible by the seller API itself, so they stay whatever the order model hides, and `currency_rate`
is not part of this contract, so do not read it.

## 3. What stays open

- K-09: markets and entities on the seller principal and its roles, per-market permissions, the server refusing a market outside the grant. Until then `allowed_markets` is `null` and the app's choice of a market grants nothing.
- K-17.0: `X-Storefront` (or the principal's scope) resolved on `api/v3/seller`, behind the flag. K-17.2: seller config (currency, decimals, country code) per market.
- K-04.0 in the app (the market filter and label on the order screens), K-04.1 and K-04.2: products created in the working market, per-market stock and finance totals. Other seller endpoints that return orders (reports, returns, refunds, the POS, the delivery-man order lists, the control tower) do not carry the two keys yet.
- K-08: the app reading this answer and hiding what the role cannot use (the Flutter app); the staff matrix test over the 53 `seller_can` declarations. K-12.4: sign-out.

## 4. Decisions for the owner

1. **An order with no record belongs to the default market** (chosen). It is how the platform reads such an order and how it reads a coupon with no storefront, and it keeps a Saudi seller's history under `SA`. The other choice
   is strict equality: such orders would only show without a filter. Switching is one line in `OrderStorefront::narrow()` and its tests.
2. **Hide the raw record columns on the order model** (`storefront_country`, `currency_code`, `currency_rate`), as the product and the coupon do. Today they are not hidden, so once migration `2026_11_26_100000` has run, every
   API that serialises an order model whole carries the three keys with the switches off (`null` for every existing order): this one, and the seller API v2, which answers `Order::with(...)->get()`; the customer endpoints were
   not checked. This slice does not change that, because it would change existing answers; it records it, and its own answer does not depend on it. Hiding them restores the answers of before the migration for everyone, and the
   two recorded `after_migration` answers would then be recorded again.
3. **The path.** `seller-center/security/me` is the one K-08 names; it is outside the `staff.manage` group beside it. A shorter path under `api/v3/seller` is a one-line change if the app prefers it.
4. **`allowed_markets: null` as "no limit"** until K-09: confirm the reading before the app depends on it (Q-18 and U6 decide what K-09 grants).
5. K-08, still open: hide a screen by role in the app, or keep answering 403 and showing the toast.

## 5. Findings that this slice leaves alone

- `SellerSecurityController::principal()` and `refuseIntegration()` use `SellerApiAuthMiddleware` and `SellerPrincipal` without importing them, on `main` and on this branch: every write endpoint of the security desk (`roles`
  POST, PUT, DELETE, `staff` POST, PUT, DELETE and `staff/{id}/sign-out`) answers 500 (`Class not found`), while its reads work. The security set adds the two imports (`wf/fix52-r2`, `gcc/release/security-gcc-integration-ci`).
  Fixing it here would change existing answers, which is why `me` is a controller of its own.
- The raw columns of the order (decision 2).

## 6. Tests

`tests/Feature/Security/SellerWhoAmITest.php` (17: the shape and its order, owner and staff of both shops, a role that grants nothing, no role, a switched-off role, a role changed between two calls, the middleware chain of `seller-info`
and no permission on the route, 401 as the sibling, a switched-off staff member and an unapproved shop, an integration key, `GET` only, no market granted and none named, the platform switch only, no credential in it) and
`tests/Feature/Storefront/SellerOrderMarketTest.php` (38: both answers as recorded while off, in two states of the database, with a filter ignored; both keys on the list and the detail while on, before and after the migration,
and where the model hides them; the filter for every kind of value, in the query string, beside the status filter and the text search, paged, never reaching the other seller, the in-house order that shares the seller's id or an order with no record of the other seller; a
default market that is not Saudi; a market that was switched off or removed; before the migration; a staff member with and without orders; another seller's order, a line whose order is gone, 401). They live in the Security and Storefront
directories, which CI runs on SQLite, MySQL 8 and MariaDB. Each behaviour was removed one at a time, and a test failed each time. To record the off answers again, only for a change the owner approved:
`RECORD_SELLER_MARKET_GOLDEN=1 vendor/bin/phpunit tests/Feature/Storefront/SellerOrderMarketTest.php`.
