# Remaining risks and open questions

This integration is **not claimed to be production-ready**. Everything below the line "verified" was exercised
only against mocked provider responses; the items under "not verified" need a person with the real systems.

## Verified (automated, mocked providers, in-memory database)

* Ownership snapshots, routing (one shipment per provider, only that provider's lines, never crossing providers),
  idempotency (unique keys, claim token, no blind resend, reconcile-before-resend), the state machine (stale and
  out-of-order events), webhook authentication / de-duplication / terminal-claim confirmation, COD allocation,
  currency conversion, timezone normalisation, secret redaction and encryption at rest.
* Both adapters against fake HTTP built from the request shapes in the official documents.
* Every admin page and the product / order / storefront views render with the real templates and controllers;
  every route carries the admin guard and the `3rd_party_setup` permission; no stored secret is ever rendered;
  every phrase is translated (English + both Arabic locales); the required Arabic notice is exact.

## Not verified — needs staging with the real dump and the real providers

1. **The core store schema.** `products`, `orders` and `order_details` come from the proprietary installation dump,
   which is not in the repository. Tests use a minimal schema *inferred* from the models and the code that writes
   them (`tests/Support/BuildsFulfillmentSchema.php`). Hooks that touch checkout — the ownership snapshot right
   after the `order_details` insert in `OrderManager`, the post-commit routing hook, the status hook in
   `OrderRepository`, the delivered guard in the admin `OrderController`, the extra `fulfillments` key on two
   customer API responses — must be exercised on a staging copy restored from the real dump before production.
   **Do not run the migrations on production first.**
2. **Live provider behaviour.** No credentials were used and, by design, no real order or stock change was made.
   Response schemas that the documents do not publish are *assumed* only in test fixtures. Before go-live a person must
   fetch real samples and verify each field map (M5AZN: catalogue, stock feed, orders; Torod Point: products, orders),
   and run a dry-run order end to end.
3. **Torod Point stock.** No documented inventory endpoint and no documented stock field in the product list (see the
   [capability matrix](capability-matrix.md#the-stock-question-brief-item-4)). Stock sync stays off, and stock is
   labelled manual, until LogesTechs confirms.
4. **Cash on delivery.** The Fulfillment API's collection-method values and M5AZN's COD settlement are undocumented.
   COD for Torod Point stays held until a value is entered; mixed-provider COD is blocked unless explicitly enabled and
   verified; an order that is part in-house, part provider is always held. Decide the business policy before taking COD.
5. **M5AZN delivery tracking.** No webhook and no documented status vocabulary: delivery depends on a verified status
   map or on an operator closing the shipment.
6. **Torod Point webhook authenticity.** The webhooks are unsigned. The secret in the URL is the only credential, and a
   terminal claim is confirmed with the provider's status call; a claim that cannot be confirmed waits. If LogesTechs can
   publish source IPs, set the allow-list.
7. **Queue configuration.** `retry_after` ships as 90 s; the fulfillment jobs can run 120 s. Set it above that on the
   connection used (see [operations](operations.md)).
8. **Worker restarts.** A long-running worker caches whether the new tables exist; run `queue:restart` after migrating.

## Product decisions worth confirming

* An imported M5AZN product is unpublished until reviewed (*Publish imported products* changes that).
* Orders placed before a provider goes live are held, never auto-sent.
* The parent order's `order_status` is never written by a provider event (financially loaded: earnings and restock hang
  off it). *Delivered* remains an admin action, guarded until every provider shipment is delivered or cancelled.
* A product can be assigned to a provider only after it is saved and matched; the create form says so.

## Observations outside this change (not modified)

* `routes/admin/routes.php` lines ~119–143 register admin routes (OTP / Zender settings and test, app carousel,
  manual message send, cart marketing) in a `Route::prefix('admin')` group that has **no `admin` authentication
  middleware** in that file. They predate this work; the new fulfillment routes are not in that group. Review them.
* `resources/views/admin-views/integrations/index.blade.php` links to `admin.integrations.templates.index` and
  `admin.integrations.chatwoot.save`, which have no registered route in the repository.
* `tests/Feature/ExampleTest` (the stock Laravel example, `GET /`) fails on the untouched base commit as well: the
  in-memory test database has none of the installation tables.
