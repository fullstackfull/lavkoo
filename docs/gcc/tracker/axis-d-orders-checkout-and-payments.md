# Axis D: Orders, checkout and payments

Detail cards of the master tracker ([`MASTER_IMPLEMENTATION_TRACKER.md`](../MASTER_IMPLEMENTATION_TRACKER.md)), as of 2026-11-18. Requirements: 37. Acceptance criteria: 173 (MET 82, CODED 30, MISSING 61). Code completion 65%, proven by a passing test 47%, release readiness 8%.

Independent verification: done for unit(s) D1, D2.

Every card gives the ten fields of the directive: (1) id and name, (2) functional requirement, (3) what exists in main, (4) what the draft pull requests did, (5) what remains, (6) pull requests and commits, (7) tests that exist and where they ran, (8) dependencies and blockers, (9) acceptance criteria marked MET (a named passing test or CI job on a named head), CODED (code exists, no passing test cited) or MISSING, (10) the next coding task.

#### D1-01. One storefront per checkout and per order

- **State: IN PROGRESS** | plan lines 10, 281 | Provisional single-store checkout (DECISIONS.md); GCC-13 separate carts and orders per storefront | reviews: finance, owner-decision
- **(2) Functional requirement.** Every checkout runs in one storefront and every order belongs to exactly one store, recorded on the order so later operations, reports and staff scopes read it instead of guessing.
- **(3) Exists now in code (main).** main: no storefront in carts or orders; OrderManager::getOrderAddData writes seller_id and seller_is only. generateOrder has seven call sites (web, API v1, payment hook); admin, vendor and seller-app POS and the marketplace dispatch write orders on their own.
- **(4) Done in the draft PRs.** Storefront chain in PR 83 (#8, #28, #47, #48, #53): a request is bound to a storefront (X-Storefront, ?storefront=, path), a product belongs to one country, a cart is narrowed per storefront, a payment finishes in the storefront it started in; in PR 83 orders carry no country (#77 derives it from their lines). On branch origin/gcc/p1-storefront-currency (GCC-05 slices 1 to 3, stacked on #64, no pull request, never run in CI): migration 2026_11_26_100000 adds nullable orders.storefront_country, currency_code and currency_rate; OrderStorefrontSnapshot::record, called by OrderManager::generateOrder and the seller-app POS after the lines are saved, stamps them from the order's lines, only while GCC_STOREFRONT_CURRENCY and the storefront flag are on, leaves them null for an order whose lines belong to two countries, no Saudi backfill; admin POS, vendor POS (OrderRepository::add) and ChannelOrderMaterializer are not stamped. All default off.
- **(5) What remains.** 1) nullable orders.storefront_country stamped in getOrderAddData, Saudi backfill after reconciliation; 2) stamp or documented exemption for the three POS paths, the marketplace materializer and the auction claim; 3) order-line product JSON omits the country (Product::$hidden); 4) tree that holds security and GCC sets together.
- **(6) PRs and commits.** [lavkoo#8](https://github.com/fullstackfull/lavkoo/pull/8) `a8c6a42`; [lavkoo#28](https://github.com/fullstackfull/lavkoo/pull/28) `8c8d32a`; [lavkoo#47](https://github.com/fullstackfull/lavkoo/pull/47) `e0dd5dc`; [lavkoo#48](https://github.com/fullstackfull/lavkoo/pull/48) `4a821d3`; [lavkoo#53](https://github.com/fullstackfull/lavkoo/pull/53) `7e32bba`; [lavkoo#77](https://github.com/fullstackfull/lavkoo/pull/77) `81a2836`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`; [lavkooapp#1](https://github.com/fullstackfull/lavkooapp/pull/1) `2a8c84c`
- **(7) Tests.**
  - `ci` StorefrontCartIsolationTest: API cash-on-delivery checkout in one storefront orders only that storefront's rows (PR 83 8ff3410: Tests, SQLite (PHP 8.2/8.3); Storefront tests, MySQL 8 and MariaDB 11.4.2)
  - `ci` StorefrontPaymentCallbackTest and PaymentStorefrontTest: a paid Kuwait cart becomes a Kuwait order although the callback names no storefront (PR 83 8ff3410: same jobs)
  - `none` A test that an order row records its storefront (refuted by the verifier: The column and tests exist on origin/gcc/p1-storefront-currency: OrderStorefrontSnapshotTest and StorefrontCheckoutSnapshotTest assert orders.storefront_country)
- **(8) Dependencies and blockers.** Flag off by default. The security set (PR 75) and the GCC chain (PR 83) are combined on origin/gcc/release/security-gcc-integration-ci (88d63c22, tip f6fb03af), but there is no pull request or CI result for it in ci-table.md, so cart, checkout and payment-hook files touched by both sets (CartManager, WebController, module-helper) cannot yet be called integrated and tested. Owner decision on POS.
- **(9) Acceptance criteria.**
  - [MET] A checkout in storefront X places an order only for the cart rows of X; the rows of another country stay in the cart (StorefrontCartIsolationTest, PR 83 8ff3410, Tests SQLite and Storefront tests MySQL 8 / MariaDB)
  - [MET] A payment started in storefront X builds its order in X although the gateway callback names no storefront (StorefrontPaymentCallbackTest, PR 83 8ff3410, same jobs)
  - [MET] With storefronts off, checkout and payment behave as the Saudi baseline (nothing narrowed, same SQL) (StorefrontCartIsolationTest and StorefrontPaymentCallbackTest while-off cases, PR 83 8ff3410)
  - [CODED] Every order row records the storefront (country) it was placed in (orders has no such column; StaffOrderVisibility derives it from lines)
  - [MISSING] Orders written outside the storefront (admin, vendor, seller-app POS, marketplace dispatch, auction claim) get a storefront or an explicit exemption (STOREFRONT_FLAG_RUNBOOK known limits; PANEL_PATHS are never narrowed)
- **(10) Next coding task.** Open the pull request for origin/gcc/p1-storefront-currency (no second orders migration: it already adds orders.storefront_country) and run its Storefront tests in CI on SQLite, MySQL 8 and MariaDB 11.4.2; then stamp or document the exemption for admin POS, vendor POS (OrderRepository::add), ChannelOrderMaterializer and the auction claim, decide whether the stamp may depend on GCC_STOREFRONT_CURRENCY, add the Saudi backfill after reconciliation, and make StaffOrderVisibility prefer the stamp. Note: docs/gcc/GCC-05-STOREFRONT-CURRENCY.md on that branch says both POS screens use generateOrder; they do not (no POS controller calls it).
- **Open owner decisions.** Where the key lives (column on orders or a storefronts table with ids); whether POS and marketplace sales are storefront sales (open in the runbook); the value used to backfill existing orders (Saudi) after reconciliation.
- **Verifier note (not applied automatically).** done_in_drafts corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** next_task corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** deps_blockers corrected by the verifier (applied above).

#### D1-02. No cart or checkout mixing countries or selling companies

- **State: IN PROGRESS** | plan lines 283 | Provisional single-store, single-selling-entity checkout (DECISIONS.md); GCC-13 | reviews: none
- **(2) Functional requirement.** One cart never holds products that belong to selling companies of different countries, and one checkout never pays for two of them; a customer who shops two countries has two carts.
- **(3) Exists now in code (main).** main: one cart space per customer; cart groups are per customer and vendor; get_cart_group_ids returns every group; one payment can cover several groups; no country, company or currency on carts.
- **(4) Done in the draft PRs.** #47 gives each product one country; #48 adds StorefrontCartScope on Cart (every read and write narrowed), refuses another country's product at add (Product_is_unavailable), keeps two cart groups for two storefronts, merges a guest cart per country at login, handles a legacy mixed group per storefront; #47 and #48 are default off. #54 secures cart-row ownership and is always on (independent of the storefront flag).
- **(5) What remains.** 1) an order-time rule that refuses checked rows spanning more than one country or company, holding when the switch is off or the request is unbound; 2) the same rule keyed on the legal entity once D1-03 exists; 3) #48 and #54 conflict in CartManager and RestAPI CartController; a combined tree is needed.
- **(6) PRs and commits.** [lavkoo#47](https://github.com/fullstackfull/lavkoo/pull/47) `e0dd5dc`; [lavkoo#48](https://github.com/fullstackfull/lavkoo/pull/48) `4a821d3`; [lavkoo#54](https://github.com/fullstackfull/lavkoo/pull/54) `54a0be5`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`
- **(7) Tests.**
  - `ci` StorefrontCartGuardTest: another country's product is refused at add and leaves the cart empty (PR 83 8ff3410: Tests SQLite (8.2/8.3); Storefront tests MySQL 8 and MariaDB 11.4.2)
  - `ci` StorefrontCartIsolationTest: two storefronts make two cart groups; remove-all, select, buy-now and checkout stay inside one storefront; a legacy mixed group is checked out per storefront (PR 83 8ff3410: same jobs)
  - `ci` StorefrontCartEdgesTest: a guest cart of two countries joins the customer's per-country carts at login (API and website) (PR 83 8ff3410: same jobs)
  - `none` A test that checkout refuses rows of two countries when the switch is off or the request is unbound (none)
- **(8) Dependencies and blockers.** Flag off by default; do not turn the flag off once another country has products (runbook). PR 48 and PR 54 both change three production files (RestAPI v1 CartController, Web CartController, CartManager); the combination exists only on origin/gcc/release/security-gcc-integration-ci, which has no CI result in the pack, so the cart code cannot be called integrated.
- **(9) Acceptance criteria.**
  - [MET] Adding another country's product to a cart is refused with Product_is_unavailable and leaves the cart empty (StorefrontCartGuardTest, PR 83 8ff3410, Storefront tests MySQL 8 / MariaDB + SQLite)
  - [MET] A customer shopping two countries has two separate cart groups; actions in one never touch the other (StorefrontCartIsolationTest, PR 83 8ff3410)
  - [MET] A guest cart holding two countries joins the customer's per-country carts at login, on the API and on the website (StorefrontCartEdgesTest, PR 83 8ff3410)
  - [MET] A legacy cart group that already holds two countries is read and checked out per storefront (StorefrontCartIsolationTest::test_checking_out_a_legacy_mixed_group..., PR 83 8ff3410)
  - [MISSING] Checked rows spanning two countries or companies are refused at order time, with the switch off or an unbound request (relies on request narrowing only; no assertion in generateOrder or checkValidationForCheckoutPages)
- **(10) Next coding task.** Stacked on #48/#53: add a guard in OrderManager::checkValidationForCheckoutPages and a second assertion inside generateOrder before the transaction that refuses checked rows whose products carry more than one storefront_country (later more than one legal entity); tests StorefrontCheckoutSingleCompanyTest for flag on and off, legacy mixed group, unbound request; run Storefront group on SQLite, MySQL 8, MariaDB 11.4.2.
- **Verifier note (not applied automatically).** done_in_drafts corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** deps_blockers corrected by the verifier (applied above).

#### D1-03. One selling company (legal entity) per order, with frozen identity

- **State: IN PROGRESS** | plan lines 10, 199-201, 282, 286 | Foundation: separate legal selling company per country (DECISIONS.md); provisional single-selling-entity checkout | reviews: legal, finance, owner-decision
- **(2) Functional requirement.** Each country has its own selling company that owns invoices, tax obligations, bank and gateway accounts; an order names exactly one such company and keeps a frozen copy of its identity, which the invoice prints.
- **(3) Exists now in code (main).** main: no legal-entity model. The invoice reads shop_address and invoice_settings from live global settings at print time; the invoice number is the order id; one platform wallet (admin_id 1); the seller's gst is read live.
- **(4) Done in the draft PRs.** PR 43 adds the single global label gcc.storefronts.legal.legal_entity recorded on legal-acceptance rows; PR 71 shows the same label in the platform-admin markets list. PR 90 (draft, stacked on #87, 3eb54456) adds the registry: legal_entities (one row for each country: legal name, trade name, tax and commercial registration, invoice series key, address, active flag; ships empty), orders.legal_entity_id and orders.entity_snapshot (nullable, additive, rollbacks refused while data exists), LegalEntityRegistry and OrderLegalEntitySnapshot (asked from OrderStorefrontSnapshot, so every order path stamps the same thing; written once), the commands gcc:legal-entity:upsert and gcc:legal-entity:list, and an optional rule that a storefront without a company may not sell (GCC_LEGAL_ENTITIES_REQUIRED, off). Off by default behind GCC_LEGAL_ENTITIES. Step 2 (26995055): the header of the admin, vendor and customer invoice is one partial that prints the company frozen on the order (name, address, tax and commercial registration, each only when it has it) and nothing of the shop's own address or business identity beside it; an order with no usable record, and every order while legal entities are off, prints what it always printed.
- **(5) What remains.** CI of the head; the invoice printing the currency the order was sold in and numbered per company with Saudi order numbers unchanged (the series needs the owner's rule); the POS receipts; POS and marketplace channel paths other than the seller app POS; per-company accounts (D2, E, H2); the companies themselves (owner and counsel); an admin screen; replacing the single label recorded on legal acceptances.
- **(6) PRs and commits.** [lavkoo#43](https://github.com/fullstackfull/lavkoo/pull/43) `82b6dfa`; [lavkoo#71](https://github.com/fullstackfull/lavkoo/pull/71) `b83a815`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`; [lavkoo#90](https://github.com/fullstackfull/lavkoo/pull/90) `26995055`
- **(7) Tests.**
  - `feature` LegalEntityRegistryTest (20), OrderLegalEntitySnapshotTest (stamping for each of the six storefronts, written once, a later change never rewrites it, the switches, before either migration), LegalEntityCommandsTest (11), LegalEntitySellingGateTest (5) (tests/Feature/Storefront; 451 tests of the Storefront group on SQLite and 70 legal entity and snapshot tests on MySQL 8.0.46 strict, locally on 3eb54456; CI of that head not read)
  - `feature` OrderInvoiceIssuerTest (26: ten shapes of an order with no usable record, each of the six companies printed from the order's own copy, only the facts a company has, trimming, an order as an array, an object or with a record already decoded, a later change of the registry never reaching a stamped order, both switches, the partial's markup with and without a record, escaping) and, in the Address directory, three end-to-end cases each for the admin and vendor invoice (PanelInvoiceTest) and the customer invoice (StorefrontOrderPagesTest) (tests/Feature/Storefront and tests/Feature/Address: 62 tests pass locally on 26995055 on SQLite and on MySQL 8.0.46 with and without strict mode; 9 of them fail on the unchanged invoice views and three mutations fail 14, 2 and 3 of them; tests/Feature/Storefront, tests/Feature/Address and tests/Unit/Deploy (1,694 tests) pass on SQLite; CI of that head not read)
- **(8) Dependencies and blockers.** Needs D1-01 for the storefront-to-entity mapping; legal facts per country (names, tax ids, e-invoicing duties) come from the owner and counsel.
- **(9) Acceptance criteria.**
  - [MET] A legal-entity record exists (country, legal name, tax id, invoice-series key) and each storefront maps to exactly one (legal_entities has one row per country (unique) with the facts of the company, the storefront-to-company mapping is the country itself (PR 90, 26995055; the registry and command tests of tests/Feature/Storefront); run 302 on 26995055 (read job by job on 2026-11-18): 22 of 23 jobs success, the deployment-overlay job red by design; the Storefront tests (SQLite 8.2 and 8.3, MySQL 8, MariaDB 11.4.2) and the Address tests (MySQL 8, MariaDB 11.4.2) are among the green ones)
  - [MET] Each order stores its selling entity id and an identity snapshot at placement (orders.legal_entity_id and entity_snapshot are stamped at placement by OrderStorefrontSnapshot for every order path (OrderLegalEntitySnapshotTest, PR 90, 26995055); run 302 on 26995055 (read job by job on 2026-11-18): 22 of 23 jobs success, the deployment-overlay job red by design; the Storefront tests (SQLite 8.2 and 8.3, MySQL 8, MariaDB 11.4.2) and the Address tests (MySQL 8, MariaDB 11.4.2) are among the green ones)
  - [MET] The invoice prints the order's frozen entity identity and tax number, and an order with no record prints what it printed before (PR 90 (26995055): OrderInvoiceIssuer and partials/invoice/_issuer.blade.php, included by the admin, vendor and customer invoices; OrderInvoiceIssuerTest (26) and the nine end-to-end invoice cases (PanelInvoiceTest, StorefrontOrderPagesTest); run 302 on 26995055 (read job by job on 2026-11-18): 22 of 23 jobs success, the deployment-overlay job red by design; the Storefront tests (SQLite 8.2 and 8.3, MySQL 8, MariaDB 11.4.2) and the Address tests (MySQL 8, MariaDB 11.4.2) are among the green ones)
  - [MISSING] The invoice prints the currency the order was sold in (the invoice still prints the amounts in the currency of the request that prints it (D1-06 slice 5 and the order's own record))
  - [MISSING] The invoice is numbered per entity, with Saudi order numbers unchanged (the invoice still carries the order id; the series key is registered and frozen on the order but no series exists, and the format is the owner's decision)
  - [MET] A legal acceptance receipt names the legal entity from configuration (StorefrontLegalAcceptanceTest::test_the_acceptance_names_the_legal_entity_from_configuration (unchanged))
- **(10) Next coding task.** Read the CI of PR 90 per job; then the owner's rule for the invoice number of each company (format, restart, gaps, tax authority) and the series on top of invoice_series_key; then the company on the payment request (D2-07).
- **Open owner decisions.** Legal name, tax registration, invoice-series and e-invoicing duties of each country's company; whether the first launch has one entity per country (the plan says so).

#### D1-04. Country, storefront, legal entity, seller and shop kept as separate keys

- **State: IN PROGRESS** | plan lines 203-217 | DECISIONS.md foundation: do not repurpose shop_id or seller_id for a country storefront | reviews: owner-decision
- **(2) Functional requirement.** Country, storefront, legal entity, seller, shop, warehouse, fulfillment account, sales-channel account and customer identity are separate keys; shop_id and seller_id keep their legacy meaning and are never used to say which store or company an order belongs to.
- **(3) Exists now in code (main).** main: seller_id/seller_is carry the vendor (in-house is seller_id 1, seller_is admin) on carts, orders and ledgers; no storefront or entity key. InventoryLocation already has an unrelated storefront_sellable flag (sales-channel stock), a naming hazard.
- **(4) Done in the draft PRs.** #47 adds products.storefront_country (CHAR(2), default SA) as a dedicated key, hidden from product JSON; the storefront itself is config (gcc.storefronts.countries), not a table; #8 resolves it per request. seller_id, shop_id and the order writer are unchanged in PR 83. Default off.
- **(5) What remains.** 1) order-level keys beside seller_id: the storefront key exists on origin/gcc/p1-storefront-currency (orders.storefront_country; no pull request, never run in CI), the entity key does not (D1-03); 2) a written key table for the nine concepts; 3) a guard test that fails when GCC code derives a storefront or company from seller_id or shop_id; 4) a decision whether storefronts stay config or become a table with ids.
- **(6) PRs and commits.** [lavkoo#8](https://github.com/fullstackfull/lavkoo/pull/8) `a8c6a42`; [lavkoo#47](https://github.com/fullstackfull/lavkoo/pull/47) `e0dd5dc`; [lavkoo#64](https://github.com/fullstackfull/lavkoo/pull/64) `216c279`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`
- **(7) Tests.**
  - `ci` StorefrontCatalogTest: a product added without a country is Saudi; each country keeps its own price and stock (PR 83 8ff3410: Tests SQLite; Storefront tests MySQL 8 and MariaDB 11.4.2)
  - `none` A guard test that no GCC code derives a country from seller_id or shop_id (none)
- **(8) Dependencies and blockers.** Depends on D1-01 and D1-03 for the order-level keys.
- **(9) Acceptance criteria.**
  - [MET] A product's country is a dedicated column, not derived from seller_id or shop_id (StorefrontCatalogTest, PR 83 8ff3410)
  - [CODED] seller_id, seller_is and shop_id keep their legacy meaning on carts, orders and ledgers (in-house is seller 1, admin) (OrderManager::getOrderAddData unchanged in PR 83; ChannelOrderMaterializer writes seller 1; no pinning test)
  - [MET] An order carries storefront and entity keys that are separate from seller_id (orders carry storefront_country, currency_code and currency_rate (PR 87, OrderStorefrontSnapshot) and legal_entity_id and entity_snapshot (PR 90), separate columns from seller_id, seller_is and shop_id, which keep their legacy meaning (OrderStorefrontSnapshotTest, OrderLegalEntitySnapshotTest); run 302 on 26995055 (read job by job on 2026-11-18): 22 of 23 jobs success, the deployment-overlay job red by design; the Storefront tests (SQLite 8.2 and 8.3, MySQL 8, MariaDB 11.4.2) and the Address tests (MySQL 8, MariaDB 11.4.2) are among the green ones)
- **(10) Next coding task.** Docs and test PR on the storefront chain: add the key table to docs/gcc/STOREFRONT_FLAG_RUNBOOK.md and a unit test (tests/Unit/Storefront/EntityKeysTest) that scans app/Services/Storefront, Staff and PlatformAdmin for seller_id or shop_id used as a country source; the order keys follow with D1-01 and D1-03.
- **Open owner decisions.** Whether storefronts stay config entries or become a table with ids; naming of the keys.
- **Verifier note (not applied automatically).** remaining corrected by the verifier (applied above).
- **Update after the assessment.** PR 87 and PR 90 (3eb54456): an order now carries storefront_country (GCC-05) and legal_entity_id with a frozen entity_snapshot (GCC-13), both separate from seller_id and shop_id; the key table and the guard test that no GCC code derives a country or company from seller_id or shop_id are still missing.

#### D1-05. Local independent pricing at checkout, frozen on the order lines

- **State: IN PROGRESS** | plan lines 11, 284-286 | Provisional local pricing, no auto-FX (DECISIONS.md); GCC-02/03 independent prices and stock (PR bodies) | reviews: finance, owner-decision
- **(2) Functional requirement.** Each storefront prices from its own product rows and never converts another country's price; checkout totals are in that storefront's currency, and each order line freezes its price, discount, tax and product copy.
- **(3) Exists now in code (main).** main: prices are stored in a base currency (USD) and converted for display with the session or default currency (usdToDefaultCurrency); order_details keeps price, discount, tax and the whole product JSON; carts.price is re-synced from the product before checkout. The customer app sends current_currency_code from the global config.
- **(4) Done in the draft PRs.** #47 makes the product row the unit of price and stock per country; #48 stops a cart from reading another country's price or stock. In PR 83 no code reads the storefront's currency (StorefrontContext::currency is metadata only). On branch origin/gcc/p1-storefront-currency (GCC-05 slices 1 to 3, behind GCC_STOREFRONT_CURRENCY, off by default, no pull request, never run in CI) StorefrontCurrency makes getWebConfig answer system_default_currency, currency_model (single_currency, no rate) and decimal_point_settings (3 for KWD, BHD, OMR) for a request served from a non-default storefront, so cart and checkout amounts follow that currency; StorefrontCheckoutSnapshotTest runs a real API cash-on-delivery checkout from each of the six storefronts. The default storefront on a multi-currency platform still follows the visitor's currency, and the admin, importer and bulk price tools still convert entered prices with the platform settings (slice 4, not built).
- **(5) What remains.** 1) checkout totals in the storefront currency (GCC-05 seam into the converters); 2) three-decimal rounding for KWD, BHD, OMR (see D1-06); 3) a test that an order line keeps its price when the product price changes; 4) admin-only copy-to-country tool and its no-sync rule are catalogue work (B).
- **(6) PRs and commits.** [lavkoo#47](https://github.com/fullstackfull/lavkoo/pull/47) `e0dd5dc`; [lavkoo#48](https://github.com/fullstackfull/lavkoo/pull/48) `4a821d3`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`; [lavkoo#87](https://github.com/fullstackfull/lavkoo/pull/87) `44b53616`
- **(7) Tests.**
  - `ci` StorefrontCatalogTest::test_each_country_keeps_its_own_price_and_stock_for_the_same_product (PR 83 8ff3410: Tests SQLite; Storefront tests MySQL 8 and MariaDB 11.4.2)
  - `ci` StorefrontCartIsolationTest::test_the_price_and_stock_of_another_storefronts_product_are_not_answered_by_the_cart (PR 83 8ff3410: same jobs)
  - `none` A test that checkout totals are in the storefront currency (refuted by the verifier: StorefrontCurrencyTest and StorefrontCheckoutSnapshotTest on origin/gcc/p1-storefront-currency assert the storefront's currency id, single_currency model, 3 dec)
  - `none` A test that an order line keeps its price after a product price change (none)
- **(8) Dependencies and blockers.** GCC-05 currency decision (how a storefront's currency reaches the converters); flag off by default.
- **(9) Acceptance criteria.**
  - [MET] A product row belongs to one country and keeps its own price and stock (StorefrontCatalogTest, PR 83 8ff3410)
  - [MET] A storefront cannot price, add or read another country's product by id (StorefrontCartGuardTest and StorefrontCartIsolationTest, PR 83 8ff3410)
  - [CODED] No code path writes one country's catalog price from another country's (no auto-FX between country copies) (no copy or sync tool exists; no test)
  - [CODED] An order line freezes unit price, discount, tax and the product JSON at placement (OrderManager::addOrderDetailsData; no immutability test)
  - [MET] Cart and order totals are in the storefront's own currency, not the session default or USD base (PR 87 (44b53616): the cart gate, the checkout and the saved order of a storefront with its own currency read and keep that currency at rate one (StorefrontMinimumOrderGateTest 21, StorefrontCheckoutSnapshotTest 10); passes locally on SQLite and MySQL 8.0.46. CI run 342 (the all-in-one integration, head a5f7de59, which holds this pull request's code), read per job on 2026-11-18: 'Tests, SQLite' on PHP 8.2 and on PHP 8.3 (the whole suite), the Storefront, Address, Catalog, Staff scope, Checkout/payment/permission, Fulfillment + security, Saudi baseline and Wallet/Loyalty groups on MySQL 8.0 and on MariaDB 11.4.2 and the strict-mode job on MariaDB: success (32 jobs: 29 success, 2 skipped by design, 1 still running, the sales-channel group A to M on MySQL, which holds none of these tests); the pull request's own run is not read)
- **(10) Next coding task.** Open the pull request for origin/gcc/p1-storefront-currency (the StorefrontCurrency resolver exists there) and prove it in CI on SQLite, MySQL 8 and MariaDB 11.4.2, with an assertion that the order total, not only the line price, is in the storefront currency and that a Saudi order is byte-identical to today; then slice 4 (price entry in the product's own country context, catalogue unit B) and the order-line immutability characterization test on main.
- **Open owner decisions.** GCC-05: how a storefront's currency reaches the converters and whether the stored base stays USD.
- **Verifier note (not applied automatically).** done_in_drafts corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** next_task corrected by the verifier (applied above).
- **Update after the assessment.** PR 87 (GCC-05 slices 1 to 5, head 44b53616): a cart of a storefront with its own currency is priced, gated and saved in that currency at rate one: StorefrontMinimumOrderGateTest (21) drives a real API checkout for each of the five storefronts (a cart below the minimum its storefront was given is refused with 403 and saves no order, equal and above pass) and StorefrontCheckoutSnapshotTest saves each order with the price of its own catalog and its currency.

#### D1-06. Order currency snapshot on orders, lines and money rows

- **State: IN PROGRESS** | plan lines 286-287 | Provisional local pricing (DECISIONS.md); price-stock map gap 10 | reviews: finance, owner-decision
- **(2) Functional requirement.** An order, its lines and its money rows (transactions, refunds) record the currency code, the decimal places and the unit of the amounts in force when it was placed, so history never depends on today's settings or exchange rate.
- **(3) Exists now in code (main).** main: no currency on orders, order_details, order_transactions, transactions or refund rows; the only currency column on orders is bring_change_amount_currency. Amounts are base (USD) and are re-converted at render time with the current rate in invoices, e-mails, exports and courier cash (price-stock map gaps 7 to 9, 13, 14 and 20). order_item_commissions.currency holds the code of the system default currency (OrderManager line 1151), not necessarily the stored unit.
- **(4) Done in the draft PRs.** In PR 83 nothing: the storefront's currency is config metadata (gcc.storefronts.countries, StorefrontContext::currency) and no order, price or converter code reads it; the wallet debit fix #36 states the premise that order totals are USD. On branch origin/gcc/p1-storefront-currency (GCC-05 slices 1 to 3, tip 25f83fe5, 2026-11-18, no pull request, never run in CI, off by default): orders.currency_code and orders.currency_rate (rate 1 for a storefront with its own currency) written once by OrderStorefrontSnapshot::record from OrderManager::generateOrder and the seller-app POS; 3 decimals for KWD, BHD and OMR through decimal_point_settings; GatewayCurrencyPolicy refuses a payment the gateway cannot take in that currency before it starts. Not built (slice 5): order_details, transactions and refund rows, invoice, mail, export and courier cash reading the order's record, Saudi backfill, rounding in some formatters (BackEndHelper::usd_to_currency, getFormatCurrency) and the two-decimal MyFatoorah money class.
- **(5) What remains.** 1) orders.currency_code and minor units (and order_details, order_transactions, refund rows); 2) one stated unit of stored amounts per order; 3) invoice, mail, export and courier cash read the order's currency; 4) 3-decimal rounding for KWD, BHD, OMR; 5) Saudi backfill after reading the production currency_model.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `none` Any test that an order stores its currency (refuted by the verifier: OrderStorefrontSnapshotTest on origin/gcc/p1-storefront-currency asserts orders.currency_code and currency_rate (rate 1 for each of the five non-default storefr)
- **(8) Dependencies and blockers.** Production values of currency_model, system_default_currency and decimal_point_settings are unknown (UNKNOWN); GCC-05 slices 1 to 3 are on origin/gcc/p1-storefront-currency (no pull request, no CI run) and slices 4 and 5 are not built; the default storefront on a multi-currency platform records no currency.
- **(9) Acceptance criteria.**
  - [MISSING] Orders, order lines and money rows store the currency code in force at placement (price-stock map gap 10: order currency is not persisted)
  - [MISSING] The unit of the stored amounts is explicit and equals the order currency (amounts are base USD; PR 35 and PR 36 note mixed units)
  - [MISSING] Invoice, e-mail, export and courier-cash figures use the order's own currency, not the session or default (price-stock map gaps 7 to 9 and 20)
  - [MISSING] KWD, BHD and OMR amounts keep three decimals through order, wallet and commission (two-decimal rounding in Convert and commission code (E09-F10))
  - [MISSING] Existing Saudi orders read as SAR after a reconciled backfill with no change of amounts (no backfill command exists)
