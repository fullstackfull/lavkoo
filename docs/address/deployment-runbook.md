# Deploying the unified Saudi address — runbook

**Nothing here may be done without the owner's explicit approval, step by step.** The branch is a draft pull request: it is not merged and not deployed, and no migration has been run anywhere but in the test databases.

This runbook uses the same tooling and conventions as the [pilot runbook](../integrations/pilot-deployment.md) (overlay package, `deploy.php`, backup scripts, explicit `--path` migrations); read its phases 0–2 first.

## Controls that stay in force for the whole procedure

* Do not edit `.env` on the server. Do not turn **External Writes** on. Do not stop the Torod **Dry Run**. Do not turn **Auto Dispatch** on.
* Send nothing to Torod or any provider by hand; run no inventory sync that touches real stock.
* Do not delete or edit order `100002` (it stays held until a city mapping is approved).
* Do not run a bare `php artisan migrate` (the platform has hundreds of migrations of its own; only the four listed below, by path), and do not use the admin panel's *System setup → Environment setup → Migrate Database / Update Database* buttons or the software-update flow while the files of Release A are on the server and Release B has not been approved: they run a bare `migrate --force`, which would also apply the four address migrations at once.
* No credentials or API secrets in logs, tickets or chat.
* Do not touch the warehouse / M5AZN / shipping work running in other conversations: this release changes none of the Torod payload fields they depend on (`cityId`, `regionId`, `addressLine1` are only *better fed*).

## What ships, in two releases

| | Release A — files | Release B — database, data, switch |
|---|---|---|
| What | the application code, views, routes, language keys, seed data files | four additive migrations, the city import, the mode switch |
| Effect | **none**: the structured address is dormant until its tables exist; every page, API response and order copy is the one the store had | the admin chooses when customers start using it |
| Needs approval | yes | yes, separately |
| Rollback | `deploy.php rollback` | mode `off`; optionally `migrate:rollback` |

Release A only adds an **Address system** entry under *Business setup* in the admin sidebar; opening it before Release B shows which of the four migrations are still to run (HTTP 503 with an explanation, no stack trace).

---

## Release A — the files (about two minutes, database untouched)

1. **Build the package** from the commit the server runs now to the release commit. `--base` is the `head` recorded in the `MANIFEST.json` of the last package applied on the server:

   ```bash
   php scripts/deploy/build-overlay.php --base=<what the server runs> --head=<release commit> --out=build/overlay --archive
   ```

   The builder refuses anything an overlay cannot do (a deletion, a change under `public/` or `composer.*`, a file mode change). This release touches `app/ config/ database/ resources/ routes/` only: **no file under `public/`** (all page scripts are inline Blade) and no dependency change.
2. **Backup, verify, apply** exactly as in the pilot runbook (phases 2, 3, 4a): `backup.sh`, `deploy.php verify`, `deploy.php apply` (dry run first, then `--yes --backup-dir=…`). Language keys are merged key by key; the site's own keys are never replaced.
3. `php artisan optimize:clear`, reset OPcache, then **`php artisan queue:restart`** (the "migrated" answer of the new code is remembered per process, so workers must start fresh after Release B as well).
4. **Smoke-test — mode is off, so everything must look and behave as before:** storefront checkout (guest and signed in), account → addresses (add, edit), an old order page and invoice, admin order details and invoice, vendor order page, POS add-customer, the mobile API (`GET /api/v1/config` → `unified_address.mode` is `off`), and *Business setup → Address system* (explains the pending migrations).

If any of this differs from before, run `deploy.php rollback` (pilot runbook, section 10).

---

## Release B — the database (about a minute of maintenance) — **needs a separate approval**

**Prerequisites:** Release A is live and smoke-tested; a **fresh** database backup that you have proven restorable; the data-licence decision below is taken; a staging rehearsal is recommended (restore the backup into a scratch database and run steps 2–6 against it).

> **Licence decision first (owner).** The bundled city list is a snapshot of the National Address Directory published by a third party under **GPL-2.0** (see [data-sources.md](data-sources.md)). Decide whether the store may import it, or import a list from another source
> (the file format is documented there; the administrator can also add or edit every city by hand). **Do not run step 5 until this is decided.**

1. **Maintenance and backup.**

   ```bash
   php artisan down --retry=60
   bash "$PKG/tools/backup.sh" --app="$APP" --to="$BACKUPS/data" --db-only      # must end with "Backup complete: <directory>"
   ```

2. **Run the four migrations — one command, by path, in this order** (they form one batch, which keeps the rollback a single command). Each is additive, guarded by `hasTable` / `hasColumn`, nullable, with no foreign keys to store tables:

   ```bash
   php artisan migrate \
       --path=database/migrations/2026_11_07_100000_create_address_master_tables.php \
       --path=database/migrations/2026_11_07_100100_add_unified_address_columns_to_shipping_addresses_table.php \
       --path=database/migrations/2026_11_07_100200_create_provider_city_mapping_tables.php \
       --path=database/migrations/2026_11_07_100300_add_directory_columns_to_provider_cities_table.php
   ```

   (`APP_ENV=production` asks for confirmation: answer yes.) The Torod integration's own tables (`provider_cities`) must already exist: the fourth migration skips itself when they do not.
