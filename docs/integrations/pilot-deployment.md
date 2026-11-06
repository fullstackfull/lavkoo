# Pilot deployment on the existing Pharmacy KSA server

> **Status: prepared, not executed.** Nothing in this document has been run against the live server, the live database or a
> provider: this repository has no access to any of them. The tooling (`deploy.php`, the package builder, the backup scripts) and
> the code behind each gate are covered by automated tests, and the tooling was also run by hand on synthetic trees and a real
> MySQL 8 (see the table [what is verified and what is not](#what-is-verified-and-what-is-not) and the [appendix](#appendix-what-was-checked-and-what-was-not)); what the live server will answer is not known until it
> is run. **Nothing is deployed, merged or switched on without your explicit approval** at the numbered gates.
>
> **Hotfix 1.** The first read-only check on the pilot server found three failures and four warnings. A second, small package clears
> two of the failures and the currency warning; two more migrations, which widen two `order_details` columns, clear the third failure
> and a warning. A server that already runs the first package does
> [Hotfix 1](#hotfix-1-fit-the-integration-to-the-schema-found-on-this-server) **before** the migration step 4c; a server that has never
> had a package gets everything in one.
>
> **Hotfix 2** (the Torod Point orders map and warehouse stock ownership; code only, no migration, nothing switched on) has its own
> document: [hotfix-2.md](hotfix-2.md). It is applied on top of Hotfix 1 and rolled back newest first.

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
| 4b′ | **Hotfix 1**, only if the first package is already on the server: the incremental package, then 4b again | 19 files; the database is not touched | **Gate A1** again, for this package's files |
| 4c | The additive migrations: two for the integration's tables, two that widen `order_details` columns | 11 new tables, one nullable column on `products`, and `order_details.delivery_status` and `.variation` made wider (no row is written) | (Gate A2) |
| 5 | Smoke-test the deployed pages | nothing | — |
| 6 | Read-only integration: credentials, `Test connection`, `fulfillment:verify` | outbound **reads and logins only** | **Gate B — allow reads** |
| 7 | Record the real response schemas, finalise the field maps | our own settings | — |
| 8 | Controlled test orders | **real orders at the providers**, one at a time, each approved | **Gate C — allow writes** (per provider, in writing) |
| 9 | Turn automatic sending on | orders flow without a person | **Gate D — go live** (only when the checklist is met) |

The order of 4a–4c is deliberate. The new code is **dormant until its migrations have run**: every place the existing order,
checkout, product and customer flows touch it asks first whether the tables exist, the scheduled commands do nothing, and the
Integration Center says what is missing (`UnmigratedStoreTest` proves each of these on a store without the tables). And the read-only
database check can only run once the code is on the server. So the files go first, you read the report, and only then does anything
touch the database.

Rollback for every phase is in [section 10](#10-rollback-for-every-phase). The remaining blockers are in [section 11](#11-blockers-and-decisions).

### What is armed after each phase

Everything is off after deployment. **Six** independent switches stand between the code and a provider; each one fails closed. Two
are lines in `.env` (write exactly `true` or `false`: any other word counts as **off**, and `fulfillment:preflight` says so); four are
on the provider's own page.

| Phase | `FULFILLMENT_INTEGRATIONS_ENABLED` | `FULFILLMENT_EXTERNAL_WRITES_ENABLED` | *Integration enabled* | *Send orders to this provider* | *Dry run* | *Send automatically (no approval per order)* | What can leave the server |
|---|---|---|---|---|---|---|---|
| 4a–4c deployed | false | false | off | off | on | off | **nothing** |
| 6 read-only | true | false | on | off | on | off | logins and reads (the transport refuses anything else) |
| 8, rehearsal | true | **false** | on | on | **on** | off | **no write and no order request**: being a dry run, the order is *recorded* as Dry run (never held by the write guard) with the request it would send; the logins and reads of the row above still leave |
| 8, controlled | true | **true** | on | on | **off** | **off** | an order, only when a person presses *Release and send now* / *Approve and send this order* on that order — and, because writes are on, a **cancellation** of an already accepted Torod Point shipment, sent by itself when its store order is closed (see *Cancellations* below; nothing asks you; M5AZN has no cancel API). Product pushes to the warehouse only if *Allow pushing products to the warehouse* is on |
| 9 live | true | true | on | on | off | **on** | every due order, and the same cancellations |

**Cancellations.** A shipment the provider has accepted is cancelled by itself, with no person asking, when its store order becomes
*canceled*, *failed*, *returned* or *refunded*, provided: both `.env` switches are on, *Integration enabled* is on, the Lastmile e-mail
and password are saved, and the package barcode is known (Torod reports it only in a status webhook; without it the shipment is
marked as needing a manual cancel and its page says so). *Send orders*, *Dry run* and *Send automatically* do not apply to a
cancellation. One wanted while writes are off or the provider is off is **kept and retried every five minutes**: it leaves the moment
both are on again, so look at the shipments marked *cancel wanted* or *needs attention* **before** you set
`FULFILLMENT_EXTERNAL_WRITES_ENABLED=true`. M5AZN has no cancel API.

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
user). Run **every** `php artisan …` of this runbook as that owner too (`sudo -u <owner> php artisan …` when you are root):
`deploy.php` keeps the owner and group of what it replaces when it runs as root, but artisan as root creates root-owned cache, log and
maintenance files under `storage/` that the web server's user can then neither write nor delete (if that happened,
`chown -R <owner>:<group> storage bootstrap/cache`). Set the paths once (and again in every new shell: a dropped SSH session loses them):

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
ls bootstrap/cache/config.php 2>&1 | head -1      # must say "No such file": a cached configuration makes every .env edit below inert (php artisan config:clear removes it)
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
php artisan migrate:status | tail -n 25            # what is pending today (we will run only OUR four migrations, by path, never a bare `migrate`)
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
  (`overlay.tar.gz` + `overlay.tar.gz.sha256`). The job builds from the commit the server runs to the pull request's own head
  commit. That baseline is the repository variable `PILOT_BASELINE` if it is set, otherwise `698aa1e` (the tree this server runs after
  Hotfix 1); for a server that still runs the first release, set `PILOT_BASELINE=ef843600`, and for one that still runs the original
  code `PILOT_BASELINE=34dce65`, to get the whole package in one. To
  build it yourself, into a **new or empty** directory:
  `php scripts/deploy/build-overlay.php --base=<what the server runs> --head=<release commit> --out=build/overlay --archive` (the builder
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
  `Modules/` or the web entry point, contains a symbolic link or submodule, changes a file's mode (alone, or together with its
  content: the server would keep the old mode), has a file name with a control character or a backslash in it, or carries its own
  copy of `deploy.php` or a backup tool that differs from the one the builder would put in the package (then build again from a
  checkout of the release): those need a person's decision and are named in the error.
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
bash "$PKG/tools/verify-backup.sh" --backup=<the directory backup.sh printed> --app="$APP"      # reads the backup only (see below)
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
  (relative, with `..`, or through a link; without GNU `realpath` any `..` in the destination is refused); it checks free disk; and a
  run that does not finish removes the dated directory it made, so no truncated `db.sql.gz` is left beside a real backup (and a
  failed dump on a full disk gives its space back). It never changes the mode of a `--to` directory that was already there.
  `--db-only` and `--files-only` each work alone and are mutually exclusive. The temporary option file that holds the database
  password is removed on every exit but a `SIGKILL` (the out-of-memory killer): after one, delete `$TMPDIR/tmp.*/client.cnf`.
* `verify-backup.sh` without options **only reads** the backup: the checksums, that the archive and the dump are intact and that
  the dump ends with mysqldump's completion line. That is **not** proof that it loads. The proof is `--restore-test`: it loads the
  dump into a scratch database it creates and drops **on the same MySQL server as the live database** and compares the row counts.
  That doubles the database's footprint while it runs and loads the live site, so: run it **off-peak**, it refuses unless about twice
  the dump's uncompressed size is free in MySQL's data directory (checked only when the database runs on this machine, and it refuses if
  the free space cannot be read; `--force-low-disk` skips the check), and it refuses when the live database has scheduled events (they would run in the scratch copy; `--allow-events`).
  If the MySQL account may not create databases (common on shared hosting) or the disk is tight, import `db.sql.gz` into a new empty
  database on **another** server (or with phpMyAdmin) and compare `row-counts.txt` by hand. **That comparison is the proof; do not
  skip it.**
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
| A write fails part-way (full disk, a permission) | **takes back everything it had written** (a file it never got to replace is left as it is; a restore the full disk refuses is tried again once the release's new files are gone), says so, exits 2 | read the error; the site is as it was |
| It cannot take it back (a file changed in the meantime, or a saved original is missing) | exits 2, names the file and the `rollback` command | keep the site down; run the `rollback` command; `--force` only after looking at the file. A saved original that is missing or damaged is copied back out of the `files.tar.gz` your `backup.sh` run made, into `<backup-dir>/files/<path>` (its checksum is checked), and `rollback` is run again |
| The process is killed (SSH dropped, out of memory) | `applied.json` in the backup directory already lists every file it had written **or was about to write** (it is written *before* each file) | reconnect, set `APP`, `PKG` and `BACKUPS` again as in phase 0 (a new shell has lost them), **do not start a second `apply`** (it refuses while an unfinished one has not been rolled back): `ls -t "$BACKUPS/deploy" \| head -1`, then `php "$PKG/deploy.php" rollback --backup-dir="$BACKUPS/deploy/<that directory>"`. A record in a neighbouring backup directory that this user may not read, or that another tool wrote, is reported with a warning and does not block `apply` (what an unfinished deployment changed would show as drift); run as the user that ran the earlier deployment to be sure |

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
not notice it, the scheduled `fulfillment:*` commands print that integrations are disabled (`fulfillment:prune` says the migrations have not been run yet), and
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
  (*2 pending*, the integration's tables, is the expected answer here; the two column-widening migrations of Hotfix 1 are reported on
  their own line, *compatibility migrations*). From the command line it cannot judge the trusted-proxy setting (that needs a web request:
  the Integration Center shows it). It does **not** detect a missing cron line or a worker that ignores the `fulfillment` queue while the
  integration is switched off: check those yourself (phase 0 and 4c).
* `migrate --pretend` prints SQL without running it, and **cannot show the whole change**: pretend mode answers every "does it exist?"
  with *no*. So it prints twelve `create table` statements (it also tries `integration_settings`, which the platform already has: the
  real run skips it and eleven tables are created), their index statements and some probe `select`s, and it **does not print the
  `alter table products` at all**. That one change, the only one that touches an existing table, is previewed by the
  `products.fulfillment_provider` line of `fulfillment:preflight` (a nullable, indexed column), and confirmed after the real run by the
  same line reading *present*. In production even `--pretend` asks *"Application in production"*; `--force`
  answers it. The two **widening** migrations of Hotfix 1 print no statement at all under `--pretend`, only their
  name (they must read the column's definition first, and pretend mode answers every query with nothing; they do not fail): their exact
  statements are shown, read-only, by the *column changes the migrations would make* line of `fulfillment:compatibility`.

Send us the output (it holds counts, names and shapes, no row of customer data). **A FAIL stops here:** nothing in the database has
changed, and `deploy.php rollback` returns the files (section 10). Do not run the migration, and do not call phase 4 finished.

> **Gate A2 — you approve the migration**, having read the report.

### Hotfix 1: fit the integration to the schema found on this server

> **When this applies.** The first package is on the server (its files are those of commit `ef843600`; the package you were given
> records `30dcbf6` as its head, which has the identical tree), the read-only checks of 4b have been run, and **no migration has**.
> A server that has never received a package skips this section: the package of section 1, built from the original code, already
> contains it, and 4c lists all four migrations.

The first read-only check on this server reported three failures and four warnings. Some were our own wrong expectations of the
platform, some were conservative rules, and two were real defects. Each is classified below, with what Hotfix 1 does about it.

| Found on the server | What it is | What Hotfix 1 does |
|---|---|---|
| Table `social_media` is missing (FAIL, *tables the application boots from*) | **Our mistake.** The platform's table is `social_medias` (its `SocialMedia` model). | The check, the outbound-channel audit and the test schema use `social_medias`. Nothing on the server changes. |
| `currencies.exchange_rate` is `varchar(191)` (FAIL, *currencies column types*) | **Our mistake.** The platform keeps rates as text itself (its migration `2021_06_03_104531_ex_rate_update.php`); the check wanted a number type. | A text column passes. Each rate is judged by its **value**: a plain positive decimal of a believable size (`1`, `3.75`; between 0.000001 and 1,000,000,000) is a rate; `''`, `abc`, `3,75`, `1e3`, `0`, `-2` and Arabic-Indic digits are not, and an amount that needs such a rate is **held**, never converted with a guess. The report names the currencies whose rate is unusable and never prints a rate. A rate column typed as a whole number is still a failure (`3.75` cannot be stored in it). **The `currencies` table is only ever read.** |
| The store's riyal is the currency **`KSA`** (id 8, rate `1`), while providers and `FULFILLMENT_CURRENCY` say **`SAR`** (WARN, *currency conversion*) | **A real runtime defect.** To our code `KSA` was not `SAR`, so on this store every provider shipment was held (*currency cannot be converted*), an order line was snapshotted with a total of `0`, and an imported M5AZN product was priced `0` (and left unpublished). | `config/fulfillment.php` gets `currency_aliases = ['KSA' => 'SAR']`: the store's code is the legacy spelling of the riyal. A single-currency store whose default currency is `KSA` needs no conversion; a multi-currency store finds the riyal's rate under `SAR` or, failing that, `KSA`. A provider is **always told `SAR`** (the shipment's `currency`, M5AZN's `supplier_cost_currency`), even if `FULFILLMENT_CURRENCY` itself says `KSA`. **No currency id, price or rate is changed.** A default currency that is not the riyal (USD, say) is still held. |
| `order_details.delivery_status` is `varchar(15)` (FAIL, *order_details column types*) | **A real, latent defect of the platform's own schema; the integration never reads or writes this column.** `out_for_delivery` has 16 characters. The platform copies the new order status into the line when it gives stock back or takes it again (cancelling, returning or failing an order, and re-opening one) and writes `delivered` on delivery. Re-opening a cancelled order straight into *out for delivery* therefore stores `out_for_deliver`, **with no error**, because the platform's connection is not in strict mode (a strict session would refuse the whole status change instead). The table has no rows today. | Migration `2026_11_06_100000` widens it to `varchar(30)`. |
| `order_details.variation` is `varchar(255)` (WARN) | **A conservative rule with a real edge case.** The platform writes the options a customer chose into it as JSON, and with every Arabic letter escaped as `\uXXXX` (six characters) a few Arabic options exceed 255. The integration reads the variant from `order_details.variant` and the product's own list, never from this column. | Migration `2026_11_06_100100` makes it `TEXT`. Optional for the integration, recommended for the store. |
| The other warnings | Pending platform migrations, *tables created outside migrations*, `audit_logs` or `stock_movements` missing, and the counts that describe existing data (unusual status words, orders without lines, duplicate SKUs…) are **the platform's existing state or observations about existing data**. None is caused by the integration or changed by Hotfix 1. | Nothing. Send us the list that remains after Hotfix 1 and we classify each (see *Reading what remains*, below). |

**What the two column migrations do.** Each reads the column's definition from `information_schema` and builds the one
`ALTER TABLE … MODIFY COLUMN` that says the same thing with a wider type (nullability, default, character set, collation and comment
restated). It waits at most 15 seconds for the table's lock (then stops with nothing altered), runs the statement, reads the column
back and fails loudly if anything but the type differs. No row is written. It **refuses before altering anything**, with a message: a
column that is not a varchar, or whose type says more than a width (`COMPRESSED`), or that is generated or invisible; an expression
default (on MariaDB also a default that contains a backslash); a CHECK constraint that mentions the column; for the TEXT change an index
on the column or a default value; and a column whose definition the database user is not allowed to see. A column that is already wide
enough is left alone, so running it twice is safe and a wider column is never narrowed; on any database but MySQL and MariaDB it does
nothing. Its `down()` does nothing on purpose: narrowing could cut off what has been stored since.

**Look at the exact statements before you run them.** `fulfillment:compatibility` prints, read-only, a line *column changes the
migrations would make*. For tables in the platform's usual character set it reads (the same on MySQL and MariaDB):

```
order_details.delivery_status: ALTER TABLE `order_details` MODIFY COLUMN `delivery_status` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending'
order_details.variation: ALTER TABLE `order_details` MODIFY COLUMN `variation` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL
```

(your character set and collation are whatever `order_details` really uses; the two statements are joined by ` | ` on one line). Send
that line to us before H6. If a column reads **THE MIGRATION WOULD REFUSE**, do not run the migrations: the message says why, and the
column can be widened by hand once a person has looked at `SHOW CREATE TABLE order_details`.

**Optional, and worth a minute: the server's engine.** The migrations were run on MySQL 8.0.46 and MariaDB 10.11.14, **not** on the
pilot server's MariaDB 11.4.2. These read-only statements (phpMyAdmin, or `mysql` with the application's database user) show whether
anything on the server differs from what was tested; send the answers with the H5 output:

```sql
SELECT VERSION(), @@lock_wait_timeout, @@alter_algorithm, @@old_alter_table, @@read_only;          -- VERSION() contains "MariaDB"; the two alter settings read DEFAULT
SHOW GRANTS FOR CURRENT_USER();                                                                     -- must include ALTER on the database or on order_details
SELECT COUNT(*) FROM order_details;                                                                 -- 0
SELECT TABLE_TYPE, ENGINE, ROW_FORMAT, CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_details';   -- BASE TABLE, InnoDB, no "partitioned"
SELECT trx_id, trx_started FROM information_schema.INNODB_TRX;                                      -- no transaction older than a few seconds when you run H6
SHOW CREATE TABLE order_details\G                                                                   -- keep it; the same command after H6 must differ only in the two column lines
```

The best rehearsal, if you have room for it, is to restore the backup of phase 2 into a scratch database on a server of the same
version and run the four-path `migrate` of H6 there first.

**The procedure.** Run these commands as `root` or as the owner of the application files. Set the variables first (a new shell has lost
them; `DOCROOT` is the one from phase 0). Nothing below touches the web root, and `art` runs artisan as the user who owns the files, as
phase 0 requires (artisan as root would leave root-owned files under `storage/` that the web server cannot write):

```bash
APP=/path/to/the/application                                   # the directory that contains artisan and .env
BACKUPS=/path/outside/every/web/root/pharmacy-backups          # the same directory as for the first deployment
HOTFIX=/path/outside/every/web/root/overlay-hotfix-1           # a NEW directory (as root, /root/… is fine)
PHP=php                                                        # the PHP binary you used for the first deployment (several versions may be installed: write its full path)
: "${APP:?}" "${BACKUPS:?}" "${HOTFIX:?}" "${DOCROOT:?}" "${PHP:?}"     # stops here if one of them is empty
for dir in "$BACKUPS" "$HOTFIX"; do
  case "$(realpath -m "$dir")/" in "$(realpath -m "$DOCROOT")"/*) echo "STOP: $dir is inside the web root" ;; *) echo "ok: $dir is outside the web root" ;; esac
done
OWNER=$(stat -c %U "$APP/artisan")                             # the user that owns the application files
art() { (cd "$APP" && sudo -u "$OWNER" "$PHP" artisan "$@"); }  # without sudo: runuser -u "$OWNER" -- "$PHP" artisan "$@"; already that user: "$PHP" artisan "$@"
```

Use `art` wherever this section needs artisan. `deploy.php` and `backup.sh` run as `root` or as `$OWNER` (if as `$OWNER`, `$HOTFIX` and
`$BACKUPS` must be readable and writable by that user).

**H0. Prove what the server runs before you change it (reads only).** The server's own `git` HEAD proves nothing: the overlay does
not move it. The record of the first deployment does:

```bash
ls -t "$BACKUPS/deploy" | head -5                                # the first deployment's directory is among them
FIRST="$BACKUPS/deploy/<the first deployment's directory>"
php -r '$m = json_decode(file_get_contents($argv[1]), true); echo $m["head"], PHP_EOL;' "$FIRST/MANIFEST.json"    # 30dcbf64…, the same tree as ef843600
grep -c rolled_back_at "$FIRST/applied.json"                     # must print 0: that deployment was not rolled back
```

**H1. Get the package.** Never fetch into, pull in, check out in or reset `$APP`: the working tree there (the files of `ef843600`, with
`git`'s own HEAD possibly still at `34dce65`) is exactly what the next steps compare against. `<HOTFIX_COMMIT>` below is the **full
40-character hash we give you in writing** with this document (never a branch name; a document inside a commit cannot name its own
hash). Either download the artifact `pilot-overlay-<HOTFIX_COMMIT>` of the pull request's CI run (it holds `overlay.tar.gz` and
`overlay.tar.gz.sha256`), or build it yourself in a scratch clone **anywhere but `$APP`** (your own computer, or the server in a new
directory outside the web root; the clone contains files that must not be web-served):

```bash
WORK=$HOME/hotfix1-build && test ! -e "$WORK"                                          # a NEW directory
git clone -c core.autocrlf=false https://github.com/fullstackfull/pharmacyksa98.git "$WORK/repo"     # a private repository: clone it the way you usually do
git -C "$WORK/repo" checkout --detach <HOTFIX_COMMIT>                                  # a scratch clone: nothing on the server changes
test "$(git -C "$WORK/repo" rev-parse HEAD)" = "<HOTFIX_COMMIT>" && echo "the clone is at the hotfix commit"
git -C "$WORK/repo" cat-file -t ef843600                                               # must print: commit
php "$WORK/repo/scripts/deploy/build-overlay.php" --repo="$WORK/repo" --base=ef843600 --head=<HOTFIX_COMMIT> --out="$WORK/overlay" --archive
```

Both ways give the same `files/`; the two archives differ in hash because `MANIFEST.json` records the build time. Copy
`overlay.tar.gz` and `overlay.tar.gz.sha256` to the server, outside every web root.

**H2. Check it.**

```bash
sha256sum -c overlay.tar.gz.sha256                                  # in the directory holding both files
mkdir -p "$HOTFIX" && tar -xzf overlay.tar.gz -C "$HOTFIX"
(cd "$HOTFIX" && sha256sum -c SHA256SUMS | grep -v ': OK$' ; echo "(nothing listed above = every file matches its checksum)")
php -r '$m = json_decode(file_get_contents($argv[1]), true); echo count($m["files"]), " files, base ", $m["base"], ", head ", $m["head"], PHP_EOL;' "$HOTFIX/MANIFEST.json"
```

Expected: `head` is `<HOTFIX_COMMIT>`, and `base` is `ef843600…` or `30dcbf64…` (the same tree; the latter if the CI variable `PILOT_BASELINE`
was set to the first package's head). A base of `34dce65…` means the **whole** package was built: do not use it. The file count we give
you is valid for that hash only.

**H3. Compare it with the files on the server (reads only).**

```bash
"$PHP" "$HOTFIX/deploy.php" verify --app="$APP" --package="$HOTFIX"
```

Expected, and nothing else: `Summary: 14 CLEAN, 5 NEW` and `Result: every file can be applied.` The five `NEW` files are `CurrencyCode`,
`ExchangeRate`, `LegacyColumnWidener` and the two migrations. `CLEAN` here means the file on the server is still exactly the one the first
package installed. A `DRIFTED` file means someone changed it since: read what they changed (`diff -u "$HOTFIX/base/<path>" "$APP/<path>"`)
and accept it by name (`--accept-drift=…`) only after a person has looked, as in section 3.

> **Gate A1 again, for this package's files.** They are dormant like the first package's: nothing here runs on a customer request
> unless a product belongs to a provider, and none does.

**H4. Put the files on the server (about a minute; the database is not touched).** One chain: if any step fails, the next does not run
and the site stays in maintenance mode (then see the failure table in 4a, reading `$HOTFIX` for `$PKG`).

```bash
STAMP=$(date -u +%Y%m%d-%H%M%S)
"$PHP" "$HOTFIX/deploy.php" apply --app="$APP" --package="$HOTFIX"                       # dry run: prints the report, changes nothing
art down --retry=60 \
  && "$PHP" "$HOTFIX/deploy.php" apply --app="$APP" --package="$HOTFIX" --yes --backup-dir="$BACKUPS/deploy/$STAMP-hotfix1" \
  && art optimize:clear && art queue:restart && art --version && art up
```

Keep the `[next] To undo:` line `apply` prints. Reload PHP-FPM or reset OPcache if `opcache.validate_timestamps` is off, as in 4a. The
`.env` lines of 4a are already in place; this package adds none. `$BACKUPS/deploy/$STAMP-hotfix1/MANIFEST.json` is the record of what
is deployed from now on: its `head` is the base of the next package.

**H5. Run the read-only checks again.**

```bash
art fulfillment:preflight --allow-production-read-only
art fulfillment:compatibility --allow-production-read-only --expect-database=<the production database name>
```

Expected, line by line:

* **FAIL:** exactly one, *order_details column types* (the two columns above), plus *app key* while D1 is still open. Any other FAIL: stop
  before H6 and send us the output.
* **PASS, all of them:** *database*, *external writes*, *switch spelling*, *m5azn dispatch*, *torod_point dispatch* (these prove writes and
  sending are off), *tables the application boots from*, *currencies column types*, *currency conversion* (it reads *single-currency
  store whose default currency is stored as KSA, the store's spelling of SAR (config fulfillment.currency_aliases): stored amounts are sent
  to providers as they are, labelled SAR*), and *nothing was written*. *exchange rates* is PASS, or WARN naming only currencies this
  single-currency store does not use.
* **info:** *column changes the migrations would make*, with the two statements and no **THE MIGRATION WOULD REFUSE**.
* **WARN you will probably still see**, none caused by this release: *audit_logs*, *tables created outside the migrations*, *stock_movements
  column types*, *migrations table* (platform migrations pending). See *Reading what remains*.
* **Preflight:** `migrations: 2 pending` and `compatibility migrations: 2 not applied`.

Send us the output (counts, names and shapes; no row of customer data). Then:

> **Gate A2 — you approve the migrations**, having read the report.

**H6. Run the four migrations** (this is 4c, with its backup and maintenance steps), in three blocks. Do not paste them as one: each
block ends where you must look at what it printed.

```bash
art down --retry=60
bash "$HOTFIX/tools/backup.sh" --app="$APP" --to="$BACKUPS/data" --db-only
```

It must end with `Backup complete: <directory>`. If it does not: run `art up` and send us the message. Note the directory: it is the
restore point for this step.

```bash
art migrate --path=database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php \
            --path=database/migrations/2026_11_05_100100_add_fulfillment_provider_to_products_table.php \
            --path=database/migrations/2026_11_06_100000_widen_order_details_delivery_status_for_fulfillment.php \
            --path=database/migrations/2026_11_06_100100_widen_order_details_variation_for_fulfillment.php
```

(`APP_ENV=production` asks *Do you really wish to run this command?*: answer `yes`.) Laravel runs them in file-name order and records each
one when it has finished. If it stops, stay down and read the message:

| It prints | State of the database | Do |
|---|---|---|
| `… was not changed: <reason>. Nothing was altered …` | unchanged; the migrations before it are recorded | send us the message |
| `Lock wait timeout exceeded` with an `ALTER TABLE` in the message | unchanged | another request held `order_details`: wait a minute and repeat the same command |
| any other SQL error that shows the `ALTER TABLE` (a foreign key, the row size…) | unchanged | send us the message |
| `… was altered but does not read back as … with its other properties unchanged` | **changed**, not recorded | keep the site down, run `SHOW CREATE TABLE order_details`, send us the output; restore the dump above only if we say so |

```bash
art optimize:clear && art queue:restart \
  && art fulfillment:preflight --allow-production-read-only \
  && art fulfillment:compatibility --allow-production-read-only --expect-database=<the production database name> \
  && art up
```

Both checks exit 1 on a FAIL, so the site comes up only if neither printed one. If the only FAIL is the *app key* one you have decided in
writing to accept under D1, run `art up` yourself.

**H7. Check the result.**

```bash
art tinker --execute='foreach (Schema::getColumns("order_details") as $c) { if (in_array($c["name"], ["delivery_status", "variation"])) { echo $c["name"], " ", $c["type"], PHP_EOL; } }'
#   delivery_status varchar(30)   and   variation text      (in the table's column order)
```

Then, in the admin panel: **3rd Party Setup → Fulfillment providers** shows *Integration mode on this server* as **OFF**, both provider
cards off and *System checks* with no red; open **M5AZN** and **Torod Point**: both render. Place nothing, send nothing. Every other
check of phase 5 applies. (The currency judgement is not a line of that page: it is the *currency conversion* line of
`fulfillment:compatibility`, H5.)

**H8. Read-only connection tests.** Phase 6 (**Gate B**), and only this part of it. Settle D1 (the `APP_KEY` hash) **before** typing a
credential. Then turn on exactly this: `FULFILLMENT_INTEGRATIONS_ENABLED=true` (edit the existing line; keep
`FULFILLMENT_EXTERNAL_WRITES_ENABLED=false`) and `art queue:restart`; on each provider page *Integration enabled*, the credentials (we
never see them) and *Test connection*; then `art fulfillment:verify m5azn` and `art fulfillment:verify torod_point`. Leave **off**:
*Pull the products selected on M5AZN*, *Read warehouse products*, stock sync, *Send orders to this provider*; leave *Dry run* **on**. No
order is created at either provider, and the scheduler has nothing to sync. Do not start H8 until H7 is clean.

**Send us**, in this order: the H0 head line; the H3 `Summary:` and `Result:` lines; the H4 `[ok]` and `To undo:` lines; the H5 output
(and the optional engine statements); the H6 output of each block; the two H7 lines.

**Taking Hotfix 1 back.** Newest first: `"$PHP" "$HOTFIX/deploy.php" rollback --backup-dir="$BACKUPS/deploy/$STAMP-hotfix1"` (the directory
`apply` printed for the hotfix) restores the first release's version of every file and removes the five new ones; only then, if you also
want the first deployment undone, run `rollback` on the first deployment's directory with the first package's `deploy.php` (the other way
round it stops with *changed after the deployment*). After H6 the two widened columns stay wide (nothing needs them narrow, and the first
release's code does not mind). The two widening migrations stay recorded in the `migrations` table (`fulfillment:preflight` still says
*compatibility migrations: both applied*) but their files are gone, so `migrate:status` no longer lists them. The integration's own two
migrations are undone as section 10 says; that rollback by path prints two harmless `Migration not found` lines for the widening files.

**Reading what remains.** Everything the checks can still print, and what kind of thing it is:

| Line | Kind | What to do |
|---|---|---|
| *order_details column types* (`delivery_status`, `variation`) | defect of the platform's schema (status), conservative rule (variation) | Hotfix 1's migrations |
| *currency conversion* | **runtime defect** if it says the shipments would be held; also a WARN if the store rounds to other than 2 decimal places (providers are sent 2) | the alias above; if the default currency is something else, or the decimals are not 2, tell us before any order |
| *exchange rates* (names of currencies) | conservative rule that protects real money: a shipment or imported price using that currency is held | correct the rate in the admin (*Business Setup → Currency*), or leave it if the currency is not used |
| *migrations*, *migrations table*, *platform migrations pending*, *…touching checkout tables* | pre-existing (D6): the platform's own state | nothing; never a bare `migrate` |
| *tables created outside the migrations*, `audit_logs`, `stock_movements` | pre-existing: tables that come from the installation dump | nothing for the integration; `audit_logs` is read in phase 0 |
| *app key* | D1 in section 11 | decide before any credential is typed |
| counts of unusual status words, orders without lines, unreadable address JSON, duplicate SKUs or barcodes, negative stock | observations about **existing data**; the integration holds or skips such a case rather than guess | read them; none blocks |

Three things to know about the currency work, none of which can occur on this store as configured:

* An order line captured while its currency could not be converted (an unusable rate, or a default currency that is not the riyal) is
  frozen with a total of `0`, and fixing the rate afterwards does not give it its value back. A shipment holding such a line therefore
  **stays held** (*currency cannot be converted*) instead of going out priced at nothing; it needs a person, not a retry.
* An M5AZN product imported while the price could not be converted was created unpublished with a price of `0`; the default price
  policy never reprices it afterwards. If any product was imported before Hotfix 1, set its price by hand.
* The currency settings are cached by the platform for a few hours. After a **direct SQL edit** of `business_settings` or `currencies`,
  run `art optimize:clear`; edits made in the admin panel clear the cache themselves.

### 4c. The migrations (about a minute)

Only our four migrations, by path, in this order. **Never a bare `php artisan migrate`.** (If the first package is already on the server
and has not had Hotfix 1 yet, do [Hotfix 1](#hotfix-1-fit-the-integration-to-the-schema-found-on-this-server) first: its files contain the two
widening migrations.)

Three blocks, not one: each ends where you must look at what it printed (a pasted block keeps going after a failure, and
`migrate` in production reads its confirmation from the next line you paste).

```bash
php artisan down --retry=60                    # recommended: the `alter table products` takes a brief metadata lock
bash "$PKG/tools/backup.sh" --app="$APP" --to="$BACKUPS/data" --db-only    # the restore point for the migration: taken NOW, not days ago
```

It must end with `Backup complete: <directory>`; if it does not, run `php artisan up` and ask us.

```bash
php artisan migrate --path=database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php \
                    --path=database/migrations/2026_11_05_100100_add_fulfillment_provider_to_products_table.php \
                    --path=database/migrations/2026_11_06_100000_widen_order_details_delivery_status_for_fulfillment.php \
                    --path=database/migrations/2026_11_06_100100_widen_order_details_variation_for_fulfillment.php
#   (APP_ENV=production asks "Do you really wish to run this command?" — answer yes. --force only for a non-interactive shell.)
#   Laravel runs them in file-name order, as listed. Each is recorded once it has finished, so a run that stops part-way is simply repeated.
```

```bash
php artisan optimize:clear && php artisan queue:restart \
  && php artisan fulfillment:preflight --allow-production-read-only \
  && php artisan fulfillment:compatibility --allow-production-read-only --expect-database=<the production database name> \
  && php artisan up
#   preflight: both integration migrations applied and both compatibility migrations applied, all tables present; products.fulfillment_provider: present
#   (both commands exit 1 on a FAIL, so the site comes up only if neither printed one)
```

(In phpMyAdmin the same change reads: `products` has a nullable, indexed `fulfillment_provider` column.)

If either command prints a **FAIL, stay down** and go to section 10 (the one exception: an `app key` FAIL you have decided in writing to accept under D1; then bring the site up). Why only these four migrations, by path: the platform has about 300
migrations of its own, and one of them (`2026_05_07_000001`) rebuilds 35 large tables to `DECIMAL(40,20)` and cannot be reversed
losslessly. **Do not run a bare `migrate`**, whatever `migrate:status` shows as pending. If `migrate:status` lists platform migrations
as pending, that is a pre-existing condition to be handled separately, with its own backup and rehearsal.

The queue worker and the cron job must include the new work, and this is the part only your hosting setup can answer (phase 0):

* **A worker is running (Supervisor):** it must listen on the `fulfillment` queue — `queue:work --queue=fulfillment,default --sleep=3
  --tries=1 --timeout=150 --max-time=3600` ([operations §1](operations.md)). A worker started without `--queue=fulfillment` never
  sees our jobs. Nothing checks that for you while the integration is off, and afterwards the go-live item *Queue worker keeping up*
  only notices when a job has been waiting more than ten minutes.
* **`QUEUE_CONNECTION=sync`:** supported. A provider call never runs inside a *customer's* request: the per-minute sweep does the
  work. A person's own action (*Release and send now*, *Approve and send this order*, confirming an order was not created, *Cancel*)
  runs **inside their admin request whatever the queue is**: the page waits for the provider's answer (each call is limited to about
  20 seconds; a first M5AZN send also signs in) and then **says what it came to** (accepted, refused, unknown…) instead of "Done". A real
  queue (`database` or `redis` with a worker as above) is nicer for Gate D, where the sweeps, retries and status polling run;
  `sync` works for the controlled phase.
* **No cron line for `schedule:run`:** add it with **absolute** paths (cron has no `$APP`, and a pasted `cd ""` is a silent no-op):
  `* * * * * cd /path/to/the/application && /usr/bin/php artisan schedule:run >> /dev/null 2>&1`. Without it nothing is dispatched,
  polled or reconciled; the Integration Center's *scheduler heartbeat* says so once the integration is on.

Deployment is done when `fulfillment:preflight` shows no FAIL (an `app key` FAIL you have decided in writing to accept under D1 is
the one exception) and the site is up. **Nothing is switched on:** no provider has been contacted, and none can be.

---

## 5. Smoke-test what was deployed (nothing here writes or sends)

After 4a, the store pages; after 4c, the Integration Center too.

* Storefront: home, a category, a product page, the cart, the checkout page (do **not** place a real customer's order; see the optional staff order below).
* Admin: sign in; **3rd Party Setup → Fulfillment providers** (`/admin/fulfillment-providers`) shows *Integration mode on this
  server* as **OFF — no outbound call of any kind**, both provider cards switched off, and *System checks* with no red; open **M5AZN**
  and **Torod Point**: both pages render, credentials show as not configured, and the *Before automatic sending* checklist lists what is
  missing.
* Admin: open an existing order: it shows **no** provider card at all (the *Provider shipments* card appears only on an order that
  has provider shipments), and the product form (*Fulfillment provider* defaults to *In-house / Default*, which is every existing product).
* `php artisan tinker --execute="echo DB::table('provider_api_logs')->count();"` prints `0`: no provider call has been logged
  (*Integration Center → API logs* shows the same).
* `tail -n 50 storage/logs/laravel.log` shows nothing new that mentions `Fulfillment`.
* **Optional, and only with your say-so after 4c:** place one cash-on-delivery order as a staff customer for an **in-house** product,
  change its status in the admin, and cancel it. It is the only way to exercise the checkout and order-status hooks on the real
  schema before the first real customer passes through them (the compatibility report only reads the schema). It creates a real
  order record, which will show in order reports until you remove it by your usual means; nothing is sent to a provider.

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
**strictly** (only codes, counts, ranges and status words of up to three words, in any language; hidden status values are counted). The API log applies the same rules to anything
about orders, packages and sign-ins. **It cannot recognise a name or an address under a key it has no reason to suspect**, so read a
file before you share it. A file that cannot be written (a full disk, a permission) turns the run into a failure instead of
quietly losing it.

The directory ships an `.htaccess` that denies direct access: that works on Apache with `AllowOverride` permitting it. On nginx or
LiteSpeed, or with `AllowOverride None`, add the equivalent rule yourself, and after the first run request a **file**
from outside, `https://YOUR-DOMAIN/storage/app/fulfillment-evidence/<provider>/<the latest run_….json>`, and require 403 or 404 when the
application is served from its root directory (the directory's own URL answering 403/404 proves nothing about the files in it).

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
   poll is inactive until it exists and the `orders` map is verified. If the status field shows **hidden** values (the order-list check
   warns "N status value(s) were hidden"), the evidence is missing words: read the status words in the M5AZN dashboard and type them
   into the map yourself.
4. Torod only: when LogesTechs has stated which quantity is available to sell (and the hand count agrees), type that field's path into
   the products map and **Save and verify**; only then tick **I confirm this field of the product list is the quantity available to
   sell …** beside the path (the moment and the path are recorded; since [Hotfix 2](hotfix-2.md) no quantity is ever proposed for you).
   Without it stock stays unused.
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
* **Torod Point only — the invoice-number round trip must be confirmed first.** No real order is created until the master administrator has
  recorded, with evidence, that the invoice number we send comes back in the order list (Torod Point page → *Invoice number round trip*;
  [torod-point.md](torod-point.md#real-orders-wait-for-one-confirmation-the-invoice-number-comes-back)). Until then step 5 answers with
  a hold, *A real order waits for the provider's confirmation step*.
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
5. Open that shipment and press **Release and send now** (after the browser's confirmation). That sends this one order, now, inside your
   request, and the page then says what the provider answered. Any other due order waits *Waiting for an operator to approve sending
   this order* until a person approves it (*Approve and send this order*). The request is built again at that moment from the order, mapping, city and
   settings as they are then, so **review and release back to back**, and rehearse again after any change to those.
6. Follow it by hand (the plan lists what to look at after each step: acceptance and the reference, the first status update, delivery).
   The first accepted response is the one schema the read-only run could not record; fill in **Where the provider order reference
   is in its response** from the API log row.
7. When the test window ends: set `FULFILLMENT_EXTERNAL_WRITES_ENABLED=false` again, `php artisan queue:restart`, and confirm the mode
   reads **READ-ONLY**. The server is back to read-only until the next authorised window. (Until then a store order that is
   cancelled also asks Torod Point to cancel its accepted package, on the conditions listed under *Cancellations* below the armed table.)

---

## 9. Turning automatic sending on

> **Gate D — you approve going live**, one provider at a time, after the controlled orders succeeded.

Before you tick anything: `FULFILLMENT_EXTERNAL_WRITES_ENABLED` was set back to `false` at the end of the controlled phase (step 8.7), and the checklist item *Sending to providers allowed on this server* is unmet while it is. Set it to `true` again and run `php artisan queue:restart`; do it when you mean to go live, because from then on orders flow as soon as automatic sending is on.

The page decides, not a person's memory. *Before automatic sending* is derived from what really happened on this server:

| Item | Met when |
|---|---|
| Credentials saved · Integration switched on · Sending to providers allowed on this server | the three switches above |
| Live verification passed recently | the latest run has no failure and is under 14 days old (a run in which nothing was asked does not count) |
| Field maps verified | every map of the provider (Torod Point's orders map is verified for its shape; see the next row) |
| **Invoice number round trip confirmed** (Torod Point only) | the master administrator has recorded the evidence on the provider page, for the current path and account |
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
| **Stop everything now** | 1. On **both** provider pages untick *Integration enabled* and save: this is read from the database on every call, so it stops the website, the scheduler **and every queue worker** at once (an automatic cancellation of an accepted Torod Point shipment waits too, and is sent once the provider is enabled again). 2. Then set `FULFILLMENT_INTEGRATIONS_ENABLED=false` and `FULFILLMENT_EXTERNAL_WRITES_ENABLED=false` in `.env` and run `php artisan config:clear` and `php artisan queue:restart`; **confirm the worker restarted** (`supervisorctl status`, or `ps` start time). A queue worker started before the edit keeps the old values until then. A request already on its way to a provider cannot be recalled: an order a worker had already claimed (seconds; longer when M5AZN needs a fresh sign-in first) is sent in the mode that was in force when it was claimed, because the dry-run decision is taken once, at the claim | seconds, then a minute |
| Automatic sending misbehaves | untick *Send automatically (no approval per order)* (read from the database: instant): every due order goes back to waiting for approval | seconds |
| A shipment was sent that should not have been | before its package exists it cannot be recalled by us (M5AZN documents no cancel endpoint, Torod no warehouse-order cancel): use the provider's dashboard or contact; once a Torod package exists, *Cancel at the provider* asks for it. The shipment page records the reference and everything sent | per order |
| The read-only checks show a FAIL (phase 4b) | nothing in the database has changed and the files do nothing: leave them while we read the report, or take them back with the row *The new code misbehaves*. Do **not** run the migration | — |
| `apply` failed or was interrupted | see the table in 4a: it takes back what it wrote; if it was killed, run its `rollback` with that backup directory. The site stays in maintenance mode until the application starts and `fulfillment:preflight` is clean | minutes |
| The new code misbehaves (phase 4a–5) | **If the migration has run, undo it first (the row *The migration must be undone*, below), while its files are still on the server.** Then `php "$PKG/deploy.php" rollback --backup-dir=$BACKUPS/deploy/<the directory apply printed>` (**newest package first**: with Hotfix 1 applied, its own directory and its own `deploy.php`, then the first package's) restores every replaced file **first**, then deletes every added one, removes cut-off temporary files, and takes back **only** the language keys the release added (the site's own keys stay). Running it twice says it was already rolled back. It refuses if a deployed file changed afterwards, unless `--force`; and it refuses, whatever the flags, when a saved original in the backup directory is missing or no longer matches its recorded checksum (it never writes an empty or different file over a live one), or when the record `applied.json` is damaged or names a path a release may not touch. In those cases it changes nothing and says what to restore by hand. Run it under `php artisan down`. Then `php artisan optimize:clear`, reset OPcache, `php artisan queue:restart` | a minute |
| The application does not start after the files landed (for example *Class not found*) | `php artisan up` cannot run either. Run the `deploy.php rollback` above, then `rm storage/framework/down` to leave maintenance mode | a minute |
| The migration must be undone | with the integration off, and after `backup.sh --db-only` (the provider tables hold the only local record of provider references: keep that dump). `php artisan migrate:status` shows the **Batch** of our migrations; if the two integration migrations are the last batch: `php artisan migrate:rollback --force --path=database/migrations/2026_11_05_100100_add_fulfillment_provider_to_products_table.php --path=database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php`; if any other migration ran after them, add `--batch=<their batch>` (otherwise it rolls back nothing). The two widening migrations have no rollback on purpose (narrowing a column could cut off what has been stored in it since): leave the columns wide, the previous release does not mind. It drops our 11 tables and the one nullable column; **it leaves `integration_settings`** (which holds the saved, encrypted credentials) and every store table. Shipments already at a provider are not recalled. Then `php artisan optimize:clear` and `php artisan queue:restart` (workers remember that the migration has run). The column is the only marker that a product belongs to a provider: products assigned to or imported from one become ordinary in-house products, so unpublish or review them first | a minute |
| The database itself was damaged | last resort, and it **discards everything ordered since the backup**. 1. `php artisan down`. 2. `backup.sh --db-only` of the damaged state, so even this is reversible. 3. Undo our migrations first (the row *The migration must be undone*), or drop our 11 tables by name (`provider_fulfillments`, `provider_fulfillment_items`, `provider_fulfillment_events`, `provider_api_logs`, `provider_sync_states`, `provider_product_mappings`, `provider_catalog_items`, `provider_price_changes`, `provider_cities`, `provider_city_aliases`, `product_provider_changes`): a restore does **not** remove tables the dump does not contain, and rows left in them would point at orders that no longer exist. 4. Restore `db.sql.gz` as `RESTORE.txt` describes. 5. `php artisan optimize:clear`, `queue:restart`, then run `fulfillment:preflight` before `php artisan up` | the length of the restore |
| The application directory was damaged | `tar -xzf files.tar.gz -C "$APP"` from the same backup (it also restores `.env` and `storage/`) | minutes |

The previous release ignores the new tables, the nullable column and the wider `order_details` columns, so putting the old code back while they exist is safe.

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
| D1 | **`APP_KEY`.** `.env.example` carries a real-looking key. If production runs the same key, anyone with the repository can decrypt the provider credentials you are about to enter. `fulfillment:preflight` compares them only when `.env.example` is on the server, which this package does not ship; otherwise compare by hand **without printing the key**: on the server, as the application's owner, `php artisan tinker --execute='echo hash("sha256", config("app.key")), PHP_EOL;'` prints the SHA-256 of the key the application really uses (however the `.env` line is quoted or ended). The example key's SHA-256 is `3c31a00b8939d8414babfdd1189a5fd229c14cf0ef270ac1949ca56ae57a0fa6`: **equal means production runs the public example key**. (Do not compare the `.env` line with `sed`/`sha256sum`: a quoted, CRLF-ended or `export`ed line hashes differently although it is the same key.) | If they match: rotate it **before** entering real credentials. There is no re-encryption tool: after the key changes, everything stored with `Crypt` on this platform can no longer be read and must be typed in again — the Zender secret, MTN Cash data and keys, the Chatwoot / WhatsApp credentials (`integration_settings`, the same table as the provider credentials), the Delivery Syria tokens — and every session ends. Note those values down from their own screens first. If you accept the risk instead, say so in writing |
| D2 | **OTP codes are constant.** `config('app.mode')` is never defined, so every login/verification code is `123456` (6 digits) or `1234` (4 digits) | A production security defect unrelated to this PR. Fix or accept deliberately |
| D3 | **Secrets committed to the repository:** ECDSA private-key files `Apple-AuthKey.p8` and `Apple-AuthKey (copy).txt`, each in `public/assets/back-end/` (web-served) and again under `resources/assets/back-end/`, the licence purchase key in `config/system-addons.php`, three public maintenance routes guarded by literal `-xk29` keys, a Paymera literal | Rotate whatever is real. None is used by this integration. Phase 0 checks that both public copies answer 403/404 |
| D4 | The document root may be the application root (phase 0) | Point the web server at `public/`, or deny everything except `index.php`, `public/` and `storage/app/public` |
| D5 | Pre-existing debug writers log **OTP + phone** (`ZenderOtpService`), parcel bodies with name/phone/address (`DeliverySyriaService`) and gateway callbacks to `storage/logs`, and `laravel.log` is truncated hourly for non-local requests | Use `LOG_CHANNEL=daily`; remove the debug writers; decide log retention (the API log tables are this project's evidence trail) |
| D6 | The platform's own pending migrations, if `migrate:status` shows any (phase 0) | Separate change, separate backup and rehearsal. Not part of this deployment |
| D7 | **SEC-1 (found while preparing this, fixed in this release):** four admin routes, among them the Zender (OTP gateway) settings, were reachable **without signing in**. The fix does not undo anything that already happened while the site was reachable | Treat the Zender secret and API key as exposed: rotate them with the gateway, review the gateway's sent-message log for messages you did not send, and search the web server's access log for requests to those routes (`security-findings.md`, SEC-1). Do this at Gate A1, before or together with the files |

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
| the write guard, the approval gate, the go-live checklist and its audited override; no duplicate order under retries and ambiguous outcomes, and under races that the tests **simulate by interleaving in one process** (not with parallel workers); money, VAT and COD rules; the overlay build, check, apply, kill-and-undo, full-disk and rollback paths including drift and language-file merge; the backup scripts' paths and failure behaviour (with stand-ins for `mysql`/`mysqldump`); the read-only schema check against a synthetic production-shaped schema | the backup and restore with a **real** MySQL 8 and a production-shaped schema; the two migrations (`--pretend`, run, rollback by path; 423 ms and 28 ms on empty tables) | the schema, data and drift of **your** database and files; how long the migration takes on real tables; **both providers' real response shapes**; the order-creation response; webhook delivery from LogesTechs; real latency and rate limits; your queue/cron/OPcache/ownership setup |

*Nothing in the right-hand column is claimed to work. It is what phases 4b, 6, 7 and 8 exist to establish.*

**Hotfix 1** has its own tests (`PilotServer*Test`, `CurrencyCodeTest`, `ExchangeRateTest`, `LegacyColumnWidenerTest`) on a schema modelled on what was found on
this server. The two column migrations were run on **MySQL 8.0.46 and MariaDB 10.11.14** (and CI runs the fulfillment suites on MySQL 8);
they were **not** run on MariaDB 11.4.2, the engine of the pilot server, which could not be installed where this was prepared. That is
what the read-only *column changes the migrations would make* line, the backup taken just before, the refusal-before-alter rules and the
read-back after the change are for.

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
