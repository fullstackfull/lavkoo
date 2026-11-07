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
2. **An old order has only typed text.** The resolver tries, in this order: an administrator's Torod alias → the address system's own `LegacyCityMatcher` (unique and region-consistent, otherwise
   *no match*) → the mapping of the city that matcher found. The region the customer typed is only a filter; it is never a second guess at the city.
3. **No Saudi catalogue or no mappings table yet** (code shipped, migrations not run): the resolver behaves exactly as before (alias, then one exact name in the cached list).

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
  *contains* or *resembles* our name is not a candidate.
* Each candidate carries a **region check**: `confirmed` (Torod's region name matches our region), `unknown` (Torod gave no region) or `unplaceable` (a region name we cannot map to one of ours). A candidate
  whose region names *another* of our regions is dropped.
* Candidates are stored as `pending` mapping rows with their evidence (names, ids, region check, sync run). **Nothing is approved without a person**, except the automatic rule below.
* `Approve` records the administrator's id and name, the evidence and a timestamp, and writes an audit entry. The database allows **one approved mapping per provider and city**
  (`approved_slot` unique index); approving a second candidate is refused unless the screen sends the id of the mapping it replaces (`replacing`), so a stale browser tab can never overwrite a newer decision.
  `Reject` and `Retire` keep the row for the audit trail.
* A mapping can only point at a city the provider **currently lists**; the Torod ids of one provider are never accepted for another.

### The one automatic rule

After a **complete** city reading, our active city with exactly **one** exact candidate that no other of our cities also claims, whose region is not contradicted, and that nobody has decided on
before, is approved automatically as `auto_unique` (one summary audit entry per run). A partial reading never approves anything, and a reading whose completeness rests only on the weakest evidence
(see below) only *proposes*.

## The city list sync is completeness-aware

`fulfillment:sync --only=cities` (and the Integration Center button) calls `TorodCityDirectory::sync()`:

1. one request with `returnAll=true`;
2. when that response does not settle it, the pages are walked (`page`, `pageSize=100`) for as long as each page adds cities not seen yet — up to the configured ceilings
   (`fulfillment.providers.torod_point.cities.max_pages`, `.max_seconds`, `.complete_floor`; defaults 100 pages, 45 s on the web / 600 s on the console, 1000 cities);
3. everything is read first and written afterwards, in one transaction, in chunks. Cities are **added or updated, never deleted**.

The verdict is recorded in `provider_city_sync_runs` and on the provider's sync state:

| Verdict | Evidence | Effect |
|---|---|---|
| `full` | the provider's own total was reached, or the page walk ended on an empty page and contained the first response, or the response flags its last page | cities the provider no longer lists get `missing_since`; the unique-match rule may run |
| `full` (weakest) | at least 1000 distinct cities and nothing says more exist | same as above for display, but **no automatic approval** |
| `partial` | anything else (for example *10 returned, paging not honoured*) | cities are added/updated only; **no** `missing_since`, **no** success stamp, last error `partial: <reason>`, `fulfillment:sync` exits non-zero and prints `PARTIAL` |
| `failed` | the first request failed | nothing changes |

Because the paging behaviour of the Lastmile list is **not documented**, the code is written to be right under any behaviour and to say `partial` when it cannot tell.

## Finding a city's candidates when the list is partial

`php artisan address:discover-provider-cities torod_point --city=<our city id> [--city=…] [--held] [--apply]` (also the *Find candidates at Torod Point* button on the matching screen) asks Torod
for the cities named like ours with the one filter the documentation promises (`search`), caches what it finds and proposes the exact-name ones. It is a **read** (`lastmile_cities`), a dry run by
default, skips when the integration is switched off or not migrated, and only ever *proposes*: a discovery result is never "unique at Torod", because the search itself may be paged.

## What reaches Torod in the order payload

`TorodOrderPayloadBuilder` sends `cityId` and `regionId` (the provider's own ids from the approved mapping) and `addressLine1` = street text plus the district (unless the street already names it).
It does **not** send the National Address and does **not** send `villageId`: the Fulfillment v5 documentation lists `cityId`, `regionId` and `addressLine1` only, and the Lastmile v2 contract that
documents `nationalAddress` belongs to a different product. Torod Fulfillment orders never go through *Lastmile Create Shipment*.

M5AZN keeps receiving a city *name* in `shipping_info.city`; it now receives our canonical Arabic name when the order has a structured city, the typed text otherwise.

## Operating it on the pilot server (nothing below sends anything to Torod)

1. Deploy the files (no database change), then run the four address migrations by explicit `--path`, then `address:import` — see [deployment-runbook.md](deployment-runbook.md). **Restart the queue workers**
   afterwards: the "migrated" answer is remembered for the life of a process.
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

## Troubleshooting

| Symptom | Likely cause | What to do |
|---|---|---|
| Order held `no_mapping` | the city was never matched, or the last reading was partial | sync, then discover candidates, then approve |
| Order held `ambiguous:…` | two or more Torod records share the name | choose by id **and** region on the matching screen |
| Order held `provider_city_missing` | the approved city disappeared from a *complete* reading | sync again; re-map to the city Torod lists now |
| Sync says *Partial* every time | paging not honoured | send question 1 to LogesTechs; use discovery meanwhile |
| Matching screen empty | migrations not run, or no city imported | `address:status`; run migrations / `address:import` |
