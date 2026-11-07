# Test report — unified Saudi address

What was run, on what, with what result, and what was **not** run. Numbers are from the branch head named at the bottom.

## Summary

| Run | Command (abridged) | Result |
|---|---|---|
| Whole suite, SQLite (in-memory) | `vendor/bin/phpunit` | **2,557 tests, 13,025 assertions, 0 failures** (41 skipped: tests that need a real MySQL/MariaDB or a provider and say so; none is an address test that was skipped for a missing key) |
| Address work only, SQLite | `vendor/bin/phpunit tests/Feature/Address` | **770 tests, 3,942 assertions, 0 failures** (46 test files) |
| CI "MySQL" job set on **MySQL 8.0.46** | `phpunit -c phpunit.mysql.xml tests/Unit/Fulfillment tests/Feature/Fulfillment tests/Feature/Address tests/Feature/Security tests/Feature/CiDatabaseTest.php` | see *Engines* below |
| Same set on **MariaDB 11.4.2** | same | see *Engines* below |
| Real Chromium (Playwright) on the rendered pages | scripts kept outside the repository (it has no JS test runner) | storefront checkout 42 checks, account pages 23, admin/vendor order modals and POS forms 64, the shared picker 37 + 10 regression checks, the old-address review page 36, the Torod matching picker 14: **all pass**; the 10 regression checks fail on the previous picker script (they bite) |
| Leak guard | `php scripts/ci/leak-guard.php --base=665c231` | nothing that looks like a secret or personal data was added |
| Overlay rehearsal | `php scripts/deploy/build-overlay.php --base=665c231 --head=HEAD --out=… --archive` | builds: 141 files (58 modified, 83 added) under `app/ config/ database/ resources/ routes/`; nothing under `public/` or `composer.*`; no deletion |
| Route table | boot in the test environment | 25 new named routes, no duplicate or closure route among them (28 pre-existing duplicate names belong to older code) |

No test sends a request to any provider: every suite that could reach one uses `Http::preventStrayRequests()` or `Http::fake()`. No migration was run anywhere except the throw-away test databases.

## The owner's 16 required test areas

