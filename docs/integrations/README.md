# Multi-provider fulfillment (M5AZN + Torod Point / LogesTechs)

Two fulfillment providers sit behind one store. A product belongs to **In-house (default)**, **M5AZN** or
**Torod Point**; an order that contains products from more than one source stays **one order, one payment**
and is split into one **provider shipment** per provider, each with its own reference, status, tracking,
attempts and API history.

| Provider | Role | What it does for us |
|---|---|---|
| **M5AZN** | Saudi dropshipping supplier | We are a *seller account* on M5AZN. Products we select there are pulled into the store; when a customer buys one, the order is sent to M5AZN and it ships to the customer. |
| **Torod Point** (LogesTechs, company ID `714`) | Our own fulfillment warehouse | The warehouse holds our stock and picks, packs and ships. Orders go through the **Fulfillment API v5**; the **Lastmile API v2** is only a helper (cities, package status, cancel, air waybill). |

> **Hard rule, enforced in code and tests:** an M5AZN product is never forwarded to Torod Point, and a Torod
> Point product is never forwarded to M5AZN. Ownership is a snapshot taken inside the checkout transaction
> (`provider_fulfillment_items`), so changing a product's provider later cannot move an order that already
> exists.

Other documents: [capability matrix](capability-matrix.md) · [M5AZN](m5azn.md) · [Torod Point](torod-point.md) ·
[cash on delivery](cod.md) · [operations: deployment, queues, webhooks, go-live, rollback, runbook](operations.md) ·
[risks and open questions](risks.md).

**Rolling it out (start here):** [**pilot deployment on the existing server**](pilot-deployment.md) — the exact procedure,
the gates that need your approval, the rollback for every phase, the remaining blockers and
[Hotfix 1](pilot-deployment.md#hotfix-1-fit-the-integration-to-the-schema-found-on-this-server) (what the first read-only check
found on the pilot server) · [Hotfix 2](hotfix-2.md) (the Torod orders map and who owns the warehouse stock; code only, nothing switched on) ·
[controlled test orders](controlled-test-orders.md) · [GO / NO-GO readiness report](readiness-report.md) ·
[live verification checklists](live-verification/README.md) ([M5AZN](live-verification/m5azn.md),
[Torod Point](live-verification/torod-point.md)) · [security findings](security-findings.md) ·
[staging validation on a restored copy](staging-validation.md) (optional; superseded by the pilot procedure) ·
[M5AZN import workflow](m5azn-import-workflow.md) · [Torod inventory gap analysis](torod-inventory-gap-analysis.md) ·
vendor requests: [M5AZN](vendor-requests/m5azn-technical-request.md), [LogesTechs](vendor-requests/logestechs-technical-request.md).

## Where things are

| | |
|---|---|
| Admin | **3rd Party Setup → Fulfillment providers** (`/admin/fulfillment-providers`, module `3rd_party_setup`) |
| Pages | Integration Center · M5AZN settings / products · Torod Point settings / matching · Provider shipments · API logs |
| Product form | **Fulfillment provider** card (admin-owned physical products) |
| Order page | **Provider shipments** card per order |
| Customer | Notice before payment; shipments + tracking on order details / tracking; additive API endpoints |
| Config | `config/fulfillment.php` (deployment defaults only — **never credentials**) |
| Code | `app/Services/Fulfillment/**`, `app/Jobs/Fulfillment/**`, `app/Http/Controllers/Admin/Fulfillment/**`, `app/Console/Commands/Fulfillment*.php` |
| Migrations | `2026_11_05_100000_create_provider_fulfillment_tables.php`, `2026_11_05_100100_add_fulfillment_provider_to_products_table.php` (additive only) |

## How an order flows

```
checkout (one transaction)
  └─ for each provider-owned line: immutable ownership snapshot (provider, mapping, prices)   → provider_fulfillment_items
after commit (never inside the transaction)
  └─ FulfillmentRouter: group lines by provider → one provider_fulfillment per provider          unique (order, provider, generation)
       └─ eligibility (status/payment/COD/mapping/city/phone/stock…) → pending  or  held(reason)
queue `fulfillment`
  └─ ProviderOrderDispatcher: claim → preflight → send once → settle
       accepted ........ submitted
       rejected ........ failed (with the provider's reason)
       known-not-sent .. retry_wait (exponential backoff, max 5)
       AMBIGUOUS ....... reconcile_pending  → look the order up at the provider; NEVER blindly re-sent
status updates: Torod Point webhooks + polling (M5AZN: polling only — it documents no webhooks)
  └─ ShipmentTracker (the only status writer) → FulfillmentStateMachine (stale/out-of-order safe)
parent order: FulfillmentAggregator derives one status (read-only — it never writes orders.order_status)
```

### Safety nets

