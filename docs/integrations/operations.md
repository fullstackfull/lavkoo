# Operations: deployment, queues, scheduler, webhooks, reconciliation, go-live, rollback, runbook

> The step-by-step procedure for the existing (pilot) server — backup, reversible overlay deployment, read-only
> compatibility check, additive migrations, gates and rollback — is [**pilot-deployment.md**](pilot-deployment.md); read it first.
> This document is the reference for what each setting, process and screen does. `php artisan fulfillment:preflight` is the
> read-only readiness check used throughout (the same runtime checks are shown on *Integration Center → System checks*).

## 1. Deploy (nothing is sent until you switch it on)

1. **Back up the database and the files** (`backup.sh`, then `verify-backup.sh`, both in the package's `tools/`, run with `bash` and
   writing outside every web root) and prove the dump loads. Never run `php artisan migrate` on an empty database (CLAUDE.md):
   restore the dump first, then migrate. Run `php artisan fulfillment:preflight --allow-production-read-only` after the files land
   and **before** migrating (the code is dormant until then), and again after.
2. Deploy the code (the overlay: `scripts/deploy/deploy.php`, which detects drift on files that differ from what the release was
   built against and keeps a per-file rollback), then run **only our migrations, by path** (four: two create the integration's tables, two widen `order_details.delivery_status` and
   `.variation` — see Hotfix 1 in the pilot-deployment runbook):
   ```bash
   php artisan migrate --path=database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php \
                       --path=database/migrations/2026_11_05_100100_add_fulfillment_provider_to_products_table.php \
                       --path=database/migrations/2026_11_06_100000_widen_order_details_delivery_status_for_fulfillment.php \
                       --path=database/migrations/2026_11_06_100100_widen_order_details_variation_for_fulfillment.php
   php artisan optimize:clear       # never config:cache, route:cache or optimize on this platform
   php artisan queue:restart
   ```
   Never a bare `migrate` here: the platform has about 300 migrations of its own, one of which rebuilds 35 large tables, and
   whatever is pending on the server is a separate change. The confirmation prompt appears only when `APP_ENV=production`; add
   `--force` only in a non-interactive shell (`--pretend` asks too). The only change to an existing table is **one nullable column and
   its index on `products`** (`fulfillment_provider`); `--pretend` cannot print it, and the `products.fulfillment_provider` line of
   `fulfillment:preflight` is its preview. `queue:restart` matters: long-running workers cache a "has the migration run?" check.
3. **Environment** (`.env`; see `.env.example`):

   | Variable | Value | Why |
   |---|---|---|
   | `FULFILLMENT_INTEGRATIONS_ENABLED` | `false` until the read-only phase | the global kill switch: no outbound provider call of any kind while off. **Read when a process starts**: after changing it, run `php artisan queue:restart` (a long-running worker keeps the old value) |
   | `FULFILLMENT_EXTERNAL_WRITES_ENABLED` | `false` until an authorised test window or go-live | with it off the integration is **read-only**: the transport lets nothing but GET requests and the sign-in leave the host (so the air-waybill download, a POST, waits too), whatever the admin panel says. An environment value that is not a clear on/off word (`true`/`false`/`1`/`0`/`yes`/`no`/`on`/`off`) counts as **off**, and the preflight says so. Same rule: `queue:restart` after every change. A dry run still records its request with this off |
   | `TRUSTED_PROXIES` | empty, or the proxy addresses | which `X-Forwarded-*` headers are believed (item 6 below) |
   | `QUEUE_CONNECTION` | `database` or `redis` | `sync` is *correct* (the sweep does the work) but slow; a real queue is recommended |
   | `QUEUE_RETRY_AFTER` | `180` | must exceed the longest job (120 s); `config/queue.php` defaults to 90 |
   | `FULFILLMENT_QUEUE` / `FULFILLMENT_QUEUE_CONNECTION` | `fulfillment` / (default) | where the jobs go |
   | `CACHE_DRIVER` | `file`, `database` or `redis` (**not** `array`) | the scheduler's overlap locks and the webhook rate limiter live in the cache |
   | `FULFILLMENT_ALLOW_MIXED_COD` | `false` | mixed cash collection stays held unless explicitly enabled **and** every provider is verified |
   | `APP_KEY` | a **new** key, not the one in `.env.example` | provider credentials are encrypted with it; the preflight FAILs on the example key in production |

   Credentials are **not** environment variables: they are entered in the admin panel and stored encrypted.
