# Torod Point and the unified address

Torod Point is an **integration of the unified address**, not a second address system. The store keeps one list of regions and cities (`address_regions`, `address_cities`); Torod's own
city list is a cache of what the provider says (`provider_cities`), and a mapping row ties one of *our* cities to one of *theirs*. The same `provider_city_mappings` table serves any later
provider (M5AZN today keeps sending the Arabic city name; Amazon, Trendyol or Odoo would add rows with another `provider` value).

## What went wrong before

* Order `100002` (`100002-TP1`) is held as `hold_city_unmapped`. The resolver compared the *typed* city with a list cached from **one** call to the Lastmile cities endpoint that returned
  **10 cities** although `returnAll=true` was sent, and it refuses to guess when a name has more than one match.
* Torod lists **two "Riyadh"** records: id `36189` in region `1204` and id `230194` in region `1265`. Nothing in the documentation says which one is canonical, so neither may be picked silently.
* The old "Sync cities" button wrote whatever the first response held and reported success. Nobody could tell a complete list from a truncated one.

## How an order finds its Torod city now

```
order snapshot city_id ──▶ approved mapping (provider + city) ──▶ ProviderCity (id + region id) ──▶ payload cityId / regionId
```

1. **The order carries one of our cities** (`city_id` in `shipping_address_data`, set by the checkout / panel / API when the Saudi address is structured). The resolver looks up the *approved*
   mapping of that city for `torod_point`. No name is compared and none of our ids is ever sent to Torod. The Torod id and region id in the payload are the ones stored on the mapping's
   `provider_cities` row.
2. **An old order has only typed text.** The resolver tries, in this order: an administrator's Torod alias (which must name a city Torod lists, is audited, and holds the order as `provider_city_missing` once Torod stops listing that city; a spelling that is empty once punctuation is removed cannot be an alias) → the address system's own `LegacyCityMatcher` (unique and region-consistent, otherwise
   *no match*) → the mapping of the city that matcher found. The region the customer typed is only a filter; it is never a second guess at the city.
