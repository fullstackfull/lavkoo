# Threat model — six markets on one installation

The nine classes of harm this programme can do that a single-market shop could not, each with what is at stake, where the
boundary runs, what goes wrong, what stops it today, the test that would FAIL if that control were removed, what is still
open, and who can close it.

It is not a list of fears. Every line under **Pinned by** is a test class and method that exists in this repository, and
`tests/Unit/Architecture/ThreatModelTest` reads this file and fails when one of them does not — so a control that is
deleted, renamed or quietly weakened takes this document red with it, and a class whose control is only an intention has
to say `Pinned by: none` out loud. The classes with nothing pinning them are the point of reading this.

**Scope.** `fullstackfull/lavkoo` (the backend), the two Flutter apps where they consume its answers, and the pilot
server's configuration. **Not** in scope: the physical security of the server, the carriers' own systems, and whatever a
market's legal entity is obliged to do with personal data, which needs a lawyer and not an engineer.

**What "control" means here.** Code on the path that refuses or narrows, in this tree. A document that says what ought to
happen is not a control. Nor is a switch that ships off: a protection that is dormant protects nothing, so where the
control is behind a switch, the line says so, because the day the switch goes on is the day the control is first load-bearing.

---

## 1. country-to-country-data-leaks

- **Asset.** Every row that belongs to one market: carts, orders, products, coupons, themes, legal acceptances, wallet and
  loyalty ledger lines, audit lines.
- **Boundary.** The storefront a request resolves to (`App\Services\Storefront\StorefrontCatalog`, the `storefront`
  middleware) against the one schema all six markets share.
- **Threat.** A read that does not name a market returns another market's rows: a Kuwaiti customer's cart shown in the
  Saudi storefront, a coupon of one market spent in another, an operator of one market reading another's orders.
- **Control.** Every table any migration creates is classified by owner scope in `docs/gcc/table-ownership.json`, and the
  per-market reads narrow by that scope: cart rows by cart group, catalogue by `products.storefront_country`, inventory by
  the market of the order. Behind `gcc.storefronts.enabled`, which ships OFF: with it off the narrowing is removed on
  purpose and every row is seen, which is the Saudi behaviour being preserved.
- **Pinned by.** `TableOwnershipTest::test_every_table_a_migration_creates_is_classified`,
  `StorefrontCartIsolationTest::test_a_cart_shows_only_the_rows_of_the_storefront_it_is_used_from`,
  `StorefrontCartIsolationTest::test_while_storefronts_are_off_every_row_is_seen_and_the_query_is_unchanged`,
  `StorefrontInventoryIsolationTest::test_a_store_order_in_one_country_does_not_come_off_the_other_countrys_stock`
- **Gap.** Turning the switch off AFTER two markets have data removes the narrowing for both, and nothing refuses that
  (M1-18). The analytics rollup has no market dimension at all, so every reach figure an operator sorts a storefront by is
  a platform total, which no scoped cache key can fix (`tracker/coverage-gaps.md`).
- **Owner.** Engineering for the reads; the owner for whether a switch may be turned off once a second market has data.

## 2. unauthorised-staff-access

- **Asset.** Admin and seller panels, the seller API, and every screen that acts on another market's orders, products,
  people or money.
- **Boundary.** An employee's country scope (`admin_country_scopes`, `App\Services\Staff\StaffCountryScope`) and the
  seller's own market scopes (`seller_market_scopes`); the route gates of the admin and vendor route files.
- **Threat.** An employee of one market reads or changes another market's rows; a seller's staff member acts beyond what
  the seller granted; a new admin route ships with no gate at all and is reachable by anyone signed in.
- **Control.** A model scope narrows orders and products to the employee's countries, an order of two countries needs an
  employee who holds both, and an employee restricted to nothing sees nothing rather than everything. Every admin route
  must be gated or listed with a reason. Behind `gcc.staff.country_scopes`, which ships OFF.
- **Pinned by.** `StaffOrderScopeTest::test_an_order_of_two_countries_needs_an_employee_who_holds_both`,
  `StaffOrderScopeTest::test_an_employee_restricted_to_nothing_sees_no_order_at_all`,
  `StaffCountryScopeTest::test_with_the_switch_off_nothing_is_asked_of_the_database_and_nobody_is_narrowed`,
  `AdminRouteAuthenticationTest::test_no_admin_route_is_reachable_without_the_admin_guard`,
  `StaffScopeGrantTrailTest::test_an_employee_whose_every_grant_has_expired_is_restricted_to_nothing_and_never_widened`,
  `StaffExportRouteInventoryTest::test_every_admin_controller_that_hands_over_a_file_is_classified`