4. **Queue worker** (jobs go to the `fulfillment` queue):
   ```ini
   ; Supervisor
   [program:pharmacy-fulfillment-worker]
   command=php /path/to/app/artisan queue:work --queue=fulfillment,default --sleep=3 --tries=1 --timeout=150 --max-time=3600
   autostart=true
   autorestart=true
   stopwaitsecs=180
   numprocs=1
   ```
   Why these numbers (validated by the preflight and the tests):

   | Setting | Value | Reason |
   |---|---|---|
   | job `timeout` | dispatch / reconcile 120 s, cancel 90 s | a provider call may take up to 20 s per attempt |
   | worker `--timeout` | 150 | above the longest job, below `retry_after` |
   | `retry_after` | 180 | a job still running when it elapses would be handed to a second worker; the preflight WARNs at 130 s or less (it must be above the longest fulfillment job, 120 s, plus a margin) |
   | job `tries` | 1 | a job **never retries itself**: whether a request may be repeated is decided by the shipment's state (*retry wait* / *outcome unknown*), never by the queue — a blind retry of a timed-out create is a duplicate order |
   | overlap lock | one per shipment, **shared** by dispatch, reconcile and cancel | before this review each job class had its own lock, so a cancel could race a send still in flight |
   | `numprocs` | 1 is enough; more is safe | the per-shipment lock, the database claim token and the unique keys refuse a double send even with several workers |

   With the `sync` driver nothing is dispatched inline from a customer's request (a provider HTTP call must never run inside it);
   the scheduled sweep does the work in its console process. A **person's own** actions — *Release and send now* / *Approve and send
   this order*, *Cancel at the provider*, *Confirm the provider has no such order* — run on the spot inside their admin request, so
   the page waits for the provider's answer (about 20 seconds at most per call) and then says what the send came to. It does so
   whatever the queue driver is: a queued job would carry the approval only in its payload, where a lost or failed job would drop it
   silently. The sweep itself never approves anything: it is not a person. A browser or PHP time limit that cuts such a request short is
   handled like a crashed worker: the shipment is left "dispatching", the recovery sweep turns it into *outcome unknown*, and nothing is
   re-sent until the provider's own dashboard has been checked.
5. **Scheduler** — registered in `App\Console\ScheduleDefinition`; the standard cron must exist:
   ```
   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
   ```
   | Command | Cadence | Does |
   |---|---|---|
   | `fulfillment:recover` | every minute (overlap lock expires after 10 min) | **eight isolated sweeps**: close unrouted lines of cancelled orders · route eligible orders · release money holds · cancel/retry cancellations for closed orders · recover shipments stuck "dispatching" (→ *outcome unknown*, never re-sent) · dispatch pending / due / released shipments · reconcile unknown outcomes · retry unmatched webhooks. A failing sweep is logged and reported (`-1`), the others still run, and the run leaves a **heartbeat** |
   | `fulfillment:poll` | every 15 min | asks each provider about open shipments (the net under a webhook that never arrives); the provider's `last_synced_at` keeps it from re-asking too often |
   | `fulfillment:sync --only=products,inventory --due` | wakes every 5 min | runs only the tasks whose provider interval (default **30**, minimum **5** minutes, set per provider) has elapsed |
   | `fulfillment:sync --only=cities` | Sundays 03:30 | refreshes Torod Point cities |
   | `fulfillment:prune` | daily 03:50 | deletes API logs and this queue's failed jobs older than 60 days, clears stored provider payloads older than 60 days, and clears the request recorded by a dry run nobody released for 60 days |

   Sweeps are bounded (`limit` 100–200 per run) and **rotate**: each run resumes after where the previous one stopped,
   so rows that cannot progress (a paused order, a switched-off provider) can never starve the ones behind them.
