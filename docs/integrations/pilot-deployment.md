# Pilot deployment on the existing Pharmacy KSA server

> **Status: prepared, not executed.** Nothing in this document has been run against the live server, the live database or a
> provider: this repository has no access to any of them. The tooling (`deploy.php`, the package builder, the backup scripts) and
> the code behind each gate are covered by automated tests, and the tooling was also run by hand on synthetic trees and a real
> MySQL 8 (see the [appendix](#appendix-what-was-checked-and-what-was-not)); what the live server will answer is not known until it
> is run. **Nothing is deployed, merged or switched on without your explicit approval** at the numbered gates.

The existing production instance is a pilot environment, so it is also the target. There is **no staging server and no second
copy of the application**: the integration is deployed to this server with everything switched off, then armed one level at a
time, with each level approved by you, backed up, and reversible.

## The sequence at a glance

| # | Phase | Changes | Needs your approval |
|---|---|---|---|
| 0 | Look at the server (read-only) | nothing | — |
| 1 | Fetch the package and check it (it carries the backup scripts) | nothing on the live tree | — |
| 2 | Full backup of database and files, proven restorable | writes a backup directory outside every web root | — |
| 3 | Check the package against the live files (drift) | nothing | **Gate A1 — put the files on the server** |
| 4a | Deploy the **files**, dormant, in a short maintenance window | the release's code files and 7 `.env` lines; **the database is not touched** | (Gate A1) |
| 4b | Read-only checks of the live schema with the new code | nothing | **Gate A2 — run the migration** (you read the report first) |
| 4c | The two additive migrations | 11 new tables and one nullable column on `products` | (Gate A2) |
| 5 | Smoke-test the deployed pages | nothing | — |
| 6 | Read-only integration: credentials, `Test connection`, `fulfillment:verify` | outbound **reads and logins only** | **Gate B — allow reads** |
| 7 | Record the real response schemas, finalise the field maps | our own settings | — |
| 8 | Controlled test orders | **real orders at the providers**, one at a time, each approved | **Gate C — allow writes** (per provider, in writing) |
| 9 | Turn automatic sending on | orders flow without a person | **Gate D — go live** (only when the checklist is met) |

The order of 4a–4c is deliberate. The new code is **dormant until its two migrations have run**: every place the existing order,
checkout, product and customer flows touch it asks first whether the tables exist, the scheduled commands do nothing, and the
Integration Center says what is missing (`UnmigratedStoreTest` proves each of these on a store without the tables). And the read-only
database check can only run once the code is on the server. So the files go first, you read the report, and only then does anything
touch the database.

Rollback for every phase is in [section 10](#10-rollback-for-every-phase). The remaining blockers are in [section 11](#11-blockers-and-decisions).

### What is armed after each phase

Everything is off after deployment. **Six** independent switches stand between the code and a provider; each one fails closed. Two
are lines in `.env`; four are on the provider's own page.

| Phase | `FULFILLMENT_INTEGRATIONS_ENABLED` | `FULFILLMENT_EXTERNAL_WRITES_ENABLED` | *Integration enabled* | *Send orders to this provider* | *Dry run* | *Send automatically (no approval per order)* | What can leave the server |
|---|---|---|---|---|---|---|---|
| 4a–4c deployed | false | false | off | off | on | off | **nothing** |
| 6 read-only | true | false | on | off | on | off | logins and reads (the transport refuses anything else) |
| 8, rehearsal | true | **false** | on | on | **on** | off | **nothing**: the order is held back by the write guard and, being a dry run, only *records* the request it would send |
| 8, controlled | true | **true** | on | on | **off** | **off** | an order, only when a person presses *Release for dispatch* / *Approve and send this order* on that order — and, because writes are on, a **cancellation** of an already accepted Torod Point shipment, sent by itself when its store order is cancelled (nothing asks you; M5AZN has no cancel API). Product pushes to the warehouse only if *Allow pushing products to the warehouse* is on |
| 9 live | true | true | on | on | off | **on** | every due order, and the same cancellations |

The write guard is in the transport (`ProviderHttp` / `ExternalOperations`), below the admin switches: with
`FULFILLMENT_EXTERNAL_WRITES_ENABLED=false` no order, product, stock or cancellation request can be sent whatever is ticked in the
admin panel, and an operation that is not classified as a read is treated as a write. `LiveVerificationTest` and
`ExternalWriteGuardTest` prove it, and the rehearsal row above is proved with the real adapters, not stand-ins.

**Which switches act at once.** The four provider-page switches are read from the database on every call: they act at once, for the
website, the scheduler and every queue worker. The two `.env` lines are read when a process starts: a web request or a cron run sees
a change immediately, **a long-running queue worker does not until it is restarted** (`php artisan queue:restart`, then confirm the
worker really restarted: `supervisorctl status` or `ps`). So after every change to a `FULFILLMENT_*` line, run `queue:restart`;
otherwise a worker that started earlier still sends under the old setting, or holds an order the page says it may send.

---

## 0. Look at the server first (read-only)

Run these as the user that owns the application files (the one that will run `deploy.php`; it is usually **not** the web server's
user). Set the paths once:

```bash
APP=/path/to/the/application                       # the directory that contains artisan and .env
PKG=/path/outside/every/web/root/overlay           # where the package will be unpacked (section 1)
BACKUPS=/path/outside/every/web/root/pharmacy-backups   # every backup goes here; NOT beside the application by default
DOCROOT=/the/document/root/the/hosting/panel/shows
for dir in "$BACKUPS" "$PKG"; do
  case "$(realpath -m "$dir")/" in "$(realpath -m "$DOCROOT")"/*) echo "STOP: $dir is inside the web root" ;; *) echo "ok: $dir is outside the web root" ;; esac
done
```

```bash
cd "$APP"
php -v | head -1                                   # CLI must be 8.2 or newer (composer.json: "php": "^8.2")
php artisan --version | head -1                    # Laravel 12.x
stat -c '%U:%G %a %n' artisan .env storage bootstrap/cache resources/lang/en/new-messages.php   # who owns the tree
id -un                                             # the user you are now; must be the owner (or root: ownership is then preserved)
grep -E '^(APP_ENV|DB_CONNECTION|QUEUE_CONNECTION|CACHE_DRIVER|SESSION_DRIVER|LOG_CHANNEL|FORCE_HTTPS)=' .env   # no secrets in this list
crontab -l 2>/dev/null | grep -n artisan           # is schedule:run installed? (a hosting panel may hold it instead)
ps aux | grep -E 'queue:(work|listen)' | grep -v grep   # is a queue worker running, and for which queues?
df -h "$(dirname "$APP")" "$BACKUPS" 2>/dev/null   # room for the backup (backup.sh checks too)
command -v mysqldump mysql tar gzip sha256sum      # all five are needed by backup.sh
grep -c setClassMapAuthoritative vendor/composer/autoload_real.php   # must print 0 (see below)
php -i | grep -E '^pcre\.jit'                      # 1 is normal; 0 only makes the personal-data scan slower (it is bounded either way)
php artisan tinker --execute="echo Schema::hasTable('audit_logs') ? 'audit_logs: present' : 'audit_logs: MISSING';"
```

Write down: the PHP version, who owns the tree, **how the queue is run** (Supervisor, a cron-driven `queue:work --stop-when-empty`,
or `QUEUE_CONNECTION=sync`), whether the cron line exists, where the document root is, and the two answers below. Those decide
steps 4 and 8.

* **`setClassMapAuthoritative` must print `0`.** The package adds classes without regenerating Composer's class map; that works
  through Composer's PSR-4 fallback. If the server's `vendor` was optimised with `--classmap-authoritative` (the line is then
  non-zero), every request and every `artisan` command would fail with *Class not found* right after the files land. In that case
  tell us before going on: running `composer dump-autoload` is a deliberate step of its own, not part of this package.
* **`audit_logs` must be present.** The platform's audit table comes from the installation dump, not from any migration. If it is
  missing, nothing an operator does here (release, cancel, link, the go-live override) is written to an audit trail, and
  `fulfillment:preflight` will say so. The go-live override is still kept on the provider's settings, but decide whether that
  is enough for you before Gate C.

**What the web server will hand out.** This platform can be served from the application's root directory instead of `public/`
(`index.php` defines `DOMAIN_POINTED_DIRECTORY = 'root'`), and there any existing file is downloadable by its address: the root
`.htaccess` only blocks `.env`. Check, from any machine:

```bash
for path in .env storage/logs/laravel.log composer.json .git/config \
            public/assets/back-end/Apple-AuthKey.p8 'public/assets/back-end/Apple-AuthKey%20(copy).txt'; do
  printf '%-64s' "$path"; curl -s -o /dev/null -w '%{http_code}\n' "https://YOUR-DOMAIN/$path"
done
```

Every line must be `403` or `404`. A `200` on `storage/logs/laravel.log` or `.git/config` is a pre-existing exposure (the log holds
order and OTP data), and a `200` on either of the last two lines means a private key committed to this repository is downloadable
(D3 below): both should be closed regardless of this project. **Do not store evidence, packages or backups under the web root.** The
evidence directory ships its own `.htaccess` denying access, but that only works on Apache. If the application sits in a
subdirectory of the document root (`public_html/shop`), the *default* backup folders beside it would be inside the web root: that is
why every command below names its own directory under `$BACKUPS`.

Also run, still read-only:

```bash
php artisan migrate:status | tail -n 25            # what is pending today (we will run only OUR two migrations, never a bare `migrate`)
```

(`fulfillment:preflight` and `fulfillment:compatibility` exist only once the files are deployed; they run in phase 4b.)

---

## 1. Fetch the package and check it

The package (the "overlay") is exactly what changes on the server: the **added and modified files of PR #1** under `app/`,
`bootstrap/`, `config/`, `database/`, `resources/` and `routes/`, each with the checksum of its baseline version. It contains no
dependency change, no file under `public/` or `storage/`, and no deletion. It also carries, beside what is installed:

| In the package | What it is for |
|---|---|
| `deploy.php` | `verify`, `apply` and `rollback` (needs only PHP) |
| `files/` | the release's version of every added or modified file: **this is what is installed** |
| `base/` | the baseline version of every file the release *modifies*: what the release was built against, so you can see exactly what someone changed on the server |
| `tools/` | `backup.sh`, `verify-backup.sh`, `mysql-defaults.php`: the backup scripts (they must run **before** anything is installed, and are never copied into the application) |
| `MANIFEST.json`, `SHA256SUMS` | the file list (with `base` and `head`: the two commits it was built between) and the checksum of everything in the package |

* **Where it comes from:** the `Deployment overlay` job of the CI run on the pull request uploads `pilot-overlay-<commit>`
  (`overlay.tar.gz` + `overlay.tar.gz.sha256`). The job builds from the commit the server runs (`34dce65`, or the repository variable
  `PILOT_BASELINE` if set) to the pull request's own head commit. To build it yourself, into a **new or empty** directory:
  `php scripts/deploy/build-overlay.php --base=34dce65 --head=<release commit> --out=build/overlay --archive` (the builder
  refuses a non-empty `--out`, so nothing from an earlier build can end up in the package).
* **What is in it:** compare the numbers `deploy.php verify` prints (phase 3) with `MANIFEST.json` of the very package you unpacked:
  `php -r '$m = json_decode(file_get_contents($argv[1]), true); echo count($m["files"]), " files, base ", $m["base"], ", head ", $m["head"], PHP_EOL;' "$PKG/MANIFEST.json"`.
  Of the modified existing files, these are the ones that can have drifted on the server:

  | Modified existing files | Why |
  |---|---|
  | `app/Utils/OrderManager.php`, `app/Repositories/OrderRepository.php`, `app/Models/Order.php`, `app/Models/Product.php` | checkout hook, snapshot, delivered/closed guards |
  | `app/Http/Controllers/{Admin,Vendor}/Order/OrderController.php`, `RestAPI/v1/OrderController.php`, `RestAPI/v2/seller/OrderController.php`, `RestAPI/v3/seller/OrderController.php`, `RestAPI/v2/delivery_man/DeliveryManController.php`, `RestAPI/v1/CustomerController.php`, `Web/WebController.php` | an order cannot be marked delivered, reopened or edited while a provider shipment is unfinished; the mixed-shipment notice |
  | `app/Services/OrderEditService.php`, `app/Traits/OrderEditManager.php` | orders with provider lines cannot be edited |
  | `app/Http/Controllers/Admin/Product/ProductController.php`, two product-form views | the *Fulfillment provider* selector |
  | `app/Http/Controllers/Admin/ZenderSettingController.php`, `routes/admin/routes.php` | pre-existing unauthenticated admin routes now require the admin guard ([SEC-1](security-findings.md)) |
  | `app/Providers/RouteServiceProvider.php`, `bootstrap/app.php`, `bootstrap/providers.php`, `routes/rest_api/v1/api.php`, `app/Console/ScheduleDefinition.php`, `config/queue.php` | registers our routes, provider, schedule and `retry_after` |
  | the three `resources/lang/*/new-messages.php`, order-detail/tracking/sidebar views | text and the provider card |

* **The package refuses to be built** when the release deletes a file, changes `composer.*`/`package.json`, touches `public/`,
  `Modules/` or the web entry point, contains a symbolic link or submodule, or changes only a file's mode: those need a person's
  decision and are named in the error.
* **Transfer it outside the web root**, check it, unpack it:

  ```bash
  sha256sum -c overlay.tar.gz.sha256                 # in the directory where you put both files (NOT under the document root)
  mkdir -p "$PKG" && tar -xzf overlay.tar.gz -C "$PKG"   # the archive has no top-level folder
  ls "$PKG"                                          # MANIFEST.json  SHA256SUMS  base  deploy.php  files  tools
  (cd "$PKG" && sha256sum -c SHA256SUMS | grep -v ': OK$' ; echo "(nothing listed above = every file matches its checksum)")
  ```

  The `.sha256` next to the download is the anchor: a package edited after it was built passes only its own `SHA256SUMS`.
* **Which release is on the server afterwards.** `apply` keeps this package's `MANIFEST.json` in its backup directory. Its `head` is
  the commit now deployed. The **next** package must be built from there (`--base=<that head>`, or set the repository variable
  `PILOT_BASELINE` to it), otherwise every file the first release added would show as a `CONFLICT`.

---

## 2. Full backup, proven restorable

A backup you have not restored is a hope. This produces one directory holding the database dump, the application files, row
counts and checksums, and then proves the dump loads. The scripts live in the package (`tools/`); keep the three files side by side
and run them with `bash`.

```bash
bash "$PKG/tools/backup.sh" --app="$APP" --to="$BACKUPS/data"          # prints the directory it made: <UTC stamp> inside it
# smaller file backup if disk is tight:  --no-vendor --no-uploads   (the database is always complete)
bash "$PKG/tools/verify-backup.sh" --backup=<the directory backup.sh printed> --app="$APP"
```

What it guarantees, and what it refuses:

* **Database:** one consistent read (`--single-transaction`, routines, triggers, events, `utf8mb4`), `gzip -t` plus a check that the
  dump ends with mysqldump's completion line, and row counts of ten key tables (`orders`, `order_details`, `products`, `users`,
  `business_settings`, `migrations`, `jobs`, `failed_jobs`, `admins`, `integration_settings`) written to `row-counts.txt`.
  The counts are taken just after the dump, on a live site: `jobs` and `orders` may legitimately differ by a few rows.
* **Files:** the whole application directory (including `.env` and `storage/app`) except `.git`, logs, caches and compiled views,
  and the archive is read back.
* **Safety:** the database password is never on a command line or in the output (a mode-600 option file in a temporary directory);
  the backup directory is mode 700; the script refuses a destination **inside the application tree, however the path is written**
  (relative, with `..`, or through a link); it checks free disk; and a run that stops early leaves no empty dated directory behind.
  `--db-only` and `--files-only` each work alone and are mutually exclusive.
* `verify-backup.sh` loads the dump into a scratch database it creates and drops, and compares the row counts. If the MySQL
  account may not create databases (common on shared hosting), import `db.sql.gz` into a new empty database with phpMyAdmin and
  compare `row-counts.txt` by hand. **That comparison is the proof; do not skip it.**
* Copy the directory **off the server** to storage you control (it contains `.env` and customer data: never GitHub, never a
  third-party service). `RESTORE.txt` inside the backup explains the restore; [section 10](#10-rollback-for-every-phase) says what
  else a database restore needs.

Tell us the backup directory name and the two numbers (`orders`, `products` counts) so they are on record before anything changes.

---

## 3. Check what can be checked before anything changes

The overlay checker compares each live file with the baseline it was built against. **It changes nothing.**

```bash
php "$PKG/deploy.php" verify --app="$APP" --package="$PKG"
```

The report gives every file a state:

| State | Meaning | What to do |
|---|---|---|
| `NEW` | does not exist on the server; will be created | nothing |
| `CLEAN` | identical to the baseline the release was built against; will be replaced | nothing |
| `MERGE` | a language file: the release's keys are merged into the live file; nothing the site added is lost | nothing |
| `ALREADY_APPLIED` | already identical to the release (a language file already holding every key of the release counts) | nothing |
| `ACCEPTED` | differs from the baseline or from an added file's name, and a person named it with `--accept-drift` (its live version is backed up first) | — |
| **`DRIFTED`** | an existing file differs from the baseline (a hotfix, a customisation, a runtime rewrite) | **read what changed**: `diff -u "$PKG/base/<path>" "$APP/<path>"` shows exactly the server's own changes, without the release's. Accept it by name with `--accept-drift=path,path` only after a person has looked |
| **`CONFLICT`** | a file with an *added* file's name already exists and differs | stop; ask us |
| **`MISSING`** | the release changes a file that is not on the server: this is not the code it was built against | stop; ask us |
| **`UNPARSEABLE`** | this server's PHP cannot parse the release's version (the release needs a newer PHP) | stop; ask us |
| **`UNREADABLE`** | a live language file does not parse or is empty (the site was probably writing it when it was cut off) | stop; fix the file; ask us |
| **`BLOCKED`** | a directory exists where the release puts a file, or a file where it needs a directory | stop; ask us |
| **`SYMLINK`** / **`UNWRITABLE`** | a link at, or on the way to, the file / the deploy user cannot write there | stop; ask us |

Exit code 0 means no problem; `apply` refuses to run while any file is in a bold state, **before it writes anything**, so a refusal
leaves the tree exactly as it was. A `DRIFTED` file in an order or checkout controller deserves a real look: it means someone changed
production code that PR #1 also changes, and the replacement would silently remove their change.

`verify` also prints one line when you are not the owner of the application's files: what the script writes would then belong to
you, and the web server may be unable to write it (the language files **must** stay writable by the web server: the platform rewrites
them while it runs). Run it as the owner, or as root (then every file keeps the owner and group it had, and new ones take their
directory's).

The database cannot be checked yet: the commands that do it ship with the code. They run in phase 4b, **after the files are on the
server and before anything touches the database**, and you read their report before approving the migration.

> **Gate A1 — you approve putting the files on the server.** The backup is verified and copied off the server, `verify` shows no
> unexplained `DRIFTED` file (and no other bold state), and you have chosen the window. This gate covers **the files only**: they are
> dormant until the migration, and `deploy.php rollback` takes every one of them back.

---

## 4. Deploy: the files first, then the read-only database checks, then the migration

### 4a. The files (about two minutes; the database is not touched)

Use `php artisan down`, **not** the platform's admin "maintenance mode" switch: the admin switch also sends a push notification to
every user. Files are replaced one at a time and atomically, **new files first** (so nothing a replaced file refers to is missing),
and the code is dormant until the migration exists, so the window only covers the checkout code being replaced while an order is
being placed. Choose a quiet hour, and run this inside `tmux` or `screen` so a dropped connection cannot cut it off.

```bash
cd "$APP"
STAMP=$(date -u +%Y%m%d-%H%M%S)
php artisan down --retry=60                                   # customers see a 503 page and may retry in a minute

php "$PKG/deploy.php" apply --app="$APP" --package="$PKG"                 # dry run: prints the same report, changes nothing
php "$PKG/deploy.php" apply --app="$APP" --package="$PKG" --yes --backup-dir="$BACKUPS/deploy/$STAMP"
#   [--accept-drift=app/Some/File.php]   only for files you have looked at
```

`apply` prints `[ok] N file(s) written. The originals are in …` and `To undo: php /…/deploy.php rollback --backup-dir=…`. **Keep that
line.** It copied each file it replaced to `$BACKUPS/deploy/$STAMP/files/` (mode 600), kept the release's `MANIFEST.json` there, and
wrote `applied.json`. If everything is already in place it says `Nothing to apply` and creates no backup.

**What `apply` does when something goes wrong:**

| What happened | What `apply` does | What you do |
|---|---|---|
| A check fails before anything is written (a bold state in section 3) | refuses, writes nothing, makes no backup directory | resolve it, or ask us |
| A write fails part-way (full disk, a permission) | **takes back everything it had written**, says so, exits 2 | read the error; the site is as it was |
| It cannot take it back (a file changed in the meantime) | exits 2, names the file and the `rollback` command | keep the site down; run the `rollback` command; `--force` only after looking at the file |
| The process is killed (SSH dropped, out of memory) | `applied.json` in the backup directory already lists every file it had written **or was about to write** (it is written *before* each file) | reconnect, **do not start a second `apply`** (it refuses while an unfinished one has not been rolled back): `ls -t "$BACKUPS/deploy" \| head -1`, then `php "$PKG/deploy.php" rollback --backup-dir="$BACKUPS/deploy/<that directory>"` |

Then the `.env` lines, the caches, and the check that the application still starts:

```bash
# the .env lines (append; nothing is overwritten). The integration stays OFF.
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

php artisan optimize:clear                                     # routine; NEVER run config:cache, route:cache or optimize on this platform
php artisan queue:restart                                      # long-running workers must load the new code (and every later .env change)
# reload PHP-FPM / "Reset OPcache" in the hosting panel if opcache.validate_timestamps is off; otherwise new code is seen within seconds
stat -c '%U:%G %a %n' resources/lang/*/new-messages.php        # same owner and group as in phase 0, and writable by the web server's user
php artisan --version && php artisan up                        # if the first command fails the application does not start: see section 10
```

`QUEUE_RETRY_AFTER=180` is the one line that touches something the platform already does: its own queued jobs are now handed to a second
worker after 180 s instead of 90 s (`config/queue.php`). Fulfillment jobs may run for 120 s, and a job that is still running must not be
handed to another worker.

State now: the new code is on the server and does nothing. The store behaves exactly as before: checkout, order and product pages do
not notice it, the scheduled `fulfillment:*` commands print *"The fulfillment migrations have not been run yet; nothing to do"*, and
the Integration Center (*3rd Party Setup → Fulfillment providers*) says its tables do not exist yet instead of failing.

### 4b. Read-only checks of the live schema — then you decide

```bash
php artisan migrate:status | tail -n 25
php artisan fulfillment:preflight --allow-production-read-only          # schema, safety, runtime; exit 1 on any FAIL
php artisan fulfillment:compatibility --allow-production-read-only --expect-database=<the production database name>
php artisan migrate --pretend --force --path=database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php \
                                      --path=database/migrations/2026_11_05_100100_add_fulfillment_provider_to_products_table.php
```

* `fulfillment:compatibility` inspects **column types, nullability, string lengths, engine and character set** of every table the
  integration reads or writes (`orders`, `order_details`, `products`, `business_settings`, `currencies`, `admins`, `admin_roles`,
  `categories`, `carts`, `attributes`, `stock_movements`), and samples the newest 2000 orders and lines for the *shapes* the new code
  depends on (address JSON keys, payment and order status values in use, variation formats). It reads in a session locked read-only
  (`SET SESSION TRANSACTION READ ONLY`), runs SELECT statements only (proved by a statement log in its tests), and prints **counts,
  names and shapes — never a row**. It refuses to run in production without `--allow-production-read-only`, and refuses a database whose
  name is not the one you give.
* `fulfillment:preflight` reports whether `APP_KEY` is the public example key (see D1 in [section 11](#11-blockers-and-decisions); if
  `.env.example` is not on the server it says it **could not compare**, which is a warning, not a pass), whether the platform's
  `audit_logs` table exists, the queue and cache drivers, the scheduler heartbeat, `retry_after`, and the state of the two migrations
  (*2 pending* is the expected answer here). From the command line it cannot judge the trusted-proxy setting (that needs a web request:
  the Integration Center shows it). It does **not** detect a missing cron line or a worker that ignores the `fulfillment` queue while the
  integration is switched off: check those yourself (phase 0 and 4c).
* `migrate --pretend` prints SQL without running it, and **cannot show the whole change**: pretend mode answers every "does it exist?"
  with *no*. So it prints twelve `create table` statements (it also tries `integration_settings`, which the platform already has: the
  real run skips it and eleven tables are created), their index statements and some probe `select`s, and it **does not print the
  `alter table products` at all**. That one change, the only one that touches an existing table, is previewed by the
  `products.fulfillment_provider` line of `fulfillment:preflight` (a nullable, indexed column), and confirmed after the real run by the
  same line reading *present*. In production even `--pretend` asks *"Application in production"*; `--force`
  answers it.

Send us the output (it holds counts, names and shapes, no row of customer data). **A FAIL stops here:** nothing in the database has
changed, and `deploy.php rollback` returns the files (section 10). Do not run the migration, and do not call phase 4 finished.

> **Gate A2 — you approve the migration**, having read the report.

### 4c. The migration (about a minute)

Only our two migrations, by path. **Never a bare `php artisan migrate`.**

```bash
php artisan down --retry=60                    # recommended: the `alter table products` takes a brief metadata lock
bash "$PKG/tools/backup.sh" --app="$APP" --to="$BACKUPS/data" --db-only    # the restore point for the migration: taken NOW, not days ago
php artisan migrate --path=database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php \
                    --path=database/migrations/2026_11_05_100100_add_fulfillment_provider_to_products_table.php
#   (APP_ENV=production asks "Do you really wish to run this command?" — answer yes. --force only for a non-interactive shell.)
php artisan optimize:clear
php artisan queue:restart                      # a running worker caches "has the migration run?"
php artisan fulfillment:preflight --allow-production-read-only        # schema: both migrations applied, all tables present; products.fulfillment_provider: present
php artisan fulfillment:compatibility --allow-production-read-only --expect-database=<the production database name>
# Only if neither command printed a FAIL:
php artisan up
```

(In phpMyAdmin the same change reads: `products` has a nullable, indexed `fulfillment_provider` column.)

If either command prints a **FAIL, stay down** and go to section 10. Why only two migrations, by path: the platform has about 300
migrations of its own, and one of them (`2026_05_07_000001`) rebuilds 35 large tables to `DECIMAL(40,20)` and cannot be reversed
losslessly. **Do not run a bare `migrate`**, whatever `migrate:status` shows as pending. If `migrate:status` lists platform migrations
as pending, that is a pre-existing condition to be handled separately, with its own backup and rehearsal.

The queue worker and the cron job must include the new work, and this is the part only your hosting setup can answer (phase 0):

* **A worker is running (Supervisor):** it must listen on the `fulfillment` queue — `queue:work --queue=fulfillment,default --sleep=3
  --tries=1 --timeout=150 --max-time=3600` ([operations §1](operations.md)). A worker started without `--queue=fulfillment` never
  sees our jobs. Nothing checks that for you while the integration is off, and afterwards the go-live item *Queue worker keeping up*
  only notices when a job has been waiting more than ten minutes.
* **`QUEUE_CONNECTION=sync`:** supported. A provider call never runs inside a *customer's* request: the per-minute sweep does the
  work. A person's own action (*Release for dispatch*, *Cancel*, confirming an order was not created) runs **inside their admin
  request**: the page waits for the provider's answer (each call is limited to about 20 seconds). A real queue (`database` or `redis`
  with a worker as above) is nicer for Gate D; `sync` works for the controlled phase.
* **No cron line for `schedule:run`:** add it with **absolute** paths (cron has no `$APP`, and a pasted `cd ""` is a silent no-op):
  `* * * * * cd /path/to/the/application && /usr/bin/php artisan schedule:run >> /dev/null 2>&1`. Without it nothing is dispatched,
  polled or reconciled; the Integration Center's *scheduler heartbeat* says so once the integration is on.

Deployment is done when `fulfillment:preflight` shows no FAIL (an `app key` FAIL you have decided in writing to accept under D1 is
the one exception) and the site is up. **Nothing is switched on:** no provider has been contacted, and none can be.

---

## 5. Smoke-test what was deployed (nothing here writes or sends)

After 4a, the store pages; after 4c, the Integration Center too.

* Storefront: home, a category, a product page, the cart, the checkout page (do **not** place an order).
* Admin: sign in; **3rd Party Setup → Fulfillment providers** (`/admin/fulfillment-providers`) shows *Integration mode on this
  server* as **OFF — no outbound call of any kind**, both provider cards switched off, and *System checks* with no red; open **M5AZN**
  and **Torod Point**: both pages render, credentials show as not configured, and the *Before automatic sending* checklist lists what is
  missing.
* Admin: open an existing order: it shows **no** provider card at all (the *Provider shipments* card appears only on an order that
  has provider shipments), and the product form (*Fulfillment provider* defaults to *In-house*, which is every existing product).
* `php artisan tinker --execute="echo DB::table('provider_api_logs')->count();"` prints `0`: no provider call has been logged
  (*Integration Center → API logs* shows the same).
* `tail -n 50 storage/logs/laravel.log` shows nothing new that mentions `Fulfillment`.

If anything is wrong: [section 10](#10-rollback-for-every-phase).

---

## 6. Read-only integration

> **Gate B — you allow reads.** From here the server makes outbound calls that can only *read* (and sign in). You provide the
> credentials; we never see them.

1. In `.env` set `FULFILLMENT_INTEGRATIONS_ENABLED=true` (keep `FULFILLMENT_EXTERNAL_WRITES_ENABLED=false`), then
   `php artisan queue:restart`. The mode reads **READ-ONLY — logins and reads only; nothing is written to a provider**.
2. **M5AZN page:** *Integration enabled* on; seller e-mail and password; *Pull the products selected on M5AZN* on; *Save settings*.
   Leave *Send orders to this provider* off and *Dry run* on.
   **Torod Point page:** *Integration enabled* on; company ID **714**; the Fulfillment API username and password; the Lastmile e-mail
   and password; *Read warehouse products* on (without it *Sync warehouse products now* does nothing); *Save settings*. Credentials are
   write-only and stored encrypted (`Crypt` with `APP_KEY`; see the `APP_KEY` decision in [section 11](#11-blockers-and-decisions)
   **before** typing real credentials).
3. **Test connection** on each page.
4. **Run the read-only checks** (the *Live verification (read-only)* card on the page, or `php artisan fulfillment:verify m5azn` /
   `torod_point`):

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

**What is stored.** Each run is one JSON file in `storage/app/fulfillment-evidence/<provider>/` (mode 640) containing the *shape*
of every answer: key names, types, counts, ranges, codes and status words. It is built to leave out credentials, tokens and
personal data: a value under a key that names a person, a contact detail, an address or an identity is not recorded; nor is any text
containing an e-mail address or a phone number; and sign-ins, order lists, order details and package status are recorded
**strictly** (only codes, counts, ranges and short status words, in any language). The API log applies the same rules to anything
about orders, packages and sign-ins. **It cannot recognise a name or an address under a key it has no reason to suspect**, so read a
file before you share it. A file that cannot be written (a full disk, a permission) turns the run into a failure instead of
quietly losing it.

The directory ships an `.htaccess` that denies direct access: that works on Apache with `AllowOverride` permitting it. On nginx or
LiteSpeed, or with `AllowOverride None`, add the equivalent rule yourself, and after the first run check from outside that
`https://YOUR-DOMAIN/storage/app/fulfillment-evidence/` answers 403 or 404 when the application is served from its root directory.

---

## 7. Record the real response schemas and finalise the field maps

Neither provider publishes response schemas for the calls the integration depends on, so **no field map is trusted until you have
confirmed it against a real answer**. That is what the evidence is for.

1. Download the latest evidence (*Download the evidence (JSON)*) and read the findings for each check.
2. On each provider page, under *Response field maps*: **Fetch a real sample** shows a redacted sample and a proposed path for every
   logical field; **Use proposed paths** or type the path; **Save and verify**. A map is *Verified* only if every required field
   resolves in most records of a *fresh* sample; changing any path un-verifies it.

   | Provider | Maps | Required fields |
   |---|---|---|
   | M5AZN | `catalog`, `inventory`, `orders` | id, name · id, stock · reference, status |
   | Torod Point | `products`, `orders` | id, name · invoice number, reference |

3. M5AZN only: fill the **status map** (provider status word → shipment status) from the vocabulary the evidence shows; the order
   poll is inactive until it exists and the `orders` map is verified.
4. Torod only: when the stock field is established (hand count + LogesTechs), tick **I confirm the stock field in the product list is
   the available quantity** (the moment is recorded). Without it stock stays unused.
5. Keep the evidence: add the reviewed JSON files to the repository under `docs/integrations/recorded-schemas/<provider>/` through
   a pull request, so the recorded schemas outlive the server. The evidence is built to hold no credential and no personal data, but
   **read each file before you commit it**: the CI leak guard looks only for credentials, keys and tokens, e-mail addresses and phone
   numbers; it does not know a customer's name, street address or note from a field name.

*Order creation responses are the one schema that cannot be recorded read-only.* It is recorded from the first controlled test
order (section 8): the API log keeps the redacted response, and you then fill in **Where the provider order reference is in its
response**. Until then a `2xx` without a recognisable reference is *outcome unknown* and is **never** re-sent.

---

## 8. Controlled test orders

> **Gate C — you allow writes, per provider, in writing.** This is the first moment a real order can reach a provider. The full plan
> is in [controlled-test-orders.md](controlled-test-orders.md); this section is the switch sequence.

Prerequisites, all from the go-live checklist (*Before automatic sending*, on each provider page): credentials saved, a passing live
verification less than 14 days old, verified field maps, at least one product assigned to the provider **and** an approved mapping
for it, the scheduler and queue running. In addition:

* **Torod Point only — the webhook must be set up first.** A Torod package barcode is learned only from a webhook event that names
  our invoice number and amount, so without webhooks **no status update ever arrives** and the *A status update received and
  understood* item can never be met. On the Torod page: **Generate webhook secret** (the URLs are shown once; copy them), turn
  **Accept webhooks** on, give both URLs to LogesTechs, and have them confirm a delivery ([operations §3](operations.md)).
* How the queue runs is known (phase 0), and after every `.env` change in this phase `php artisan queue:restart` was run.

1. Take a fresh database backup: `bash "$PKG/tools/backup.sh" --app="$APP" --to="$BACKUPS/data" --db-only`.
2. Assign **only the test product(s)** to the provider (*Product form → Fulfillment provider*). Every other product stays in-house.
3. **Rehearse first, with nothing able to leave.** Provider page: *Send orders to this provider* on, **Dry run on**, *Send automatically*
   off; `FULFILLMENT_EXTERNAL_WRITES_ENABLED` still `false`. Place the test order as a staff customer. The shipment becomes **Dry run**,
   and its page shows **Request recorded by the dry run**: the request that would be sent (phone and e-mail masked), with its
   fingerprint. Read it against the provider's documentation: product and SKU ids, quantities, amounts, city, the reference, the
   cash-on-delivery amount. (Neither provider's request carries a currency: see [controlled-test-orders.md](controlled-test-orders.md).)
4. Only when that request is right: set `FULFILLMENT_EXTERNAL_WRITES_ENABLED=true`, run `php artisan queue:restart`, and turn **Dry run
   off** (*Save settings*). The mode reads **LIVE — writes to providers are allowed (the provider switches decide)**. Nothing is sent by
   either change: a recorded dry run is never sent by itself, not even by the sweep.
5. Open that shipment and press **Release for dispatch**. That sends this one order, now (with a queue worker: as soon as it picks the
   job up; without one: inside your request). Any other due order waits *Waiting for an operator to approve sending this order* until a
   person approves it (*Approve and send this order*). The request is built again at that moment from the order, mapping, city and
   settings as they are then, so **review and release back to back**, and rehearse again after any change to those.
6. Follow it by hand (the plan lists what to look at after each step: acceptance and the reference, the first status update, delivery).
   The first accepted response is the one schema the read-only run could not record; fill in **Where the provider order reference
   is in its response** from the API log row.
7. When the test window ends: set `FULFILLMENT_EXTERNAL_WRITES_ENABLED=false` again, `php artisan queue:restart`, and confirm the mode
   reads **READ-ONLY**. The server is back to read-only until the next authorised window. (Until then a store order that is
   cancelled also asks Torod Point to cancel its accepted package: see the armed table.)

---

## 9. Turning automatic sending on

> **Gate D — you approve going live**, one provider at a time, after the controlled orders succeeded.

The page decides, not a person's memory. *Before automatic sending* is derived from what really happened on this server:

| Item | Met when |
|---|---|
| Credentials saved · Integration switched on · Sending to providers allowed on this server | the three switches above |
| Live verification passed recently | the latest run has no failure and is under 14 days old (a run in which nothing was asked does not count) |
| Field maps verified | every map of the provider |
| Products assigned and mapped | at least one product assigned to this provider **that has an approved mapping** (a mapping on a product that is not assigned does not count) |
| Status tracking ready | M5AZN: status map + verified orders map; Torod: company ID |
| Scheduler running · Queue worker keeping up | a sweep in the last 5 minutes · no fulfillment job waiting over 10 minutes (a queue driver that cannot be measured is reported as such) |
| **One real order accepted by the provider** | a shipment the provider's own answer was read as accepting, at dispatch or when a lookup found the order there. **A reference an operator typed in to link a shipment by hand does not count** |
| **A status update received and understood** | a webhook or poll event that matched one of this provider's shipments and a known status (for Torod Point: see the webhook prerequisite in section 8) |
| No shipment waiting for a decision | none *outcome unknown* / *needs attention* |
| *Advisory (never blocks)* | stock synchronisation running · order reference location named · cash on delivery settlement verified |

Turning **Send automatically (no approval per order)** on works like this, in one transaction under the provider's row lock:
everything you submitted is saved first with automatic sending held off, the checklist is judged on the state that now really exists
(a prerequisite the same save removes counts against it; one it restores counts for it), and only then is automatic sending switched
on. While items are unmet it stays off, the rest of the form is kept, and the unmet items are named.

Only the **master admin** may go ahead anyway, with the **override** checkbox **and a reason** (at least ten characters). The unmet
items, the person, the time and the reason are kept on the provider's settings and shown on its page for as long as automatic sending
runs on that decision. They are also written to the platform's audit trail **when the server has one**: if `audit_logs` is missing
(phase 0; `fulfillment:preflight` warns) only the provider's own record exists. Do not use the override casually: an unmet *One real
order accepted* means nothing has ever proved the write path.

A page left open from before an emergency stop cannot switch sending back on by saving something else: the page posts each arming
switch (*Integration enabled*, *Send orders to this provider*, *Dry run*, *Send automatically*) together with the value it showed, and a
switch you did not touch keeps whatever is stored at that moment. Saving a field map or rotating a webhook secret never rewrites the
*Integration enabled* switch either.

On the first day: keep the Integration Center open, read *Held / Failed / Outcome unknown / Needs attention* every few hours, and
leave the rollback in section 10 within reach. Orders placed **before** the go-live moment are held (`hold_before_go_live`) and are never
swept out by the switch; release the ones you want sent.

---

## 10. Rollback for every phase

| If this goes wrong… | Do | Cost |
|---|---|---|
| **Stop everything now** | 1. On **both** provider pages untick *Integration enabled* and save: this is read from the database on every call, so it stops the website, the scheduler **and every queue worker** at once. 2. Then set `FULFILLMENT_INTEGRATIONS_ENABLED=false` and `FULFILLMENT_EXTERNAL_WRITES_ENABLED=false` in `.env` and run `php artisan queue:restart`; **confirm the worker restarted** (`supervisorctl status`, or `ps` start time). A queue worker started before the edit keeps the old values until then. A request already on its way to a provider cannot be recalled | seconds, then a minute |
| Automatic sending misbehaves | untick *Send automatically (no approval per order)* (read from the database: instant): every due order goes back to waiting for approval | seconds |
| A shipment was sent that should not have been | it cannot be recalled by us: M5AZN documents no cancel endpoint and Torod no warehouse-order cancel; use the provider's dashboard/contact. The shipment page records the reference and everything sent | per order |
| The read-only checks show a FAIL (phase 4b) | nothing in the database has changed and the files do nothing: leave them while we read the report, or take them back with the next row. Do **not** run the migration | — |
| `apply` failed or was interrupted | see the table in 4a: it takes back what it wrote; if it was killed, run its `rollback` with that backup directory. The site stays in maintenance mode until the application starts and `fulfillment:preflight` is clean | minutes |
| The new code misbehaves (phase 4a–5) | **If the migration has run, undo it first (next row), while its files are still on the server.** Then `php "$PKG/deploy.php" rollback --backup-dir=$BACKUPS/deploy/<the directory apply printed>` restores every replaced file, deletes every added one, removes cut-off temporary files, and takes back **only** the language keys the release added (the site's own keys stay). Running it twice says it was already rolled back. It refuses if a deployed file changed afterwards, unless `--force`. Then `php artisan optimize:clear`, reset OPcache, `php artisan queue:restart` | a minute |
| The application does not start after the files landed (for example *Class not found*) | `php artisan up` cannot run either. Run the `deploy.php rollback` above, then `rm storage/framework/down` to leave maintenance mode | a minute |
| The migration must be undone | with the integration off, and after `backup.sh --db-only` (the provider tables hold the only local record of provider references: keep that dump). `php artisan migrate:status` shows the **Batch** of our two migrations; if they are the last batch: `php artisan migrate:rollback --force --path=database/migrations/2026_11_05_100100_add_fulfillment_provider_to_products_table.php --path=database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php`; if any other migration ran after them, add `--batch=<their batch>` (otherwise it rolls back nothing). It drops our 11 tables and the one nullable column; **it leaves `integration_settings`** (which holds the saved, encrypted credentials) and every store table. Shipments already at a provider are not recalled | a minute |
| The database itself was damaged | last resort, and it **discards everything ordered since the backup**. 1. `php artisan down`. 2. `backup.sh --db-only` of the damaged state, so even this is reversible. 3. Undo our migrations first (previous row), or drop our 11 tables by name (`provider_fulfillments`, `provider_fulfillment_items`, `provider_fulfillment_events`, `provider_api_logs`, `provider_sync_states`, `provider_product_mappings`, `provider_catalog_items`, `provider_price_changes`, `provider_cities`, `provider_city_aliases`, `product_provider_changes`): a restore does **not** remove tables the dump does not contain, and rows left in them would point at orders that no longer exist. 4. Restore `db.sql.gz` as `RESTORE.txt` describes. 5. `php artisan optimize:clear`, `queue:restart`, then run `fulfillment:preflight` before `php artisan up` | the length of the restore |
| The application directory was damaged | `tar -xzf files.tar.gz -C "$APP"` from the same backup (it also restores `.env` and `storage/`) | minutes |

The previous release ignores the new tables and the nullable column, so putting the old code back while the tables exist is safe.

---

## 11. Blockers and decisions

### Needs access or information from you (nothing below can be done without it)

| # | Needed | Why it blocks | Blocks |
|---|---|---|---|
| 1 | **Shell access to the server** (SSH or the hosting panel's terminal) as the user that owns the application, with PHP CLI ≥ 8.2, `mysqldump`, `mysql`, `tar`, `gzip`, `sha256sum`. If there is no shell at all, tell us: a manual (file-manager + phpMyAdmin) procedure is a different and riskier path that has **not** been prepared | every phase | 0–4 |
| 2 | The answers to phase 0: PHP version, file owner, **how the queue runs**, whether the cron line exists, where the document root is, whether the Composer class map is authoritative, whether `audit_logs` exists | the queue and cron lines, the evidence and backup locations and the gate decisions depend on them | 4, 6, 8 |
| 3 | The **production database name** (for `--expect-database`) and a **maintenance window** | the read-only checks refuse a database they were not told to expect | 3, 4 |
| 4 | A place **off the server** for the backup copy, and the directory outside every web root that will hold `$BACKUPS` | a backup on the same disk is not a backup, and one inside the web root can be downloaded | 2 |
| 5 | **M5AZN seller credentials** (and whether M5AZN offers a test account) | nothing can be read without them | 6–9 |
| 6 | **Torod Point / LogesTechs credentials:** Fulfillment API username and password, Lastmile e-mail and password, company ID **714**; and whether LogesTechs offers a **sandbox company** for write tests | same; and tests that write | 6–9 |
| 7 | **Written authorisation** from each provider for the controlled test orders (who receives them, who cancels them), and your own **Gate C** approval per provider ([live-verification](live-verification/README.md) levels) | M5AZN has no cancel API and Torod has no warehouse-order cancel: a test order cannot be undone from here | 8 |
| 8 | The **test products** (cheap, in stock at the provider, not a controlled medicine, no expiry blocking), a **staff customer account**, a **delivery address and phone that belong to staff** | a test order is a real parcel | 8 |
| 9 | LogesTechs' answer on **where stock is** in the Fulfillment API ([request](vendor-requests/logestechs-technical-request.md)) and M5AZN's on **response schemas, order statuses and cancellation** ([request](vendor-requests/m5azn-technical-request.md)) | until then stock-based availability for Torod products and M5AZN tracking rest on what the live read-only run shows | 7, 9 |
| 10 | **Webhook registration at LogesTechs** (they must be given the two URLs from the Torod page, and confirm a delivery) | without it a Torod package barcode is never learned and **no Torod status ever arrives**: step 8.6 and the go-live item *A status update received and understood* cannot be met | 8 (Torod), 9 |

### Decisions only you can take (found while preparing this; independent of the integration but they affect it)

| # | Finding | Decision |
|---|---|---|
| D1 | **`APP_KEY`.** `.env.example` carries a real-looking key. If production runs the same key, anyone with the repository can decrypt the provider credentials you are about to enter. `fulfillment:preflight` compares them only when `.env.example` is on the server, which this package does not ship; otherwise compare by hand **without printing the key**: on a machine with the repository `git show 34dce65:.env.example \| sed -n 's/^APP_KEY=//p' \| sha256sum`, on the server `sed -n 's/^APP_KEY=//p' .env \| sha256sum`: equal hashes mean the same key | If they match: rotate it **before** entering real credentials. There is no re-encryption tool: after the key changes, everything stored with `Crypt` on this platform can no longer be read and must be typed in again — the Zender secret, MTN Cash data and keys, the Chatwoot / WhatsApp credentials (`integration_settings`, the same table as the provider credentials), the Delivery Syria tokens — and every session ends. Note those values down from their own screens first. If you accept the risk instead, say so in writing |
| D2 | **OTP codes are constant.** `config('app.mode')` is never defined, so every login/verification code is `123456` (6 digits) or `1234` (4 digits) | A production security defect unrelated to this PR. Fix or accept deliberately |
| D3 | **Secrets committed to the repository:** ECDSA private-key files `Apple-AuthKey.p8` and `Apple-AuthKey (copy).txt`, each in `public/assets/back-end/` (web-served) and again under `resources/assets/back-end/`, the licence purchase key in `config/system-addons.php`, three public maintenance routes guarded by literal `-xk29` keys, a Paymera literal | Rotate whatever is real. None is used by this integration. Phase 0 checks that both public copies answer 403/404 |
| D4 | The document root may be the application root (phase 0) | Point the web server at `public/`, or deny everything except `index.php`, `public/` and `storage/app/public` |
| D5 | Pre-existing debug writers log **OTP + phone** (`ZenderOtpService`), parcel bodies with name/phone/address (`DeliverySyriaService`) and gateway callbacks to `storage/logs`, and `laravel.log` is truncated hourly for non-local requests | Use `LOG_CHANNEL=daily`; remove the debug writers; decide log retention (the API log tables are this project's evidence trail) |
| D6 | The platform's own pending migrations, if `migrate:status` shows any (phase 0) | Separate change, separate backup and rehearsal. Not part of this deployment |

### Known limitations of the providers (not blockers, but they shape the plan)

* **M5AZN** documents no webhook, no cancel endpoint and no response schemas: tracking is polling only (every 15 minutes) and needs a
  verified `orders` map plus a status map; an order sent cannot be cancelled by the API.
* **Torod Point** documents no stock quantity in the product list, no warehouse-order cancel and no warehouse-order status lookup;
  the Lastmile API is used only for cities, package status, air waybill and package cancel — **its create-shipment endpoint is never called**
  (it would duplicate the warehouse's own shipment). Its status needs the webhook (blocker 10): a package barcode is learned from
  the webhook, and only then can a status be polled.
* Mixed baskets (a product from each provider) are shipped as two parcels and the customer is told so; cash on delivery is refused
  for them unless `FULFILLMENT_ALLOW_MIXED_COD=true` **and** every provider involved is marked *Cash-on-delivery settlement verified*.

### What is verified and what is not

| Verified by automated tests (SQLite, and the fulfillment suites on MySQL 8) | Checked once by hand, not by a test or CI | Not verified — needs the live server or the providers |
|---|---|---|
| the write guard, the approval gate, the go-live checklist and its audited override; no duplicate order under retries, ambiguous outcomes, concurrent workers; money, VAT and COD rules; the overlay build, check, apply, kill-and-undo, full-disk and rollback paths including drift and language-file merge; the backup scripts' paths and failure behaviour (with stand-ins for `mysql`/`mysqldump`); the read-only schema check against a synthetic production-shaped schema | the backup and restore with a **real** MySQL 8 and a production-shaped schema; the two migrations (`--pretend`, run, rollback by path; 423 ms and 28 ms on empty tables) | the schema, data and drift of **your** database and files; how long the migration takes on real tables; **both providers' real response shapes**; the order-creation response; webhook delivery from LogesTechs; real latency and rate limits; your queue/cron/OPcache/ownership setup |

*Nothing in the right-hand column is claimed to work. It is what phases 4b, 6, 7 and 8 exist to establish.*

---

## Appendix: what was checked and what was not

* `OverlayDeploymentTest` builds an overlay from two revisions of a synthetic repository and applies, drifts, merges language files,
  and rolls back on a synthetic "live" tree; it also kills `apply` mid-write and fills the disk under it, and proves the tree comes
  back byte for byte. `BackupScriptsTest` runs the backup scripts with stand-ins for `mysql` and `mysqldump`. `LeakGuardTest`,
  `GoLiveReadinessTest`, `LiveVerificationTest`, `ExternalWriteGuardTest`, `ApprovalGateTest` and `CompatibilityCommandTest` cover the
  rest of the tooling. CI runs them on SQLite (PHP 8.2 and 8.3) and the fulfillment suites on MySQL 8.
* The leak guard looks for credentials, private keys, tokens, environment files, database dumps, and real-looking phone numbers and
  e-mail addresses in what a change **adds**. It cannot recognise a customer's name, street address or free-text note.
* **Not run:** anything against your server, your database or a provider. The sandbox this was prepared in has no route to any of them.
* The older [staging-validation.md](staging-validation.md) describes the same checks on a restored copy; it is kept for anyone who
  prefers to rehearse that way, and is **not** required for this deployment.