- **(10) Next coding task.** Open the pull request for origin/gcc/p1-storefront-currency and run its four test files in CI (SQLite, MySQL 8, MariaDB 11.4.2); then slice 5 on that stack: additive nullable currency columns on order_details, order_transactions and refund_transactions, invoice, mail, export and courier cash preferring the order's record, dry-run command gcc:orders:backfill-currency for Saudi history once the production currency_model is read, tests for SAR and KWD (3 decimals).
- **Open owner decisions.** Stored unit of amounts (storefront currency or USD base), rounding rule for 3-decimal currencies, and how Saudi history is backfilled once the production currency_model is read.
- **Verifier note (not applied automatically).** done_in_drafts corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** deps_blockers corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** next_task corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** exists_now corrected by the verifier (applied above).
- **Update after the assessment.** PR 87 (GCC-05 slice 2, head 4af1c305): orders.storefront_country, currency_code and currency_rate are written by OrderStorefrontSnapshot right after the order lines, in OrderManager::generateOrder and the seller app POS; OrderStorefrontSnapshotTest (18) and StorefrontCheckoutSnapshotTest (10, a cash-on-delivery checkout from each of the six storefronts) pass on SQLite and the Storefront group on MySQL 8.0.46 (local runs; CI of the head not read). Order lines and money rows still carry no currency.
- **Next coding task today (supersedes item 10 above).** Slice 5 of PR 87: currency columns on order lines and money rows (payments, refunds, transactions), invoices, e-mails and exports reading the order's own record, and the Saudi backfill after the production currency model is read by the administrator.

#### D1-07. Order tax snapshot by country and selling company