6. **Trusted proxies.** The webhook rate limit (600 / minute per client IP) and the optional IP allow-list use the
   client address Laravel sees. `bootstrap/app.php` wires the *framework's* `TrustProxies` middleware (the class in
   `app/Http/Middleware/TrustProxies.php` is never used, so editing it does nothing), and that middleware reads
   `config/trustedproxy.php`: set `TRUSTED_PROXIES` in `.env` to the proxy addresses or CIDR ranges (`*` only if the
   application is reachable solely through the proxy). Empty, the default, trusts none: behind a CDN or a reverse proxy the
   application then sees the *proxy's* address, so every caller shares one rate-limit bucket and an allow-list can never
   match. Confirm on *Integration Center → System checks → client address*.

## 2. Enter credentials

Admin → **3rd Party Setup → Fulfillment providers** → choose the provider → enter the credentials → *Save*. Fields
are write-only: after saving you only see that a value is configured; to change one, type the new value; to remove
one, tick its *Clear* box. A failed save never copies credentials into the session. Use **Test connection** (documented
login / a read-only call; rate limited). Only admins with the `3rd_party_setup` module can reach these pages. Rotating
the webhook secret keeps the configured base URL.

## 3. Webhook setup and security (Torod Point; M5AZN has no webhooks)

Settings → *Webhooks* → **Generate webhook secret**; copy the two URLs shown (they are shown in full only now) and
send them to LogesTechs. Turn on *Accept webhooks*.

| Layer | What it does |
|---|---|
| Kill switches | refused unless integrations, the provider and its webhooks are all enabled; every failure is the same flat 401 |
| Secret | 48 random characters, compared in constant time, in the URL path (LogesTechs can only be given a URL) **or** in `Authorization: Bearer` / `X-Webhook-Secret` if they can set headers — prefer a header |
| IP allow-list | optional; addresses **and CIDR ranges**, IPv4 and IPv6; malformed entries are rejected when saving |
| Rate limit | 600 / minute per client IP (see *Trusted proxies*) |
| Body | ≤ 64 KB; payload keys read case-insensitively; only the fields status processing reads are stored (the receiver, the courier and proof links are not), de-duplicated by a unique key |
| Unsigned events | the secret is the only credential, so an event is **never trusted for a terminal claim**: "delivered / cancelled / returned" is confirmed by asking Lastmile about **the package we hold for that shipment** and checking that the package reports the cash we asked for; a claim that cannot be confirmed waits (and the 15-minute poll applies the real status). A package barcode is learned only from an event that names our invoice number and our cash amount; an event whose references point at two different shipments is left unmatched |

**The secret appears in the URL path**, which web-server access logs record. Redact `/api/fulfillment-webhooks/` in the
access log (or ask LogesTechs for header-based authentication) and rotate the secret if a log was shared. Rotate on any
suspicion: *Generate webhook secret* again and send LogesTechs the new URLs.

## 4. Reconciliation (the outcome of a send is unknown)

A timeout, a dropped connection, a 5xx, or a worker killed mid-request means the provider may or may not hold the order.
It is **never** re-sent automatically:

1. The shipment moves to **Outcome unknown** (a worker lost mid-request is moved there after 10 minutes).
2. Reconciliation asks the provider (Torod: finds our invoice number in the order list, needs a verified orders map;
   M5AZN: finds our order id, needs the orders map) — first after 2 minutes, then after waits of 5, 10, 15, 20 and 25
   minutes. Finding it records the acceptance. (Torod: a blank invoice number never matches, an order's id or barcode is never taken
   for it, and two orders carrying our number are not linked automatically; they wait for a person.)