| # | Area | Where it is covered (examples; each file has more) |
|---|---|---|
| 1 | Region and city selection | `AddressCatalogEndpointsTest`, `AddressDataLayerTest` (search ranking, Arabic/English, inactive), `StorefrontCheckoutTest::test_soft_a_saudi_address_is_saved_with_its_region_city_and_national_address`, `ApiAddressCrudTest`; Chromium: region search, city filtered by region, cleared on region change |
| 2 | A city that does not belong to the region is refused | `StorefrontCheckoutTest::test_soft_a_city_that_is_not_in_the_chosen_region_is_refused`, `ApiAddressCrudTest::test_a_wrong_pair_of_region_and_city_is_refused_with_the_field_name`, `AddressServicesTest`, order edit tests |
| 3 | A random free-text city is refused in the new Saudi checkout | `StorefrontCheckoutTest::test_soft_a_free_text_city_is_not_accepted_for_a_saudi_address`, `ApiAddressCrudTest` (old payload in `strict`), `PanelOrderAddressEditTest` |
| 4 | National Address saved (and validated) | `AddressServicesTest` (shape, digits, case, separators, bidi marks), `StorefrontCheckoutTest::test_soft_a_malformed_national_address_is_refused`, `OrderSnapshotTest` |
| 5 | An order keeps a correct snapshot | `OrderSnapshotTest` (24), `ApiOrderPlacementTest`, `OrderPaymentCallbackTest`, `StorefrontOrderPagesTest`; immutability: `test_a_past_order_does_not_change_when_the_address_the_city_or_the_region_does` |
| 6 | A customer edits an address | `StorefrontAccountAddressTest`, `StorefrontCheckoutTest` (update flags, IDOR), `ApiAddressCrudTest` (partial update, ZIP kept when omitted) |
| 7 | An administrator edits an order's address | `PanelOrderAddressEditTest` (admin), `PanelVendorOrderAddressEditTest`, `ApiSellerAddressTest`, `OrderAddressEditGuardTest` (not once a provider holds the shipment) |
| 8 | Guest checkout | `StorefrontCheckoutTest` (guest tests, guest cannot reuse a customer's address), `StorefrontAddressServiceTest::test_a_guest_is_checked_against_the_guest_session…`, `ApiOrderPlacementTest` |
| 9 | Old customer addresses | `AdminLegacyReviewTest` (20), `AdminLegacyBackfillTest` (19), `StorefrontCheckoutTest::test_soft_an_old_saved_address_must_be_completed_before_the_checkout_continues`, `AddressDataLayerTest` (matcher on the real data) |
| 10 | REST API compatibility | `ApiAddressCrudTest`, `ApiConfigTest`, `ApiUnmigratedStoreTest`, `ApiOrderPlacementTest`, `ApiPaymentAddressTest`, `ApiSellerAddressTest`; old payloads in `off` and `soft` |
| 11 | Old ZIP-based delivery restrictions | `StorefrontCheckoutTest::test_the_zip_allow_list_still_judges_the_posted_zip`, `…zip_restriction…pauses…`, `ApiAddressCrudTest`, `AdminDeliveryRestrictionTest`, `AdminAddressModeTest` |
| 12 | Riyadh matching in the internal city list | `AddressServicesTest::test_old_free_text_cities_are_matched_only_when_certain`, `AddressDataLayerTest::test_curated_aliases_settle_the_common_spellings_against_the_shipped_data` (spelling variants such as رياض / الرياض / Riyadh / City of Riyadh), the Torod candidate tests |
| 13 | A Torod id is never chosen when there is more than one candidate | `TorodCityMappingServiceTest::test_riyadh_has_two_candidates_with_what_tells_them_apart_and_none_is_chosen`, `TorodCityResolutionTest::test_the_duplicate_riyadh_is_ambiguous_and_names_both_candidates`, `TorodMatchingScreenTest` (both Riyadh records side by side), the database allows one approved mapping per provider and city |
| 14 | No shipment is sent when the city does not match | `TorodCityResolutionTest::test_the_duplicate_riyadh_holds_the_order_with_both_candidates_and_nothing_is_sent` and the five unsettled-city cases (unmapped, pending, ambiguous, provider city missing, submit refused) — no HTTP request in any of them |
| 15 | Dry run only, no real shipment | `TorodCityResolutionTest::test_preflight_and_a_dry_run_make_no_request_at_all_not_even_to_the_city_list`, `…approving_one_candidate_lets_the_sweep_pick_the_held_order_up_and_a_rehearsal_sends_nothing`, and the existing `ExternalWriteGuardTest`, `DispatcherTest`, `TorodAdapterTest` (all still pass) |
| 16 | Product stock and shipping calculations are not changed | `TorodCityResolutionTest` (stock and shipping rows untouched), `OrderScenarioMatrixTest`, `StatusAndCheckoutGuardsTest`, the whole existing Fulfillment and order suites (pass), no change to inventory, pricing or shipping-rate code (see the diff: no file of those areas is touched) |

Arabic, English and spelling variants are exercised in `AddressDataLayerTest` (key folding: alef/hamza/ya/ta marbuta/ha, the article, diacritics, `Al-Khobar`/`Khobar`), `AddressServicesTest`, `AdminLegacyReviewTest` (real typed texts such as رياض, جده, الهفوف) and `TorodCityMappingServiceTest` (exact-key candidates only).

## Engines

The same CI test set was run against real engines (the address tests build their tables with the real migrations):

| Engine | Result |
|---|---|
| MySQL 8.0.46 (strict) | run in progress when this was written; the CI "MySQL" job runs the same set |
| MariaDB 11.4.2 (strict) | run in progress when this was written |
| MariaDB 10.11 (non-strict `sql_mode`, like production) | not run yet |

## What was not run

* **No production or staging system was touched.** Nothing was deployed, no migration was run on a real store database, and no provider (Torod, M5AZN) was contacted.
* **The real store schema.** The core tables come from a proprietary dump that is not in the repository: the tests rebuild them from inferred columns (`BuildsFulfillmentSchema`, `Builds*AddressSchema`). The compatibility of the four migrations with the live `shipping_addresses`, `orders` and `provider_cities` tables is covered by their `hasTable`/`hasColumn` guards and by the read-only `address:status`, `fulfillment:preflight` and `fulfillment:compatibility` commands the runbook has you run before and after; it is not proven here.
* **Torod's real responses** (paging, `returnAll`, ids v2 versus v5). The sync is tested against scripted fixtures of every behaviour we could think of (10 cities with a larger total, ignored `pageSize`, repeated page, zero-based paging, mid-run failure, empty list, ceilings, time budget); which one is true is a question for LogesTechs.
* **Real devices.** No physical phone, no real mobile app build, no Safari/Firefox; Chromium only (desktop 1100–1280 px and 375–390 px, LTR and RTL).
* **The auction claim checkout and external order import** (not in this repository).
* **National Address existence checks** (SPL's lookup API needs a key; only the published shape is enforced).
* **Load and performance** at production volumes (the old-address review reads typed cities once per distinct text and the page caps its live reading at 2,000 distinct texts).

## Mutation checks

Where a rule protects data or a shipment, the corresponding tests were run against a deliberately broken copy of the code to prove they fail: the Torod resolution and sync rules (19 mutations: shared-city claim, already-mapped check, silent replace, region contradiction, sync verdict, person decisions, repeated page counted as full, partial marking cities missing, total not reached, state used as a second city guess, stale mapping, ambiguous collapse, address text in a hold detail, district dropped, National Address sent, scrub gap), the panel edit (structured input dropped, ZIP always written, guest filter removed) and the shared picker (10 regression checks that fail on the previous script).
