# Axis C: Inventory and fulfillment

Detail cards of the master tracker ([`MASTER_IMPLEMENTATION_TRACKER.md`](../MASTER_IMPLEMENTATION_TRACKER.md)), as of 2026-11-20. Requirements: 18. Acceptance criteria: 94 (MET 65, CODED 9, MISSING 20). Code completion 79%, proven by a passing test 69%, release readiness 30%.

Independent verification: done for unit(s) C.

Every card gives the ten fields of the directive: (1) id and name, (2) functional requirement, (3) what exists in main, (4) what the draft pull requests did, (5) what remains, (6) pull requests and commits, (7) tests that exist and where they ran, (8) dependencies and blockers, (9) acceptance criteria marked MET (a named passing test or CI job on a named head), CODED (code exists, no passing test cited) or MISSING, (10) the next coding task.

#### C-01. One fulfillment provider per product in a country storefront

- **State: IN PROGRESS** | plan lines 235 | DECISIONS.md core foundation (one provider/source per product per country storefront); GCC-02/03 as used by PRs 47-53; owner question Q-33 | reviews: owner-decision
- **(2) Functional requirement.** A product row belongs to one country and holds at most one execution source (products.fulfillment_provider, NULL = in-house), changed only through the assignment service. A twin of the same SKU in another country has its own source, and a provider is assignable only where it serves the product's country.
- **(3) Exists now in code (main).** main: one nullable column products.fulfillment_provider (migration 2026_11_05_100100), not mass-assignable, written only by ProductProviderAssignmentService (admin-owned physical products, approved mapping per variant, audit row in product_provider_changes). No country on the product or on the provider connection.
- **(4) Done in the draft PRs.** #47 (GCC_STOREFRONTS_ENABLED, off): products.storefront_country, so the source on a row is that country's. #48: CheckoutCodGuard reads only the storefront's cart rows. #72/#76 (GCC_COUNTRY_SCOPED_CODES, off): Torod/M5AZN suggestions, pickers and the M5AZN importer act on the default storefront's products only. All are in the #83 tree.
- **(5) What remains.** (1) refuse a provider whose connection does not serve the product's country (connections have no country); (2) a test that two twins keep different providers; (3) show and filter the source in the admin product list; (4) Torod matching and M5AZN import for non-default countries (today default storefront only).
- **(6) PRs and commits.** [lavkoo#47](https://github.com/fullstackfull/lavkoo/pull/47) `e0dd5dc`; [lavkoo#48](https://github.com/fullstackfull/lavkoo/pull/48) `4a821d3`; [lavkoo#64](https://github.com/fullstackfull/lavkoo/pull/64) `216c279`; [lavkoo#72](https://github.com/fullstackfull/lavkoo/pull/72) `1c779d2`; [lavkoo#76](https://github.com/fullstackfull/lavkoo/pull/76) `0ad49d2`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`
- **(7) Tests.**
  - `ci` ProductAssignmentTest: mapping required, vendor/digital refused, column not mass-assignable (main tests, green on PR 1 head d664ab4 (main + one test line): Fulfillment + security MySQL 8 and MariaDB 11.4.2, Tests SQLite 8.2/8.3)
  - `ci` StorefrontCartIsolationTest: the COD guard judges only the storefront's cart rows (m5azn product in the AE basket) (PR 83 8ff3410: Storefront tests MySQL 8 and MariaDB 11.4.2, Tests SQLite 8.2/8.3)
  - `ci` ProviderMatchingCountryTest: Torod suggestions and picker narrowed to the default storefront (PR 83 8ff3410: Catalog tests MySQL 8 and MariaDB 11.4.2)
  - `none` No test that two twins hold different providers; none for refusing a provider of the wrong country (-)
- **(8) Dependencies and blockers.** Owner answer Q-33 (which countries Torod Point, M5AZN and LogesTechs serve, contracts, company ids: UNKNOWN). Needs #47/#64 (country on the row) and #72 (country-scoped codes). Product.php and CartManager.php are changed by both the security set (#75) and the GCC chain (#83); no combined tree exists.
- **(9) Acceptance criteria.**
  - [MET] The provider column cannot be mass-assigned and changes only through the assignment service, which refuses vendor and digital products and a provider without an approved mapping for every variant (ProductAssignmentTest (mapping, vendor/digital, mass assignment); PR 1 d664ab4, Fulfillment + security MySQL 8/MariaDB)
  - [MET] The cash-on-delivery guard judges only the cart rows of the storefront making the request (StorefrontCartIsolationTest::test_the_cash_on_delivery_guard_judges_only_the_storefronts_rows; PR 83, Storefront tests MySQL 8/MariaDB)
  - [MET] With country-scoped codes on, a provider's catalogue items are suggested and picked only among the default storefront's products, never a twin of another country (ProviderMatchingCountryTest; PR 83, Catalog tests MySQL 8/MariaDB 11.4.2)
  - [CODED] Two twins of one SKU in two countries can hold different providers independently (row-per-country (#47) plus a single writer; no test)
  - [MISSING] Assigning a provider whose connection does not serve the product's country is refused with a named reason (ProductProviderAssignmentService::refusal has no country check; #72 body: provider connections have no country)
- **(10) Next coding task.** New branch stacked on gcc/p1-country-scoped-codes-2 (#76): config fulfillment.providers.<code>.countries (default ['SA']) read by ProductProviderAssignmentService::refusal and ProviderMappingService::suggestionsFor; add tests/Feature/Fulfillment/ProductAssignmentCountryTest (KW product refused for torod_point, SA allowed, twins keep own provider, switch off = today's answer) and run it in the Fulfillment MySQL group.
- **Open owner decisions.** Q-33: written statement per provider and market of where it can serve, before any non-Saudi assignment is allowed.
- **Verifier note (not applied automatically).** tests[0].where: 'main + one test line' misdescribes PR 1's head d664ab4 (the same phrase is in coverage_gaps). Besides the one-line Torod fixture fix it adds tests/Unit/Marketplace/PaymentRoutingWithoutRulesTest.php (78 lines), a CLAUDE.md note and docs/gcc/PHASE0-B-SAUDI-REGRESSION-CI-AUTHORIZATION.md, and it lacks main's three later documentation commits (c5c04b7b). git diff of app, database, config, routes, resources, .github, composer files and phpunit.xml between the two is empty. Proposed: main tests, green on PR 1 head d664ab4 (main's code unchanged; adds the Torod fixture line and tests/Unit/Marketplace/PaymentRoutingWithoutRulesTest.php; lacks main's three later documentation commits): Fulfillment + security MySQL 8 and MariaDB 11.4.2, Tests SQLite 8.2/8.3

#### C-02. No automatic fallback between fulfillment providers

- **State: MERGED** | plan lines 237 | DECISIONS.md core foundation (no automatic fallback to another provider); E11-F06 | reviews: none
- **(2) Functional requirement.** When a provider cannot cover a line (stock too low, preflight hold, failure), the line and its product stay with that provider: nothing is re-routed to another provider or to in-house. The order waits in a named hold or goes to a person.
- **(3) Exists now in code (main).** main: FulfillmentRouter groups unrouted lines strictly by the provider frozen on each line; the dispatcher takes the adapter of the shipment's own provider; known low provider stock holds with hold_stock_unavailable and the sweep retries with the same provider; DynamicFulfillmentRoutingEngine sends a channel order whose stock sits at another provider to manual_review; a product leaving a provider is set to stock 0.
- **(4) Done in the draft PRs.** No draft changes FulfillmentRouter, ProviderOrderDispatcher, the adapters or DynamicFulfillmentRoutingEngine. #72 (also in #76 and #83; switch GCC_COUNTRY_SCOPED_CODES, off by default) only narrows which SKU rule matches a line by the product's country (RoutingRuleMatcher, RoutingContext, RoutingContextFactory, RoutingLine, ProductCodeScope::skuRuleAppliesTo; tests tests/Feature/Catalog/RoutingRuleCountryTest and SixCountryRoutingRuleTest) and the provider catalogue matching and M5AZN import (ProviderMappingService, M5aznProductImporter); #48 narrows CheckoutCodGuard to the storefront's cart rows. The stock_not_at_target_provider guard and the router's per-provider grouping are untouched, and #83's CI runs the whole Fulfillment suite and sales channels N-Z green with the chain merged.
- **(5) What remains.** Nothing for the Saudi baseline. Repeat the rule for any non-Saudi provider when one exists (C-12). A literal pin (stock-out with approved mappings at both providers never creates a fulfillment at the other) is the next task.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` RouterTest and DispatcherTest: each provider receives only its own items; one provider's failure never touches the other's (PR 1 d664ab4: Fulfillment+security (MySQL 8, MariaDB), SQLite)
  - `ci` TorodAdapterTest and M5aznAdapterTest: known low stock holds, stale stock does not (PR 1 d664ab4: same jobs)
  - `ci` RoutingEngineTest: a rule naming a provider that does not hold the goods goes to manual review (PR 1 d664ab4: Sales-channel and cost tests, sales channels N-Z, MySQL 8.0 and MariaDB)
  - `ci` OwnershipSnapshotTest and ProductAssignmentTest: reassigning never moves a placed order; leaving a provider stops the sale (PR 1 d664ab4: Fulfillment + security jobs)
- **(8) Dependencies and blockers.** None for the baseline. Main alone is red on one unrelated Torod fixture test (repaired by the line #1 carries in every PR head cut from main), so CI proof is read from PR 1's head (main's code plus that line and one new unit test); the overlay job is red on every head cut from main (known tooling result).
- **(9) Acceptance criteria.**
  - [MET] Each provider receives only its own items; a line of provider A is never placed in provider B's fulfillment (RouterTest::test_each_provider_receives_only_its_own_items; DispatcherTest::test_the_provider_receives_exactly_its_own_items_and_never_another_providers; PR 1 d664ab4)
  - [MET] One provider's failure leaves the other provider's fulfillment untouched (DispatcherTest::test_one_providers_failure_never_touches_the_others_fulfillment; PR 1 d664ab4)
  - [MET] Known low provider stock holds the fulfillment (hold_stock_unavailable) and stale stock does not; a hold that clears is retried by the sweep for the same fulfillment, never re-routed (TorodAdapterTest::test_known_low_stock_holds_but_stale_stock_does_not; M5aznAdapterTest::test_stock_known_to_be_too_low_holds_the_order_but_stale_stock_does_not; DispatcherTest::test_the_dispatch_sweep_picks_up_pending_due_retries_and_fixed_holds; PR 1 d664ab4)
  - [MET] Reassigning a product never moves a placed order, and a line with a missing mapping cannot fall through to in-house (OwnershipSnapshotTest (reassign, missing mapping); PR 1 d664ab4)
  - [MET] A channel order whose stock sits at another provider than the rule's target goes to manual review, not to the other provider (RoutingEngineTest::test_a_rule_that_names_a_provider_which_does_not_hold_the_goods_does_not_send_them_there; PR 1 d664ab4, sales channels N-Z)
  - [MET] A product that leaves a provider is set to stock 0 and stops being sold until a person confirms the balance (ProductAssignmentTest::test_a_product_that_leaves_a_provider_stops_being_sold_until_its_balance_is_confirmed; PR 1 d664ab4)
- **(10) Next coding task.** Add tests/Feature/Fulfillment/NoAutomaticFallbackTest.php on a branch from main: a product on torod_point with approved mappings at both providers and provider stock 0; place an order, route, run the sweep; assert the fulfillment is held hold_stock_unavailable under torod_point, none exists for m5azn, products.fulfillment_provider is unchanged and product_provider_changes is empty.
- **Verifier note (not applied automatically).** done_in_drafts corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** deps_blockers corrected by the verifier (applied above).

#### C-03. Every product and listing tied to an explicit, visible source

- **State: IN PROGRESS** | plan lines 239 | DECISIONS.md core foundation (explicit source); E11-F05, E11-F08 | reviews: security, owner-decision
- **(2) Functional requirement.** Each product shows exactly one execution source (a provider or the in-house default), each channel listing names the stock location it takes quantity from, and nothing is guessed: no location means nothing is published, and an unknown stored source is refused rather than treated as in-house.
- **(3) Exists now in code (main).** main: product form card (default, m5azn, torod_point, with the reason a choice is unavailable); NULL = in-house, no backfill; listings resolve listing > product policy > account policy and StockListingGate refuses no_stock_source; docs/integrations/README.md names the three sources. A stored provider value the enum does not know is treated as in-house at checkout.
- **(4) Done in the draft PRs.** #64 lets the admin choose and filter a product's country (not its source). No draft adds a per-country default source, a source column or filter, or explicit handling of an unknown source.
- **(5) What remains.** (1) an unknown stored provider must be held or refused, not in-house (E11-F05); (2) in-house is an implicit NULL: no explicit per-country default source or location; (3) source column and filter in the admin product list and export; (4) one ownership map of the three location concepts warehouses, inventory_locations, provider code (E11-F08).
- **(6) PRs and commits.** [lavkoo#64](https://github.com/fullstackfull/lavkoo/pull/64) `216c279`
- **(7) Tests.**
  - `ci` ProductAssignmentTest: the form says why a provider cannot be picked; form and rule never disagree (PR 1 d664ab4: Fulfillment + security MySQL 8/MariaDB, Tests SQLite)
  - `ci` NoonPoolMembershipTest: a listing takes no stock while no location admits its channel; an inactive location is no source (PR 1 d664ab4: Sales-channel and cost tests, sales channels N-Z)
  - `ci` MigrationsTest: up adds one nullable column and changes no existing row (PR 1 d664ab4: Fulfillment + security jobs)
  - `ci` OwnershipSnapshotTest::test_an_unknown_provider_value_falls_back_to_in_house_rather_than_guessing pins the current, opposite behaviour (PR 1 d664ab4: Fulfillment + security jobs)
- **(8) Dependencies and blockers.** Unknown-value handling is the restricted audit item SEC-E11-10 (no detail here). A per-country default source needs the location-ownership decision of C-05.
- **(9) Acceptance criteria.**
  - [MET] The product form offers default and each provider and says why a choice is unavailable; the form and the rule never disagree (ProductAssignmentTest::test_the_form_says_why_a_provider_cannot_be_picked, ::test_the_form_and_the_rule_never_disagree; PR 1 d664ab4)
  - [MET] A listing with no admitted, active stock location takes no stock (NoonPoolMembershipTest (no location admits noon; inactive location); StockListingGate no_stock_source; PR 1 d664ab4, N-Z group)
  - [MET] Existing products keep NULL (in-house): the migration changes no existing row (MigrationsTest::test_up_adds_only_new_tables_and_one_nullable_column_and_changes_no_existing_row; PR 1 d664ab4)
  - [MISSING] A stored provider value the integration does not know is refused or held at checkout, never silently in-house (OwnershipSnapshotTest pins in-house today; compatibility command only warns)
  - [MISSING] Each non-Saudi country has an explicit default source or location for its in-house products (no code on any branch)
  - [MISSING] The admin product list and export show and filter each product's source next to its country (#64 shows and filters the country only)
- **(10) Next coding task.** New branch from main: make OrderItemOwnershipSnapshotter::capture refuse the line with a named reason (its transaction already rolls back on error) when fulfillment_provider is non-empty and not a ProviderCode, behind default-off fulfillment.refuse_unknown_provider; test both switch states beside OwnershipSnapshotTest; make fulfillment:compatibility fail, not warn, on unknown values.
- **Open owner decisions.** Whether an unknown stored source refuses checkout or holds the line; which default source each non-Saudi country's in-house products belong to; which of the three location concepts is the reference (E11-F08).

#### C-04. Historical execution source frozen on order lines

- **State: IN PROGRESS** | plan lines 239 | DECISIONS.md core foundation (order lines keep the chosen historical execution source); E11-F10; plan line 286 belongs to the checkout unit | reviews: finance, owner-decision
- **(2) Functional requirement.** At checkout each provider-owned line freezes who fulfils it (provider, mapping, external ids, cost) in an immutable snapshot that routing reads instead of the product; a later change of the product never moves a placed order. With several countries the snapshot also says which country and fulfillment account it was placed under, and old Saudi rows read as the default Saudi account by a written rule.
- **(3) Exists now in code (main).** main: OrderItemOwnershipSnapshotter writes provider_fulfillment_items inside the order transaction (unique order_detail_id); the model blocks later changes and deletes; the router reads only the snapshot; an order with provider lines cannot be edited. In-house lines write no row (absence = in-house). The snapshot has no country, legal entity, account or location.
- **(4) Done in the draft PRs.** Nothing on the snapshot. The runbook in #53 records that orders carry no country: it follows from the lines (order_details to products.storefront_country), and #64 fixes a product's country at creation, so a product never changes country.
- **(5) What remains.** (1) nullable storefront_country and fulfillment-account columns on provider_fulfillment_items, filled from now on, no UPDATE of old rows; (2) an explicit in-house source marker; (3) a written rule that old rows are the default Saudi account; (4) the order-level country and currency snapshot belongs to the checkout unit (GCC-05).
- **(6) PRs and commits.** [lavkoo#47](https://github.com/fullstackfull/lavkoo/pull/47) `e0dd5dc`; [lavkoo#53](https://github.com/fullstackfull/lavkoo/pull/53) `7e32bba`; [lavkoo#64](https://github.com/fullstackfull/lavkoo/pull/64) `216c279`
- **(7) Tests.**
  - `ci` OwnershipSnapshotTest: freeze with mapping and prices, immutable, never deleted, reassign later does not move a placed order (PR 1 d664ab4: Fulfillment + security MySQL 8/MariaDB, Tests SQLite)
  - `ci` OrderGuardAndStorefrontTest::test_an_order_with_provider_lines_cannot_be_edited_in_any_state (PR 1 d664ab4: same jobs)
  - `none` No test of a country or account on the snapshot (neither exists) (-)
- **(8) Dependencies and blockers.** Order-level country and currency need GCC-05 (checkout unit). The new columns touch provider_fulfillment_items only, which neither integration tree changes.
- **(9) Acceptance criteria.**
  - [MET] A provider-owned line freezes provider, mapping and external ids when the order is placed (OwnershipSnapshotTest::test_a_provider_product_is_frozen_with_its_mapping_prices_and_provider; PR 1 d664ab4)
  - [MET] The snapshot cannot be changed or deleted afterwards (routing bookkeeping excepted) (OwnershipSnapshotTest::test_the_snapshot_cannot_be_changed_afterwards, ::test_a_snapshot_is_never_deleted; PR 1 d664ab4)
  - [MET] Reassigning the product later does not move an order already placed (OwnershipSnapshotTest::test_reassigning_the_product_later_does_not_move_an_order_already_placed; ProductAssignmentTest; PR 1 d664ab4)
  - [MET] An order that holds provider lines cannot be edited, so the frozen source cannot drift from the lines (OrderGuardAndStorefrontTest::test_an_order_with_provider_lines_cannot_be_edited_in_any_state; PR 1 d664ab4)
  - [MISSING] An in-house line records an explicit source (test_an_in_house_product_writes_no_snapshot_and_costs_nothing pins the absence)
  - [MISSING] The snapshot records the storefront country and fulfillment account, and old rows read as the default Saudi account by a written rule (no code on any branch)
- **(10) Next coding task.** Branch stacked on gcc/p1-country-owned-products (#47): additive migration with down() for nullable storefront_country (CHAR(2) ascii_bin) and fulfillment_account_id on provider_fulfillment_items; OrderItemOwnershipSnapshotter fills the country from the product row while GCC_STOREFRONTS_ENABLED; tests/Feature/Fulfillment/OwnershipSnapshotCountryTest (KW line records KW, Saudi line unchanged, old rows untouched) on MySQL and MariaDB.
- **Open owner decisions.** The rule for old orders (default Saudi account, no data rewrite); whether in-house lines get an explicit row; which legal entity the snapshot is attributed to.

#### C-05. Stock ownership per storefront and country, separate from seller_id

- **State: IN PROGRESS** | plan lines 25 | DECISIONS.md (do not repurpose shop_id/seller_id; independent inventories per country); E11-F07, E11-F08, E11-F19 | reviews: security, owner-decision
- **(2) Functional requirement.** Stock belongs to one country's storefront. The store's own stock lives on a country-owned product row, and every stock location, pool and reservation says which country and legal entity it belongs to through its own column, never seller_id or shop_id.
- **(3) Exists now in code (main).** main: inventory_locations has seller_id (0 = platform), sales_channel_account_id and marketplace_id, no country; pools, reservations and outbox rows are keyed by (location, product, variant) and a global reservation_key; three unrelated location concepts (warehouses, inventory_locations, provider code) with fixed codes torod-warehouse, m5azn-supplier, own-warehouse; products.current_stock is one value per row.
- **(4) Done in the draft PRs.** #47 (off): products.storefront_country, so price and stock live on a country's own row; #48 cart guard; #53 a payment finishes in its storefront; #64 the admin chooses the country; #74/#77/#82 staff scopes. No draft adds a country or entity to inventory_locations, pools, reservations or channel_stock_events, and none touches the location, pool, reservation or outbox code.
- **(5) What remains.** (1) decide and add a nullable country/legal-entity owner to inventory_locations, read as the default storefront when empty, no rewrite; (2) refuse pool membership across countries; (3) one map of the three location concepts; (4) the own-warehouse read-through (channels:stock-use-platform-for-own-warehouse) uses global current_stock, so it fits one country per row only.
- **(6) PRs and commits.** [lavkoo#47](https://github.com/fullstackfull/lavkoo/pull/47) `e0dd5dc`; [lavkoo#48](https://github.com/fullstackfull/lavkoo/pull/48) `4a821d3`; [lavkoo#53](https://github.com/fullstackfull/lavkoo/pull/53) `7e32bba`; [lavkoo#64](https://github.com/fullstackfull/lavkoo/pull/64) `216c279`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`
- **(7) Tests.**
  - `ci` StorefrontCatalogTest::test_each_country_keeps_its_own_price_and_stock_for_the_same_product (PR 83 8ff3410: Storefront tests MySQL 8 and MariaDB 11.4.2, Tests SQLite 8.2/8.3)
  - `ci` StorefrontCartGuardTest and StorefrontCartIsolationTest: another country's product cannot be added; its price and stock are not answered (PR 83 8ff3410: same jobs)
  - `none` No test for a country on locations, pools or reservations (the column does not exist) (-)
- **(8) Dependencies and blockers.** Owner decision on the entity model (Country, Storefront, Legal Entity, Location, Fulfillment Account). CartManager.php, Product.php and the seller POS controller are changed by both #75 and #83 (five conflicting files); no combined tree, so nothing here can reach INTEGRATED AND TESTED yet.
- **(9) Acceptance criteria.**
  - [MET] Two copies of one product in two countries keep their own price and stock (StorefrontCatalogTest::test_each_country_keeps_its_own_price_and_stock_for_the_same_product; PR 83 Storefront tests MySQL 8/MariaDB, SQLite)
  - [MET] A cart cannot add, reprice or read the price and stock of another country's product (StorefrontCartGuardTest, StorefrontCartIsolationTest; PR 83 Storefront tests MySQL 8/MariaDB)
  - [CODED] A copy made for another country is a new row with its own stock (the copy-to-country tool is deferred) (#76 G9 deferred; gallery copy creates a new row; no test)
  - [MISSING] A stock location belongs to one country and legal entity through its own column, not seller_id (inventory_locations has no such column on any branch)
  - [MISSING] A channel listing of an account in country X cannot join the pool of a product of country Y (PoolMembers unchanged in #83 (E11-F19))
  - [MISSING] Existing Saudi locations, balances and reservations are read as the default storefront's by a written rule, without a data rewrite (no rule or document)
- **(10) Next coding task.** Branch stacked on #53: additive migration, nullable inventory_locations.storefront_country (CHAR(2) ascii_bin, NULL = default storefront, no backfill, down()); StockListingGate::refusal and PoolMembers::of return location_country_mismatch when the listing's product country differs while GCC_STOREFRONTS_ENABLED; tests/Feature/SalesChannels/LocationCountryTest with SA and KW twin products (A-M partition); extend StockNeverWritesPlatformTablesTest.
- **Open owner decisions.** Which entity owns a location (country, legal entity or both); the default for existing rows; which of warehouses, inventory_locations or the provider code is the reference for each purpose (E11-F08); whether the own warehouse may read the store's stock through in more than one country.

#### C-06. Inventory Locations and provider Inventory Sync retained and reused

- **State: MERGED** | plan lines 243, 245 | DECISIONS.md guardrail 1 (no rewrite of working inventory/fulfillment); E11 section 2.15 | reviews: none
- **(2) Functional requirement.** Provider stock keeps flowing through the existing locations and the single sync writer: the verified, switched-on Torod Point figure sets the stock of the products assigned to it, a typed figure is never taken for a provider's, an unusable figure never becomes zero, the sync and its outbox note are one transaction, and the own warehouse reads the store's stock through.
- **(3) Exists now in code (main).** main: InventoryLocation and InventoryLocationBalance, InventoryLocationService::ensureDefaults (three locations), InventorySyncService (product row lock, outbox note in the sync transaction), ProviderStockOwnership, LocationStockReader, StorefrontStockRefresher; Torod stock-ownership, outbox and own-warehouse tests, all inside the MySQL and MariaDB groups.
- **(4) Done in the draft PRs.** None change it. Among the Fulfillment services the GCC chain touches only CheckoutCodGuard, ProviderMappingService::suggestionsFor (default-storefront filter) and M5aznProductImporter (import country); #83's CI runs the Fulfillment and SalesChannels groups green with the chain merged.
- **(5) What remains.** Nothing for the retained behaviour. Country-aware use is C-05 (country on locations) and C-16 (a sync of a KW-mapped row never touches an SA twin: no test yet); a sync for a non-Saudi provider account is C-12.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` TorodStockOwnershipTest: when the warehouse sets stock, unusable figure never zero, zero is real, sync twice = once (PR 1 d664ab4: Fulfillment+security (MySQL 8, MariaDB), SQLite)
  - `ci` StockOutboxTest::test_a_provider_sync_that_dies_between_the_two_writes_leaves_neither_the_figure_nor_the_event (PR 1 d664ab4: Sales-channel and cost tests, sales channels N-Z, MySQL 8.0/MariaDB)
  - `ci` OwnWarehouseReadThroughTest: the own warehouse reads the store's stock, never copies it (PR 1 d664ab4: sales channels N-Z jobs)
- **(8) Dependencies and blockers.** None for the baseline. Same CI reading as C-02: proof is taken from PR 1's head; the overlay job is a known tooling result.
- **(9) Acceptance criteria.**
  - [MET] The warehouse sets a product's stock only when it is assigned, mapped, switched on, verified and confirmed (TorodStockOwnershipTest::test_the_warehouse_sets_the_stock_only_when_every_condition_holds; PR 1 d664ab4)
  - [MET] An unusable or missing quantity leaves the stock untouched and never becomes zero, while a stated zero is a real figure (TorodStockOwnershipTest (unusable figure, missing field, zero is real); PR 1 d664ab4)
  - [MET] A sync run twice changes the stock once and leaves one movement (TorodStockOwnershipTest::test_syncing_twice_changes_it_once_and_leaves_one_movement_with_its_balance; PR 1 d664ab4)
  - [MET] A provider sync writes the store figure and its outbox note in one transaction or neither (StockOutboxTest::test_a_provider_sync_that_dies_between_the_two_writes_leaves_neither_the_figure_nor_the_event; PR 1 d664ab4, N-Z group)
  - [MET] The own warehouse reads the store's stock through and a negative store stock reads as zero (OwnWarehouseReadThroughTest; PR 1 d664ab4, N-Z group)
- **(10) Next coding task.** Add tests/Feature/Fulfillment/TorodSyncTwinRowsTest.php on a branch stacked on gcc/p1-country-scoped-codes-2 (#76): SA and KW twin products with one SKU, mapping approved for the SA row only; run syncInventory; assert only the SA row and one stock_movements row change and the KW row is untouched; then add the file to the Fulfillment MySQL group.

#### C-07. Inventory Pools and Stock Safety Buffers retained

- **State: MERGED** | plan lines 244, 247 | DECISIONS.md guardrail 1; E11 section 2.7 | reviews: none
- **(2) Functional requirement.** One physical shelf is divided between channels by the existing pool arithmetic: net = provider figure minus store orders not yet counted, minus open channel holds, minus buffer, never below zero; two accounts never show more than the net; buffers and the storefront reserve resolve listing, product, account and pool.
- **(3) Exists now in code (main).** main: inventory_pools (unique location, product, variant; storefront reserve, safety_buffer), PoolNet, PoolFigures, PoolAllocationService (single writer, row lock), StockAllocator, StorefrontReserve, PublishPolicyResolver (buffer, cap, share), StockPlanner; unit and feature tests plus real-engine concurrency tests.
- **(4) Done in the draft PRs.** None change it: no draft touches Allocation, Publishing or the pool tables, and #83's CI is green on both engines with the chain merged.
- **(5) What remains.** Nothing for the retained behaviour. Country isolation of pools (a KW twin's pool independent of the SA twin's, membership by account country) is C-05, C-14 and C-16; the plan asks for no per-country buffer.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` PoolNetTest, StockAllocationTest, StockPricePublisherTest: net, buffer, cap, reserve, shares (PR 1 d664ab4: Sales-channel N-Z (MySQL 8.0, MariaDB))
  - `ci` tests/Unit/SalesChannels StockAllocatorTest and PoolFiguresTest (PR 1 d664ab4: Sales-channel and cost tests (costs), both engines)
  - `ci` StockConcurrencyTest: six processes dividing one pool; two accounts publishing at once (PR 1 d664ab4: Sales-channel and cost tests (concurrency), MySQL 8.0 and MariaDB)
- **(8) Dependencies and blockers.** None for the baseline.
- **(9) Acceptance criteria.**
  - [MET] Net available = figure minus store orders not yet counted minus open channel holds minus buffer, and a deficit is published as zero (PoolNetTest::test_the_figure_less_store_orders_channel_orders_and_the_buffer, ::test_a_deficit_is_published_as_zero_never_as_a_negative_quantity; PR 1 d664ab4)
  - [MET] Two accounts on one pool are never shown more than the net, also when they publish at once (StockAllocationTest::test_two_accounts_on_one_pool_are_never_shown_more_in_total_than_the_net; StockConcurrencyTest (concurrency jobs))
  - [MET] A listing buffer and a pool buffer both come off and the cap holds; shares resolve listing, then product, then account (StockAllocationTest::test_a_listing_buffer_and_a_pool_buffer_are_taken_off_and_never_more_than_the_cap_is_shown, ::test_shares_come_from_the_listing_then_the_product_policy_then_the_account_policy)
  - [MET] The storefront reserve comes off first and a pool can name its own (StockAllocationTest::test_the_storefront_reserve_comes_off_first_and_a_pool_can_name_its_own)
  - [MET] Six processes dividing one pool give the serial result and one version (StockConcurrencyTest::test_six_processes_dividing_one_pool_at_once_give_the_serial_result_and_one_version; concurrency jobs MySQL 8.0/MariaDB)
- **(10) Next coding task.** Add tests/Feature/SalesChannels/PoolCountryTwinsTest.php (class name starts with P: N-Z partition) stacked on #76: SA and KW twin products at one location with listings; assert each pool's net, division version and outbox events are independent and a hold on the KW twin never changes the SA pool (tests/Support/BuildsStockPools gains a country argument).

#### C-08. Reservations with idempotent holds retained

- **State: MERGED** | plan lines 246, 251 | DECISIONS.md guardrail 1; E11-F07; E11 section 2.7 | reviews: owner-decision
- **(2) Functional requirement.** Channel orders promise units of a location's stock once per order line: the key is unique and a repeat changes nothing, a committed hold stops counting once the location's own count is newer, a release gives back exactly what was held, and never more is promised than exists.
- **(3) Exists now in code (main).** main: inventory_reservations (unique reservation_key 'channel-order:{mapping}:{line}', states reserved, committed, released), ReservationService (race-safe insert, locking re-read), ChannelOrderReservations as the only reserve() caller. Store checkout does not reserve: it takes stock at order creation (C-11).
- **(4) Done in the draft PRs.** #24 (test-only) pins hold behaviour: holding a fulfillment or a reservation changes no current_stock, a repeated release changes nothing, and a reservation released for a cancelled order stays released on reopen (flagged to the owner as a possible oversell).
- **(5) What remains.** Owner decision on the reinstated-order point (#24). The country dimension of reservations is C-05; a hold over the cart-to-payment window for store orders does not exist and is not asked for (E11-F07).
- **(6) PRs and commits.** [lavkoo#24](https://github.com/fullstackfull/lavkoo/pull/24) `eebb588`
- **(7) Tests.**
  - `ci` ChannelOrderReservationsTest and StockOutboxTest: one hold per line however often read, exact release, cancelled unit gives its place back (PR 1 d664ab4: Sales-channel and cost tests, sales channels A-M and N-Z, MySQL 8.0/MariaDB)
  - `ci` StockConcurrencyTest::test_the_same_order_read_by_the_poller_and_the_webhook_at_once_is_one_order_and_one_hold (PR 1 d664ab4: Sales-channel and cost tests (concurrency), MySQL 8.0/MariaDB 11.4.2)
  - `ci` StockHoldsCharacterizationTest (17 tests) pins holds, releases and the reinstated-order behaviour (PR 24 eebb588: Tests SQLite 8.2/8.3 (SQLite only))
- **(8) Dependencies and blockers.** None for the baseline.
- **(9) Acceptance criteria.**
  - [MET] One hold per order line however often the order is read, and the units follow the line while it is only reserved (ChannelOrderReservationsTest::test_an_open_order_keeps_its_units_out_of_what_is_offered_once_however_often_it_is_read; StockOutboxTest::test_a_unit_is_never_held_twice_however_many_times_anything_runs; PR 1 d664ab4)
  - [MET] A cancelled unit gives its place back and a release returns exactly what was held (ChannelOrderReservationsTest (cancelled unit); StockOutboxTest::test_a_release_gives_back_exactly_what_was_held; PR 1 d664ab4)
  - [MET] A committed hold counts only until the location's own figure is newer, so units are never subtracted twice (ChannelOrderReservationsTest::test_once_a_shipment_is_linked_the_units_count_only_until_the_location_counts_them_itself; PR 1 d664ab4)
  - [MET] The same order read by the poller and the webhook at once is one order and one hold (StockConcurrencyTest (concurrency jobs MySQL 8.0/MariaDB 11.4.2))
  - [MET] More promised than the warehouse holds leaves nothing to sell and never a negative stock (SharedProviderStockTest::test_more_promised_than_the_warehouse_holds_leaves_nothing_to_sell_and_never_a_negative_stock; PR 1 d664ab4)
- **(10) Next coding task.** After the owner decides, on a branch stacked on #24: write the failing test for a reinstated channel order (cancelled, then reopened with units) expecting a new hold, then change ReservationService (reserve/reopen) and flip test_a_reservation_released_for_a_cancelled_order_stays_released_on_reopen_and_reserve; if the owner keeps today's rule, document it in docs/sales-channels.
- **Open owner decisions.** #24: must a reservation released for a cancelled order be held again if the channel order is reinstated? Today it stays released, which may allow overselling. Pinned as is, not changed.

#### C-09. Transactional outbox for stock changes retained

- **State: MERGED** | plan lines 248 | DECISIONS.md guardrail 1; E11 section 2.8 | reviews: none
- **(2) Functional requirement.** A change to a channel order's holds (imported, changed, cancelled, units returned), to a provider's figure, or to a listing's or pool's settings writes its outbox row in the same transaction; rows are claimed once, retried, and kept as dead for a person; a row is never closed while nothing was settled. A store checkout or a store return writes no outbox row: the channels learn of them at the next scheduled quick or full publish run.
- **(3) Exists now in code (main).** main: channel_stock_events (pending, claimed, worked, dead; outcome; abandoned claims taken back), StockEventOutbox::record inside the change's transaction, StockEventDrain, StockEventNudge, ProviderFigureNotes (the provider sync writes its note in its own transaction), DrainStockEvents job and the channels:stock-events command. Provider dispatch has no outbox table: the provider_fulfillments row is its durable intention.
- **(4) Done in the draft PRs.** None change it.
- **(5) What remains.** Nothing for the retained behaviour. Events are keyed by (location, product, variant), so they follow the product row's country; a twin test is C-16. The nudge throttle cache key is one key for every market.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` StockOutboxTest: rollback leaves neither row, two runs never take one event, dead events kept, never closed unsettled (PR 1 d664ab4: Sales-channel N-Z (MySQL 8.0, MariaDB))
  - `ci` StockConcurrencyTest: six workers claiming and draining the outbox at once work nothing twice (PR 1 d664ab4: Sales-channel concurrency (MySQL 8.0, MariaDB))
- **(8) Dependencies and blockers.** None for the baseline.
- **(9) Acceptance criteria.**
  - [MET] A change and its outbox row are written in one transaction: a rolled-back change leaves neither (StockOutboxTest::test_a_rolled_back_change_leaves_neither_the_hold_nor_the_event; PR 1 d664ab4)
  - [MET] Two runs never take the same event, and the events of a dead run are taken back, not lost (StockOutboxTest::test_two_runs_never_take_the_same_event, ::test_an_event_a_dead_run_still_holds_is_taken_back_and_worked_rather_than_lost; PR 1 d664ab4)
  - [MET] A failed batch is retried; an event nobody can work is kept as dead for a person, not dropped (StockOutboxTest::test_an_event_nobody_can_work_is_kept_as_dead_for_a_person_not_quietly_dropped; PR 1 d664ab4)
  - [MET] A row is never closed as worked while the publisher settled nothing (StockOutboxTest::test_a_row_is_never_closed_as_worked_while_the_publisher_settled_nothing; PR 1 d664ab4)
  - [MET] Six workers claiming and draining at once work nothing twice (StockConcurrencyTest::test_six_workers_draining_the_outbox_at_once_leave_nothing_waiting_and_work_nothing_twice; concurrency jobs)
- **(10) Next coding task.** Add tests/Feature/SalesChannels/OutboxCountryTwinsTest.php stacked on #76: a hold or a provider figure on the KW twin writes channel_stock_events only for the KW twin's pool key, and StorefrontStockRefresher rewrites only the KW row.
- **Verifier note (not applied automatically).** functional corrected by the verifier (applied above).

#### C-10. Fulfillment adapters, provider preflight and kill switches retained

- **State: MERGED** | plan lines 249, 250 | DECISIONS.md guardrail 1; E11-F15; plan lines 443 (H2) and 537-562 for the safety rules | reviews: none
- **(2) Functional requirement.** Every provider sits behind one adapter contract. Nothing leaves the host until two independent switches are on, a provider needs readiness and a proven controlled phase before automatic sending, a failed preflight holds with a named reason, and a dry run sends nothing.
- **(3) Exists now in code (main).** main: the ProviderAdapter contract (readiness, testConnection, preflight, submit, cancel, fetchStatus, mapStatus); FULFILLMENT_INTEGRATIONS_ENABLED and FULFILLMENT_EXTERNAL_WRITES_ENABLED default off and enforced by the transport (ProviderHttp); FulfillmentPreflight, GoLiveReadiness, the fulfillment:preflight command, dry run, named HoldReason constants.
- **(4) Done in the draft PRs.** None change it.
- **(5) What remains.** Nothing for the retained behaviour. Both switches, the queue and the sweeps are installation-wide, so a store pause (GCC-12) must not use them (E11-F15); the adapters accept Saudi phones and the riyal only (C-15).
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` ExternalWriteGuardTest: a write is refused before anything leaves the host; reads and login still work (PR 1 d664ab4: Fulfillment+security (MySQL 8, MariaDB), SQLite)
  - `ci` DispatcherTest: unready provider holds, kill switch stops sending, dry run sends nothing, preflight hold is idempotent (PR 1 d664ab4: same jobs)
  - `ci` GoLiveReadinessTest and FulfillmentPreflightTest (PR 1 d664ab4: same jobs, plus Strict-mode tests MariaDB 11.4.2)
- **(8) Dependencies and blockers.** None for the baseline.
- **(9) Acceptance criteria.**
  - [MET] A write is refused before anything leaves the host while writes are off; reads and the token login still work (ExternalWriteGuardTest::test_a_write_is_refused_before_anything_leaves_the_host, ::test_reads_and_the_token_login_still_work_while_writes_are_off; PR 1 d664ab4)
  - [MET] The global kill switch stops all sending and nothing queued is lost (DispatcherTest::test_the_global_kill_switch_stops_everything, ::test_when_integrations_are_switched_off_after_claiming_nothing_is_lost; PR 1 d664ab4)
  - [MET] An unready or disabled provider holds with what is missing, and the hold releases itself when fixed (DispatcherTest::test_an_unready_provider_holds_with_what_is_missing, ::test_a_preflight_hold_is_idempotent_and_releases_itself_when_fixed; PR 1 d664ab4)
  - [MET] A provider is not ready for automatic sending until its controlled phase has produced proof, and orders placed before go-live are held, not shipped retroactively (GoLiveReadinessTest::test_a_provider_that_has_only_been_configured_is_not_ready_and_says_why; DispatcherTest::test_orders_placed_before_go_live_are_held_not_shipped_retroactively; PR 1 d664ab4)
  - [MET] A dry run validates and builds the request without sending and never claims submission (DispatcherTest::test_a_dry_run_validates_without_sending_and_never_claims_submission; PR 1 d664ab4)
  - [MET] fulfillment:preflight prints a report, exits non-zero on a failure and changes nothing (FulfillmentPreflightTest::test_the_command_prints_a_report_exits_non_zero_on_a_failure_and_changes_nothing; PR 1 d664ab4)
- **(10) Next coding task.** In the combined-tree task (merge #75 and #83 into one CI tree, resolving the five conflicting files), run tests/Unit/Fulfillment, tests/Feature/Fulfillment and tests/Feature/Security on MySQL 8 and MariaDB 11.4.2 to prove adapters and preflight survive the security set (it changes SaudiPhone.php and InventoryService.php) and the GCC chain together.

#### C-11. Idempotency, concurrency and oversell protection of stock writers

- **State: IN PROGRESS** | plan lines 251, 252 | DECISIONS.md guardrail 1; E11 section 2.17; INV-01 to INV-05 in the bodies of #13, #17, #24 | reviews: none
- **(2) Functional requirement.** Every writer of stock or of a fulfillment is safe to repeat and to race: routing, dispatch, holds and the outbox act once per key; checkout refuses more than the stock under a row lock in id order; a return restocks once and its variant; a retried POS sale takes stock once; a status change restores or takes stock once per line.
- **(3) Exists now in code (main).** main: unique keys and locks in FulfillmentRouter, ProviderOrderDispatcher, ReservationService and StockEventOutbox; OrderManager locks products in id order and decrements with where current_stock >= qty (InsufficientStockException; allow_oversell only on the post-payment path); is_stock_decreased flips conditionally. Real-engine tests cover holds, pools and the outbox; none covered checkout stock, return receipt or POS stock.
- **(4) Done in the draft PRs.** #18: checkout refusal, variant shortfall, lock order, six-customer MySQL race. #17: return receipt locked, once, variant restocked. #24: characterization, three defects pinned as they are. #13: POS stock in id order. #35: POS Idempotency-Key, one order, one debit, one stock take. #12: a refused wallet debit rolls the stock back. None of them is in #75 or #83.
- **(5) What remains.** (1) fix the three defects #24 pins: reopening a cancelled line drives the variant negative; a partial return then 'returned' restocks more than the line held; a return above the line's quantity restocks units that never left; (2) the seller app must send Idempotency-Key; (3) a combined tree with #75/#83.
- **(6) PRs and commits.** [lavkoo#12](https://github.com/fullstackfull/lavkoo/pull/12) `dd5f95e`; [lavkoo#13](https://github.com/fullstackfull/lavkoo/pull/13) `516881c`; [lavkoo#17](https://github.com/fullstackfull/lavkoo/pull/17) `5cbc384`; [lavkoo#18](https://github.com/fullstackfull/lavkoo/pull/18) `de1270f`; [lavkoo#24](https://github.com/fullstackfull/lavkoo/pull/24) `eebb588`; [lavkoo#35](https://github.com/fullstackfull/lavkoo/pull/35) `7c75b7f`
- **(7) Tests.**
  - `ci` GenerateOrderStockTest (9 tests): refusal, variant shortfall, whole order refused, lock order, allow_oversell on the post-payment path (PR 18 de1270f: Tests SQLite 8.2/8.3 (SQLite only))
  - `ci` CheckoutStockConcurrencyTest: six customers, last three units, three refusals (PR 18 de1270f: Sales-channel concurrency (MySQL 8.0, MariaDB))
  - `ci` ReturnReceiptTest (SQLite) and ReturnReceiptConcurrencyTest (real engine) (PR 17 5cbc384: Tests SQLite; concurrency jobs MySQL 8.0/MariaDB 11.4.2)
  - `ci` PosSaleIdempotencyConcurrencyTest: one key, one order, one debit, one stock take (PR 35 7c75b7f: concurrency jobs MySQL 8.0/MariaDB 11.4.2)
  - `pr-body` GenerateOrderStockTest lock-order assertion on MySQL 8.0.46, PosStockDecrementOrderTest (SQLite only, fails on MySQL by design) (PR 18 and PR 13 bodies; no MySQL CI group runs tests/Feature/Checkout or tests/Feature/Pos)
- **(8) Dependencies and blockers.** #17, #18, #24, #13, #35 and #12 are in neither integration tree. #17 and #24 pin each other (the second to merge updates test_a_restocked_return_of_a_variant_line_currently_leaves_the_variant_quantity_unchanged). OrderManager.php is also changed by the security set and by #9, #11, #12, #23, #29, #55; no combined tree.
- **(9) Acceptance criteria.**
  - [MET] A router run twice or racing creates one fulfillment per order, provider and generation (the database refuses a duplicate), and the provider is asked exactly once however often the job runs, a timeout after sending never answered by a second send (RouterTest (three tests); DispatcherTest::test_the_provider_is_asked_exactly_once_however_many_times_the_job_runs, ::test_a_timeout_after_sending_is_never_answered_with_a_second_send; PR 1 d664ab4, Fulfillment + security MySQL 8/MariaDB)
  - [CODED] An order for more than the stock is refused and leaves stock unchanged; a variant shortfall is not masked; one short line refuses the whole order; products are locked in ascending id order (GenerateOrderStockTest; PR 18 de1270f, Tests SQLite 8.2/8.3)
  - [MET] Six customers ordering the last three units get three orders and three refusals and stock 0, on a real engine (CheckoutStockConcurrencyTest; PR 18 de1270f, concurrency jobs MySQL 8.0/MariaDB)
  - [MET] A return received twice, also by two processes at once, restocks once and restocks its variant (ReturnReceiptTest, ReturnReceiptConcurrencyTest; PR 17 5cbc384)
  - [MET] A retried POS sale with one Idempotency-Key places one order, debits once and takes stock once (the seller app does not send the key yet) (PosSaleIdempotencyConcurrencyTest; PR 35 7c75b7f, concurrency jobs MySQL 8.0/MariaDB)
  - [MISSING] Reopening a cancelled line, a partial return followed by 'returned', and a return above the line's quantity never restock more than left the shelf (pinned as defects in #24 (..._currently_ tests); no fix on any branch)
- **(10) Next coding task.** Branch stacked on #17: flip the three pinned tests of #24 with production fixes (floor the variant quantity at zero in OrderManager::getStockUpdateOnOrderStatusChange; cap a return to the line's quantity and claim is_stock_decreased on the cumulative returned quantity in ReturnLogisticsService); keep the other tests; run Tests SQLite and the concurrency group.
- **Open owner decisions.** Whether the post-payment path may keep taking stock below zero (allow_oversell, pinned in #18) and whether a hold over the cart-to-payment window is wanted (E11-F07); the owner accepts the fixes of the three #24 findings.

#### C-12. Room for country-specific fulfillment providers (UAE, Kuwait, Bahrain, Qatar, Oman)

- **State: IN PROGRESS** | plan lines 254 | DECISIONS.md (future country-specific providers; Fulfillment Account is a distinct concept); E11-F01; owner question Q-33 | reviews: security, owner-decision
- **(2) Functional requirement.** A provider for another country is added as one adapter and one configuration entry, with its own account and credentials per country and legal entity, and declares the countries it serves; routing, dispatch, retries, webhooks and the order summary need no change.
- **(3) Exists now in code (main).** main: ProviderRegistry resolves adapters from config('fulfillment.providers.<code>.adapter') and the contract is provider-neutral. But ProviderCode is a closed enum of two cases with referenceCode() arms, 'torod_point' and 'm5azn' are written into SaleDocumentReader, ChannelAccountService and StagingChannels, integration_settings has one row per provider code, and the provider tables are unique per provider code only.
- **(4) Done in the draft PRs.** #72/#76: ProductCodeScope::countryOfProviderProducts() says every provider connection serves the default storefront; M5AZN import and Torod matching are narrowed to it and tested. No draft adds a provider, an account dimension or a country on integration_settings.
- **(5) What remains.** (1) a provider account per country or legal entity (unique keys of provider_fulfillments, provider_product_mappings, provider_cities and provider_sync_states gain the account; webhook route per account); (2) served countries declared by config and read by assignment, matching and checkout; (3) open the enum or prove a fake third adapter registers; (4) real providers per market (Q-33).
- **(6) PRs and commits.** [lavkoo#72](https://github.com/fullstackfull/lavkoo/pull/72) `1c779d2`; [lavkoo#76](https://github.com/fullstackfull/lavkoo/pull/76) `0ad49d2`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`
- **(7) Tests.**
  - `ci` ProviderMatchingCountryTest: matching narrowed to the default storefront while connections have no country (PR 83 8ff3410: Catalog tests MySQL 8 and MariaDB 11.4.2)
  - `ci` TranslationsTest and GoLiveReadinessTest iterate ProviderCode::cases() through ProviderRegistry (two providers only) (PR 1 d664ab4: Fulfillment + security MySQL 8/MariaDB 11.4.2)
  - `none` No test registers a third adapter or a second account of one provider (-)
- **(8) Dependencies and blockers.** Owner answer Q-33 (real coverage, contracts, company ids) and the entity model of C-05; Integration Center permissions are not country-scoped (E11-F16; GCC-14c slices 4-6 not built).
- **(9) Acceptance criteria.**
  - [MET] The existing adapters are resolved from configuration through the registry and report their readiness (TranslationsTest (ProviderRegistry::adapter()->readiness() for every ProviderCode case); PR 1 d664ab4)
  - [MET] Matching and import act only on the default storefront's products while connections have no country (ProviderMatchingCountryTest; PR 83 Catalog tests MySQL 8/MariaDB 11.4.2)
  - [MISSING] A third provider registers with an adapter class and a config entry only; a fake adapter proves routing, dispatch and the order summary unchanged (ProviderCode is a closed enum; no test)
  - [MISSING] The same provider code runs for two countries with separate credentials (an account per country or legal entity) (integration_settings one row per provider; unique keys carry no account)
  - [MISSING] A provider declares the countries it serves and assignment, matching and checkout refuse others (only the default-storefront assumption of #72/#76)
- **(10) Next coding task.** Branch from main (the adapters are touched by neither set): add tests/Feature/Fulfillment/ThirdProviderTest.php with a test-only FakeAeAdapter registered through config; route, preflight, dry-run dispatch and summarize an order for it. It will show the closed ProviderCode enum is the blocker; then decide between a new enum case per provider and a registry of codes.
- **Open owner decisions.** Q-33: which providers serve AE, KW, BH, QA, OM and under which contracts; one provider account per country or per legal entity; whether one provider code may be reused across countries.

#### C-13. No second inventory engine: country separation on the existing row

- **State: IMPLEMENTED IN DRAFT** | plan lines 256 | DECISIONS.md guardrail 1 (expand and protect, no rewrite); E11-F07 | reviews: none
- **(2) Functional requirement.** Country separation of stock is built on the existing engine and the product row, with no parallel stock table or writer: the shared-stock modules never write platform stock tables, and each country's stock is its own product row's current_stock.
- **(3) Exists now in code (main).** main: StockNeverWritesPlatformTablesTest scans the module source and fails on any write to products, stock_movements, warehouse_stock, warehouses, suppliers, purchase tables or batches; docs/sales-channels/realtime-stock.md states it builds no second stock engine; one method writes the store figure of a provider product (InventorySyncService).
- **(4) Done in the draft PRs.** #47 (off): products.storefront_country is the only GCC migration touching product or stock data; nothing is added to inventory_*, provider_* or stock_* (the 14 migrations of #83 are legal, country, platform admin and staff scopes). The chain adds no stock writer.
- **(5) What remains.** A guard test that fails when a migration adds a stock-like table or a second writer of current_stock appears outside the allowed list; the written map of the three location concepts (C-05).
- **(6) PRs and commits.** [lavkoo#47](https://github.com/fullstackfull/lavkoo/pull/47) `e0dd5dc`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`
- **(7) Tests.**
  - `ci` StockNeverWritesPlatformTablesTest (2 tests) (PR 1 d664ab4: Tests SQLite; costs group MySQL 8.0/MariaDB; PR 83 8ff3410 the same jobs)
  - `ci` StorefrontCatalogTest::test_each_country_keeps_its_own_price_and_stock_for_the_same_product (PR 83 8ff3410: Storefront tests MySQL 8/MariaDB 11.4.2, Tests SQLite)
  - `none` No guard against a new stock-like table or a second writer of current_stock (-)
- **(8) Dependencies and blockers.** Criterion 3 rests on a diff, not a test, so this cannot be INTEGRATED AND TESTED; #47 also changes Product.php and CartManager.php, which the security set changes too (no combined tree).
- **(9) Acceptance criteria.**
  - [MET] The shared-stock modules write no platform stock table and use no raw or dynamic write the scan cannot judge (StockNeverWritesPlatformTablesTest; PR 1 d664ab4 and PR 83 8ff3410 (Tests SQLite, costs MySQL 8/MariaDB))
  - [MET] Each country's stock is its own product row's current_stock: twins keep separate stock (StorefrontCatalogTest::test_each_country_keeps_its_own_price_and_stock_for_the_same_product; PR 83 8ff3410)
  - [CODED] With the switches off every stock write path is the old one: the chain adds no inventory table, column or writer (diff of #83 against main: one products column, no engine file; no guard test)
- **(10) Next coding task.** On a branch stacked on #53 extend StockNeverWritesPlatformTablesTest (or add tests/Unit/Storefront/NoSecondStockEngineTest.php): scan database/migrations for tables with stock-like columns (available, reserved, quantity) outside the allowed inventory_*, provider_*, stock_* and product_* list, and list the only files allowed to write products.current_stock.
- **Verifier note (not applied automatically).** tests[0].what: 'StockNeverWritesPlatformTablesTest (2 tests)': the class has four test methods (test_no_module_file_writes_a_platform_table, test_the_check_sees_what_it_claims_to_and_would_catch_a_write, test_every_way_of_hiding_a_write_in_the_fixture_is_reported, test_the_allowed_files_are_still_the_only_ones_and_still_clean_in_what_they_were_allowed_for). git grep -c -F 'public function test_' gives 4 at origin/main and at PR 83. Its moduleFiles() scan reads only app/Services/SalesChannels, app/Http/Controllers/Admin/SalesChannels and app/Console/Commands/SalesChannels*.php. Proposed: StockNeverWritesPlatformTablesTest (4 tests; the scan covers app/Services/SalesChannels, app/Http/Controllers/Admin/SalesChannels and app/Console/Commands/SalesChannels*.php only, so it does not watch app/Services/Fulfillment, the storefront services or any new GCC service)

#### C-14. Fulfillment provider kept separate from sales channel; one source feeds every marketplace

- **State: IN PROGRESS** | plan lines 258 | DECISIONS.md (Sales Channel Account and Fulfillment Account are distinct); E11-F19 | reviews: security, owner-decision
- **(2) Functional requirement.** A product published on several marketplaces takes its quantity from the same fulfillment location and pool; a channel never chooses or changes the provider, a location says which channels may see its stock, and channel-held stock (FBA, FBN) is never mixed with ours.
- **(3) Exists now in code (main).** main: InventoryLocation.publishable_channels and StockListingGate (a location must admit the channel; none admits noon until a person decides); PoolMembers and PoolAllocationService divide one net across listings; open channel orders come off the figure the store shows (hold_channel_units_from_storefront, default on); PoolWarnings flags a listing whose location is not where the product's provider keeps it (a warning only). ProviderCode and SalesChannel are separate enums.
- **(4) Done in the draft PRs.** #72/#76: ChannelAccountCountry gives a Trendyol, noon or Amazon account its country for product matching only; routing rules by SKU are narrowed by the product's country. No draft makes pool membership country-aware.
- **(5) What remains.** (1) pool membership has no country condition: an account of country X can join the pool of a product of country Y (E11-F19; PoolMembers unchanged in #83); (2) location/provider mismatch is only a warning; (3) the country of Amazon multi-marketplace accounts (marketplace unit, H2); (4) deduction of channel orders from store stock is off (apply_channel_orders_to_platform_stock).
- **(6) PRs and commits.** [lavkoo#72](https://github.com/fullstackfull/lavkoo/pull/72) `1c779d2`; [lavkoo#76](https://github.com/fullstackfull/lavkoo/pull/76) `0ad49d2`
- **(7) Tests.**
  - `ci` StockAllocationTest and StockConcurrencyTest: two accounts on one pool never show more than the net (PR 1 d664ab4: sales channels N-Z and concurrency jobs, MySQL 8.0/MariaDB 11.4.2)
  - `ci` NoonPoolMembershipTest and SharedProviderStockTest: admission per location; store and channel orders both come off the figure (PR 1 d664ab4: sales channels N-Z jobs)
  - `ci` ChannelProductMatcherCountryTest and SixCountryChannelMatcherTest: account country used for matching (PR 83 8ff3410: Catalog tests MySQL 8/MariaDB 11.4.2)
  - `none` No test of pool membership by account country; none for PoolWarnings::OTHER_LOCATION by name (-)
- **(8) Dependencies and blockers.** Owner decision E11-F19 (shared stock forbidden, or a definition question) with the marketplace unit (H2); depends on #76's ChannelAccountCountry.
- **(9) Acceptance criteria.**
  - [MET] A product listed on two accounts is offered at most its pool's net in total, also under concurrency (StockAllocationTest::test_two_accounts_on_one_pool_are_never_shown_more_in_total_than_the_net; StockConcurrencyTest; PR 1 d664ab4)
  - [MET] A listing takes stock only from a location that admits its channel; no location admits noon until a person decides (NoonPoolMembershipTest::test_a_noon_listing_takes_no_stock_while_no_location_admits_noon; PR 1 d664ab4)
  - [MET] Store orders and channel orders both come off the figure and neither covers for the other (SharedProviderStockTest::test_store_orders_and_channel_orders_both_come_off_and_neither_covers_for_the_other; PR 1 d664ab4)
  - [MET] Amazon FBA and noon FBN stock never joins our pools and nothing is sent for it (StockOutboxTest::test_the_drain_sends_nothing_for_a_listing_whose_stock_the_channel_itself_owns; PR 1 d664ab4)
  - [MET] A listing is flagged when its stock location is not where the product's provider keeps it (PoolWarnings::OTHER_LOCATION; no test found by name)
  - [MISSING] A marketplace account of country X takes part only in the pools of that country's products (PoolMembers and StockListingGate carry no country in #83)
- **(10) Next coding task.** Slice 3 branch stacked on gcc/p1-country-scoped-codes-2 (#76): red test first in tests/Feature/SalesChannels/PoolCountryMembershipTest (a KW account's listing linked to an SA product is refused from the SA pool), then StockListingGate::refusal returns account_country_mismatch via ChannelAccountCountry while GCC_COUNTRY_SCOPED_CODES is on; an account without a country stays unnarrowed.
- **Open owner decisions.** E11-F19: may an account of one country take part in the pool of another country's product at all (shared stock is forbidden by the decisions)? Should PoolWarnings::OTHER_LOCATION become a refusal?

#### C-15. Country defaults of the fulfillment config and adapters (SAR and Saudi assumptions)

- **State: NOT STARTED** | plan lines 22 | E11-F02, E11-F03, E11-F04; GCC-05 (open) | reviews: finance, owner-decision
- **(2) Functional requirement.** Nothing in fulfillment assumes Saudi Arabia: provider currency, decimals, country code, phone rules and city lists follow the order's storefront and the provider account, with today's Saudi values as the explicit default of the Saudi account.
- **(3) Exists now in code (main).** main: config/fulfillment.php currency SAR (FULFILLMENT_CURRENCY), KSA-to-SAR alias, m5azn default_country SA; M5aznAdapter sends country_code SA; SaudiPhone::normalize accepts Saudi mobiles only (others hold hold_phone_invalid); Torod payload uses 00966; provider_fulfillments.currency defaults SAR; two decimals fixed. A single-currency store whose currency is not the riyal has its shipments held, not sent (PilotServerMoneyTest).
- **(4) Done in the draft PRs.** Nothing. No draft touches config/fulfillment.php, the adapters or the fulfillment money classes; #58 and #62 extend SaudiPhone for password-reset phone matching only, and normalize() stays Saudi-only.
- **(5) What remains.** (1) provider currency from the storefront currency (needs the GCC-05 seam; orders carry no currency); (2) 3-decimal currencies KWD, BHD, OMR through FulfillmentMoney, CodAllocator and the columns; (3) destination country and phone rules per country in the adapters; (4) default_country per provider account; (5) acceptance tests per currency before any non-Saudi provider.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` PilotServerMoneyTest and RouterTest pin the Saudi riyal figures and the hold for an unconvertible amount (PR 1 d664ab4: Fulfillment + security MySQL 8/MariaDB 11.4.2 (Saudi baseline only))
  - `ci` TorodAdapterTest and M5aznAdapterTest: an invalid phone holds the order (PR 1 d664ab4: same jobs)
  - `none` No test for KWD, BHD or OMR amounts or a non-Saudi destination (-)
- **(8) Dependencies and blockers.** GCC-05 storefront currency (orchestrator task M3, slice 1 in progress) and the address unit (Saudi-only city mapping). Orders carry no currency of their own.
- **(9) Acceptance criteria.**
  - [MISSING] The currency stamped on a provider fulfillment is the order's storefront currency (FulfillmentRouter::create stamps CurrencyCode::provider() from config)
  - [MISSING] Amounts in three-decimal currencies are carried and a COD split is allocated without rounding to two decimals (CodAllocator works in cents, FulfillmentMoney rounds to 2 decimals and the columns are decimal(14,2); no test)
  - [MISSING] The country and phone sent to a provider follow the destination address's country (SaudiPhone and country_code SA in the adapters)
  - [MISSING] The Saudi account keeps today's values as explicit per-account defaults and its provider output stays byte for byte the same (no per-account default exists)
- **(10) Next coding task.** Test-only characterization on a branch from main: tests/Feature/Fulfillment/FulfillmentMoneyThreeDecimalsTest pins what a KWD 12.345 cash-on-delivery order does today (held, or rounded to two decimals) so the later fix flips it; add the same for BHD and OMR.
- **Open owner decisions.** Which currency a provider is paid in for a non-Saudi order (storefront currency or a provider-account currency); E11-F02 rates it P0 if a non-Saudi provider is enabled before this is done.

#### C-16. Cross-country overlap, oversell and duplicate-reservation protection

- **State: IN PROGRESS** | plan lines 499 | DECISIONS.md (market-scoped caches, queues and inventory; no shared stock); E11-F07, E11-F19; plan reports 08 and 10 (lines 634, 666, 670) | reviews: security
- **(2) Functional requirement.** No stock of one country can be taken, held, restocked, synced or shown to another country: checkout, return, provider sync, channel hold, pool publication, POS and caches act on the product rows of the storefront's own country, proven by a six-country twin test and by concurrency on a real engine.
- **(3) Exists now in code (main).** main: one global current_stock per product row and one global SKU space; no country anywhere in the stock writers, locations, pools, reservations or outbox. Isolation is the row only, and there is nothing to isolate while every product is Saudi.
- **(4) Done in the draft PRs.** #47 country on the row; #48 cart rows and by-id reads follow the storefront; #50 product caches keyed per storefront; #53 a payment is finished in the storefront it started in; #72/#76 six-country matrix for SKU and barcode (not stock); #71 per-market zero-stock monitor. All off by default and all in #83.
- **(5) What remains.** No stock-writer test across two countries: checkout in KW leaves the SA twin; a return restocks the KW row only; a sync of a KW-mapped row; a channel hold by a KW account; pool nets of twins; concurrency across twins. The seller and admin POS, the marketplace order materializer and the sales-channel webhooks are not behind the sale gate (owner decision); orders carry no country.
- **(6) PRs and commits.** [lavkoo#47](https://github.com/fullstackfull/lavkoo/pull/47) `e0dd5dc`; [lavkoo#48](https://github.com/fullstackfull/lavkoo/pull/48) `4a821d3`; [lavkoo#50](https://github.com/fullstackfull/lavkoo/pull/50) `98191ff`; [lavkoo#53](https://github.com/fullstackfull/lavkoo/pull/53) `7e32bba`; [lavkoo#72](https://github.com/fullstackfull/lavkoo/pull/72) `1c779d2`; [lavkoo#76](https://github.com/fullstackfull/lavkoo/pull/76) `0ad49d2`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`; [lavkoo#71](https://github.com/fullstackfull/lavkoo/pull/71) `b83a815`
- **(7) Tests.**
  - `ci` StorefrontCartGuardTest and StorefrontCartIsolationTest: another country's product cannot be added or repriced; its stock is not answered (PR 83 8ff3410: Storefront tests MySQL 8/MariaDB 11.4.2, Tests SQLite)
  - `ci` StorefrontProductCachesTest (14): product caches are kept per storefront and cleared in every storefront (PR 50 98191ff and PR 83 8ff3410: Storefront tests, Tests SQLite)
  - `ci` StorefrontPaymentCallbackTest: a paid Kuwait cart becomes an order in its own storefront (PR 53 7e32bba and PR 83 8ff3410: Storefront tests)
  - `none` No test places an order or a return, runs a sync or a channel hold across two countries (-)
- **(8) Dependencies and blockers.** Needs the harnesses of #17 and #18 (GenerateOrderStockTest, ReturnReceiptOperations) in the same tree as the storefront chain: today they are in neither #75 nor #83. CartManager.php, Product.php and the seller POS controller are changed by both sets (five conflicting files): no combined tree.
- **(9) Acceptance criteria.**
  - [MET] A cart cannot add, reprice or read the price and stock of another country's product (StorefrontCartGuardTest, StorefrontCartIsolationTest; PR 83 Storefront tests MySQL 8/MariaDB)
  - [MET] Caches built from products (price, stock) are kept per storefront and a product change clears every storefront's copy (StorefrontProductCachesTest; PR 50 98191ff and PR 83)
  - [MET] A paid cart is turned into an order in the storefront the payment started in, from that storefront's product rows (StorefrontPaymentCallbackTest::test_a_paid_kuwait_cart_becomes_an_order_although_the_callback_names_no_storefront; PR 83)
  - [CODED] An order placed in storefront X takes stock only from X's product rows and leaves the twin of another country unchanged (carts are per storefront (#48) and the decrement is by product id; no test places the order)
  - [CODED] A return, a provider sync and a channel hold change only the row they name, never a twin of another country (writers are keyed by product id; no twin test)
  - [MISSING] Seller and admin POS and marketplace orders are storefront sales gated like the web checkout, or the owner records that they are not (STOREFRONT_FLAG_RUNBOOK: not behind the sale gate)
- **(10) Next coding task.** Create a CI-only integration branch that merges #17, #18 and the storefront chain to #53; add tests/Feature/Storefront/StorefrontStockIsolationTest (SA and KW twins, one SKU, country-scoped codes on): a KW checkout takes KW stock only, a KW return restocks KW only, a KW-mapped provider sync and a KW channel hold leave SA unchanged; add a twin race to tests/Feature/Concurrency and the paths to the Storefront MySQL/MariaDB group.
- **Open owner decisions.** Whether the seller and admin POS, the marketplace order materializer and the sales-channel webhooks are storefront sales; whether a channel order's units may come off a product of another country than its account's.

#### C-17. Axis E map: the inventory and fulfillment flow and where countries can overlap

- **State: IN PROGRESS** | plan lines 493-499 | E11 (discovery 2026-11-12); price and stock map 2026-11-16; DECISIONS.md (verify rather than assume market-scoped inventory) | reviews: none
- **(2) Functional requirement.** A current written map of Provider Sync, Inventory Location, Pool, Reservation, Checkout, Order, Fulfillment and Channel Updates that names where countries can overlap, stock can be oversold or a hold duplicated, and for each risk the test that pins it or the open owner decision.
- **(3) Exists now in code (main).** Not in main. Branch claude/exciting-shannon-4mh2ok holds E11 (four subsystems, keys, reservation lifecycle, outbox, source selection, concurrency table, findings E11-F01 to F20) and the price and stock map (399 sites, 29 gaps). main has docs/sales-channels/realtime-stock.md (outbox flow). The owner guide does not link E11.
- **(4) Done in the draft PRs.** The map predates the drafts. The docs of #53 (STOREFRONT_FLAG_RUNBOOK.md) and #72/#76 update the product-row and SKU parts only; nothing re-draws the engine flow for the row-per-country design.
- **(5) What remains.** (1) update the map for the row-per-country design; (2) map each cross-country risk (E11-F08, F19, F07; global switches F15) to a pinning test or an owner decision; (3) publish it in a PR and link it from OWNER_PR_GUIDE.md.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `none` Document deliverable: no test applies (-)
- **(8) Dependencies and blockers.** None technical. It is closed by the decisions and slices of C-05, C-14 and C-16.
- **(9) Acceptance criteria.**
  - [CODED] A flow map with file references exists: four subsystems, keys, reservation lifecycle, outbox, source selection (E11 sections 2.1 to 2.9 on claude/exciting-shannon-4mh2ok (not in main or a PR))
  - [CODED] Where countries can overlap is named: locations and pools without country, one provider account, global switches, shared SKU space (E11-F01, F08, F15, F19)
  - [CODED] Where stock can be oversold or a hold duplicated is named with its protections (E11 section 2.17 and E11-F07)
  - [MISSING] Each named cross-country risk has a pinning test or an owner decision in the owner guide, for the post-GCC state (OWNER_PR_GUIDE.md does not mention E11)
- **(10) Next coding task.** Docs branch from main: docs/gcc/INVENTORY_FLOW_AND_COUNTRY_OVERLAP.md with the eight-hop flow; per hop the six-country overlap risk with file references, the test that pins it or MISSING, and the owner decision; link it from docs/gcc/OWNER_PR_GUIDE.md and refresh it when the C-05, C-14 and C-16 slices land.

#### C-18. Torod Point and M5AZN integrations preserved for the Saudi store

- **State: INTEGRATED AND TESTED** | plan lines 254 | DECISIONS.md core foundation (retain Torod, M5AZN and the other integrations); guardrail 1; docs/integrations/README.md | reviews: none
- **(2) Functional requirement.** Torod Point (warehouse: orders, stock sync, webhooks, city mapping) and M5AZN (dropshipping: catalogue import, orders, status polling) keep working for the Saudi store as built, with the GCC chain merged and every switch off.
- **(3) Exists now in code (main).** main: TorodPointAdapter with its fulfillment and lastmile clients, M5aznAdapter, M5aznClient and M5aznProductImporter, the webhook endpoint, the Fulfillment feature and unit suites with every provider call faked, pilot-server compatibility tests, and docs/integrations (torod-point.md, m5azn.md, live-verification checklists). Main alone is red on one clock-dependent Torod discovery test.
- **(4) Done in the draft PRs.** #1 repairs the clock-dependent Torod discovery test (test-only; every PR head cut from main carries the same line, #75 and #83 included; PRs 78, 81, 85 and 86 are cut from the server baseline and have no such test). #72/#76 (switch off) narrow Torod suggestions, its picker and the M5AZN import to the default storefront, with the switch-off behaviour pinned. #83's CI is green on SQLite, MySQL 8 and MariaDB 11.4.2 with the chain merged.
- **(5) What remains.** Merge the Torod test repair of #1 so main's own CI is green. Live verification against the real providers is an owner-run step that no repository shows (UNVERIFIED). The security set and the chain were never run together; they share no file of these integrations (the security set changes SaudiPhone.php only).
- **(6) PRs and commits.** [lavkoo#1](https://github.com/fullstackfull/lavkoo/pull/1) `d664ab4`; [lavkoo#72](https://github.com/fullstackfull/lavkoo/pull/72) `1c779d2`; [lavkoo#76](https://github.com/fullstackfull/lavkoo/pull/76) `0ad49d2`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`
- **(7) Tests.**
  - `ci` TorodAdapterTest, TorodSyncTest, TorodWebhookTest, TorodStockOwnershipTest, PilotServer*Test (all provider calls faked) (PR 83 8ff3410: Fulfillment + security MySQL 8/MariaDB 11.4.2, Tests SQLite 8.2/8.3, Strict-mode MariaDB)
  - `ci` M5aznAdapterTest, M5aznImporterTest, M5aznCatalogSyncTest, M5aznImportWorkflowTest (PR 83 8ff3410: same jobs)
  - `ci` M5aznImporterCodeScopeTest: import parks on a clash in any country with the switch off, only in its import country with it on (PR 83 8ff3410: Catalog tests MySQL 8/MariaDB 11.4.2)
  - `ci` TorodShipmentDiscoveryTest scheduled-run test, repaired by one line (PR 1 d664ab4: sales channels N-Z MySQL 8.0/MariaDB, Tests SQLite (fails on main alone))
- **(8) Dependencies and blockers.** The only red test on main is the clock-dependent Torod discovery fixture; #1 carries the one-line repair. No file of these integrations is changed by both integration trees (#75 and #83).
- **(9) Acceptance criteria.**
  - [MET] A Torod Point event is matched by our invoice number, applied once, never overwrites a newer state and does not leak into another provider's fulfillment (TorodWebhookTest::test_a_repeated_delivery_of_the_same_event_is_stored_and_applied_exactly_once, ::test_an_event_for_our_invoice_number_does_not_leak_into_the_other_providers_fulfillment; PR 83 Fulfillment + security jobs)
  - [MET] A staged M5AZN item becomes one unpublished store product owned by M5AZN with an approved mapping; importing it again updates it; a store product with the same SKU is reported for a person, not adopted (M5aznImporterTest::test_a_staged_product_becomes_an_unpublished_store_product_owned_by_m5azn, ::test_importing_the_same_item_again_updates_it_and_never_creates_a_duplicate, ::test_a_store_product_with_the_same_sku_is_not_adopted_it_is_reported_for_a_human; PR 83)
  - [MET] With the GCC chain merged and every switch off, the whole Fulfillment suites pass on SQLite, MySQL 8 and MariaDB 11.4.2 (PR 83 8ff3410: Fulfillment + security MySQL 8/MariaDB 11.4.2, Tests SQLite 8.2/8.3, Strict-mode MariaDB all success)
  - [MET] With country-scoped codes off the M5AZN import parks an item on a clash with a product of any country, as today; with them on it parks only on a clash in its import country (M5aznImporterCodeScopeTest::test_with_the_switch_off_a_sku_held_by_a_product_of_another_country_still_parks_the_item, ::test_with_the_switch_on_a_sku_held_only_by_another_country_does_not_park_the_item; PR 83 Catalog tests)
  - [MET] The Torod shipment-discovery scheduled-run test passes whatever the date (PR 1 d664ab4 repairs it (fails on main run 43122772278); carried by every PR head and by #83)
- **(10) Next coding task.** On a branch from main add tests/Feature/Fulfillment/TorodPayloadStorefrontOnTest.php: build one Torod and one M5AZN order payload with GCC_STOREFRONTS_ENABLED off and on (Saudi default) and assert the two payloads are byte for byte equal; then, in the combined-tree task, run tests/Feature/Fulfillment and the Catalog M5AZN/Torod classes on MySQL 8 and MariaDB 11.4.2.
- **Open owner decisions.** Merging #1 also changes CLAUDE.md on main (its own note): the owner decides, or only its Torod test line is cherry-picked.
- **Verifier note (not applied automatically).** done_in_drafts corrected by the verifier (applied above).
