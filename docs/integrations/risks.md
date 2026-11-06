# Remaining risks and open questions

This integration is **not claimed to be production-ready**. The [readiness report](readiness-report.md) classifies every
item below as a blocker or a non-blocker. "Verified" means exercised by the automated suite against mocked providers
(SQLite everywhere; the integration's own suites also on a real MySQL 8.0 in CI); "not verified" needs a person with the
real systems.

## Verified

* **Ownership and routing** — immutable ownership snapshots taken inside the checkout transaction; one shipment per
  provider, only that provider's lines, never crossing providers; a re-routed or repeated order creates nothing twice.
* **Duplicate prevention** — unique keys on (order, provider, generation), the idempotency key, one claim token, no blind
  resend, *outcome unknown* → reconcile-before-resend; operator overrides are locked, conditional steps refused when the
  situation changed; a shipment that already carries a provider reference is never sent; one lock per shipment shared by
  dispatch, reconcile and cancel.
* **Money** — shipment total = its lines + a pro-rata share of shipping/coupon/rounding (shares sum to the order total to
  the cent); VAT inclusive and exclusive; part-paid cash on delivery; mixed cash on delivery held unless enabled **and**
  every provider verified; the shipment is **re-assessed against the order just before every send** (an order paid after
  routing is sent as prepaid); currency conversion; a basket that would split cash is not offered cash on delivery.
* **Order lifecycle** — a provider event never writes the parent `order_status`; *delivered* is refused on every status
  writer (admin, vendor, both seller API endpoints, delivery-man app) until each shipment is delivered or cancelled; a
  closed order with provider lines cannot be reopened; orders with provider lines cannot be edited; cancellation is a
  recorded, retried, one-at-a-time request (see [operations](operations.md#5-cancellation)).
* **Webhooks and tracking** — authentication, de-duplication, stale/out-of-order handling to the millisecond, terminal-claim
  confirmation bound to **our** package and cash, contradictory references left unmatched, unmapped statuses kept visible.
* **Recovery** — eight isolated sweeps with a heartbeat; rotating cursors so stuck rows cannot starve the rest; stuck
  dispatch → *outcome unknown*.
* **Security** — see [security-findings](security-findings.md) (SEC-1 … SEC-12).
* **Admin and storefront** — every page renders with the real templates; every route carries the admin guard and the
  `3rd_party_setup` permission; no stored secret is rendered; every phrase is translated (English + both Arabic locales);
  the required Arabic notice is exact; provider names never reach customers.
* **Database** — the two integration migrations are additive, idempotent and reversible on SQLite and MySQL 8.0; the integration's own
  tables, unique keys, row locks, JSON, decimals and `timestamp(3)` behave on a real MySQL 8.0. The two migrations of Hotfix 1 are
  idempotent widening ALTERs of two columns of the platform's own `order_details` table (type only, no row written, deliberately not
  reversible); they were run on MySQL 8.0 and MariaDB 10.11, **not** on MariaDB 11.4.2 (the pilot server's engine): see the
  pilot-deployment runbook.

## Not verified — needs the live server (the pilot deployment) and the real providers

1. **The core store schema.** `products`, `orders` and `order_details` come from the proprietary installation dump, which
   is not in the repository. Tests use a minimal schema *inferred* from the models and the code that writes them
   (`tests/Support/BuildsFulfillmentSchema.php`). The hooks that touch checkout — the ownership snapshot right after the
   `order_details` insert in `OrderManager`, the post-commit routing hook, the status hook in `OrderRepository`, the
   status guards in five controllers, the checkout cash-on-delivery guard, the extra `fulfillments` key on customer API
   responses — must be exercised against the real schema. The pilot procedure checks the live schema read-only
   (`fulfillment:compatibility`) **before** the migrations, behind a verified backup, and keeps everything switched off until
   it has passed: [pilot-deployment.md](pilot-deployment.md). There is no staging server by decision; the older
   [staging-validation.md](staging-validation.md) describes the same checks on a restored copy.
2. **Live provider behaviour.** No credentials were used and, by design, no real order or stock change was made. Response
   schemas the documents do not publish are *assumed* only in test fixtures. The read-only live verification
   (`fulfillment:verify`, or the provider page) records the real *shapes*, built to leave out credentials and personal data (a person
   reads them before they are shared); the order-creation
   response can only be recorded by the first controlled test order ([plan](controlled-test-orders.md)). See the [live verification
   checklists](live-verification/README.md): every field map must be verified from a real sample.
3. **Torod Point stock.** No documented inventory endpoint and no documented stock field in the product list
   ([gap analysis](torod-inventory-gap-analysis.md)). Stock stays manual and labelled until LogesTechs confirms; nothing
   simulates warehouse stock. **This is why "automatic inventory synchronization" cannot be delivered today.**
4. **Cash on delivery.** The Fulfillment API's collection-method values and M5AZN's COD settlement are undocumented. COD
   for Torod Point stays held until a value is entered; mixed-provider COD is blocked unless explicitly enabled and verified;
   an order that is part in-house, part provider is always held. The assumption that Lastmile's package `cod` equals the
   cash we sent (used to confirm webhook claims) is unverified (T-6).
5. **M5AZN delivery tracking.** No webhook and no documented status vocabulary: delivery depends on a verified status map
   or on an operator closing the shipment. M5AZN cannot cancel through the API.
6. **Torod Point webhook authenticity.** Unsigned; the secret in the URL is the only credential. Terminal claims are
   confirmed with the provider; the first event for a package is trusted on first sight.
7. **Deployment facts we cannot see.** Whether the web server's document root is `public/` or the application root (SEC-13), trusted
   proxies (`TRUSTED_PROXIES`; the webhook rate limit and any allow-list depend on them), the production `APP_KEY` (SEC-2), the Zender
   secret rotation (SEC-1), the queue worker and cron configuration, and whether the platform has migrations of its own pending.
8. **Payment changes after dispatch.** Once a shipment is accepted, a later payment or refund on the parent order is **not**
   propagated to the provider (neither provider documents an update call). Handled by an operator with the provider.
9. **Imported-product freshness.** M5AZN imports are polled (default 30 min, minimum 5). The delay between selecting a
   product on M5AZN and seeing it in the store is M5AZN's own latency plus up to one interval plus the 5-minute scheduler
   tick; *Sync products now* removes the wait ([workflow](m5azn-import-workflow.md)).

## Product decisions worth confirming

* An imported M5AZN product is unpublished until reviewed (*Publish imported products* changes that); a product with no
  price, or a variant with no price, is never sellable.
* Orders placed before a provider goes live are held, never auto-sent.
* The parent order's `order_status` is never written by a provider event (financially loaded: earnings and restock hang
  off it). *Delivered* remains an admin action, guarded until every provider shipment is delivered or cancelled.
* A basket that would be shipped by more than one collector cannot be paid with cash on delivery (unless mixed collection
  is enabled and verified for every provider) — customers pay online instead of the order sitting held.
* A product can be assigned to a provider only after it is saved and matched; the create form says so.
* Orders with provider lines cannot be edited or reopened (cancel and re-place instead) until an edit can amend the
  provider's order.

## Observations outside this change

* The unauthenticated admin routes found in the first review are **fixed** (SEC-1).
* `resources/views/admin-views/integrations/index.blade.php` links to `admin.integrations.templates.index` and
  `admin.integrations.chatwoot.save`, which have no registered route in the repository (pre-existing, not modified).
* `tests/Feature/ExampleTest` (`GET /`) failed on the untouched base commit as well: the storefront middleware reads
  installation tables the in-memory test database does not have. It now skips with that reason when they are absent and
  still runs where the dump has been imported ([ci notes](../../.github/workflows/ci.yml)).
* The platform's MySQL connection runs non-strict (`'strict' => false`), under which a text value compared with a numeric
  column is read as 0 (SEC-11). Other platform endpoints share the pattern.
