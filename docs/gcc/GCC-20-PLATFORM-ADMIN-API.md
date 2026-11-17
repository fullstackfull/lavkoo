# GCC-20 — Platform admin API, level 1 (monitor)

**Status:** implemented on a feature branch, read-only, switched off by default and dormant until its migrations run. Not release ready: the review gates at the end of this
document apply (GCC-20 privileged authentication needs a security review before merge or release, `AUTONOMOUS_DEVELOPMENT_CHARTER.md` section 5).
**This document is the contract the Flutter seller-app shell is built on.** Business requirement: [GCC-20-SUPER-ADMIN-MOBILE.md](./GCC-20-SUPER-ADMIN-MOBILE.md).

## 1. What it is

A small JSON API under `/api/platform-admin/v1` with which the platform owner, signed in to the seller app as an **admin**, watches all six markets: whether each market is
open and legally ready, how many orders each has and in which state, what is waiting to be fulfilled, refund requests, stock at zero or low, alerts, and an audit trail of
what the API itself did.

It is a **separate principal**. It is not a seller, a seller staff member, a seller API key, a customer, or an admin who happens to be signed in to the web panel:

| Caller | Can it use this API? | Can it become a platform admin? |
|---|---|---|
| Seller owner, seller staff, seller API key | No: their tokens are never looked at here | No: no route of the seller API, and nothing a seller sends, creates a grant or a platform token |
| Customer (Passport token or session) | No | No |
| Admin signed in to the web panel | No: the panel session is not a credential here | Only through the sign-in of this API, and only with a grant |
| Admin with a grant | Yes, with a token from `POST /auth/login` | Already is one |

How it is kept apart: its own sign-in endpoint, its own token table (`platform_admin_tokens`), its own middleware, no seller middleware, no seller table, no `Auth` guard, no
Passport (`ReverseIsolationTest` scans the code for these). The identity is the admin's own e-mail and password, checked against the `admins` table **without starting a session**.
Which admin may use it is decided by a row in `platform_admin_grants`, written only by a console command an operator runs on the server. There is no hard-coded admin id (not
admin 1), no e-mail allow-list and no flag the client sends. A grant is made for one admin **row**: it remembers the id and the creation time of the row, and a different row that
merely comes to carry the same id (a deleted admin whose id is reused, a restored dump) is not that admin and gets no access.

Level 1 only reads. The only things it writes are its own session rows (sign-in, sign-out, ending a session, the time a session was last used) and its own audit lines.

## 2. The flag and the three states

`config/gcc.php`, block `platform_admin`. Environment: `GCC_PLATFORM_ADMIN_ENABLED` (default `false`), `GCC_PLATFORM_ADMIN_TOKEN_TTL_MINUTES`,
`GCC_PLATFORM_ADMIN_LOGIN_ATTEMPTS_PER_MINUTE`, `GCC_PLATFORM_ADMIN_REQUESTS_PER_MINUTE`; the window and the alert thresholds are configuration only.

| State | What every route of the API does |
|---|---|
| Flag off (default) | **404, exactly the answer of a route that does not exist** (same status, same message, same headers), before the database, the cache, the limiter or any other service is touched. Verified with a query listener: zero statements. Only a real boolean `true` switches it on: `"1"`, `"yes"` and `1` do not. |
| Flag on, migrations not run | **503** `platform_admin_unavailable`, one stable JSON body, no write anywhere, no 500. One missing table of the three is enough. |
| Flag on, migrations run | Serves. |

Whatever the state, nothing an existing API answers changes: `ReverseIsolationTest` compares the seller sign-in, the seller API's refusal, the customer API's refusal and the
storefront list with the API on and off, and the routes of every other API are asked with and without a platform token and answer identically.

A request for another HTTP method on the same path answers the router's own 405 whatever the flag says, because the path is registered for the method that exists. Only the
routes of the API answer 404 while it is off.

## 3. Operator runbook