3. **No Saudi catalogue, no mappings table or no directory columns yet** (code shipped, migrations not run or only partly run): the resolver keeps the old name matching (an administrator's alias, then one exact name in the cached list). One thing differs on purpose: the address's *state* text is no longer tried as a second city when the city matches nothing, because that was a guess (a typed city that matched nothing would have been sent to the city of the same name as its state).

Resolving reads the local database only. Preflight and submit never call Torod for a city.

### Outcomes and the hold they cause

| Resolution | Meaning | Order |
|---|---|---|
| `resolved` | exactly one approved mapping and the provider still lists that city | goes on (still behind the dry-run / approval gates) |
| `unmapped` (`no_mapping`) | our city has no pending or approved mapping | held `hold_city_unmapped` |
| `pending_review` | exactly one candidate is waiting for a person | held |
| `ambiguous` (`ambiguous:36189,230194`) | several candidates (the two Riyadh records) | held — **nothing is chosen** |
| `provider_city_missing` | the approved city is no longer in Torod's list | held |

The hold detail is `code|city name` (machine-readable code, then our city's name). It never contains the street, phone, National Address or any other personal data. An order is never sent to a
"nearest" or "first" city, and a hold is never released by the sweep on its own: a person approves a mapping, the minute sweep re-evaluates the held order, and it then continues through the
normal `AWAITING_APPROVAL` / dry-run path. The order row itself (`100002`) is never edited by this feature.

## Candidates and approval

* A candidate is a Torod city whose **exact comparison key** equals ours (`AddressText::key()`: Arabic letter variants, the article *ال*, kind words, case and punctuation folded). A city that merely
  *contains* or *resembles* our name is not a candidate. Candidates are matched by name and region name only: nothing here reads a country from Torod's city records (question 9), so a namesake in another country would be offered as one more candidate (a unique match becomes ambiguous and a person decides), never chosen silently. A mapping's `country_code` stays empty until the provider says which country its city is in.
* Each candidate carries a **region check**: `confirmed` (Torod's region name matches our region), `unknown` (Torod gave no region) or `unplaceable` (a region name we cannot map to one of ours). A candidate
  whose region names *another* of our regions is dropped.
* Candidates are stored as `pending` mapping rows with their evidence (names, ids, region check, sync run). **Nothing is approved without a person**, except the automatic rule below.
* `Approve` records the administrator's id and name, the evidence and a timestamp, and writes an audit entry. The database allows **one approved mapping per provider and city**
  (`approved_slot` unique index); approving a second candidate is refused unless the screen sends the id of the mapping it replaces (`replacing`), so a stale browser tab can never overwrite a newer decision.
  `Reject` and `Retire` keep the row for the audit trail.
* A mapping can only point at a city the provider **currently lists**; the Torod ids of one provider are never accepted for another.

### The one automatic rule

After a city reading whose completeness is **proven**, our active city with exactly **one** exact candidate is approved automatically as `auto_unique` (one summary audit entry per run) when all of
this holds: Torod lists that name **exactly once** (two Riyadh records are two records, whatever their regions say, so Riyadh is always a person's decision), no other of our cities also claims the Torod
city, it is not already mapped to another of ours, Torod does not mark it as not selected (`isSelected: false`), its region name **agrees** with ours (a record that names no region, or one that cannot be placed, is only proposed: a name alone is not enough), and nobody has ever decided on that city. A partial
reading never approves anything, and a reading whose completeness rests only on the weakest evidence (see below) only *proposes*. The write is conditional on the proposal still waiting: a rejection, or
the approval of another candidate, that a person recorded while the run was working is never overwritten.

**The weekly schedule runs this rule too.** The existing Sunday 03:30 schedule (`fulfillment:sync --only=cities`) refreshes the list and, after a reading that proves it complete, applies the automatic rule unattended, like the console and the Integration Center button. Nothing is sent to Torod either way (the shipments stay behind the dry run and the approval gate), and every approval is audited with its evidence and can be retired. No setting turns the rule off: only a *Partial* reading prevents it, and with the integrations or the provider switched off no sync runs at all.

## The city list sync is completeness-aware

`fulfillment:sync --only=cities` (and the Integration Center button) calls `TorodCityDirectory::sync()`:

1. one request with `returnAll=true`;
2. when that response does not settle it — it states a larger total or more pages, or it holds **fewer than 1000 cities** (no national delivery list does) — the pages are walked (`page`,
   `pageSize=100`) for as long as each page adds cities not seen yet, up to the configured ceilings. The deployment defaults are
   in `config/fulfillment.php` under `fulfillment.providers.torod_point.cities`: **250 pages** (25,000 cities — the list holds
   20,858, so it is read to its end without a hand edit on a server; `TOROD_CITY_SYNC_MAX_PAGES` moves it), **600 s** on the
   console and 45 s on the web (`.max_seconds_console`, `.max_seconds_web`, or `.max_seconds` for both; `TOROD_CITY_SYNC_MAX_SECONDS`
   moves the console one) and **1000 cities** (`.complete_floor`). `TorodCityDirectory` clamps every one of them — at most
   `MAX_PAGES_CEILING` = **500 pages** (50,000 cities) and 1800 s, at least one page — so no setting, and no typo in one, can
   make the walk unbounded. The walk normally ends long before a ceiling: a page that repeats what was seen, an empty page or
   the provider's own total stops it. Reaching a ceiling is a `partial` reading, which adds and updates what it read and
   **deletes nothing, marks nothing missing and approves nothing**;
3. everything is read first and written afterwards, in one transaction, in chunks. Cities are **added or updated, never deleted**.

The verdict is recorded in `provider_city_sync_runs` and on the provider's sync state:

| Verdict | Evidence | Effect |
|---|---|---|
| `full` | the page walk ended on an empty page and contained the first response (**the only proof for a list under 1000 cities**: a truncated answer states its own total, last page or page count truthfully, or leaves the count at zero); or, from 1000 cities on, the provider's own total was reached, or the response flags its last page | cities the provider no longer lists get `missing_since`; the unique-match rule may run |
| `full` (weakest) | at least 1000 distinct cities and nothing says more exist | stored as `full` for display, but **nothing is marked missing and nothing is approved automatically**: a provider that caps its answer would otherwise put every city beyond the cap out of reach |
| `partial` | anything else (for example *10 returned, paging not honoured*) | cities are added/updated only; **no** `missing_since`, **no** success stamp, last error `partial: <reason>`, `fulfillment:sync` exits non-zero and prints `PARTIAL` |
| `failed` | the first request failed | nothing changes |

Because the paging behaviour of the Lastmile list is **not documented**, the code is written to be right under any behaviour and to say `partial` when it cannot tell.

## Finding a city's candidates when the list is partial

`php artisan address:discover-provider-cities torod_point --city=<our city id> [--city=…] [--held] [--apply]` (also the *Find candidates at Torod Point* button on the matching screen) asks Torod
for the cities named like ours with the one filter the documentation promises (`search`), caches what it finds and proposes the exact-name ones. It is a **read** (`lastmile_cities`, a GET) — without `--apply` it only asks Torod and reports, and it stores no city and no mapping (each request is recorded in the provider API log, like every call to Torod) — it skips when the
integration is switched off or not migrated, and it only ever *proposes*: a discovery result is never "unique at Torod", because the search itself may be paged.

## What reaches Torod in the order payload

`TorodOrderPayloadBuilder` sends `cityId` and `regionId` (the provider's own ids from the approved mapping) and `addressLine1` = street text plus the district (unless the street already names it).
It does **not** send the National Address and does **not** send `villageId`: the Fulfillment v5 document shows `villageId`, `cityId`, `regionId` and `addressLine1` in `receiverAddress`, but what a village is and when it is needed is unexplained (question 4 below), and the Lastmile v2 contract that
documents `nationalAddress` belongs to a different product. Torod Fulfillment orders never go through *Lastmile Create Shipment*.

M5AZN keeps receiving a city *name* in `shipping_info.city`; it now receives our canonical Arabic name when the order has a structured city, the typed text otherwise. Its `shipping_info.address` is the
same street-plus-district line Torod gets (an old copy has no district, so it reads exactly as before); the National Address is not sent to M5AZN either, until M5AZN confirms a field for it.

## Operating it on the pilot server (only *reads* from Torod: nothing is created, sent or changed there)

1. Deploy the files (no database change), then run the four address migrations by explicit `--path`, then `address:fetch-spl` and `address:import` (step 5) — see [deployment-runbook.md](deployment-runbook.md). A worker started before the migrations asks again
   and notices the new tables within 30 seconds (only a *yes* is remembered for the life of a process), so no restart is needed for that; restart the queue workers after the files of a release and after a rollback that drops the tables.
2. *Integration Center → Torod Point → City matching*: press **Sync cities now**. Read the banner: *Complete* or *Partial* (with the reason, the number of cities read and the provider's total if it
   gave one). Do not treat a *Partial* list as the provider's truth.
3. If partial, use *Find candidates at Torod Point* for the cities that held orders are waiting for.
4. For each waiting city compare the candidates side by side (**id, region, region check**), approve one, or *Match a city by hand* (choose by id and region, never by name alone). Order `100002`
   is released from the hold by the next sweep and then stops at the normal approval gate: the Torod **dry run stays on**, external writes stay off, and no request is made.
5. Re-check `address:status` (counts only, no personal data) and the matching screen's *Approved city matches* table.

Approved is **not** verified: whether the v2 city and region ids are accepted by the v5 *Add Order* endpoint is still unproven (see below), which is why the first real orders stay behind the approval gate.

## Questions for LogesTechs / Torod (none can be answered from the code)

1. **Paging of `GET /lastmile/v2/cities`** (or the endpoint actually used): the parameter names (`page`/`pageSize`/`size`), whether paging is zero- or one-based, and what `returnAll=true` returns (everything, or the first page?). What envelope keys state the total?
2. Is the list **scoped to the company** (`isSelected`, `isAddedByUser`)? Do orders only accept cities that are `isSelected`?
3. Are the **v2 city / region ids the same ids** Fulfillment v5 expects in `cityId` / `regionId`?
4. Is **`villageId`** ever required for a Saudi address, and which cities need it?
5. Which of the two **Riyadh** records (36189 / region 1204, 230194 / region 1265) is canonical for deliveries, and why are there two?
6. Does Fulfillment v5 accept the **National Address / short address** anywhere? (If yes, we will send it only after you confirm the field and the format.)
7. What are the **length limits** of `addressLine1` and the other address fields?
8. Is there a **regions endpoint**, so regions can be matched by id instead of by name?
9. Does a city record say **which country** it is in (the name of the field), or is the list Saudi Arabia only?

## Troubleshooting

| Symptom | Likely cause | What to do |
|---|---|---|
| Order held `no_mapping` | the city was never matched, or the last reading was partial | sync, then discover candidates, then approve |
| Order held `ambiguous:…` | two or more Torod records share the name | choose by id **and** region on the matching screen |
| Order held `provider_city_missing` | the approved city (or the Torod city an alias points at) disappeared from a *proven complete* reading | sync again; re-map to the city Torod lists now |
| Sync says *Partial* every time | paging not honoured | send question 1 to LogesTechs; use discovery meanwhile |
| Matching screen empty | migrations not run, or no city imported | `address:status`; run migrations / `address:fetch-spl` |
