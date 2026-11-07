# Deploying the unified Saudi address — runbook

**Nothing here may be done without the owner's explicit approval, step by step.** The branch is a draft pull request: it is not merged and not deployed, and no migration has been run anywhere but in the test databases.

This runbook uses the same tooling and conventions as the [pilot runbook](../integrations/pilot-deployment.md) (overlay package, `deploy.php`, backup scripts, explicit `--path` migrations); read its phases 0–2 first.

## Controls that stay in force for the whole procedure

* Do not edit `.env` on the server. Do not turn **External Writes** on (`FULFILLMENT_EXTERNAL_WRITES_ENABLED`). Do not stop the Torod **Dry Run**. Do not turn **Auto Dispatch** on (*Send automatically (no approval per order)* on the provider page).
* Send nothing to Torod or any provider by hand; run no inventory sync that touches real stock.
* Do not delete or edit order `100002` (it stays held until a city mapping is approved).
* Do not run a bare `php artisan migrate` (the platform has hundreds of migrations of its own; only the four listed below, by path), and do not use the admin panel's *System setup → Environment setup → Migrate Database / Update Database* buttons or the software-update flow while the files of Release A are on the server and Release B has not been approved: *Migrate Database* runs a bare `migrate`, *Update Database* and the software update run `migrate --force`, and each of them would apply the four address migrations at once.
* No credentials or API secrets in logs, tickets or chat.
* Do not touch the warehouse / M5AZN / shipping work running in other conversations: this release changes none of the Torod payload fields they depend on (`cityId`, `regionId`, `addressLine1` are only *better fed*).

## What ships, in two releases

