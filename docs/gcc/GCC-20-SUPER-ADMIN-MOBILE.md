# GCC-20 — Super Admin Mobile Control Center (Lavkoo Seller App)

**Status:** Business requirement approved in planning on 2026-11-12. **No code implementation or backend API design approved yet.**  
**Owner intent:** The *platform/project owner* needs a true **main Super Admin experience INSIDE the existing Flutter seller app**, with a central view of all Lavkoo markets and market-specific controls. This is NOT a normal seller account expanded to see every seller's data.

## Scope

- Existing Flutter seller application: `fullstackfull/lavkooappseller`.
- Backend/API and admin authorization source: `fullstackfull/lavkoo`.
- Customer application `fullstackfull/lavkooapp` should remain unaffected unless contracts change.
- Preserve the seller app's current normal seller/staff experiences; do not rebrand/remodel them blindly.

## Two principals, one app shell

| Principal | Access | Important distinction |
|---|---|---|
| `seller` / seller staff | Their authorized seller/shop actions and data only | Continue existing seller login, roles, seller API v3 and seller control tower behavior. |
| `platform_super_admin` | All authorized storefronts, countries, companies and centrally managed functions | Authenticate with the backend **admin identity** and dedicated correctly authorized admin mobile APIs; never pass admin credentials through the existing seller login or mint a fake seller bearer token. |

Role detection must be server-authoritative, not a client-side flag, email allowlist, or hardcoded user ID. Owner account should have strong authentication (prefer MFA), short-lived tokens where compatible, remote revocation, device/session management, account audit and step-up confirmation for critical actions. Existing admin guard/permissions and project architecture must be audited before choosing the precise transport.

Do not infer that every seller must be permitted to switch to an admin role. Separate role/guard and no privilege escalation.

## Super Admin home — all-country overview

Mobile dashboard may show, according to actual backend information/permission:

- Country/store state, alerts, readiness checks; data freshness / sync health.
- Order volume, order status, pending fulfillment, returns and refunds.
- Sales and financial totals **by legal entity and currency**; avoid summing SAR/AED/KWD/QAR/BHD/OMR into one number without explicit reporting conversion and exchange-rate metadata.
- Stock health, zero/low stock, allocation and oversell alerts.
- Payment/webhook incidents, reconciliation and integration failures.
- Provider, marketplace, Odoo sync and logistics health.
- Active staff/seller issues; escalations and pending approvals.
- In-app deep links from global alerts to the relevant store and entity.

## Country / storefront drill-down

Selector choices: **All Markets**, SA, AE, KW, QA, BH, OM. Store selection must propagate to every API query, chart, export, mutation and notification.

Within a market, show (subject to permission and release readiness):

- Orders, returns/refunds, products, inventory and selected fulfillment source;
- Seller/vendor management, allowed staff, local delivery and logistics;
- Payments, settlement summaries, legal company, country currency, tax and compliant reports;
- Country-specific settings, marketing/campaigns, approved policies, themes and customer service;
- Marketplace and accounting integration state;
- Storefront activation/paused mode with critical-action confirmation and readiness preflight.

Never equate country with seller, shop or warehouse. If a country contains several legal entities, filter/partition by the correct entity.

## Action levels and approval gates

### Level 1 — Monitor (initial priority)
Read-only cross-country KPIs, alerts, drill-down, operational health, audit logs.

### Level 2 — Daily operations (later, explicitly approved)
Review/assign support issues, request stock review, manage selected local operational settings, approve authorized seller staff tasks as policy allows.

### Level 3 — Critical actions (separate owner authorization and backend safety)
Switch store to Active/Browse Only/Maintenance/Redirect; alter payment account/credential or settlement destination; publish legal policies; release stock; change permissions; approve/refund large amounts; enable/disable integrations.

Require clear confirmation, status preconditions, RBAC, full audit trail, and ideally step-up/MFA and dual approval where appropriate. **Do not turn these on in the discovery task.** A country manager may only perform explicitly assigned functions within their scope; only platform super admin can see every market.

## Alerts and notifications

- Global alerts to Super Admin; country/store-specific drill-down; filters by severity/market.
- Admin notification topics must not reuse marketing/customer topics or seller-specific topics.
- Deduplication, permission checks on alert open, privacy-safe excerpts, and remote revocation.

## Identified existing code — narrow verification only

- Seller app `lib/features/control_tower/screens/control_tower_screen.dart` and `lib/features/menu/screens/more_screen.dart` already expose seller-focused control/finance/reports screens.
- Backend `app/Http/Controllers/RestAPI/v3/seller/SellerControlTowerController.php` calls `forSeller($request->seller->id)`.
- Backend `app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php` handles seller and seller-staff login.
- Backend `app/Http/Middleware/SellerApiAuthMiddleware.php` maps token to seller principal and `$request->seller`; its authorization is seller scoped.
- Backend `app/Http/Middleware/AdminMiddleware.php` uses the distinct `admin` guard.

Therefore do not re-label the existing seller control tower as global admin; audit backend admin auth and dedicated mobile API contracts. Preserve existing code by extending its service patterns rather than changing seller API responses for all users.

## Required Claude Discovery additions

1. Map current seller/staff login, token storage, API calls, navigation, deep links, session timeout and push tokens.
2. Identify existing admin guard/roles/permissions and safe authentication pattern for mobile platform administrators.
3. Identify backend data aggregations that already exist; avoid duplicate dashboards and expensive unbounded queries.
4. Specify `AdminMobileApi` / `SuperAdminApi` as an architecture proposal only, not an instruction to implement a separate auth system without analysis.
5. Propose UX/navigation separation by principal and switching among countries without leaking previous market cache.
6. Determine minimal viable release: monitoring first, limited operational controls second, sensitive admin writes last.
7. Produce an authorization test matrix proving normal sellers/staff cannot access Super Admin APIs (including direct bearer/API calls); verify revoked sessions, different-country contexts, exports and push topics.
8. Include seller app version compatibility/force-update behavior, backend feature flag for safe rollout and rollback.
9. Identify useful mobile admin activities vs desktop-only complex workflows; avoid blindly mirroring every admin web page.
10. Keep all technical changes unimplemented pending approval; audit documentation can be Git-committed and pushed per `COLLABORATION.md`.

## Acceptance criteria (future, not executed)

- Super Admin login resolves verified platform principal and displays `All Markets` view; no seller masquerading.
- Authorized admin sees each of the six markets with appropriate currency/identity and can drill down.
- Existing seller and seller-staff accounts retain exact scoped behavior; never see Super Admin menu/API.
- Country switching flushes scoped caches and does not leak seller, customer, finance or inventory data from a previous country.
- Sensitive actions require backend scope verification, audited event, preflight and confirmation; pausing a store cannot break previous order webhooks/refunds.
- API/load performance bounded and data freshness visible.
- Three repo compatibility and old Saudi experience regression covered.

**Current phase remains DISCOVERY ONLY. This document records a business decision and proposed audit scope; it does not authorize code writes, schema migrations, PR merges, or deployments.**