All of it is run on the server by an operator. **No HTTP route can create, widen or revoke a grant.** Nothing here touches production data by itself; follow the deployment
process of the platform for migrations and configuration.

### Before the first use

1. Merge and deploy the code. With the flag off nothing changes.
2. **Look at `admins.id` (informational: the migration does not depend on it).** `platform_admin_grants.admin_id` is tied to `admins.id` by a foreign key with `ON DELETE CASCADE`
   when the real column can be referenced, and the migration checks that itself: `BIGINT UNSIGNED` on an InnoDB table (MySQL and MariaDB). The installation dump that creates `admins`
   is not in this repository, so `SHOW CREATE TABLE admins` tells you beforehand which case you are in. If the key cannot be made (another type or engine, no `admins` table, or the
   database refuses it for any other reason) **the migration still succeeds**: it creates the table without the key and writes one warning to the application log that says why.
   Nothing else changes, because a grant is tied to the admin row it was made for by a fingerprint, not by the key (see "Deleting an admin who holds a grant" below).
3. Run the three migrations `2026_11_27_100000_create_platform_admin_grants_table`, `..._100100_create_platform_admin_tokens_table` and `..._100200_create_platform_admin_audit_logs_table`.
   They only add tables. Rolling them back drops the three tables and nothing else; roll all three back together (the tokens table has a foreign key to the grants table, which
   MySQL refuses to drop while it is referenced).
4. **Behind a proxy or a load balancer, set `TRUSTED_PROXIES`** (`config/trustedproxy.php`) so that the address the API sees is the client's. The sign-in and request limits count
   addresses; if every client arrives as the proxy's address they share one limit.
5. Grant access, then switch the flag on:

```
php artisan gcc:platform-admin:grant 7                       # admin 7 is the platform owner: every market
php artisan gcc:platform-admin:grant 12 --countries=KW,AE    # admin 12 may see only Kuwait and the Emirates
GCC_PLATFORM_ADMIN_ENABLED=true                              # then clear the configuration cache the way the deployment does
```

### Day to day

| To do | Command | What it does |
|---|---|---|
| Make a platform owner | `gcc:platform-admin:grant {admin_id}` | Every market. Refuses an unknown admin, an **inactive admin (status must be exactly 1, the first admin included)**, and any level but 1. |
| Restrict, narrow or widen | `gcc:platform-admin:grant {admin_id} --countries=KW,AE` | The grant is updated in place. Codes are case-insensitive, deduplicated and stored in configuration order. An unknown code, or a list with no code in it, is refused and nothing is written. Leaving `--countries` out makes the admin a platform owner again. |
| End a stolen or lost token | `gcc:platform-admin:revoke-tokens {admin_id}` | Every session of that admin ends at once; the grant stays, so they can sign in again. |
| Take access away | `gcc:platform-admin:revoke {admin_id}` | The grant is revoked and every session ends. Revoking twice is harmless and keeps the first time. |
| Give it back | `gcc:platform-admin:grant {admin_id}` | A revoked grant is reactivated, and any session that survived a hand edit is ended first. |

Each of these writes one audit line (`grant.created`, `grant.updated`, `grant.reactivated`, `grant.revoked`, `tokens.revoked`) with the scope before and after. The commands
work whether the flag is on or off, and each tells the operator to run the migrations if the tables are missing instead of failing.

### Rotating and revoking

* A token lives 480 minutes by default; the configured value is never more than 1440 and a value below 1 is the default. A session cannot be extended: the app signs in again.
* **Any change of the admin's password, made anywhere, ends every one of that admin's sessions on the next request.** A session remembers the SHA-256 of the password hash it was
  opened with. (A rehash of the same password, which Laravel does when the hashing cost changes, counts as a change.)
