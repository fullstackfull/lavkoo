# Old addresses, old orders and how to roll back

Nothing in this feature deletes, rewrites or "improves" what the store already holds. The structured address is **added next to** the old columns, and an administrator decides when it starts to be used.

## What is never touched

| Data | Guarantee |
|---|---|
| `shipping_addresses` rows | no row is deleted; `city`, `state`, `country`, `zip`, `address`, `phone`, … keep their values; the four new columns (`region_id`, `city_id`, `district`, `national_address`) are nullable and empty for old rows |
| `zip` | never derived from, copied from or padded from the National Address, a building number or a city; never set to `00000` / `N/A`; an edit that does not mention it leaves it as it was |
| `orders.shipping_address_data` / `billing_address_data` | the copy an order keeps is built once when the order is placed and **never rewritten**: renaming a city, switching it off, moving it to another region or editing the saved address changes nothing in a past order. Old copies have no region/city ids and keep printing the way they always did (invoices keep the single *City / Zip* row) |
| reports and exports | every old key stays in the copy (`zip` included); the new keys are additions |
| order `100002` | not modified by this feature; its fulfillment hold is re-evaluated by the normal sweep once a city mapping is approved |

There is no "nearest city", no fuzzy matching and no guess anywhere: a place is either matched **exactly and uniquely**, or a person decides.

## How an old saved address becomes structured

1. **Nothing happens by itself at deployment.** The migrations only add empty columns and tables.
2. After `address:import`, a **dry run** (`php artisan address:backfill-legacy`, or *Address system → Old addresses* in the admin panel) reads each *distinct typed city + typed state* once and classifies it:

   | Result | Meaning | Applied? |
   |---|---|---|
   | `matched` | an administrator-confirmed spelling points at one city, or exactly one *registered* city (one the register lists districts for, rank above 0) has that name and the typed state does not point elsewhere, or exactly one place has it and the typed state names its region | yes, **only when you press apply** |
   | `unconfirmed` | exactly one place has the name but it is a small one (rank 0) and nothing confirms it: the list lacks most districts, so a district typed in the city box looks just like a village of that name in another region (for example *الشفا*, a village in Asir) | never automatically: the review page offers it with a button |
   | `ambiguous` | several cities share the name (for example *الهفوف* or *جدة*, which the source data also lists as villages) | never automatically |
   | `conflict` | the typed state belongs to another region than the only city of that name | never automatically |
   | `none` | the typed text is not in the list | never automatically |
   | `inactive` | the unique match is switched off | never |

3. Applying (`--apply`, or the *Apply all certain matches* button) writes **only `region_id` and `city_id`** on rows whose `city_id` is still empty, through the query builder (so `updated_at` does not move and
   "latest address" lists keep their order), 500 ids per statement, one transaction per matched pair. The typed text, the ZIP and the National Address are not touched.
4. Everything else is decided by a person on the *Old addresses* page: pick the city for a typed text once; the spelling is remembered as a confirmed alias (with who confirmed it) and every saved address typed that
   way gets the city. A confirmation never overrides an alias that points at another city and never uses a switched-off city.
5. Whatever is still unmatched keeps working as an old address; it is completed by the **customer** the next time it is used (below).

The queue shows counts only; `address:status` prints no personal data.

## Reusing an old Saudi address after the mode is turned on

* **Website and panels (`soft` and `strict`)**: choosing an old Saudi address (free-text city, no valid National Address) at checkout shows a *Needs completion* badge and the structured fields pre-filled from what is known; the order cannot
  continue until region, city and National Address are given, and completing it updates the saved address (the "update this address" box is ticked for the customer).
  A panel user editing an old Saudi order must likewise give region, city and National Address even for a phone fix — the contact phone must also be a valid Saudi mobile.
