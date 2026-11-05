# Staging validation on a restored copy of the real database

**Purpose.** Prove, on a throwaway copy of the real Pharmacy KSA database, that the fulfillment integration is
compatible with the real schema and data, that its migrations apply and roll back cleanly, and that every order
scenario behaves as designed — **before** anything touches production.

> **Hard rules (every step below assumes them)**
>
> 1. **Production is never accessed, connected to, migrated or modified by this procedure.** The only thing taken from
>    production is a *backup file* that already exists (or a dump taken by the DBA from a replica), restored somewhere else.
> 2. **No real order is dispatched.** Staging keeps `FULFILLMENT_INTEGRATIONS_ENABLED=false`; when the dry-run exercise
>    needs the pipeline, providers stay on **Dry run**. `fulfillment:preflight` fails if live dispatch is switched on.
> 3. **No real customer is contacted.** Scrub personal data (step 2) and point mail/SMS/payment gateways at sinks or
>    sandboxes before the application boots.
> 4. **Never point PHPUnit at the staging copy.** The suite rebuilds schemas. (The test helper refuses any database whose
>    name does not contain `test` and any `APP_ENV` other than `testing`, but do not rely on that.)
> 5. **Never run `php artisan migrate` on an empty database** (CLAUDE.md): restore the dump first, then migrate.

What this procedure does *not* cover: live calls to M5AZN or LogesTechs (see the
[live verification checklists](live-verification/README.md); those need separate written authorization).

---

## 0. Where this runs and what you need

| Need | Detail |
|---|---|
| A staging host | Same OS family, PHP (8.2 or 8.3) and **MySQL major version as production** (the CI job uses MySQL 8.0). Not the production host. Not reachable from the public internet, or behind basic auth. |
| The backup | A recent logical dump or snapshot of the production database, produced by whoever already owns backups. |
| The code | This branch (`claude/clever-newton-wvhbt2`), `composer install --no-dev` is *not* enough for the test commands — install dev dependencies only if you will run the automated suite on a **separate** `*_test` database. |
| A person | One engineer to run it, one person who knows the store's real order flow to judge scenarios. |