- **State: IN PROGRESS** | plan lines 286 | Foundation: taxes belong to the country's legal entity (DECISIONS.md) | reviews: finance, legal
- **(2) Functional requirement.** An order stores the tax regime it was placed under (type, model, amount, rate lines and the selling company's tax registration), chosen from the storefront's country, so tax history does not move when settings change.
- **(3) Exists now in code (main).** main: orders.tax_type, tax_model, total_tax_amount and order_details.tax are written at placement; order_taxes rows hold tax name and rate. One default TaxModule setup serves the whole system (country_type single, country_code unused at checkout); the cache key has no market; in tax-included mode the amount is not computed or recorded.
- **(4) Done in the draft PRs.** Nothing for tax on the order: PR 83 leaves OrderManager, the cart tax functions and the TaxModule calculation untouched (only the two tax report controllers get staff country scopes in #82).
- **(5) What remains.** 1) pick the TaxModule setup by the storefront's country (country_code columns exist); 2) market-scoped cache key; 3) compute and record included tax; 4) store the selling entity's tax id with the order (D1-03); 5) characterization tests for excluded-tax orders (order_taxes rows).
- **(6) PRs and commits.** [lavkoo#98](https://github.com/fullstackfull/lavkoo/pull/98) `718edb99`; [lavkoo#99](https://github.com/fullstackfull/lavkoo/pull/99) `f2942370`; [lavkoo#102](https://github.com/fullstackfull/lavkoo/pull/102) `d2d130f8`
- **(7) Tests.**
  - `ci` ApiOrderPlacementTest::test_the_money_of_an_order_is_what_the_cart_says_whatever_the_address_and_the_mode (tax-included, total_tax_amount 0 only) (PR 83 8ff3410: Address tests MySQL 8 and MariaDB 11.4.2; Tests SQLite)
  - `none` A test of order_taxes rows or of an excluded-tax order (none: no test creates a tax setup)
- **(8) Dependencies and blockers.** Production tax setup (included or excluded, rates) is UNKNOWN; D1-03 for the entity tax id; legal review of country VAT rules.
- **(9) Acceptance criteria.**
  - [MET] An order stores tax_type, tax_model and total_tax_amount at placement (PR 98 (718edb99): OrderTaxRecordedAtPlacementTest asserts orders.tax_type, tax_model and total_tax_amount, and order_details.tax and tax_model, for an order-wise setup, a quantity of three, a product-wise setup and a tax-included setup; run 314 on 718edb99 (finished 14:41 UTC on 2026-11-18, read job by job): 20 of 21 jobs are success, among them the Address tests on MySQL 8 and on MariaDB 11.4.2, Tests on SQLite 8.2 and 8.3, the strict-mode job on MariaDB 11.4.2 and every sales-channel and cost group on both engines; the deployment-overlay job is red by design and is not a required check)
  - [MET] Per-rate tax rows (order_taxes) are written for an excluded-tax order (PR 98 (718edb99): one order_taxes row for each rate with its name, type, rate, amounts, quantity, payer, seller and setup, for an order-wise and a product-wise setup; a test fails on main for the empty string written into taxable_id and passes with the fix; run 314 on 718edb99 (finished 14:41 UTC on 2026-11-18, read job by job): 20 of 21 jobs are success, among them the Address tests on MySQL 8 and on MariaDB 11.4.2, Tests on SQLite 8.2 and 8.3, the strict-mode job on MariaDB 11.4.2 and every sales-channel and cost group on both engines; the deployment-overlay job is red by design and is not a required check)
  - [CODED] The rows of an order add up to the tax the order carries (PR 102 (draft, stacked on PR 98, d2d130f8, awaiting the finance review): the tax of a line is shared among that line's rates and the shipping tax among each line's own (a cart of two lines wrote half of each line's tax and a third of the shipping tax); OrderTaxRecordedAtPlacementTest (12: the corrected rows pinned, a product-wise cart whose lines do not share their rates, the shipping tax of each line adding up to 34.5 of 34.5), the three cases fail on the code before the change; the order (30), its lines (20 and 10) and the amount are unchanged; the Address directory (1,074 tests) passes on SQLite; CI not read)
  - [CODED] The orders already placed whose rows differ from their tax can be listed without rewriting them (PR 102: orders:tax-rows-reconcile (OrderTaxRowsReconciler, in the tax module) classes each order of a period as reconciled, short, over or without rows by what its rows add up to against orders.total_tax_amount, names order ids and amounts only, exits 1 while any order differs and only selects (a test proves every statement is a select and no row changes); OrderTaxRowsReconcileTest (17), one case through a real checkout next to an order that kept the old rows; what to do with the orders already placed is the finance review's; CI not read)
  - [MET] The tax setup is chosen by the storefront's country, and a storefront with none is never taxed with the platform's Saudi setup (PR 99 (draft, stacked on PR 87, f2942370, off by default): StorefrontTax reads the active default setup whose country_code is the storefront's and, when it has none, a setup built in memory and never saved that charges nothing, whether or not the currency switch is on; the platform's row is the one no storefront owns (the admin screen, its status switch and the module calculator never read a storefront's row as the platform's); one cache entry for each storefront; the panel edit of an order is taxed under the setup the order was placed under; StorefrontTaxSetupTest (22), mutated for each mechanism; passes on SQLite and MySQL 8.0.46. CI run 342 (the all-in-one integration, head a5f7de59, which holds this pull request's code), read per job on 2026-11-18: 'Tests, SQLite' on PHP 8.2 and on PHP 8.3 (the whole suite), the Storefront, Address, Catalog, Staff scope, Checkout/payment/permission, Fulfillment + security, Saudi baseline and Wallet/Loyalty groups on MySQL 8.0 and on MariaDB 11.4.2 and the strict-mode job on MariaDB: success (32 jobs: 29 success, 2 skipped by design, 1 still running, the sales-channel group A to M on MySQL, which holds none of these tests); the pull request's own run is not read)
  - [MET] A storefront cannot sell before its tax setup is approved or a documented decision that it charges no tax is recorded (PR 99 (f2942370): StorefrontTaxApproval and StorefrontSellingGate: a storefront other than the default one sells only while the business setting storefront_tax_approval_<country> holds a decision that names what, who, where and when (setup, with the fingerprint of the rates, type, included flag and shipping tax, or no_tax), and it goes stale when what the storefront is taxed with changes; gcc:storefront:tax-approve records it with an audit line, withdraws it and shows it; the storefront list, every checkout, payment and top-up route and the readiness report follow; StorefrontTaxApprovalTest (47) and StorefrontTaxApproveCommandTest (16), six mutations each caught; SQLite: Storefront 673, Address 1,045, Security 99, MyFatoorah 137; MySQL 8.0.46: Storefront 673 normal, and 209 strict on the integration tree; nothing here approves a tax, the person, the document and the rates are the owner's. CI run 342 (the all-in-one integration, head a5f7de59, which holds this pull request's code), read per job on 2026-11-18: 'Tests, SQLite' on PHP 8.2 and on PHP 8.3 (the whole suite), the Storefront, Address, Catalog, Staff scope, Checkout/payment/permission, Fulfillment + security, Saudi baseline and Wallet/Loyalty groups on MySQL 8.0 and on MariaDB 11.4.2 and the strict-mode job on MariaDB: success (32 jobs: 29 success, 2 skipped by design, 1 still running, the sales-channel group A to M on MySQL, which holds none of these tests); the pull request's own run is not read)
  - [MISSING] The included tax amount of a tax-included order is recorded (CartManager tax functions return zero when is_included)
  - [MET] The selling company's tax registration is frozen on the order (PR 90 (26995055): the order's entity_snapshot freezes the company's tax_registration_number with the rest of its identity (OrderLegalEntitySnapshotTest, OrderInvoiceIssuerTest), and the three invoices print it; run 302 on 26995055 (read job by job on 2026-11-18): 22 of 23 jobs success, the deployment-overlay job red by design; the Storefront tests (SQLite 8.2 and 8.3, MySQL 8, MariaDB 11.4.2) and the Address tests (MySQL 8, MariaDB 11.4.2) are among the green ones)
- **(10) Next coding task.** Test-first on the PR 83 chain: characterization tests that create a TaxModule setup (excluded, order_wise and product_wise) and assert order_taxes, total_tax_amount and tax_model; then pass StorefrontContext::country() into VatTaxManagement::getTaxSystemType with a market-scoped cache key and record included tax, behind a default-off flag; SQLite, MySQL 8, MariaDB 11.4.2.
- **Open owner decisions.** VAT rate and mode per country and entity; whether included tax must be stored; any e-invoicing duty (none exists in code).
- **Update after the assessment.** PR 98 (draft, main based, 718edb99): OrderTaxRecordedAtPlacementTest pins what an order keeps of its tax on main and fixes the empty string written into the integer column taxable_id, which a strict SQL server refuses. PR 99 (draft, stacked on PR 87, f2942370, off by default): a storefront other than the default one is taxed by the active default setup whose country_code is its own and, when it has none, by nothing (a setup built in memory and never saved): the platform's Saudi setup is never used for it, whether or not the currency switch is on (StorefrontTax; the module trait and its twin helper, five reads of the panel order edit, the admin screen of the system tax setup and the module calculator never read a storefront's row as the platform's; one cache entry for each storefront). StorefrontTaxApproval and the selling gate: a storefront does not sell before a person with the authority has approved its setup (a fingerprint of the rates, type, included flag and shipping tax, stale when any of them changes) or recorded, in a document, that it charges no tax; gcc:storefront:tax-approve records, withdraws and shows the decision with an audit line (an approver that names a tool or an assistant is refused); the readiness report marks a storefront without one UNSAFE; 123 tests in the tax classes, a mutation of each mechanism fails the matching tests. PR 102 (draft, stacked on PR 98, d2d130f8, awaiting the finance review): the rows of order_taxes added up to a share of the order's tax in a cart of several lines (half in the case of two lines, a third of the shipping tax); new orders write rows that add up, orders already placed keep theirs, and orders:tax-rows-reconcile lists the orders whose rows differ without writing anything; 29 tests. Still missing: the tax of a tax-included price is recorded nowhere, and the rates, the model and the registration of each country, and who approves them, are the owner's.
- **Next coding task today (supersedes item 10 above).** Read the CI of PR 99, PR 98 and PR 102 per job; the finance review of PR 102 (R6); with the owner's decision on whether a tax-included price must record its tax, compute and record it behind its own default-off switch (an additive record, not a change of the order's amounts); the owner gives each storefront its setup (gcc:storefront:tax) and a person approves it (gcc:storefront:tax-approve) before it opens.

#### D1-08. Address snapshot on every order

- **State: MERGED** | plan lines 286 | DECISIONS.md: preserve historical order and legal snapshots | reviews: none
- **(2) Functional requirement.** An order keeps an immutable copy of its delivery and billing address as they were when it was placed, and every reader (courier, panels, invoice) uses that copy.
- **(3) Exists now in code (main).** main: orders.shipping_address_data and billing_address_data written once by OrderAddressService::snapshot / AddressSnapshot::build (every old key including zip, plus region and city ids and names once the structured address is on); the order's address must be the customer's own; readers use the copy.
- **(4) Done in the draft PRs.** Nothing needed for the snapshot itself. The address work is dormant until its migrations run (mode off, soft, strict). Structured address rules exist only for Saudi addresses; the other five countries keep the legacy typed form (address work, cross-axis).
- **(5) What remains.** 1) make the Seller Center order page read shippingAddressSnapshot() for the phone and the block condition, with a test that edits and deletes the saved address; 2) a six-country snapshot test under the storefront flag is a cheap extra; address structure for AE, KW, QA, BH, OM belongs to the address unit.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` OrderSnapshotTest: copy keeps every old key; a past order does not change when the address, city or region does; courier reads the copy (PR 83 8ff3410: Address tests MySQL 8 and MariaDB 11.4.2; Tests SQLite)
  - `ci` ApiOrderPlacementTest: a structured address is copied with the names of that day and the copy never moves (PR 83 8ff3410: same jobs)
- **(8) Dependencies and blockers.** none
- **(9) Acceptance criteria.**
  - [MET] An order stores a copy of its delivery and billing address at placement with every old key and, once structured, the names of region and city that day (OrderSnapshotTest, ApiOrderPlacementTest, PR 83 8ff3410, Address tests MySQL 8 / MariaDB)
  - [MET] A later edit or delete of the saved address, or a renamed or switched-off city, never changes a placed order (OrderSnapshotTest::test_a_past_order_does_not_change_..., PR 83 8ff3410)
  - [CODED] The courier and the panels read the order's copy, not the live row (OrderSnapshotTest courier cases, PR 83 8ff3410)
  - [MET] An order cannot be placed with an address that is not the caller's own (ApiOrderPlacementTest and OrderSnapshotTest, PR 83 8ff3410)
- **(10) Next coding task.** Add AddressSnapshotSixCountryTest on the PR 83 chain: place orders with legacy AE, KW, QA, BH and OM addresses under the storefront flag and assert the copy keeps country and postal fields unchanged after the address is edited; Address group on SQLite, MySQL 8 and MariaDB 11.4.2.
- **Verifier note (not applied automatically).** remaining corrected by the verifier (applied above).

#### D1-09. Fulfillment source frozen on each order line

- **State: MERGED** | plan lines 286 | DECISIONS.md: order lines keep the chosen historical execution source | reviews: none
- **(2) Functional requirement.** Each order line records who fulfils it (provider, mapping, price) at placement, and reassigning the product later never moves an order already placed.
- **(3) Exists now in code (main).** main: OrderItemOwnershipSnapshotter::capture runs inside the order transaction and writes ProviderFulfillmentItem for a provider-owned product (provider, mapping ids, external ids, price, tax, JSON snapshot); a missing mapping still records ownership; in-house products write nothing (the absence is the in-house default); the snapshot cannot be changed or deleted.
- **(4) Done in the draft PRs.** Nothing needed. Country-specific provider accounts and warehouses are fulfillment work (cross-axis); the line's country follows its product row.
- **(5) What remains.** 1) a test through OrderManager::generateOrder with a provider-owned product that proves the snapshot is written inside the order transaction; 2) decide and document whether provider-owned lines sold through admin POS, vendor POS and seller-app POS are exempt (they get no snapshot today); 3) once D1-01 and D1-03 exist, add storefront and entity to the snapshot JSON; provider accounts per country belong to the fulfillment unit.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` OwnershipSnapshotTest: line frozen with provider, mapping and price; later reassignment does not move it; missing mapping still records ownership; snapshot immutable (PR 83 8ff3410: Fulfillment + security tests MySQL 8 and MariaDB 11.4.2; Tests SQLite)
- **(8) Dependencies and blockers.** none
- **(9) Acceptance criteria.**
  - [CODED] A provider-owned line is frozen with provider, mapping and price at placement (OwnershipSnapshotTest::test_a_provider_product_is_frozen_with_its_mapping_prices_and_provider, PR 83 8ff3410)
  - [MET] Reassigning the product later does not move an order already placed (OwnershipSnapshotTest::test_reassigning_the_product_later_does_not_move_an_order_already_placed, PR 83 8ff3410)
  - [MET] A missing mapping still records ownership so the line never falls through to in-house (OwnershipSnapshotTest, PR 83 8ff3410)
  - [MET] The snapshot cannot be changed or deleted afterwards (OwnershipSnapshotTest::test_the_snapshot_cannot_be_changed_afterwards, PR 83 8ff3410)
- **(10) Next coding task.** Add a storefront-flag-on case to OwnershipSnapshotTest on the PR 83 chain (an Emirati provider product ordered in AE keeps its provider when the product is reassigned); later extend the snapshot JSON with storefront and entity once D1-01 and D1-03 land.
- **Verifier note (not applied automatically).** remaining corrected by the verifier (applied above).

#### D1-10. Historical refunds in the original currency and merchant account

- **State: IN PROGRESS** | plan lines 287 | Foundation: separate settlements per legal entity (DECISIONS.md); price-stock map gap 12 | reviews: finance, legal
- **(2) Functional requirement.** A refund or cancellation after the fact returns what the buyer paid, in the original currency, through the merchant account and company that took the payment, whatever the rate or settings are now.
- **(3) Exists now in code (main).** main: refunds are per order line (getRefundAmountFromOrderDetails: line total plus tax minus discount, coupon and referral share, shipping excluded). RefundRequest and RefundTransaction carry an amount only, no currency, rate or account. A wallet refund credit converts the stored amount at the current rate (usdToDefaultCurrency); otherwise the money is a manual note. The MyFatoorah refund row has its own currency (gateway work).
- **(4) Done in the draft PRs.** Nothing for currency or account. #15 only characterizes today's sequential refund guards.
- **(5) What remains.** 1) currency_code, rate basis and entity or account reference on refund_requests and refund_transactions; 2) refund computed in the order's own currency; 3) refund routed to the merchant account that took the payment (D2); 4) tests across a rate change and a 3-decimal currency.
- **(6) PRs and commits.** [lavkoo#101](https://github.com/fullstackfull/lavkoo/pull/101) `1447d433`; [lavkoo#103](https://github.com/fullstackfull/lavkoo/pull/103) `d100dd01`
- **(7) Tests.**
  - `none` Any test of a refund in the original currency or merchant account (none)
- **(8) Dependencies and blockers.** Needs D1-06 (order currency) and D1-03 (entity); gateway accounts per entity belong to D2; financial review.
- **(9) Acceptance criteria.**
  - [CODED] A refund request or transaction stores the currency and the selling company of the original payment (PR 101 (draft, stacked on PR 90, 1447d433): RefundOrderMoney copies the order's storefront, currency, rate and legal_entity_id into the refund request (customer API and web) and the refund transaction (RefundTransactionService::getData); RefundOrderMoneyTest (12) and StorefrontRefundRecordTest (4, through the real API after a real checkout) pass on SQLite and MySQL 8.0.46, CI not read)
  - [MISSING] A refund request or transaction stores the merchant account of the original payment (gateway accounts for each company belong to D2 and do not exist; nothing records which account took the payment)
  - [CODED] A computation of a refund from the original payment (its amount and currency) exists and does not move with the exchange rate (PR 103 (draft, stacked on PR 101, d100dd01, prepared and not adopted): RefundFromOriginalPayment gives a refund as its share of the original payment, in the currency it was paid in (the one paid gateway record, else the order's own currency and rate; an order that recorded neither answers null); the exchange rate is not an input: in the test the computation stays 37.50 while the conversion of today moves from 37.50 to 35.00; exact decimals; RefundFromOriginalPaymentTest (26), mutations of the cumulative rounding, the cap, the cart share, the paid filter, the ambiguity rule and the rate each caught; CI not read)
  - [MISSING] The refund flow uses it, so that a refund is not re-converted at the refund-time rate (no refund calls the computation (a test fails for any file under app/ other than the preview command that refers to it): the owner's policy across currency movements is open (the original amount, or the amount at the current rate); a wallet refund still credits usdToDefaultCurrency of the stored amount)
  - [MISSING] A refund goes back through the merchant account and company that took the payment (no entity or account on refund rows)
  - [CODED] The computation returns exactly the amount paid across the refunds of an order, after a rate change and for a three-decimal currency (PR 103 (d100dd01): the refunds of one order, split in four ways and in thirds of a three-decimal currency (3.333, 3.334, 3.333 of 10.000 KWD), add up to exactly what was paid because each is the difference of two roundings of the cumulative share; a refund that would take more than the order is capped; a payment of several orders is shared by their stored amounts; CI not read)
  - [MISSING] A refund made through the flow after an exchange-rate or settings change returns exactly the amount paid (depends on the refund flow using the computation (the owner's policy) and on the merchant account of the company (D2); no refund in the flow is computed from the original payment)
- **(10) Next coding task.** After D1-06: additive nullable currency_code, rate_basis and legal_entity_id on refund_requests and refund_transactions, filled from the order; make RefundStatusService compute in order currency; tests that place an order at rate X, change the rate, refund, and get the same amount for SAR and KWD (3 decimals); SQLite, MySQL 8, MariaDB 11.4.2.
- **Open owner decisions.** Refund policy across currency movements (original amount versus current rate); credit notes; whether refunds may only return to the original merchant account.
- **Verifier note (not applied automatically).** refs: Cites price-stock map gap 12, which is 'API v2 seller product writes unit_price and discount; low-stock read'. The text 'Refund amounts carry no currency or rate snapshot' is gap 15 (API v2 seller refunds computed from base line amounts). Proposed: Foundation: separate settlements per legal entity (DECISIONS.md); price-stock map gap 15
- **Verifier note (not applied automatically).** ac[1].e: Same wrong gap number in the evidence of the criterion 'A refund amount is computed in the order's own currency'. Proposed: price-stock map gap 15: refunds carry no currency or rate snapshot
- **Update after the assessment.** PR 101 (draft, stacked on PR 90, 1447d433): a refund request and a refund transaction copy from their order's own record the storefront, the currency, the rate and the selling company when the row is made (App\\Services\\Storefront\\RefundOrderMoney; migration 2026_12_03_100000: four nullable columns on both tables, a rollback refused while a refund carries a record; the customer API, the customer web and RefundTransactionService::getData ask it; RefundTransaction::$fillable names the columns). 16 tests, three mutations each caught. PR 103 (draft, stacked on PR 101, d100dd01, prepared and not adopted): RefundFromOriginalPayment works out what a refund gives back as its share of the original payment (the one paid gateway record of the order, else the order's own currency and rate), in the currency it was paid in, so the exchange rate is not an input; the refunds of an order add up to exactly what was paid (cumulative rounding in the decimals of the currency, three for the dinar and the rial), a refund that would take more than the order is capped, and the arithmetic is on exact decimals; refunds:preview-original-payment prints it next to today's conversion and writes nothing; no refund calls it (a test fails for any file that does); 26 tests. Still missing: the refund flow using it (the owner's policy across currency movements), the merchant account of the company (D2).
- **Next coding task today (supersedes item 10 above).** Read the CI of PR 101 and PR 103; with the owner's rule on refunds across currency movements, wire RefundFromOriginalPayment into the refund flow behind its own default-off switch, and route a refund through the merchant account of the company that took the payment once D2 has the accounts.

#### D1-11. Operations on placed orders survive store state and run once

- **State: IMPLEMENTED IN DRAFT** | plan lines 287, 505 | GCC-12 (prior orders, payments, refunds and webhooks preserved); GCC-11 | reviews: finance
- **(2) Functional requirement.** Refunds, cancellations, due payments, callbacks and returns on an order already placed keep working when its storefront may not sell, are finished in the storefront the order belongs to, and take effect once.
- **(3) Exists now in code (main).** main: refund, cancel, due-payment and callback routes carry no sale gate (none exists on main). ReturnLogisticsService::receive checks isOpen before its transaction and does not re-read the return under a lock, so two receipts of one partial return can both restock (changed by #17).
- **(4) Done in the draft PRs.** #42 and #49 keep refunds, cancellations, due payments, callbacks and the MyFatoorah status open while new-sale routes are gated; #53 records the storefront with a payment and finishes it there even if the country was switched off or deleted from config; #17 receives a return once under a row lock and restocks its variant. All default off except #17.
- **(5) What remains.** 1) end-to-end tests: an order placed in AE, AE deactivated then removed, then cancel, refund request, status change and return receipt; 2) #17 is in no integration tree (not PR 75, PR 83 or origin/gcc/release/security-gcc-integration-ci); the #53 versus PR 56 module-helper overlap is resolved only on that combined branch, which has no CI result; 3) add the gateway callback routes to the open list of StorefrontSalesRoutesTest (they are not in it today); 4) pause modes (GCC-12) belong to store operations.
- **(6) PRs and commits.** [lavkoo#17](https://github.com/fullstackfull/lavkoo/pull/17) `5cbc384`; [lavkoo#42](https://github.com/fullstackfull/lavkoo/pull/42) `2534953`; [lavkoo#49](https://github.com/fullstackfull/lavkoo/pull/49) `b52d9b2`; [lavkoo#53](https://github.com/fullstackfull/lavkoo/pull/53) `7e32bba`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`
- **(7) Tests.**
  - `none` StorefrontSalesRoutesTest::test_refunds_cancellations_callbacks_and_obligations_on_placed_orders_stay_open (route middleware lists) (refuted by the verifier: The open list holds nine actions and no gateway callback route, so the 'callbacks' in the test name is not asserted at route level.)
  - `ci` StorefrontPaymentCallbackTest and PaymentStorefrontTest: payment finished in the recorded storefront, also when switched off or removed from config (PR 83 8ff3410: same jobs)
  - `ci` ReturnReceiptTest (SQLite) and ReturnReceiptConcurrencyTest (MySQL, MariaDB): a return received twice restocks once (PR 17 5cbc384: Tests SQLite; Sales-channel and cost tests MySQL 8.0 and MariaDB (concurrency))
  - `none` End-to-end cancel or refund of an order of a deactivated country (none)
- **(8) Dependencies and blockers.** PR 17 sits in neither integration tree; PR 53 and PR 56 conflict in app/Utils/module-helper.php; no tree combines the security set and the GCC chain.
- **(9) Acceptance criteria.**
  - [CODED] Refund, cancel, due-payment, callback and status-check routes of placed orders do not carry the sale gate (StorefrontSalesRoutesTest, PR 83 8ff3410, Storefront tests MySQL 8 / MariaDB + SQLite)
  - [MET] A payment started in a storefront is finished there, also if that country was switched off or deleted from the configuration since (StorefrontPaymentCallbackTest, PaymentStorefrontTest, PR 83 8ff3410)
  - [MET] A partial return received twice restocks once, and a variant return restocks the variant and the total (ReturnReceiptTest, ReturnReceiptConcurrencyTest, PR 17 5cbc384, jobs Tests SQLite and concurrency MySQL 8.0 / MariaDB)
  - [CODED] A customer or staff action on an order of a deactivated country (cancel, refund request, status change) works end to end (routes are ungated; no end-to-end test with a switched-off country)
- **(10) Next coding task.** Add StorefrontPlacedOrderOperationsTest on the PR 83 chain (order in AE, deactivate and remove AE, cancel, refund request, admin status change, return receipt); merge #17 into the planned combined integration branch and re-run the concurrency group on MySQL 8 and MariaDB 11.4.2.
- **Open owner decisions.** A return authorised for zero units restocks one unit (flagged in PR 17); pause modes of GCC-12 are store operations.
- **Verifier note (not applied automatically).** remaining corrected by the verifier (applied above).

#### D1-12. Multi-vendor and sub-order behaviour kept inside one storefront and company

- **State: IN PROGRESS** | plan lines 289 | DECISIONS.md guardrail 4: preserve vendor and seller behaviour; E09 compatible approach | reviews: finance
- **(2) Functional requirement.** A basket of several vendors still becomes one order per vendor under one order_group_id, seller-scoped access and commission and ledger entries keep working, and the storefront and company rules sit above the vendor split without changing it.
- **(3) Exists now in code (main).** main: getVendorWiseCartList groups checked rows by cart_group_id (vendor and seller_is); generateOrder loops the groups, one orders row each under one order_group_id; in-house orders use seller_id 1 and seller_is admin; per line commission snapshot and vendor-ledger entries are written (never failing the order). No test on main pins the split, the ledger or the commission.
- **(4) Done in the draft PRs.** #48 keeps a second product of the same country in its cart group and makes two groups for two storefronts (its fixtures use the in-house vendor only, so vendor separation is not exercised). #20 and #52 scope seller API order, line and refund lookups to seller_id and seller_is seller, so in-house orders sharing seller id 1 stay out of reach (OrderDetail::ofSeller). #20 is in neither integration tree; #52 is in PR 75.
- **(5) What remains.** 1) characterization tests for the vendor split through generateOrder (order per group, same order_group_id, seller keys, commission and ledger rows) and a seller-vendor fixture in the storefront cart tests; 2) storefront and entity stamped on each sub-order (D1-01, D1-03); 3) #20 and #52 both change four seller API files (v2 RefundController; v3 OrderController, POSController, RefundController); a combined tree.
- **(6) PRs and commits.** [lavkoo#20](https://github.com/fullstackfull/lavkoo/pull/20) `75afe85`; [lavkoo#48](https://github.com/fullstackfull/lavkoo/pull/48) `4a821d3`; [lavkoo#52](https://github.com/fullstackfull/lavkoo/pull/52) `ead8e2a`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`
- **(7) Tests.**
  - `ci` StorefrontCartIsolationTest: two storefronts make two cart groups; a second in-house product of one country joins its group (no seller-vendor fixture) (PR 83 8ff3410: Tests SQLite; Storefront tests MySQL 8 and MariaDB 11.4.2)
  - `ci` SellerApiOrderScopingTest (15 tests): seller API order, line and refund lookups are the seller's own, in-house orders excluded (PR 20 75afe85: Fulfillment + security tests MySQL 8 and MariaDB 11.4.2; Tests SQLite)
  - `none` A test that generateOrder splits a two-vendor basket into two orders under one order_group_id (none on main or in PR 83 or PR 75)
- **(8) Dependencies and blockers.** PR 20 and PR 52 both change four seller API controllers (RestAPI v2 RefundController, v3 OrderController, POSController, RefundController); PR 20 is not in an integration tree.
- **(9) Acceptance criteria.**
  - [CODED] A basket with products of two vendors becomes one order per vendor under one order_group_id (OrderManager::generateOrder loop; no test)
  - [MET] Within a storefront a second product of the same (in-house) vendor joins the existing cart group, and two storefronts make two groups (StorefrontCartIsolationTest (fixtures use in-house products only), PR 83 8ff3410)
  - [MET] A seller reaches only orders and lines with its own seller_id and seller_is seller; in-house orders sharing the id are not reachable (SellerApiOrderScopingTest, PR 20 75afe85, jobs Fulfillment + security MySQL 8 / MariaDB and Tests SQLite)
  - [CODED] Commission snapshot and vendor-ledger entries are written per sub-order line at checkout (OrderManager::recordOrderItemCommission; no test)
  - [MISSING] Each sub-order carries its own storefront and selling company (depends on D1-01 and D1-03)
- **(10) Next coding task.** Test-only PR on main: MultiVendorCheckoutTest placing a two-vendor basket (in-house plus one seller) through generateOrder and asserting one order per group, shared order_group_id, seller keys, commission snapshot and ledger rows; a second case under the storefront flag with two countries proves the vendor split and the storefront split compose; run on SQLite, MySQL 8, MariaDB 11.4.2.

#### D1-13. Checkout and order concurrency: stock locks and order numbers

- **State: IN PROGRESS** | plan lines 499, 505 | Plan line 499 (overselling, duplicate reservation) and the axis F trace; E09 section 2.11 | reviews: finance
- **(2) Functional requirement.** Simultaneous checkouts never oversell, never deadlock, never collide on an order id, and a refused order leaves stock untouched; a paid order from a gateway is never refused for stock.
- **(3) Exists now in code (main).** main: generateOrder locks every product of the order up front in ascending id order, decrements stock with a conditional update, refuses a short line as a whole, picks the order id with lockForUpdate and retries up to 3 times on a key collision, and lets the post-payment path oversell instead of refusing. The three POS order writers still pick ids as 100000 plus the row count with no lock.
- **(4) Done in the draft PRs.** #18 is test-only: GenerateOrderStockTest (9 tests, lock SQL built from the connection grammar) and CheckoutStockConcurrencyTest (six processes, last three units, real MySQL or MariaDB). No production code changed; #18 is in neither integration tree.
- **(5) What remains.** 1) POS and seller-app sales take their id from the locked generator, with finance sign-off because the order number is also the invoice number; 2) a direct test of the id-collision retry; 3) #18 into the combined tree.
- **(6) PRs and commits.** [lavkoo#18](https://github.com/fullstackfull/lavkoo/pull/18) `de1270f`
- **(7) Tests.**
  - `ci` CheckoutStockConcurrencyTest: six customers order the last three units at once: three orders, three refusals, stock zero, distinct ids, no escaped exception (PR 18 de1270f: Sales-channel and cost tests MySQL 8.0 (concurrency) and MariaDB 11.4.2 (concurrency))
  - `ci` GenerateOrderStockTest (9 tests): short stock refuses the whole order, exact stock accepted, products locked in ascending id order, allow_oversell after payment (PR 18 de1270f: Tests, SQLite (PHP 8.2/8.3))
  - `none` A test of two simultaneous POS sales or a POS plus a storefront sale (none)
- **(8) Dependencies and blockers.** PR 18 is not in an integration tree; changing POS numbering touches invoice numbers and needs a finance decision.
- **(9) Acceptance criteria.**
  - [MET] Six simultaneous checkouts of the last three units give three orders, three refusals, stock zero, distinct order ids and no escaped exception (CheckoutStockConcurrencyTest, PR 18 de1270f, concurrency jobs MySQL 8.0 and MariaDB 11.4.2)
  - [MET] Every product of the cart is locked up front in ascending id order (GenerateOrderStockTest, PR 18 de1270f, Tests SQLite)
  - [MET] An order for more than the stock, or one short line or variant, is refused whole and leaves stock unchanged (GenerateOrderStockTest, PR 18 de1270f)
  - [CODED] A gateway-paid order is never refused for stock (allow_oversell); an unknown policy rejects (GenerateOrderStockTest, PR 18 de1270f)
  - [CODED] An order-id collision between simultaneous checkouts is retried up to 3 times instead of failing (OrderManager::generateOrder retry loop; exercised only indirectly by the concurrency test)
  - [MISSING] Admin POS, vendor POS and seller-app POS take their order id from the locked generator (all three still use 100000 + count + 1)
- **(10) Next coding task.** Branch from main: make Admin and Vendor POSOrderController and v3 seller POSController call OrderManager::generateNewOrderID inside their transaction (numbering unchanged for existing ids); add a two-process PosOrderIdConcurrencyTest to tests/Feature/Concurrency; merge #18 into the combined integration branch; run the concurrency group on MySQL 8 and MariaDB 11.4.2.

#### D1-14. Order creation is idempotent

- **State: IN PROGRESS** | plan lines 505 | Plan axis F: checkout, webhook, order confirmation; E09-F13 | reviews: finance, security
- **(2) Functional requirement.** Repeating the same event (a submit, a gateway callback, a POS retry) never creates a second order or a second debit.
- **(3) Exists now in code (main).** main: no idempotency key on the customer checkout submit (COD, offline, wallet); the order-integrity monitor lists checkout_idempotency_key as not configured and names the remedy (orders.checkout_token). A paid gateway callback can build the order again if the cart was refilled; MyFatoorah has its own settlement guard (payments unit).
- **(4) Done in the draft PRs.** #56 (in PR 75) makes the payment-success hook skip a payment whose transaction_ref already has an order, with replay tests. #35 (wallet chain) adds an optional Idempotency-Key to the seller-app POS sale: a repeat returns the first order, debited and stocked once (table pos_sale_idempotency_keys). Neither covers the customer checkout submit.
- **(5) What remains.** 1) per-checkout token or Idempotency-Key on web and API v1 COD, offline and wallet submits, unique per payment and vendor group; 2) apps send it (the seller app must send the header for #35 to act); 3) a concurrency test for two simultaneous deliveries of one paid callback (the guard is an existence check).
- **(6) PRs and commits.** [lavkoo#35](https://github.com/fullstackfull/lavkoo/pull/35) `7c75b7f`; [lavkoo#56](https://github.com/fullstackfull/lavkoo/pull/56) `f2e6f2c`; [lavkoo#75](https://github.com/fullstackfull/lavkoo/pull/75) `47b78ab`
- **(7) Tests.**
  - `ci` SettlementsInOneProcessTest: the same paid callback delivered again after the cart was refilled builds no second order (PR 56 f2e6f2c and PR 75 47b78ab: Tests, SQLite (PHP 8.2/8.3))
  - `ci` PosWalletSaleTest (SQLite) and PosSaleIdempotencyConcurrencyTest (MySQL): one key, one order, one debit, also under two simultaneous submits (PR 35 7c75b7f: Tests SQLite; Sales-channel and cost tests MySQL 8.0 and MariaDB (concurrency))
  - `none` A test that a repeated customer checkout submit creates one order (none)
- **(8) Dependencies and blockers.** PR 35 sits in the wallet chain (#9, #11, #13) and not in an integration tree; app releases are needed to send keys; financial review for the POS change.
- **(9) Acceptance criteria.**
  - [MET] A paid gateway callback delivered again, even after the cart was refilled, builds no second order (SettlementsInOneProcessTest, PR 56 f2e6f2c, PR 75 47b78ab, Tests SQLite)
  - [MET] A repeated POS sale submit with the same Idempotency-Key returns the first order, debited and stocked once, also when two arrive together (PosWalletSaleTest and PosSaleIdempotencyConcurrencyTest, PR 35 7c75b7f, SQLite and concurrency MySQL 8.0 / MariaDB)
  - [MISSING] A repeated submit of the same customer checkout (COD, offline, wallet) creates one order (OrderIntegrityPanel reports checkout_idempotency_key not configured)
  - [MISSING] The customer and seller apps send a per-attempt idempotency key (PR 35 note: the seller app must send the header; the customer app sends none)
  - [MISSING] Two simultaneous deliveries of one paid callback build one order (guard is an existence check on orders.transaction_ref, no lock or unique key, no test)
- **(10) Next coding task.** Branch stacked on the combined security and GCC tree: nullable orders.checkout_token char(36) with a unique index on (token, vendor group), set from an Idempotency-Key header or the cart group in OrderManager::generateOrder; web and API v1 COD, offline and wallet answer a repeat with the first order ids; two-process concurrency test in tests/Feature/Concurrency; the monitor panel reads the new column.
- **Open owner decisions.** Whether the server issues a per-checkout token that the apps must send (needs app releases) or only accepts a client key.

#### D1-15. Checkout and order operations act only for their owner

- **State: IN PROGRESS** | plan lines 505 | Plan axis F trace; DECISIONS.md guardrail 4 (seller isolation); GCC-13 | reviews: security, finance, owner-decision
- **(2) Functional requirement.** Every checkout and order operation (read, cancel, refund, due payment, cart change, seller action) acts only for the customer, guest or seller who owns the order, and identity comes only from the server's guard or session, never from a posted field.
- **(3) Exists now in code (main).** main: signed-in access scoped by customer_id alone (a guest order shares the number space), seller API lookups by seller_id alone, edit-order due payments and the web due payment without an owner check, request identity read from posted fields in the checkout and cart paths, cart rows changed by id.
- **(4) Done in the draft PRs.** #16 registered-order scope (Order::ownedByCustomer); #20 seller lookups also need seller_is seller; #21 API edit-order due payments and #22 web due payment act only for the owner or a verified tracking session (wallet signed-in only); #23 identity from guard, resolver or session; #54 cart rows only by their owner, into a variation of their own product; #52 seller APIs only on own rows. #54, #52 and #56 are in PR 75; #16, #20, #21, #22, #23 are in no integration tree.
- **(5) What remains.** 1) guest identifiers are sequential and guessable (owner and app-team decision); 2) the customer app's guest due-payment flow needs an app change (#21 note); 3) #16, #20, #22 and #23 touch files the security set also changes (RestAPI v1 OrderController and CustomerController, UserProfileController, seller controllers, CartManager, OrderManager, module-helper, Helpers) and #16, #20, #23 also files of the GCC chain (Order.php, seller POSController, CartManager, module-helper); conflicts untested, a combined tree is needed.
- **(6) PRs and commits.** [lavkoo#16](https://github.com/fullstackfull/lavkoo/pull/16) `65d2d09`; [lavkoo#20](https://github.com/fullstackfull/lavkoo/pull/20) `75afe85`; [lavkoo#21](https://github.com/fullstackfull/lavkoo/pull/21) `af862ff`; [lavkoo#22](https://github.com/fullstackfull/lavkoo/pull/22) `20a99a5`; [lavkoo#23](https://github.com/fullstackfull/lavkoo/pull/23) `59aa978`; [lavkoo#52](https://github.com/fullstackfull/lavkoo/pull/52) `ead8e2a`; [lavkoo#54](https://github.com/fullstackfull/lavkoo/pull/54) `54a0be5`; [lavkoo#75](https://github.com/fullstackfull/lavkoo/pull/75) `47b78ab`
- **(7) Tests.**
  - `ci` ApiCustomerOrderOwnershipTest and WebCustomerOrderOwnershipTest: a signed-in customer cannot reach the guest order sharing its id (PR 16 65d2d09: Fulfillment + security tests MySQL 8 and MariaDB 11.4.2; Tests SQLite)
  - `ci` SellerApiOrderScopingTest: seller lookups own rows only (PR 20 75afe85: Fulfillment + security tests MySQL 8 and MariaDB; Tests SQLite)
  - `ci` ApiDuePaymentOwnershipTest (PR 21) and CustomerOrderEditPayDueAmountTest (PR 22): due payments only for the owner or a verified tracking session (PR 21 af862ff and PR 22 20a99a5: Tests, SQLite (PHP 8.2/8.3))
  - `ci` TrustedRequestIdentityTest: a posted user or customer_id is nobody (PR 23 59aa978: Tests, SQLite (PHP 8.2/8.3))
  - `ci` CartRowOwnershipTest: a cart row changes only for its owner into a variation of its own product (PR 54 54a0be5 and PR 75 47b78ab: Tests, SQLite (PHP 8.2/8.3))
- **(8) Dependencies and blockers.** Guest-identity secret needs an app release and an owner decision; the five p0 PRs need rebasing onto the combined tree; owner and security review before merge (PR 23 changes checkout identity).
- **(9) Acceptance criteria.**
  - [MET] A signed-in customer reaches, cancels, refunds and downloads the invoice of registered orders it owns only; the guest order sharing its numeric id is not reachable (ApiCustomerOrderOwnershipTest, WebCustomerOrderOwnershipTest, PR 16 65d2d09, Fulfillment + security MySQL 8 / MariaDB + SQLite)
  - [MET] Seller APIs act only on orders and lines with the seller's own seller_id and seller_is seller (SellerApiOrderScopingTest, PR 20 75afe85)
  - [MET] Due payments on placed orders act only for the owner or a verified tracking session; the wallet only for a signed-in owner (ApiDuePaymentOwnershipTest PR 21 af862ff; CustomerOrderEditPayDueAmountTest PR 22 20a99a5; Tests SQLite)
  - [MET] Request identity comes only from the guard, the request resolver or the session, never a posted user or customer_id (TrustedRequestIdentityTest, PR 23 59aa978, Tests SQLite)
  - [MET] A cart row is changed only by its owner, into a variation of its own product (CartRowOwnershipTest, PR 54 54a0be5 and PR 75 47b78ab, Tests SQLite)
  - [MISSING] A guest order, cart or address cannot be reached by knowing or guessing the guest id (guest ids are sequential; open owner decision (PERM-05) in PR 16, 21, 23)
- **(10) Next coding task.** Open a pull request for, or dispatch CI on, origin/gcc/release/security-gcc-integration-ci (it already combines PR 75 and PR 83); then rebase #16, #20, #21, #22 and #23 onto it (none of them is in it), resolve the overlaps (OrderController, UserProfileController, seller POSController, CartManager, OrderManager, module-helper, Helpers) and re-run the Security, Payment and Address groups on SQLite, MySQL 8 and MariaDB 11.4.2. In parallel, write the per-guest secret design for the owner.
- **Open owner decisions.** Guest identity secret (needs a mobile release); behaviour changes listed in #21 and #22 (guest due payments, partial phone refused, a signed-in customer cannot act on a guest order).
- **Verifier note (not applied automatically).** next_task corrected by the verifier (applied above).

#### D1-16. Sale refusal for a storefront that may not sell yet

- **State: IN PROGRESS** | plan lines 10, 13 | GCC-11 (approved legal texts per country before selling); GCC-12; GCC-19 | reviews: legal, native-arabic, owner-decision
- **(2) Functional requirement.** A new order or payment in a storefront that is not ready to sell is refused on the server, not only hidden in the app; the Saudi storefront keeps selling; existing obligations stay open.
- **(3) Exists now in code (main).** main: no storefront and no sale gate; every order and payment route accepts a request.
- **(4) Done in the draft PRs.** #38 and #39 add legal readiness per storefront and a sellable flag in the storefront list; #42 (API) and #49 (web) put the storefront.sellable middleware on every route that starts an order or a payment: refused with 422 storefront_not_sellable or a flash message; Saudi is grandfathered; a missing legal table refuses. Customer app #3 and #5 follow the answer and disable checkout. All default off; the app was not run on a device.
- **(5) What remains.** 1) a documented rule for the POS paths, the marketplace dispatch, sales-channel webhooks and the auction claim (not gated); 2) readiness beyond legal texts: payment account, selling entity, tax set-up, shipping and the pause modes of GCC-12 (the storefront's currency is added on origin/gcc/p1-storefront-currency, no pull request); 3) the gate edits routes/web/routes.php and routes/rest_api/v1/api.php, which security PRs also edit: origin/gcc/release/security-gcc-integration-ci carries both but has no CI result.
- **(6) PRs and commits.** [lavkoo#38](https://github.com/fullstackfull/lavkoo/pull/38) `122960e`; [lavkoo#39](https://github.com/fullstackfull/lavkoo/pull/39) `8149090`; [lavkoo#42](https://github.com/fullstackfull/lavkoo/pull/42) `2534953`; [lavkoo#49](https://github.com/fullstackfull/lavkoo/pull/49) `b52d9b2`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`; [lavkooapp#3](https://github.com/fullstackfull/lavkooapp/pull/3) `eda7814`; [lavkooapp#5](https://github.com/fullstackfull/lavkooapp/pull/5) `5526c4b`
- **(7) Tests.**
  - `ci` StorefrontSellingGateTest, StorefrontSalesRoutesTest and StorefrontLegalReadinessTest: gate on every new-sale route, 422 storefront_not_sellable, Saudi never refused, missing table refuses (PR 83 8ff3410: Tests SQLite; Storefront tests MySQL 8 and MariaDB 11.4.2)
  - `none` checkout_sale_gate_test.dart and storefront_can_sell_test.dart: a browse-only storefront disables checkout and payment and shows the server's message (refuted by the verifier: Both are pure unit tests (CheckoutSaleGate.permits, StorefrontChoice.canSell, StorefrontRefusal.messageOf); neither builds the cart or checkout screen or contro)
  - `none` A test of the POS or marketplace paths under a non-sellable storefront (none)
- **(8) Dependencies and blockers.** Legal texts per country must be approved and published (Arabic and English); owner decision on POS; backend flag and app flag must go together (app note).
- **(9) Acceptance criteria.**
  - [MET] Every route that starts a new order or payment (API v1 place, offline, wallet, again, digital-payment, add-to-fund; web checkout posts, payment request, add-fund, order-again) carries the sale gate (StorefrontSalesRoutesTest, PR 83 8ff3410)
  - [MET] A storefront without current approved legal texts in every required type and locale gets 422 storefront_not_sellable (JSON) or goes back with a message (browser) (StorefrontSellingGateTest, StorefrontLegalReadinessTest, StorefrontSalesRoutesTest, PR 83 8ff3410)
  - [MET] The Saudi storefront is never refused, nothing is refused while storefronts are off, and a missing legal table refuses instead of a 500 (StorefrontSellingGateTest, PR 83 8ff3410)
  - [CODED] The customer app disables checkout and payment on a browse-only storefront and shows the server's refusal (checkout_sale_gate_test.dart, lavkooapp PR 5 5526c4b, job storefront-header (not run on a device))
  - [MISSING] Order creators outside the storefront (admin, vendor, seller-app POS, marketplace dispatch, sales-channel webhooks, auction claim) apply a documented sale rule for their store (runbook: not behind the sale gate)
  - [MISSING] A storefront is also refused for a pause mode, a missing payment account, selling entity or tax set-up (readiness is legal texts only; no pause mode in the tree)
- **(10) Next coding task.** Stacked on #49: introduce StorefrontSaleState (legal readiness, entity, payment account, tax set-up, pause mode) behind the existing flag and call it from StorefrontSellingGate; apply it to the POS and channel order writers once the owner decides; matrix test over six countries and the states; show the reason in the app; SQLite, MySQL 8, MariaDB 11.4.2.
- **Open owner decisions.** Arabic wording of the refusal (the app PR asks for it); whether POS and marketplace sales are storefront sales; what ready to sell includes beyond legal texts.
- **Verifier note (not applied automatically).** remaining corrected by the verifier (applied above).

#### D1-17. Purchase depends on delivery-address eligibility, not on the visitor's country

- **State: IN PROGRESS** | plan lines 187-191, 507 | GCC-16 (local and cross-border shipping by eligibility); GCC-19 | reviews: legal, owner-decision
- **(2) Functional requirement.** A visitor from a non-active country is served the default storefront, and whether they can buy is decided at checkout by the eligibility of the delivery address (storefront, destination, product, provider), never by where the visitor is.
- **(3) Exists now in code (main).** main: one global delivery-country allow-list (delivery_country_restriction setting with the delivery_country_codes table) is checked at address entry and, in the REST order paths, against one of the order's addresses; no storefront or product dimension; the Saudi structured address adds its own rules; no IP inference exists.
- **(4) Done in the draft PRs.** #8, #19 and #28 resolve the storefront per request (explicit choice, then X-Storefront header, then path, then default; a deactivated or unknown country falls back to the default and shows none of its products, pinned by the kill-switch test). No destination eligibility is added by any open PR.
- **(5) What remains.** 1) a DeliveryEligibility service (storefront, destination address, product, provider) as one order-time gate on web and REST, replacing the per-path checks; 2) a per-storefront destination matrix; 3) product restrictions (regulated pharmacy goods) checked apart from courier coverage (H1 rules).
- **(6) PRs and commits.** [lavkoo#8](https://github.com/fullstackfull/lavkoo/pull/8) `a8c6a42`; [lavkoo#19](https://github.com/fullstackfull/lavkoo/pull/19) `74372eb`; [lavkoo#28](https://github.com/fullstackfull/lavkoo/pull/28) `8c8d32a`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`
- **(7) Tests.**
  - `ci` StorefrontKillSwitchTest and StorefrontResolverTest: a deactivated country is served the default storefront; an explicit choice beats the header and the path (PR 83 8ff3410: Tests SQLite; Storefront tests MySQL 8 and MariaDB 11.4.2)
  - `ci` StorefrontCheckoutTest::test_the_country_allow_list_still_judges_the_posted_country (global list, web) (PR 83 8ff3410: Address tests MySQL 8 and MariaDB 11.4.2; Tests SQLite)
  - `none` A test of delivery eligibility per storefront, product or provider (none)
- **(8) Dependencies and blockers.** Depends on H1 for the shipping and product eligibility rules; legal review of cross-border and regulated goods.
- **(9) Acceptance criteria.**
  - [MET] A visitor who asks for a deactivated or unconfigured country is served the default storefront and none of its products (StorefrontKillSwitchTest, StorefrontResolverTest, PR 83 8ff3410)
  - [MET] An explicit manual choice beats the header, the path prefix and the default (no IP inference exists to override it) (StorefrontResolverTest, PR 83 8ff3410)
  - [CODED] Checkout refuses a delivery address whose country is not on the platform's delivery-country list when the restriction is on (StorefrontCheckoutTest, PR 83 8ff3410, Address tests MySQL 8 / MariaDB (baseline global list))
  - [MISSING] Allowed destinations are decided per storefront, by one order-time gate that every channel (web, REST, apps) goes through (global list checked in separate places)
  - [MISSING] A visitor outside every active country can buy when the delivery address is eligible for the product, provider and storefront, and is refused otherwise (no product or provider by destination check)
- **(10) Next coding task.** Stacked on #49: DeliveryEligibility service with a config matrix per storefront (default: Saudi to Saudi only), called from OrderAddressService::assertOrderable so web and REST share it, the legacy delivery_country_restriction kept as an additional AND; matrix tests over six storefronts and destinations including a visitor outside every active country; SQLite, MySQL 8, MariaDB 11.4.2.
- **Open owner decisions.** Which destinations each storefront ships to; cross-border rules for regulated health goods; whether a visitor from a non-active country may buy at all.

#### D2-01. Paymob kept working for the Saudi baseline and pinned by tests

- **State: IN PROGRESS** | plan lines 293, 295 | Foundation: preserve Paymob; guardrail 1; Discovery E10 s2.8, R-071; Q-33 | reviews: security, finance
- **(2) Functional requirement.** Paymob keeps taking and confirming payments exactly as today while GCC code lands, and its pay and return behaviour is pinned by tests before anything is generalised to other markets.
- **(3) Exists now in code (main).** No test pins Paymob's pay or return behaviour on main or any lavkoo branch. The only test file that names Paymob is tests/Feature/Storefront/GatewayCurrencyPolicyTest.php on gcc/p1-storefront-currency@25f83fe5 (key paymob_accept as currency-list cases); it never calls PaymobController.
- **(4) Done in the draft PRs.** Nothing Paymob-specific. Shared drafts reach it: PR 53 records the storefront in Payment::generate_link; PR 56 guards the shared success hook against a second order (not exercised with Paymob).
- **(5) What remains.** 1) Characterisation tests: pay request takes amount and currency from the stored row; a valid return runs the hook once; wrong HMAC or success not true runs the failure hook; no payment in session. 2) Provider-side confirmation and amount match (D2-11). 3) A CI group that also runs on MySQL 8 and MariaDB.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `none` Paymob pay and return behaviour (no test file; zero Paymob hits under tests on main and on every lavkoo branch)
- **(8) Dependencies and blockers.** Q-33. The pay request is made with inline curl_* calls in credit(), so a test of criterion 1 needs a namespaced curl_init/curl_exec stub in the test bootstrap or a small production seam; the callback criteria (HMAC, success flag, hook, no payment in session) need no HTTP stub and no production change. No live account is needed.
- **(9) Acceptance criteria.**
  - [CODED] The payment sent to Paymob takes its amount (minor units) and currency from the stored payment row, not from the request (PaymobController::credit on main; no test)
  - [CODED] A return with a wrong HMAC, or success not 'true', marks nothing paid and runs the failure hook (PaymobController::callback on main; no test)
  - [CODED] A valid return settles once and a replay builds no second order (hook guard hasOrderFor in PR 56 (PR 75 tree); not exercised with Paymob)
  - [MISSING] A Paymob characterisation suite runs in CI on SQLite, MySQL 8 and MariaDB (none exists)