* **Mobile app / other API clients**: in `soft` an old app may keep sending the old fields; the order is accepted and its copy is marked `legacy`; at fulfilment its typed city is used **only when it is certain** (an administrator's alias, or exactly one city of ours that has an approved Torod mapping) and the order is **held for a person** otherwise. In `strict`
  every client must send the structured address.
* **Orders already paid** are never refused for their address after the money is taken (payment callbacks): an old Saudi address simply becomes a `legacy` copy, whose typed city the fulfillment layer uses only when it is certain and holds the order for a person otherwise.

## What the fulfillment layer does with old orders

An old order has no city id, so Torod's resolver tries (1) an administrator's Torod alias, (2) the same `LegacyCityMatcher` as above, (3) that city's approved mapping. Otherwise the order is **held** with a reviewable reason
(`no_mapping`, `pending_review`, `ambiguous:<ids>`), never sent. After the migrations run and the city list is imported, old orders therefore need an approved mapping: the first *complete* city reading creates the unambiguous
ones automatically, a person approves the rest (see [torod-city-mapping.md](torod-city-mapping.md)).

## Rollback, from the safest step

Each step is independent; do only as many as you need.

1. **Switch the mode off** (*Address system → Address mode → Off*, or `php artisan address:mode off` when the panel cannot be used; both drop the settings cache). Effective immediately, no deploy, no data change. (Editing the `unified_address_mode` row of `business_settings` by hand is *not* immediate: business settings are cached for three hours, so follow it with `php artisan optimize:clear`.) the website, the panels and the API return to the
   old forms and the old validation. The mode is also forced to `off` automatically while the ZIP-code delivery restriction is on, and while the migrations or the city list are missing.
   Orders placed meanwhile keep their structured copies; the extra keys are ignored by every old reader.
2. **Torod**: nothing to undo. Approved mappings are inert while no order carries a `city_id`; held orders stay held. If a mapping was approved by mistake, *Retire* it on the matching screen (the row is kept for the audit trail).
3. **Roll the files back** with the overlay tool (`php "$PKG/deploy.php" rollback --backup-dir="$BACKUPS/deploy/<the directory apply printed>"`, newest package first — see section 10 of the [pilot runbook](../integrations/pilot-deployment.md)). The old code ignores the new columns and tables, so the files can go back without touching the database.
4. **Optionally remove the data** (only after a verified backup; this is the one step that loses new data). The runbook applies the four migrations in **one** `migrate` run, so they form one batch and roll back in one command, newest first.
   Always with the four `--path` options and never without them: a bare `migrate:rollback` would undo the *last batch*, whatever it contains.

   ```bash
   php artisan migrate:status | grep 2026_11_07_10                       # all four must show the same batch number
   php artisan migrate:rollback \
       --path=database/migrations/2026_11_07_100000_create_address_master_tables.php \
       --path=database/migrations/2026_11_07_100100_add_unified_address_columns_to_shipping_addresses_table.php \
       --path=database/migrations/2026_11_07_100200_create_provider_city_mapping_tables.php \
       --path=database/migrations/2026_11_07_100300_add_directory_columns_to_provider_cities_table.php
   ```

   Laravel rolls back only migrations of the **last batch** that are inside the given paths, and says *Migration not found* for any other migration of that batch, so the command cannot undo anything else. If something else was
   migrated after ours (the output says *Nothing to rollback*, or lists fewer than four), add `--batch=<the batch number migrate:status shows for ours>`.
   Each `down()` drops only what its `up()` added (the three new tables and, on `shipping_addresses`, the four columns; then the provider mapping tables; then the six directory columns of `provider_cities`), guarded by `hasTable` / `hasColumn`. On `shipping_addresses` a column is dropped only together with the index this migration created for it (the district goes with the National Address column), so a column of the same name that the table already had is left alone and the rollback does not stop half way; the runbook asks for a look at the table before migrating.
   Afterwards run `php artisan optimize:clear && php artisan queue:restart`: a running worker still remembers that the tables exist and would fail Torod jobs until it restarts (the file rollback restarts them as well, which is why the order above puts it first).
   The old address columns, the old orders and every other table are untouched. You lose: the region/city links on saved addresses, the district and National Address columns, the confirmed aliases, the provider mappings and the sync history.
   The order copies keep their own JSON.

## What can go wrong, and the answer

| Situation | Answer |
|---|---|
| A city was imported under the wrong region | edit it (*Address system → Cities*); the saved addresses of that city follow the new region (`region_id`; the structured address never writes the old `state` text, and a typed one is never rewritten), past orders keep theirs |
| A city should no longer be offered | switch it off; it is never deleted (addresses, mappings and order copies point at it) |
| The data file has newer information | `address:import` is idempotent. It never changes whether a place is active, and it changes names only with `--refresh-names`; it does refresh the rank, the coordinates and the source date of the places the file lists, so an administrator's edit of those two is overwritten by the next import that lists the place |
| The structured mode must not start while ZIP-based delivery rules are in use | the mode cannot be switched on while `delivery_zip_code_area_restriction` is on, and is paused if it is turned on later; see [zip-compatibility.md](zip-compatibility.md) |