Record the production DB version (`SELECT VERSION()`), collation (`SHOW CREATE TABLE orders`) and the `sql_mode` your
application connection uses (the platform ships `'strict' => false` in `config/database.php`; the integration is tested
under the default strict settings *and* it does not rely on non-strict coercion — see the findings in
[the readiness report](readiness-report.md#what-the-first-real-mysql-run-found)).

---

## 1. Restore the copy

```bash
# On the STAGING database server only. The file is the production backup that already exists.
mysql -e "CREATE DATABASE pharmacyksa_staging CHARACTER SET utf8mb4 COLLATE <same collation as production>;"
mysql pharmacyksa_staging < production-backup.sql
```

Keep the database name free of the string `prod` (the preflight warns when it sees it outside `APP_ENV=production`).
Record the restore time and the row counts of `orders`, `order_details`, `products`, `users`.

## 2. Scrub personal data and silence outbound channels

The integration reads customer names, phones and addresses to build provider orders. A copy of real customers must never
be able to reach a real provider, a payment gateway or a customer's phone.

* Replace names, phones, e-mails and address text in the tables that hold them (`users`, `shipping_addresses`,
  `orders.shipping_address_data`, `guest_users`, anything else the store keeps). **Review the column list against your
  schema; this repository does not contain the production schema.** Keep the *shape* (Saudi `05xxxxxxxx` numbers,
  Arabic city names that exist in the Torod city list) so address validation behaves realistically.
* Delete stored secrets that are not needed: payment gateway live keys, SMS provider credentials, Zender secret, mail
  credentials, push server keys. (`integration_settings` for the fulfillment providers is created *empty* by the
  migration — enter sandbox credentials only for the dry-run phase, if LogesTechs/M5AZN supplied sandbox ones.)
* `.env` for staging:
  ```dotenv
  APP_ENV=staging
  APP_DEBUG=false
  APP_KEY=<a NEW key: php artisan key:generate --show>   # never the example key from .env.example
  DB_DATABASE=pharmacyksa_staging
  MAIL_MAILER=log
  FULFILLMENT_INTEGRATIONS_ENABLED=false
  FULFILLMENT_ALLOW_MIXED_COD=false
  QUEUE_CONNECTION=database           # or redis, as in production
  QUEUE_RETRY_AFTER=180
  CACHE_DRIVER=file                   # or redis; never array
  ```
  Because `APP_KEY` differs from production, any credential stored encrypted in the restored data becomes unreadable on
  staging. That is intended.

## 3. Preflight *before* migrating (read-only)

```bash
php artisan fulfillment:preflight          # exit code 0 = no failures, 1 = at least one FAIL
php artisan fulfillment:preflight --json   # machine-readable, for the sign-off record
```

It only reads metadata and settings; it writes nothing. Run on an empty MySQL 8 database it produced (excerpt):

```
| safety  | database            | PASS | mysql://127.0.0.1/pharmacy_scratch
| safety  | app key             | WARN | APP_KEY equals the example key in .env.example (…never for production).
| schema  | orders              | FAIL | table is missing - restore the installation dump before migrating
| schema  | storage engine      | PASS | orders, order_details, products are InnoDB (transactions and row locks work)
| schema  | fulfillment tables  | PASS | not migrated yet (expected before the first migrate)
| schema  | migrations          | PASS | 2 pending: 2026_11_05_100000_create_provider_fulfillment_tables, …
| runtime | queue retry_after   | WARN | 90s is not above the longest fulfillment job (120s) …
10 failure(s), 2 warning(s), 28 checks.
```

On the restored copy you want **zero FAILs** before migrating. Meaning of the checks that can fail here:

| Check | A FAIL means | Do |
|---|---|---|
| `safety · database` | `APP_ENV=production`: refuses to run. | You are on the wrong machine/env. (`--allow-production-read-only` exists for a deliberate read-only look at production metadata; it is **not** part of this procedure.) |
| `safety · <provider> dispatch` | Live dispatch is on (kill switch + *Integration enabled* + *Send orders* + not *Dry run*). | Turn it off. Staging must never send real orders. |
| `safety · app key` | Example key in production (WARN elsewhere). | Generate a new key. |
| `schema · <core table>` | A table the integration reads or writes is missing or lacks a required column (the check lists which). | The dump is incomplete or from a different platform version. Fix the restore; **do not** migrate. |
| `schema · id types` | `orders.id`, `order_details.order_id`, `products.id` are not integer types. | Stop. The integration stores these as `bigint` without foreign keys; a non-integer key needs a design change. |
| `schema · storage engine` | A core table is not InnoDB. | Row locks and transactions — which duplicate prevention relies on — do nothing on MyISAM. Convert first. |
| `schema · fulfillment columns` | A database migrated by an *earlier revision of this branch* lacks later columns. | On staging only: roll that migration back and migrate again. |
| `runtime · table jobs / failed_jobs` | The queue tables do not exist. | Run the platform's queue migrations. |

## 4. Compatibility checks the preflight cannot do (read-only SQL)

Run these on the staging copy and keep the output with the sign-off. None of them writes.

```sql
-- 4.1 Shape of the tables the integration touches
SELECT table_name, engine, table_collation, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('orders','order_details','products','business_settings','currencies','admins','admin_roles','categories');

-- 4.2 Column types of the fields the integration reads (compare with the preflight's list)
SELECT table_name, column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND ( (table_name = 'orders'        AND column_name IN ('id','customer_id','order_amount','paid_amount','shipping_cost','payment_method','payment_status','order_status','shipping_address_data','is_pause','order_type','is_guest'))
     OR (table_name = 'order_details' AND column_name IN ('id','order_id','product_id','qty','price','discount','tax','tax_model','variant','product_details'))
     OR (table_name = 'products'      AND column_name IN ('id','code','barcode','current_stock','variation','unit_price','status','details')) );

-- 4.3 The values the routing and money rules branch on — are there any you did not expect?
SELECT payment_method, payment_status, COUNT(*) FROM orders GROUP BY payment_method, payment_status;
SELECT order_status, COUNT(*) FROM orders GROUP BY order_status;
SELECT tax_model, COUNT(*) FROM order_details GROUP BY tax_model;

-- 4.4 Rows that break an assumption (expect 0, or understand every row)
SELECT COUNT(*) FROM orders WHERE customer_id = 0;                                   -- walk-in / POS sales stored with customer id 0
SELECT COUNT(*) FROM orders WHERE order_amount <= 0;
SELECT COUNT(*) FROM orders WHERE shipping_address_data IS NOT NULL AND JSON_VALID(shipping_address_data) = 0;
SELECT COUNT(*) FROM products WHERE variation IS NOT NULL AND variation <> '' AND JSON_VALID(variation) = 0;
SELECT COUNT(*) FROM orders WHERE order_type = 'POS';                                -- never routed to a provider

-- 4.5 Scale: how long will the first sweep and the migration take?
SELECT (SELECT COUNT(*) FROM orders) AS orders, (SELECT COUNT(*) FROM order_details) AS lines, (SELECT COUNT(*) FROM products) AS products;

-- 4.6 Triggers or foreign keys on the core tables that could interact with new rows or the new products column
SELECT trigger_name, event_object_table, action_timing, event_manipulation FROM information_schema.triggers WHERE trigger_schema = DATABASE();
SELECT table_name, constraint_name, referenced_table_name FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name IN ('orders','order_details','products');
```

Stop and investigate if: `customer_id` is not numeric, `shipping_address_data` is invalid JSON for recent orders, a trigger
writes to `products`/`orders`, or `payment_method` has values other than the ones the store uses (the integration treats
exactly `cash_on_delivery` as COD).

## 5. Migrate, review, verify

```bash
php artisan migrate:status                 # note the last migration BEFORE ours
php artisan migrate --pretend              # read the SQL: two migrations, additive only
php artisan migrate                        # asks for confirmation in staging; --force only in non-interactive CI
php artisan migrate:status                 # both of ours: Ran
php artisan fulfillment:preflight          # schema section: all PASS; 'migrations': both applied
```

What the two migrations do (and only this):

| Migration | Creates / changes | Existing tables |
|---|---|---|
| `2026_11_05_100000_create_provider_fulfillment_tables` | 11 new tables (`provider_fulfillments`, `…_items`, `…_events`, `provider_api_logs`, `provider_sync_states`, `provider_product_mappings`, `provider_catalog_items`, `provider_price_changes`, `provider_cities`, `provider_city_aliases`, `product_provider_changes`) and `integration_settings` if absent | none altered; no foreign keys to the platform tables |
| `2026_11_05_100100_add_fulfillment_provider_to_products_table` | one nullable column `products.fulfillment_provider` (+ index) | `products`: **one nullable column added**; on a large table this is the only statement that could take time — check `4.5` and do it in a quiet window |

Both are guarded (`hasTable` / `hasColumn`), so re-running is a no-op; `MigrationsTest` proves up, down, repeat and
data preservation on SQLite and on MySQL 8.0.

> **An earlier revision of this branch?** The feature ships as one unreleased migration that was edited in place while it
> was in review. A database that ran an earlier revision has the tables but not the later columns (`cancel_*`,
> `imported_hash`); `fulfillment:preflight` reports exactly which. On staging: roll back both migrations and migrate
> again. Production has never run any revision, so this does not apply there.

## 6. Rollback rehearsal

Rehearse the way back before you need it.

1. **Verify the order first:** `php artisan migrate:status` — our two migrations must be the **last two** that ran.
2. `php artisan migrate:rollback --step=2` — drops the 11 new tables and the one column; `integration_settings` and all
   store data are untouched.
3. `php artisan fulfillment:preflight` → back to *"not migrated yet"* with no FAIL.
4. `php artisan migrate` again (idempotent) and compare row counts of the core tables with step 1: they must be identical.
5. The authoritative rollback is always **restore the pre-migration backup** — the `down()` methods are the convenience.

Note what a rollback does *not* undo: provider shipments already accepted by a provider (handle those with the provider),
and any stock/price/product changes made by import or stock sync (`stock_movements`, `provider_price_changes` are dropped
with the feature; the product rows stay).

## 7. Functional validation with providers off, then dry-run

Log in as an admin with the `3rd_party_setup` module. **Integration Center → System checks** shows the same runtime
checks as the command. Work through the scenarios below on staging orders you place through the real checkout (web and
API). "Held" and "Dry-run" are *expected, safe* states — nothing is sent.

Prepare: two M5AZN products and two Torod Point products (assign via the product form after approving matches — dry-run
data is fine), one in-house product. Set both providers **Integration enabled = on, Send orders = on, Dry run = on**, and
`FULFILLMENT_INTEGRATIONS_ENABLED=true` **only after** the preflight shows no live-dispatch FAIL (dry-run is not live).

> **What can leave the staging host while the kill switch is on.** *Dispatch in Dry run sends nothing.* But a provider that
> is enabled and has credentials also runs its **scheduled read-only calls**: product/stock sync (`product_sync_enabled`,
> `inventory_sync_enabled`), the weekly city refresh, and the status poll for shipments that carry a provider reference.
> Those are Level 1 calls. If you do not want any provider traffic during this exercise, switch those sync options off
> (or do not enter credentials until the live-verification step) — an unconfigured provider holds orders with
> `hold_provider_not_ready`, which is itself a scenario worth seeing. **Never** link a real-looking Torod package barcode
> to a staging shipment while the kill switch is on and credentials are present: *Cancel* on such a shipment is a live
> (write) call to LogesTechs.

| # | Scenario | Expected on staging | Automated counterpart |
|---|---|---|---|
| 1 | Prepaid order, Torod items only | one shipment `…-TP1`, status *Dry-run*, cash 0, total = items + shipping | `OrderScenarioMatrixTest` |
| 2 | Prepaid order, M5AZN items only | one shipment `…-MZ1`, only M5AZN lines | same |
| 3 | Prepaid order, one of each | **two** shipments, each with only its own lines; customer sees **one** order with the exact Arabic notice | same + `OrderGuardAndStorefrontTest` |
| 4 | Prepaid order, provider + in-house lines | provider shipment worth its lines + a pro-rata share of shipping; in-house part untouched | `OrderScenarioMatrixTest` |
| 5 | COD, one provider | that provider collects everything still owed | same |
| 6 | COD, part-paid (wallet/loyalty) | collects only the balance | same |
| 7 | COD, **two providers** (mixed) | the payment step does **not offer COD**; forcing the API call returns the explanatory 403 | `StatusAndCheckoutGuardsTest` |
| 8 | COD routed, then marked paid before dispatch | shipment is re-assessed at send time: prepaid, cash 0, event `money_reassessed_before_send` | `OrderScenarioMatrixTest` |
| 9 | Edit a provider order (admin / vendor / seller API) | refused with the explanatory message; nothing written | `OrderGuardAndStorefrontTest` |
| 10 | Mark an order *Delivered* in admin, vendor panel, seller API, delivery-man app while a shipment is open | refused with the same reason on every path | `StatusAndCheckoutGuardsTest` |
| 11 | Reopen a cancelled order that has provider lines | refused | same |
| 12 | Cancel the order before dispatch | each shipment cancelled locally; nothing sent | `CancellationTest` |
| 13 | Cancel the order after a shipment is *submitted* (link a made-up reference to an **M5AZN** shipment: *Link an existing provider order*) | M5AZN cannot cancel through its API and makes no network call → the cancellation is *blocked*, the shipment shows the manual-cancel instructions and the order becomes *attention required*; *Mark cancelled by hand* closes it. (Do this with M5AZN, or with the kill switch off — see the box above.) | `CancellationTest` |
| 14 | Change the dispatch window (set *Send orders* off) and place orders | shipments are **held** (`hold_provider_disabled`), the sweep releases them when it is turned back on | `DispatcherTest` |
| 15 | Customer order page / API | provider **names never appear**; one shipment row per provider; *delivered* only when all required shipments are | `OrderGuardAndStorefrontTest` |

Record, for each: order id, shipment ids, final statuses, and a screenshot of the shipment page.

## 8. Queue, scheduler and recovery rehearsal

| Exercise | How | Expected |
|---|---|---|
| Worker | `php artisan queue:work --queue=fulfillment,default` under Supervisor | jobs complete; `failed_jobs` empty |
| Scheduler | one `* * * * * php artisan schedule:run`; wait 5 min | `fulfillment:preflight` → `scheduler heartbeat` PASS (last sweep ≤ 1–5 min) |
| Stopped scheduler | stop cron for 20 min | heartbeat → FAIL "the scheduler or cron has stopped" |
| Killed worker | dry-run a dispatch and `kill -9` the worker mid-job | after 10 min the shipment moves to *Outcome unknown*, never re-sent; reconciliation backs off; an operator resolves it |
| `retry_after` | set 60 on the connection, run a 100-second fake job | preflight WARNs; with 180 it does not |
| Sweep isolation | make one order un-routable (e.g. paused) | the others are still dispatched; the heartbeat stays green |
| Cache driver | set `CACHE_DRIVER=array` | preflight WARNs (scheduler locks cannot work) |

## 9. Performance and regression

* Place 50 test orders in a row before and after enabling the feature; compare checkout latency. The only checkout-time
  work is one indexed lookup per line (the ownership snapshot) and an after-commit routing request that is queued, never
  executed inside the customer's request.
* Run the per-minute sweep against the real row counts (`php artisan fulfillment:recover` — with the kill switch **off** it
  does nothing; with it on and providers on Dry-run it exercises the queries). Look in the slow-query log for anything over
  ~100 ms. The sweeps are bounded (`limit` 100–200 per run, rotating cursor) so their cost does not grow with history.
* Re-run the platform's own critical flows (checkout, refund, admin order list, vendor order list) and compare with
  production behaviour.

## 10. Exit criteria and sign-off

| Gate | Evidence | Passed |
|---|---|---|
| Preflight, before and after migrate | `--json` output of both | ☐ |
| Compatibility SQL (section 4) | outputs; every unexpected row explained | ☐ |
| Migrate / rollback / re-migrate | status outputs, equal row counts | ☐ |
| Scenarios 1–15 | table with order ids and screenshots | ☐ |
| Queue / scheduler rehearsal (section 8) | notes | ☐ |
| No outbound provider traffic from staging | provider API log table is empty or dry-run only; firewall/egress log | ☐ |
| Findings triaged | each finding: blocker / non-blocker, owner | ☐ |

Only after all gates pass does the readiness report's *real-database staging* blocker clear. Live provider verification
is a **separate** step with its own authorization.

## Appendix — the mechanical steps were rehearsed

Sections 3, 5 and 6 were run with the real commands on a **MySQL 8.0.46** server against a synthetic stand-in for the
restored copy (the core tables only — the real dump is not in the repository):

| Step | Observed |
|---|---|
| `fulfillment:preflight` before migrating | core tables, id types, InnoDB and "not migrated yet" all PASS; the only FAILs were the queue tables the stand-in does not have |
| `migrate` (the two migrations) | 423 ms and 28 ms on empty tables; preflight afterwards: all 11 tables present, columns up to date, both migrations applied, `products.fulfillment_provider` present |
| `migrate:rollback --step=2 --pretend` | prints the 11 `drop table` statements and the column drop, executes nothing |
| `migrate:rollback --step=2` | 11 tables and the one column gone; the store's own rows unchanged (2 products before and after) |
| `migrate` again | applies cleanly; rows unchanged |

`migrate:status` on that stand-in also listed an unrelated platform migration as *Pending* — the reason section 6 says to
check that ours are the **last two that ran** before using `--step=2`. On the real copy the 300-odd platform
migrations are already recorded, so only ours are pending.

## Appendix — what the automated suite does and does not prove

* It runs the same suites on SQLite and on a real **MySQL 8.0** service (CI) against a minimal core schema **inferred**
  from the models and the code that writes the order tables. That proves the integration's own tables, constraints,
  locking, JSON, decimals and `timestamp(3)` behave on MySQL; it does **not** prove the real `orders`/`order_details`/
  `products` shapes, triggers, or data. Sections 3–4 above exist for that gap.
* It never contacts a provider (`Http::preventStrayRequests()`), so response shapes the vendors do not document are
  assumed only in fixtures. The live checklists exist for that gap.