| | Release A — files | Release B — database, data, switch |
|---|---|---|
| What | the application code, views, routes, language keys, seed data files | four additive migrations, the city import, the mode switch |
| Effect | the structured address is dormant until its tables exist: the pages, API responses and order copies are the ones the store had, **apart from the mode-independent fixes** listed in [README.md](README.md#what-changes-even-while-the-mode-is-off) | the admin chooses when customers start using it |
| Needs approval | yes | yes, separately |
| Rollback | `deploy.php rollback` | mode `off`; optionally `migrate:rollback` (before the files are taken back) |

Release A only adds an **Address system** entry under *Business setup* in the admin sidebar; opening it before Release B shows which of the four migrations are still to run (HTTP 503 with an explanation, no stack trace).

---

## Release A — the files (about two minutes, database untouched)

1. **Build the package** from the commit the server runs now to the release commit. `--base` is the `head` recorded in the `MANIFEST.json` of the last package applied on the server:

   ```bash
   php scripts/deploy/build-overlay.php --base=<what the server runs> --head=<release commit> --out=build/overlay --archive
   ```

   The builder refuses anything an overlay cannot do (a deletion, a change under `public/` or `composer.*`, a file mode change). This release touches `app/ config/ database/ resources/ routes/` only: **no file under `public/`** (all page scripts are inline Blade) and no dependency change.
   The package that the CI job *Deployment overlay* attaches to the pull request is built from the repository variable `PILOT_BASELINE`, and falls back to an older commit when it is not set: set it to the commit the server runs now (the `head` of the last applied `MANIFEST.json`) before you use that artifact, or build the package yourself as above. `deploy.php verify` refuses a package made against another base (it reports the files as *DRIFTED*); that refusal is safe, and `--accept-drift` is not the answer to it.
   **Before the package is applied** the owner takes the data-licence decision below: `database/data/address/cities.json` is part of Release A, so it reaches the server (and, on an installation whose web root is the application folder, can be fetched from the web) before the import is ever run.
2. **Backup, verify, apply** exactly as in the pilot runbook (phases 2, 3, 4a): `backup.sh`, `deploy.php verify`, `deploy.php apply` (dry run first, then `--yes --backup-dir=…`). Language keys are merged key by key; the site's own keys are never replaced.
3. `php artisan optimize:clear`, reset OPcache, then **`php artisan queue:restart`** (a worker must run the new code, and a worker that still remembers tables a rollback dropped would fail Torod jobs; a worker started before the migrations notices the new tables by itself within 30 seconds, so Release B needs no restart for that).
4. **Smoke-test — mode is off, so everything must look and behave as before, plus the mode-independent changes in the README:** storefront checkout (guest and signed in), account → addresses (add, edit), an old order page and invoice, admin order details and invoice, vendor order page, POS add-customer **with the ZIP restriction on** and a POS delivery order, the **courier app** (an order's address), the **seller app** (edit an order's address), **guest order tracking**, the mobile API (`GET /api/v1/config` → `unified_address.mode` is `off`; **one cash-on-delivery order placed from the app**, **one address added and edited from the app**), **one online payment through a gateway** (a sandbox gateway, or a replayed callback on a copy: the payment callbacks now check that the address belongs to the payer and keep the order's address copy), the **Seller Center** order list and order page (the city printed is the one on the order's own address copy), the `ae` language when the store uses it (the generic words *Yes*, *Cancel*, *Save* are Arabic now), and *Business setup → Address system* (explains the pending migrations).

If any of this differs from before, run `deploy.php rollback` (pilot runbook, section 10).

---

## Release B — the database (about a minute of maintenance) — **needs a separate approval**

**Prerequisites:** Release A is live and smoke-tested; a **fresh** database backup that you have proven restorable; the data-licence decision below is taken; a staging rehearsal is recommended (restore the backup into a scratch database and run steps 2–6 against it).

> **Licence decision first (owner), before Release A is applied.** The bundled city list is a snapshot of the National Address Directory published by a third party under **GPL-2.0** (see [data-sources.md](data-sources.md)). Decide whether the store may ship and import it, or import a list from another source
> (the file format is documented there; the administrator can also add or edit every city by hand): to use another source, build the package from a commit on which `database/data/address/cities.json` has been removed (a throw-away commit on top of the release commit, used as `--head`): the builder has no exclude option, and `deploy.php verify` refuses a package from which a listed file was deleted by hand. **Do not apply Release A, and do not run step 5, until this is decided.**

1. **Maintenance and backup.**

   ```bash
   php artisan down --retry=60
   bash "$PKG/tools/backup.sh" --app="$APP" --to="$BACKUPS/data" --db-only      # must end with "Backup complete: <directory>"
   ```

2. **Run the four migrations — one command, by path, in this order** (they form one batch, which keeps the rollback a single command). Each is additive, guarded by `hasTable` / `hasColumn`, nullable, with no foreign keys to store tables. First look at the address book table (`SHOW COLUMNS FROM shipping_addresses`): none of `region_id`, `city_id`, `district` and `national_address` may exist yet. The migration would leave such a column as it is, but its rollback would drop the district together with the National Address index, taking a pre-existing `district` column and its data with it; if any of the four names exists, stop and tell us:

   ```bash
   php artisan migrate \
       --path=database/migrations/2026_11_07_100000_create_address_master_tables.php \
       --path=database/migrations/2026_11_07_100100_add_unified_address_columns_to_shipping_addresses_table.php \
       --path=database/migrations/2026_11_07_100200_create_provider_city_mapping_tables.php \
       --path=database/migrations/2026_11_07_100300_add_directory_columns_to_provider_cities_table.php
   ```

   (`APP_ENV=production` asks for confirmation: answer yes.) The Torod integration's own tables (`provider_cities`) must already exist (`SHOW TABLES LIKE 'provider_cities'`): the fourth migration skips itself when they do not, and Laravel still records it as done, so a second run of this command prints *Nothing to migrate* and `address:status` keeps listing it as pending. If that happened, apply the integration's own migrations first and then repeat only the fourth: `php artisan migrate:rollback --path=database/migrations/2026_11_07_100300_add_directory_columns_to_provider_cities_table.php` (it finds nothing to drop) followed by `php artisan migrate --path=database/migrations/2026_11_07_100300_add_directory_columns_to_provider_cities_table.php`.
3. **Check, read-only.** `php artisan address:status` prints migrations, mode, list sizes and counts of old addresses — no personal data. All four migrations must show *done*. Also read, in the database client, which of the columns the new writers send an empty value to are nullable: `SHOW COLUMNS FROM shipping_addresses` — `zip`, `city`, `address`, `latitude`, `longitude` and `phone` should say `YES` under *Null* (the installation dump that defines the table is not in the repository, so this cannot be proven here; the account book and the REST API already store a blank ZIP as NULL, which says it is). If one says `NO`, stop and tell us before step 8. The existing `fulfillment:preflight --allow-production-read-only` must still end without a FAIL (the address tables are deliberately not part of its own-table list).
4. **Bring the site back**: `php artisan optimize:clear && php artisan queue:restart && php artisan up`. **The mode is still `off`: nothing has changed for customers.**
5. **Import the lists.** First a dry run (the default), read it, then apply:

   ```bash
   php artisan address:import                    # dry run: counts of regions, cities and aliases it would add or change
   php artisan address:import --apply            # 13 regions, 4,581 places, 57 curated spellings (44 distinct once spelling variants are folded together, which is what the preview counts too); never deletes, keeps which places are switched off and what was renamed
   ```

   If the shipped `database/data/address/cities.json` is not on the server (option b or c of [data-sources.md](data-sources.md)), the two commands above stop with *The file … cannot be read* and write nothing: name the file, `php artisan address:import --apply --cities=<your file>` (and `--regions=<file>` when the regions come from the same source). The panel's import page loads only the shipped files, so it cannot be used then.
   **From the moment the list is imported, Torod-bound orders need an approved city mapping in every mode** (an order whose city has none is held, not sent): do step 7 right after this step, so that held orders do not pile up. Switching the mode off does not bring the old name matching back (see [legacy-and-rollback.md](legacy-and-rollback.md)).
   The same is available in the admin panel (*Address system → Import*: preview, then a confirmed apply). Open *Address system → Cities* and look: the places with the most districts (the large cities, not always the capital) are listed first in each region; switch off what you do not want offered.
6. **Old addresses (optional, after step 5).** `php artisan address:backfill-legacy` is a dry run; read the counts, then `--apply` only for the certain matches. The rest is decided by a person in *Address system → Old addresses* (see [legacy-and-rollback.md](legacy-and-rollback.md)).
7. **Torod (reads only; nothing is created, sent or changed at Torod).** *Integration Center → Torod Point → City matching*: **Sync cities now** (after a *Complete* reading it approves by itself every city that has exactly one exact, region-confirmed candidate: audited, and undone with *Retire*); read *Complete* / *Partial*; use *Find candidates at Torod Point* for the cities that held orders are waiting for; approve exactly one candidate per city by id and region
   (the two Riyadh records are shown side by side and nothing is chosen for you). Order `100002` leaves its hold on the next sweep and stops at the normal approval gate — Dry Run stays on. Details: [torod-city-mapping.md](torod-city-mapping.md).
8. **Turn the structured address on — `soft` first.** *Address system → Address mode → Soft*. The switch is refused while: the migrations are missing, no city is active, or the **ZIP-code delivery restriction** (*Business setup → Delivery zone*) is on. (If the restriction is turned on later, the store pauses the structured address and
   goes back to the old address with the ZIP code until it is turned off.) Then repeat the smoke tests of Release A, this time expecting: region / city / district / National Address on the website checkout, account book, admin and vendor order edit, POS add-customer; ZIP shown only for other countries. Then place **one test order from the website with a structured address and open it in the courier app, the customer app and the admin and vendor order pages** (the website posts the same default coordinates the old forms did, so the map block and the apps see numbers, as before).
   Two checks belong here. The region and city lists the pages search while a customer types are rate limited **per client address** (120 requests a minute for the website and again for the API): confirm that the application sees the customer's address and not the proxy's (the Integration Center's readiness check *client address*, and item 6 of [operations.md](../integrations/operations.md): `TRUSTED_PROXIES`), otherwise every customer shares one budget. And if you ever have to change the mode in the database instead of the panel, run `php artisan address:mode off` (or `php artisan optimize:clear` after a raw edit): business settings are cached for three hours.
9. **`strict` later**, only when every mobile app version in use reads `unified_address` from `GET /api/v1/config` and sends the structured fields. `soft` lets the old app keep working (its orders are accepted; at fulfillment the typed city is used only when it is certain, otherwise a person looks at the order first).

## What to watch after step 8

* *Address system → Overview*: counts of saved Saudi addresses with a region and city, old ones to complete, addresses without a ZIP.
* The Torod matching screen: held orders by reason (`no_mapping`, `pending_review`, `ambiguous:…`). A new hold means a city without an approved mapping — approve it, do not work around it.
* `storage/logs`: no address, phone or National Address is logged by this feature; a `ValidationException` is shown to the customer as a message.
* Checkout conversion for Saudi customers on the day (a rise in abandoned checkouts at the address step would mean a usability problem: set the mode back to `off` and tell us).

## Rollback

In this order, stopping as soon as the problem is gone: **mode `off`** (immediate from the panel, or `php artisan address:mode off`; a raw edit of the setting in the database is not seen until `php artisan optimize:clear`, because settings are cached for three hours) → only if the data must go: **`migrate:rollback` of the four paths** (removes the new data; only after a verified backup, and **before the files are taken back**: `deploy.php rollback` deletes the four migration files, which `migrate:rollback` needs) → **`deploy.php rollback`** (files; the database can stay). Exact commands and what each step loses: [legacy-and-rollback.md](legacy-and-rollback.md).

## Known limits to know before you switch it on

* **Auction module**: `Modules/Auction` is not part of this repository (only its payment-callback helpers in `app/Utils/module-helper.php` are, and those now keep the same address copy as any other order). The claim-checkout **page** is in the repository (`resources/themes/default/auction/web-views/product/details/_claim-checkout.blade.php`) and still asks for a typed city and ZIP; it posts to `auction.claim.process`, a route of the module on the server, which saves the winner's address and which this release cannot change. With the mode on, an auction winner can therefore still enter a Saudi city as text. Test an auction claim on a copy before the mode is turned on, or keep auctions out of the first window.
* **External order import**: no code in the repository writes an address for an externally imported order yet; the shared structure is ready for it.
* **Themes other than `default`** (for example an add-on theme installed only on the server) still post the old address fields: in `soft` mode their Saudi checkout would be refused. Confirm the production theme before step 8.
* **ZIP-based delivery rules** keep working only while the mode is paused; an area-based replacement (region/city rules, stages S2/S3 in [zip-compatibility.md](zip-compatibility.md)) needs the owner's decision.
* **POS delivery sales** (admin and vendor panels) copy the customer's saved address into the order as it is: an old address becomes a `legacy` copy (its typed city is used at fulfilment only when it is certain), and a customer with no saved address falls back to the profile fields, exactly as before. The structured address is entered when the customer or the address is created (*POS add-customer*) and when staff edit an order; complete a customer's address first if a POS delivery order must ship right away.
* **The weekly city sync** (Sundays 03:30, an existing schedule) also applies the automatic approval of unique candidates after a reading that proves the list complete: unattended, audited, reversible, and nothing is sent to Torod. See [torod-city-mapping.md](torod-city-mapping.md).
* **The city list a customer searches** offers the first 50 matches of what is typed, best match first. The imported list has 185 groups of places that share one name inside one region ([data-sources.md](data-sources.md)): they appear as identical rows, and choosing either is accepted. An administrator can switch the surplus one off (*Address system → Cities*). Typing a full name and leaving the box chooses it only when exactly one place has that name.
* **National Address existence is not verified** (SPL publishes the shape, not the list of codes); the shape is enforced, nothing claims a code is real. See [national-address.md](national-address.md).
* **Torod**: the paging of the city list and the v2↔v5 id equivalence are unproven; the questions for LogesTechs are in [torod-city-mapping.md](torod-city-mapping.md). The first real orders stay behind the approval gate.
* **Pre-existing findings, unrelated to this work and left as they were:** `routes/web/routes.php` carries three diagnostic routes that are protected only by fixed values written in the code. One of them is `/diag-write-xk29` (from the first commit of the repository), which tests writes into `storage/app/public` and, with `?fix=1`, changes file permissions. The release ships this file again because it adds the address routes, so the package will show it as changed; compare what `deploy.php verify` reports for it with this note, and do not use `--accept-drift` for it without reading the diff. The three routes should be reviewed by the owner and removed or protected in a change of their own.