- **Gap.** `AdminRouteTableTest`, which fails a NEW unguarded admin route, is not on `main` (M1-16). The seller app's own
  staff screens are proven by the backend's refusals, not by a device run. **The EXPORTS are the measured hole, and the
  figure is now counted rather than estimated: 10 of the 39 admin controllers that hand over a file still hand a
  restricted employee every market's rows** (the employee and role lists, the coupon export, the operational costs and
  their reports, a courier verification log, a theme version, a delivery man's withdrawals, the VAT rates and the file
  manager), and this matters more than the equivalent hole in a screen because a downloaded file cannot be taken back.
  One of the ten is unreachable by ANY country scope by construction -- the file manager serves a file of the storage by
  its NAME, with no row behind it to carry a market -- so that one is M1-09's upload surface and not a scoping task.
  Of the screens, slices 1 to 5 are in and slice 6 (sales channels, fulfilment providers, operational costs) is not
  started; and an employee cannot be restricted by legal ENTITY at all, since `admin_country_scopes` has no entity
  column (G-13).
- **Owner.** Engineering: the scopes and the route gates are code, and the switch they sit behind is the owner's to turn on per market.

## 3. mixed-money-and-balances

- **Asset.** `users.wallet_balance`, `users.loyalty_point`, the two ledgers, the per-market balance accounts, vendor ledger
  entries, refunds.
- **Boundary.** The writer of a money column, and the market and legal entity a balance belongs to.
- **Threat.** One market's credit spent in another; a balance written by a path nobody counted, so the ledger and the
  column drift; a refund paid from a market that did not take the money.
- **Control.** The balance columns have a NAMED set of writers and every other file that mentions one is listed with the
  KIND of its mention, so a listed file still fails when a real write appears in it. The per-market accounts ship OFF and
  `users.wallet_balance` stays the authority until the owner adopts them; `wallet:reconcile` proves, read-only, that every
  balance equals the ledger rows behind it, and exits 1 while any difference is unexplained.
- **Pinned by.** `WalletBalanceWritersTest::test_the_wallet_balance_is_written_by_its_five_writers_and_nothing_else`,
  `WalletBalanceWritersTest::test_the_loyalty_points_are_written_by_their_two_writers_and_nothing_else`,
  `WalletBalanceWritersTest::test_the_migrations_that_name_a_balance_column_change_its_shape_and_write_no_balance`,
  `WalletCurrencyConsistencyTest::test_api_wallet_order_is_placed_only_when_the_balance_covers_the_usd_debit_in_whole_cents`
- **Gap.** `WalletLedgerCharacterizationTest` pins a KNOWN drift between the ledger and the column: it is recorded, not
  fixed, and a backfill must not start until it is explained (M2-17). `total_spent` sums `order_amount` across orders
  carrying different `currency_code`s, which is a figure with no unit.
- **Owner.** The owner for the drift and for what a loyalty point is worth (LF-1); engineering for the writers.

## 4. leaked-integration-keys

- **Asset.** Carrier, marketplace, payment, mail, SMS and push credentials; the application key; one-time codes.
- **Boundary.** The repository and its history; the log files; anything a monitoring page or an API answer prints.
- **Threat.** A key committed, or written to a log by a connector that logs its own requests, and then read by anyone with
  the repository or the log — which for a public repository is anyone at all.
- **Control.** A CI job refuses a pull request whose diff carries a secret or a personal detail, by the FORM the value
  takes rather than by a list of names, and refuses a file that must never be committed wherever it sits. The
  sales-channel suites are guarded against a credential reaching a log, because only a real call shows a leak: the Odoo API
  key reached `sales_channel_api_logs` on every read and nothing noticed. The one-time-code paths log neither the code nor
  the message.
- **Pinned by.** `LeakGuardTest::test_a_secret_or_personal_detail_is_recognised_in_the_form_it_takes`,
  `LeakGuardTest::test_a_file_that_must_never_be_committed_is_refused_by_name_wherever_it_is`,
  `OtpLoggingTest::test_the_vendor_password_reset_logs_no_code`,
  `StoredUploadPolicyTest::test_the_document_helper_never_stores_a_name_a_server_would_run_or_render`
- **Gap.** Neither Flutter repository has a secret scan on its default branch, and the customer app has no CI at all there
  (M1-V2) — the two repositories a leaked key would most plausibly be committed to. No key has been rotated (DR-1), so a
  key that leaked before any of this is still live.
- **Owner.** The owner for rotation; engineering for the app repositories' CI.

## 5. currency-context-errors