3. Not finding it is *not* proof it does not exist; after 6 attempts it waits for a person: check the provider's own
   dashboard, then **Confirm the provider has no such order** (only possible while the provider never gave a reference; it **sends the order again at once**, as Release would, after the browser's confirmation) or
   **Link provider order**. Both need a reason, are audited, and are refused if the situation changed while
   the page was open.
4. A cancellation wanted meanwhile is remembered and sent the moment the provider is proven to hold the order.

## 5. Cancellation

* **Not yet with the provider** (pending, held, retry, dry-run, failed): cancelled on the spot, nothing is called.
* **Outcome unknown**: the wish is recorded and honoured once the order is proven to exist.
* **With the provider**: the provider is asked once at a time (a lease; a crashed caller loses it after 3 minutes).
  "Requested" is recorded **only** when the provider acknowledges. A failed call is retried with the configured backoff
  (5 attempts) and is then **blocked**; a refusal, or a provider that cannot cancel at all (M5AZN; Torod before its
  package exists), blocks at once. A blocked cancellation shows a banner with instructions, lists the shipment under
  *Needs attention* and makes the parent order *attention required*: cancel at the provider, then **Mark cancelled by hand**.
* The shipment becomes *Cancelled* only when the provider reports it (or an operator says so, with a reason).

## 6. Go-live checklist (in this order, one provider at a time)

The full sequence, with its approval gates, is in [pilot-deployment.md](pilot-deployment.md); in short:

1. Deployed with everything off; `php artisan fulfillment:preflight --allow-production-read-only` and
   `fulfillment:compatibility --allow-production-read-only` show no FAIL (they only read).
2. Credentials saved; **Test connection** passes; read-only live verification recorded for that provider
   (`php artisan fulfillment:verify <provider>`), field maps verified ([live-verification](live-verification/README.md)).
3. **Controlled phase** ([controlled-test-orders.md](controlled-test-orders.md)): *Send orders* on, *Dry run* on, *Send automatically*
   off. A test order records the exact request on the shipment page. Then, in an authorised window only,
   `FULFILLMENT_EXTERNAL_WRITES_ENABLED=true`, **Dry run** off, and a person presses **Release and send now** (after the browser's confirmation) on that one shipment.
4. The provider page's **Before automatic sending** checklist is derived from what really happened (a recent passing verification,
   verified maps, *Torod only:* the invoice-number round trip confirmed with its evidence, mapped products, a running scheduler and queue, one order accepted by the provider, one status update received and
   understood). **Send automatically** is refused while an item is unmet; the **master admin** may go ahead with the override
   checkbox and a written reason, which is kept on the provider's settings and, when the platform's `audit_logs` table exists, in the audit log with the unmet items.
5. The moment automatic sending becomes live is recorded as the provider's *go-live* time: orders placed **before** it are held
   (`hold_before_go_live`) and never swept out by the switch — release the ones you want sent, one by one.
6. Watch *Integration Center* (open / held / failed / outcome-unknown / attention) and the API logs for the first real orders.

## 7. Rollback

* **Stop sending, no deploy:** the switches on a provider's page are read from the database on every call, so they act at once for the
  website, the scheduler and every queue worker: untick *Send automatically (no approval per order)* (every due order then waits for
  a person), or *Send orders to this provider* (or *Integration enabled*). The two `.env` switches —
  `FULFILLMENT_EXTERNAL_WRITES_ENABLED=false` (read-only) and `FULFILLMENT_INTEGRATIONS_ENABLED=false` (off) — act at once for a web
  request or a cron run, but **a queue worker started earlier keeps the old values until `php artisan queue:restart`**: run it and
  confirm the worker restarted. Queued work waits; nothing is lost or failed.
* **Return a product to in-house:** product form → Fulfillment provider → *In-house / Default* (blocked while orders for
  it are in flight with the provider; finish or cancel them first).
* **Remove the feature:** disable the integrations, take a `--db-only` backup (the provider tables hold the only local record of
  provider references), confirm with `php artisan migrate:status` which **batch** the integration's two migrations (`2026_11_05_*`) ran in, then
  `php artisan migrate:rollback --batch=<that batch> --path=…` with those two `--path`s, **while their files are still on the
  server**: the file rollback below deletes them. They only ever *created* new tables and one nullable column, so rollback drops
  exactly those and no store data (the two `2026_11_06_*` widening migrations have no rollback on purpose: the columns stay wide); `integration_settings`, with the saved credentials, stays. **The authoritative rollback is
  restoring the pre-migration backup**, after which the provider tables a dump does not contain must be removed too (the exact list
  is in the pilot-deployment rollback table). Shipments already accepted by a provider are not recalled by either — handle those with
  the provider.
* **Code rollback** while the tables exist is safe: the previous release ignores the new tables and the nullable column.
  The overlay deployment undoes itself file by file: `php deploy.php rollback --backup-dir=<the directory apply printed>`
  restores every replaced file, removes every added one and takes back only the language keys the release added. An `apply` that
  fails part-way takes back what it wrote; one that is killed leaves a record in its backup directory that `rollback` reads, and a
  new `apply` is refused until that one has been rolled back.
  The full table of rollbacks for every phase is in [pilot-deployment.md](pilot-deployment.md#10-rollback-for-every-phase).

## 8. Runbook

| You see | Meaning | Do |
|---|---|---|
| **Held** · *A real order waits for the provider's confirmation step* on Torod Point, with the capability *Real order submission: Unverified* | The invoice number we send has not been confirmed to come back in the order list; no real order is created until it is | A master administrator records the confirmation with its evidence (Torod Point page → *Invoice number round trip*); the sweep then retries the held orders (each still waits for the usual approval unless *Send automatically* is on). A rehearsal is not held |
| Sync status for stock shows `invalid`, `unsupported`, `not_assigned` or `absent` | Figures the warehouse gave that were refused (missing, negative, fractional, contradictory), a whole-product mapping on a product with variants or a variant that no longer exists, mapped products that are not assigned to Torod Point, or assigned products the answer did not mention | Nothing was written for those: the storefront kept its stock. Look at the product list in the sample; a high `invalid` means the confirmed field is wrong. A sync that wrote nothing because no assigned product received a figure is shown as failed |
| A typed stock figure was not saved: *This product's stock is set by the warehouse sync* | The warehouse sets that product's stock (assigned, mapped, confirmed, switched on, figure recent) | Nothing to fix. To type stock again: switch *Follow warehouse stock levels* off, or unassign the product |
| **Held** + reason | Safe: nothing sent. The reason names the cause (mapping, city, phone, COD, currency, provider off, before go-live, **already has a provider reference**…) | Fix the cause; the sweep releases causes it can re-check, or press *Release* (money holds and "already with provider" release only when their cause is fixed) |
| **Outcome unknown** | A request may or may not have reached the provider. It is *not* re-sent | See section 4 |
| **Failed** | The provider refused, or retries ran out | Read the last error and the API calls on the shipment page; fix the data or settings, then *Release* (resets the attempt counters) |
| **Needs attention** · *provider reported a problem* | The provider's own status asks for a person | Handle with the provider |
| **Needs attention** · *duplicate provider reference* | The provider accepted the shipment but returned a reference another shipment already carries | Compare both shipments in the provider's dashboard; fix the references |
| Banner **Automatic cancellation cannot finish** | The provider refused or cannot cancel | Cancel in the provider's dashboard, then **Mark cancelled by hand** |
| **Delivery failed / postponed** | The courier could not deliver | Handled with the provider; the parent order stays open |
| An M5AZN shipment never changes | M5AZN has no webhook and its status words are undocumented | Check the status map and orders field map, or close it by hand with a reason |
| Order cannot be marked delivered / reopened / edited | A provider shipment is unfinished, the order is closed with provider lines, or it has provider lines | Wait for delivery, mark that shipment delivered/cancelled on its page, or place a new order |
| Cash on delivery missing at checkout | The basket ships from more than one collector | Customer pays online (or the business enables and verifies mixed collection) |
| System checks: *scheduler heartbeat* FAIL | `schedule:run` has stopped | Check cron; nothing is dispatched or reconciled meanwhile |
| System checks: *shipment tracking* WARN | Open shipments heard nothing for 48 h | Check the webhook setup and the poll (M5AZN: a verified orders map) |

Log files and the API log are built to hold no credential or token (credentials written into text are removed by pattern; a pattern is a net, not a proof), and the e-mail addresses and phone numbers that a provider's
error text or an exception message may echo back are removed before this integration writes them (`PiiRedactor::scrub`; a failing
fulfillment job lets out only where it failed and a scrubbed message, so the queue worker's own report and `failed_jobs` are clean
too). That covers what this integration writes. It does **not** cover an exception from code that is not ours, which the framework
reports with its whole message, and it cannot recognise a **name or a street** in free text. API-log rows for orders, packages and
sign-ins are redacted strictly (only codes, counts and status words survive); catalogue and reference calls keep product and
city names. API logs, stored provider payloads and the fulfillment queue's failed jobs are pruned after 60 days by
`fulfillment:prune`. What the integration *does* keep about a customer, and where, is in
[security-findings SEC-16](security-findings.md#sec-16--personal-data-where-this-integration-keeps-it-and-where-it-does-not).
