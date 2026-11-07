# Unified Saudi address

One address system for the whole store: customers pick a **region** and a **city** from the store's own list and give their **National Address**; every courier, warehouse or marketplace is an integration *linked to that list*,
never a second list of its own. It extends what the store already had (`shipping_addresses`, the order's address copy, the checkout, the account book, the panels, the REST API, the Torod Point integration) instead of replacing it.

| Read this | When |
|---|---|
| [design.md](design.md) | what was found, the tables, the services, the modes, the flows |
| [deployment-runbook.md](deployment-runbook.md) | **before touching production**: the two releases, the four migrations, import, switching on |
| [legacy-and-rollback.md](legacy-and-rollback.md) | what happens to old addresses and orders; how to go back without losing data |
| [torod-city-mapping.md](torod-city-mapping.md) | order `100002`, the two Riyadh records, the 10-city sync, approvals, questions for LogesTechs |
| [zip-compatibility.md](zip-compatibility.md) | every use of `zip`, the rules, the staged path for ZIP-based delivery rules |
| [national-address.md](national-address.md) | the National Address format and what is (not) verified |
| [data-sources.md](data-sources.md) | where the region and city data come from, its coverage and licence |
| [test-report.md](test-report.md) | what was tested, how, on which engines, and what was not run |

## How it behaves, in six lines

1. **`off` (the default)**: the old forms, the old validation and the old data model for everyone. The code ships dormant; the tables do not exist until the migrations are run on purpose. A short list of fixes and hardening applies in every mode, listed below.
2. **`soft`**: the website and the panels must use region / city / district / National Address for Saudi addresses; the mobile app may keep sending the old fields (its orders are accepted; at fulfillment the typed city is used only when it is certain, otherwise a person looks at the order first).
3. **`strict`**: every client must send the structured address.
4. An order keeps an **immutable copy** of its address (all old keys, `zip` included, plus the ids and the names of the region and city that day).
5. The provider adapter turns *our city id* into *the provider's city id* through an **approved mapping**; no mapping, a pending one or several candidates ⇒ the shipment is **held** with a reviewable reason, never sent, never guessed.
6. The ZIP column is untouched; it is never derived from the National Address; while a ZIP-code delivery restriction is on, the structured address is paused and the store behaves as before.

## What changes even while the mode is `off`

Everything that depends on the structured address is dormant, and the pages, API responses and order copies of an unmigrated or `off` store are the ones it had (checked by rendering the old and the new templates with the same data). These changes are **not** behind the mode, because they close holes or keep the data model coherent; they are the things to look at in the Release A smoke test:

| # | Change | Why |
|---|---|---|
| 1 | every address id that arrives in a request is resolved among the caller's **own** addresses (checkout, account book, REST, payment, seller/POS); the checkout page lists only the signed-in customer's saved addresses (a guest sees none). For a **guest** of the mobile API, "own" means the `guest_id` the device sends, which is not a secret: see decision 9 | IDOR: ids used to be trusted |
| 2 | the legacy `customer/choose-shipping-address` route checks the session token and behaves like `-other` (same service, same rules) | it was CSRF-exempt and applied different rules |
| 3 | the courier (delivery-man) app is served the **order's copy** of the address when the order has one, not the live saved row | the copy is what the order was placed with; a staff edit now reaches the courier too |
| 4 | the POS default delivery address is the customer's newest non-billing address (none for a walk-in customer) | it used to take the highest id of *any* row, including other shoppers' anonymous checkout addresses |
| 5 | admin, vendor and seller-app order-address edits: length limits (the production database truncates silently), an audit entry (who, which order, which fields, never what), only the fields that were sent change, and no edit once a provider holds the shipment | data integrity and the provider's delivery address |
| 6 | REST and web address updates change only what was sent and no longer rewrite `created_at` | an edit used to move the address to the top of the list |
| 7 | the four POS add-customer forms name the ZIP select `zip_code` when the ZIP restriction is on | a chosen ZIP was silently dropped |
| 8 | guest order tracking compares the phone with the order's own copy first and refuses a blank phone | the saved address may have changed or been removed since the order |
| 9 | the four account-address actions require a signed-in customer | they were reachable without one |
| 10 | after the migrations, address JSON (list API, order copies) carries four more keys (null) and `GET /api/v1/config` carries `unified_address` | additive |
| 11 | a Torod order with no approved mapping is matched by an administrator's alias or one exact name in the cached list, **never** by the address's state text | the state used to be tried as a second city, which is a guess |
| 12 | with the mode off, an old-form edit that moves an address which already has a region and city (matched by the backfill, or made while the mode was on) to another city or country clears those ids with it | the ids must never name a place the address no longer says |
| 13 | guest order tracking (the website and `POST /api/v1/order/track-order`) accepts only a phone given as **text** and compares it as a number (`+966…` and `05…` are one number) | a JSON `true` or a bare number is "equal" to any phone in PHP's loose comparison and opened any guest order |
| 14 | the courier app's `order-item` returns only an order assigned to that courier | any courier could read any order by its number |
| 15 | the seller app's order-address edit goes through the same service as the panels (field limits, audit entry, no edit once a provider holds the shipment) | it had none of the three |
| 16 | `/address/*` (the region and city typeahead) is not remembered as the page to return to after signing in | every keystroke replaced it with a JSON URL |
| 17 | M5AZN's `shipping_info.address` is the street followed by the district (the line Torod gets); an old copy reads exactly as before | a structured order would otherwise tell M5AZN less than a typed one |
| 18 | a Torod city alias is audited, must name a city Torod lists, cannot be empty, and holds the order once Torod stops listing that city | the alias path skipped the checks the approved mapping has |

## What needs a decision from the owner or from LogesTechs / Torod

| # | Decision / confirmation | Why it matters | Where |
|---|---|---|---|
| 1 | **Data licence** of the bundled city list (a GPL-2.0 snapshot of the National Address Directory) | it must be decided before Release A is applied (the file is part of the package) and before the import is run; an alternative source or a hand-made list can be loaded in the same format | [data-sources.md](data-sources.md) |
| 2 | Paging and `returnAll` behaviour of Torod's cities list; company scoping (`isSelected`, `isAddedByUser`) | decides whether a sync can be *complete*; until then it is reported *Partial* and nothing is approved automatically | [torod-city-mapping.md](torod-city-mapping.md) |
| 3 | Are the **v2 city / region ids** the ones Fulfillment **v5** expects in `cityId` / `regionId`? Is `villageId` ever needed? | "approved" is not "verified"; the first real orders stay behind the approval gate | same |
| 4 | Which **Riyadh** is canonical (36189 / region 1204 or 230194 / region 1265)? | a person approves one by id and region; nothing is chosen for them | same |
| 5 | Does Fulfillment v5 accept the **National Address** anywhere, and what are the `addressLine1` limits? | today the National Address is stored and shown but **not sent** to Torod | same |
| 6 | ZIP-based **delivery rules**: replace by region/city rules (stage S2) or keep the ZIP list and pause the feature while it is on | the store cannot use both at once | [zip-compatibility.md](zip-compatibility.md) |
| 7 | Should the structured address also be used for **auction claim** checkouts and **externally imported** orders? | the module's backend is not in this repository; no external-order importer writes an address yet | [deployment-runbook.md](deployment-runbook.md) |
| 8 | When to move from `soft` to `strict` | needs every mobile app version in use to send the structured fields | [deployment-runbook.md](deployment-runbook.md) |
| 9 | **Guest identity in the mobile API.** A guest's saved addresses are keyed by `guest_id`, a sequential number `GET /api/v1/get-guest-id` hands out and the app sends back with no secret. Anyone who guesses it can list, edit or delete that guest's addresses (now including the region, city, district and National Address), and a signed-in customer who names it in a request can order to one of them. Pre-existing; everything else here is scoped to the caller. The fix is a per-guest secret issued by `get-guest-id` and required on every guest route, which needs a mobile release | the one hole this branch cannot close server-side alone | [design.md](design.md) |
| 10 | `account-address-delete` deletes on a plain **GET** (the three themes' scripts link to it). Pre-existing; the action is scoped to the signed-in customer's own addresses | a link on another site can delete a saved address; making it a POST means touching the themes' scripts | — |

## Running the tests

```bash
vendor/bin/phpunit tests/Feature/Address            # the address work on SQLite (770+ tests)
vendor/bin/phpunit                                  # everything
# the same address tests on a real MySQL / MariaDB (the CI "MySQL" job does this on MySQL 8):
EXPECT_DB_DRIVER=mysql DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=pharmacy_testing DB_USERNAME=root DB_PASSWORD=… \
  vendor/bin/phpunit -c phpunit.mysql.xml tests/Feature/Address
```

The test databases must have a name that contains `test` (the suite refuses anything else). No test sends a request to any provider (`Http::preventStrayRequests()` / `Http::fake()`).