- **Asset.** Every stored amount, every amount shown, every amount charged or refunded.
- **Boundary.** The currency of the ORDER against the currency of the request, and the decimals of each currency.
- **Threat.** A riyal figure shown as a dinar, a three-decimal amount rounded to two on the way in, a refund in the wrong
  currency, a rate read for the market of the request when the order belongs to another.
- **Control.** The decimals come from the currency the row names, not from an operator's setting; a rate or a switch that
  belongs to a market is read for the market of the ORDER, through `StorefrontCurrency::withinOrder()`, and the order is
  read with the query builder because the model carries a staff scope that would hide it from a restricted employee. The
  provider money columns carry three decimals so a fils amount is not rounded away on the way in.
- **Pinned by.** `StorefrontCurrencyTest::test_a_storefront_answers_the_three_money_settings_for_its_own_currency`,
  `StorefrontCurrencyTest::test_every_storefront_reads_the_platform_settings_while_the_switch_is_off`,
  `OrderCurrencyOnEverySurfaceTest::test_a_dinar_order_keeps_the_three_decimals_of_its_currency_over_an_operator_who_set_two`,
  `FulfillmentMoneyThreeDecimalsTest::test_the_decimals_come_from_the_currency_the_money_is_in`
- **Gap.** Saving a product price from the admin while a three-decimal market is in force, and reading it back on the
  catalogue and in the cart, is proved by no test (M2-07).
- **Owner.** Engineering, with one exception the owner holds: which currency a courier settles in (E11-F02), where a mislabelled shipment is held rather than sent.

## 6. overlapping-stock-or-reservations

- **Asset.** `products.current_stock`, the variant quantities, `provider_product_mappings.provider_stock`,
  `inventory_location_balances`, `inventory_reservations`, the stock outbox.
- **Boundary.** One physical shelf against several markets and several sales channels selling from it.
- **Threat.** Two markets sell the same unit; a hold is taken and never released, so units are held for ever; the
  storefront offers more than the provider has; a stock change dies in the outbox and no quantity is ever corrected.
- **Control.** A channel order's units come off the figure the storefront is told it has, and store orders and channel
  orders both come off without either covering for the other. `monitoring:reconcile-stock` compares, read-only, every hold
  with the order that justifies it and each provider's count with what the storefront offers, and exits 1 while anything is
  unexplained.
- **Pinned by.** `SharedProviderStockTest::test_store_orders_and_channel_orders_both_come_off_and_neither_covers_for_the_other`,
  `SharedProviderStockTest::test_a_channel_order_with_an_internal_order_of_its_own_is_counted_once_not_twice`,
  `StockReconciliationTest::test_a_storefront_that_offers_more_than_the_provider_has_is_reported_with_the_units`,
  `StockReconciliationTest::test_a_hold_whose_order_is_finished_is_reported_and_a_hold_of_an_open_order_is_not`
- **Gap.** `PoolKey` carries no country, so the channel stock pools and publications are not per market (M2-08, M2-12).
  Four restock defects are pinned as current behaviour and not fixed (M2-08). `inventory_location_balances` is compared
  with nothing.
- **Owner.** Engineering, except which carrier serves which country under which contract (Q-33), which decides whose shelf is shared at all.

## 7. theme-or-cache-leaks-between-countries

- **Asset.** Theme settings, home content, categories, campaign banners, business settings — everything cached.
- **Boundary.** The cache key: one Redis or file store serving six markets, where a key that names no market is a key they share.
- **Threat.** The first market to warm a key serves its own content to the other five, which is both a brand leak and,
  where the cached payload holds prices, a money one. It survives a deploy, because a cache does.
- **Control.** Every cache key of the theme code is either the storefront's own or DECLARED global, the declaration is
  checked against the code both ways, and no declared global key may also be written under a storefront. The Saudi
  storefront keeps the unscoped key it always had, so its cache is not cold after the deploy.
- **Pinned by.** `ThemeCacheKeysAreStorefrontAwareTest::test_every_cache_key_of_the_theme_code_is_the_storefronts_own_or_declared_global`,
  `ThemeCacheKeysAreStorefrontAwareTest::test_no_declared_global_key_is_also_written_under_a_storefront`,
  `StorefrontCacheScopeTest::test_home_categories_are_cached_under_the_storefront_that_asked_for_them`,
  `StorefrontCacheScopeTest::test_the_saudi_storefront_keeps_the_home_categories_key_it_always_had`
- **Gap.** The scan covers the theme code; a key invented outside those roots is not read. Every test run pins
  `CACHE_DRIVER=array` and `QUEUE_CONNECTION=sync`, so no test uses a SHARED cache store or a real worker, which is the
  arrangement a leak between markets would actually happen in (M2-18).