3. **Check, read-only.** `php artisan address:status` prints migrations, mode, list sizes and counts of old addresses — no personal data. All four migrations must show *done*. The existing `fulfillment:preflight --allow-production-read-only` must still end without a FAIL (the address tables are deliberately not part of its own-table list).
4. **Bring the site back**: `php artisan optimize:clear && php artisan queue:restart && php artisan up`. **The mode is still `off`: nothing has changed for customers.**
5. **Import the lists.** First a dry run (the default), read it, then apply:

   ```bash
   php artisan address:import                    # dry run: counts of regions, cities and aliases it would add or change
   php artisan address:import --apply            # 13 regions, 4,581 places, 57 curated spellings; never deletes, keeps administrator edits
   ```

   The same is available in the admin panel (*Address system → Import*: preview, then a confirmed apply). Open *Address system → Cities* and look: the capital cities are listed first in each region; switch off what you do not want offered.
6. **Old addresses (optional, after step 5).** `php artisan address:backfill-legacy` is a dry run; read the counts, then `--apply` only for the certain matches. The rest is decided by a person in *Address system → Old addresses* (see [legacy-and-rollback.md](legacy-and-rollback.md)).
7. **Torod (reads only; nothing is created, sent or changed at Torod).** *Integration Center → Torod Point → City matching*: **Sync cities now**; read *Complete* / *Partial*; use *Find candidates at Torod Point* for the cities that held orders are waiting for; approve exactly one candidate per city by id and region
   (the two Riyadh records are shown side by side and nothing is chosen for you). Order `100002` leaves its hold on the next sweep and stops at the normal approval gate — Dry Run stays on. Details: [torod-city-mapping.md](torod-city-mapping.md).
8. **Turn the structured address on — `soft` first.** *Address system → Address mode → Soft*. The switch is refused while: the migrations are missing, no city is active, or the **ZIP-code delivery restriction** (*Business setup → Delivery zone*) is on. (If the restriction is turned on later, the store pauses the structured address and
   goes back to the old address with the ZIP code until it is turned off.) Then repeat the smoke tests of Release A, this time expecting: region / city / district / National Address on the website checkout, account book, admin and vendor order edit, POS add-customer; ZIP shown only for other countries.
9. **`strict` later**, only when every mobile app version in use reads `unified_address` from `GET /api/v1/config` and sends the structured fields. `soft` lets the old app keep working (its orders wait for review at fulfillment instead of failing).

## What to watch after step 8

* *Address system → Overview*: counts of saved Saudi addresses with a region and city, old ones to complete, addresses without a ZIP.
* The Torod matching screen: held orders by reason (`no_mapping`, `pending_review`, `ambiguous:…`). A new hold means a city without an approved mapping — approve it, do not work around it.
* `storage/logs`: no address, phone or National Address is logged by this feature; a `ValidationException` is shown to the customer as a message.
* Checkout conversion for Saudi customers on the day (a rise in abandoned checkouts at the address step would mean a usability problem: set the mode back to `off` and tell us).

## Rollback

In this order, stopping as soon as the problem is gone: **mode `off`** (immediate) → **`deploy.php rollback`** (files; the database can stay) → **`migrate:rollback` of the four paths** (removes the new data; only after a verified backup). Exact commands and what each step loses: [legacy-and-rollback.md](legacy-and-rollback.md).

## Known limits to know before you switch it on

* **Auction module**: `Modules/Auction` is not part of this repository (only its payment-callback helpers in `app/Utils/module-helper.php` are, and those now keep the same address copy as any other order). The claim-checkout page and the code that writes an auction winner's address live in the module on the server, which this release cannot change. Test an auction claim on a copy before the mode is turned on, or keep auctions out of the first window.
* **External order import**: no code in the repository writes an address for an externally imported order yet; the shared structure is ready for it.
* **Themes other than `default`** (for example an add-on theme installed only on the server) still post the old address fields: in `soft` mode their Saudi checkout would be refused. Confirm the production theme before step 8.
* **ZIP-based delivery rules** keep working only while the mode is paused; an area-based replacement (region/city rules, stages S2/S3 in [zip-compatibility.md](zip-compatibility.md)) needs the owner's decision.
* **National Address existence is not verified** (SPL publishes the shape, not the list of codes); the shape is enforced, nothing claims a code is real. See [national-address.md](national-address.md).
* **Torod**: the paging of the city list and the v2↔v5 id equivalence are unproven; the questions for LogesTechs are in [torod-city-mapping.md](torod-city-mapping.md). The first real orders stay behind the approval gate.
* **One pre-existing finding, unrelated to this work and left as it was:** `routes/web/routes.php` has a diagnostic route `/diag-write-xk29` (from the first commit of the repository) that is protected only by a fixed value written in the code, tests writes into `storage/app/public` and, with `?fix=1`, changes file permissions. It should be reviewed by the owner and removed or protected.