* A session is also refused on the next request when its admin is deactivated, its grant is revoked, or it expires. Nothing is cached, so there is nothing to wait for.
* The admin sees their own sessions with `GET /sessions` and ends one with `DELETE /sessions/{id}`.
* If a token may have leaked: `revoke-tokens`, then ask the admin to change the password. A token starts with `lkpa_`, so one found in a log or a file can be recognised and revoked.
* **Deleting an admin who holds a grant.** With the foreign key the grant and its sessions go with the admin. Without it (the migration said so in the log) they stay behind, and
  cannot be used: a grant remembers the id and the creation time of the row it was made for, so a row that comes to carry the same id later is a different admin and gets no access, at
  sign-in or with a session that was already open. Run `gcc:platform-admin:revoke {admin_id}` before deleting the admin to leave nothing behind. If the new row is meant to have the
  access, run `gcc:platform-admin:grant {admin_id}` again: the grant is made over to the row that carries the id now, the sessions of the old row end, the command says so, and the audit line (`grant.updated`) carries `replaced_admin_row`.
  Changing an admin's e-mail or name does not touch the grant; replacing the row does.
* To switch the whole API off: unset the flag. Every route answers 404 again at once.

### Watching it

`GET /audit-logs` (platform owner: every line) and the table `platform_admin_audit_logs`. A refused sign-in is always in the application log, with its reason (`malformed_input`, `unknown_admin`, `wrong_password`, `inactive_admin`, `no_grant`,
`grant_of_another_admin_row`, `revoked_grant`) and a hash of the address, never the address itself. Only a refusal for an admin who holds a grant is also audited, and at most `failed_login_audit_per_hour` (10) lines per grant per hour, so
traffic from the internet cannot make a table grow.

## 4. Conventions

* Base path `/api/platform-admin/v1`. Everything is JSON. Send `Accept: application/json`.
* Success: `{"data": ...}`, with `"meta": {...}` for lists. Failure: `{"errors": [{"code": "...", "message": "...", ...}]}`. **The `code` is the contract.** Messages are plain
  English for a person reading a log, are never translated, and may change. The app localises by code.
* Timestamps are UTC ISO 8601 with a trailing `Z`. **Dates** (`from`, `to`, `window`) are calendar dates written `YYYY-MM-DD`, in the zone the platform stamps its rows in: the PHP
  default zone, which the application sets at boot from the merchant's `timezone` setting (reported in the answer as `timezone`). See section 9, point 10.
* Every answer of the API (except the 404 of a switched-off API) carries `Cache-Control: no-store, private` and `Vary: Authorization`.
* Authentication: `Authorization: Bearer lkpa_<64 characters>`. The scheme is case-insensitive; **only** the header counts, not a query string, a body field or a cookie.
* **Every failure to authenticate is the identical 401 `platform_admin_auth`**, with no hint which check failed: unknown or malformed token, expired, revoked, admin deactivated,
  password changed, grant revoked, grant of another admin.
* Limits: 120 requests a minute from one address to any route (`429 platform_admin_throttled` with `Retry-After`), counted before a token is looked up.

### Error codes