- **Owner.** Engineering for the keys; the pilot server's operator for the cache store the six markets will actually share.

## 8. notifications-to-the-wrong-audience

- **Asset.** E-mail, SMS, WhatsApp and push messages, their sender identity, their language and their recipients.
- **Boundary.** The market of the ORDER or of the customer against the account a message leaves through and the topic it is
  published to.
- **Threat.** A Kuwaiti order's e-mail signed by the Saudi company and sent from a domain that does not belong to it; a
  marketing push reaching all six markets because the topic is platform-wide; a one-time code leaving through another
  market's gateway.
- **Control.** One account per channel per market (`notification_channel_profiles`, keyed `(channel, country)` with `''`
  for the platform-wide row), read for the market recorded on the order, including from a queued listener. Push topics are
  per market and the set of topics the code may name is a list the scan checks both ways. The accounts ship OFF
  (`GCC_NOTIFICATION_PROFILES`): with the switch off every message leaves exactly as it does today.
- **Pinned by.** `MarketMailAccountTest::test_an_email_about_a_kuwaiti_order_leaves_through_the_kuwaiti_hosts_and_from_address`,
  `MarketMailAccountTest::test_a_queued_listener_uses_the_account_of_the_market_recorded_on_the_order`,
  `MarketMailAccountTest::test_while_the_switch_is_off_a_markets_own_account_is_not_read`,
  `PushTopicNamesTest::test_the_code_names_no_topic_that_is_not_on_the_list_and_none_of_the_literal_topics_is_gone`
- **Gap.** None of the 27 `ShouldQueue` classes names a storefront of its own: a queued message is correct because the
  order it reads carries the market, not because the job does (M2-13, M2-18). A market that has stopped selling still has
  to notify the orders it already took, and that is proved for the stop path only.
- **Owner.** Engineering; the per-market topics and the separation of marketing are a decision the owner has already taken (A1-17).

## 9. mixed-webhooks-and-historical-orders

- **Asset.** Payment callbacks, carrier status callbacks, marketplace order imports, and the orders they settle — including
  orders placed before any of this existed.
- **Boundary.** The market a payment was STARTED in, carried through a callback that names no market at all.
- **Threat.** A gateway reports a payment and the order is built in the market of whoever happens to be asking; a second
  report of one payment builds a second order; a callback for an old Saudi order is read as a new market's; a market
  switched off mid-payment loses the money.
- **Control.** The settlement runs inside the market the payment was started in, and the scope that stops a second order
  is in the same path. A payment started in a storefront that was switched off since is still finished, because a customer
  who has paid is not a configuration question.
- **Pinned by.** `StorefrontPaymentCallbackTest::test_two_payments_of_two_markets_settled_one_after_the_other_each_build_their_own`,
  `StorefrontPaymentCallbackTest::test_a_second_report_of_one_payment_builds_no_second_order_even_with_a_cart_to_build_from`,
  `StorefrontPaymentCallbackTest::test_a_paid_kuwait_cart_becomes_an_order_although_the_callback_names_no_storefront`,
  `StorefrontPaymentCallbackTest::test_a_payment_started_in_a_storefront_that_was_switched_off_since_is_still_finished`
- **Gap.** A refund and a webhook replay are proved for Saudi Arabia and Kuwait only, not for all six markets (M2-11).
  Marketplace order import sits outside the sale gate, so what happens to a paused market's channel orders is an open
  owner decision (I2-09).
- **Owner.** The owner for the paused-market channel orders; engineering for the remaining four markets' callbacks.

---

## The classes no control is pinned to

None. All nine carry at least one control with a test that fails when the control is removed — which is NOT the same as
saying the nine are closed: every one of them has a **Gap** line, and four of those gaps are the thing that would actually
go wrong first:

1. **the switch that cannot be turned off again** (class 1) — the narrowing is removed for every market at once, and
   nothing refuses it once a second market has data;
2. **neither app repository scans for a committed secret** (class 4), and nothing has been rotated;
3. **no test uses a shared cache store or a real worker** (class 7), which is the only arrangement in which a cache leak
   between markets can be observed at all;
4. **no queued job carries a market of its own** (class 8) — correctness rests on every job reading an order that happens
   to carry one.

## What this document is not

It is not a security review. `docs/gcc/REVIEW_GATES.md` records that no review in the register has been requested or
done, and that nobody is named for the security role. A reviewer reading this should start from the four gaps above and
from `docs/gcc/DEFERRED_RISKS.md`.
