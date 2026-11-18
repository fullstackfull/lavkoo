# Axis K: Flutter seller app

Detail cards of the master tracker ([`MASTER_IMPLEMENTATION_TRACKER.md`](../MASTER_IMPLEMENTATION_TRACKER.md)), as of 2026-11-18. Requirements: 23. Acceptance criteria: 91 (MET 5, CODED 36, MISSING 50). Code completion 45%, proven by a passing test 5%, release readiness 2%.

Independent verification: done for unit(s) K.

Every card gives the ten fields of the directive: (1) id and name, (2) functional requirement, (3) what exists in main, (4) what the draft pull requests did, (5) what remains, (6) pull requests and commits, (7) tests that exist and where they ran, (8) dependencies and blockers, (9) acceptance criteria marked MET (a named passing test or CI job on a named head), CODED (code exists, no passing test cited) or MISSING, (10) the next coding task.

#### K-01. Seller market scope and X-Storefront header (default off)

- **State: IMPLEMENTED IN DRAFT** | plan lines 173-193; 481-485 | GCC-19; GCC-20 doc 'Country / storefront drill-down' | reviews: owner-decision
- **(2) Functional requirement.** The seller app can name one of the six GCC markets (SA, AE, KW, QA, BH, OM) as the storefront of its requests through an X-Storefront header. All Markets is a viewing scope that never selects a storefront and has no currency. With the build flag off, every request is exactly today's Saudi request.
- **(3) Exists now in code (main).** Nothing on main. DioClient sends Authorization, Content-Type, Accept and three language headers only; the seller app has no market type, flag or header. In lavkoo main nothing reads X-Storefront either (git grep of app, config and routes finds no hit).
- **(4) Done in the draft PRs.** app#1 (964557e): MarketScope enum (six markets plus All Markets, one currency each, default SA), StorefrontConfig (dart-define GCC_STOREFRONTS_ENABLED, default false), DioClient adds X-Storefront from the stored market only when the flag is on (missing, unknown or All Markets fall back to SA). 13 tests in 2 files. Backend header: lavkoo#28 and the storefront chain (customer routes), default off.
- **(5) What remains.** (4) The job is red. The 2026-11-13 handoff section 28 records 155 tests run on #1, 154 passed and 1 failed (app_version_gate_test), so the 13 new tests ran and passed; the criteria stay CODED because the job conclusion is failure and per-test results are not in the data pack. PR body: Dart not run locally.
- **(6) PRs and commits.** [lavkooappseller#1](https://github.com/fullstackfull/lavkooappseller/pull/1) `964557e`; [lavkoo#28](https://github.com/fullstackfull/lavkoo/pull/28) `8c8d32a`
- **(7) Tests.**
  - `pr-body` dio_storefront_header_test (8: flag off keeps today's header keys and values; resolver falls back to SA) and market_scope_test (5: six currencies, All Markets has none) (lavkooappseller#1 964557e; analyze-and-test red, handoff 2026-11-17 says only the version-gate test fails (step log not in the data pack, UNVERIFIED); PR body: Dart not run locally)
- **(8) Dependencies and blockers.** Owner decision Q-18 (one app with a market switcher, or one build per country). Backend seller v3 must read the header for it to matter (K-17). Release is blocked by the seller version-gate test (K-14), not coding. No seller-app integration tree exists.
- **(9) Acceptance criteria.**
  - [CODED] Flag off: the DioClient header map has exactly the keys and values main has (Authorization, Content-Type, Accept, lang, X-localization, Accept-Language) whatever market is stored (test/dio_storefront_header_test.dart on app#1 964557e; job analyze-and-test red, step UNVERIFIED)
  - [CODED] Flag on: X-Storefront carries the stored market code; a missing, unknown or All Markets value falls back to SA (StorefrontConfig.resolveHeaderValue tests (resolver level only; no DioClient test with the flag on))
  - [CODED] All Markets never produces a header or a country code and has no currency, so no amount is summed across currencies (test/market_scope_test.dart; no aggregate helper in lib/features/storefront)
  - [CODED] The flag is off in every default build and needs an explicit dart-define to turn on (bool.fromEnvironment(..., defaultValue: false) in storefront_config.dart; test 'the storefront flag is off by default')
- **(10) Next coding task.** app#1 branch gcc/p1-seller-storefront-header: make the flag an injectable DioClient parameter (default StorefrontConfig.enabled, as CartRepository already does), add flag-on header tests and the SA fallback through DioClient, and add a CI step running test/dio_storefront_header_test.dart with --dart-define=GCC_STOREFRONTS_ENABLED=true (see K-15).
- **Open owner decisions.** Q-18: one seller app with a market switcher (header or scoped token) or one build per country; whether any seller works in more than one market (U6).
- **Verifier note (not applied automatically).** remaining corrected by the verifier (applied above).

#### K-02. Seller market choice: storage and manual precedence

- **State: IMPLEMENTED IN DRAFT** | plan lines 187-189 | GCC-19 | reviews: owner-decision
- **(2) Functional requirement.** A seller works in one market at a time. Nothing chosen means Saudi Arabia, a stored choice is read back after a restart, All Markets is refused as a working market, an unknown or non-text stored value falls back to SA, and nothing infers the market from IP, locale or language. With the flag off there is no market to choose and nothing is written.
- **(3) Exists now in code (main).** Nothing on main: the stored keys are token, e-mail, language, currency, theme, cart_list, the POS outbox and a few UI helpers; there is no market key. The lang header carries a language (ar or en), never a country.
- **(4) Done in the draft PRs.** app#2 (e5fd716, stacked on #1): SellerMarketChoice.current() and choose(scope) over the SharedPreferences key storefront_country, gated by the same flag; 7 tests. app#5 reads it for the POS scan country. Nothing in the app calls choose() yet.
- **(5) What remains.** (1) No screen writes the choice (K-03), so with the flag on every seller is SA. (2) The key is device-wide, not bound to the signed-in seller (K-12). (3) The server must validate the market against the seller's markets (K-09). (4) #2 is stacked, so it has no CI run: its tests have no result on record.
- **(6) PRs and commits.** [lavkooappseller#2](https://github.com/fullstackfull/lavkooappseller/pull/2) `e5fd716`; [lavkooappseller#1](https://github.com/fullstackfull/lavkooappseller/pull/1) `964557e`
- **(7) Tests.**
  - `pr-body` seller_market_choice_test (7: off => All Markets and no write; on => SA by default, a saved choice survives a new instance, All Markets refused, unknown or non-text value => SA) (lavkooappseller#2 e5fd716: no CI run (stacked on #1) and the PR body says not run locally, so no run is on record)
- **(8) Dependencies and blockers.** Stacked on app#1; no CI until retargeted to main (K-15). Q-18 decides whether the app has a switcher at all.
- **(9) Acceptance criteria.**
  - [CODED] Flag on and nothing stored: the seller works in Saudi Arabia (seller_market_choice_test 'nothing chosen yet works in Saudi Arabia'; app#2 e5fd716, no run on record)
  - [CODED] A saved choice is read back by a new instance (survives a restart) (test 'choosing a market saves it, and it survives a new instance')
  - [CODED] All Markets cannot be saved as the working market; an unknown or non-text stored value falls back to SA (tests 'All Markets is refused and writes nothing', 'an unknown stored code falls back to Saudi Arabia')
  - [CODED] Flag off: every read answers All Markets and nothing is written (group 'while storefronts are off' in seller_market_choice_test)
  - [CODED] The market is never inferred from IP, GPS or locale: a manual choice always wins (git grep of lib on app#5 finds no IP, GPS or locale lookup that sets a market; dial codes come from config.country_code; no test pins it)
- **(10) Next coding task.** Retarget app#2 to main once #1 merges (or add 'gcc/**' to the workflow's pull_request branches, K-15) and add a named CI step for test/seller_market_choice_test.dart so its seven tests get a CI result. No code change needed.
- **Open owner decisions.** Q-18 and U6: whether the seller app has a market switcher and whether a seller may work in more than one market.

#### K-03. Market selector, live header update and market-switch hygiene

- **State: NOT STARTED** | plan lines 182; 189; 481-485 | GCC-20 doc 'Acceptance criteria' (country switching flushes scoped caches) | reviews: owner-decision
- **(2) Functional requirement.** A seller can pick the market in the app. The pick reaches the next request without a restart, on every request (Dio and the nine multipart sites), and everything held for the previous market is dropped: lists, the POS cart, notifications. Unsent offline sales stay tied to the market they were taken in.
- **(3) Exists now in code (main).** Nothing: no picker and no market label on any screen. The single lazy-singleton DioClient fixes its headers when it is built (lib/di_container.dart), nine http.MultipartRequest sites build their own headers, and cart_list and the offline POS outbox are device-global keys.
- **(4) Done in the draft PRs.** Nothing. app#1 and #2 store and read the choice but no screen writes it; the app#5 body says the market picker does not exist yet.
- **(5) What remains.** Picker screen (six markets, RTL and LTR, light and dark); DioClient.useStorefront updating headers and the stored choice together; one header helper for the nine multipart sites; flush or per-market keys for cart_list, lists and notifications; a market label on money screens; tests and a device run.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `none` none exists for a switch, a picker or the multipart sites (-)
- **(8) Dependencies and blockers.** Builds on app#1 and #2 (open drafts). Owner decision Q-18 (switcher in one app or one build per country). Backend v3 must honour the header (K-17).
- **(9) Acceptance criteria.**
  - [MISSING] A seller picks one of the six markets on a screen; the pick is saved and the market is visible on money screens (no picker or label in any head tree: git grep SellerMarketChoice on app#5 finds only cart_repository reading it)
  - [MISSING] After a switch the next request carries the new X-Storefront value without restarting the app (DioClient reads storefront_country once in its constructor and is a lazy singleton)
  - [MISSING] A multipart call (product image, shop update, digital file) carries the same market header as a JSON call (nine http.MultipartRequest sites build their own headers)
  - [MISSING] A switch drops market-scoped state (POS cart, lists, notifications); a queued offline sale keeps the market it was taken in (cart_list and offline_pos_orders are device-global keys)
- **(10) Next coding task.** New branch stacked on gcc/p1-seller-market-choice: add DioClient.useStorefront(MarketScope) (updates the header map and the stored choice together), one StorefrontHeaders helper used by the nine multipart call sites, and tests in test/dio_storefront_header_test.dart for a live switch and for each multipart site. No UI yet; flag stays off.
- **Open owner decisions.** Q-18 (switcher or separate builds); whether All Markets is a view in the seller app at all (the owner overview is the Super Admin shell, unit L).

#### K-04. Per-market data in seller screens (orders, products, stock, finance)

- **State: NOT STARTED** | plan lines 197-217; 219-231; 233-258; 277-289 | GCC-09 (audit seller-app API impact); GCC-14; guardrail 4 | reviews: owner-decision, finance
- **(2) Functional requirement.** Orders, products, stock, finance and POS in the seller app show one market's data at a time, in that market's currency. A product created from the app belongs to the market the seller is working in. No screen mixes markets or adds figures across currencies.
- **(3) Exists now in code (main).** Main has no market anywhere: seller API v3 serves every order and product of a seller and the app keeps no data per market. The GCC drafts pin the same status quo: seller APIs are never narrowed, X-Storefront does not reach api/v3, a seller product takes the table default SA, seller payloads hide storefront_country.
- **(4) Done in the draft PRs.** Nothing for these screens. Only pins: SellerSurfacesCountryTest (lavkoo#76) fails the day a seller can hold products in two countries; the POS scan takes a country query (K-05).
- **(5) What remains.** Owner model for sellers in markets (Q-18, U6). Then: storefront resolution on v3, storefront and currency on every order and product row (orders wait for the order market and currency slice), market filter and label in the app, product creation in the working market, per-market stock and finance totals, two-market tests per screen.
- **(6) PRs and commits.** [lavkoo#76](https://github.com/fullstackfull/lavkoo/pull/76) `0ad49d2`; [lavkoo#72](https://github.com/fullstackfull/lavkoo/pull/72) `1c779d2`; [lavkoo#108](https://github.com/fullstackfull/lavkoo/pull/108) `11a9707f`
- **(7) Tests.**
  - `ci` SellerSurfacesCountryTest (pins that no seller path writes a country and fails when a seller can hold two countries) (lavkoo#83 8ff3410: Catalog tests MySQL 8 and MariaDB 11.4.2, Tests SQLite PHP 8.2/8.3 success; also #76 0ad49d2 SQLite)
- **(8) Dependencies and blockers.** Owner decision Q-18/U6. Backend: the order market and currency slice (GCC-05, now lavkoo PR 87, head 4af1c305, slices 1-4, not read in this unit) for orders. ProductController v3 and Product.php are touched by both the security set and the GCC set; a combined tree exists on gcc/release/security-gcc-integration-ci (f6fb03af, no pull request) but its CI is not read, so there is no CI-proven joint behaviour yet.
- **(9) Acceptance criteria.**
  - [CODED] The seller order list and detail carry the storefront and currency of each order and can be filtered by market (PR 108 (11a9707f, off by default): while storefronts are on, the seller order list and the lines of the order detail carry storefront_country and currency_code (null when the order has no record, never made up), and the list takes a storefront filter that only narrows the seller's own orders (an unknown or malformed value answers an empty list, never all orders; the default market also holds the orders with no record); while storefronts are off the answers are what they were, compared key for key with answers recorded from the controller before it changed, in two database states (SellerOrderMarketTest, 38 tests); the seller app does not show or filter by it yet; CI not read)
  - [MISSING] A product added or edited from the app is saved in the market the seller works in, not the table default SA (GCC-14B doc: no seller path writes a country; SellerSurfacesCountryTest pins the status quo)
  - [MISSING] Stock and finance screens total per market and per currency; an All Markets view never adds figures across currencies (no market dimension in any seller model; MarketScope has no consumer on these screens)
- **(10) Next coding task.** lavkoo, stacked on gcc/p1-country-scoped-codes-2: return storefront_country on the seller product list and detail answers (additive; hidden today, identical with the flag off) with a six-market test in tests/Feature/Catalog; app: show it as a tag on the product list behind GCC_STOREFRONTS_ENABLED. Orders wait for the order market and currency slice.
- **Open owner decisions.** Q-18 and U6: does one seller account trade in several markets (switcher), or does each market get its own seller account and legal entity; which seller data is global (profile, bank) and which per market.
- **Verifier note (not applied automatically).** deps_blockers corrected by the verifier (applied above).
- **Update after the assessment.** PR 108 (draft, stacked on PR 87, 11a9707f, off by default): the seller order list and detail carry the storefront and currency of each order while storefronts are on and the list can be narrowed to one market. Not done: products created in the working market (K-04.1), per-market stock and finance totals (K-04.2), the other seller endpoints that return orders, and everything in the app.

#### K-05. POS scan with country-scoped codes

- **State: IN PROGRESS** | plan lines 219-231; 489-491 | GCC-14b (country-scoped SKU and barcode); GCC-19 | reviews: native-arabic, owner-decision
- **(2) Functional requirement.** A POS scan resolves the code in the seller's own market. A code held twice answers 409 with candidates and the app asks the seller which product, never picking one; a country the server does not know answers 422 and is explained. With the flag off the request is today's ?code=<code> only.
- **(3) Exists now in code (main).** Main sends GET api/v3/seller/pos/products?code=<code> and treats only 200 as a product. The camera scanner is restricted to Code 128. An empty answer reaches Product.fromJson, which throws, so nothing is shown.
- **(4) Done in the draft PRs.** app#5 (90b9d33, stacked on #2): the scan adds &country=<market> only with the flag on, reads 409 ambiguous_code (candidate sheet; tap a country to rescan, or open the product list) and 422 country (server text or a localized fallback), 11 new en/ar keys, 106 tests. Server: lavkoo#72 (GCC_COUNTRY_SCOPED_CODES, off) and #76.
- **(5) What remains.** (1) A scan that finds nothing stays silent: the existing key product_not_found (en and ar, already used by the action center and the restock list) is not used by the scan flow. Criterion 5 evidence: 'app#5 body: the empty answer is unchanged on purpose; product_not_found exists but the scan does not use it'.
- **(6) PRs and commits.** [lavkooappseller#5](https://github.com/fullstackfull/lavkooappseller/pull/5) `90b9d33`; [lavkooappseller#2](https://github.com/fullstackfull/lavkooappseller/pull/2) `e5fd716`; [lavkoo#72](https://github.com/fullstackfull/lavkoo/pull/72) `1c779d2`; [lavkoo#76](https://github.com/fullstackfull/lavkoo/pull/76) `0ad49d2`
- **(7) Tests.**
  - `pr-body` test/pos_scan (106: request per flag state, 409 and 422 parsing, candidate sheet in en and ar, light and dark, 320x640 at 2x text, controller, strings, source rules) (lavkooappseller#5 90b9d33: no CI (stacked); PR body: flutter test 267 pass, 1 fail (version gate) on a clean export)
  - `ci` PosScanCodeScopeTest and SixCountryPosScanTest (switch off and on, 409 candidates, 422, six countries) (lavkoo#83 8ff3410 Catalog tests MySQL 8 and MariaDB 11.4.2, Tests SQLite 8.2/8.3; #72 1c779d2 and #76 0ad49d2 SQLite)
- **(8) Dependencies and blockers.** Backend switch GCC_COUNTRY_SCOPED_CODES stays off until the app sends country and handles 409 and 422 (#72 and #76 guides). Stacked on #2 and #1. POSController.php is changed by both #52 (security) and #72 (GCC); a combined tree now exists (gcc/release/security-gcc-integration-ci, f6fb03af, no pull request) but its CI run is not read, so the joint behaviour has no CI record.
- **(9) Acceptance criteria.**
  - [CODED] Flag off: the scan request is ?code=<code> with no country, whatever market is stored (test/pos_scan/cart_repository_scan_test.dart on app#5 90b9d33; no CI run, PR-body run only)
  - [CODED] Flag on: the request carries &country=<working market> (SA while nothing is stored); a country picked in the sheet is sent on that one rescan (cart_repository_scan_test and pos_scan_source_rules_test (country query pinned))
  - [CODED] 409 ambiguous_code adds nothing and lists the candidates with their country; 422 country shows the server's sentence or a localized fallback and asks nothing again (ambiguous_code_sheet_test, barcode_scan_controller_test, product_scan_refusal_test)
  - [MET] Server: the scan is narrowed to the requested or default country and answers 409 and 422 as above (PosScanCodeScopeTest, SixCountryPosScanTest; lavkoo#83 8ff3410 Catalog tests MySQL 8, MariaDB 11.4.2 and Tests SQLite success)
  - [MISSING] A scan that finds nothing in this market tells the seller (app#5 body: the empty answer is unchanged on purpose; key product_not_found exists unused)
  - [MISSING] A retail EAN-13 or UPC-A code can be read by the camera (barcode_scan_controller.dart: restrictFormat: [BarcodeFormat.code128])
- **(10) Next coding task.** app#5 (gcc/seller-pos-ambiguous-code): show product_not_found for an empty answer only when the flag is on (default build unchanged and pinned), allow EAN-13, UPC-A and Code 128 in restrictFormat behind the flag with controller tests, percent-encode code and country, and add a named CI step for test/pos_scan (K-15).
- **Open owner decisions.** GCC-14B open decision 2: who changes the seller app to send country and handle 409 and 422, and when; whether a not-found scan should speak; whether Code 128 only is acceptable once two countries share an EAN.
- **Verifier note (not applied automatically).** deps_blockers corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** remaining corrected by the verifier (applied above).

#### K-06. POS sale safety: retries, offline outbox and customer id

- **State: IN PROGRESS** | plan lines 241-252; 277-289; 499-500 | GCC-13; guardrail 4 | reviews: finance, security
- **(2) Functional requirement.** A POS sale is placed once even when the answer is lost and the sale is replayed. A queued offline sale is replayed only under the seller and market that took it. A refusal answered with HTTP 200 is not shown as a placed order. A registered customer is never sent as a walk-in.
- **(3) Exists now in code (main).** Main parks a sale in the device-global outbox (offline_pos_orders) when the response is null and replays the same body up to 10 times, after which the entry is dropped from the outbox without any message to the seller (offline_sales_controller.dart:139,142); any 200 counts as success or synced; the app sends no Idempotency-Key; a customer whose id has four digits is sent as user_id 0 (pos_screen.dart:827).
- **(4) Done in the draft PRs.** App: nothing. Server half: lavkoo#35 (7c75b7f) charges the server total in the store currency and answers a repeated Idempotency-Key with the first order, debited once (table pos_sale_idempotency_keys); #30 refuses a non-positive wallet amount. Neither is in the PR 75 or PR 83 tree.
- **(5) What remains.** App sends a stable Idempotency-Key per sale (kept in PendingPosOrder, replayed unchanged); outbox entries record seller and market and replay only under them; 200 product_not_available is read as a refusal and not marked synced; the four-digit walk-in test becomes an explicit null check; flow tests; finance review of the wallet sale.
- **(6) PRs and commits.** [lavkoo#35](https://github.com/fullstackfull/lavkoo/pull/35) `7c75b7f`; [lavkoo#30](https://github.com/fullstackfull/lavkoo/pull/30) `4f66dce`
- **(7) Tests.**
  - `ci` PosWalletSaleTest (17) and PosSaleIdempotencyConcurrencyTest (two submits, one key) (lavkoo#35 7c75b7f: Tests SQLite 8.2/8.3, concurrency group MySQL 8.0 and MariaDB 11.4.2 success; not in #75 or #83)
  - `pr-body` pending_pos_order_test (encode and decode round trip, copyWith) (main; main CI at 9ea6423 had 141 pass and only the gate test red (seller-version audit s.3); step log not in the data pack)
- **(8) Dependencies and blockers.** Backend #35 (7c75b7f) is not merged and is in neither the PR 75 nor the PR 83 tree; it is only in gcc/release/all-integration-ci (built 2026-11-18, CI not read). Finance review before merge (charter s.5). POSController.php is touched by both the security and the GCC set.
- **(9) Acceptance criteria.**
  - [MET] Server: a repeated Idempotency-Key answers the first order and debits the wallet once; a wallet sale debits the server total in the store currency, not the client's paid_amount (PosWalletSaleTest, PosSaleIdempotencyConcurrencyTest; lavkoo#35 7c75b7f CI (SQLite, MySQL 8.0, MariaDB 11.4.2 concurrency))
  - [MISSING] The app sends one stable Idempotency-Key per sale and replays it unchanged from the offline outbox (no 'Idempotency' string in lib or test on main or any seller head (git grep))
  - [MISSING] A queued sale is replayed only under the seller and market that took it, and is never deleted silently at sign-out (PendingPosOrder has no seller or market; storage key offline_pos_orders is device-global)
  - [MISSING] A 200 answer that refuses the sale (product_not_available) is not shown as a placed order and not counted as synced (cart_controller.dart and offline_sales_controller.dart treat any 200 as success; audit 2026-11-17 row 16)
  - [MISSING] A registered customer whose id has four digits is not sent as a walk-in (pos_screen.dart: customerId.toString().length == 4 ? 0 : customerId (Discovery E20-F14))
- **(10) Next coding task.** app, new branch off main (independent of the market chain): add Idempotency-Key (one UUID per sale stored in PendingPosOrder), read 200 product_not_available as a refusal, replace the four-digit walk-in test with an explicit null check, with tests in test/pos_sale using the scripted adapter of test/pos_scan/support. Servers without #35 ignore the header.
- **Open owner decisions.** Finance owner: wallet debit rounding (D18) and the units of paid_amount (PR 35 'Not changed, open'); how long unsent outbox entries are kept.
- **Verifier note (not applied automatically).** exists_now corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** deps_blockers corrected by the verifier (applied above).

#### K-07. Order edit refusal handling and approved-only review average

- **State: IN PROGRESS** | plan lines 537-562; 700-716 | DECISIONS guardrail 4 (preserve seller behaviour; audit API v3/seller) | reviews: owner-decision
- **(2) Functional requirement.** A refused order edit (HTTP 200 with status error) keeps the edit screen open, stops the spinner, does not reload the order and shows the server's message. A saved edit still closes the screen. Ratings the seller sees count approved reviews only, and no refusal body can crash the app.
- **(3) Exists now in code (main).** Main judges edit-order-submit by status code alone: a refused 200 reloads the order and closes the screen as if saved. The add-product sheet averages every review in the product JSON and dereferences reviews with !, so after backend #51 unapproved reviews count.
- **(4) Done in the draft PRs.** app#3 (7e9f674): OrderEditSubmitResult (saved, refused, failed); editOrderSubmit returns it and shows a refusal as an error toast (generic localized text if none); EditProductScreen pops only when saved; ProductHelper.getApprovedReviewAverage feeds the sheet. 17 new tests. Backend refusals: #52 (order not editable, product-less line).
- **(5) What remains.** The POS variation dialog and product_widget.dart still average every review; the edit icon's gate (order_top_section_widget) is looser than the server rules; edit-order-validation on a pre-#52 server still shows a 0 total; #52's refusal channel is an owner decision; #3's job is red on the known test (step UNVERIFIED); no device run.
- **(6) PRs and commits.** [lavkooappseller#3](https://github.com/fullstackfull/lavkooappseller/pull/3) `7e9f674`; [lavkoo#52](https://github.com/fullstackfull/lavkoo/pull/52) `ead8e2a`; [lavkoo#51](https://github.com/fullstackfull/lavkoo/pull/51) `a4356ee`
- **(7) Tests.**
  - `pr-body` order_edit_submit_contract_test (8), order_edit_submit_flow_test (4, real controller with a stub network), product_review_average_test (5) (lavkooappseller#3 7e9f674: analyze-and-test red; PR body: flutter test 158 pass, 1 fail (version gate), 17 new, 15 mutations killed)
  - `ci` SellerApiOrderEditProductsTest and SellerApiOrderOwnershipTest (edit-order-submit refusals and ownership) (lavkoo#75 47b78ab: Tests SQLite 8.2/8.3 success (security set, not in #83))
- **(8) Dependencies and blockers.** Backend #52 and #51 are open drafts. The refusal channel (200 status error vs 403) is an owner decision. No version, pubspec or gate change. #3 targets main so it has CI, but its job is red for the known gate test.
- **(9) Acceptance criteria.**
  - [CODED] A refused edit (200 + status error) keeps the screen open, stops the spinner, does not reload the order and shows the server's message or a localized generic one (order_edit_submit_flow_test on app#3 7e9f674; job analyze-and-test red, step UNVERIFIED)
  - [CODED] A saved edit, or an old server's 200 without status error, reloads the order and closes the screen as before (order_edit_submit_flow_test 'a saved edit reloads the order and shows no error')
  - [CODED] An empty, HTML or non-object 200 body never throws (order_edit_submit_contract_test)
  - [CODED] The add-product sheet averages approved reviews only and survives a missing or empty reviews array (product_review_average_test)
  - [MISSING] Every place that averages product reviews (POS variation dialog, product_widget) counts approved reviews only (product_variation_selection_dialog_widget.dart:144 and product_widget.dart:40 unchanged on app#3)
- **(10) Next coding task.** app#3 (gcc/seller-order-edit-refusal): reuse ProductHelper.getApprovedReviewAverage in product_variation_selection_dialog_widget.dart and product_widget.dart with a test each, then align the edit icon's gate in order_top_section_widget.dart with the server rules. No version, pubspec or gate file.
- **Open owner decisions.** Refusal channel for #52 (audit row 10): keep HTTP 200 + status error (API-key integrators read it) or answer 403 with errors[0].message. The app reads both.

#### K-08. Seller owner and staff roles keep working; the app shows what a role may use

- **State: IN PROGRESS** | plan lines 372-391 | GCC-14; guardrail 4 | reviews: security, owner-decision
- **(2) Functional requirement.** A seller owner holds every permission. A staff member signs in with their own e-mail and gets exactly what their role grants; owner-only actions stay owner-only. The app knows who is signed in and hides what the role cannot use instead of answering 403 on each call.
- **(3) Exists now in code (main).** Backend on main: staff login (seller_staff, own token), SellerPermissionService.principalFor (owner, staff, key), 53 seller_can and 3 seller_owner declarations over 236 seller routes. The app has a Security centre for roles and staff but no permission gate: staff see every screen and meet 403 toasts; the login answer is a token only.
- **(4) Done in the draft PRs.** Backend #52 (ead8e2a): cm-firebase-token becomes owner-only, staff.manage grant limits, seller API staff-access tests (with #68 helpers). App: nothing for roles; app#3 to #6 add nothing here.
- **(5) What remains.** A who-am-I answer (owner or staff, role, permission keys) and an app permission scope that hides Security centre, finance, POS and settings entries the role lacks; a staff-aware matrix test over the seller routes; the owner decisions on staff push (audit row 8).
- **(6) PRs and commits.** [lavkoo#52](https://github.com/fullstackfull/lavkoo/pull/52) `ead8e2a`; [lavkoo#68](https://github.com/fullstackfull/lavkoo/pull/68) `9a1c850`; [lavkoo#108](https://github.com/fullstackfull/lavkoo/pull/108) `11a9707f`
- **(7) Tests.**
  - `ci` SellerApiStaffPushTokenTest, SellerApiSecurityDeskGrantTest, SellerApiProductAiHelpersStaffAccessTest, DigitalFilesStaffDownloadTest (staff by role) (lavkoo#75 47b78ab: Tests SQLite 8.2/8.3 and Fulfillment + security MySQL 8 and MariaDB 11.4.2 success; they cover some route groups, not a matrix of the 53 declarations)
  - `pr-body` security_contract_test (roles, staff and access holders parse) (main; baseline 141 pass (seller-version audit s.3), step log not in the data pack)
- **(8) Dependencies and blockers.** Backend endpoint for the principal is new; #52 is not merged; owner decision on hiding vs the 403 toast; no seller-app integration tree.
- **(9) Acceptance criteria.**
  - [CODED] A staff member signs in with their own credentials and receives a staff token; an owner's address is never shadowed by a staff row (LoginController::staffToken on main; no staff login test found)
  - [CODED] A route that asks for a permission refuses a staff member whose role lacks it (403) and lets the owner through (53 seller_can declarations on main; tests cover some groups only (#75 47b78ab))
  - [CODED] Owner-only actions (seller-update, account-delete, payment information, verification, push token) stay owner-only (seller_owner groups on main; #52 adds cm-firebase-token (SellerApiStaffPushTokenTest, #75))
  - [CODED] The app knows who is signed in: owner or staff, role and permissions (PR 108 (11a9707f): the route above tells the app who is signed in, owner or staff, the role and the permissions the server enforces (SellerWhoAmITest); the Flutter app does not read it yet, and hiding what a role cannot use (criterion 4) is app work; CI not read)
  - [MISSING] The app hides screens and buttons a role cannot use (more_screen.dart lists the Security centre for everyone; no permission check outside the security feature)
- **(10) Next coding task.** lavkoo, stacked on gcc/security-seller-product-ownership: add GET api/v3/seller/seller-center/security/me returning principal type, role name and permission keys (additive; the owner gets every key) with a test per principal type. App: a PermissionScope read once after login that hides entries the role lacks, behind a flag, with widget tests for the owner and two staff roles.
- **Open owner decisions.** Hide by role in the app or keep the 403 toast (the study doc's phase 4 was never done); staff push tokens (#52 row 8): accept owner-only or build per-staff device tokens.

#### K-09. Country and entity scopes for sellers and seller staff (GCC-14, seller side)

- **State: NOT STARTED** | plan lines 374; 378-391 | GCC-14; GCC-20 doc 'Two principals'; Discovery Q-17, Q-18 | reviews: security, owner-decision
- **(2) Functional requirement.** One seller or staff login can hold different permissions per market and legal entity. The server enforces the scope in authorization, API answers, queries, exports, reports, jobs, sensitive actions and audit logs. The market chosen in the app is never an authorization; only the platform owner sees all markets.
- **(3) Exists now in code (main).** None. SellerPrincipal carries seller, staff, permissions and key scopes, no market or entity; a token maps to one seller and one shop; sellers have no markets yet (14C doc); seller APIs are never narrowed by country (runbook). Country scopes exist only for admin-panel employees (lavkoo#74, #77, #82, drafts).
- **(4) Done in the draft PRs.** Nothing for sellers or seller staff. Admin-panel staff scopes (GCC-14c, default off) and the platform admin intersection (#84) belong to other units; app#4 and #6 add the separate platform principal (unit L).
- **(5) What remains.** Owner model first (Q-18, U6). Then: markets and entities on the seller principal and role (additive, default every current seller = SA), per-market permission sets, X-Storefront checked against the grant, scoped exports, reports, bulk jobs, automation rules and audit entries, a country and entity on seller registration (the form has none), a matrix test of owner and staff against six markets, and the app reading the allowed markets.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `none` none for seller or staff market scopes (-)
- **(8) Dependencies and blockers.** Owner decision Q-18/U6; GCC-14 legal-entity table does not exist yet (14C doc); security review; routes/rest_api/v3/seller.php, SellerTeamService and the vendor SellerStaffController are touched by the security set (#52, #75), while SellerPermissionService and SellerPrincipal are touched by no open branch.
- **(9) Acceptance criteria.**
  - [MISSING] A seller or staff token carries the markets and entities it may use; a request for a market outside the grant is refused by the server whatever the app sends (SellerPrincipal has no market or entity member (app/Services/Marketplace/SellerPrincipal.php))
  - [MISSING] Choosing a market in the app never grants access: X-Storefront outside the grant gets 403 and no data (X-Storefront does not reach api/v3 (GCC-14B doc))
  - [MISSING] A staff member can hold different permissions in different markets (seller_roles hold permission keys only; no market dimension)
  - [MISSING] Exports, reports, bulk jobs, automation rules and audit logs of a staff member contain only their markets (seller APIs are never narrowed (STOREFRONT_FLAG_RUNBOOK))
- **(10) Next coding task.** lavkoo docs/gcc: write GCC-14D-SELLER-MARKET-SCOPES.md (principal gains a markets list, default [SA]; staff roles gain per-market permission sets) with a six-market x owner/staff test matrix committed as skipped tests, so the first slice starts from acceptance tests. No runtime code until Q-18 is answered.
- **Open owner decisions.** Q-17 and Q-18: whether sellers trade in several markets with one login; who holds the top scope; entity scope as a second dimension (14C: entities have no table yet).
- **Verifier note (not applied automatically).** deps_blockers corrected by the verifier (applied above).

#### K-10. Per-market currency, decimals and no app-side conversion

- **State: IN PROGRESS** | plan lines 11; 22; 175-180; 284-287 | DECISIONS 'Local pricing, not auto-FX'; GCC-13 | reviews: finance, owner-decision
- **(2) Functional requirement.** Money on a market's screens is shown with that market's currency symbol, symbol position and decimals (SAR, AED, QAR 2; KWD, BHD, OMR 3), sent in the same units and never converted by the app. No figure of one market is added to another's.
- **(3) Exists now in code (main).** One platform-wide format: PriceConverter reads decimal_point_settings (default 1), the currency model and symbol position from the shared GET /api/v1/config; in multi_currency mode it multiplies by exchange rates; the discount helpers and the payout field are fixed at 2 decimals. 249 PriceConverter calls in 74 files.
- **(4) Done in the draft PRs.** app#1: MarketScope maps each market to one currency code (SAR, AED, KWD, QAR, BHD, OMR) with no conversion helper; nothing reads it yet. Backend config/gcc.php lists the six currencies; the config endpoint stays global.
- **(5) What remains.** Per-market currency, symbol, position and decimals from the server; PriceConverter takes them per amount or per market and drops the exchange-rate path for GCC builds; remove the fixed 2-decimal sites (payout field, bulk edit, discount helpers); 3-decimal tests for KWD, BHD, OMR. Production currency_model and decimal setting are UNKNOWN.
- **(6) PRs and commits.** [lavkooappseller#1](https://github.com/fullstackfull/lavkooappseller/pull/1) `964557e`
- **(7) Tests.**
  - `pr-body` market_scope_test (six currency codes, All Markets has none) (lavkooappseller#1 964557e; analyze-and-test red on the known test, step UNVERIFIED)
- **(8) Dependencies and blockers.** Backend storefront-currency slice (in progress, not in the data pack); live currency_model and decimal_point_settings UNKNOWN; finance review; old apps keep the Saudi config.
- **(9) Acceptance criteria.**
  - [CODED] Each market has exactly one currency code in the app: SA SAR, AE AED, KW KWD, QA QAR, BH BHD, OM OMR (test/market_scope_test.dart on app#1)
  - [MISSING] Money on a market's screens uses that market's symbol, position and decimals (3 for KWD, BHD, OMR) (price_converter.dart: decimalPointSettings ?? 1 from the shared config)
  - [MISSING] The app never multiplies an amount by an exchange rate in a market build (PriceConverter.convertPrice multiplies by myCurrency.exchangeRate in multi_currency mode)
  - [MISSING] Inputs that send money (payout, bulk price, POS discount) accept and send the market's decimals (request_payout_sheet_widget.dart allows two decimals)
  - [CODED] All Markets shows no figure summed across currencies (MarketScope.allMarkets has no currency and no aggregate helper exists)
- **(10) Next coding task.** lavkoo: return currency, symbol, symbol position and decimals for the requested storefront in a seller-scoped config answer (additive; the shared config stays Saudi). App: PriceConverter.forMarket(MarketScope, decimals) used by one screen (finance statement) with KWD, BHD and OMR three-decimal tests, and replace the 2-decimal regex of the payout field by the market's decimals.
- **Open owner decisions.** Where decimals live (server per storefront or an app table); whether multi_currency is dropped for GCC builds.

#### K-11. Seller push: tokens, topics, payload market and routing

- **State: IN PROGRESS** | plan lines 193; 405-418 | GCC-12; GCC-20 doc 'Alerts and notifications' | reviews: owner-decision
- **(2) Functional requirement.** Seller pushes reach only the right audience: tokens and topics are scoped by app role and market, a payload carries the market of its order and the app opens that market, routing does not depend on English title text, and the shop owner's token is never replaced by a staff phone. Deep links resolve.
- **(3) Exists now in code (main).** One token per seller (sellers.cm_firebase_token); two global topics, lavkoo_seller (no sender found in lavkoo) and maintenance_mode_start_vendor; NotificationBody has no market; background routing tests the English title (contains 'Order'); the lavkooseller:// scheme is declared with no handler.
- **(4) Done in the draft PRs.** Backend #52 (ead8e2a): cm-firebase-token is owner-only, so a staff phone no longer replaces the owner's token (SellerApiStaffPushTokenTest). App: nothing.
- **(5) What remains.** Decide the seller topic (delete or feed); topics scoped by market where used; storefront in the push data and the notification list; NotificationBody.storefront and routing by type only; a handler for lavkooseller://; per-staff device tokens (owner decision); tests for cold start, foreground and background opens in both languages.
- **(6) PRs and commits.** [lavkoo#52](https://github.com/fullstackfull/lavkoo/pull/52) `ead8e2a`
- **(7) Tests.**
  - `ci` SellerApiStaffPushTokenTest (staff of any role cannot replace the owner's push token) (lavkoo#75 47b78ab: Tests SQLite 8.2/8.3 and Fulfillment + security MySQL 8 / MariaDB 11.4.2 success; #52 ead8e2a)
- **(8) Dependencies and blockers.** Backend must send the market in push data (notification unit); Firebase iOS/Android project consistency UNKNOWN (Discovery E19-F09); owner decisions on the topic and on staff tokens.
- **(9) Acceptance criteria.**
  - [MET] A staff member's phone cannot replace the shop owner's push token (SellerApiStaffPushTokenTest; lavkoo#75 47b78ab, Tests SQLite PHP 8.2/8.3 and Fulfillment + security MySQL 8 / MariaDB 11.4.2)
  - [MISSING] Topics and tokens are scoped by app role and market; the unused lavkoo_seller topic is removed or has a sender (AppConstants.topic = 'lavkoo_seller'; no sender in lavkoo main)
  - [MISSING] A push payload carries the market of its order and opening it shows that market (NotificationBody holds order_id, order_details_id, refund_id, type and message_key only)
  - [MISSING] Opening a push routes by type or data, not by the English title text (my_notification.dart: message.notification!.title!.contains('Order'))
  - [MISSING] The lavkooseller:// deep link opens its target screen (scheme declared in AndroidManifest.xml and Info.plist, no Dart handler (Discovery E19-F11))
- **(10) Next coding task.** lavkoo (notification unit) first: add storefront to the seller order push data and to notification list items (additive). App: NotificationBody.storefront, route on type and data only (drop the title check) with tests for English and Arabic titles, and a lavkooseller:// handler for order and refund ids. Decide the topic with the owner.
- **Open owner decisions.** Q-44 (D-14): remove or feed the lavkoo_seller topic (E19 U7 asks whether it was ever sent to by hand); per-staff push tokens (#52 row 8); whether pushes for necessary order notices continue while a market is paused (GCC-12).
- **Verifier note (not applied automatically).** owner_decisions corrected by the verifier (applied above).

#### K-12. Session and token handling in the seller app

- **State: IN PROGRESS** | plan lines 345-348; 389; 482-485 | GCC-14; charter s.5 (seller session tokens need security review) | reviews: security
- **(2) Functional requirement.** Sign-out and a 401 drop the credential once and cleanly; a blank token never replaces a live session; the session token, and everything scoped to the previous account or market (stored market, POS cart), is not inherited by another login; unsent sales are never lost silently.
- **(3) Exists now in code (main).** 8 of the 9 http.MultipartRequest sites set Authorization by hand; the registration site sets no header. (rest of the field unchanged)
- **(4) Done in the draft PRs.** Nothing in the market chain changes sessions (#1, #2, #3 and #5 touch no auth file). app#4 and #6 keep a separate platform admin session in its own secure-storage key (unit L).
- **(5) What remains.** Token in the platform secure store with a migration; stored market and POS cart bound to the seller or cleared at sign-out and account switch while unsent sales stay safe; server-side session end on sign-out (restricted item SEC-E19-04, UNVERIFIED); one header path for the nine multipart sites; tests; security review.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `pr-body` dio_auth_header_test (no 'Bearer null', a blank token never overwrites a session, clearAuthToken drops the credential) (main; baseline CI had 141 pass and only the gate test red (seller-version audit s.3); step log not in the data pack)
- **(8) Dependencies and blockers.** Security review (charter s.5); restricted annex SEC-E19-04 not opened; owner decision on where unsent sales live after sign-out.
- **(9) Acceptance criteria.**
  - [CODED] Sign-out clears the header and the stored token; a blank or null token never overwrites a working session (test/dio_auth_header_test.dart on main)
  - [CODED] A burst of 401s signs the seller out once (ApiChecker.isUnAuthorize latch in api_checker.dart; no test found)
  - [MISSING] The session token is kept in the platform secure store, not in plain SharedPreferences (auth_repository.dart: sharedPreferences!.setString(AppConstants.token, token))
  - [MISSING] A second login on the same device inherits no stored market, POS cart or currency of the first; unsent sales stay queued for their seller (sign-out removes only the token and the password; cart_list, currency and offline_pos_orders are device-global)
  - [MISSING] Sign-out ends the session on the server (no logout route or call in the app or in lavkoo seller v3 routes; token lifecycle is a restricted item, UNVERIFIED)
- **(10) Next coding task.** app, branch off main: move the session token to flutter_secure_storage with the migration pattern of SecureCredentialStore.prime (read old key, write secure, remove old); key the stored market, cart_list and the POS outbox view by seller id; add tests next to dio_auth_header_test; open for security review, no merge.
- **Open owner decisions.** Where queued offline sales live after sign-out; whether a stored market is per account or per device.
- **Verifier note (not applied automatically).** exists_now corrected by the verifier (applied above).

#### K-13. Arabic strings awaiting a native reader

- **State: IN PROGRESS** | plan lines 408-409 | GCC-09; OWNER_PR_GUIDE (native Arabic read of #72, app#5 and app#6) | reviews: native-arabic
- **(2) Functional requirement.** Every string the seller sees in the market and POS flows exists in English and Arabic and has been read by a native Arabic speaker, including the two server messages the scan can show. The two language files keep identical keys.
- **(3) Exists now in code (main).** Main has 1838 keys in en.json and in ar.json and a key-parity checker (tool/check_translations.dart, run in CI). No Arabic string of the market work exists on main.
- **(4) Done in the draft PRs.** app#5: 11 keys (scan_shared_code_title, scan_shared_code_choose_country, scan_shared_code_open_list, scan_open_product_list, scan_market_unavailable, six scan_country_*); its body asks whether المسح should be المسح الضوئي. lavkoo#72: two Arabic server messages the app shows verbatim. Unit L: app#4 (94 keys) and app#6 (43 keys) also wait for a reader.
- **(5) What remains.** A native read of the 11 app keys and the 2 server texts (sa, ae and en files); apply the wording in assets/language/ar.json and resources/lang/sa and ae new-messages.php; RTL screenshots at 320x640 and 2x text for the reader; the owner marks the strings as reviewed.
- **(6) PRs and commits.** [lavkooappseller#5](https://github.com/fullstackfull/lavkooappseller/pull/5) `90b9d33`; [lavkoo#72](https://github.com/fullstackfull/lavkoo/pull/72) `1c779d2`
- **(7) Tests.**
  - `pr-body` pos_scan_translations_test (keys in both files, Arabic has Arabic letters only and differs from English, six country names, no placeholders) (lavkooappseller#5 90b9d33: no CI (stacked); PR-body local run)
  - `ci` PosScanCodeScopeTest: the messages of the two refusals exist in every language file (lavkoo#83 8ff3410 Catalog tests MySQL 8 and MariaDB 11.4.2, Tests SQLite; #72 1c779d2)
- **(8) Dependencies and blockers.** A native Arabic reader (owner); nothing else.
- **(9) Acceptance criteria.**
  - [CODED] Every key the scan flow shows exists in en and ar, the Arabic has Arabic letters only, and the key sets stay identical (test/pos_scan/pos_scan_translations_test.dart; tool/check_translations.dart; app#5 90b9d33)
  - [MISSING] A native Arabic reader has approved the 11 scan strings, including المسح versus المسح الضوئي (app#5 body asks for the read; no answer on record)
  - [MISSING] A native Arabic reader has approved the two server messages the scan can show (sa and ae files) (OWNER_PR_GUIDE: review the two Arabic strings of #72; no answer on record)
  - [CODED] The translation-key check passes on the head of every PR that adds keys (app#5 body: OK 1228 used, 1849 defined (local run); CI step result UNVERIFIED, #5 has no CI)
- **(10) Next coding task.** app: add tool/list_new_strings.dart that prints the keys present in ar.json but not on main, with the English text and the screen that uses each; run the candidate sheet in RTL at 320x640 and 2x text for screenshots; hand the sheet plus the two server texts to the reader, then apply the answer in one commit.
- **Open owner decisions.** Wording of scan: المسح or المسح الضوئي (the app#5 body points to the existing scan_item_or_add_from_item).

#### K-14. Version gate and force-update flow (record only)

- **State: IN PROGRESS** | plan lines 167; 193 | DECISIONS guardrail 4; charter s.4-5; GCC-19; GCC-20 doc item 8 | reviews: owner-decision
- **(2) Functional requirement.** The seller splash gate keeps comparing the installed version with the published minimum and blocks login only when older. No GCC change touches the version, the minimum or the gate. Installed builds that send no market header are treated as Saudi (GCC-19). The live minimum and the store versions are recorded before any release.
- **(3) Exists now in code (main).** Main: pubspec 1.0.0+3; AppVersion.isUpdateRequired and the splash gate (UpdateScreen) read seller_app_version_control from GET /api/v1/config; test/app_version_gate_test.dart pins a backend minimum of 2.0.1 and fails (1.0.0 is below it) on main and on every head. The live minimum and store versions are UNKNOWN.
- **(4) Done in the draft PRs.** Nothing changes the gate: app#1, #2, #3 and #5 touch no pubspec, lock, version helper, gate test or workflow (diff checked against main); #4 and #6 state the same. The audit of 2026-11-12 records the facts and the owner's options A (correct the constant with evidence), B (release at or above the minimum), C (lower the backend minimum).
- **(5) What remains.** The owner supplies the live seller_app_version_control values, the store versions and whether 1.0.0+3 shipped, then picks A, B or C; the pubspec or the test constant then changes in one reviewed change. Until then the red test stays red and untouched (a release blocker, not a coding blocker).
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `pr-body` version_helper_test (compare, isUpdateRequired, an empty or non-numeric minimum never blocks) and app_version_gate_test (red) (main: seller-version audit s.3 records run 43137204619 on 9ea6423 with 141 pass and only app_version_gate_test failing; ci-table: analyze-and-test red on #1, #3, #4)
- **(8) Dependencies and blockers.** Owner statement or screenshot of the live minimum and the store versions; a separate store-release decision; charter s.4: no version bump or lowered minimum from a test constant.
- **(9) Acceptance criteria.**
  - [CODED] An installed version older than the published minimum shows UpdateScreen; an absent or non-numeric minimum never blocks login (test/version_helper_test.dart; splash_screen.dart AppVersion.isUpdateRequired)
  - [MISSING] The shipped version clears the live published minimum, so login is reachable (app_version_gate_test red: 1.0.0+3 against a pinned 2.0.1; live value UNKNOWN)
  - [MISSING] The live seller_app_version_control values and the store versions are recorded with a date (audit 2026-11-12 s.2 lists them as UNKNOWN)
  - [CODED] No market, POS or order-edit change touches the version, the minimum or the gate (git diff of #1, #2, #3 and #5 against main shows no pubspec, lock, version_helper, gate test or ci.yml change)
  - [MISSING] An installed build that sends no X-Storefront is treated as the Saudi default (StorefrontResolver::resolve falls to the default country (lavkoo#83 tree); app flag off sends no header)
- **(10) Next coding task.** Record-only: when the owner provides the live seller_app_version_control values and the store versions, add them to docs/gcc/audits/2026-11-12-seller-version-verification.md as a dated addendum. No change to pubspec.yaml, test/app_version_gate_test.dart, the backend setting or the gate. The other tests are made visible separately by the CI work in K-15.
- **Open owner decisions.** Options A, B or C of the 2026-11-12 audit: correct the test constant with evidence, release a build at or above the minimum, or lower the production minimum.

#### K-15. Tests and CI of the seller app

- **State: IN PROGRESS** | plan lines 164; 529; 700-716 | charter s.5-6; DECISIONS guardrail 4 | reviews: none
- **(2) Functional requirement.** Every seller-app pull request, stacked or not, gets CI that analyzes, checks translation keys, runs the whole suite and builds a debug APK, and each GCC feature's tests also run as a named step so one unrelated red test cannot hide them. The known red version-gate test is never edited or skipped.
- **(3) Exists now in code (main).** Main: .github/workflows/ci.yml (Flutter 3.47.0 pinned) runs flutter analyze --no-fatal-infos, tool/check_translations.dart and flutter test on pull requests to main and pushes to claude/**; build-android needs analyze-and-test. 19 test files; baseline 141 pass, 1 fail (version gate).
- **(4) Done in the draft PRs.** app#4 adds one named step (platform admin tests with the flag on) before the full run. app#1, #3, #4: analyze-and-test red, build-android skipped; #2, #5, #6: no run (stacked, base is not main). PR bodies report clean-export runs: #3 158 pass 1 fail; #5 267/1; #4 611/1; #6 865/1; analyze 39 infos, equal to main.
- **(5) What remains.** CI for stacked PRs (base gcc/**); named steps for the market, POS scan and order-edit tests, also with GCC_STOREFRONTS_ENABLED=true; an Android build result; per-step logs in the data pack; a seller-app integration branch holding #1, #2, #3, #5 (and the admin chain, whose language files overlap) with CI; device runs.
- **(6) PRs and commits.** [lavkooappseller#1](https://github.com/fullstackfull/lavkooappseller/pull/1) `964557e`; [lavkooappseller#2](https://github.com/fullstackfull/lavkooappseller/pull/2) `e5fd716`; [lavkooappseller#3](https://github.com/fullstackfull/lavkooappseller/pull/3) `7e9f674`; [lavkooappseller#4](https://github.com/fullstackfull/lavkooappseller/pull/4) `716f84d`; [lavkooappseller#5](https://github.com/fullstackfull/lavkooappseller/pull/5) `90b9d33`; [lavkooappseller#6](https://github.com/fullstackfull/lavkooappseller/pull/6) `e4d7d02`
- **(7) Tests.**
  - `pr-body` tool/check_translations.dart (key parity of en and ar and keys used in code), run as a CI step (main workflow; per-step result not in the data pack)
- **(8) Dependencies and blockers.** The red app_version_gate_test must not be touched (K-14). CI minutes (DR-5). Stacked PRs need a workflow change or retargeting; app#4's workflow edit overlaps.
- **(9) Acceptance criteria.**
  - [CODED] On every pull request to main CI runs analyze, the translation check and the whole flutter test (ci.yml on main; runs exist for #1 964557e, #3 7e9f674, #4 716f84d (analyze-and-test))
  - [MISSING] A stacked pull request gets the same CI (ci.yml pull_request branches: [main]; no run for #2 e5fd716, #5 90b9d33, #6 e4d7d02)
  - [MISSING] The whole flutter test is green on every head (app_version_gate_test fails on main and every head; analyze-and-test = failure on #1, #3, #4)
  - [MISSING] A debug APK builds after the tests (build-android skipped on #1, #3, #4 because it needs analyze-and-test)
  - [MISSING] Each GCC feature's tests run as a named CI step before the full run (only app#4 adds a step (platform admin, flag on); none for market, POS scan or order edit)
  - [CODED] flutter analyze stays at the main baseline (39 infos, no warnings) (PR bodies #3, #4, #5, #6: 39 infos identical to main on clean exports)
- **(10) Next coding task.** app, new branch off main: edit only .github/workflows/ci.yml: add 'gcc/**' to pull_request.branches, add named steps before the full test for test/dio_storefront_header_test.dart, market_scope_test, seller_market_choice_test, the order_edit tests and test/pos_scan (also with --dart-define=GCC_STOREFRONTS_ENABLED=true where it matters). Keep the full step and the gate test untouched and red.

#### K-16. Seller workflows keep working (orders, products, stock, POS, withdrawals, reviews)

- **State: IN PROGRESS** | plan lines 537-562; 713-716 | DECISIONS guardrail 4; charter s.5 | reviews: none
- **(2) Functional requirement.** With every GCC flag off, the seller app sends the same requests and handles the same answers as main for login, orders, products, stock, POS, withdrawals and reviews, and keeps working against the open backend security PRs for an owner account.
- **(3) Exists now in code (main).** about 190 API path constants and 19 test files in total (14 contract tests, plus the auth header, pending POS order, version helper, version gate and export helper tests); no flow test of product add or edit, order list or detail, stock update, withdrawals or review reply. tests[1].what: '14 contract tests and 5 other tests (incl. dio_auth_header_test) on main'.
- **(4) Done in the draft PRs.** The audit of 2026-11-17 reads the app against #44, #51, #52, #58, #61 and #63 line by line: nothing breaks for an owner account except one conditional (#58 Firebase reset, fixed in #58's head). app#1, #2, #3 and #5 are additive: flag-off parity is tested for the header (#1) and the scan request (#5).
- **(5) What remains.** Flow tests for the six workflows with a stub adapter; the audit's manual tests T1-T13 on staging (UNKNOWN until run); the app tested on one tree holding the security set and the GCC chain (none exists: a merge of #75 and #83 conflicts in five backend files); a device smoke run.
- **(6) PRs and commits.** [lavkooappseller#1](https://github.com/fullstackfull/lavkooappseller/pull/1) `964557e`; [lavkooappseller#5](https://github.com/fullstackfull/lavkooappseller/pull/5) `90b9d33`; [lavkoo#52](https://github.com/fullstackfull/lavkoo/pull/52) `ead8e2a`; [lavkoo#44](https://github.com/fullstackfull/lavkoo/pull/44) `61798e8`; [lavkoo#58](https://github.com/fullstackfull/lavkoo/pull/58) `40020ae`; [lavkoo#63](https://github.com/fullstackfull/lavkoo/pull/63) `de0a7d5`
- **(7) Tests.**
  - `ci` Seller API ownership and flow tests (orders, POS, payouts, products, shipping, promotions) with owner and staff tokens, incl. 'the seller can still sell their own product' (lavkoo#75 47b78ab: Tests SQLite 8.2/8.3 and Fulfillment + security MySQL 8 / MariaDB 11.4.2 success (security set only))
  - `pr-body` 19 model-contract test files and dio_auth_header_test on main (main baseline 141 pass (seller-version audit s.3); step log not in the data pack)
- **(8) Dependencies and blockers.** A combined security + GCC tree exists (gcc/release/security-gcc-integration-ci, f6fb03af, no pull request) but its CI is not read; manual tests T1-T13 pending; the seller version gate blocks release.
- **(9) Acceptance criteria.**
  - [CODED] With the flags off the headers and the POS scan request equal main's (dio_storefront_header_test (#1) and cart_repository_scan_test (#5); no CI run proves them)
  - [MET] A seller's own orders, POS sales, payouts, products, shipping methods and promotions are still served after the security fixes (SellerApiPosOwnershipTest, SellerApiPayoutOwnershipTest, SellerApiOrderOwnershipTest, SellerApiCatalogueOwnershipTest; lavkoo#75 47b78ab CI)
  - [CODED] The app handles every answer the security PRs change (403 owner_only, 404 bodies, refusals) without a crash or a sign-out (audit 2026-11-17 rows 1-41 (static review); manual tests T1-T13 not run)
  - [MET] Firebase password reset works for a number that keeps the trunk zero after a non-Gulf country code (Syria) (SellerPasswordResetTest 'Syria with the trunk zero kept after the country code'; lavkoo#75 47b78ab; production Firebase mode UNKNOWN (T1))
  - [MISSING] Orders, products, stock, withdrawals and reviews have app-side flow tests of the request and the handling of the answer (the 19 test files on main pin models and the auth header only)
  - [MISSING] The seller flows pass on one tree that holds the security set and the GCC chain (no such tree: #75 and #83 conflict in five files)
- **(10) Next coding task.** app, new branch off main: add flow tests (scripted Dio adapter as in test/pos_scan/support) for orders list and detail, product add and edit, stock update, withdrawal request and review reply, asserting path, body and the handling of 200, 401, 403 and 404; run the audit's T1-T13 on a staging backend that has the security set.
- **Verifier note (not applied automatically).** exists_now corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** deps_blockers corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** ac[5].e (The seller flows pass on one tree that holds the security set and the GCC chain): Evidence 'no such tree: #75 and #83 conflict in five files' is stale; the criterion stays MISSING for a different reason. Proposed: A tree holding both sets exists (gcc/release/security-gcc-integration-ci, f6fb03af, five conflicts resolved, no pull request) but no CI result for it is in the pack and no seller-flow test is known to run on it; MISSING until that run is read.
- **Verifier note (not applied automatically).** ac[1].e (A seller's own orders, POS sales, payouts, products, shipping methods and promotions are still served): The four named classes do not cover shipping methods or promotions: SellerApiCatalogueOwnershipTest has restock requests, digital-product files and category shipping COST, and no promotion test. The 'still served' tests for those areas are in other classes of the PR 75 tree, so the MET stands but the evidence list is incomplete. Proposed: SellerApiPosOwnershipTest, SellerApiPayoutOwnershipTest, SellerApiOrderOwnershipTest, SellerApiCatalogueOwnershipTest, SellerApiShippingMethodOwnershipTest (methods), SellerApiPromotionOwnershipTest (clearance and coupons), SellerProductOwnershipTest (products); all in tests/Feature/Security of the PR 75 tree; lavkoo#75 47b78ab CI (Tests SQLite 8.2/8.3, Fulfillment + security MySQL 8 and MariaDB 11.4.2).

#### K-17. Backend contracts and decisions the seller app depends on

- **State: IN PROGRESS** | plan lines 5-13; 159-166; 482-485 | DECISIONS guardrail 4; GCC-14 (seller side); Discovery Q-18 | reviews: owner-decision, security
- **(2) Functional requirement.** The seller app gets from the backend what GCC needs, and the owner has decided it: seller API v3 resolves the storefront, a who-am-I answer, a per-market config, one refusal channel, per-staff push tokens, and authenticated digital-file routes. Old app builds keep working as Saudi.
- **(3) Exists now in code (main).** Main: X-Storefront is read nowhere in lavkoo; seller v3 routes carry no storefront middleware; GET /api/v1/config is global (currency, decimals, country code); login answers a token only; one push token per seller; the owner-decision items of the 2026-11-17 compat audit are open.
- **(4) Done in the draft PRs.** lavkoo#8, #19 and #28: storefront context, middleware alias and X-Storefront header for customer routes. #72 and #76: POS scan contract (country query, 409, 422). #35: POS idempotency. #52: refusals as 200 status error, owner-only push token. #69: authenticated digital-file routes (switch off). None resolves a seller's storefront: seller v3 still ignores X-Storefront.
- **(5) What remains.** Owner answers: Q-18/U6 (seller markets), refusal channel, staff push tokens, admin-assigned coupons, digital file types, the lavkoo_seller topic (U7). Then build: storefront on v3, who-am-I, per-market seller config (additive), market in the push payload, and the app following the #69 digital-file path before DIGITAL_FILES_PRIVATE is turned on.
- **(6) PRs and commits.** [lavkoo#72](https://github.com/fullstackfull/lavkoo/pull/72) `1c779d2`; [lavkoo#76](https://github.com/fullstackfull/lavkoo/pull/76) `0ad49d2`; [lavkoo#35](https://github.com/fullstackfull/lavkoo/pull/35) `7c75b7f`; [lavkoo#52](https://github.com/fullstackfull/lavkoo/pull/52) `ead8e2a`; [lavkoo#69](https://github.com/fullstackfull/lavkoo/pull/69) `31d9186`; [lavkoo#28](https://github.com/fullstackfull/lavkoo/pull/28) `8c8d32a`; [lavkoo#108](https://github.com/fullstackfull/lavkoo/pull/108) `11a9707f`
- **(7) Tests.**
  - `ci` PosScanCodeScopeTest, SixCountryPosScanTest, SellerSurfacesCountryTest (contract pins) (lavkoo#83 8ff3410 Catalog tests MySQL 8, MariaDB 11.4.2 and Tests SQLite success)
  - `ci` DigitalFilesStaffDownloadTest (seller app v2 and v3 authenticated routes) (lavkoo#75 47b78ab Tests SQLite success (#69 31d9186 is in the PR 75 tree))
- **(8) Dependencies and blockers.** The owner decisions listed; #35 is in neither the PR 75 nor the PR 83 tree (only in gcc/release/all-integration-ci), #69 is in the PR 75 tree; the combined tree gcc/release/security-gcc-integration-ci (f6fb03af, five conflicts resolved, no pull request) has no CI record in the pack.
- **(9) Acceptance criteria.**
  - [MISSING] Seller API v3 resolves the storefront (header or the principal's scope) behind a default-off flag, and an absent header means SA (GCC-14B doc: X-Storefront does not reach api/v3; the storefront middleware sits on customer v1 API and web routes, not on seller v3)
  - [CODED] The app can ask who it is: principal type, role, permissions and allowed markets (PR 108 (draft, stacked on PR 87, 11a9707f, off by default): GET /api/v3/seller/seller-center/security/me answers the caller and nobody else (principal_type owner or staff, seller_id, staff_id, role, the permission keys held now, market_scope unrestricted, allowed_markets null until K-09, storefronts_enabled), from the principal the token resolved to, with no credential or contact detail (SellerWhoAmITest, 17 tests: owner and staff, no role, a switched-off role, a role changed between two calls, the middleware chain, 401, a switched-off staff member, an issued key refused, GET only); the 55 tests of its two classes pass on SQLite and on MySQL 8 (strict included); the seller app does not call it yet; CI not read)
  - [MISSING] Seller config (currency, decimals, country code) is answered per market while old apps still receive the Saudi default (ConfigController: decimal_point_settings is a global business setting)
  - [CODED] A refusal on order edit reaches the seller whichever channel (200 status error or 403) the owner picks (app#3 reads 200 status error; ApiChecker shows 403 messages)
  - [MISSING] The app follows the authenticated digital-file path before the private-disk switch is turned on (#69) (#69 routes digital-file/{id}/{fileName?}; no app code reads them)
  - [MISSING] Staff devices have their own push tokens, or the owner accepts owner-only registration (#52 makes cm-firebase-token owner-only; one token column per seller)
- **(10) Next coding task.** lavkoo docs/gcc: write GCC-21-SELLER-APP-CONTRACT.md listing each backend answer the app needs (storefront on v3, principal, per-market config, market in push data, digital-file path), its default-off flag and one contract test per item, so the backend and app slices share acceptance tests; send the six owner questions with it.
- **Open owner decisions.** Q-18 and U6; refusal channel (#52 row 10); staff push tokens (row 8); admin-assigned coupons (row 15); digital file types (row 35); the lavkoo_seller topic (Q-44 / D-14).
- **Verifier note (not applied automatically).** owner_decisions corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** links: PR 69 is listed at 31d9186. Its branch head moved on 2026-11-18 07:08Z to 4096f1be (a serialization fix in DigitalFileAddressService and one test). The PR 75 tree still holds 31d9186, so the DigitalFilesStaffDownloadTest CI claim stands, but the head and its CI are not in the pack. Proposed: lavkoo PR 69 head 4096f1be (was 31d9186 at the time of the CI table); the PR 75 tree holds 31d9186; CI of 4096f1be not read.
- **Verifier note (not applied automatically).** deps_blockers corrected by the verifier (applied above).
- **Update after the assessment.** PR 108 (draft, stacked on PR 87, 11a9707f, off by default): the seller app can ask who it is (GET /api/v3/seller/seller-center/security/me: owner or staff, shop, role, permission keys, market scope unrestricted and allowed_markets null until K-09). Not done: K-17.0 (X-Storefront or the principal's scope resolved on api/v3/seller), K-17.2 (seller config per market), the digital-file path, staff push tokens.
- **Next coding task today (supersedes item 10 above).** Read the CI of PR 108 per job (MariaDB included); then K-17.0 (resolve X-Storefront or the principal's scope on api/v3/seller behind a default-off flag) with K-09 (markets on the seller principal); the Flutter app reads security/me (K-08); the owner's five decisions in docs/gcc/K-SELLER-MARKET-CONTRACT.md.

#### K-18. Seller-app theme and API impact of per-country themes (GCC-09 audit)

- **State: IN PROGRESS** | plan lines 313-333; 335-343 | GCC-07; GCC-09; GCC-10; Discovery R-117 | reviews: owner-decision
- **(2) Functional requirement.** A per-country web or customer-app theme never changes the seller app: it keeps its own bundled light and dark theme and calls no theme endpoint. The one value it reads from the global theme (active_theme, for shop image rules) stays correct once themes differ per country.
- **(3) Exists now in code (main).** The seller app has a bundled theme (ThemeController: a light or dark bool in SharedPreferences) and no theme endpoint in AppConstants (Discovery E19 s.4). It reads config active_theme to choose shop banner image rules (shop_update_screen.dart theme_aster and theme_fashion; registration and info field).
- **(4) Done in the draft PRs.** Nothing in the seller app. The audit exists: Discovery E19, E20 and R-117 (owner decision on the seller's theme scope). Per-country themes in the backend belong to another unit.
- **(5) What remains.** Owner decision on the seller's theme scope (R-117); replace the active_theme dependency of the shop image rules by explicit image rules or an additive server-provided rule; a source-rule test that no lib file calls a theme endpoint; company name and logo on the maintenance screen stay global until GCC-10 is applied to the app.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `none` none: no test asserts that the app calls no theme endpoint or that image rules ignore active_theme (-)
- **(8) Dependencies and blockers.** Owner decision R-117; backend per-country themes (GCC-07) must keep config active_theme meaningful for old apps.
- **(9) Acceptance criteria.**
  - [CODED] The seller app consumes no theme endpoint and keeps its own bundled light and dark theme (ThemeController stores a bool; AppConstants has no theme URI (Discovery E19 s.4); no test pins it)
  - [MISSING] Shop banner and logo rules in registration and shop update do not depend on the single global active_theme (shop_update_screen.dart: configModel?.activeTheme == "theme_aster")
  - [MISSING] A source test fails if any lib file calls a theme endpoint (no such test in any seller tree)
- **(10) Next coding task.** app, branch off main: add a source-rule test (pattern of test/pos_scan/pos_scan_source_rules_test.dart) that fails when any lib file calls a theme endpoint, and move the shop image size rule from active_theme to a constant table keyed by image slot; no UI change; record the owner's R-117 answer in the PR.
- **Open owner decisions.** Q-43 (D-12; risk R-117): the seller app's theme scope (own static theme versus consuming the theme contract, or only image rules) and whether the app shows the market's brand (GCC-10).
- **Verifier note (not applied automatically).** owner_decisions corrected by the verifier (applied above).

#### K-V1. Seller app compatibility with the open backend PRs that change seller v3 answers but were not read i...

- **State: NOT STARTED** | plan lines 537-562; 700-716 (DECISIONS guardrail 4: audit API v3/seller) | added by the independent verifier: the assessor of this unit omitted it | reviews: none
- **(2) Functional requirement.** Seller app compatibility with the open backend PRs that change seller v3 answers but were not read in the 2026-11-17 audit: #20 (order and refund lookups scoped to the seller), #32 (seller withdraw updates and closes scoped to the owning seller), #29 (referral reward in the seller order status path) and #13 (POS wallet atomicity), plus the staged hotfix line (PR 78, 81, 86: 21 seller v3 files each)
- **(3) Exists now in code (main).** The audit lists only #44, #51, #52, #58, #61 and #63 in its section 0. git diff against each merge-base: #20 changes v3 OrderController, POSController and RefundController; #32 changes v3 SellerController (65 lines) and the withdraw handling; #29 changes v3 OrderController; #13 changes v3 POSController. #20, #29, #32 and #13 are in neither the PR 75 nor the PR 83 tree, and none of them appears anywhere in assess-K.json, although withdrawals and orders are named seller workflows.
- **(4) Done in the draft PRs.** See the evidence note above.
- **(5) What remains.** Its acceptance criteria are not written yet; until they are, it counts as one criterion.
- **(6) PRs and commits.** none
- **(7) Tests.** none
- **(8) Dependencies and blockers.** See the evidence note.
- **(9) Acceptance criteria.**
  - [MISSING] Seller app compatibility with the open backend PRs that change seller v3 answers but were not read in the 2026-11-17 audit: #20 (order and refund lookups scoped (one placeholder criterion for a requirement found by the verifier; write the real ones before relying on this line)
- **(10) Next coding task.** Write its acceptance criteria and its next coding task (added by the verifier).

#### K-V2. Sensitive actions of a seller or seller staff (payout and bank details, staff management, account de...

- **State: NOT STARTED** | plan lines 386; 389-391 (GCC-14: sensitive actions; all markets only for the general manager) | added by the independent verifier: the assessor of this unit omitted it | reviews: none
- **(2) Functional requirement.** Sensitive actions of a seller or seller staff (payout and bank details, staff management, account deletion, verification) are scoped by market and entity, and only the platform owner sees every market
- **(3) Exists now in code (main).** K-09's functional text lists sensitive actions, but its four criteria cover authorization, X-Storefront, per-market permissions and exports, reports, jobs and audit logs only. Owner-only routes exist on main (seller_owner groups for seller-update, account-delete, payout methods, verification) with no market dimension.
- **(4) Done in the draft PRs.** See the evidence note above.
- **(5) What remains.** Its acceptance criteria are not written yet; until they are, it counts as one criterion.
- **(6) PRs and commits.** none
- **(7) Tests.** none
- **(8) Dependencies and blockers.** See the evidence note.
- **(9) Acceptance criteria.**
  - [MISSING] Sensitive actions of a seller or seller staff (payout and bank details, staff management, account deletion, verification) are scoped by market and entity, and o (one placeholder criterion for a requirement found by the verifier; write the real ones before relying on this line)
- **(10) Next coding task.** Write its acceptance criteria and its next coding task (added by the verifier).

#### K-V3. A queued POS sale that has failed ten replays is never dropped silently: it stays visible with its l...

- **State: NOT STARTED** | plan lines 537-562; 713-716 (preserve seller behaviour); K-12 functional text 'unsent sales are never lost silently' | added by the independent verifier: the assessor of this unit omitted it | reviews: none
- **(2) Functional requirement.** A queued POS sale that has failed ten replays is never dropped silently: it stays visible with its last error until the seller removes it
- **(3) Exists now in code (main).** lib/features/pos/controllers/offline_sales_controller.dart lines 139 and 142 drop the entry (if (retried.attempts < _maxAttempts) remaining.add(retried)) and persist the shorter list; git grep finds no screen that reads attempts or lastError. K-06 criterion 3 and K-12 criterion 4 only cover replay under the right seller and sign-out.
- **(4) Done in the draft PRs.** See the evidence note above.
- **(5) What remains.** Its acceptance criteria are not written yet; until they are, it counts as one criterion.
- **(6) PRs and commits.** none
- **(7) Tests.** none
- **(8) Dependencies and blockers.** See the evidence note.
- **(9) Acceptance criteria.**
  - [MISSING] A queued POS sale that has failed ten replays is never dropped silently: it stays visible with its last error until the seller removes it (one placeholder criterion for a requirement found by the verifier; write the real ones before relying on this line)
- **(10) Next coding task.** Write its acceptance criteria and its next coding task (added by the verifier).

#### K-V4. Seller app behaviour when a market is paused: maintenance or pause of one market keeps order handlin...

- **State: NOT STARTED** | plan lines 445-459 (GCC-12: browse only, maintenance, redirect; old orders, refunds, payouts keep working) | added by the independent verifier: the assessor of this unit omitted it | reviews: none
- **(2) Functional requirement.** Seller app behaviour when a market is paused: maintenance or pause of one market keeps order handling, refunds and payouts of that market working, instead of one global vendor_app switch that replaces the whole app
- **(3) Exists now in code (main).** cross_axis_notes says K-04 and K-11 'need a paused-market state in the app', but no requirement or criterion carries it. Today splash_screen.dart sends every seller to MaintenanceScreen when maintenanceStatus == 1 and selectedMaintenanceSystem.vendorApp == 1.
- **(4) Done in the draft PRs.** See the evidence note above.
- **(5) What remains.** Its acceptance criteria are not written yet; until they are, it counts as one criterion.
- **(6) PRs and commits.** none
- **(7) Tests.** none
- **(8) Dependencies and blockers.** See the evidence note.
- **(9) Acceptance criteria.**
  - [MISSING] Seller app behaviour when a market is paused: maintenance or pause of one market keeps order handling, refunds and payouts of that market working, instead of on (one placeholder criterion for a requirement found by the verifier; write the real ones before relying on this line)
- **(10) Next coding task.** Write its acceptance criteria and its next coding task (added by the verifier).

#### K-V5. The seller version gate can be switched off per platform without a release (the published status hon...

- **State: NOT STARTED** | plan lines 167; 193; 537-562 (guardrail 4: audit the seller app version gate); GCC-20 doc item 8 | added by the independent verifier: the assessor of this unit omitted it | reviews: none
- **(2) Functional requirement.** The seller version gate can be switched off per platform without a release (the published status honoured), so a wrong minimum cannot lock every seller out (Discovery E19-F06, P1)
- **(3) Exists now in code (main).** lib/features/splash/domain/models/config_model.dart parses PlatformVersion.status, but splash_screen.dart reads only .version (lines 79 and 81) before AppVersion.isUpdateRequired; K-14 records the unknown minimum and the red test but not that the gate has no off switch. Lower priority than the other four; no code or test change is proposed while the charter forbids touching the gate.
- **(4) Done in the draft PRs.** See the evidence note above.
- **(5) What remains.** Its acceptance criteria are not written yet; until they are, it counts as one criterion.
- **(6) PRs and commits.** none
- **(7) Tests.** none
- **(8) Dependencies and blockers.** See the evidence note.
- **(9) Acceptance criteria.**
  - [MISSING] The seller version gate can be switched off per platform without a release (the published status honoured), so a wrong minimum cannot lock every seller out (Dis (one placeholder criterion for a requirement found by the verifier; write the real ones before relying on this line)
- **(10) Next coding task.** Write its acceptance criteria and its next coding task (added by the verifier).
