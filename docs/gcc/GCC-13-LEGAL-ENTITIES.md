# GCC-13: the company that sells in each country (legal entities)

Requirement D1-03 of the master tracker. The approved rule is that each country has its own legal company, with its own data, gateway accounts, bank accounts, invoices and tax duties (plan section 5.1). Until now nothing in the code named that company: a single label, `gcc.storefronts.legal.legal_entity`, was copied onto legal-acceptance rows, and an invoice reads the shop settings of the day it is printed. This work adds the registry, the order stamp and the invoice header that reads the stamp. It **ships empty** and does nothing until it is switched on, and it **invents no company**: the names, tax numbers and e-invoicing duties of each country are facts the owner and counsel supply.

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
| `OrderInvoiceIssuer`, `partials/invoice/_issuer.blade.php` | The three invoices (admin, vendor and the customer's, on the web and in the app) print the issuer block of their header from the company frozen on the order: its name, address, tax registration number and commercial registration number, each only when it has one, instead of the shop's address and the business identity of the invoice settings. An order with no usable record prints what it always printed. See "The invoice" below |

## The two switches

- `GCC_LEGAL_ENTITIES` (`gcc.storefronts.legal_entities.enabled`, default `false`). While it is off, or while `GCC_STOREFRONTS_ENABLED` is off, nothing reads the registry and nothing is recorded: every order keeps being read as an order of the Saudi company in the platform's own settings, as every order was before.
- `GCC_LEGAL_ENTITIES_REQUIRED` (`gcc.storefronts.legal_entities.required_to_sell`, default `false`). Off, a missing company stops nothing. This is the owner's choice and nothing here makes it.

The company is stamped independently of `GCC_STOREFRONT_CURRENCY`: with the currency switch off, an order still records who sold it.

## The invoice

The header of an invoice has a right-hand block: the address of the shop and, when the invoice settings ask for it, one business identity line ("VAT : 3000…"). It was the same for every order, so an invoice of a Kuwaiti order carried the Saudi shop's address and the Saudi tax number. The block is now one partial, `resources/views/partials/invoice/_issuer.blade.php`, included by the admin, the vendor and the customer's invoice (the customer's is the one the web and the app download), and it asks `OrderInvoiceIssuer::lines($order)`:

- **An order with a usable frozen company** prints that company: its legal name, its address, its tax registration number and its commercial registration number, each line only when the company has the fact. Nothing of the shop's own address or business identity is printed beside another company, and a company with no tax number prints none (Kuwait has none to print). The labels are the language keys `tax_registration_number` and `commercial_registration_number`, with an Arabic text in `sa` and `ae`. Values are escaped like every other value of the page.
- **Every other order** prints the block it always printed, markup unchanged: an order with no record (every order placed before the registry), a record that is not JSON, not an object, or that carries nothing printable, and every order while `GCC_LEGAL_ENTITIES` or `GCC_STOREFRONTS_ENABLED` is off. The last is the way back: switching legal entities off restores the old invoice even for an order that was stamped, and the record stays on the order.
- The invoice reads only the order's own copy. A later rename or a new tax number of the company changes the invoices of later orders, never of an order already placed, and it does not depend on which storefront the person printing it is served from.

Not changed on purpose: the logo, the terms and conditions, the seller's GST line of a vendor order, and the POS receipts (a narrow receipt that prints the shop name and does not print a tax number today). No invoice number is generated: the invoice still carries the order id. A number for each company (the series key is already registered and frozen on the order) needs the owner's rule for the format, the reset and the gaps, and, in some countries, a tax authority's own sequence (see the open decisions).

## Step 3: the registry reaches products and the seller ledger

The registry of step 1 had two readers, the order stamp and the invoice. Step 3 gives it the two it was blocking, both behind the same switch.

| Piece | What it is |
|---|---|
| `products.legal_entity_id` | The company that sells a product: the company half of a product's selling scope, which `StorefrontCatalog::sellingScopeOf()` already read with no migration anywhere for it. Additive migration `2026_11_27_100200_add_legal_entity_to_products_table.php`, nullable, no index (an index on the country column beside it was measured on 200,000 products and made the selective seller and category lists 5 to 15 times slower); its rollback is refused while a product names a company |
| `LegalEntityRegistry::forProduct($row, $country)` | The company that sells a product in a storefront: the one the product names, the country's for a product that names none. It reads an Eloquent `Product` and the plain products row of the query builder the same way, and answers null rather than throwing while the switch is off, before the migrations, with an empty registry, for a country with no active company, and when the company a product names is no longer active. A product is never quietly sold by a company it does not name, and a missing company never stops a sale |
| `LegalEntityRegistry::newProductAttributes($country)` | What a new product is saved with, read by `ProductService::getAddProductData` beside the country attributes, so the admin chooses the country once and the company follows. A company named in the request is ignored, and a seller product carries none because it carries no country |
| `_storefront-country.blade.php` | The company shown read only beside the country picker of the admin product form, in the same partial and the same shape as the country field. Nothing of it is drawn while the switch is off or while the country has no company |
| `vendor_ledger_entries.legal_entity_id` | The company a seller's money was earned, charged or paid under. Additive migration `2026_11_27_100300_add_legal_entity_to_vendor_ledger_entries_table.php`, nullable, no index, and it does **nothing at all while the table is absent**: `vendor_ledger_entries` has no migration in this repository (the live table came from the installation dump, which is not in the tree), so the migration makes no claim about the live shape and alters no existing column. An index on a large live money table is the owner's to approve. Its rollback is refused while an entry names a company |
| `VendorLedger::record(..., $legalEntityId)` | The company of a ledger row, recorded only while legal entities are on and the ledger has the column. `VendorLedger::recordsCompanies()` is the single gate and it asks the config switch **before** it reads any schema, so the live order, refund and payout paths make no extra query |

### Which company each writer of the ledger carries

Every one of the eight callers of `VendorLedger::record()` passed no company, so `legal_entity_id` was null on every row the running system wrote (the stated blocker on E-17.2 of the master tracker). Each now carries one, by the rule it can honour:

| Writer | What it carries |
|---|---|
| `OrderManager` the order earning, and the commission charged on it | the **order's own frozen stamp** (`orders.legal_entity_id`), read once for both, so a registry that changes later never reaches money already recorded and the pair can never land in two different companies. The stamp has to exist when it is read, and it did not: `generateOrder` stamped the order *after* `addOrderDetailsData` had already written the money, so until this was corrected every ledger row of a running system named no company whatever the registry held. `addOrderDetailsData` now saves every line, stamps the order once, and only then records the money |
| `RefundReversalService` the refund, and the commission given back | the company of the **earning they reverse**, read off that entry, and **none** when the line has no earning on the ledger at all. An earning that names none is reversed by entries that name none, so a reversal can never take one company negative while leaving the earning's over-credited; a line with no earning to agree with names no company rather than the order's stamp, for the same reason |
| `PayoutService` the payout reserve, and the legacy withdraw mirror of `VendorController` | the one company of the rows they draw on, and **none** unless every row of that seller's ledger names that same one: a debit drawn on a pool that is not all one company's would otherwise be labelled with one of them. A missing company never blocks the write |
| `PayoutService` money back after a failed transfer, and a released reservation | the company of the reservation they reverse |

Whether a payout should instead be **drawn per company**, rather than on the seller's balance as a whole, is an owner decision that is still open; until it is taken, a reserve drawn on a mixed pool names no company rather than guessing one.

## Before it is switched on (staging only)

1. Run the two migrations (they add one table and two nullable columns; check `@@version` and rehearse on a copy).
2. Register the companies with `php artisan gcc:legal-entity:upsert KW --legal-name="..." --tax-number="..." ...` for each country that will sell, from the facts the owner supplies.
3. Run `php artisan gcc:legal-entity:list` (exit 0 means every storefront that is switched on has its company).
4. Set `GCC_LEGAL_ENTITIES=true`, clear the config cache. Decide separately whether to set `GCC_LEGAL_ENTITIES_REQUIRED=true`.

Going back: switch it off and clear the config cache. Orders already stamped keep their record, and the columns can only be dropped while no order carries one.

## What is not in this slice

- **Invoice numbers per company and the invoice's currency.** The invoice prints the frozen company (see above) but is still numbered by the order id, and the currency it prints is the one it always printed. A series for each company and an e-invoicing hand-off (ZATCA for the Saudi company, the other countries' systems) are separate slices that need the owner's rules.
- **POS receipts** still print the shop's name and address.
- **No per-company accounts**: gateway accounts, bank accounts, the customer wallet and loyalty ledgers, settlements and the Odoo company are still global (GCC-15, GCC-17 and the accounting integration). The **seller** ledger is the exception since step 3: every row it writes names the company the money belongs to. The customer wallet cannot name one at all yet — `users.wallet_balance` and `wallet_transactions` are one pot with no market, company or currency column, so there is nowhere to record it without building a wallet system first.
- **No staff or report scope by company**, and the Super Admin money view has nothing to group by yet.
- **No screen for the registry.** It is filled by the command; an admin page, or an endpoint of the platform admin API, can follow. The admin **product** form does show the company that sells a product, read only, since step 3.
- The label `gcc.storefronts.legal.legal_entity` recorded on legal acceptances is not replaced by the registry yet.
- **No backfill.** `products.legal_entity_id` and `vendor_ledger_entries.legal_entity_id` ship null on every row that exists, and every reader treats null as "names no company": a product that names none is sold by its country's company, and a ledger row that names none is part of the one ledger the platform has always kept.
- `entity_snapshot` is stamped by `OrderStorefrontSnapshot`, which `addOrderDetailsData` runs once the order's lines are saved and before its money is recorded. A path that creates an order without going through it carries no company; the seller app's POS writes its own lines and calls the stamp itself, and it writes no seller-ledger row.

## Owner decisions open

1. The companies themselves: legal name, tax and commercial registration, the invoice series, the e-invoicing duties of each country, and whether the first launch has exactly one company for each country (the plan says so; the table allows one).
2. Whether a storefront without a company may sell (`GCC_LEGAL_ENTITIES_REQUIRED`).
3. Whether the Saudi default storefront gets a company row now (it is stamped only if one is registered) or when the first other country opens.
4. The invoice number of each company: the format, whether it restarts each year, whether gaps are allowed, and whether a tax authority issues or approves it. Until this is decided every invoice is numbered by its order id, as before.
5. Whether the tax registration label should differ by country ("VAT", "TRN", "Tax ID"). The invoice prints one neutral label, `Tax registration number`, for every company.

## Tests

`tests/Feature/Marketplace`: `VendorLedgerLegalEntityTest` (25) covers the ledger's two migrations, every writer with the switch off and on, the refund rules and the two ledger readers, each by calling the writer directly with the world already in the state it reads. `OrderCompanyOnTheLivePathTest` (6) places real orders through `OrderManager::generateOrder` and reads the ledger afterwards — the earning and its commission, a refund of that order, the company a payout would be drawn on, an order of two countries, an empty registry, and the switch off — because the **order** of the steps is what was wrong and no test that starts from a stamped order can see it. Both run on SQLite and on MySQL 8 and MariaDB 11.4 in CI.

`tests/Feature/Storefront`: `LegalEntityRegistryTest` (20: each country finds its own company, inactive and missing ones, the switches, the unique country, the frozen identity, flush, the requirement, the report, both migrations and their refused rollbacks), `OrderLegalEntitySnapshotTest` (stamping for each of the six storefronts, non-ASCII names, written once, a later change never rewrites it, the switches, a country without a company, a mixed order, before either migration, with and without the currency switch), `LegalEntityCommandsTest` (registering, partial updates, clearing, deactivating, every refusal, the list and its exit codes) and `LegalEntitySellingGateTest`; `OrderInvoiceIssuerTest` (26: an order with no usable record, ten shapes of it; each of the six companies printed from the order's own copy; only the facts a company has; trimming; an order as an array, an object, a model-like object and an already decoded record; a later change of the registry never reaching a stamped order; both switches; the partial's markup with and without a record, escaping) and, in the Address directory that already renders the real templates, three end-to-end cases each for the admin and vendor invoice (`PanelInvoiceTest`) and the customer's invoice (`StorefrontOrderPagesTest`): no record keeps the old line, a frozen company replaces it, legal entities off restores it. They run on SQLite and, because they sit in the Storefront directory, on MySQL 8 and MariaDB 11.4 in CI.
