# GCC-13: the company that sells in each country (legal entities)

Requirement D1-03 of the master tracker. The approved rule is that each country has its own legal company, with its own data, gateway accounts, bank accounts, invoices and tax duties (plan section 5.1). Until now nothing in the code named that company: a single label, `gcc.storefronts.legal.legal_entity`, was copied onto legal-acceptance rows, and an invoice reads the shop settings of the day it is printed. This slice adds the registry and the order stamp. It **ships empty** and does nothing until it is switched on, and it **invents no company**: the names, tax numbers and e-invoicing duties of each country are facts the owner and counsel supply.

## What it adds

| Piece | What it is |
|---|---|
| `legal_entities` | One row for each country (`country` is unique, compared byte for byte on MySQL and MariaDB): the legal name, a trade name, the tax and commercial registration numbers, the key of the invoice series, an address and an active flag. Additive migration `2026_11_27_100000_create_legal_entities_table.php`; its rollback is refused while it holds a company |
| `orders.legal_entity_id`, `orders.entity_snapshot` | The id of the company and a frozen JSON copy of its identity, both nullable and appended without a position (an instant add on MySQL 8 and MariaDB 11.4), no index. Migration `2026_11_27_100100_add_legal_entity_to_orders_table.php`; its rollback is refused while any order carries a company |
| `LegalEntityRegistry` | `forCountry($country)` is the active company of a country, or null while the switch is off, storefronts are off, the table is not migrated, or the country has none. It remembers an answer for thirty seconds, so a queue worker notices a change without a restart |
| `OrderLegalEntitySnapshot` | Asked from `OrderStorefrontSnapshot`, which already finds the storefront from the lines an order was saved with, so the web and API checkout, both POS screens and the seller app's POS stamp the same thing. Written once: a later change of the company never rewrites an order, and an order of more than one country gets none |
| `gcc:legal-entity:upsert` | Registers or changes the company of one country. It takes only the facts it is given and writes the registry only |
| `gcc:legal-entity:list` | Read-only report of every configured country, with an exit code that is 1 while a storefront that is switched on (other than the default one) has no active company. `--json` prints the rows |
| `StorefrontSellingGate` | With `GCC_LEGAL_ENTITIES_REQUIRED` on, a storefront without an active company may not sell. The default storefront is never refused for lack of one |

## The two switches

- `GCC_LEGAL_ENTITIES` (`gcc.storefronts.legal_entities.enabled`, default `false`). While it is off, or while `GCC_STOREFRONTS_ENABLED` is off, nothing reads the registry and nothing is recorded: every order keeps being read as an order of the Saudi company in the platform's own settings, as every order was before.
- `GCC_LEGAL_ENTITIES_REQUIRED` (`gcc.storefronts.legal_entities.required_to_sell`, default `false`). Off, a missing company stops nothing. This is the owner's choice and nothing here makes it.

The company is stamped independently of `GCC_STOREFRONT_CURRENCY`: with the currency switch off, an order still records who sold it.

## Before it is switched on (staging only)

1. Run the two migrations (they add one table and two nullable columns; check `@@version` and rehearse on a copy).
2. Register the companies with `php artisan gcc:legal-entity:upsert KW --legal-name="..." --tax-number="..." ...` for each country that will sell, from the facts the owner supplies.
3. Run `php artisan gcc:legal-entity:list` (exit 0 means every storefront that is switched on has its company).
4. Set `GCC_LEGAL_ENTITIES=true`, clear the config cache. Decide separately whether to set `GCC_LEGAL_ENTITIES_REQUIRED=true`.

Going back: switch it off and clear the config cache. Orders already stamped keep their record, and the columns can only be dropped while no order carries one.

## What is not in this slice

- **The invoice does not read the snapshot.** It still reads the shop settings at print time. Making it print the order's frozen identity, the tax number and the currency, numbered per company, is the next slice (a change to the invoice views, in the two themes' own structure).
- **No per-company accounts**: gateway accounts, bank accounts, the wallet and loyalty ledgers, settlements and the Odoo company are still global (GCC-15, GCC-17 and the accounting integration).
- **No staff or report scope by company**, and the Super Admin money view has nothing to group by yet.
- **No screen.** The registry is filled by the command; an admin page, or an endpoint of the platform admin API, can follow.
- The label `gcc.storefronts.legal.legal_entity` recorded on legal acceptances is not replaced by the registry yet.
- `entity_snapshot` is stamped by `OrderStorefrontSnapshot`, which runs after the order's lines are saved. A path that creates an order without going through it (none is known today) would carry no company.

## Owner decisions open

1. The companies themselves: legal name, tax and commercial registration, the invoice series, the e-invoicing duties of each country, and whether the first launch has exactly one company for each country (the plan says so; the table allows one).
2. Whether a storefront without a company may sell (`GCC_LEGAL_ENTITIES_REQUIRED`).
3. Whether the Saudi default storefront gets a company row now (it is stamped only if one is registered) or when the first other country opens.

## Tests

`tests/Feature/Storefront`: `LegalEntityRegistryTest` (20: each country finds its own company, inactive and missing ones, the switches, the unique country, the frozen identity, flush, the requirement, the report, both migrations and their refused rollbacks), `OrderLegalEntitySnapshotTest` (stamping for each of the six storefronts, non-ASCII names, written once, a later change never rewrites it, the switches, a country without a company, a mixed order, before either migration, with and without the currency switch), `LegalEntityCommandsTest` (registering, partial updates, clearing, deactivating, every refusal, the list and its exit codes) and `LegalEntitySellingGateTest`. They run on SQLite and, because they sit in the Storefront directory, on MySQL 8 and MariaDB 11.4 in CI.