| Status | Code | When |
|---|---|---|
| 401 | `platform_admin_auth` | Any failure to authenticate or to sign in |
| 403 | `market_not_allowed` | The market is not in the admin's grant (`market=all` is never refused: it means all of theirs) |
| 404 | `session_not_found` | The session is not one of the admin's own open sessions (another admin's id, an ended one and an unknown one all answer alike) |
| 404 | (router's own) | The API is switched off, or the path does not exist |
| 422 | `invalid_market` | `market` is not `all` or a configured code; the error carries `valid` |
| 422 | `invalid_window` | `from` or `to` is not a date written `YYYY-MM-DD`, or `from` is after `to` |
| 422 | `invalid_severity` | `severity` is not `info`, `warning` or `critical`; the error carries `valid` |
| 429 | `platform_admin_throttled` | Too many sign-in attempts or requests; `Retry-After` says when |
| 503 | `platform_admin_unavailable` | The flag is on and the migrations have not run |

## 5. Endpoints

All but `POST /auth/login` need the bearer token. Every request of a signed-in admin is written to the audit trail (action, market, address, user agent, status), refused ones too.

### `POST /auth/login`

Body (JSON or form; **a body only, credentials in the address are not read**): `email` (up to 254 characters), `password` (up to 1024), `device_name` (1 to 100 characters of UTF-8).

```json
{"data": {
  "token": "lkpa_<64 random characters>", "token_type": "Bearer", "expires_at": "2026-11-17T14:00:00Z",
  "me": {"admin_id": 7, "name": "Platform Owner", "level": 1, "scope": "all", "markets": ["SA","AE","KW","QA","BH","OM"], "token_expires_at": "2026-11-17T14:00:00Z"}
}}
```

The token is shown **once**; only its SHA-256 is stored. Success needs: the admin exists, the password matches, **`status` exactly 1 (no exemption for admin 1)** and an active grant made for that admin row.
Everything else, including a malformed body, is the same 401, with the same work done: one lookup of the admin, **one password check against a real bcrypt hash even when there is
no such admin**, one lookup of the grant. Limits: 5 attempts a minute for one e-mail from one address (configurable), 15 for one address in all; every attempt counts, and a
success starts the count of that e-mail from that address again. A sign-in also deletes that admin's sessions that ended more than 30 days ago.

### `POST /auth/logout`

Ends the session of the token used: `{"data": {"revoked": true}}`. The admin's other sessions are not touched.

### `GET /me`

`{"data": {"admin_id", "name", "level", "scope", "markets", "token_expires_at"}}`. `scope` is `all` (the platform owner) or `restricted`; `markets` is what the server allows, in
configuration order. **The app must never decide for itself what an admin may see; it reads this on every start.** No e-mail is returned.

### `GET /sessions`, `DELETE /sessions/{id}`

`GET` lists this admin's open sessions (not revoked, not expired), newest first, at most 100: `{"data": [{"id", "device_name", "created_at", "last_used_at", "expires_at", "current"}]}`.
Never a token, a hash, an address or a user agent. `last_used_at` moves at most once a minute. `DELETE` ends one of them and answers `{"data": {"id": 12, "revoked": true}}`; it takes
effect on that token's next request. Ending the session in use is allowed.

### `GET /markets`

```json
{"data": {"scope": "all", "generated_at": "2026-11-17T06:00:00Z", "markets": [
  {"code": "SA", "path": "sa", "currency": "SAR", "active": true,  "storefront_enabled": false, "legal_ready": true,  "legal_entity": null},
  {"code": "AE", "path": "ae", "currency": "AED", "active": false, "storefront_enabled": false, "legal_ready": false, "legal_entity": null}
]}}
```

The configured countries in configuration order, limited to the admin's. `active` is the country's own switch (`gcc.storefronts.countries.XX.active`); `storefront_enabled` is the
platform switch (`gcc.storefronts.enabled`), repeated on each market so each is self-contained: a market serves customers when both are true. `legal_ready` is the answer of
`StorefrontLegalReadiness`, the code that decides whether a storefront may sell, and is `false` rather than an error when its table is missing; the default storefront is
grandfathered there. `legal_entity` is `gcc.storefronts.legal.legal_entity` (one value for every market today) or null.

### `GET /overview?market=all|SA|AE|KW|QA|BH|OM&from=YYYY-MM-DD&to=YYYY-MM-DD`

`market` is case-insensitive and defaults to `all`; for a restricted admin `all` means all of theirs and the answer says `scope: restricted`. The window defaults to the last 30 days
ending today and is **never longer than 92 days** (longer is cut at the old end with `clamped: true`; `from` after `to` is a 422). Only `to`: the 30 days ending there. Only
`from`: up to today.

```json
{"data": {
  "scope": "all", "market": "all", "generated_at": "2026-11-17T06:00:00Z",
  "window": {"from": "2026-10-19", "to": "2026-11-17", "days": 30, "clamped": false, "timezone": "Asia/Kuwait"},
  "freshness": {"mode": "live", "cached": false},
  "attribution": {
    "orders": {"rule": "all_lines_one_market", "via": "order_details.product_id > products.storefront_country"},
    "refund_requests": {"rule": "product_market", "via": "refund_requests.product_id > products.storefront_country"},
    "stock": {"rule": "live_physical_products_by_market", "via": "products.storefront_country"},
    "products_country": "column",
    "currency": {"per_order": false, "reason": "order_currency_not_recorded"}
  },
  "markets": [{
    "market": "KW", "storefront_currency": "KWD", "active": true,
    "orders": {"total": 3, "by_status": {"pending": 1, "confirmed": 0, "processing": 0, "out_for_delivery": 0, "delivered": 1, "returned": 0, "failed": 0, "canceled": 1}, "latest_at": "2026-11-16T04:00:00Z"},
    "sales": {"by_currency": null, "reason": "order_currency_not_recorded"},
    "pending_fulfilment": {"count": 1},
    "returns": {"refund_requests": 1, "pending": 1},
    "stock": {"zero": 2, "low": 1},
    "alerts": {"critical": 1, "warning": 1, "info": 1}
  }],
  "unattributed": {
    "orders": {"total": 4, "by_status": {"pending": 2, "...": 0}, "latest_at": "2026-11-13T07:00:00Z"},
    "sales": {"by_currency": null, "reason": "order_currency_not_recorded"},
    "pending_fulfilment": {"count": 3}, "returns": {"refund_requests": 2, "pending": 1}, "stock": {"zero": 1, "low": 0},
    "reason": "no_single_market"
  }
}}
```

* `orders` and `returns` are **flows of the window**: orders created in it, refund requests created in it (and how many of those are still pending).
* `pending_fulfilment` is a **state**: orders in `pending`, `confirmed` or `processing` (`SlaService::AWAITING_SELLER_STATUSES`) created within the last 92 days, whatever window was
  asked for, so an order that has waited longer than the window is still waiting. An order older than 92 days is not looked for.
* `stock` is a state too: live physical products at zero stock, and at low stock. See section 6.
* `by_status` has every status the platform knows (`getOrderStatusList()`) in its order, with zero, then any other status found, by name.
* `unattributed` is `null` unless the admin is the platform owner asking for `all`. A single market, and a restricted admin, never see it: those orders might belong to a market
  the admin may not see.
* `alerts` counts the market's alerts by severity.

### `GET /alerts?market=&severity=&page=&per_page=`

```json
{"data": [
  {"id": "6b0e0d0a1c2f3e4d", "severity": "critical", "market": "KW", "code": "legal_not_ready",
   "message": "The KW storefront is active but its legal documents are not approved, so it cannot take orders.",
   "deep_link": "markets/KW", "generated_at": "2026-11-17T06:00:00Z"}
], "meta": {"scope": "all", "market": "all", "severity": null, "generated_at": "2026-11-17T06:00:00Z", "page": 1, "per_page": 25, "total": 5, "has_more": false}}
```

Derived when asked from signals that already exist, stored nowhere. `id` is a stable hash of the code and the market. Worst first, then by market in configuration order, then by code.
At most 50 to a page (default 25); a page or size that is not a positive whole number is the default; a size above 50 is 50.

| Code | Severity | Raised when | Defaults |
|---|---|---|---|
| `legal_not_ready` | critical | storefronts are on, the market is active, and `StorefrontLegalReadiness` says it is not ready | n/a |
| `stock_zero` | warning, critical | live products at zero stock reach the count | 10, 50 |
| `orders_pending_too_long` | info, warning, critical | orders still `pending` after the age, created within the last 92 days | 1, 5, 20 after 24 hours |

The counts and the age are in `config/gcc.php` (`platform_admin.alerts`). A critical count below its warning count is raised to it, and a value that makes no sense is the default.
`deep_link` is a path the app can route; at level 1 every alert links to the market (`markets/XX`). A message carries only a count and a market, never a name.

### `GET /audit-logs?page=&per_page=`

`{"data": [{"id", "admin_id", "action", "market", "token_id", "ip", "user_agent", "context", "created_at"}], "meta": {"scope", "page", "per_page", "has_more"}}`. Newest first, 25 to a
page, at most 100, and the page number is at most 1000. The **platform owner reads every line; a restricted admin reads only their own.** It never counts the table. `action` is one
of `auth.login`, `auth.login_failed`, `auth.logout`, `me.view`, `sessions.list`, `session.revoke`, `markets.view`, `overview.view`, `alerts.view`, `audit_logs.view`, `grant.created`,
`grant.updated`, `grant.reactivated`, `grant.revoked`, `tokens.revoked`. `context` of a request holds the status and the few parameters the API takes (`id`, `market`, `from`, `to`,
`severity`, `page`, `per_page`); never a password, a token, an e-mail or any other value the request carried. `ip` is as sent; `user_agent` is cut at 255 characters.

## 6. How a figure is put in a market, and what the schema cannot say

An order carries **no country and no currency** of its own (`docs/gcc/STOREFRONT_FLAG_RUNBOOK.md`: "Orders carry no country. It follows from their lines"). Each product row belongs to
one country, `products.storefront_country` (default `SA`), and never changes it. So:

* **Orders.** An order is in a market when it has at least one line, **every line is a product that still exists, and all of them are of one configured market.** Any other order
  goes to the `unattributed` bucket: no line, a line whose product has been deleted, lines of more than one market (a point-of-sale cart can mix them), or a product whose country is
  not a configured market (a typo). It is **never counted as Saudi** to make the numbers look whole.
* **Refund requests** are in the market of the product they were made for (`refund_requests.product_id`).
* **Stock** counts the live physical products of the market: `product_type = 'physical'`, `status = 1`, `request_status = 1`, and either added by the admin or by a seller whose
  status is `approved` and who is not deleted. How low is low is the rule of the seller's inventory screens (`InventoryService::stockLimitFor`): the seller's own `stock_limit` when
  it is a whole unit or more, otherwise the platform setting `stock_limit`, never below one; stock above zero and up to the limit is low. A test compares the SQL with
  `InventoryService` for six seller limits, fractions included. The brand and business-mode filters of `Product::active()` are not applied.
* **While `products.storefront_country` does not exist** (the products migration of the storefront stack has not run) every product belongs to the default storefront, which is what
  the storefront code itself assumes before that migration, and the answer says `products_country: "default_storefront"`. An order with no line or a deleted product is still
  unattributed.
* **Money is not reported.** An order stores no currency, and its amounts are stored in the platform's base currency whatever the market it was sold in (the storefront prices are
  not converted; GCC-05 is undecided). Adding them up under a market's currency, or under any, would be a guess; adding currencies would be wrong. So `sales` is `null` with the
  reason `order_currency_not_recorded`, **no figure of money appears anywhere in the answer**, and `storefront_currency` is the configured currency of the storefront, not the
  currency of an order. The contract keeps a place for it: when orders record their market and currency, `sales.by_currency` will become a list grouped per market and currency, and
  `attribution.orders` will name the new rule. Nothing will ever be summed across currencies.
