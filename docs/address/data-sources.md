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

The republishing repository is licensed **GPL-2.0**; the underlying register is public government data (National Address, SPL). The ship-ready data file is a separate text file, not code,
but the owner should decide (legal review) between: (a) keeping the file, (b) replacing it with an export from SPL's own **National Address Lookups API v3.1** (regions, cities with
governorate, districts; Arabic/English; stable ids; needs a free subscription key from SPL; default encoding windows-1256, so request `encode=utf8`), imported with the same command
(`address:import --apply --regions=<file> --cities=<file>`, same JSON shape), or (c) importing only the 13 regions (`--cities=<a file with an empty list>`: `{"_meta": {"source": "none"}, "cities": []}`) and adding the cities one by one in *Address system → Cities*.
With a source other than the shipped one the curated spellings (`aliases.json`) name places that source does not have: they are skipped, one notice each, and nothing is written for them. The panel's import page loads only the three shipped files, so with another source the command is the way.

The importer accepts any file with `_meta.source`, so switching source later does not change code or data model.

## Refreshing

1. Obtain the file (SPL API export or a newer snapshot) in the documented JSON shape.
2. `php artisan address:import --cities=<file>` (dry run) → read the counts (`created / updated / unchanged / skipped / not_in_file`).
3. `php artisan address:import --cities=<file> --apply`. Places that disappeared are only *reported* (`not_in_file`); deactivate them in the admin screen if wanted — addresses,
   mappings and order snapshots may still point at them.
