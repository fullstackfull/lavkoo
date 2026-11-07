# Region and city data: sources, coverage and licence

## What is in the repository (test and development data)

`database/data/address/regions.json` (13 regions) and `database/data/address/cities.json` (4,581 places) — **never packaged; the store reads its own copy from Saudi Post, see below** — loaded by `php artisan address:import` (dry run by default,
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

## Where the production data comes from — decided

**The store reads its lists from the official source, Saudi Post's (SPL) National Address Lookups API, with the owner's own key (`php artisan address:fetch-spl`).** Nothing from that source is kept in the software package or in the repository: the store makes its own copy, under its own subscription, in its own database.

* **The key** is free (<https://api.address.gov.sa/>, "Sign up"). The API Terms of use are shown at registration (their link on the public pages has no address), so **the owner reads them there; if they forbid keeping the lists in the store's own database, the command is not run.** What the public pages do say: the portal calls the API "publicly available for all business sectors", and SPL's API page says the national address databases are available "for commercial and governmental uses". They state no rule on storing the lists.
* **The free Starter plan** allows 5 calls a minute and 100 a week. A full read is 28 calls (the regions, then the cities of each of the 13 regions, each in Arabic and in English), 13 seconds apart: about six minutes. The command writes `regions.json`, `cities.json` and `aliases.json` under `storage/app/address/spl/` (readable by the application only) and, with `--apply`, imports them. It stops before writing anything when the key is refused (401), the plan is used up (403), the answer is not the shape the importer was written against, a region is not the one its id stands for, a city appears under two regions, or fewer than a thousand places come back.
* **What the service does not give** is coordinates and district counts. `rank` (which places a picker offers first, and what tells a city proper from a hamlet when an old typed city is matched) is therefore 1000 for the cities the curated spellings are about, 1 for the seat of a governorate (a city named like its governorate) and 0 for the rest. The curated spellings (`aliases.json`, written for this project) are kept only for cities the service lists under the expected name.
* **The shape of the answers** (`Regions[{Id, Name}]`, `Cities[{Id, Name, GovernorateName}]`, `success`, `statusdescription`; the key goes in `api_key`; `language` A or E, `format`, `encode=utf8`, `regionid`) was read from the portal's public documentation and is tested against a stand-in of the service, not against the service. The first real run is the proof: an answer of another shape stops the command with a message and writes nothing.

| Decision | Date | Decided by | Reference |
|---|---|---|---|
| Source: Saudi Post (SPL) National Address Lookups API, read with the owner's own key; the bundled files are test data and are never packaged | 2026-11-07 | the store owner's instruction to settle on a source whose use is permitted | the public statements above; **to be kept by the owner: the API Terms of use accepted at registration** |

### The bundled files are test data, never packaged

`database/data/address/regions.json` and `cities.json` are an extract of the National Address register that a third party republishes under GPL-2.0. They stay in the repository for tests and development only; `build-overlay.php` never puts them in a package, whoever builds it, so they never reach the store. They are not used because nothing found shows that the republisher had Saudi Post's permission to license them (public pages read on 2026-11-07; a reading of those pages, not legal advice):

| Source | What it says | What it does not say |
|---|---|---|
| The republishing repository, <https://github.com/homaily/Saudi-Arabia-Regions-Cities-and-Districts> | `LICENSE`: GNU General Public License version 2 (the standard text; the line for a copyright holder is left blank and no holder is named). README: "The data is public data collected from https://maps.address.gov.sa/"; its licence section says only "GPL-2.0" | that the data has a licence of its own, who owns it, or that its owner agreed to the republication |
| Saudi Post (SPL) open-data page, <https://splonline.com.sa/en/data-management-offices/open-data> | the reuse conditions of *its open-data platform*: cite it as the source with a link, do not distort the data or its source, use the data only for "legitimate and educational purposes" | a licence name, any term on redistribution or commercial use, or a dataset of regions, cities or districts |
| National Address API portal, <https://api.address.gov.sa/> | "publicly available for all business sectors"; a rights notice in favour of Saudi Post | the terms of use of the Lookups API or of storing its responses (its sign-up and documentation pages were not read) |
| A third-party licensing review of the same upstream data, <https://datahub.io/logistics/postal-codes-sa/.licensing-notes> | "NOT CLEAR TO SELL OR FREELY REDISTRIBUTE": a third party cannot put a GPL on data it does not own | that the register has no open licence: it calls that an open question |

Reading: a GPL label on a republished copy does not show that the republisher had the right to license the data, and nothing found shows the permission of Saudi Post (SPL) for this store to ship it. That is for the owner, with counsel, to settle; this document does not settle it.

If Saudi Post's terms turn out not to allow keeping the lists, the openly licensed sources (Wikidata is published under CC0 and GeoNames under CC BY 4.0: check both on their own pages before use) could be converted to the same file format; they hold fewer places than the register and are not the source the customers' National Addresses belong to, so nothing is prepared for them.

The importer accepts any file with `_meta.source`, so switching source later does not change code or data model.

## Refreshing

Run `php artisan address:fetch-spl` again (28 calls; the free plan allows three full reads a week). It writes new files and shows the import as a dry run (`created / updated / unchanged / skipped / not_in_file`); with `--apply` it imports. Places that disappeared are only *reported* (`not_in_file`); deactivate them in the admin screen if wanted — addresses,
mappings and order snapshots may still point at them. Files from a different source cannot be mixed in: a place is matched by the source's own id, so a second source would add its places beside the first.
