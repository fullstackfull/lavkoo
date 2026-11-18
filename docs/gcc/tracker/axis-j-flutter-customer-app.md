# Axis J: Flutter customer app

Detail cards of the master tracker ([`MASTER_IMPLEMENTATION_TRACKER.md`](../MASTER_IMPLEMENTATION_TRACKER.md)), as of 2026-11-18. Requirements: 20. Acceptance criteria: 93 (MET 32, CODED 26, MISSING 35). Code completion 62%, proven by a passing test 34%, release readiness 5%.

Independent verification: done for unit(s) J.

Every card gives the ten fields of the directive: (1) id and name, (2) functional requirement, (3) what exists in main, (4) what the draft pull requests did, (5) what remains, (6) pull requests and commits, (7) tests that exist and where they ran, (8) dependencies and blockers, (9) acceptance criteria marked MET (a named passing test or CI job on a named head), CODED (code exists, no passing test cited) or MISSING, (10) the next coding task.

#### J-01. Default storefront and manual choice precedence

- **State: IN PROGRESS** | plan lines 187-191 | GCC-19 (default storefront); GCC-12 (redirect to default) | reviews: none
- **(2) Functional requirement.** The app opens on the default storefront (Saudi Arabia, changeable by the admin later), keeps the customer's manual choice across restarts, never changes it from IP, device locale or GPS, and falls back to the default when the server stops listing the chosen storefront.
- **(3) Exists now in code (main).** Nothing on main: no lib/features/storefront, no X-Storefront; a Saudi-only app. Prefs countryCode is the language country (AppConstants.languages), not a market. GPS (geolocator) is used only in the address map (location_controller.dart, add_new_address_screen.dart).
- **(4) Done in the draft PRs.** lavkooapp #1: six storefronts SA AE KW QA BH OM, default SA (StorefrontCountry.defaultCountry), StorefrontConfig.resolve, compile-time flag GCC_STOREFRONTS_ENABLED (default off). #2: StorefrontChoice saves the choice in prefs gcc_storefront_country, writes nothing while off. #5: keeps the server list. #7: the choice drives header and caches. Nothing infers a storefront from IP or locale.
- **(5) What remains.** (1) Parse default_country, is_default and enabled from GET /storefronts: the app hard-codes SA and ignores them. (2) Reconcile a stored choice the server no longer lists: the server then serves the default while the app keeps the old code in header, label and cache keys. (3) The flag is compile-time: turning GCC on needs a new app release. (4) Test that no inference exists.
- **(6) PRs and commits.** [lavkooapp#1](https://github.com/fullstackfull/lavkooapp/pull/1) `2a8c84c`; [lavkooapp#2](https://github.com/fullstackfull/lavkooapp/pull/2) `6b74524`; [lavkooapp#3](https://github.com/fullstackfull/lavkooapp/pull/3) `eda7814`; [lavkooapp#5](https://github.com/fullstackfull/lavkooapp/pull/5) `5526c4b`; [lavkooapp#7](https://github.com/fullstackfull/lavkooapp/pull/7) `56deea7`
- **(7) Tests.**
  - `ci` storefront_config_test (default SA, stored code resolution, language country never a storefront), storefront_choice_test (saved, survives a new instance, ignored while off), stored_storefronts_test (lavkooapp #7 56deea7, job storefront-header, flag-off run)
- **(8) Dependencies and blockers.** Backend list: lavkoo #28 (GET /api/v1/storefronts with default_country and is_default), #39 adds sellable; green in PR 83 8ff3410 (StorefrontListApiTest). The default changes only through env GCC_DEFAULT_COUNTRY, no admin screen yet. No tree combines app and backend.
- **(9) Acceptance criteria.**
  - [MET] With nothing stored the app resolves to Saudi Arabia (storefront_config_test 'nothing stored resolves to Saudi Arabia'; lavkooapp #7 56deea7, job storefront-header)
  - [MET] A manual choice is saved, survives a new instance (restart) and is never overwritten by the language country (storefront_choice_test; storefront_config_test 'the language country is never read as a storefront'; #7 56deea7)
  - [MET] With the flag off the stored choice is ignored, nothing is written and Saudi Arabia is current (storefront_choice_test group 'while storefronts are off'; #7 56deea7)
  - [CODED] Nothing derives the storefront from IP, device locale or GPS (By absence: geolocator is imported only by location_controller.dart and add_new_address_screen.dart; no test asserts it)
  - [MISSING] When the server changes default_country the app's fallback follows it (StorefrontCountry.tryParse reads country, path, currency and sellable only; the fallback is the const saudiArabia)
  - [MISSING] A stored storefront the server no longer lists falls back to the default and rebuilds header and caches (No reconcile after StorefrontController.refresh; the server resolves an unlisted country to the default (StorefrontResolver))
- **(10) Next coding task.** On the #7 branch keep default_country and enabled in StorefrontCountry.parseList and StoredStorefronts; add StorefrontChoice.reconcile() after each refresh that moves an unlisted stored code to the server default through StorefrontSwitcher; tests in storefront_choice_test and storefront_switcher_test (flag-on run).

#### J-02. Country chooser and storefront switch

- **State: IN PROGRESS** | plan lines 189, 515-517 | GCC-13; GCC-19 | reviews: native-arabic
- **(2) Functional requirement.** The customer picks a country store by hand in the app. A switch saves the choice, rebuilds the request headers, clears the left storefront's caches, makes every holder of storefront data drop it before any reload, then lands on home.
- **(3) Exists now in code (main).** Nothing: main has no chooser and no switch handler. Only a language switch exists (LocalizationController.setLanguage clears all caches).
- **(4) Done in the draft PRs.** lavkooapp #7: Settings row beside language and currency (only with the flag on and more than one listed storefront), StorefrontChooserSheet (name, orders available or browse-only, ar/en, RTL), StorefrontSwitcher (save, ask server, rebuild headers, notify), StorefrontChangeNotifier with 18 controllers, StorefrontScope against late answers. #5: StorefrontController.choose. #2: StorefrontChoice.choose.
- **(5) What remains.** (1) choose() awaits a network refresh with 60 s timeouts before the sheet closes: save first, refresh in the background. (2) GoRouter navigation to home, the splash config reload and a real device are untested. (3) Settings is the only entry (no first-run or home entry). (4) bn, es, hi strings and the Arabic of the shared 'select' button are unreviewed.
- **(6) PRs and commits.** [lavkooapp#2](https://github.com/fullstackfull/lavkooapp/pull/2) `6b74524`; [lavkooapp#5](https://github.com/fullstackfull/lavkooapp/pull/5) `5526c4b`; [lavkooapp#7](https://github.com/fullstackfull/lavkooapp/pull/7) `56deea7`
- **(7) Tests.**
  - `ci` storefront_chooser_sheet_test (list, browse-only wording, order of calls, RTL, 320x640 at 2x text), storefront_switcher_test, storefront_change_notifier_test, storefront_controllers_registered_test, storefront_availability_test (lavkooapp #7 56deea7, job storefront-header, flag-off and flag-on runs)
- **(8) Dependencies and blockers.** Needs GET /api/v1/storefronts listing more than one active storefront (lavkoo #28, #39; PR 83 8ff3410) and the headers of J-03. No real-server run exists.
- **(9) Acceptance criteria.**
  - [MET] The chooser lists every server-listed storefront as orders available or browse-only, and is hidden with the flag off or one storefront listed (storefront_chooser_sheet_test 'the settings entry' and 'the sheet'; storefront_availability_test; #7 56deea7, job storefront-header)
  - [MET] A choice runs in this order: save, ask the server, rebuild headers, notify listeners, then go home (storefront_switcher_test; storefront_chooser_sheet_test 'the switch the sheet makes'; #7 56deea7)
  - [MET] Every holder of the left storefront's data drops it before any reload answers, and late answers are not shown (storefront_change_notifier_test 'lets every listener drop before any of them reloads'; storefront_scope_test; #7 56deea7)
  - [MET] The sheet fits 320x640 at 2x text in Arabic RTL and English, light and dark (storefront_chooser_sheet_test 'the layout'; #7 56deea7)
  - [CODED] After the switch the customer lands on the home route (onSwitched calls RouterHelper.getDashboardRoute; the callback is tested, the GoRouter navigation is not (PR 7 body))
  - [MISSING] The switch completes in bounded time on a bad network (StorefrontController.choose awaits refresh(); PR 7 body: up to 60 s in Loading)
- **(10) Next coding task.** On the #7 branch make StorefrontController.choose save and return, refreshing in the background; add a test with a never-completing refresh asserting switchTo completes; add a GoRouter test for the dashboard route after a switch.
- **Open owner decisions.** Arabic wording of the ten new strings and the six country names; the shared 'select' button reads as 'he chooses' in Arabic; bn, es, hi lines are unreviewed.

#### J-03. X-Storefront header on every API request

- **State: IN PROGRESS** | plan lines 333 | none (header contract in lavkoo #28) | reviews: none
- **(2) Functional requirement.** Every backend request names the customer's storefront (SA, AE, KW, QA, BH, OM) in X-Storefront when storefronts are on, and sends exactly today's headers when off, so the server resolves the right market (explicit choice, then header, then path, then default).
- **(3) Exists now in code (main).** main builds Authorization, Accept, X-Platform, X-App-Version, X-Client-Id and lang in DioClient._headers(): no storefront key. Chat, profile, refund, review and support-ticket uploads bypass DioClient (http.MultipartRequest) and send only Authorization.
- **(4) Done in the draft PRs.** lavkooapp #1: X-Storefront added inside a collection-if on StorefrontConfig.enabled, value from the stored code (SA default, unknown value falls back to SA), key gcc_storefront_country apart from the language country. #7: DioClient publishes the header's storefront as StorefrontScope and rebuilds headers on updateHeader (login, language, choice). #6 keys caches by it.
- **(5) What remains.** (1) The five multipart uploads send no X-Storefront, so the server resolves the default; reviews/submit sits behind the storefront middleware (effect UNVERIFIED). (2) WebViews carry no header (payment pages rely on the server-recorded storefront). (3) Five tests assume a flag-off build and fail with the flag on. (4) Never run against the real backend.
- **(6) PRs and commits.** [lavkooapp#1](https://github.com/fullstackfull/lavkooapp/pull/1) `2a8c84c`; [lavkooapp#6](https://github.com/fullstackfull/lavkooapp/pull/6) `547c9d9`; [lavkooapp#7](https://github.com/fullstackfull/lavkooapp/pull/7) `56deea7`
- **(7) Tests.**
  - `ci` dio_storefront_header_test (flag off: no X-Storefront key, unchanged after updateHeader and login); dio_cache_key_order_test and dio_storefront_namespace_test (flag on: header AE after a choice and rebuild, kept after login) (lavkooapp #7 56deea7, job storefront-header, flag-off and flag-on runs)
- **(8) Dependencies and blockers.** Backend resolver and middleware: lavkoo #8, #19, #28 (StorefrontResolver::HEADER, ResolveStorefrontMiddlewareTest), green in PR 83 8ff3410.
- **(9) Acceptance criteria.**
  - [MET] Flag off: the header map has main's keys and no X-Storefront (dio_storefront_header_test (3 cases); lavkooapp #7 56deea7, job storefront-header)
  - [MET] Flag on: a request after a choice and a header rebuild carries X-Storefront = the chosen code (SA when none) (dio_cache_key_order_test 'AE' assertion; dio_storefront_namespace_test; #7 56deea7 flag-on run)
  - [MET] A login or language change keeps the storefront in the headers (dio_storefront_namespace_test 'a login keeps the storefront that is published'; dio_cache_key_order_test; #7 56deea7)
  - [MET] The language country is never sent as the storefront, and an unknown stored value is never sent (storefront_config_test (language country; unknown code falls back to SA); #7 56deea7)
  - [MISSING] Multipart uploads carry X-Storefront (Review, refund, support ticket, profile and chat repositories build http.MultipartRequest with Authorization only)
  - [CODED] The real backend resolves the same market from the header (StorefrontResolver::HEADER and ResolveStorefrontMiddlewareTest green on lavkoo PR 83 8ff3410; no app-with-backend run)
- **(10) Next coding task.** On the #7 branch add a flag-gated StorefrontRequestHeaders helper used by DioClient and the five multipart repositories, with tests for both flags; make dio_storefront_header_test flag-agnostic so the flag-on CI step can run it.
- **Verifier note (not applied automatically).** remaining (1): 'The five multipart uploads' undercounts: git grep finds six http.MultipartRequest call sites in five repositories at 56deea7 (support_ticket_repository.dart builds two, create and reply). The audit 2026-11-17-customer-app-compat-security-prs.md also says '5 multipart uploads', so the miscount is inherited, but the code has six. Proposed: (1) The six multipart upload calls (chat_repository.dart:69, profile_repository.dart:41, refund_repository.dart:23, review_repository.dart:34, support_ticket_repository.dart:24 and :74) send only Authorization, so the server resolves the default storefront; reviews/submit sits behind the storefront middleware (effect UNVERIFIED). The five repositories in next_task and the AC evidence stay as they are.

#### J-04. Caches and local storage keyed by storefront

- **State: IMPLEMENTED IN DRAFT** | plan lines 333, 515-517 | DECISIONS.md (app-local caches market-scoped) | reviews: security
- **(2) Functional requirement.** Nothing the app remembers about a storefront (HTTP response cache in memory and on disk, local Drift rows, theme pages, controller lists, shop config) is shown or kept for another storefront; with the flag off every key is byte-identical, so caches already on phones stay valid.
- **(3) Exists now in code (main).** main keys the HTTP cache by path, query and language only (DioClient._normalizeCacheKey, 5-minute window) and the Drift response rows by endpoint plus language. After a market switch the previous catalogue would show from memory or disk for the cache window.
- **(4) Done in the draft PRs.** lavkooapp #6: ApiCacheKey adds #CODE only while storefronts are on (memory, in-flight, disk and _time keys). #7: Drift rows filed key#CODE, StorefrontScope drops late answers, StorefrontChangeNotifier clears the left storefront and tells 18 controllers (cart, wishlist, banners, theme, config, shops), source-scan inventory test of caches, prefs keys and ChangeNotifiers.
- **(5) What remains.** (1) SplashController re-asks the config on a switch through Get.context: untested. (2) Pre-existing: DataSyncRepo writes the request headers, bearer token included, into the local database: security review. (3) Wallet, orders, addresses and profile are deliberately not storefront-scoped in the app. (4) Flag-on never run on a device or against the backend.
- **(6) PRs and commits.** [lavkooapp#6](https://github.com/fullstackfull/lavkooapp/pull/6) `547c9d9`; [lavkooapp#7](https://github.com/fullstackfull/lavkooapp/pull/7) `56deea7`
- **(7) Tests.**
  - `ci` api_cache_key_test, dio_cache_storefront_test, cache_response_storefront_test, storefront_local_caches_test (sync rows, theme, flash deals, orders, config), dio_storefront_namespace_test, storefront_scope_test, storefront_catalogue_reload_test, storefront_change_notifier_test, storefront_cache_inventory_test (lavkooapp #7 56deea7, job storefront-header, flag off and flag on (SQLite in memory))
- **(8) Dependencies and blockers.** No backend change needed for the keys. Storefront-aware answers come from lavkoo #37, #47, #48, #50 (green in PR 83 8ff3410); /config is not storefront-aware there. No tree combines app and backend, so the state stops at IMPLEMENTED IN DRAFT.
- **(9) Acceptance criteria.**
  - [MET] Flag off: HTTP cache keys and Drift row keys equal main's; existing caches are still read (api_cache_key_test (11 pinned strings); dio_cache_storefront_test; storefront_local_caches_test 'while storefronts are off'; lavkooapp #7 56deea7, job storefront-header)
  - [MET] Flag on: an answer cached for one storefront is never read for another (memory, disk, Drift rows) (dio_cache_storefront_test flag-on group; storefront_local_caches_test 'two storefronts never read each other's rows'; #7 56deea7 flag-on run)
  - [MET] A switch clears the left storefront's HTTP and local rows, 18 listeners drop before any reload, late answers are neither shown nor stored (storefront_change_notifier_test; storefront_catalogue_reload_test; storefront_scope_test; #7 56deea7)
  - [MET] A new cache, store or ChangeNotifier fails a test until it is classified as storefront data or not (storefront_cache_inventory_test; #7 56deea7)
  - [CODED] The shop config (currencies, payment methods) is requested again after a switch (SplashController.onStorefrontChanged calls initConfig; no test (needs the GetX context, PR 7 body))
- **(10) Next coding task.** On the #7 branch: inject the context into SplashController.onStorefrontChanged (no Get.context) and test that a switch re-requests /config while the held config stays until the answer arrives; after security review stop persisting the Authorization header in DataSyncRepo and test it.

#### J-05. Per-storefront currency and price formatting

- **State: NOT STARTED** | plan lines 175-180 | DECISIONS.md (local pricing, no auto-FX) | reviews: finance, native-arabic
- **(2) Functional requirement.** Every price prints in the active storefront's currency (SAR, AED, KWD, QAR, BHD, OMR) with that currency's decimals (three for KWD, BHD, OMR; two for SAR, AED, QAR) in cart, checkout, orders and wallet, with no automatic conversion between storefronts.
- **(3) Exists now in code (main).** main formats prices in PriceConverter.convertPrice as whole numbers (toStringAsFixed(0)) after an exchange-rate conversion from the global /config currency_list, with a currency selector in Settings; other helpers use one global decimal_point setting. No test.
- **(4) Done in the draft PRs.** Nothing formats by storefront. lavkooapp #1 keeps the currency code in StorefrontCountry.currency (SAR, AED, KWD, QAR, BHD, OMR) but no code reads it; the Settings currency selector stays beside the new country row (#7).
- **(5) What remains.** (1) Formatter keyed by the storefront currency with ISO minor units. (2) Hide the FX currency selector while storefronts are on. (3) Route all PriceConverter methods and the 51 toStringAsFixed uses through one formatter. (4) Symbols and position in ar and en. (5) Backend: /config and /storefronts carry no symbol or decimals, and /config is not storefront-aware.
- **(6) PRs and commits.** [lavkooapp#1](https://github.com/fullstackfull/lavkooapp/pull/1) `2a8c84c`
- **(7) Tests.**
  - `none` none: no price formatting test exists on any head (lavkooapp main and #7 56deea7 have no price_converter test)
- **(8) Dependencies and blockers.** Backend storefront currency contract (unit D1/E, GCC-05 slice): storefront-aware config with symbol and minor units; today ConfigController returns one system_default_currency and a global currency_list (lavkoo PR 83 8ff3410).
- **(9) Acceptance criteria.**
  - [MISSING] A KW, BH or OM price prints with three decimals and its own currency symbol (PriceConverter.convertPrice uses toStringAsFixed(0) and the global symbol)
  - [MISSING] SA, AE and QA prices print with two decimals and their own symbol (Same formatter, whole numbers only)
  - [MISSING] No FX: no currency selector and no exchange-rate multiplication while storefronts are on (SelectCurrencyBottomSheetWidget stays in settings_screen.dart; convertPrice multiplies by exchangeRate)
  - [MISSING] Cart, checkout, order, wallet and coupon amounts all use one formatter (51 lines with toStringAsFixed( in lib (PR 7 head))
- **(10) Next coding task.** New branch from the #7 head: pure CurrencyFormat (code to minor units, symbol, position), PriceConverter methods use it when StorefrontConfig.enabled (flag-off output stays byte-identical), selector hidden with the flag on; tests with golden strings for six currencies, rounding and the flag-off pin.
- **Open owner decisions.** Currency symbol or code per language (for example KWD or the Arabic symbol), trailing zeros and rounding mode: finance to confirm.
- **Verifier note (not applied automatically).** plan_lines: Lines 175-180 are only the list of countries and currencies. The functional text's 'no automatic conversion between storefronts' and local pricing are plan lines 284-285 (section 5.5 Checkout and pricing). Proposed: 175-180, 284-285

#### J-06. Theme rendering per storefront and platform

- **State: IMPLEMENTED IN DRAFT** | plan lines 313-333 | GCC-07; GCC-09 | reviews: none
- **(2) Functional requirement.** The Flutter Dynamic Theme Engine renders the theme the server publishes for the customer's storefront and the app platform, negotiates what this build can draw, and never shows, caches or reuses another storefront's page, header, checksum or section data.
- **(3) Exists now in code (main).** main has the engine: ThemeCapabilities headers (X-UI-Components, X-UI-Engine, X-UI-Schema), device class, /theme/version revision check, ETag checksum, pages cached in Drift under /api/v1/theme/home::page. One global theme. Six theme tests exist; only dynamic_theme_controller_test is run by the drafts' CI.
- **(4) Done in the draft PRs.** lavkooapp #6: /theme/version answers keyed by storefront in the HTTP cache. #7: theme rows filed per storefront, a page fetched for a left storefront is dropped, DynamicThemeController.onStorefrontChanged clears payload, header, pages, sections and checksum and force-fetches the new home; late answers ignored.
- **(5) What remains.** (1) Web and app section customisation (GCC-09), drafts, scheduled publish, rollback and app-version compatibility are server side: no real-backend run. (2) No in-app preview of a draft theme (backend ThemePreviewToken exists, the app has no preview code). (3) dynamic_theme_render_matrix_test has two known RenderFlex failures and is in no CI job. (4) A switch to a storefront with no published theme is untested. (5) Per-storefront brand (GCC-10) is not wired.
- **(6) PRs and commits.** [lavkooapp#6](https://github.com/fullstackfull/lavkooapp/pull/6) `547c9d9`; [lavkooapp#7](https://github.com/fullstackfull/lavkooapp/pull/7) `56deea7`
- **(7) Tests.**
  - `ci` storefront_theme_reload_test (15 cases), storefront_local_caches_test 'the theme payload rows', dynamic_theme_controller_test (lavkooapp #7 56deea7, job storefront-header)
  - `pr-body` dynamic_theme_registry_test, dynamic_theme_schema_test, section_variant_coverage_test, theme_action_resolver_test, dynamic_theme_render_matrix_test (On main; run only locally per PR 5 to 7 bodies (2 known failures in the matrix test); in no CI job)
- **(8) Dependencies and blockers.** Backend theme chain (unit F): lavkoo #37 (theme route bound to the storefront, ThemeDelivery scoped cache key), per-storefront ThemeVersion and ThemeSection, platform rules in ViewerContext; green in PR 83 8ff3410. No tree combines app and backend.
- **(9) Acceptance criteria.**
  - [MET] Flag off: theme requests, cache keys and rows are the ones main uses (storefront_local_caches_test 'the page is filed under the key it always had'; storefront_theme_reload_test 'a language switch behaves as it did'; lavkooapp #7 56deea7, job storefront-header)
  - [MET] Two storefronts never read each other's theme page (HTTP cache and local row) (storefront_local_caches_test 'two storefronts never read each other's page'; dio_cache_storefront_test; #7 56deea7)
  - [MET] A switch drops payload, header, section data and checksum at once, then fetches the new home by force with no old ETag (storefront_theme_reload_test; #7 56deea7)
  - [MET] A theme answer for a storefront the shopper has left is not cached, shown or acted on (storefront_theme_reload_test; storefront_local_caches_test; #7 56deea7)
  - [CODED] The request tells the server which section types this build draws, so a storefront's sections are filtered per app build (capability_registry.dart on main; dynamic_theme_registry_test exists but runs in no CI job)
  - [CODED] A storefront that publishes different app and web sections is drawn from the app payload only (Server filters by platform in ThemeDelivery; the app asks with the device class; no app test with per-platform payloads)
- **(10) Next coding task.** On the #7 branch add the four non-CI theme test files to ci.yml, fix or quarantine with a reason the two render-matrix failures, and test a switch to a storefront whose revision is 0 (built-in home shown, not the old storefront's).

#### J-07. Cart and wishlist per storefront

- **State: IMPLEMENTED IN DRAFT** | plan lines 349 | GCC-13; single-store checkout constraint | reviews: none
- **(2) Functional requirement.** A customer or guest has a separate cart, wishlist, compare and restock list per storefront. After a switch the app shows only the new storefront's lists, and what derives from the cart (applied coupon, shipping choice) never carries over. One cart never mixes storefronts.
- **(3) Exists now in code (main).** main has one cart, wishlist and compare list per customer or guest id (CartController; guest id and token are the same everywhere; guest cart merged at login on dashboard init). No storefront notion.
- **(4) Done in the draft PRs.** lavkooapp #7: Cart, Wishlist, Compare, Restock, Coupon and Shipping controllers are told on a storefront change: drop at once, show loading, reload for the new storefront. A guest's wishlist, compare and restock lists are emptied and not requested (a 401 would sign the guest out). Late answers are ignored. Server isolation: lavkoo #48 (StorefrontCartScope).
- **(5) What remains.** (1) Fixtures are hand-written, not recorded from the backend; no run against lavkoo PR 83 with the flag on. (2) Guest-to-customer cart merge per storefront is reasoned from the server, not tested in the app.
- **(6) PRs and commits.** [lavkooapp#7](https://github.com/fullstackfull/lavkooapp/pull/7) `56deea7`
- **(7) Tests.**
  - `ci` storefront_cart_reload_test: cart, wishlist, compare, restock, search, coupon and shipping drop and reload for customer and guest, late answers (lavkooapp #7 56deea7, job storefront-header, flag off and flag on)
- **(8) Dependencies and blockers.** Backend cart isolation: lavkoo #48 (StorefrontCartIsolationTest, StorefrontCartGuardTest), green in PR 83 8ff3410. No tree combines app and backend, so the state stops at IMPLEMENTED IN DRAFT.
- **(9) Acceptance criteria.**
  - [MET] The cart is gone at once and loading before any answer; the new storefront's cart then shows (storefront_cart_reload_test 'the cart'; lavkooapp #7 56deea7, job storefront-header)
  - [MET] A cart answer on its way when the customer moved on is never shown (storefront_cart_reload_test 'never shows an answer that was on its way'; #7 56deea7)
  - [MET] A guest's wishlist, compare and restock lists are emptied, not requested (storefront_cart_reload_test 'of a guest is emptied and not asked for'; #7 56deea7)
  - [MET] The applied coupon, shipping choices and product-details choices derived from the cart are dropped (storefront_cart_reload_test 'what is derived from the cart'; #7 56deea7)
  - [CODED] A cart row added under one storefront is not visible or purchasable under another (Server scope lavkoo #48, StorefrontCartIsolationTest green on PR 83 8ff3410; the app adds no filter of its own)
- **(10) Next coding task.** Record real cart and wishlist answers for SA and KW from the backend integration tree (lavkoo PR 83 with the flag on, disposable database) and replace the hand-written fakes in storefront_cart_reload_test; add a guest-login merge test per storefront.

#### J-08. Sellable gate and paused-storefront modes

- **State: IN PROGRESS** | plan lines 445-459 | GCC-12; GCC-11 | reviews: native-arabic
- **(2) Functional requirement.** A storefront that may not sell (browse-only: legal readiness or pause) can be listed, chosen and browsed but cannot start an order, payment or wallet top-up; maintenance and redirect-to-default modes are honoured; earlier orders, payments, refunds, support and notifications keep working.
- **(3) Exists now in code (main).** main has one shop-wide maintenance screen (config maintenance_mode_data, customerApp flag) and no storefront concept; checkout always proceeds.
- **(4) Done in the draft PRs.** lavkooapp #3: StorefrontCountry.sellable (SA true, others false), StorefrontChoice.canSell. #5: GET /api/v1/storefronts refreshed at splash, link launch and choice; stored answer; CheckoutSaleGate; notice in cart and checkout; Proceed off; guards in CheckoutController.placeOrder, digitalPaymentPlaceOrder and WalletController.addFundToWallet; the 422 storefront_not_sellable message shown. Orders already placed are not gated.
- **(5) What remains.** (1) Maintenance and Redirect-to-default modes: no app code, and the backend list has no mode field (config/gcc.php: path, currency, active). (2) The controller and screen guards have no test: only the pure gate, canSell and the error handler are tested. (3) Arabic notice wording unreviewed. (4) A stale non-SA choice stays browse-only if the server flag is off.
- **(6) PRs and commits.** [lavkooapp#3](https://github.com/fullstackfull/lavkooapp/pull/3) `eda7814`; [lavkooapp#5](https://github.com/fullstackfull/lavkooapp/pull/5) `5526c4b`
- **(7) Tests.**
  - `ci` checkout_sale_gate_test, storefront_can_sell_test, storefront_controller_test, api_error_handler_storefront_test, storefront_browse_only_notice_test, storefront_availability_test (lavkooapp #5 5526c4b and #7 56deea7, job storefront-header)
- **(8) Dependencies and blockers.** Backend gate: lavkoo #39 (sellable in the list) and #42 (422 storefront_not_sellable on the REST order and payment routes), green in PR 83 8ff3410. Maintenance and redirect need a backend mode field (unit I2).
- **(9) Acceptance criteria.**
  - [MET] With the flag off every storefront may sell and no request or element is added (storefront_can_sell_test; storefront_controller_test 'while storefronts are off'; lavkooapp #7 56deea7, job storefront-header)
  - [MET] With the flag on SA sells before any answer, every other storefront is browse-only until the server marks it sellable, and the server's answer wins (storefront_can_sell_test; #7 56deea7)
  - [MET] The gate can switch a button off but never on, and the notice shows exactly while the storefront may not sell (checkout_sale_gate_test; storefront_browse_only_notice_test; #7 56deea7)
  - [CODED] A 422 storefront_not_sellable shows the server's own localized message, not the generic 422 or payment-not-configured text (api_error_handler_storefront_test; #5 5526c4b, #7 56deea7)
  - [CODED] On a browse-only storefront no order, payment or wallet top-up request leaves the app (Guards in CheckoutController (2) and WalletController (1); no controller or screen test)
  - [MISSING] Maintenance and redirect-to-default storefront modes are honoured by the app (No app code; the backend list carries no mode field)
- **(10) Next coding task.** On the #7 branch add controller tests (scripted adapter, browse-only gate) proving placeOrder, digitalPaymentPlaceOrder and addFundToWallet send no request; with the backend unit define a mode key (browse_only, maintenance, redirect) in StorefrontListing, parse it in StorefrontCountry, then add the maintenance screen and redirect-to-default.
- **Open owner decisions.** Approve the Arabic notice wording (PR 5 asks) and the bn, es, hi lines; the app screens for Maintenance and Redirect wait for a backend mode field.

#### J-09. Payment and wallet flows per storefront

- **State: IN PROGRESS** | plan lines 291-311, 351 | GCC-13; GCC-15 | reviews: security, finance
- **(2) Functional requirement.** The app starts payments only for the storefront it is on, offers only the gateways and wallet that storefront's legal entity supports (Paymob, MyFatoorah, Tabby, Tamara where eligible), finishes a payment in the storefront it was started in, and shows the wallet and top-up of that storefront's currency.
- **(3) Exists now in code (main).** main: payments through POST /api/v1/digital-payment (WebView or InAppBrowser), MyFatoorah return handling (host check, reference from the callback URL, outcome from /payment/myfatoorah/status), generic success/fail/cancel matching for other gateways, wallet top-up and wallet pay. Methods come from the global /config. No Tabby or Tamara code.
- **(4) Done in the draft PRs.** lavkooapp #5 only: payment start and wallet top-up are refused before any request on a browse-only storefront, and the server's 422 message is shown. Payment requests carry X-Storefront through DioClient (#1, #7); lavkoo #53 records the storefront at payment start (PaymentStorefront) so a callback finishes in it.
- **(5) What remains.** (1) Method list is the global /config payment_methods plus cash_on_delivery and offline flags: nothing per storefront, currency or merchant eligibility. (2) Tabby, Tamara: no app or backend code. (3) Wallet balance and ledger are customer-global in the app. (4) No app test of a payment started under one storefront and returned after a switch.
- **(6) PRs and commits.** [lavkooapp#1](https://github.com/fullstackfull/lavkooapp/pull/1) `2a8c84c`; [lavkooapp#5](https://github.com/fullstackfull/lavkooapp/pull/5) `5526c4b`; [lavkooapp#7](https://github.com/fullstackfull/lavkooapp/pull/7) `56deea7`
- **(7) Tests.**
  - `pr-body` myfatoorah_return_url_test, myfatoorah_payment_status_test (host check, reference parsing, unresolved never a failure) (On main, in no CI job; PR 6 and 7 bodies report the full suite run locally)
  - `ci` api_error_handler_storefront_test (refusal message) (lavkooapp #5 5526c4b, #7 56deea7, job storefront-header)
- **(8) Dependencies and blockers.** Backend (units D2, E): storefront-aware payment method list and wallet per storefront and legal entity; lavkoo #53 pins the callback storefront (StorefrontPaymentCallbackTest). CI success does not prove a real merchant account works (plan line 710).
- **(9) Acceptance criteria.**
  - [CODED] A MyFatoorah return is accepted only on the shop's host with a reference, the outcome comes from the server status, and an unresolved status never reads as a failure (myfatoorah_return_url_test and myfatoorah_payment_status_test on main, in no CI job)
  - [CODED] No payment or wallet top-up request leaves the app on a browse-only storefront (Guards in CheckoutController.digitalPaymentPlaceOrder and WalletController.addFundToWallet; no controller test)
  - [CODED] A refused payment shows the server's own message (api_error_handler_storefront_test; lavkooapp #7 56deea7, job storefront-header)
  - [MISSING] Only the gateways and methods enabled for the current storefront (currency, legal entity, merchant) are offered (config_model.dart reads the global payment_methods; ConfigController is not storefront-aware (lavkoo PR 83))
  - [CODED] A payment started in one storefront finishes there even if the customer switched meanwhile (X-Storefront on POST /digital-payment; server PaymentStorefront (lavkoo #53); no app test)
  - [MISSING] The wallet balance, ledger and top-up show the current storefront's wallet and currency (Wallet and profile are customer-global; the inventory test marks the wallet controller not storefront data)
- **(10) Next coding task.** Add the myfatoorah and deep-link tests to ci.yml; add a widget test of DigitalPaymentOrderPlaceScreen with a scripted adapter (return URL, status poll carries X-Storefront, unresolved, paid); wire the method list to a storefront-aware payment_methods once the backend exposes it.
- **Open owner decisions.** Per-market gateway and BNPL eligibility (Paymob, MyFatoorah, Tabby, Tamara) per legal entity and merchant account (plan lines 303-307).

#### J-10. Deep links, App Links and old links

- **State: IN PROGRESS** | plan lines 193 | none | reviews: owner-decision, security
- **(2) Functional requirement.** Existing Saudi links keep opening the app; links with a storefront path (/ae/..., /kw/...) open the right product, brand or order under a defined storefront rule; Android App Links and iOS universal links are declared for the production host.
- **(3) Exists now in code (main).** main: Android intent filter (autoVerify, host lavkoo.com; paths /, /track-order, /referral-login, /product/, /vendor-shop/, /brand/, /products, /go/); iOS entitlement applinks:pharmacyksa.com; DeepLinkResolver asks /api/v1/deep-link/resolve and falls back to reading the path; routes /brand/:slug, /products, /track-order/:orderId, /go/:code; deep_link_resolver_test.
- **(4) Done in the draft PRs.** Nothing for links. lavkooapp #5 only refreshes the storefront list when a launch from a link loads the config (main.dart). No branch touches AndroidManifest, entitlements, DeepLinkResolver or the routes.
- **(5) What remains.** (1) No pathPrefix for /sa to /om in the Android filter; DeepLinkResolver.readLocally and the router do not know a storefront segment (it falls to a web view). (2) The rule when a link names another storefront than the chosen one. (3) The iOS entitlement names pharmacyksa.com while Android and baseUrl use lavkoo.com; live association files UNVERIFIED. (4) Backend config/deeplinks.php lists unprefixed paths only.
- **(6) PRs and commits.** [lavkooapp#5](https://github.com/fullstackfull/lavkooapp/pull/5) `5526c4b`
- **(7) Tests.**
  - `pr-body` deep_link_resolver_test (published paths read locally, short link to home, unpublished path to web) (On main, in no CI job; PR 7 body reports the full suite flag-off run)
- **(8) Dependencies and blockers.** Backend web paths /sa to /om and old-link redirects (unit A1), config/deeplinks.php and AssociationFileWriter published paths; the owner's rule for a link naming another storefront.
- **(9) Acceptance criteria.**
  - [CODED] The published Saudi paths (/product/{slug}, /brand/{slug}, /products, /track-order/{id}, /go/{code}) still open the matching screen (AndroidManifest filter and DeepLinkResolver.readLocally on main; deep_link_resolver_test, in no CI job)
  - [MISSING] A link with a storefront prefix (/ae/product/x) is accepted by the app (Android filter, resolver, router) (No pathPrefix /ae in the manifest; readLocally switches on segments.first only)
  - [MISSING] A link names its storefront and the app applies the owner's rule (switch, ask or stay) before opening (No storefront field in ResolvedDeepLink)
  - [MISSING] iOS associated domains and Android App Links name the same production host (Runner.entitlements applinks:pharmacyksa.com versus manifest host lavkoo.com)
  - [MISSING] The resolver's offline fallback and the server's /deep-link/resolve agree on prefixed paths (Backend config/deeplinks.php lists unprefixed paths only)
- **(10) Next coding task.** Branch from main: DeepLinkResolver.readLocally strips a leading storefront segment (sa, ae, kw, qa, bh, om) into ResolvedDeepLink.storefront; add the six pathPrefix entries and routes; apply the owner rule through StorefrontSwitcher; extend deep_link_resolver_test and add it to ci.yml; backend: publish the prefixed paths in config/deeplinks.php.
- **Open owner decisions.** What a link that names another storefront does (switch, ask, or open as the current one; manual choice precedes inference, plan line 189). Which host is production for iOS (pharmacyksa.com or lavkoo.com).
- **Verifier note (not applied automatically).** ac[0].t and ac[4].e (criteria 1 and 5): Criterion 1 lists /track-order/{id} among 'the published Saudi paths' that open the matching screen. The backend does not publish it: config/deeplinks.php (PR 83 8ff34109) lists only /, /product/*, /brand/*, /products and /go/*, and its comment says /track-order is DELIBERATELY ABSENT. With the shop reachable, DeepLinkResolver.resolve takes the server answer (target web, reason this_path_is_not_published_as_an_app_link) and deep_link_gateway_screen.dart opens the browser; readLocally maps it to order_tracking only when the call fails. The manifest also declares /referral-login and /vendor-shop/, which neither side maps to a screen. So the app fallback and the server already disagree on unprefixed paths, not only on prefixed ones (criterion 5). Proposed: Criterion 1: 'The published Saudi paths (/, /product/{slug}, /brand/{slug}, /products, /go/{code

#### J-11. Push topics and notifications per storefront

- **State: IN PROGRESS** | plan lines 405-418 | plan 5.13 | reviews: legal
- **(2) Functional requirement.** A push reaches only the audience of its storefront: topics are scoped per storefront, an order push opens the right order even when the storefront is paused, a push names its storefront so the app opens the right context, and marketing pushes respect the customer's consent.
- **(3) Exists now in code (main).** main subscribes every install to global topics: sixvalley (twice), maintenance_mode_start_user_app, theme_updates_user_app, plus server-named cart and restock topics, and registers one token (/customer/cm-firebase-token). NotificationHelper routes order, wallet, chatting, restock and referral payloads; NotificationBody has no storefront. No consent control beyond the OS prompt and iOS ATT.
- **(4) Done in the draft PRs.** Nothing: lavkooapp #1 to #7 leave topic, token and notification code untouched. The theme, maintenance and marketing topics stay global.
- **(5) What remains.** Storefront-scoped topic names and subscribe/unsubscribe on login, logout and switch; a storefront field in the payload and a rule for a tap on another storefront's order, wallet or restock push; a consent control per scope; keep order notifications when a storefront is paused. Backend audience isolation (FCM topics, tokens per storefront) is a dependency.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `none` none: no test of topics or push routing (-)
- **(8) Dependencies and blockers.** Backend notification audience and topic naming (unit I1): PushNotificationTrait, NotificationController, campaign targeting by storefront; token storage per storefront. UNVERIFIED: how the backend names the cart and restock topics today.
- **(9) Acceptance criteria.**
  - [MISSING] Marketing topics are scoped by storefront so one country's push cannot reach another's audience (AppConstants.topic = 'sixvalley', subscribed in updateDeviceToken)
  - [MISSING] A switch or logout unsubscribes the left storefront's topics and subscribes the new one's (No unsubscribe or per-storefront subscribe code in lib)
  - [MISSING] A push carries its storefront and the tap opens the order, wallet or product in that storefront's context (NotificationBody has no storefront field)
  - [CODED] An order push opens the order by id on any storefront, including a browse-only one (notification_helper routes type order to getOrderDetailsScreenRoute with no sale gate; no test)
  - [MISSING] Marketing consent is captured per scope and respected (No consent state or screen in lib)
- **(10) Next coding task.** New branch from the #7 head: StorefrontTopics (names such as lavkoo_{code}_{kind}) over an injectable messaging wrapper, subscribe at login and splash, move on switch and logout; optional storefront key in NotificationBody and a tap rule; unit tests with a fake wrapper. Backend: topic and audience contract.
- **Open owner decisions.** Topic naming and whether the legacy sixvalley topic stays for SA; default marketing consent per storefront (legal).

#### J-12. One login across storefronts and auth security compatibility

- **State: IMPLEMENTED IN DRAFT** | plan lines 347-348, 357 | GCC-13 | reviews: security, owner-decision
- **(2) Functional requirement.** One customer account and one login serve every storefront and the web. Switching storefront never signs the customer out, and the social-login 'is this your account?' answer carries the server's one-use token, so the app works with the backend security fix.
- **(3) Exists now in code (main).** main: token and guest id in prefs shared by all requests; the social-login answer with user opens the 'is this your account?' sheet, and existing-account-check sends only email, user_response and medium (the backend fix, lavkoo #60, refuses that).
- **(4) Done in the draft PRs.** lavkooapp #4 (from main): AuthController keeps temporary_token from social-customer-login and sends it once with existing-account-check, only when given (5 files, +26/-7). #7: sign-in prefs and AuthController are not storefront data; a switch keeps token and guest id; a guest's lists are not requested, so no 401 sign-out.
- **(5) What remains.** (1) #4 has no test and no CI run (no workflow on its tree): nothing compiled it. (2) Ship order: #4 first, then lavkoo #60 (owner guide); older builds lose only that sheet (owner decision D1). (3) Merge with the GCC stack not run, but the file sets are disjoint. (4) Password and OTP flows against the security set rest on the audit, not on a device.
- **(6) PRs and commits.** [lavkooapp#4](https://github.com/fullstackfull/lavkooapp/pull/4) `08b9b9d`; [lavkooapp#7](https://github.com/fullstackfull/lavkooapp/pull/7) `56deea7`
- **(7) Tests.**
  - `ci` storefront_cart_reload_test guest cases and storefront_cache_inventory_test (auth is not storefront data) (lavkooapp #7 56deea7, job storefront-header)
  - `none` none for the temporary_token hand-off (lavkooapp #4 08b9b9d has no CI run)
- **(8) Dependencies and blockers.** Backend security set: lavkoo #60 (PR 75 47b78ab) needs #4 first; the security set and the GCC chain are not combined (no tree contains both); no tree combines app and backend either.
- **(9) Acceptance criteria.**
  - [MET] A storefront switch never clears the saved token or guest id and starts no request that signs a guest out (storefront_cart_reload_test guest groups; storefront_cache_inventory_test 'the sign-in, the same at every storefront'; lavkooapp #7 56deea7, job storefront-header)
  - [CODED] The app sends temporary_token once, only when the server gave one, with existing-account-check (#4 08b9b9d diff (auth_controller.dart, auth_repository.dart); no test, no CI run)
  - [CODED] One account for all storefronts: the app has no per-storefront account or token and the login routes are the same everywhere (Token in prefs, no storefront key in the auth code; the server users table is global)
  - [CODED] Existing sign-ins survive the upgrade: the token, guest id and user-data pref keys are untouched by #1 to #7 (git diff main..#7 touches no auth file; storefront_cache_inventory_test lists the sign-in keys)
- **(10) Next coding task.** On gcc/security-social-confirmation-token add a unit test of the temporary_token hand-off (kept from the answer with user, sent once, absent when not given) and a minimal analyze-and-test workflow so the PR gets a CI run; security review before it ships first.
- **Open owner decisions.** D1 of the owner guide: deploy lavkoo #60 without waiting for adoption of #4, or wait.

#### J-13. Order history across storefronts

- **State: IN PROGRESS** | plan lines 350, 352 | GCC-13 | reviews: finance
- **(2) Functional requirement.** A customer sees their own orders from every storefront in one history, each in the currency and storefront it was placed in. Order details, tracking, refund, support and chat stay available for any order even when its storefront is browse-only or paused.
- **(3) Exists now in code (main).** main: GET /api/v1/customer/order/list?limit=10&offset= with no storefront parameter; OrderModel has no storefront or currency field; amounts use the global PriceConverter; the delivered-orders row is cached in the local database.
- **(4) Done in the draft PRs.** lavkooapp #5: the gate asks only about starting sales; existing orders, tracking and viewing are not gated. #7: the orders row (reorder list) is filed per storefront and late answers are dropped; order controllers are deliberately not told on a switch (they reload when their screen opens).
- **(5) What remains.** (1) Order currency and storefront are not parsed or shown: an AED order would print in the active currency. (2) Backend: no migration in PR 83 adds a storefront or order-currency column to orders, and the order list route is unfiltered. (3) No test that one history spans two storefronts.
- **(6) PRs and commits.** [lavkooapp#5](https://github.com/fullstackfull/lavkooapp/pull/5) `5526c4b`; [lavkooapp#7](https://github.com/fullstackfull/lavkooapp/pull/7) `56deea7`
- **(7) Tests.**
  - `ci` storefront_local_caches_test 'the delivered orders row'; api_error_handler_storefront_test (order-again refusal message) (lavkooapp #7 56deea7, job storefront-header)
- **(8) Dependencies and blockers.** Backend order snapshot of company, currency, tax and storefront (plan 286, unit D1) and the formatter of J-05.
- **(9) Acceptance criteria.**
  - [CODED] The history lists the customer's orders of every storefront (no storefront filter in the app or in the list route) (orderUri has no storefront parameter; lavkoo PR 83 api.php: customer/order/list has no storefront middleware)
  - [MISSING] Each order and its totals print in the order's own currency with its storefront shown (OrderModel parses no currency or storefront; PriceConverter uses the global currency)
  - [CODED] Details, tracking, refund, support and chat of an existing order work on any storefront, including a browse-only one (The gate asks only about new sales (checkout_sale_gate.dart); no test)
  - [MET] The cached orders row of one storefront is never shown on another (storefront_local_caches_test 'the delivered orders row'; lavkooapp #7 56deea7, job storefront-header)
- **(10) Next coding task.** On the #7 branch parse optional currency and storefront_country in OrderModel behind the flag, format order totals with J-05's formatter, add a two-storefront history test on hand-made fixtures, and re-record them once the backend order snapshot lands.
- **Verifier note (not applied automatically).** plan_lines: 'each in the currency ... it was placed in' rests on plan lines 286-287 (orders keep a snapshot of company, currency, tax, prices; refunds in the original currency), which are not cited. Lines 350 and 352 do not say it. Proposed: 286-287, 350, 352

#### J-14. Address eligibility per storefront

- **State: NOT STARTED** | plan lines 191, 353 | GCC-16; docs/address/README.md | reviews: legal
- **(2) Functional requirement.** Addresses belong to the customer account, and each is checked for eligibility against the current storefront (country, region, city, courier coverage) before checkout. A visitor from a non-active country browses the default storefront but buys only with an eligible address. Saudi addresses use the central region, city, district and National Address.
- **(3) Exists now in code (main).** main: free-text AddressModel (address, city, zip, state, country); add-address screen with the template default country 'IN', delivery-country restriction list from config, GPS pin via geolocator; no call to /api/v1/address/regions or cities; no storefront eligibility.
- **(4) Done in the draft PRs.** Nothing: no lavkooapp PR touches the address feature; the address controller is deliberately not told on a switch.
- **(5) What remains.** Central address catalog client (regions, cities, district, National Address) behind the unified_address_mode setting (off, soft, strict; strict needs every client to send the structured address), eligibility message at address save and at checkout, catalogs for the non-Saudi markets, removal of the template default country 'IN'. Backend eligibility API for GCC countries is not in the drafts.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `none` none: no test of address eligibility or the central address catalog in the app (-)
- **(8) Dependencies and blockers.** Backend central address system (docs/address/README.md; routes api/v1/address/regions and cities exist in PR 83), unit H1 for courier coverage and product eligibility of regulated goods. strict mode would break this app.
- **(9) Acceptance criteria.**
  - [MISSING] The app can save a structured Saudi address (region id, city id, district, National Address) from the central catalog (The app never calls /api/v1/address/regions or /cities; AddressModel is free text)
  - [MISSING] An address outside the current storefront's deliverable area is refused at save or checkout with a clear message (No eligibility code in lib/features/address)
  - [MISSING] A visitor outside the active markets browses the default storefront but cannot order with an ineligible address (The gate is per storefront only (sellable), never per address)
- **(10) Next coding task.** Read docs/address/README.md and design.md, then on a new branch add AddressCatalogRepository for GET /api/v1/address/regions and /cities behind a GCC_UNIFIED_ADDRESS dart-define and a config-driven mode, with tests on hand-made fixtures; leave the legacy form untouched while the mode is off.
- **Open owner decisions.** none (address design decisions are in docs/address)
- **Verifier note (not applied automatically).** ac[1].e (criterion 'An address outside the current storefront's deliverable area is refused ...'): 'No eligibility code in lib/features/address' is false. origin/main already has a shop-wide delivery restriction: config delivery_country_restriction, AddressController.getRestrictedDeliveryCountryList and getRestrictedDeliveryZipList, called from add_new_address_screen.dart lines 105-107 and used at lines 197-201, 488 and 543 to limit the country and zip pick lists. It is not per storefront, region or city, and it refuses nothing at save or checkout with a message, so the mark stays MISSING. Proposed: Only the shop-wide delivery_country_restriction country and zip pick lists exist (add_new_address_screen.dart 105-107, 197-201, 488, 543; address_controller.dart restrictedCountryList). Nothing is per storefront, region, city or courier coverage, and nothing refuses an address at save or chec

#### J-15. Legal policy screens and consent scopes

- **State: IN PROGRESS** | plan lines 354, 393-399 | GCC-11 | reviews: legal, native-arabic
- **(2) Functional requirement.** The app shows each storefront's approved legal documents (terms, privacy, refund, return) in the customer's language, requires acceptance before an order, records which version was accepted in which storefront, and keeps marketing consent in its proper scope.
- **(3) Exists now in code (main).** main: checkout and sign-up checkboxes open pages by slug (terms-and-conditions, privacy, refund, return) from the global /api/v1/business-pages list loaded at dashboard start; acceptance is a local checkbox; nothing is sent to record a document version.
- **(4) Done in the draft PRs.** Only indirectly: lavkooapp #5 honours the server's sellable flag (lavkoo #38, #39 set it from legal readiness), so an unready storefront is browse-only. Business pages sit in the response cache, whose key carries the storefront (#6).
- **(5) What remains.** (1) No REST route serves a storefront's legal documents to the app (backend has StorefrontLegalDocument and StorefrontLegalAcceptanceRecorder but no route and no caller). (2) The app sends no accepted-document field with an order. (3) No marketing-consent UI or per-scope state. (4) Arabic legal text per country needs legal review.
- **(6) PRs and commits.** [lavkooapp#5](https://github.com/fullstackfull/lavkooapp/pull/5) `5526c4b`; [lavkooapp#6](https://github.com/fullstackfull/lavkooapp/pull/6) `547c9d9`
- **(7) Tests.**
  - `none` none: no test of legal pages, acceptance or consent (-)
- **(8) Dependencies and blockers.** Backend (unit I1): a public legal-documents route per storefront and an accepted-documents field on order placement (lavkoo #38 legal readiness, #43 acceptance receipts, test-only).
- **(9) Acceptance criteria.**
  - [MISSING] Checkout and sign-up show the current storefront's approved terms, privacy, refund and return text in the app language (Pages come from the global /api/v1/business-pages by slug)
  - [MISSING] An order carries the accepted document type, version and storefront so the server can record it (StorefrontLegalAcceptanceRecorder has no caller; the app sends no acceptance field)
  - [MISSING] Marketing consent is captured per scope and respected by push and email (No consent state or screen in lib)
  - [CODED] Business pages cached for one storefront are never shown for another (/api/v1/business-pages is in DioClient's cacheable list and ApiCacheKey adds the storefront; no endpoint-specific test)
- **(10) Next coding task.** Backend first (unit I1): a public GET legal-documents route per storefront and an accepted-documents field on order placement; then in the app a LegalDocumentRepository and a checkout acceptance payload behind the flag, tested with fakes.
- **Open owner decisions.** Which document types need explicit acceptance per storefront and whether the customer's identity is stored with the acceptance (config gcc.storefronts.legal): legal and privacy review.

#### J-16. Language, RTL and strings

- **State: IMPLEMENTED IN DRAFT** | plan lines 515-517 | none | reviews: native-arabic
- **(2) Functional requirement.** Arabic (default, RTL) and English work in every new GCC screen and message, every new key exists in all shipped language files, a language change still clears every storefront's caches and refreshes the theme, and the lang header carries the language code only.
- **(3) Exists now in code (main).** main: ar default and en, RTL for ar (isLtr), five language JSON files, LocalizationController.setLanguage clears Drift, memory and disk caches and refreshes the theme, lang header from prefs, localization_keys_test (run by no job on main).
- **(4) Done in the draft PRs.** lavkooapp #5: browse-only notice in en, ar, bn, es, hi. #7: ten more keys (chooser, status labels, six country names, switch failure) in all five files, RTL and 320x640 at 2x text widget tests; the language switch behaves as before.
- **(5) What remains.** (1) Native-Arabic review of every new string (listed in the PR 5 and 7 bodies; the shared 'select' button reads 'he chooses'); bn, es, hi unreviewed. (2) A language switch with the flag on has no end-to-end test over HTTP cache, local rows and theme (theme only). (3) Arabic wording for currency and legal screens still to come.
- **(6) PRs and commits.** [lavkooapp#5](https://github.com/fullstackfull/lavkooapp/pull/5) `5526c4b`; [lavkooapp#7](https://github.com/fullstackfull/lavkooapp/pull/7) `56deea7`
- **(7) Tests.**
  - `ci` localization_keys_test, storefront_chooser_sheet_test ('the language files', 'the layout'), storefront_browse_only_notice_test (ar, en, narrow, RTL icon) (lavkooapp #7 56deea7, job storefront-header)
- **(8) Dependencies and blockers.** A native-Arabic reader; J-05 and J-15 add more strings. No tree combines app and backend, so the state stops at IMPLEMENTED IN DRAFT.
- **(9) Acceptance criteria.**
  - [CODED] Every storefront string exists, non-empty, in ar, en, bn, es and hi (localization_keys_test; storefront_chooser_sheet_test 'carry every word the chooser says, in all five languages'; lavkooapp #7 56deea7, job storefront-header)
  - [MET] The chooser and the notice read in Arabic in words (not keys) and put the mark and icon at the right end in RTL and LTR (storefront_chooser_sheet_test; storefront_browse_only_notice_test; #7 56deea7)
  - [MET] No overflow at 320x640 with 2x text in either language, light and dark (storefront_chooser_sheet_test 'the layout'; storefront_browse_only_notice_test; #7 56deea7)
  - [MET] The lang header carries the language code (ar or en) and follows a language switch (dio_cache_key_order_test asserts the lang header 'ar' after a switch; #7 56deea7)
  - [CODED] A language switch with storefronts on clears every cached answer and refreshes the theme (LocalizationController.setLanguage; only the theme part is tested (storefront_theme_reload_test 'a language switch behaves as it did'))
- **(10) Next coding task.** Native-reader review table in the PR body; add a test that every new storefront_ key in bn, es and hi is non-empty and marked untranslated; add an end-to-end language-switch test with storefronts on (HTTP cache, Drift rows, theme).
- **Open owner decisions.** Native-Arabic approval of the new Arabic strings and the six country names; wording of the shared select button.

#### J-17. Version gate and update flow (never bypassed)

- **State: IN PROGRESS** | plan lines 97, 320, 562 | Charter section 4 (version gates); DECISIONS.md guardrail 4 | reviews: owner-decision
- **(2) Functional requirement.** A build older than the minimum version the shop publishes (status on) is held at the update screen and cannot proceed. Nothing in the GCC work bypasses, lowers or bumps that gate, and the update screen leads somewhere usable.
- **(3) Exists now in code (main).** main: SplashScreen._updateIsRequired compares user_app_version_control (per platform, status 1, minimum) with AppConstants.appVersion 16.2.1 (pubspec says 3.0.6+48) and routes to UpdateScreen, which opens the configured store link or a fallback. The shop publishes floor 14.1 with an empty link (comment in app_constants.dart, read 2026-11-11).
- **(4) Done in the draft PRs.** Nothing changes it, by design: the diff of #7 against main leaves _updateIsRequired, UpdateScreen, appVersion, pubspec.yaml, android/ and ios/ untouched; the 2026-11-17 audit confirms no PR touches X-App-Version or the gate.
- **(5) What remains.** (1) No test pins the gate or the appVersion-versus-floor rule: app_constants.dart cites test/release_version_test.dart, which exists on no ref. (2) The gate logic sits in a widget State, so it cannot be unit tested. (3) The shop floor (14.1) and the app's real version (3.0.6) disagree: owner decision. (4) UpdateScreen passes the configured link unchanged: an empty link fails canLaunchUrlString and a missing one hits appUrl!.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `none` none: no test of _updateIsRequired, UpdateScreen or appVersion (test/release_version_test.dart, cited in app_constants.dart, exists on no ref)
- **(8) Dependencies and blockers.** Owner decision on aligning the shop's published floor with the app's numbering; the lavkoo user_app_version_control setting. Release of any build is separate from this branch work.
- **(9) Acceptance criteria.**
  - [CODED] A build below the published minimum (status 1, the platform's entry) is routed to the update screen before maintenance or login (splash_screen.dart _updateIsRequired and the two route branches; no test)
  - [CODED] No update is forced when status is not 1 or the minimum is empty (Same method; no test)
  - [CODED] The GCC branches leave the gate, appVersion, pubspec version, package id and Firebase settings untouched (git diff main..#7 over splash_screen.dart shows only the storefront refresh; pubspec, android and ios are identical (diff stat empty))
  - [MISSING] A regression test pins the gate (equal, lower, higher, status off, empty, non-numeric) and the appVersion-versus-floor rule (test/release_version_test.dart is cited but absent)
  - [MISSING] The update screen offers a usable target when the config link is empty (UpdateScreen passes the link unchanged: an empty link fails canLaunchUrlString)
- **(10) Next coding task.** Extract the comparison from SplashScreen into a pure UpdateGate (same semantics), add test/release_version_test.dart with the cases above and list it in ci.yml; do not change appVersion, the shop floor or pubspec.
- **Open owner decisions.** Which way to align the version numbers (lower the shop's floor to at most 3.0.6 and set appVersion to match, or renumber the app at or above 14.1). The charter forbids bumping a version or lowering a minimum on a test constant alone.
- **Verifier note (not applied automatically).** refs: DECISIONS.md guardrail 4 ('audit API v3/seller and seller app version gate') is about the seller app's gate, not the customer app's. The Charter section 4 reference is right. The rule that covers this app is DISCOVERY_PROMPT_01.md line 25 ('do not rename ... release version gates') and the app's own CLAUDE.md ('do not automatically rename package IDs, bundle IDs, Firebase settings, imports or version numbers'). Proposed: Charter section 4 (version gates); DISCOVERY_PROMPT_01.md line 25 and lavkooapp CLAUDE.md (no version, package id or Firebase change); DECISIONS.md guardrail 4 names the seller app's gate and is context only.

#### J-18. App tests, CI and device verification

- **State: IN PROGRESS** | plan lines 700-716 | Charter sections 5 and 7 | reviews: none
- **(2) Functional requirement.** Every app change is covered by tests that run in CI on a pinned Flutter toolchain in both flag settings, the whole app is analysed, the full suite is green and the storefront flows are proven on a device against the backend integration tree, with no check bypassed.
- **(3) Exists now in code (main).** main: no .github folder and no CI; 11 test files (banner model, deep link resolver, dynamic theme x4, localization keys, MyFatoorah x2, section variant coverage, theme action resolver) that no job runs; whole-project analyze reports 255 findings on the stack (PR 5 to 7 bodies).
- **(4) Done in the draft PRs.** lavkooapp #1 adds .github/workflows/ci.yml (job storefront-header, Flutter 3.47.0, push to gcc/** and pull request to main); #2 to #7 grow its lists: scoped flutter analyze, cart screen errors-only, 30 test files flag off (28 new plus localization_keys_test and dynamic_theme_controller_test) and a flag-on run of 15 files. Green on #7 56deea7.
- **(5) What remains.** (1) CI analyses listed files only; the whole app has 255 findings and cart_screen.dart is checked for errors only. (2) The full flutter test run is in no job: nine of main's 11 test files run nowhere (banner model, deep link, MyFatoorah x2, five theme); dynamic_theme_render_matrix_test has two known RenderFlex failures. (3) Five tests assume a flag-off build and fail with the flag on. (4) No device or emulator run, no real backend. (5) #4 has no workflow run; stacked PRs get CI only from pushes to gcc/**.
- **(6) PRs and commits.** [lavkooapp#1](https://github.com/fullstackfull/lavkooapp/pull/1) `2a8c84c`; [lavkooapp#2](https://github.com/fullstackfull/lavkooapp/pull/2) `6b74524`; [lavkooapp#3](https://github.com/fullstackfull/lavkooapp/pull/3) `eda7814`; [lavkooapp#4](https://github.com/fullstackfull/lavkooapp/pull/4) `08b9b9d`; [lavkooapp#5](https://github.com/fullstackfull/lavkooapp/pull/5) `5526c4b`; [lavkooapp#6](https://github.com/fullstackfull/lavkooapp/pull/6) `547c9d9`; [lavkooapp#7](https://github.com/fullstackfull/lavkooapp/pull/7) `56deea7`
- **(7) Tests.**
  - `none` storefront-header job: scoped analyze, 30 test files flag off, 15 flag on (refuted by the verifier: The counts are true only at #7 56deea7. ci.yml at #1 runs 2 test files, #2 and #3 run 3, #5 runs 13 and #6 runs 15, all flag off only; the flag-on step (15 file)
- **(8) Dependencies and blockers.** Backend integration tree (lavkoo PR 83 8ff3410, CI green except the known overlay job) for any app-with-backend run; a staging backend with fake data and devices for the manual plan in PR 7.
- **(9) Acceptance criteria.**
  - [MET] The storefront tests run in CI on pushes to gcc/** and pull requests to main with Flutter 3.47.0, flag off and flag on (ci.yml at #7 56deea7; job storefront-header success (ci-table.md))
  - [MISSING] The whole project is analysed in CI with no new finding (Analyze lists files; the 255 findings are not gated)
  - [MISSING] The full flutter test suite runs in CI and is green (Not in any job; two known failures in dynamic_theme_render_matrix_test)
  - [MISSING] The complete suite also passes with GCC_STOREFRONTS_ENABLED=true (Five flag-off assumptions fail (PR 6 and 7 bodies: flag on +507 ~8 -7))
  - [MISSING] Every app pull request gets a CI run (#4 08b9b9d: no-runs in ci-table.md)
  - [MISSING] Cart, checkout, chooser, RTL and payment return are run on a device or emulator against the backend integration tree (None run; fixtures hand-written (PR 7 body))
- **(10) Next coding task.** Extend ci.yml: whole-project flutter analyze with the 255-finding baseline as a ratchet, a full flutter test job, the five flag-off assumption tests made flag-agnostic, the two render-matrix failures fixed or quarantined with a reason; rebase #4 on the workflow so it gets a run.
- **Verifier note (not applied automatically).** plan_lines: Lines 700-716 are the report-quality rules (section 9), not the test plan. The plan's regression and test list is Report 10, lines 655-672 (line 668 'themes and the Flutter cache'); of section 9 only line 710 (CI success does not prove a real merchant integration) bears on this requirement. Proposed: 655-672, 710

#### J-V1. Draft theme preview in the customer app: the app renders an unpublished draft of a storefront's home...

- **State: NOT STARTED** | plan lines 320 (section 5.7: preview among the capabilities to preserve); 313-319 | added by the independent verifier: the assessor of this unit omitted it | reviews: none
- **(2) Functional requirement.** Draft theme preview in the customer app: the app renders an unpublished draft of a storefront's home from a signed preview token (backend GET /api/v1/theme/home with the preview parameter), per storefront, never cached and never sent with an ETag, so a merchant can check a draft on a real phone before publishing.
- **(3) Exists now in code (main).** ThemeSectionController::home in PR 83 8ff34109 accepts a signed preview query token and answers with Cache-Control no-store; lavkooapp main and all seven heads have no preview parameter or token code in lib/features/dynamic_theme or DynamicThemeRepository (git grep -i preview finds only image assets and unrelated chat and health widgets). J-06 remaining (2) mentions the gap but no requirement or criterion tracks it.
- **(4) Done in the draft PRs.** See the evidence note above.
- **(5) What remains.** Its acceptance criteria are not written yet; until they are, it counts as one criterion.
- **(6) PRs and commits.** none
- **(7) Tests.** none
- **(8) Dependencies and blockers.** See the evidence note.
- **(9) Acceptance criteria.**
  - [MISSING] Draft theme preview in the customer app: the app renders an unpublished draft of a storefront's home from a signed preview token (backend GET /api/v1/theme/home (one placeholder criterion for a requirement found by the verifier; write the real ones before relying on this line)
- **(10) Next coding task.** Write its acceptance criteria and its next coding task (added by the verifier).

#### J-V2. Loyalty points in the customer app (points ledger, earn rules, point-to-wallet conversion) belong to...

- **State: NOT STARTED** | plan lines 351 and 359-370 (section 5.10, GCC-15 country-local loyalty, next to the wallet bullet) | added by the independent verifier: the assessor of this unit omitted it | reviews: none
- **(2) Functional requirement.** Loyalty points in the customer app (points ledger, earn rules, point-to-wallet conversion) belong to the current storefront: shown, earned and converted only there, with no automatic transfer between storefronts.
- **(3) Exists now in code (main).** assess-J cross_axis_notes say the app side of wallet and points is 'J-09 criterion 6', but that criterion names only the wallet balance, ledger and top-up, so no J requirement covers the points. storefront_cache_inventory_test at 56deea7 classifies lib/features/loyaltyPoint/controllers/loyalty_point_controller.dart as 'the customer's own points ledger, loaded when its screen opens' (not storefront data); no draft or test scopes points by storefront. Outside the unit's listed plan lines, but the result claims it covered.
- **(4) Done in the draft PRs.** See the evidence note above.
- **(5) What remains.** Its acceptance criteria are not written yet; until they are, it counts as one criterion.
- **(6) PRs and commits.** none
- **(7) Tests.** none
- **(8) Dependencies and blockers.** See the evidence note.
- **(9) Acceptance criteria.**
  - [MISSING] Loyalty points in the customer app (points ledger, earn rules, point-to-wallet conversion) belong to the current storefront: shown, earned and converted only th (one placeholder criterion for a requirement found by the verifier; write the real ones before relying on this line)
- **(10) Next coding task.** Write its acceptance criteria and its next coding task (added by the verifier).
