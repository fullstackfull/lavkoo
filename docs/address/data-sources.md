# Region and city data: sources, coverage and licence

## What ships

`database/data/address/regions.json` (13 regions) and `database/data/address/cities.json` (4,581 places) — loaded by `php artisan address:import` (dry run by default,
`--apply` to write, never deletes, keeps whether a place is active and what an administrator renamed). Every file records its source, snapshot date and origin in `_meta`; every row keeps the source's own id
(`ref`) so a later import updates the same row. Our own primary key is the internal `id`: third-party ids and names are attributes, never keys.

| | |
|---|---|
| Regions | 13 of 13 administrative regions, Arabic + English, ISO 3166-2:SA codes — verified against five independent sources (National Address register, Wikidata, dr5hn, GeoNames, ISO) |
| Places | 4,581 entries of the National Address register as published on <https://maps.address.gov.sa> and republished (snapshot of **2022-01-11**) at <https://github.com/homaily/Saudi-Arabia-Regions-Cities-and-Districts>; Arabic + English names, region, centre point |
| Order of offer | `rank` = number of districts the register lists for the place (only 152 places have any): large cities are offered first, villages after |

Honest coverage statement: **regions are verified; places are the register as of 2022, not reconciled with the live register.** About 1,190 places are confirmed by a second
independent signal (GeoNames name + coordinates). It was checked that all 20 major cities exist with the right region. Known quality notes:

* **375 Arabic names occur more than once** (290 across regions, 185 groups inside one region, e.g. `أم سليم` twice in Riyadh region). That is why names are never keys and why
  automatic matching refuses any name that points at more than one place.
* Arabic names are mostly written without hamza (`ابها`); search normalises letter variants, and an administrator can rename a place (the import then keeps the edit).
* There is no governorate layer and districts exist for 152 places only, so `district` is a free-text field for now (the register's 3,732 districts are *not* shipped because the list is
  too incomplete to enforce).

## Licence — decision needed before shipping `cities.json` to the server (Release A) and before importing it

**Status: unresolved.** Nothing in this repository or in the sources below shows that the file may be redistributed, and the owner has not decided. Until a decision is recorded in the table below, Release A is built without `cities.json` and `address:import` is not run.

### What the primary sources say (public pages read on 2026-11-07; a reading of those pages, not legal advice)

| Source | What it says | What it does not say |
|---|---|---|
| The republishing repository, <https://github.com/homaily/Saudi-Arabia-Regions-Cities-and-Districts> | `LICENSE`: GNU General Public License version 2 (the standard text; the line for a copyright holder is left blank and no holder is named). README: "The data is public data collected from https://maps.address.gov.sa/"; its licence section says only "GPL-2.0" | that the data has a licence of its own, who owns it, or that its owner agreed to the republication |
| Saudi Post (SPL) open-data page, <https://splonline.com.sa/en/data-management-offices/open-data> | the reuse conditions of *its open-data platform*: cite it as the source with a link, do not distort the data or its source, use the data only for "legitimate and educational purposes" | a licence name, any term on redistribution or commercial use, or a dataset of regions, cities or districts |
| National Address API portal, <https://api.address.gov.sa/> | "publicly available for all business sectors"; a rights notice in favour of Saudi Post | the terms of use of the Lookups API or of storing its responses (its sign-up and documentation pages were not read) |
| A third-party licensing review of the same upstream data, <https://datahub.io/logistics/postal-codes-sa/.licensing-notes> | "NOT CLEAR TO SELL OR FREELY REDISTRIBUTE": a third party cannot put a GPL on data it does not own | that the register has no open licence: it calls that an open question |

Reading: a GPL label on a republished copy does not show that the republisher had the right to license the data, and nothing found shows the permission of Saudi Post (SPL) for this store to ship it. That is for the owner, with counsel, to settle; this document does not settle it.

### Evidence that would settle it (any one, kept with the decision)

1. A written statement from Saudi Post (SPL), the owner of the register, that its regions and cities may be stored in the store's database and distributed inside its release package, commercial use included.
2. The terms of the National Address API subscription, read by counsel, that allow storing its responses: then option (b) below.
3. A written legal opinion that accepts the GPL-2.0 position for the data file: the owner's own risk decision, recorded as such.

### Decision record (completed by the owner; empty means undecided)

| Option chosen (a, b or c below) | Date | Decided by | Reference to the evidence |
|---|---|---|---|
| | | | |

### What follows while it is undecided

* **Release A can go out without the file**: build the package from a commit on which `database/data/address/cities.json` has been removed (the runbook says how; rehearsed: 148 files instead of 149, the file absent from the manifest). Nothing in Release A needs it: the structured address stays off because no city is loaded (and once the migrations have run, the panel's import page shows the file as not readable). `address:import` is not run in Release B.
* `regions.json` (13 regions: names and ISO codes) has the same origin and the same open question, a much smaller one; `aliases.json` (spelling variants written for this project, pointing at register ids) does not. If counsel wants the regions held back too, leave `regions.json` out of the package the same way and load the 13 regions later from a file the owner supplies (`address:import --regions=<file> --cities=<a file with an empty list>`).

### The options

The republishing repository labels its copy **GPL-2.0**; the underlying register is government data (National Address, SPL). The ship-ready data file is a separate text file, not code.
The owner decides, after a legal review, between: (a) keeping the file, (b) replacing it with an export from SPL's own **National Address Lookups API v3.1** (regions, cities with
governorate, districts; Arabic/English; stable ids; needs a free subscription key from SPL; default encoding windows-1256, so request `encode=utf8`), imported with the same command
(`address:import --apply --regions=<file> --cities=<file>`, same JSON shape), or (c) importing only the 13 regions (`--cities=<a file with an empty list>`: `{"_meta": {"source": "none"}, "cities": []}`) and adding the cities one by one in *Address system → Cities*.
With a source other than the shipped one the curated spellings (`aliases.json`) name places that source does not have: they are skipped, one notice each, and nothing is written for them. The panel's import page loads only the three shipped files, so with another source the command is the way.

The importer accepts any file with `_meta.source`, so switching source later does not change code or data model.

## Refreshing

1. Obtain the file (SPL API export or a newer snapshot) in the documented JSON shape.
2. `php artisan address:import --cities=<file>` (dry run) → read the counts (`created / updated / unchanged / skipped / not_in_file`).
3. `php artisan address:import --cities=<file> --apply`. Places that disappeared are only *reported* (`not_in_file`); deactivate them in the admin screen if wanted — addresses,
   mappings and order snapshots may still point at them.
