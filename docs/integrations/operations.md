# Operations: deployment, queues, scheduler, webhooks, reconciliation, go-live, rollback, runbook

> Read [staging-validation.md](staging-validation.md) first: **nothing here is run on production until it has run
> cleanly on a staging copy restored from the real database.** `php artisan fulfillment:preflight` is the read-only
> readiness check used throughout (the same runtime checks are shown on *Integration Center → System checks*).

## 1. Deploy (nothing is sent until you switch it on)

1. **Back up the database.** Never run `php artisan migrate` on an empty database (CLAUDE.md): restore the dump first,
   then migrate. Run `php artisan fulfillment:preflight` before **and** after migrating.
2. Deploy the code, then:
   ```bash
   php artisan migrate --force      # two additive migrations; no existing table is altered or dropped
   php artisan optimize:clear
   php artisan queue:restart
   ```
   The only change to an existing table is **one nullable column and its index on `products`** (`fulfillment_provider`).
   `queue:restart` matters: long-running workers cache a "has the migration run?" check.
3. **Environment** (`.env`; see `.env.example`):

   | Variable | Value | Why |
   |---|---|---|
   | `FULFILLMENT_INTEGRATIONS_ENABLED` | `false` until go-live | the global kill switch: no outbound provider call of any kind while off |
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
   | `retry_after` | 180 | a job still running when it elapses would be handed to a second worker; the preflight WARNs below 120 s |
   | job `tries` | 1 | a job **never retries itself**: whether a request may be repeated is decided by the shipment's state (*retry wait* / *outcome unknown*), never by the queue — a blind retry of a timed-out create is a duplicate order |
   | overlap lock | one per shipment, **shared** by dispatch, reconcile and cancel | before this review each job class had its own lock, so a cancel could race a send still in flight |
   | `numprocs` | 1 is enough; more is safe | the per-shipment lock, the database claim token and the unique keys refuse a double send even with several workers |

   With the `sync` driver nothing is dispatched inline (a provider HTTP call must never run inside a customer's request);
   the scheduled sweep does the work in its console process, and an operator's *Cancel* runs on the spot.
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
   | `fulfillment:prune` | daily 03:50 | deletes API logs older than 60 days |

   Sweeps are bounded (`limit` 100–200 per run) and **rotate**: each run resumes after where the previous one stopped,
   so rows that cannot progress (a paused order, a switched-off provider) can never starve the ones behind them.
6. **Trusted proxies.** The webhook rate limit (600 / minute per client IP) and the optional IP allow-list use the
   client address Laravel sees. `app/Http/Middleware/TrustProxies.php` ships with **no trusted proxies**: behind a CDN
   or a reverse proxy the application sees the *proxy's* address, so every caller shares one rate-limit bucket and an
   allow-list can never match. Set the proxies for your environment, then confirm on *Integration Center → System
   checks → client address*.

## 2. Enter credentials

Admin → **Third-party setup → Fulfillment providers** → choose the provider → enter the credentials → *Save*. Fields
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
| Body | ≤ 64 KB; payload keys read case-insensitively; stored redacted, de-duplicated by a unique key |
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
   minutes. Finding it records the acceptance.
3. Not finding it is *not* proof it does not exist; after 6 attempts it waits for a person: check the provider's own
   dashboard, then **Confirm nothing was created** (only possible while the provider never gave a reference) or
   **Link an existing provider order**. Both need a reason, are audited, and are refused if the situation changed while
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

1. Staging validation signed off ([staging-validation.md](staging-validation.md), section 10).
2. Live verification ([live-verification](live-verification/README.md)) recorded for that provider, maps verified.
3. Credentials saved; **Test connection** passes. `php artisan fulfillment:preflight` on production with
   `--allow-production-read-only` shows no FAIL (it only reads metadata).
4. Turn **Integration enabled** on and leave **Send orders** off, or on with **Dry run**. Place a test order and read the
   recorded request on the shipment page and in *API logs*.
5. Set `FULFILLMENT_INTEGRATIONS_ENABLED=true`, **Send orders** on, **Dry run** off. The moment dispatch becomes live is
   recorded as the provider's *go-live* time: orders placed **before** it are held (`hold_before_go_live`) and never sent
   by themselves — release the ones you want sent, one by one.
6. Watch *Integration Center* (open / held / failed / outcome-unknown / attention) and the API logs for the first real orders.

## 7. Rollback

* **Stop sending, instantly, no deploy:** untick *Send orders* (or *Integration enabled*) for a provider, or set
  `FULFILLMENT_INTEGRATIONS_ENABLED=false`. Queued work waits; nothing is lost or failed.
* **Return a product to in-house:** product form → Fulfillment provider → *In-house / Default* (blocked while orders for
  it are in flight with the provider; finish or cancel them first).
* **Remove the feature:** disable the integrations, confirm with `php artisan migrate:status` that these two migrations are
  the **last two** that ran, then `php artisan migrate:rollback --step=2`. They only ever *created* new tables and one
  nullable column, so rollback drops exactly those and no store data. **The authoritative rollback is restoring the
  pre-migration backup.** Shipments already accepted by a provider are not recalled by either — handle those with the provider.
* **Code rollback** while the tables exist is safe: the previous release ignores the new tables and the nullable column.

## 8. Runbook

| You see | Meaning | Do |
|---|---|---|
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

Structured logs never contain credentials, tokens, passwords or customer phone numbers; API-log rows are pruned after
60 days.