* Every figure is a **grouped aggregate in the database over a bounded range** (the window, or at most the last 92 days); nothing is loaded row by row, and the number of statements
  does not grow with the data (a test adds sixty of everything and counts again). An overview reads the orders twice, an alerts call once.

## 7. Isolation and what is never done

* **No storefront logic applies.** The routes are mapped under the `api` prefix but outside the `api` middleware group, so no telemetry row, no analytics, no response shape for
  the developer portal, no visitor cookie; and none of the storefront middleware. The market is only ever the validated `market` parameter. Nothing reads the product model, whose
  scopes would narrow a query to the storefront a request asks for. `StorefrontIsolationTest` asks every read route with 8 ways a storefront picks a country (`X-Storefront` for
  another country, `?storefront=`, `?country=`, a currency, a session value, a cookie, language and CDN country headers) and 5 market values, and the answers do not change; a control
  shows the header *would* narrow a product query on this very path.
* **No server-side cache.** A cache entry would need a key for the market and for who asks, and level 1 has none; a test scans the code. Two admins with different scopes,
  alternated three times over every route, only ever see their own answer. Everything the browser or a proxy might keep is forbidden by the headers.
* The developer portal lists every route as an internal admin endpoint with bearer authentication and **never to a partner** (`RoutesTest` builds the manifest).