* **Six independent switches; an order is sent only when *Integration enabled*, *Send orders* and both `.env` lines are on, *Dry run* is off, for Torod Point the invoice-number round trip has been confirmed by the master administrator, and (for an order nobody released by hand) *Send automatically* is on:**
  `FULFILLMENT_INTEGRATIONS_ENABLED` and `FULFILLMENT_EXTERNAL_WRITES_ENABLED` (environment; with the second off the integration
  is **read-only**: the transport lets nothing but GET requests and the sign-in leave the host; both are read when a process starts, so a
  long-running queue worker needs `php artisan queue:restart` after a change), and, on the provider's page (read from the database
  on every call, so they act at once), **Integration enabled**, **Send orders to this provider** and **Dry run**.
  **Send automatically (no approval per order)** is the sixth, separate switch: while it is off, a due order waits for a person to
  *Release* it, which sends that one order. A dry run records the exact request it would have sent (shown on the shipment
  page) and sends nothing, even with writes off. Everything ships off, and *Send automatically* can only be turned on when the
  go-live checklist on the provider page is met (or knowingly overridden by the master admin with a reason, which is kept).
* **No duplicate orders:** unique `(order, provider, generation)`, unique SHA-256 idempotency key, a database claim
  token before any request, a job that never retries itself (`tries = 1`), and a mandatory reconcile-before-resend
  for any outcome that might have reached the provider.
* **No cross-provider leaks:** routing reads the snapshot, each provider adapter only ever sees its own lines, and
  tests assert that a mixed order produces exactly one request per provider containing only that provider's lines.
* **Webhooks:** de-duplicated by a unique key, the secret compared in constant time, terminal claims
  (`delivered`/`cancelled`) confirmed against the provider before being believed, unmatched events retried.
* **Secrets and personal data:** credentials are encrypted at rest (`integration_settings.credentials`), write-only in the admin UI
  (a blank field keeps the stored value), and removed from logs and API-log rows. Personal data is removed by pattern everywhere
  (e-mail addresses and phone numbers in any text, in any common spelling, including Arabic digits) and by key where the answer is
  about people (order lists, order details, package status and sign-ins keep only codes, counts and status words, and everything
  under a `receiver` or an `address`); an order's own record, like the order itself, keeps the data it needs to ship. What it
  cannot do is recognise a name or a street in free text under a key it has no reason to suspect, so evidence is read by a person
  before it is shared. The exact list of what is kept, where and for how long is in
  [security findings](security-findings.md#sec-16--personal-data-where-this-integration-keeps-it-and-where-it-does-not).
* **Parent order status** is never moved by a provider event. An order cannot be marked *delivered* — from the admin
  panel, the vendor panel, either seller API endpoint or the delivery-man app — while a provider shipment is unfinished;
  a closed order with provider lines cannot be reopened, and orders with provider lines cannot be edited; warehouse
  packing is progress, never delivery.
* **Money is re-assessed before every send:** an order paid (or part-paid) after routing is sent as paid; a cash split that
  is no longer allowed is held. Cash on delivery is not offered for a basket that would split cash between collectors.
* **Cancellation is a recorded request**, set only when the provider acknowledges it, retried with a backoff, one caller at
  a time, and surfaced for a person when the provider refuses or cannot cancel.
* **Readiness checks:** `php artisan fulfillment:preflight` and `fulfillment:compatibility` (both read-only), *Integration Center → System
  checks*, and the provider page's *Before automatic sending* checklist. `php artisan fulfillment:verify <provider>` runs the read-only live checks.

## Tables (all new)

`provider_fulfillments` · `provider_fulfillment_items` · `provider_fulfillment_events` · `provider_api_logs` ·
`provider_sync_states` · `provider_product_mappings` · `provider_catalog_items` · `provider_price_changes` ·
`provider_cities` · `provider_city_aliases` · `product_provider_changes` — plus one nullable, indexed column
`products.fulfillment_provider` (NULL = in-house). `integration_settings` is created only if the database lacks it
(it already exists in installations; the migration never alters it).

No foreign key points at the core store tables: they come from the installation dump, so their engine and key
types cannot be assumed. Integrity to `orders` / `products` is by indexed id.

## Tests

`php vendor/bin/phpunit` (whole suite, SQLite) or, against a real MySQL 8 whose name contains `test`,
`DB_CONNECTION=mysql DB_DATABASE=… vendor/bin/phpunit -c phpunit.mysql.xml tests/Feature/Fulfillment tests/Feature/Security`
(`phpunit.xml` pins SQLite and overrides the environment; `phpunit.mysql.xml` leaves the database to the environment) —
all provider traffic is mocked (`Http::fake` with `Http::preventStrayRequests()`), no test can reach a real provider.
CI runs both. The core store tables
(`products`, `orders`, `order_details`…) are an **inferred minimal schema** in `tests/Support/BuildsFulfillmentSchema.php`
because the installation dump is not in the repository; see [risks](risks.md).