- **(10) Next coding task.** Test-only branch gcc/p0-paymob-characterization from main: tests/Feature/Payment/PaymobControllerTest.php with a signed callback fixture for the callback criteria and a namespaced curl stub (or a separately approved one-line seam) for the pay request; add its folder to a CI group that runs on MySQL 8 and MariaDB.
- **Open owner decisions.** Q-33: which GCC markets Paymob serves and for which legal entity; whether Paymob gets a server-to-server notification (R-071), decided after the characterisation.
- **Verifier note (not applied automatically).** deps_blockers corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** next_task corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** exists_now corrected by the verifier (applied above).

#### D2-02. MyFatoorah kept working for the Saudi baseline, suite green on three engines

- **State: MERGED** | plan lines 293, 296 | Foundation: preserve MyFatoorah; guardrail 1; Discovery E10 s2.7 | reviews: none
- **(2) Functional requirement.** MyFatoorah keeps its hosted page, server-to-server verification, signed webhook, settle-once, reconcile and refund behaviour for Saudi while GCC code lands, shown by its own suite on SQLite, MySQL 8 and MariaDB.
- **(3) Exists now in code (main).** app/Services/MyFatoorah (15 files), controller, webhook route, three tables (migrations 2026_11_08_100001-3), config/myfatoorah.php (off until MYFATOORAH_ENABLED and the admin switch are on), myfatoorah:reconcile, app status endpoint. 157 test methods in 7 files: Feature/MyFatoorah (4), Unit/MyFatoorah (2), Feature/Concurrency (1).
- **(4) Done in the draft PRs.** No draft changes a MyFatoorah file (no branch differs from main in a path containing 'fatoorah'). The shared code it calls (Payment::generate_link, the success hook) is changed by PR 53 and PR 56. The suite is green on the GCC tree (PR 83) and the security tree (PR 75).
- **(5) What remains.** Keep the three MyFatoorah CI groups green in the combined tree and after each extension (D2-05 to D2-09). MyFatoorah settlement and recovery run through the shared success hook, whose main-branch defects are fixed only in PR 56 (D2-13). No test runs MyFatoorah with storefronts on; add one with storefront currencies off.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` MyFatoorah suite: payment flow, settlement and webhook, refund and admin, checkout visibility, signature, money, concurrency (157 methods) (PR 83 head 8ff3410: jobs 'Sales-channel and cost tests, MySQL 8.0 (myfatoorah)', 'MariaDB 11.4.2 (myfatoorah)', both '(concurrency)' jobs, 'Tests, SQLite (PHP 8.2)' and '(PHP 8.3)')
- **(8) Dependencies and blockers.** None for the baseline. Extensions depend on D2-05 to D2-09; the combined PR 53 + PR 56 tree (task B) must keep these jobs green.
- **(9) Acceptance criteria.**
  - [MET] A payment is created from the server-side amount, and a currency other than the configured SAR is refused (PaymentFlowTest::test_a_payment_is_created_from_the_amount_on_the_server and ::test_a_currency_other_than_sar_is_refused; PR 83@8ff3410 myfatoorah MySQL 8, MariaDB, SQLite)
  - [MET] The gateway key stays 'fatoorah' and exactly one card exists, so old records and app builds resolve (RefundAndAdminTest::test_the_gateway_key_stays_fatoorah_so_old_records_still_resolve; same jobs)
  - [MET] The gateway is not offered while the environment switch is off or no credentials are saved (CheckoutVisibilityTest (9) and PaymentFlowTest::test_the_environment_kill_switch_overrides_the_admin_panel; same jobs)
  - [MET] One paid payment settles once under webhook, redirect, recovery and simultaneous deliveries (SettlementAndWebhookTest (61), MyFatoorahConcurrencyTest (6); PR 83@8ff3410 concurrency jobs on MySQL 8 and MariaDB)
- **(10) Next coding task.** When task B builds the combined PR 53 + PR 56 tree, run tests/Feature/MyFatoorah, tests/Unit/MyFatoorah and tests/Feature/Concurrency on SQLite, MySQL 8 and MariaDB, and add one test that settles a Kuwait cart paid through MyFatoorah with storefronts on (none exists).
- **Verifier note (not applied automatically).** remaining corrected by the verifier (applied above).

#### D2-03. Tabby and Tamara reviewed; BNPL gateways only after owner approval and provider confirmation

- **State: NOT STARTED** | plan lines 298, 300-301 | Foundation: examine Tabby/Tamara; Q-09, Q-33; Discovery E10 s4 and s5 | reviews: owner-decision, finance, legal, security
- **(2) Functional requirement.** Tabby and Tamara are reviewed in the existing branches and, only after the owner approves and each provider confirms market, currency and merchant eligibility in writing, added as new gateways on the MyFatoorah pattern (attempt table, server verification, signed webhook, refunds).
- **(3) Exists now in code (main).** Append: the legacy customer app clone /home/user/ksaapp98 (main only, 79f50be) also has no Tabby or Tamara code or text (it has the MyFatoorah screens only); pharmacyksa98 is the only legacy repository not available.
- **(4) Done in the draft PRs.** Nothing: none of the 86 lavkoo, 7 lavkooapp and 6 lavkooappseller pull requests adds a BNPL provider.
- **(5) What remains.** Owner decision Q-09 and written provider confirmation per market; BNPL needs data the payment row lacks (cart lines, shipping address, tax) and a state model beyond is_paid (authorised, captured, partly captured); app status endpoint; webhooks; refund and cancel; tests with a mocked provider; per-account credentials (D2-07, D2-08).
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `none` Tabby and Tamara (no code, so no test)
- **(8) Dependencies and blockers.** Q-09 and Q-33; D2-05 capability registry and D2-07 accounts first; the payment row carries no cart lines or shipping address (E10 s4).
- **(9) Acceptance criteria.**
  - [MISSING] A BNPL method is offered only where the storefront's account has it enabled for the order's currency and amount range (no BNPL code on any branch)
  - [MISSING] A BNPL order is created only after the provider reports an authorised payment whose amount and currency match the stored payment (no BNPL code on any branch)
  - [MISSING] Capture, cancel and partial refund are recorded per attempt and are idempotent (no BNPL code on any branch)
  - [MISSING] Adding a BNPL gateway cannot create a second key or controller for a gateway that exists (registry guard) (needs D2-04 registry test)
- **(10) Next coding task.** After D2-05: list Tabby and Tamara in the gateway capability registry with confirmed=false for all six markets, and test that an unconfirmed gateway is never listed or accepted; build no adapter until Q-09 is answered.
- **Open owner decisions.** Q-09: study only (audit default), build for Saudi first, or build multi-country after written confirmation; which markets and merchant accounts exist.
- **Verifier note (not applied automatically).** exists_now corrected by the verifier (applied above).

#### D2-04. One gateway registry, no duplicate gateway or class

- **State: IN PROGRESS** | plan lines 311 | Foundation: preserve, do not duplicate; Discovery E10 s2.1; R-002 | reviews: security, finance
- **(2) Functional requirement.** Each gateway key has exactly one controller, route, default-list entry and currency entry, and a test fails when a class is declared twice or a gateway is added a second time.
- **(3) Exists now in code (main).** Dead copies: app/Http/Controllers/Payment/PaymeraController.php and app/Services/PaymentRoutingService.php (PR 3 deletes both) and app/Packages/PaystackGateway/NewPaystackController.php (unrouted, referenced by nothing). app/Traits/Payment.txt is NOT dead: AddonService::deleteAddon copies it over app/Traits/Payment.php when the Gateways add-on is deleted. It is a sixth hand-kept route list (maps 'fatoorah' to payment/fatoorah/pay, no my_fatoorah key, no PaymentStorefront::record of PR 53), so deleting that add-on on a server where it is installed would overwrite the trait (R-002).
- **(4) Done in the draft PRs.** PR 3 (24cc103) deletes the two duplicate class files and adds tests/Unit/Architecture/SingleClassDeclarationTest.php, which fails when any type is declared by two files under app/ or Modules/. It is in neither integration tree; its body says not ready to merge before the security or financial owner reviews it.
- **(5) What remains.** Registry consistency test; delete only the three truly dead copies after owner confirmation; keep app/Traits/Payment.txt in step with Payment.php (include it in the consistency test) or change AddonService::deleteAddon first; later one registry class instead of six lists.
- **(6) PRs and commits.** [lavkoo#3](https://github.com/fullstackfull/lavkoo/pull/3) `24cc103`
- **(7) Tests.**
  - `ci` SingleClassDeclarationTest: no type declared by two files (PR 3 head 24cc103: 'Tests, SQLite (PHP 8.2)' and '(PHP 8.3)' (whole suite))
  - `ci` RefundAndAdminTest: 'fatoorah' is in both default lists and has one card (PR 83 head 8ff3410: myfatoorah jobs on MySQL 8, MariaDB, SQLite)
- **(8) Dependencies and blockers.** PR 3 needs security or financial owner sign-off (payment-provider file). Modules/Gateways add-on state on the server is unknown (R-002) and would change which routes exist.
- **(9) Acceptance criteria.**
  - [MET] No class, interface, trait or enum under app/ or Modules/ is declared by two files (SingleClassDeclarationTest on PR 3@24cc103, SQLite 8.2/8.3 green; fails on main before the deletions per the PR body)
  - [MET] 'fatoorah' is in both default gateway lists and only one card exists (RefundAndAdminTest::test_the_gateway_key_stays_fatoorah_so_old_records_still_resolve; PR 83@8ff3410 myfatoorah jobs)
  - [MISSING] Every key of the route map that has a route is in the default list and the currency map, and no key repeats (no such test)
  - [CODED] A stub key (no route or controller) is never offered at checkout (payment_gateways() limits to the default list unless the Gateways add-on is published; no test)
- **(10) Next coding task.** On the PR 3 branch add tests/Unit/Payments/GatewayRegistryConsistencyTest.php that parses the route map in Payment::generate_link, the two default lists, the currency map and the registered routes and asserts criteria 3 and 4; keep the other dead copies until the owner confirms they are dead.
- **Open owner decisions.** Confirm the discarded Paymera copy holds no logic worth keeping (PR 3 body); whether legacy non-GCC gateways (Paymera, MTN Cash, bKash, SSLCommerz, Paytm) and the 23 stubs are disabled for GCC (audit: Isolate).
- **Verifier note (not applied automatically).** exists_now corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** remaining corrected by the verifier (applied above).

#### D2-05. Gateway, method and currency offered only where the provider supports the market

- **State: IN PROGRESS** | plan lines 303, 305 | Foundation: never assume a provider supports every country or method; Q-33; R-016 | reviews: owner-decision, finance
- **(2) Functional requirement.** A gateway, method and currency are offered in a storefront only where the provider's support for that market and merchant is confirmed and switched on; a country named in documentation counts for nothing (default deny).
- **(3) Exists now in code (main).** Append: PaymentGatewayTrait::getPaymentGatewayCurrencyCode (lines 476-499) never refuses a currency: it returns the requested currency if the gateway lists it and it is active, else the gateway's only currency, else the first active currency the gateway lists, else 'USD'.
- **(4) Done in the draft PRs.** Nothing in a pull request. Branch gcc/p1-storefront-currency (tip 25f83fe5, committed 2026-11-18 06:06 UTC, no pull request, no CI record) carries GCC-05 slices 1 to 3, off by default: StorefrontSellingGate also needs the storefront's currency to exist (still no gateway check), and GatewayCurrencyPolicy, asked first in Payment::generate_link before the row is written, refuses a gateway whose PaymentGatewayTrait list lacks the storefront's own currency, and a three-decimal currency (KWD, BHD, OMR) unless the gateway is in gcc.storefronts.currency.verified_three_decimal_gateways (empty); GatewayCurrencyPolicyTest pins it. Slices 4 and 5 (prices in the product's country context, wallets, fixed decimals) are not done. The sale gates of PR 42 and PR 49 still judge legal readiness only.
- **(5) What remains.** Capability registry gateway x market x currency x method x merchant-confirmed, default deny; storefront-filtered payment_gateways() and /api/v1/config; refusal at payment start; readiness command; sale gate needs an eligible online gateway; provider confirmations for every cell.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `none` Gateway capability per market (no test; the baseline currency maps and admin check have none either)
- **(8) Dependencies and blockers.** Q-33: provider and market confirmations (every cell UNKNOWN); D2-07 for merchant-level eligibility; GCC-05 slice 4 for amounts and decimals.
- **(9) Acceptance criteria.**
  - [MISSING] A gateway not confirmed for Kuwait is absent from the Kuwait storefront's method list (web and /api/v1/config) while Saudi still lists it (payment_gateways() is one global list)
  - [CODED] A payment start for a gateway, currency or method the storefront does not support is refused before a payment row is written (PaymentController::payment checks no gateway eligibility)
  - [MISSING] A storefront with online payment on and no eligible gateway reports sellable false (StorefrontSellingGate checks legal readiness only (PR 83))
  - [MISSING] A capability entry needs an explicit confirmed flag with provider, date and currency; a country name alone grants nothing (no registry on any branch)
- **(10) Next coding task.** Branch from gcc/p1-storefront-currency@25f83fe5 (not from the PR 83 tree) and extend GatewayCurrencyPolicy with the capability registry (default deny, config gcc.payments.<CC>.<gateway>, confirmed flag with provider, date, currency); call it from payment_gateways() and keep the single guard in Payment::generate_link; tests in tests/Feature/Storefront so MySQL 8 and MariaDB run them.
- **Open owner decisions.** Q-33: for each market, which of Paymob, MyFatoorah, Tabby, Tamara are confirmed, with currency, methods and merchant eligibility; accept default-deny as the rule.
- **Verifier note (not applied automatically).** done_in_drafts corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** next_task corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** exists_now corrected by the verifier (applied above).
- **Update after the assessment.** PR 87 (GCC-05 slice 3): GatewayCurrencyPolicy, asked in Payment::generate_link() before any payment row is written, refuses a gateway whose currency list lacks the storefront currency; a three-decimal currency (KWD, BHD, OMR) only goes to a gateway verified for it, and the verified list is empty. 19 tests (GatewayCurrencyPolicyTest). The capability registry per gateway, market and merchant account is still missing.

#### D2-06. Order-level payment-method eligibility, including cross-border orders

- **State: IN PROGRESS** | plan lines 270, 303 | GCC-16; R-072; Discovery E10 s2.9 | reviews: owner-decision, legal, finance
- **(2) Functional requirement.** Which payment methods an order may use is decided on the server from its storefront, destination country, amount and route (local or cross-border), shown the same on web and app, and enforced again when the payment starts.
- **(3) Exists now in code (main).** PaymentRoutingRule (gateway_code, hide or prefer, min and max amount, country as free text) and PaymentRoutingService, used only by the web payment page. /api/v1/config does not apply it and PaymentController::payment does not enforce it. No migration creates payment_routing_rules (without the table there are no rules). CheckoutCodGuard blocks COD only for mixed-provider carts. Nothing handles cross-border.
- **(4) Done in the draft PRs.** PR 1 (d664ab4) pins the no-rule baseline with 7 tests; PR 3 removes the dead duplicate App\Services\PaymentRoutingService.
- **(5) What remains.** ISO country and storefront or currency dimensions on rules; apply them in /api/v1/config; enforce in PaymentController::payment; additive migration for payment_routing_rules; cross-border inputs (source storefront, destination, regulated-goods flag, courier COD support); tests that a rule hides a gateway on web, app and direct start.
- **(6) PRs and commits.** [lavkoo#1](https://github.com/fullstackfull/lavkoo/pull/1) `d664ab4`; [lavkoo#3](https://github.com/fullstackfull/lavkoo/pull/3) `24cc103`
- **(7) Tests.**
  - `ci` PaymentRoutingWithoutRulesTest (7): gateway list unchanged while no rule exists (PR 1 head d664ab4: 'Tests, SQLite (PHP 8.2)' and '(PHP 8.3)')
  - `none` A rule that matches (no test of PaymentRoutingRule matching on any branch)
- **(8) Dependencies and blockers.** D2-05 registry; D1 single-company checkout; courier route data (shipping unit); GCC-16 regulatory rules from the owner.
- **(9) Acceptance criteria.**
  - [MET] With no routing rule the checkout gateway list is unchanged (PaymentRoutingWithoutRulesTest; PR 1@d664ab4 SQLite 8.2/8.3 green)
  - [CODED] A rule hiding a gateway for a destination country removes it from the web payment page (WebController::resolvePaymentGateways on main; no test with a rule)
  - [MISSING] The same rule removes it from /api/v1/config and a direct payment start with it is refused (ConfigController and PaymentController do not consult the service)
  - [MISSING] A cross-border order is offered only methods enabled for its route; COD is not offered where the courier cannot collect cash (no cross-border code on any branch)
  - [MISSING] Rules match ISO country codes and the storefront, not free text (PaymentRoutingRule.country is free text)
- **(10) Next coding task.** Branch from main: PaymentRoutingService::resolve() takes storefront and ISO country; apply it in ConfigController and refuse an unlisted gateway in PaymentController::payment; additive migration for payment_routing_rules guarded like the other GCC tables; tests that a rule hides a gateway on web, app and direct start.
- **Open owner decisions.** Which methods are allowed on cross-border orders (COD, wallet, BNPL); who maintains regulated-goods restrictions (GCC-16).

#### D2-07. Per-company gateway accounts bound to each payment

- **State: NOT STARTED** | plan lines 307 | Foundation: accounts by legal entity and store; Q-10, Q-33, D-10; R-015; Discovery roadmap phase 8 | reviews: security, finance, owner-decision
- **(2) Functional requirement.** Each selling company has its own gateway accounts per gateway and environment; a payment is bound to one account when it starts, and settlement, verification, refund and reconciliation use that account; today's Saudi configuration stays the default SA account.
- **(3) Exists now in code (main).** One addon_settings row per gateway key for the whole platform (updateOrInsert on key_name and settings_type), read by every controller. payment_requests has no storefront, entity or account column (the table comes from the installed SQL dump, not from a migration); receiver_id is the constant '100'. MyFatoorah freezes only the mode (test or live) per attempt. GCC_LEGAL_ENTITY is one environment value.
- **(4) Done in the draft PRs.** PR 53 records the storefront country in payment_requests.additional_data (no migration), the only per-market fact stored with a payment. No account entity, table or flag exists on any branch (gcc/core-payment-accounts from the roadmap was never created).
- **(5) What remains.** Legal entity model (Q-10); table payment_gateway_accounts (entity or storefront, gateway, environment, status); resolver storefront to account with the Saudi row as default SA account; account reference on payment rows, attempts, refunds and webhook events; flag GCC_PAYMENT_ACCOUNTS default off; admin screens scoped by entity (GCC-14); tests.
- **(6) PRs and commits.** [lavkoo#53](https://github.com/fullstackfull/lavkoo/pull/53) `7e32bba`
- **(7) Tests.**
  - `none` Gateway accounts (no account concept, so no test)
- **(8) Dependencies and blockers.** Q-10 and D-10 entity model; the real schema of payment_requests and addon_settings (SQL dump) before any ALTER; Modules/Gateways add-on state on the server is unknown (R-002).
- **(9) Acceptance criteria.**
  - [MISSING] A Kuwait payment starts against the Kuwait account's credentials and never the Saudi account's (one row per gateway key)
  - [MISSING] An attempt keeps its account after the account is edited or disabled; verification, settlement and refund use that account (attempts freeze the mode only)
  - [MISSING] The existing Saudi row works unchanged as the SA account with no data change (no resolver)
  - [MISSING] A storefront has at most one live account per gateway and environment (unique key) (no table)
- **(10) Next coding task.** Branch gcc/core-payment-accounts from the PR 83 tree: additive migration payment_gateway_accounts guarded like the other GCC tables, GatewayAccountResolver reading the existing addon_settings row as the SA account, flag GCC_PAYMENT_ACCOUNTS off; tests on MySQL 8 and MariaDB for the unique key and the SA fallback.
- **Open owner decisions.** Q-10: first legal entity model; which entity owns each gateway account; approval of migrations on payment tables.

#### D2-08. Per-company gateway secrets and separate test and live environments

- **State: IN PROGRESS** | plan lines 307 | Foundation: gateway accounts and settlements per entity; SEC-E10-06; DR-1 (referenced only) | reviews: security
- **(2) Functional requirement.** Each company's gateway credentials are stored per account and per environment (test and live), encrypted at rest, never shown or logged, and a payment is verified in the environment that created it.
- **(3) Exists now in code (main).** MyFatoorah: api_key and webhook_secret are encrypted per mode, never rendered back, a blank save keeps the stored key, the mode is frozen per attempt, tested. Generic gateways including Paymob: the shared form writes the same validated values into both live_values and test_values, kept as plain arrays by Setting, so test and live cannot differ there (a code comment in the admin routes says the same).
- **(4) Done in the draft PRs.** PR 7 (ad09d6c) reads the Paymera header secret from configuration, refuses to call without it and removes the payload debug writes (12 tests). PR 63 (de0a7d5, security set) validates the gateway logo upload as an image.
- **(5) What remains.** Encrypted per-account, per-environment storage for every gateway, not only MyFatoorah; separate test and live blobs in the generic form; access to gateway screens by company (GCC-14); audit of changes; payment:check per account. Credential exposure items stay in the owner register (DR-1), referenced only.
- **(6) PRs and commits.** [lavkoo#7](https://github.com/fullstackfull/lavkoo/pull/7) `ad09d6c`; [lavkoo#63](https://github.com/fullstackfull/lavkoo/pull/63) `de0a7d5`
- **(7) Tests.**
  - `ci` MyFatoorah secret storage and per-mode verification tests (PR 83 head 8ff3410: myfatoorah jobs on MySQL 8, MariaDB, SQLite)
  - `ci` PaymeraCallbackTest (12): secret from configuration, none means not configured (PR 7 head ad09d6c: 'Tests, SQLite (PHP 8.2)' and '(PHP 8.3)')
- **(8) Dependencies and blockers.** D2-07; owner decision on where company secrets live; DR-1 open in DEFERRED_RISKS.md (referenced only); security review before merge.
- **(9) Acceptance criteria.**
  - [MET] MyFatoorah api_key and webhook_secret are stored encrypted, never rendered back, and a blank save keeps the stored key (PaymentFlowTest::test_secrets_are_encrypted_at_rest_and_never_rendered_back, ::test_a_blank_field_on_save_keeps_the_stored_key; PR 83@8ff3410 myfatoorah jobs)
  - [MET] A payment is verified against the environment that created it (PaymentFlowTest::test_an_attempt_is_verified_against_the_mode_that_created_it; same jobs)
  - [MET] Paymera's header secret comes from configuration only, and an empty value stops the gateway (PaymeraCallbackTest on PR 7@ad09d6c, SQLite 8.2/8.3 green)
  - [MISSING] Every gateway's credentials are encrypted per company account and environment, and test and live values can differ (generic form writes one set into both blobs)
  - [MISSING] A staff member scoped to one country cannot open or change another company's gateway settings (gateway screens sit behind module:3rd_party_setup only)
- **(10) Next coding task.** On the D2-07 accounts branch: GatewayAccountSecrets generalising GatewayConfigRepository (encrypt per account and environment), first for Paymob; give the generic form separate live and test blobs; test that account A's secret is never used for account B; extend payment:check by account.
- **Open owner decisions.** Where each company's secrets live (admin form with encryption or a secret store); who may see them (GCC-14 scope).

#### D2-09. Per-company webhook settings and webhook scope

- **State: NOT STARTED** | plan lines 307 | Foundation: gateway accounts per entity; R-015, R-016, R-071 | reviews: security
- **(2) Functional requirement.** Webhook URL, signing secret and event scope belong to the company's gateway account: a delivery is authenticated with the secret of the account it names, and one company's secret never validates another company's delivery.
- **(3) Exists now in code (main).** MyFatoorah: one public URL POST /api/myfatoorah/webhook, also sent with every invoice; verified with the active mode's webhook_secret, then with the other mode's; no account notion. Paymob: no server-to-server route, only pay and the browser callback. Paymera: a CSRF-exempt trigger that re-checks the status with the provider.
- **(4) Done in the draft PRs.** Nothing.
- **(5) What remains.** Per-account webhook route or a lookup before the signature check; per-account secret; dedupe key and payload digest scoped by account; registration instructions per company; tests with two accounts and cross-signed deliveries.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `none` Per-account webhooks (no account concept, so no test)
- **(8) Dependencies and blockers.** D2-07 accounts and D2-08 per-account secrets first.
- **(9) Acceptance criteria.**
  - [MISSING] A webhook signed with company A's secret is refused for company B's payment (one secret per mode for the platform)
  - [MISSING] Each account shows its own webhook URL and secret status in the admin screen (one URL route('myfatoorah.webhook'))
  - [MISSING] Webhook dedupe keys are unique per account (dedupe_key is unique platform-wide)
- **(10) Next coding task.** After D2-07: route /api/myfatoorah/webhook/{account} that resolves the account before the signature check and takes the secret from it, dedupe key (account, delivery); tests with two accounts and cross-signed deliveries on SQLite and MySQL 8.
- **Open owner decisions.** none beyond D2-07 and D2-12 (R-071).

#### D2-10. Payment transaction records carry market, company, account and exact amount

- **State: IN PROGRESS** | plan lines 309, 505 | GCC-13; R-007, R-015, R-035; Discovery E10 s2.2 | reviews: finance
- **(2) Functional requirement.** Each payment record carries the market it belongs to (storefront, selling company, gateway account, currency and exact minor-unit amount) and links to the orders it paid, so history, refunds and reconciliation can be told apart by market.
- **(3) Exists now in code (main).** payment_requests (uuid, payment_amount, currency_code, payment_method, additional_data JSON, transaction_id, is_paid, hooks); schema only in the installed SQL dump, and a code comment says it has no index or primary key (UNVERIFIED). One row can cover several seller orders; the link is the text orders.transaction_ref = payment_requests.transaction_id. MyFatoorah adds attempts, webhook events and refunds tables with DECIMAL(24,2).
- **(4) Done in the draft PRs.** Add: gcc/p1-storefront-currency@25f83fe5 (no PR, no CI): slice 2 records storefront_country, currency_code and currency_rate on orders (migration 2026_11_26_100000, OrderStorefrontSnapshot); slice 3 refuses a KWD, BHD or OMR payment to any gateway not in gcc.storefronts.currency.verified_three_decimal_gateways, so such a payment cannot start today. Criterion 4 (minor-unit storage and comparison) stays MISSING.
- **(5) What remains.** Selling company and gateway account on the record (D2-07); currency exponent and minor-unit amounts for KWD, BHD, OMR (decimal(24,2) and the x100 code assume two decimals); link by order id; one attempt model for all gateways; indexes on payment_requests; additive migration after the real schema is read; backfill rule for old Saudi rows.
- **(6) PRs and commits.** [lavkoo#53](https://github.com/fullstackfull/lavkoo/pull/53) `7e32bba`; [lavkoo#56](https://github.com/fullstackfull/lavkoo/pull/56) `f2e6f2c`
- **(7) Tests.**
  - `ci` StorefrontPaymentCallbackTest and PaymentStorefrontTest: storefront recorded with the payment (PR 53 head 7e32bba: 'Storefront tests, MySQL 8', 'Storefront tests, MariaDB 11.4.2', SQLite 8.2/8.3; PR 83 head 8ff3410 the same)
  - `none` PaymentsPanel findings (no test file mentions PaymentsPanel)
- **(8) Dependencies and blockers.** Real payment_requests schema (SQL dump); D2-07; GCC-05 slice 4 for minor units; D1 order snapshot.
- **(9) Acceptance criteria.**
  - [MET] A payment started in a storefront records that storefront, also when the app starts it with X-Storefront (StorefrontPaymentCallbackTest::test_a_payment_started_in_a_storefront_records_it; PR 53@7e32bba Storefront jobs MySQL 8, MariaDB, SQLite)
  - [MET] While storefronts are off nothing new is recorded, so Saudi rows stay as they are (StorefrontPaymentCallbackTest::test_a_payment_started_while_storefronts_are_off_records_no_storefront; same jobs)
  - [MISSING] A payment names its selling company and gateway account (no such field on any branch)
  - [MISSING] Amounts are stored and compared in each currency's minor unit (three decimals for KWD, BHD, OMR) (Money x100, Paymob x100, DECIMAL(24,2))
  - [CODED] A paid payment with no order is detected and listed for an operator (PaymentsPanel::capturedWithoutOrder (read-only, no test) and the critical log of PR 56)
- **(10) Next coding task.** On PR 53's branch: record legal entity and gateway account next to the storefront in additional_data (no migration) behind the same flag, tests in tests/Feature/Storefront; then draft additive columns once the real payment_requests schema is read from the installed SQL dump.
- **Open owner decisions.** Columns on payment_requests (needs the real schema) or keep additional_data; entity identity (Q-10).
- **Verifier note (not applied automatically).** done_in_drafts corrected by the verifier (applied above).
- **Update after the assessment.** PR 87 (GCC-05 slice 3): the payment request carries the storefront currency and the refusal is decided before the row exists; the row still carries no market or company.

#### D2-11. Redirect verification: the customer's return is confirmed with the provider

- **State: IN PROGRESS** | plan lines 309 | Discovery E10 s2.4; R-071; SEC-E10-01 (restricted annex) | reviews: security, finance
- **(2) Functional requirement.** A customer's return from a gateway proves nothing by itself: the server confirms the payment with the provider and matches reference, amount and currency to the stored payment before settling, and an unknown outcome never runs the failure hook.
- **(3) Exists now in code (main).** Paymob: the return is verified by signature and success flag; the remaining controls of this path are assessed in the restricted annex (SEC-E10-01) and are not described in public text. Criterion 4 text: 'Paymob: a return is settled only after the provider confirms it (owner decision R-071)'. D2-01 remaining item 2: 'Provider-side confirmation (D2-11)'. D2-12: describe the sandbox waiver only as 'signature checks are not waivable on a live account'.
- **(4) Done in the draft PRs.** PR 7 (Paymera): the status is re-checked with the provider before any hook; pending, rejected and unreachable answers run no hook (tests).
- **(5) What remains.** Paymob: provider-side confirmation plus amount, currency and reference match and settle-once; the same for each gateway kept for GCC; the app resolves every gateway through a server status endpoint; the shared hook does not compare the order total with the amount paid (PR 56 left it to the owner). App tests for MyFatoorah status exist but are not in the app CI job.
- **(6) PRs and commits.** [lavkoo#7](https://github.com/fullstackfull/lavkoo/pull/7) `ad09d6c`
- **(7) Tests.**
  - `ci` MyFatoorah verification: amount, currency, reference, invoice, pending and unknown outcomes (PaymentFlowTest 44) (PR 83 head 8ff3410: myfatoorah jobs on MySQL 8, MariaDB, SQLite)
  - `ci` PaymeraCallbackTest: pending, rejected and unreachable status run no hook (PR 7 head ad09d6c: 'Tests, SQLite (PHP 8.2)' and '(PHP 8.3)')
- **(8) Dependencies and blockers.** D2-01 characterisation first; owner decision on the amount-mismatch policy; a Paymob sandbox only for a live check.
- **(9) Acceptance criteria.**
  - [MET] MyFatoorah: a callback never settles from its URL alone; a paid payment with another amount, currency, reference or invoice is flagged and not settled (PaymentFlowTest::test_a_payment_for_a_different_amount_is_flagged_and_never_paid and the currency, reference and invoice tests; PR 83@8ff3410 myfatoorah jobs)
  - [MET] MyFatoorah: an unknown or pending outcome shows the pending page and runs no failure hook (PaymentFlowTest::test_a_network_failure_is_unknown_and_never_a_failure, ::test_a_pending_payment_stays_pending_and_runs_no_hook; same jobs)
  - [MET] Paymera: a pending, rejected or unreachable status answer runs no hook and marks nothing paid (PaymeraCallbackTest::test_a_pending_status_runs_no_hook_and_marks_nothing_paid and two siblings; PR 7@ad09d6c SQLite 8.2/8.3)
  - [MISSING] Paymob: a return is accepted only after the provider confirms it and amount, currency and reference match the stored payment (PaymobController::callback has no confirmation call or amount match)
  - [CODED] The customer app resolves the outcome from the server status endpoint, not from the redirect URL (MyFatoorah only (lavkooapp main myfatoorah_payment_service.dart); test/myfatoorah_*.dart exist but are not in the app CI job)
  - [MISSING] A settlement refuses or flags an order total that differs from the amount paid (PR 56 body: the hook does not compare (paid 275, order 375 reproduced))
- **(10) Next coding task.** Paymob: add PaymobVerification (transaction inquiry; amount_cents, currency and order reference matched against payment_requests) used by PaymobController::callback behind a config switch default off, with a partial-mock test of cURL(); run on SQLite, MySQL 8 and MariaDB.
- **Open owner decisions.** Whether a settlement whose order total differs from the paid amount is refused or flagged (PR 56 left it to the owner); whether Paymob needs the confirmation call (R-071).
- **Verifier note (not applied automatically).** exists_now corrected by the verifier (applied above).

#### D2-12. Webhook verification: only authentic notifications, each processed once

- **State: IN PROGRESS** | plan lines 309 | Discovery E10 s2.4 and s2.7; R-071 | reviews: security
- **(2) Functional requirement.** Only authentic gateway notifications are processed; an authentic one is still confirmed by reading the payment from the provider; each delivery is processed once, and unknown events or payments are harmless.
- **(3) Exists now in code (main).** MyFatoorah only: HMAC-SHA256 signature compared with hash_equals, one flat 401 for every refusal, sandbox-only waiver, 64 KB body cap, optional IP allow-list, throttle 600 a minute, dedupe key written before processing, body not stored, event type 1 only (other types cannot be authenticated and are refused), then a server-to-server read. Paymob has no notification route; Paymera has a trigger that re-checks status.
- **(4) Done in the draft PRs.** PR 7 removes Paymera's payload dump from its callback and logs a failing hook by class and location only.
- **(5) What remains.** A notification path or recovery sweep for Paymob (owner decision); handling of MyFatoorah refund-status events (event types other than 1 are refused today); per-account verification (D2-09); BNPL webhooks.
- **(6) PRs and commits.** [lavkoo#7](https://github.com/fullstackfull/lavkoo/pull/7) `ad09d6c`
- **(7) Tests.**
  - `ci` SettlementAndWebhookTest (61), WebhookSignatureTest (10), MyFatoorahConcurrencyTest (6) (PR 83 head 8ff3410: myfatoorah and concurrency jobs on MySQL 8 and MariaDB, SQLite 8.2/8.3)
  - `ci` PaymeraCallbackTest (12) (PR 7 head ad09d6c: SQLite 8.2/8.3)
- **(8) Dependencies and blockers.** Owner decision R-071 for Paymob; D2-09 for per-account scope.
- **(9) Acceptance criteria.**
  - [MET] A MyFatoorah webhook with a missing, wrong or tampered signature, or an event type that cannot be authenticated, is refused with the same 401 and settles nothing (SettlementAndWebhookTest::test_a_webhook_with_a_wrong_signature_is_refused and siblings; PR 83@8ff3410 myfatoorah jobs)
  - [MET] The same webhook delivered ten times, or six deliveries at once, creates one order (test_the_same_webhook_ten_times_creates_one_order; MyFatoorahConcurrencyTest::test_six_simultaneous_deliveries_of_one_payment_create_one_order; concurrency jobs MySQL 8 and MariaDB)
  - [MET] A signed webhook for another amount, an abandoned invoice or an unknown payment settles nothing (test_a_webhook_that_reports_a_different_amount_is_not_settled, test_a_webhook_for_an_abandoned_invoice_creates_no_order; same jobs)
  - [MET] The unsigned-delivery waiver does nothing on a live account (test_the_unsigned_escape_hatch_does_nothing_on_a_live_account; same jobs)
  - [MISSING] A Paymob payment can be confirmed without the customer's browser (notification or sweep) (no notification route or sweep for Paymob)
- **(10) Next coding task.** With the owner's answer on R-071, build a Paymob recovery sweep (read-only inquiry for payments paid at Paymob but not settled) behind a switch default off, modelled on MyFatoorah ReconciliationService, with fixtures; test on SQLite and MySQL 8.
- **Open owner decisions.** R-071: server-to-server notification or sweep for Paymob, or keep the browser return only.

#### D2-13. Idempotent settlement: one payment, one order, for the payer it names

- **State: IMPLEMENTED IN DRAFT** | plan lines 309, 505 | Guardrail 1; Discovery E10 s2.2; PR 53 and PR 56 conflict (OWNER_PR_GUIDE section 7) | reviews: security, finance
- **(2) Functional requirement.** One payment is settled exactly once for the payer it names, whichever of redirect, webhook, recovery or replay runs first and in whatever order in one process; a replay builds no second order even if the cart was refilled.
- **(3) Exists now in code (main).** MyFatoorah settlement: row lock, conditional is_paid=0, hook lease with fencing token, orders.transaction_ref check, unique attempt keys; tested. The shared hook digital_payment_success on main builds the order from whoever the request says is paying and has no replay guard (both are fixed by PR 56).
- **(4) Done in the draft PRs.** Add: a combined tree exists on branch gcc/release/security-gcc-integration-ci@f6fb03af (no pull request, no CI record in the pack, no PaymentReplayInStorefrontTest): digital_payment_success is the PaymentStorefront::within wrapper and digital_payment_place_orders starts with PaymentSettlementScope::hasOrderFor. Criterion 4 stays CODED and the state stays IMPLEMENTED IN DRAFT until a CI run exists.
- **(5) What remains.** Build the combined PR 53 + PR 56 tree (resolution is written in the guide and was checked on a scratch merge only, per the PR body) and run it on SQLite, MySQL 8 and MariaDB; a replayed Kuwait payment test there; amount-versus-order-total policy (D2-11); conditional update in Paymob. Capped at IMPLEMENTED IN DRAFT while module-helper.php is touched by both sets.
- **(6) PRs and commits.** [lavkoo#56](https://github.com/fullstackfull/lavkoo/pull/56) `f2e6f2c`; [lavkoo#53](https://github.com/fullstackfull/lavkoo/pull/53) `7e32bba`; [lavkoo#75](https://github.com/fullstackfull/lavkoo/pull/75) `47b78ab`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`
- **(7) Tests.**
  - `ci` SettlementsInOneProcessTest (15 tests, 19 cases) plus the Address OrderPaymentCallbackTest change (PR 56 head f2e6f2c and PR 75 head 47b78ab: 'Tests, SQLite (PHP 8.2)' and '(PHP 8.3)' only; not in a MySQL or MariaDB group)
  - `ci` MyFatoorah settlement and concurrency tests (PR 83 head 8ff3410: myfatoorah and concurrency jobs on MySQL 8 and MariaDB)
  - `pr-body` PR 53 + PR 56 scratch merge: 50 payment, callback and settlement tests pass (PR 53 body and PR 56 body; no CI tree)
