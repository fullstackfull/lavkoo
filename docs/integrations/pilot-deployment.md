# Pilot deployment on the existing Pharmacy KSA server

> **Status: prepared, not executed.** Nothing in this document has been run against the live server, the live database or a
> provider: this repository has no access to any of them. Every command below was rehearsed on a synthetic tree and on a real
> MySQL 8 (see the [appendix](#appendix-what-was-rehearsed-and-what-was-not)); what the live server will answer is not known
> until it is run. **Nothing is deployed, merged or switched on without your explicit approval** at the numbered gates.

The existing production instance is a pilot environment, so it is also the target. There is **no staging server and no second
copy of the application**: the integration is deployed to this server with everything switched off, then armed one level at a
time, with each level approved by you, backed up, and reversible.

## The sequence at a glance

| # | Phase | Changes | Needs your approval |
|---|---|---|---|
| 0 | Look at the server (read-only) | nothing | — |
| 1 | Full backup of database and files, proven restorable | writes a backup directory outside the web root | — |
| 2 | Fetch and check the package | nothing on the live tree | — |
| 3 | Check compatibility with the live schema (read-only) | nothing | **Gate A — deploy** |
| 4 | Deploy in a short maintenance window | 234 code files, 2 additive migrations, 7 `.env` lines | (Gate A) |
| 5 | Smoke-test the deployed pages | nothing | — |
| 6 | Read-only integration: credentials, `Test connection`, `fulfillment:verify` | outbound **reads and logins only** | **Gate B — allow reads** |
| 7 | Record the real response schemas, finalise the field maps | our own settings | — |
| 8 | Controlled test orders | **real orders at the providers**, one at a time, each approved | **Gate C — allow writes** (per provider, in writing) |
| 9 | Turn automatic sending on | orders flow without a person | **Gate D — go live** (only when the checklist is met) |

Rollback for every phase is in [section 10](#10-rollback-for-every-phase). The remaining blockers are in [section 11](#11-blockers-and-decisions).

### What is armed after each phase

Everything is off after deployment. Five independent switches stand between the code and a provider; each one fails closed.

| Phase | `FULFILLMENT_INTEGRATIONS_ENABLED` | `FULFILLMENT_EXTERNAL_WRITES_ENABLED` | Provider: *Integration enabled* | *Send orders* | *Dry run* | *Send automatically* | What can leave the server |
|---|---|---|---|---|---|---|---|
| 4 deployed | false | false | off | off | on | off | **nothing** |
| 6 read-only | true | false | on | off | on | off | logins and reads (the transport refuses anything else) |
| 8 controlled | true | **true** | on | on | **off** | **off** | an order, only when a person presses *Approve and send* on that order |
| 9 live | true | true | on | on | off | **on** | every due order |

The write guard is in the transport (`ProviderHttp` / `ExternalOperations`), below the admin switches: with
`FULFILLMENT_EXTERNAL_WRITES_ENABLED=false` no order, product, stock or cancellation request can be sent whatever is ticked in the
admin panel, and an operation that is not classified as a read is treated as a write. `LiveVerificationTest` and
`ExternalWriteGuardTest` prove it.

---

## 0. Look at the server first (read-only)

Run these as the user that owns the application files (the one that will run `deploy.php`). Set the two paths once:

```bash
APP=/path/to/the/application     # the directory that contains artisan and .env
PKG=/path/outside/the/web/root/overlay-<sha>   # where the package will be unpacked (section 2)
```

```bash
cd "$APP"
php -v | head -1                                   # CLI must be 8.2 or newer (composer.json: "php": "^8.2")
php artisan --version | head -1                    # Laravel 12.x
stat -c '%U:%G %a %n' artisan .env storage bootstrap/cache   # who owns the tree; storage and bootstrap/cache must be writable
id -un                                             # must be that same user (or a member of its group)
grep -E '^(APP_ENV|DB_CONNECTION|QUEUE_CONNECTION|CACHE_DRIVER|SESSION_DRIVER|LOG_CHANNEL|FORCE_HTTPS)=' .env   # no secrets in this list
crontab -l 2>/dev/null | grep -n artisan           # is schedule:run installed? (a hosting panel may hold it instead)
ps aux | grep -E 'queue:(work|listen)' | grep -v grep   # is a queue worker running, and for which queues?
df -h "$(dirname "$APP")"                          # room for the backup (backup.sh checks too)
command -v mysqldump mysql tar gzip sha256sum      # all five are needed by backup.sh
```

Write down: the PHP version, who owns the tree, how the queue is run (Supervisor, a cron-driven `queue:work --stop-when-empty`,
or `QUEUE_CONNECTION=sync`), and whether the cron line exists. Those four answers decide steps 4 and 9.

**What the web server will hand out.** This platform can be served from the application's root directory instead of `public/`
(`index.php` defines `DOMAIN_POINTED_DIRECTORY = 'root'`), and there any existing file is downloadable by its address: the root
`.htaccess` only blocks `.env`. Check, from any machine:

```bash
for path in .env storage/logs/laravel.log composer.json .git/config public/assets/back-end/Apple-AuthKey.p8; do
  printf '%-48s' "$path"; curl -s -o /dev/null -w '%{http_code}\n' "https://YOUR-DOMAIN/$path"
done
```

Every line must be `403` or `404`. A `200` on `storage/logs/laravel.log` or `.git/config` is a pre-existing exposure (the log holds
order and OTP data), and a `200` on the last line means a private key committed to this repository is downloadable (D3 below): both
should be closed regardless of this project. **Do not store evidence or backups under the web root.** The evidence directory ships
its own `.htaccess` denying access, but that only works on Apache.

Also run, still read-only, the two checks that tell you what this server's database looks like to the new code:

```bash
php artisan migrate:status | tail -n 25            # what is pending today (we will run only OUR two migrations, never a bare `migrate`)
php artisan fulfillment:preflight --allow-production-read-only   # only exists after phase 4; shown here for later
```

*(The second command is available only once the code is deployed; phase 3 uses the same checks through `deploy.php` first.)*

---

## 1. Full backup, proven restorable

A backup you have not restored is a hope. This produces one directory holding the database dump, the application files, row
counts and checksums, and then proves the dump loads.

```bash
# from a checkout or the unpacked package: scripts/deploy/backup.sh and scripts/deploy/verify-backup.sh
scripts/deploy/backup.sh --app="$APP"                       # default: $(dirname "$APP")/pharmacy-backups/<UTC stamp>
# smaller file backup if disk is tight:  --no-vendor --no-uploads   (the database is always complete)
scripts/deploy/verify-backup.sh --backup=<the directory backup.sh printed> --app="$APP"
```

What it guarantees, and what it refuses:

* **Database:** one consistent read (`--single-transaction`, routines, triggers, events, `utf8mb4`), `gzip -t` plus a check that the
  dump ends with mysqldump's completion line, and row counts of ten key tables (`orders`, `order_details`, `products`, `users`,
  `business_settings`, `migrations`, `jobs`, `failed_jobs`, `admins`, `integration_settings`) written to `row-counts.txt`.
* **Files:** the whole application directory (including `.env` and `storage/app`) except `.git`, logs, caches and compiled views,
  and the archive is read back.
* **Safety:** the database password is never on a command line or in the output (a mode-600 option file in a temporary directory);
  the backup directory is mode 700 and **must be outside the application tree** (the script refuses otherwise); it checks free disk.
* `verify-backup.sh` loads the dump into a scratch database it creates and drops, and compares the row counts. If the MySQL
  account may not create databases (common on shared hosting), import `db.sql.gz` into a new empty database with phpMyAdmin and
  compare `row-counts.txt` by hand. **That comparison is the proof; do not skip it.**
* Copy the directory **off the server** to storage you control (it contains `.env` and customer data: never GitHub, never a
  third-party service). `RESTORE.txt` inside the backup explains the restore.

Tell us the backup directory name and the two numbers (`orders`, `products` counts) so they are on record before anything changes.

---

## 2. Fetch and check the package

The package (the "overlay") is exactly what changes on the server: the **added and modified files of PR #1** under `app/`,
`bootstrap/`, `config/`, `database/`, `resources/` and `routes/`, each with the checksum of its baseline version. It contains no
dependency change, no file under `public/` or `storage/`, and no deletion.

* **Where it comes from:** the `Deployment overlay` job of the CI run on the pull request uploads
  `pilot-overlay-<commit>` (`overlay.tar.gz` + `overlay.tar.gz.sha256`). To build it yourself:
  `php scripts/deploy/build-overlay.php --base=34dce65 --head=<release commit> --out=build/overlay --archive`.
* **Current contents** (built from `34dce65` to the branch head): **234 files — 33 modified, 201 added**, two of them migrations.

  | Modified existing files (these are the ones that can have drifted on the server) | Why |
  |---|---|
  | `app/Utils/OrderManager.php`, `app/Repositories/OrderRepository.php`, `app/Models/Order.php`, `app/Models/Product.php` | checkout hook, snapshot, delivered/closed guards |
  | `app/Http/Controllers/{Admin,Vendor}/Order/OrderController.php`, `RestAPI/v1/OrderController.php`, `RestAPI/v2/seller/OrderController.php`, `RestAPI/v3/seller/OrderController.php`, `RestAPI/v2/delivery_man/DeliveryManController.php`, `RestAPI/v1/CustomerController.php`, `Web/WebController.php` | an order cannot be marked delivered, reopened or edited while a provider shipment is unfinished; the mixed-shipment notice |
  | `app/Services/OrderEditService.php`, `app/Traits/OrderEditManager.php` | orders with provider lines cannot be edited |
  | `app/Http/Controllers/Admin/Product/ProductController.php`, two product-form views | the *Fulfillment provider* selector |
  | `app/Http/Controllers/Admin/ZenderSettingController.php`, `routes/admin/routes.php` | pre-existing unauthenticated admin routes now require the admin guard ([SEC-1](security-findings.md)) |
  | `app/Providers/RouteServiceProvider.php`, `bootstrap/app.php`, `bootstrap/providers.php`, `routes/rest_api/v1/api.php`, `app/Console/ScheduleDefinition.php`, `config/queue.php` | registers our routes, provider, schedule and `retry_after` |
  | the three `resources/lang/*/new-messages.php`, order-detail/tracking/sidebar views | text and the provider card |

* **The package refuses to be built** when the release deletes a file, changes `composer.*`/`package.json`, or touches `public/`,
  `Modules/` or the web entry point: those need a person's decision and are named in the error.
* **Transfer it outside the web root**, check it, unpack it:

  ```bash
  sha256sum -c overlay.tar.gz.sha256                 # in the directory where you put both files (NOT under the document root)
  mkdir -p "$PKG" && tar -xzf overlay.tar.gz -C "$PKG"   # the archive has no top-level folder
  ls "$PKG"                                          # MANIFEST.json  SHA256SUMS  deploy.php  files/
  (cd "$PKG" && sha256sum -c SHA256SUMS | grep -v ': OK$' ; echo "(nothing listed above = every file matches its checksum)")
  ```

---

## 3. Check compatibility with the live schema — read-only — and decide

The overlay checker compares each live file with the baseline it was built against; the database check compares the live schema
with what the new code reads and writes. **Neither changes anything.**

```bash
php "$PKG/deploy.php" verify --app="$APP" --package="$PKG"
```

The report gives every file a state:

| State | Meaning | What to do |
|---|---|---|
| `NEW` | does not exist on the server; will be created | nothing |
| `CLEAN` | identical to the baseline the release was built against; will be replaced | nothing |
| `MERGE` | a language file: the release's keys are merged into the live file; nothing the site added is lost | nothing |
| `ALREADY_APPLIED` | already identical to the release | nothing |
| **`DRIFTED`** | an existing file differs from the baseline (a hotfix, a customisation, a runtime rewrite) | **read the difference before replacing it**; accept it by name with `--accept-drift=path,path` only after a person has looked |
| **`CONFLICT`** | a file with an *added* file's name already exists and differs | stop; ask us |
| **`MISSING`** / `UNWRITABLE` / `SYMLINK` | not the code the release was built against / not writable / a link | stop; ask us |

Exit code 0 means no problem; `apply` refuses to run with any `DRIFTED`, `CONFLICT`, `MISSING`, `UNWRITABLE` or `SYMLINK` file.
A `DRIFTED` file in an order or checkout controller deserves a real look: it means someone changed production code that PR #1 also
changes, and the replacement would silently remove their change.

The database side exists only after the code is deployed, so it runs twice: once in phase 4 straight after the files land
(before the migration, read-only), and again after it. What it tells you:

```bash
php artisan fulfillment:preflight --allow-production-read-only          # schema, safety, runtime; exit 1 on any FAIL
php artisan fulfillment:compatibility --allow-production-read-only --expect-database=<the production database name>
```

* `fulfillment:compatibility` inspects **column types, nullability, string lengths, engine and character set** of every table the
  integration reads or writes (`orders`, `order_details`, `products`, `users`, `shipping_addresses`, `business_settings`, `currencies`,
  `sellers`…), and samples the newest 2000 orders and lines for the *shapes* the new code depends on (address JSON keys, payment and
  order status values in use, variation formats). It reads in a session locked read-only (`SET SESSION TRANSACTION READ ONLY`), runs
  SELECT statements only (proved by a statement log in its tests), and prints **counts, names and shapes — never a row**. It
  refuses to run in production without `--allow-production-read-only`, and refuses a database whose name is not the one you give.
* `fulfillment:preflight` reports whether `APP_KEY` is the public example key (see [section 11](#11-blockers-and-decisions)), the
  queue and cache drivers, the scheduler heartbeat, `retry_after`, the trusted-proxy situation, and the state of the two migrations.
* `php artisan migrate --pretend --path=database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php --path=database/migrations/2026_11_05_100100_add_fulfillment_provider_to_products_table.php`
  prints the SQL without running it. Read it: eleven `create table` statements and one `alter table products add fulfillment_provider`
  plus its index. Nothing else.

> **Gate A — you approve the deployment** once: the backup is verified and copied off the server, `verify` shows no unexplained
> `DRIFTED` file, and you have chosen the window.

---

## 4. Deploy in a short maintenance window

Use `php artisan down`, **not** the platform's admin "maintenance mode" switch: the admin switch also sends a push notification to
every user. Files are replaced one at a time and atomically, and the code is dormant until the migration and the `.env` lines exist
(`FulfillmentSchema` keeps every order and product flow exactly as it was until the tables are there), so the window is about the
checkout code being replaced while an order is being placed. Choose a quiet hour; five minutes is enough.

```bash
cd "$APP"
php artisan down --retry=60                                   # customers see a 503 page and may retry in a minute

php "$PKG/deploy.php" apply --app="$APP" --package="$PKG"                 # dry run: prints the same report, changes nothing
php "$PKG/deploy.php" apply --app="$APP" --package="$PKG" --yes           # writes the files; PRINTS the backup directory
#   [--accept-drift=app/Some/File.php]   only for files you have looked at
```

`apply` copies each file it replaces to `…/pharmacy-deploy-backups/<UTC stamp>/` (outside the tree, mode 600), writes
`applied.json` there, and prints **`To undo: php deploy.php rollback --backup-dir=…`**. Keep that line.

Then, in this order:

```bash
# 1. the .env lines (append; nothing is overwritten). The integration stays OFF.
cat >> .env <<'EOF'

# Multi-provider fulfillment (M5AZN, Torod Point). Credentials are NOT set here: they are entered in the admin panel.
FULFILLMENT_INTEGRATIONS_ENABLED=false
FULFILLMENT_EXTERNAL_WRITES_ENABLED=false
FULFILLMENT_CURRENCY=SAR
FULFILLMENT_QUEUE=fulfillment
QUEUE_RETRY_AFTER=180
FULFILLMENT_ALLOW_MIXED_COD=false
TRUSTED_PROXIES=
EOF

# 2. the database: ONLY our two migrations, by path. Never a bare `php artisan migrate`.
php artisan migrate --pretend --path=database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php \
                              --path=database/migrations/2026_11_05_100100_add_fulfillment_provider_to_products_table.php
php artisan migrate --path=database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php \
                    --path=database/migrations/2026_11_05_100100_add_fulfillment_provider_to_products_table.php
#   (APP_ENV=production asks "Do you really wish to run this command?" — answer yes. --force only for a non-interactive shell.)

# 3. caches, workers, PHP
php artisan optimize:clear                                     # routine; NEVER run config:cache, route:cache or optimize on this platform
php artisan queue:restart                                      # a running worker caches "has the migration run?"
# reload PHP-FPM / "Reset OPcache" in the hosting panel if opcache.validate_timestamps is off; otherwise new code is seen within seconds

# 4. the checks again, now against the migrated schema
php artisan fulfillment:preflight --allow-production-read-only
php artisan fulfillment:compatibility --allow-production-read-only --expect-database=<the production database name>

php artisan up
```

Why only two migrations, by path: the platform has about 300 migrations of its own, and one of them
(`2026_05_07_000001`) rebuilds 35 large tables to `DECIMAL(40,20)` and cannot be reversed losslessly. **Do not run a bare
`migrate`**, whatever `migrate:status` shows as pending. If `migrate:status` lists platform migrations as pending, that is a
pre-existing condition to be handled separately, with its own backup and rehearsal.

The queue worker and the cron job must include the new work, and this is the part only your hosting setup can answer (phase 0):

* **A worker is running (Supervisor):** it must listen on the `fulfillment` queue — `queue:work --queue=fulfillment,default --sleep=3
  --tries=1 --timeout=150 --max-time=3600` ([operations §1](operations.md)). A worker started without `--queue=fulfillment` never
  sees our jobs; the go-live checklist reports it as *Queue worker keeping up: not met*.
* **`QUEUE_CONNECTION=sync`:** correct and supported — a provider call never runs inside a customer's request; the per-minute
  sweep does the work.
* **No cron line for `schedule:run`:** add `* * * * * cd "$APP" && php artisan schedule:run >> /dev/null 2>&1`. Without it nothing is
  dispatched, polled or reconciled, and the Integration Center says so (*scheduler heartbeat*).

Deployment is done when `fulfillment:preflight` shows no FAIL and the site is up. **Nothing is switched on:** no provider has been
contacted, and none can be.

---

## 5. Smoke-test what was deployed (nothing here writes or sends)

* Storefront: home, a category, a product page, the cart, the checkout page (do **not** place an order).
* Admin: sign in; **Third-party setup → Fulfillment providers** (`/admin/fulfillment-providers`) shows the *Integration Center* with the
  mode banner **OFF**, both provider cards switched off, and *System checks* with no red; open **M5AZN** and **Torod Point**: both
  pages render, credentials show as not configured, and the *Before automatic sending* checklist lists what is missing.
* Admin: open an existing order (the *Provider fulfillments* card is empty), and the product form (*Fulfillment provider* defaults to
  *In-house*, which is every existing product).
* `php artisan tinker --execute="echo DB::table('provider_api_logs')->count();"` prints `0`: no provider call has been logged
  (*Integration Center → API logs* shows the same).
* `tail -n 50 storage/logs/laravel.log` shows nothing new that mentions `Fulfillment`.

If anything is wrong: [section 10](#10-rollback-for-every-phase).

---

## 6. Read-only integration

> **Gate B — you allow reads.** From here the server makes outbound calls that can only *read* (and sign in). You provide the
> credentials; we never see them.

1. In `.env` set `FULFILLMENT_INTEGRATIONS_ENABLED=true` (keep `FULFILLMENT_EXTERNAL_WRITES_ENABLED=false`). The mode banner reads **READ-ONLY**.
2. **M5AZN page:** *Integration enabled* on; seller e-mail and password; *Save*. Leave *Send orders* off and *Dry run* on.
   **Torod Point page:** *Integration enabled* on; company ID **714**; the Fulfillment API username and password; the Lastmile e-mail
   and password; *Save*. Credentials are write-only and stored encrypted (`Crypt` with `APP_KEY`; see the `APP_KEY` decision in
   [section 11](#11-blockers-and-decisions) **before** typing real credentials).
3. **Test connection** on each page.
4. **Run the read-only checks** (button on the page, or `php artisan fulfillment:verify m5azn` / `torod_point`):

   | Provider | What it asks (all reads) | What it records |
   |---|---|---|
   | M5AZN | sign in · catalogue · the products you selected · one product in detail · stock changes in the last day · categories · order list · one order | the **shape** of each answer: key names, types, list path, paging fields, stock-like fields with ranges, identifier uniqueness, the status words in use |
   | Torod Point | product list, pages 1 and 2 (stock fields, paging) · categories · warehouse order list · cities · villages · the status of our newest package | the same; and, if you give `--skus=13222,13223` (SKUs the warehouse has **counted by hand**), a side-by-side of every numeric field against your count, to find the field that means *available to sell* |

   Every call is a `GET`; M5AZN's sign-in (which only returns a token) is the only `POST`. The run is spaced at 150 ms and the page
   allows three runs a minute. A test proves that even with `FULFILLMENT_EXTERNAL_WRITES_ENABLED=true` no request that creates,
   changes or cancels anything is sent.
5. **Warehouse inventory availability** is the Torod `stock_comparison` finding above plus the M5AZN stock-change feed; if the
   Torod product list carries no quantity at all, that is the answer, and the [LogesTechs request](vendor-requests/logestechs-technical-request.md)
   (items on stock) is the next step. Do not guess a stock field.

**What is stored.** Each run is one JSON file in `storage/app/fulfillment-evidence/<provider>/` (mode 640, directory denied to the
web server) containing the *shape* of every answer with credentials, tokens, names, phone numbers, e-mail addresses, street
addresses and notes removed (keys decide first, values second; order lists use strict mode that hides every free-text string). The
API log keeps the same redaction. A person reading a file can still see company ID, host and mode — nothing else about you or
your customers.

---

## 7. Record the real response schemas and finalise the field maps

Neither provider publishes response schemas for the calls the integration depends on, so **no field map is trusted until you have
confirmed it against a real answer**. That is what the evidence is for.

1. Download the latest evidence (*Live verification → Download the evidence*) and read the findings for each check.
2. On each provider page, under *Field maps*: **Fetch sample** shows a redacted sample and a proposed path for every logical field;
   *Use the proposal* or type the path; **Save and verify**. A map is `verified` only if every required field resolves in most
   records of a *fresh* sample; changing any path un-verifies it.

   | Provider | Maps | Required fields |
   |---|---|---|
   | M5AZN | `catalog`, `inventory`, `orders` | id, name · id, stock · reference, status |
   | Torod Point | `products`, `orders` | id, name · invoice number, reference |

3. M5AZN only: fill the **status map** (provider status word → shipment status) from the vocabulary the evidence shows; the order
   poll is inactive until it exists and the `orders` map is verified.
4. Torod only: when the stock field is established (hand count + LogesTechs), tick **I confirm this is the available quantity**
   (the moment is recorded). Without it stock stays unused.
5. Keep the evidence: add the reviewed JSON files to the repository under `docs/integrations/recorded-schemas/<provider>/` through
   a pull request (the leak guard in CI checks it contains no credential or personal detail), so the recorded schemas outlive the
   server.

*Order creation responses are the one schema that cannot be recorded read-only.* It is recorded from the first controlled test
order (section 8): the API log keeps the redacted response, and you then fill in **Where the provider order reference is in its
response**. Until then a `2xx` without a recognisable reference is *outcome unknown* and is **never** re-sent.

---

## 8. Controlled test orders

> **Gate C — you allow writes, per provider, in writing.** This is the first moment a real order can reach a provider. The full plan
> is in [controlled-test-orders.md](controlled-test-orders.md); this section is the switch sequence.

Prerequisites, all from the go-live checklist (*Before automatic sending*, on each provider page): credentials saved, a passing live
verification less than 14 days old, verified field maps, at least one product assigned to the provider **with an approved mapping**,
the scheduler and queue running.

1. Take a fresh database backup: `scripts/deploy/backup.sh --app="$APP" --db-only`.
2. Assign **only the test product(s)** to the provider (*Product form → Fulfillment provider*). Every other product stays in-house.
3. **Rehearse first, with nothing able to leave.** Provider page: *Send orders* on, **Dry run on**, *Send automatically* off;
   `FULFILLMENT_EXTERNAL_WRITES_ENABLED` still `false`. Place the test order as a staff customer. The shipment becomes **Dry run**,
   and its page shows **Request recorded by the dry run**: the exact request (phone and e-mail masked), with its fingerprint. Read it
   against the provider's documentation: product and SKU ids, quantities, amounts, city, invoice number, cash-on-delivery amount.
4. Only when that request is right: set `FULFILLMENT_EXTERNAL_WRITES_ENABLED=true` (the banner reads **LIVE**) and turn **Dry run off**
   (Save). Nothing is sent by either change: a recorded dry run is never sent by itself, not even by the sweep.
5. Open that shipment and press **Release for dispatch**. That sends this one order, now. Any other due order waits *Waiting for an
   operator to approve* until a person releases it.
6. Follow it by hand (the plan lists what to look at after each step: acceptance and the reference, the first status update, delivery).
   The first accepted response is the one schema the read-only run could not record; fill in **Where the provider order reference
   is in its response** from the API log row.
7. When the test window ends: set `FULFILLMENT_EXTERNAL_WRITES_ENABLED=false` again. The server is back to read-only until the next
   authorised window.

---

## 9. Turning automatic sending on

> **Gate D — you approve going live**, one provider at a time, after the controlled orders succeeded.

The page decides, not a person's memory. *Before automatic sending* is derived from what really happened on this server:

| Item | Met when |
|---|---|
| Credentials saved · Integration switched on · Sending allowed on this server | the three switches above |
| Live verification passed recently | the latest run has no failure and is under 14 days old (a run in which nothing was asked does not count) |
| Field maps verified | every map of the provider |
| Products assigned and mapped | at least one assigned product and one approved mapping |
| Status tracking ready | M5AZN: status map + verified orders map; Torod: company ID |
| Scheduler running · Queue worker keeping up | a sweep in the last 5 minutes · no fulfillment job waiting over 10 minutes |
| **One real order accepted by the provider** | a shipment with a provider reference |
| **A status update received and understood** | a webhook or poll event that matched a shipment and a known status |
| No shipment waiting for a decision | none *outcome unknown* / *needs attention* |
| *Advisory (never blocks)* | stock sync running · order-reference path named · COD settlement verified |

Turning **Send automatically** on while items are unmet saves every other setting, keeps automatic sending off, and names the unmet
items. An administrator may go ahead anyway with the **override** checkbox; the unmet items and the person are written to the audit
log with that very save. Do not use it casually: an unmet *One real order accepted* means nothing has ever proved the write path.

On the first day: keep the Integration Center open, read *Held / Failed / Outcome unknown / Needs attention* every few hours, and
leave the rollback in section 10 within reach. Orders placed **before** the go-live moment are held (`hold_before_go_live`) and are never
swept out by the switch; release the ones you want sent.

---

## 10. Rollback for every phase

| If this goes wrong… | Do | Cost |
|---|---|---|
| Anything, instantly, no deploy | untick *Send orders* (or *Integration enabled*) on the provider, or set `FULFILLMENT_INTEGRATIONS_ENABLED=false` / `FULFILLMENT_EXTERNAL_WRITES_ENABLED=false`. Queued work waits; nothing is lost | seconds |
| Automatic sending misbehaves | untick *Send automatically*: every due order goes back to waiting for approval | seconds |
| A shipment was sent that should not have been | it cannot be recalled by us: M5AZN documents no cancel endpoint and Torod no warehouse-order cancel; use the provider's dashboard/contact. The shipment page records the reference and everything sent | per order |
| The new code misbehaves (phase 4–5) | `php "$PKG/deploy.php" rollback --backup-dir=<the directory apply printed>` restores every replaced file, deletes every added one, and takes back **only** the language keys the release added (the site's own keys stay). Then `php artisan optimize:clear`, reset OPcache, `php artisan queue:restart`. Refuses if a deployed file changed afterwards, unless `--force` | a minute |
| The migration must be undone | with the integration off: `php artisan migrate:rollback --force --path=database/migrations/2026_11_05_100100_add_fulfillment_provider_to_products_table.php --path=database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php` (drops our 11 tables and one nullable column; no store data). Shipments already at a provider are not recalled | a minute |
| The database itself was damaged | restore `db.sql.gz` from the phase-1 backup (`RESTORE.txt`). **This discards everything ordered since the backup.** Last resort | the length of the restore |
| The application directory was damaged | `tar -xzf files.tar.gz -C "$APP"` from the same backup (it also restores `.env` and `storage/`) | minutes |

The previous release ignores the new tables and the nullable column, so putting the old code back while the tables exist is safe.

---

## 11. Blockers and decisions

### Needs access or information from you (nothing below can be done without it)

| # | Needed | Why it blocks | Blocks |
|---|---|---|---|
| 1 | **Shell access to the server** (SSH or the hosting panel's terminal) as the user that owns the application, with PHP CLI ≥ 8.2, `mysqldump`, `mysql`, `tar`, `gzip`, `sha256sum`. If there is no shell at all, tell us: a manual (file-manager + phpMyAdmin) procedure is a different and riskier path that has **not** been prepared | every phase | 0–4 |
| 2 | The answers to phase 0: PHP version, file owner, how the queue runs, whether the cron line exists, whether the document root is `public/` or the application root | the queue and cron lines and the evidence/backup locations depend on them | 4, 6 |
| 3 | The **production database name** (for `--expect-database`) and a **maintenance window** | the read-only checks refuse a database they were not told to expect | 3, 4 |
| 4 | A place **off the server** for the backup copy | a backup on the same disk is not a backup | 1 |
| 5 | **M5AZN seller credentials** (and whether M5AZN offers a test account) | nothing can be read without them | 6–9 |
| 6 | **Torod Point / LogesTechs credentials:** Fulfillment API username and password, Lastmile e-mail and password, company ID **714**; and whether LogesTechs offers a **sandbox company** for write tests | same; and tests that write | 6–9 |
| 7 | **Written authorisation** from each provider for the controlled test orders (who receives them, who cancels them), and your own **Gate C** approval per provider ([live-verification](live-verification/README.md) levels) | M5AZN has no cancel API and Torod has no warehouse-order cancel: a test order cannot be undone from here | 8 |
| 8 | The **test products** (cheap, in stock at the provider, not a controlled medicine, no expiry blocking), a **staff customer account**, a **delivery address and phone that belong to staff** | a test order is a real parcel | 8 |
| 9 | LogesTechs' answer on **where stock is** in the Fulfillment API ([request](vendor-requests/logestechs-technical-request.md)) and M5AZN's on **response schemas, order statuses and cancellation** ([request](vendor-requests/m5azn-technical-request.md)) | until then stock-based availability for Torod products and M5AZN tracking rest on what the live read-only run shows | 7, 9 |
| 10 | **Webhook registration at LogesTechs** (they must be given the two URLs from the Torod page) | without it Torod status arrives by polling every 15 minutes only | 9 (advisory) |

### Decisions only you can take (found while preparing this; independent of the integration but they affect it)

| # | Finding | Decision |
|---|---|---|
| D1 | **`APP_KEY`.** `.env.example` carries a real-looking key. If production runs the same key, anyone with the repository can decrypt the provider credentials you are about to enter. `fulfillment:preflight` tells you without showing the key (*app key*: FAIL in production when it matches) | If it matches: rotate it **before** entering real credentials, knowing that everything else stored with `Crypt` on this platform (the Zender secret, MTN Cash data, sessions) must then be re-entered. If you accept the risk, say so in writing |
| D2 | **OTP codes are constant.** `config('app.mode')` is never defined, so every login/verification code is `123456` (6 digits) or `1234` (4 digits) | A production security defect unrelated to this PR. Fix or accept deliberately |
| D3 | **Secrets committed to the repository:** four ECDSA private-key files (`Apple-AuthKey*.p8`, two of them under `public/`, so web-served), the licence purchase key in `config/system-addons.php`, three public maintenance routes guarded by literal `-xk29` keys, a Paymera literal | Rotate whatever is real. None is used by this integration |
| D4 | The document root may be the application root (phase 0) | Point the web server at `public/`, or deny everything except `index.php`, `public/` and `storage/app/public` |
| D5 | Pre-existing debug writers log **OTP + phone** (`ZenderOtpService`), parcel bodies with name/phone/address (`DeliverySyriaService`) and gateway callbacks to `storage/logs`, and `laravel.log` is truncated hourly for non-local requests | Use `LOG_CHANNEL=daily`; remove the debug writers; decide log retention (the API log tables are this project's evidence trail) |
| D6 | The platform's own pending migrations, if `migrate:status` shows any (phase 0) | Separate change, separate backup and rehearsal. Not part of this deployment |

### Known limitations of the providers (not blockers, but they shape the plan)

* **M5AZN** documents no webhook, no cancel endpoint and no response schemas: tracking is polling only (every 15 minutes) and needs a
  verified `orders` map plus a status map; an order sent cannot be cancelled by the API.
* **Torod Point** documents no stock quantity in the product list, no warehouse-order cancel and no warehouse-order status lookup;
  the Lastmile API is used only for cities, package status, air waybill and package cancel — **its create-shipment endpoint is never called**
  (it would duplicate the warehouse's own shipment).
* Mixed baskets (a product from each provider) are shipped as two parcels and the customer is told so; cash on delivery is refused
  for them unless `FULFILLMENT_ALLOW_MIXED_COD=true` **and** every provider involved is marked *COD settlement verified*.

### What is verified and what is not

| Verified (automated, on SQLite and on real MySQL 8) | Not verified — needs the live server or the providers |
|---|---|
| the write guard, the approval gate, the go-live checklist and its audited override; no duplicate order under retries, ambiguous outcomes, concurrent workers; money, VAT and COD rules; the overlay build/verify/apply/rollback including drift and language-file merge; the backup and restore scripts; the two migrations on MySQL; the read-only schema check against a synthetic production-shaped schema | the schema, data and drift of **your** database and files; how long the migration takes on real tables; **both providers' real response shapes**; the order-creation response; webhook delivery from LogesTechs; real latency and rate limits; your queue/cron/OPcache setup |

*Nothing in the right-hand column is claimed to work. It is what phases 3, 6, 7 and 8 exist to establish.*

---

## Appendix: what was rehearsed and what was not

* `OverlayDeploymentTest` builds an overlay from two revisions of a synthetic repository and applies, drifts, merges language files,
  and rolls back on a synthetic "live" tree. `LeakGuardTest`, `GoLiveReadinessTest`, `LiveVerificationTest`, `ExternalWriteGuardTest`,
  `ApprovalGateTest` and `CompatibilityCommandTest` cover the rest of the tooling. CI runs them on SQLite (PHP 8.2 and 8.3) and the
  fulfillment suites on MySQL 8.
* `backup.sh`, `verify-backup.sh` and the two migrations (`--pretend`, run, rollback by path) were run against a real MySQL 8 with a
  production-shaped schema; the migrations took 423 ms and 28 ms on empty tables.
* **Not run:** anything against your server, your database or a provider. The sandbox this was prepared in has no route to any of them.
* The older [staging-validation.md](staging-validation.md) describes the same checks on a restored copy; it is kept for anyone who
  prefers to rehearse that way, and is **not** required for this deployment.
