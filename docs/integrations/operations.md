# Operations: deployment, go-live, rollback, runbook

## 1. Deploy (nothing is sent until you switch it on)

1. **Back up the database.** Never run `php artisan migrate` on an empty database (CLAUDE.md): import
   `installation/backup/database.sql` first, then migrate. **Do not run these migrations against production until
   they have run cleanly on a staging copy restored from the real dump** — the core tables (`products`, `orders`,
   `order_details`) come from that dump and were not available during development (see [risks](risks.md)).
2. Deploy the code, then:
   ```bash
   php artisan migrate --force      # two additive migrations; no existing table is altered or dropped
   php artisan optimize:clear
   php artisan queue:restart
   ```
   `queue:restart` matters: long-running workers cache a "has the migration run?" check.
3. **Queue worker** (the dispatch jobs go to the `fulfillment` queue by default; override with `FULFILLMENT_QUEUE` /
   `FULFILLMENT_QUEUE_CONNECTION`):
   ```bash
   php artisan queue:work --queue=fulfillment,default
   ```
   Run it under a process manager (Supervisor/systemd). The jobs declare their own tries and timeouts (a dispatch
   or reconcile job may run up to 120 s and is never retried by the queue; whether a request may be repeated is
   decided by the fulfillment state machine). **Set `retry_after` on the queue connection that carries the
   `fulfillment` queue above 120 s (180 is a good value)**: `config/queue.php` ships with 90, and a job still running
   when `retry_after` elapses would be handed to a second worker. The jobs also hold a per-shipment lock and a
   database claim token, so a double run is refused, but the setting should still match the job length.
   With the `sync` queue driver nothing is dispatched inline (a provider HTTP call must never run inside a customer's
   request); the scheduled sweep does the work instead, so a real queue is recommended but the scheduler alone is
   correct.
4. **Scheduler** — already registered in `App\Console\ScheduleDefinition`; make sure the standard cron exists:
   ```
   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
   ```
   | Command | Cadence | Does |
   |---|---|---|
   | `fulfillment:recover` | every minute | routes unrouted orders, dispatches pending/retry-due shipments, moves stuck "dispatching" to *reconcile pending*, reconciles, retries unmatched webhooks, releases holds whose cause is fixed |
   | `fulfillment:poll` | every 15 min | asks providers about open shipments (the net under missing webhooks) |
   | `fulfillment:sync --only=products,inventory` | every 30 min | pulls catalogues / stock where enabled |
   | `fulfillment:sync --only=cities` | Sundays 03:30 | refreshes Torod Point cities |
   | `fulfillment:prune` | daily 03:50 | deletes API logs older than 60 days |
5. Environment (`.env`; see `.env.example`): `FULFILLMENT_INTEGRATIONS_ENABLED=false` (default). Credentials are **not**
   environment variables.

## 2. Enter credentials

Admin → **Third-party setup → Fulfillment providers** → choose the provider → enter the credentials → *Save*. Fields
are write-only: after saving you only see that a value is configured; to change one, type the new value; to remove
one, tick its *Clear* box. Use **Test connection** (it makes only the documented login / a read-only call). Only
admins with the `3rd_party_setup` module can reach these pages.

## 3. Webhook setup (Torod Point)

Settings → *Webhooks* → **Generate webhook secret**; copy the two URLs shown (they are shown in full only now) and
send them to LogesTechs for the Lastmile and Fulfillment webhooks. Turn on *Accept webhooks*. M5AZN has no webhooks.

## 4. Go-live checklist (in this order, one provider at a time)

1. Staging: restore the real dump, migrate, run the test suite.
2. Credentials saved; **Test connection** passes.
3. Torod Point: *Sync warehouse products*; verify the products field map from a real sample; match products; confirm
   or reject the stock field; enter the COD collection method if you take COD; map any cities that were held.
   M5AZN: verify the catalogue / orders field maps from real samples; set the status map and order-reference path.
4. Turn **Integration enabled** on and leave **Send orders** off, or on with **Dry run**. Place a test order and
   read the recorded request on the shipment page and in *API logs*.
5. Set the environment switch `FULFILLMENT_INTEGRATIONS_ENABLED=true`, **Send orders** on, **Dry run** off. The
   moment dispatch becomes live is recorded automatically as the provider's *go-live* time: orders placed **before** it
   are held (`hold_before_go_live`) and are never sent by themselves — release the ones you want sent, one by one.
6. Watch *Integration Center* (open / held / failed / outcome-unknown) and the API logs for the first real orders.

## 5. Rollback

* **Stop sending, instantly, no deploy:** untick *Send orders* (or *Integration enabled*) for a provider, or set
  `FULFILLMENT_INTEGRATIONS_ENABLED=false`. Queued work waits; nothing is lost or failed.
* **Return a product to in-house:** product form → Fulfillment provider → *In-house / Default* (blocked while orders
  for it are in flight with the provider; finish or cancel them first).
* **Remove the feature:** disable the integrations, then `php artisan migrate:rollback --step=2`. The migrations only
  ever *created* new tables and one nullable column, so rollback drops exactly those. Provider shipments already
  accepted by a provider are not recalled by this — handle those with the provider.

## 6. Runbook

| You see | Meaning | Do |
|---|---|---|
| **Held** + reason | Safe: nothing sent. The reason names the cause (mapping, city, phone, COD, currency, provider off, before go-live…) | Fix the cause; the sweep releases causes it can re-check, or press *Release* (money holds only release when their cause is fixed) |
| **Outcome unknown** | A request may or may not have reached the provider. It is *not* re-sent | Check the provider's own portal, then *Confirm nothing was created* (lets it be sent again) or *Link an existing provider order* (with the reference). Both need a reason and are audited |
| **Failed** | The provider refused, or retries ran out (dead letter) | Read the last error and the API calls on the shipment page; fix the data or settings, then *Release* |
| **Delivery failed / postponed** | The courier could not deliver | Handled with the provider; the parent order stays open |
| An M5AZN shipment never changes | M5AZN has no webhook and its status words are undocumented | Check the status map and orders field map, or close it by hand with a reason |
| Order cannot be marked delivered | A provider shipment is unfinished | Wait for delivery, or mark that shipment delivered/cancelled on its page |

Structured logs never contain credentials, tokens, passwords or customer phone numbers; API-log rows are pruned after
60 days.