- **(8) Dependencies and blockers.** Combined tree does not exist (files touched by both sets include app/Utils/module-helper.php, Web/WebController.php, routes/web/routes.php); task B builds it.
- **(9) Acceptance criteria.**
  - [MET] Settling one MyFatoorah payment five times, receiving its webhook ten times or six deliveries at once creates one order (SettlementAndWebhookTest::test_settling_the_same_payment_five_times_creates_one_order and siblings; PR 83@8ff3410 myfatoorah and concurrency jobs)
  - [MET] A paid callback delivered again after the cart was refilled builds no second order (shared hook, any gateway) (SettlementsInOneProcessTest::test_the_same_paid_callback_delivered_again_builds_no_second_order_even_when_the_cart_was_refilled; PR 56@f2e6f2c and PR 75@47b78ab, SQLite only)
  - [MET] A settlement is for the payer the stored payment names, not for the browser or session that delivers it (SettlementsInOneProcessTest (guest, registered, bystander, query-string cases); same SQLite jobs)
  - [CODED] A Kuwait payment replayed in the combined tree builds one order in the Kuwait storefront (PR 53 wrapper and PR 56 guard resolved on a scratch merge only (PR bodies))
- **(10) Next coding task.** Start a CI run on gcc/release/security-gcc-integration-ci@f6fb03af (draft PR or workflow_dispatch; ci.yml has no push trigger for gcc/** branches), add tests/Feature/Storefront/PaymentReplayInStorefrontTest.php (a Kuwait payment delivered twice gives one order in the Kuwait storefront), and run it on SQLite, MySQL 8 and MariaDB.
- **Open owner decisions.** Merge order and who resolves the PR 53 and PR 56 conflict (documented in the guide); the amount-mismatch policy (D2-11).
- **Verifier note (not applied automatically).** done_in_drafts corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** next_task corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** refs: Puts the PR 53 and PR 56 conflict in OWNER_PR_GUIDE section 7; section 7 is the owner decisions, the conflict row is in section 8. Proposed: Guardrail 1; Discovery E10 s2.2; PR 53 and PR 56 conflict (OWNER_PR_GUIDE section 8, row '#56 and #53')

#### D2-14. A payment is finished, and the customer returns, in the storefront it started in

- **State: IN PROGRESS** | plan lines 309 | GCC-02/03 country context; GCC-12; GCC-13; STOREFRONT_FLAG_RUNBOOK | reviews: security, finance
- **(2) Functional requirement.** A payment started in one storefront is finished in that storefront whatever request the gateway's callback arrives as, even if the storefront was switched off or removed since, and the customer returns to a page of that storefront.
- **(3) Exists now in code (main).** Nothing: main has no storefront context, so a callback is served as the default storefront.
- **(4) Done in the draft PRs.** PR 53 (stack #46 to #50, flag GCC_STOREFRONTS_ENABLED default off): Payment::generate_link records the storefront; digital_payment_success binds it with PaymentStorefront::within and restores the request's own afterwards, also on a throw; a switched-off or removed country is still bound; runbook with the kill switch (deactivate the country, never turn the flag off). The customer app sends X-Storefront (lavkooapp PR 1) and refuses payments on a browse-only storefront (lavkooapp PR 5).
- **(5) What remains.** The return pages (payment-success, payment-fail, web-payment) and external_redirect_link are unprefixed routes served as the default storefront; wallet top-up and due-payment hooks are not wrapped (by design); combine with PR 56 (D2-13); no test settles a MyFatoorah or Paymob payment with storefronts on (the tests use the generic hook).
- **(6) PRs and commits.** [lavkoo#53](https://github.com/fullstackfull/lavkoo/pull/53) `7e32bba`; [lavkoo#42](https://github.com/fullstackfull/lavkoo/pull/42) `2534953`; [lavkoo#49](https://github.com/fullstackfull/lavkoo/pull/49) `b52d9b2`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`; [lavkooapp#1](https://github.com/fullstackfull/lavkooapp/pull/1) `2a8c84c`; [lavkooapp#5](https://github.com/fullstackfull/lavkooapp/pull/5) `5526c4b`
- **(7) Tests.**
  - `ci` StorefrontPaymentCallbackTest and PaymentStorefrontTest (14 and 16 cases per the PR body) (PR 53 head 7e32bba and PR 83 head 8ff3410: 'Storefront tests, MySQL 8', 'Storefront tests, MariaDB 11.4.2', SQLite 8.2/8.3)
  - `ci` StorefrontSalesRoutesTest: due payments and the status check stay open for a storefront that may not sell (PR 83 head 8ff3410: Storefront jobs on MySQL 8 and MariaDB)
- **(8) Dependencies and blockers.** PR 53's stack (#8, #19, #28, #37, #38, #39, #42, #43, #47, #48, #49, #50) merges first; the website has no storefront picker; combine with PR 56.
- **(9) Acceptance criteria.**
  - [MET] A paid Kuwait cart becomes an order although the callback names no storefront (both callback shapes) (StorefrontPaymentCallbackTest::test_a_paid_kuwait_cart_becomes_an_order_although_the_callback_names_no_storefront; PR 53@7e32bba and PR 83@8ff3410 Storefront jobs)
  - [MET] A payment started in a storefront switched off or removed from the configuration since is still finished there and never takes the customer's Saudi cart (StorefrontPaymentCallbackTest (switched off, removed country); same jobs)
  - [MET] The request's own storefront is restored after the order is built, also when the build throws (PaymentStorefrontTest::test_the_requests_own_storefront_is_put_back_when_the_callback_fails; same jobs)
  - [MET] A payment started by the app with X-Storefront records the storefront, and none is recorded while storefronts are off (StorefrontPaymentCallbackTest (records / records no storefront); same jobs)
  - [MET] Due payments on placed orders and the payment status check stay open for a storefront that may not sell (StorefrontSalesRoutesTest::test_refunds_cancellations_callbacks_and_obligations_on_placed_orders_stay_open; PR 83@8ff3410 Storefront jobs)
  - [MISSING] After the gateway the customer lands on the success or fail page of the storefront they paid in (payment-success and payment-fail are unprefixed routes; external_redirect_link comes from the request)
- **(10) Next coding task.** On PR 53's branch: make Processor::payment_response and the web external_redirect_link carry the recorded storefront (path prefix or ?storefront=) while storefronts are on; add StorefrontPaymentLandingTest (a Kuwait payment lands on the Kuwait page, off means unchanged); then a MyFatoorah settlement test with storefronts on.
- **Open owner decisions.** URL form of the return pages (path prefix on one domain or a query parameter); accept the kill-switch rule 'deactivate the country, never turn the flag off'.
- **Verifier note (not applied automatically).** plan_lines: Lists only 309; the requirement is the payment half of GCC-12 (line 457) and of store-context resolution for webhooks and queue jobs (lines 485-487), as the cross_axis note for line 457 says. Proposed: 309, 457, 485-487

#### D2-15. Refunds through the original account and currency, linked to the order

- **State: IN PROGRESS** | plan lines 309, 505 | R-073; GCC-13; Discovery E10 s2.11; plan line 287 is D1 | reviews: finance, security, owner-decision
- **(2) Functional requirement.** A refund goes back through the gateway account and in the currency that took the payment, is idempotent, never exceeds what was paid, is linked to the order and the refund request, and its execution is recorded apart from the decision.
- **(3) Exists now in code (main).** MyFatoorah RefundService: admin action on a paid attempt, headroom summed under a row lock, idempotency key, statuses requested, pending, executed, rejected, failed, executed only after an operator confirms in the portal (RefundAndAdminTest, 21 tests). It is not linked to refund_requests or orders (its only caller passes neither). The store refund path records text and credits the wallet only for customer_wallet; no other gateway has a refund call.
- **(4) Done in the draft PRs.** Who may decide a refund: PR 20 scopes the seller API refund lookups to the seller's own order lines; PR 27 refuses rejected, pending or suspended seller tokens on v2 refunds; PR 26 and PR 15 pin permission gates and sequential guards as they are (race tests skipped pending owner decisions). None changes how money is returned.
- **(5) What remains.** Link the gateway refund to refund_requests and order_id and to the vendor and commission ledger; refund through the original account and currency (D2-07 and the order's currency snapshot, D1); refunds for Paymob or BNPL only after provider confirmation; concurrency guard for simultaneous refund decisions; staff country scope on gateway refund actions.
- **(6) PRs and commits.** [lavkoo#15](https://github.com/fullstackfull/lavkoo/pull/15) `59133c3`; [lavkoo#20](https://github.com/fullstackfull/lavkoo/pull/20) `75afe85`; [lavkoo#26](https://github.com/fullstackfull/lavkoo/pull/26) `d65a7f5`; [lavkoo#27](https://github.com/fullstackfull/lavkoo/pull/27) `df1c778`
- **(7) Tests.**
  - `ci` RefundAndAdminTest (21): headroom, idempotency, unknown outcome, confirmation (PR 83 head 8ff3410: myfatoorah jobs on MySQL 8, MariaDB, SQLite)
  - `ci` NonApprovedSellerCannotDecideRefundsThroughApiV2Test (PR 27 head df1c778: 'Tests, SQLite (PHP 8.2)' and '(PHP 8.3)')
  - `ci` RefundSequentialGuardCharacterizationTest (7): second refunded status and resubmitted amount rejected (PR 15 head 59133c3: SQLite 8.2/8.3; its 4 race tests are skipped)
- **(8) Dependencies and blockers.** D1 order currency and company snapshot (branch gcc/p1-storefront-currency, no PR); D2-07 accounts; owner decisions on the refund races (docs/wallet/refund-loyalty-idempotency-proposals.md on PR 15).
- **(9) Acceptance criteria.**
  - [MET] A MyFatoorah refund never exceeds the paid amount (pending requests count) and the same decision submitted twice refunds once (RefundAndAdminTest::test_partial_refunds_may_add_up_to_the_paid_amount_and_no_further, ::test_the_same_refund_decision_submitted_twice_refunds_once; PR 83@8ff3410 myfatoorah jobs)
  - [MET] A refund whose outcome is unknown stays requested so it is not sent twice, and becomes executed only after an operator confirms (RefundAndAdminTest::test_a_refund_whose_outcome_is_unknown_stays_requested_so_it_is_not_sent_twice and ::test_a_refund_is_only_executed_when_someone_confirms_it; same jobs)
  - [MET] The store refund path rejects a second refunded status and a resubmitted return amount (sequential guard) (RefundSequentialGuardCharacterizationTest on PR 15@59133c3, SQLite 8.2/8.3 (pins current behaviour))
  - [MET] A rejected, pending or suspended seller token cannot decide a refund through API v2 (NonApprovedSellerCannotDecideRefundsThroughApiV2Test on PR 27@df1c778, SQLite 8.2/8.3)
  - [MISSING] A gateway refund is linked to its refund request and order and changes the vendor and commission ledger once (SettingsController::refund passes no refund request or order id)
  - [MISSING] A refund is sent through the gateway account and currency that took the payment (refund reads the attempt's mode only; no account or order currency)
- **(10) Next coding task.** Branch from main: pass refundRequestId and orderId from the admin refund screen into RefundService::request, show the gateway refund on the refund request page, and test that approving a refund request for a MyFatoorah-paid order requests one refund of the right amount (tests/Feature/MyFatoorah); the wallet path stays unchanged.
- **Open owner decisions.** Who approves and who executes gateway refunds, manual or automatic; whether a refund must link to refund_requests (R-073); the refund race decisions in the PR 15 proposals.

#### D2-16. Payment reconciliation per market and company

- **State: IN PROGRESS** | plan lines 309, 505 | Discovery E10 s2.11; R-071; GCC-20 monitor wish list | reviews: finance
- **(2) Functional requirement.** Payments are reconciled per market and company: gateway records against payment rows against orders, lost callbacks recovered, mismatches and duplicates listed, and gateway statements compared with what the store recorded.
- **(3) Exists now in code (main).** MyFatoorah only: myfatoorah:reconcile sweeps attempts in flight for 72 hours (batch 50), settles paid ones through the webhook path and reports mismatched, stuck and in-doubt; scheduled every 10 minutes only when MYFATOORAH_ENABLED and MYFATOORAH_SCHEDULE_ENABLED are on (default off). PaymentsPanel (read-only, 500-order sample) lists captured-without-order, amount mismatch, duplicate capture, refund-without-payment: no test. PaymentFeeContributor estimates the gateway fee from a contract.
- **(4) Done in the draft PRs.** Nothing on payment reconciliation.
- **(5) What remains.** A recovery sweep for Paymob (D2-12); statement or settlement-file import per gateway account and a read-only comparison command; grouping by company and currency; tests for PaymentsPanel; alert when a paid payment has no order; the platform admin API of GCC-20 has no payment data.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` Reconciliation tests in SettlementAndWebhookTest (recovery, no double settle, pending left alone, kill switch, command) (PR 83 head 8ff3410: myfatoorah jobs on MySQL 8, MariaDB, SQLite)
  - `none` PaymentsPanel integrity findings (no test file mentions PaymentsPanel)
- **(8) Dependencies and blockers.** D2-07 accounts for per-company grouping; the settlement files each provider supplies (owner); D2-12 for Paymob.
- **(9) Acceptance criteria.**
  - [MET] A MyFatoorah payment nothing else reported is found and settled once by the sweep, and a payment already settled by a webhook is left alone (SettlementAndWebhookTest::test_reconciliation_recovers_a_payment_nothing_told_us_about, ::test_reconciliation_after_a_webhook_already_settled_does_nothing_more; PR 83@8ff3410 myfatoorah jobs)
  - [MET] The sweep does nothing and says so while the gateway is switched off (SettlementAndWebhookTest::test_reconciliation_does_nothing_while_the_kill_switch_is_off_and_says_so; same jobs)
  - [CODED] The integrity panel lists paid-without-order, amount mismatch, duplicate capture and refund-without-payment (PaymentsPanel on main; no test)
  - [MISSING] Gateway statements are compared with recorded payments per company and currency (no statement or settlement-file code on any branch)
  - [MISSING] A Paymob payment paid at the provider but never settled is found and settled by a sweep (no Paymob sweep)
- **(10) Next coding task.** Add tests/Feature/Monitoring/PaymentsPanelTest.php with a fixture per finding (no panel test exists), then a read-only GatewayStatementCheck command that compares a settlement CSV fixture with payment rows for one account.
- **Open owner decisions.** Which settlement file or API each provider supplies and who in finance reconciles it; retention.
- **Verifier note (not applied automatically).** ac[1].t: Says the sweep does nothing while the gateway is switched off. ReconciliationService::sweep skips only when GatewayAvailability::verificationAllowed() is false, i.e. MYFATOORAH_ENABLED off AND MYFATOORAH_VERIFY_WHEN_DISABLED off; that setting defaults to true, so by default the sweep keeps verifying payments already in flight after the gateway is switched off (by design). The cited test sets both switches. Proposed: The sweep does nothing and says so while MyFatoorah calls are switched off for the deployment (MYFATOORAH_ENABLED off and MYFATOORAH_VERIFY_WHEN_DISABLED off); with the default verify-when-disabled on it still verifies payments in flight.

#### D2-17. Payment security constraints: logs, hosts, rate limits, ownership

- **State: IN PROGRESS** | plan lines 309 | SEC-E10-* (restricted annex); DR-1 (referenced only); charter s5 | reviews: security
- **(2) Functional requirement.** Payment code writes no secret or personal payload to logs or files, calls only allow-listed hosts, rate-limits its public endpoints, and lets a customer, guest or seller act only on payments and orders of their own.
- **(3) Exists now in code (main).** MyFatoorah: host allow-list so a stored host cannot redirect the API key, no redirects, no retry on POST, webhook and status throttles, secrets encrypted, webhook body not stored. The shared success hook still appends customer id, guest id, address id and order ids to storage/logs/order_generate_test.txt on every paid order. Gateway admin screens sit behind module:3rd_party_setup.
- **(4) Done in the draft PRs.** PR 7: Paymera secret from configuration, no callback or hook dumps. PR 21 and PR 22: API and web due payments (wallet, offline, COD, digital) act only for the order's owner or its verified guest or tracking session. PR 56: the payer comes only from the stored payment. PR 63: gateway logo upload validated. PR 7, 21 and 22 are in neither integration tree; PR 56 and 63 are in PR 75 only.
- **(5) What remains.** Remove the order_generate_test.txt write (PR 56 and PR 53 only keep tests from leaving the file); apply PR 7, 21, 22 in the combined tree; audit the remaining legacy gateways' callbacks (restricted annex); guest-id guessability (PR 21 decision 1); review by the payments owner and a security reviewer before any merge.
- **(6) PRs and commits.** [lavkoo#7](https://github.com/fullstackfull/lavkoo/pull/7) `ad09d6c`; [lavkoo#21](https://github.com/fullstackfull/lavkoo/pull/21) `af862ff`; [lavkoo#22](https://github.com/fullstackfull/lavkoo/pull/22) `20a99a5`; [lavkoo#56](https://github.com/fullstackfull/lavkoo/pull/56) `f2e6f2c`; [lavkoo#63](https://github.com/fullstackfull/lavkoo/pull/63) `de0a7d5`
- **(7) Tests.**
  - `ci` PaymeraCallbackTest (12), ApiDuePaymentOwnershipTest, CustomerOrderEditPayDueAmountTest, SettlementsInOneProcessTest (PR 7 ad09d6c, PR 21 af862ff, PR 22 20a99a5, PR 56 f2e6f2c: 'Tests, SQLite (PHP 8.2)' and '(PHP 8.3)')
  - `ci` MyFatoorah host and secret tests (PR 83 head 8ff3410: myfatoorah jobs on MySQL 8, MariaDB, SQLite)
- **(8) Dependencies and blockers.** Security review and the payments owner before merge (PR 3 and PR 7 bodies); DR-1 open in DEFERRED_RISKS.md (referenced only); combined tree for PR 7, 21, 22 with PR 75.
- **(9) Acceptance criteria.**
  - [MET] The Paymera callback and hook write no payload to any file or log, and no secret literal is in the controller (PaymeraCallbackTest::test_the_controller_has_no_debug_writes_dumps_or_secret_literal and siblings; PR 7@ad09d6c SQLite 8.2/8.3)
  - [MET] A stranger or another customer cannot record a due payment, switch to COD or spend a wallet on someone else's order (web and API) (CustomerOrderEditPayDueAmountTest (PR 22@20a99a5) and ApiDuePaymentOwnershipTest (PR 21@af862ff), SQLite 8.2/8.3)
  - [MET] MyFatoorah calls only allow-listed hosts and a payment URL on another host is refused (RefundAndAdminTest::test_a_tampered_host_in_the_configuration_is_ignored, PaymentFlowTest::test_a_payment_url_that_is_not_a_myfatoorah_host_is_refused; PR 83@8ff3410 myfatoorah jobs)
  - [MISSING] The paid-order hook writes no customer, guest, address or order identifiers to a plain file (module-helper.php still writes storage/logs/order_generate_test.txt on main and in both integration trees)
  - [MISSING] Public payment endpoints are rate-limited (limiters myfatoorah-webhooks (600 a minute) and myfatoorah-status (90) only; no test)
- **(10) Next coding task.** Branch from the PR 56 tree: delete the order_generate_test.txt block from the success hook, add a leak-guard test that fails on file_put_contents in app/Utils/module-helper.php and the gateway controllers, and extend tests/Support/KeepsOrderDebugLogClean accordingly.
- **Open owner decisions.** Guest ids are sequential and guessable (PR 21 decision 1): add a guest token or accept; delete the old debug files only after a retention review; DR-1 stays in the owner register.

#### D2-18. Payment lifecycle (axis F) pinned end to end per market

- **State: IN PROGRESS** | plan lines 501-507 | Axis F; plan Report 10 line 669; Discovery E10 s2.2 | reviews: none
- **(2) Functional requirement.** The path checkout, payment authorisation, webhook, order confirmation, fulfilment, refund and reconciliation is documented and pinned by end-to-end tests for each market's payment route (local and cross-border), including currency, tax and the selling company.
- **(3) Exists now in code (main).** Discovery E10 section 2.2 documents the lifecycle (payment row, hook builds the order after payment with allow_oversell, transaction_ref link, status page). Tests pin pieces: MyFatoorah payment to order, the hook with the address snapshot (Address OrderPaymentCallbackTest), fulfilment guards. No test carries one payment through order, fulfilment submission and refund.
- **(4) Done in the draft PRs.** PR 53 drives digital_payment_success through the real order pipeline for a Kuwait cart with storefronts on; PR 56 does so per payer. Neither reaches fulfilment, refund or a cross-border route.
- **(5) What remains.** A lifecycle matrix test per market (Saudi baseline, Kuwait and UAE local, cross-border once D2-06 exists) through order confirmation, fulfilment submission, refund and reconciliation; tax and currency lines (needs the D1 snapshot); regulated-product refusal on cross-border orders.
- **(6) PRs and commits.** [lavkoo#53](https://github.com/fullstackfull/lavkoo/pull/53) `7e32bba`; [lavkoo#56](https://github.com/fullstackfull/lavkoo/pull/56) `f2e6f2c`
- **(7) Tests.**
  - `ci` OrderPaymentCallbackTest: a paid order is built with the payer's structured address and a frozen copy (PR 83 head 8ff3410: 'Address tests, MySQL 8', 'Address tests, MariaDB 11.4.2', SQLite 8.2/8.3)
  - `ci` StorefrontPaymentCallbackTest: a paid Kuwait cart becomes an order (PR 53 head 7e32bba: Storefront jobs on MySQL 8, MariaDB, SQLite)
- **(8) Dependencies and blockers.** D2-06 for cross-border; D1 snapshot for currency, tax and company; the combined tree (D2-13).
- **(9) Acceptance criteria.**
  - [CODED] A paid payment becomes orders in the storefront it started in, with the payer's address and a frozen copy of it (OrderPaymentCallbackTest (main) and StorefrontPaymentCallbackTest (PR 53@7e32bba); Address and Storefront jobs on MySQL 8 and MariaDB)
  - [MISSING] The same payment carries through to fulfilment submission and a refund without a second order or a second charge (no end-to-end test)
  - [MISSING] A cross-border payment uses the source company's account and currency and only eligible methods (no cross-border code (D2-06, D2-07))
- **(10) Next coding task.** In the PR 83 tree add tests/Feature/Storefront/PaymentLifecycleMatrixTest.php: Saudi and Kuwait carts paid through a faked gateway, courier mocked, admin refund, asserting one order, one shipment, one refund and the storefront's currency; extend for cross-border after D2-06.

#### D2-V1. Payment currency is the storefront's currency: no automatic conversion and no silent fallback to the...

- **State: IN PROGRESS** | plan lines 303, 507 | added by the independent verifier: the assessor of this unit omitted it | reviews: none
- **(2) Functional requirement.** Payment currency is the storefront's currency: no automatic conversion and no silent fallback to the first active currency or USD when a payment starts (currencies are a named item of axis F line 507 and of line 303; owner decision: local pricing, no auto-FX)
- **(3) Exists now in code (main).** E10-F05 (P0). On main PaymentGatewayTrait::getPaymentGatewayCurrencyCode (lines 476-499) substitutes the first active listed currency, else USD, instead of refusing. GCC-05 slice 3 on gcc/p1-storefront-currency@25f83fe5 refuses instead (GatewayCurrencyPolicy, GatewayCurrencyPolicyTest, no PR, no CI); slice 4 (prices, amounts, fixed decimals) not done. D2-05 criterion 2 and D2-10 criterion 4 cover it only indirectly; may belong to the GCC-05 unit, in which case D2-05 and D2-10 should reference it.
- **(4) Done in the draft PRs.** See the evidence note above.
- **(5) What remains.** Its acceptance criteria are not written yet; until they are, it counts as one criterion.
- **(6) PRs and commits.** none
- **(7) Tests.** none
- **(8) Dependencies and blockers.** See the evidence note.
- **(9) Acceptance criteria.**
  - [MISSING] Payment currency is the storefront's currency: no automatic conversion and no silent fallback to the first active currency or USD when a payment starts (currenc (one placeholder criterion for a requirement found by the verifier; write the real ones before relying on this line)
- **(10) Next coding task.** Write its acceptance criteria and its next coding task (added by the verifier).

#### D2-V2. Per-storefront on/off of payment methods that are not gateways (cash on delivery, offline payment, d...

- **State: NOT STARTED** | plan lines 270, 303 | added by the independent verifier: the assessor of this unit omitted it | reviews: none
- **(2) Functional requirement.** Per-storefront on/off of payment methods that are not gateways (cash on delivery, offline payment, digital payment, wallet top-up limits): today platform-wide business settings, so one market cannot be switched off without the others
- **(3) Exists now in code (main).** E10-F11 (P1). getWebConfig 'cash_on_delivery', 'digital_payment' and 'offline_payment' are read platform-wide in app/Http/Controllers/Admin/ThirdParty/PaymentMethodController.php:94-96 and app/Http/Controllers/RestAPI/v1/ConfigController.php:159-160; no storefront-level switch exists in app/Services/Storefront or config/gcc.php on origin/gcc/release/gcc-integration-ci or gcc/p1-storefront-currency. D2-05 and D2-06 cover gateway and rule-based eligibility only.
- **(4) Done in the draft PRs.** See the evidence note above.
- **(5) What remains.** Its acceptance criteria are not written yet; until they are, it counts as one criterion.
- **(6) PRs and commits.** none
- **(7) Tests.** none
- **(8) Dependencies and blockers.** See the evidence note.
- **(9) Acceptance criteria.**
  - [MISSING] Per-storefront on/off of payment methods that are not gateways (cash on delivery, offline payment, digital payment, wallet top-up limits): today platform-wide b (one placeholder criterion for a requirement found by the verifier; write the real ones before relying on this line)
- **(10) Next coding task.** Write its acceptance criteria and its next coding task (added by the verifier).
