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

1. **`off` (the default)**: nothing changes for anyone. The code ships dormant; the tables do not exist until the migrations are run on purpose.
2. **`soft`**: the website and the panels must use region / city / district / National Address for Saudi addresses; the mobile app may keep sending the old fields (its orders wait for review at fulfillment instead of failing).
3. **`strict`**: every client must send the structured address.
4. An order keeps an **immutable copy** of its address (all old keys, `zip` included, plus the ids and the names of the region and city that day).
5. The provider adapter turns *our city id* into *the provider's city id* through an **approved mapping**; no mapping, a pending one or several candidates ⇒ the shipment is **held** with a reviewable reason, never sent, never guessed.
6. The ZIP column is untouched; it is never derived from the National Address; while a ZIP-code delivery restriction is on, the structured address is paused and the store behaves as before.

## What needs a decision from the owner or from LogesTechs / Torod

| # | Decision / confirmation | Why it matters | Where |
|---|---|---|---|
| 1 | **Data licence** of the bundled city list (a GPL-2.0 snapshot of the National Address Directory) | it must be decided before the import is run on production; an alternative source or a hand-made list can be loaded in the same format | [data-sources.md](data-sources.md) |
| 2 | Paging and `returnAll` behaviour of Torod's cities list; company scoping (`isSelected`, `isAddedByUser`) | decides whether a sync can be *complete*; until then it is reported *Partial* and nothing is approved automatically | [torod-city-mapping.md](torod-city-mapping.md) |
| 3 | Are the **v2 city / region ids** the ones Fulfillment **v5** expects in `cityId` / `regionId`? Is `villageId` ever needed? | "approved" is not "verified"; the first real orders stay behind the approval gate | same |
| 4 | Which **Riyadh** is canonical (36189 / region 1204 or 230194 / region 1265)? | a person approves one by id and region; nothing is chosen for them | same |
| 5 | Does Fulfillment v5 accept the **National Address** anywhere, and what are the `addressLine1` limits? | today the National Address is stored and shown but **not sent** to Torod | same |
| 6 | ZIP-based **delivery rules**: replace by region/city rules (stage S2) or keep the ZIP list and pause the feature while it is on | the store cannot use both at once | [zip-compatibility.md](zip-compatibility.md) |
| 7 | Should the structured address also be used for **auction claim** checkouts and **externally imported** orders? | the module's backend is not in this repository; no external-order importer writes an address yet | [deployment-runbook.md](deployment-runbook.md) |
| 8 | When to move from `soft` to `strict` | needs every mobile app version in use to send the structured fields | [deployment-runbook.md](deployment-runbook.md) |

## Running the tests

```bash
vendor/bin/phpunit tests/Feature/Address            # the address work on SQLite (770+ tests)
vendor/bin/phpunit                                  # everything
# the same address tests on a real MySQL / MariaDB (the CI "MySQL" job does this on MySQL 8):
EXPECT_DB_DRIVER=mysql DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=pharmacy_testing DB_USERNAME=root DB_PASSWORD=… \
  vendor/bin/phpunit -c phpunit.mysql.xml tests/Feature/Address
```

The test databases must have a name that contains `test` (the suite refuses anything else). No test sends a request to any provider (`Http::preventStrayRequests()` / `Http::fake()`).