## 8. What level 1 deliberately does not do

* No write to any business data: orders, products, stock, settings, payments, wallets, sellers, markets. A test snapshots every table before and after a series of reads: only
  `platform_admin_tokens.last_used_at` and `platform_admin_audit_logs` change, and no statement that changes anything runs on another table.
* **No multi-factor authentication yet** (see section 10). The sign-in is e-mail and password, throttled, behind a grant.
* No push notifications or topics, no exports, no step-up confirmation (there is nothing critical to confirm).
* No level 2 (support, local operations) or level 3 (store status, payment accounts, legal publication, stock release, permissions, integrations). A grant of any level but 1 cannot
  be made, and level 1 is all that is read.
* Not yet in the overview, because nothing in the repository gives them a per-market signal: payment and webhook incidents, reconciliation, provider, marketplace, Odoo and logistics
  health, staff and seller issues, approvals. Each can be added as a new read endpoint without changing these.

## 9. Assumptions and choices that could have gone another way

1. **Routes outside the `api` middleware group** (the way the webhooks are mapped): the group records a telemetry row, an analytics event and a response shape for every request and
   sets a visitor cookie. That would write outside the API's own tables, and record answers that are private.
2. **Foreign keys.** The repository has no schema for `admins` (it comes from the installation dump). The grants migration ties `admin_id` to `admins.id` with a foreign key
   (`ON DELETE CASCADE`) only after checking that it can: `BIGINT UNSIGNED` on an InnoDB table (MySQL, MariaDB), or SQLite, which does not tell the types apart. Otherwise, or if the
   database refuses the key for any other reason, it logs one warning and goes on: a feature that ships switched off must never fail the deploy of the whole application. The
   tokens table is always tied to the grants table (its types match by construction). What the key gave, that a reused admin id cannot inherit a grant, does not depend on it: the
   grant carries a fingerprint of the admin row it was made for (SHA-256 of the id and the row's `created_at`), and a grant whose fingerprint is not the row's is no grant, at
   sign-in and on every request. The creation time is used rather than the e-mail because an admin may change their e-mail. A row without a creation time (the first admin of an
   installation may have none) is told apart by its id alone, so for such a row the guard is as good as the id; a restored dump with the same ids and the same creation times is the
   same admin. Changing the formula would end every grant that exists, and a test pins it.
3. **Sign-in limits.** The same number for one address and for one address with one e-mail would make the second unreachable. One e-mail from one address gets the configured number;
   the address as a whole gets three times that. Distributed guessing from many addresses is not throttled by this, which is what MFA is for.
4. **Audit of requests is written before the answer is returned**, in the same request: if the trail cannot be written, the request fails instead of being served unrecorded. The audit
   of a *refused sign-in* is written after the answer, so a refusal costs the same whoever the caller claims to be.
5. **The dummy hash** used when there is no such admin is made once per process, with the configured cost. If the real admins' hashes were made with another cost, the time of a
   refusal for an unknown admin differs from one for a known admin by that ratio; the throttle bounds how often it can be measured.
6. **`token` storage.** A token is 64 random characters, so a fast SHA-256 is enough and a lookup is one indexed read. A bcrypt per request would add nothing against a random secret.
7. **`GET /sessions` and `DELETE /sessions/{id}` work on open sessions only** (not revoked, not expired), so what is listed is exactly what can be ended.
8. **`storefront_enabled`** is the platform switch repeated on each market; there is no separate per-market storefront switch in the configuration.
9. **`legal_entity`** is the one value of `gcc.storefronts.legal.legal_entity`; the configuration has no per-market entity yet.
10. **Day boundaries are in the process time zone, not in `app.timezone`.** Laravel stamps `created_at` with the wall clock of PHP's default zone, and `ConfigServiceProvider` sets that
    zone at boot from the merchant's `timezone` setting (`business_settings`), while `config('app.timezone')` stays at the repository's `Asia/Dhaka`. A window cut at `app.timezone`
    midnight would be hours away from the orders it counts, so the window is cut in the zone the rows are stamped in, and the answer reports it (`window.timezone`). It is the one
    zone of the platform, not that of a market: a Kuwaiti and a Saudi order are both counted in the day of the platform's setting. A test runs the overview with the zone set to `Asia/Kuwait`.
11. **Stuck orders** are looked for only within the last 92 days, and an alert is raised per market, so stuck orders that belong to no market raise none (they are in the overview's
    bucket).
12. **Table sizes.** `products` and `refund_requests` are read by one aggregate each. `refund_requests` has no index on `created_at` in the migrations of this repository, and the
    statuses and `request_status` of `products` may not be indexed; on a very large catalogue read the plans before enabling the flag.
13. **Level 1 grants have one level.** `level` exists so level 2 and 3 can be added without a migration; a level above 1 cannot be granted now.
14. **The monitoring that already counts every request** (route pattern and status, no body, no token) counts these routes like any other.

## 10. Open owner decisions

1. **MFA before release?** The GCC-20 requirement prefers it for the owner account. Options: TOTP at sign-in, or a step-up before any level 2 or 3 action only. Level 1 is read-only but
   it shows every market's operations.
2. **Token lifetime.** 480 minutes (8 hours), no refresh, a new sign-in when it ends. A shorter life or a refresh token is a product decision.
3. **Who holds a grant.** The platform owner (all markets) and, if wanted, country managers as restricted viewers. Should a restricted viewer ever see the unattributed bucket?
4. **GCC-05: record the market and the currency on every order.** That is what would let `sales` be reported per market and currency. Until then no sales figure is given.
5. **Alert thresholds** (zero stock 10 and 50, pending 24 hours, 1, 5 and 20) are placeholders for a Saudi-sized catalogue.
6. **An allow-list of addresses, or a separate host,** for the API (it is a bearer API reachable from the internet like every other).
7. **A panel screen** for an admin to see and end the sessions of an API (today: the console and the API itself).
8. **Retention** of `platform_admin_audit_logs` (it grows with use and is never pruned).
9. **Push topics** for global alerts, separate from marketing and seller topics (GCC-20), once there is something to push.

## 11. Review gates before merge and release

`AUTONOMOUS_DEVELOPMENT_CHARTER.md` section 5 requires a security review of GCC-20 privileged authentication before merge or release. This implementation is a draft for that review:
what to read first is `AuthenticatePlatformAdmin`, `PlatformAdminTokenService::principalFor`, `PlatformAdminAuthenticator`, `PlatformAdminGate` and the migrations of the grants and tokens tables. Not done here:
no run against a real database, no production access, no migration on any real environment, no push, no merge. G0 remains open.

Tests, in `tests/Feature/PlatformAdmin` (run on SQLite, SQLite with `APP_DEBUG=true` and MySQL 8; CI runs them on SQLite with the whole suite, and on MySQL and MariaDB with the storefront
set): the authorization matrix (every route of the route table against every caller that is not the holder of a live token: no header, malformed headers, other schemes, a seller, a seller's
staff, an API key, a customer, a panel session, expired, ended, deactivated, changed password, revoked or foreign grant), the reverse isolation tests, the flag and dormancy tests, login,
sessions, request limit, markets, overview, alerts, audit logs, storefront isolation, read-only proof, routes and migrations (the grants migration against every shape of `admins`:
`BIGINT UNSIGNED`, `INT UNSIGNED`, signed `BIGINT`, MyISAM, partitioned, missing), the grant fingerprint, and the grant commands.
