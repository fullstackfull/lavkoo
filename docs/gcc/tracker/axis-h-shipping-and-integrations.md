# Axis H: Shipping and integrations

Detail cards of the master tracker ([`MASTER_IMPLEMENTATION_TRACKER.md`](../MASTER_IMPLEMENTATION_TRACKER.md)), as of 2026-11-18. Requirements: 34. Acceptance criteria: 152 (MET 79, CODED 10, MISSING 63). Code completion 59%, proven by a passing test 52%, release readiness 16%.

Independent verification: done for unit(s) H1, H2.

Every card gives the ten fields of the directive: (1) id and name, (2) functional requirement, (3) what exists in main, (4) what the draft pull requests did, (5) what remains, (6) pull requests and commits, (7) tests that exist and where they ran, (8) dependencies and blockers, (9) acceptance criteria marked MET (a named passing test or CI job on a named head), CODED (code exists, no passing test cited) or MISSING, (10) the next coding task.

#### H1-01. Cross-border selling is allowed per storefront by setting, default off

- **State: IN PROGRESS** | plan lines 26, 262 | GCC-16; GCC-19; Discovery owner question Q-13 | reviews: owner-decision, legal
- **(2) Functional requirement.** A storefront may take an order for a destination country other than its own only when an explicit setting allows that source-to-destination pair. The setting ships off, so each store sells inside its own country until the owner opens a pair.
- **(3) Exists now in code (main).** main has no per-storefront rule. The only destination rule is global: business setting delivery_country_restriction plus table delivery_country_codes, judged at web checkout (SystemController), REST address add and update, and REST order routes. When that setting is off any country text passes (live value UNVERIFIED). main has no config/gcc.php; in the PR 83 tree it holds no shipping key.
- **(4) Done in the draft PRs.** Nothing for destinations. The storefront chain (#8, #19, #28, #37, #42, #47 to #50, #53) fixes the source side only: country-owned products, a cart that holds one storefront's rows, a legal-readiness sale gate and payment completion in the starting storefront. It adds no destination rule and no cross-border key.
- **(5) What remains.** (1) a per-storefront switch and allowed-destination list in config/gcc.php, default off, with a kill switch; (2) one policy service read by web, REST and dispatch; (3) an admin screen with audit and staff country scope; (4) one test per pair and per refusal; (5) the owner's choice of launch pairs.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` StorefrontCheckoutTest::test_the_country_allow_list_still_judges_the_posted_country and ApiAddressCrudTest::test_the_country_restriction_still_applies_to_a_structured_address (the global list only) (PR 83 8ff3410 and PR 75 47b78ab: Address tests MySQL 8 and MariaDB 11.4.2, Tests SQLite (PHP 8.2, 8.3))
- **(8) Dependencies and blockers.** Needs the storefront context and sale gate (#8 to #53, drafts, flag off) and the engine of H1-06 to enforce it. A2-12 (saved-address eligibility) needs this same destination list: build it once here.
- **(9) Acceptance criteria.**
  - [MISSING] With the switch off, an order whose destination country differs from the storefront's country is refused at web and REST checkout (no pair rule in any tree; with delivery_country_restriction off any country text passes)
  - [MISSING] With one pair enabled, that pair is accepted and every other destination is still refused (no per-storefront destination list)
  - [MET] The global delivery-country list still refuses a country that is not on it, at web checkout and at address creation (StorefrontCheckoutTest::test_the_country_allow_list_still_judges_the_posted_country; ApiAddressCrudTest::test_the_country_restriction_still_applies_to_a_structured_address; PR 83 8ff3410 and PR 75 47b78ab, Address tests MySQL 8 and MariaDB 11.4.2)
  - [MISSING] Changing a pair is audited and allowed only to staff of the source country (no setting, no screen)
- **(10) Next coding task.** New branch stacked on #53: config gcc.shipping.cross_border (env GCC_CROSS_BORDER_SHIPPING, default false) with allowed destinations per source country, and App\Services\Shipping\CrossBorderPolicy::allows(source, destination). Add tests/Unit/Storefront/CrossBorderPolicyTest: off, own country, enabled pair, other pair refused, unknown code, letter case. No checkout wiring yet (H1-06).
- **Open owner decisions.** Which source-to-destination pairs may open first, who may switch a pair on, and whether any pair needs a legal check first (Discovery Q-13).

#### H1-02. A carrier declares the routes it serves; checkout and dispatch honour them

- **State: NOT STARTED** | plan lines 26, 266 | GCC-16; DECISIONS (support future country-specific providers); Discovery E11-F01, E11-F04, E11-F11; owner question Q-33 | reviews: owner-decision
- **(2) Functional requirement.** Each carrier or fulfillment provider declares the source-to-destination country routes it serves and whether cash on delivery works on them. Checkout refuses a route no provider of the cart's items serves, and dispatch holds an order whose destination lies outside its provider's routes.
- **(3) Exists now in code (main).** Preflight checks product mapping and approval, known stock, address text, Saudi phone, the Torod city mapping and the cash-on-delivery setting, so nothing compares the order's destination with the provider's country.
- **(4) Done in the draft PRs.** No route data in any draft. #72 and #76 only record in ProductCodeScope that provider connections have no country of their own and all serve the default storefront (Torod suggestions, its picker and the M5AZN import are narrowed to it, switch off by default).
- **(5) What remains.** (1) a route declaration (config or table: provider, source, destination, services, cash on delivery); (2) a RouteSupport service read by cart assignment, checkout and preflight; (3) a new hold reason for an unsupported route; (4) write and filter provider_city_mappings.country_code; (5) each carrier's real coverage in writing.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `none` no test for provider route support (ProviderMatchingCountryTest only pins that matching follows the default storefront) (-)
- **(8) Dependencies and blockers.** Real carrier coverage per country, contracts and company ids are vendor facts in no repository (Q-33). Needs H1-10 (country on mappings) and H1-11 (the provider contract carries coverage).
- **(9) Acceptance criteria.**
  - [MISSING] A provider can declare the source and destination countries it serves, and an undeclared route is unsupported (ProviderCode is a closed enum; config/fulfillment.php carries only default_country SA)
  - [MISSING] Checkout refuses, before payment, a destination that no provider of the cart's items serves (no check)
  - [MISSING] Dispatch preflight holds an order whose destination is outside the provider's routes with a named reason and sends nothing (HoldReason has no route reason; today only phone and city holds)
  - [MISSING] A mapping row carries the country of its city and candidates are filtered by it (provider_city_mappings.country_code is never written)
- **(10) Next coding task.** Branch from main (the adapters are touched by neither integration tree): config fulfillment.providers.<code>.routes (Torod and M5AZN: SA to SA), App\Services\Fulfillment\ProviderRouteSupport and HoldReason::ROUTE_UNSUPPORTED checked in the dispatcher before adapter preflight. tests/Feature/Fulfillment/RouteSupportTest: a config-declared SA to AE route passes, SA to KW holds, Saudi orders unchanged.
- **Open owner decisions.** Q-33: which carrier serves which country under which contract and company id, and whether one provider code may serve two countries.
- **Verifier note (not applied automatically).** exists_now corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** ac[2].e (Dispatch preflight holds ... with a named reason): Evidence text 'today only phone and city holds' is inaccurate for the same reason (HoldReason also has mapping, stock, address and cash-on-delivery holds). Proposed: HoldReason has no route reason; today the preflight holds for mapping, known stock, address, phone, city mapping and the cash-on-delivery setting

#### H1-03. Product shipping, export and import permission; no permission means no foreign shipping

- **State: NOT STARTED** | plan lines 26, 267, 275 | GCC-16; DECISIONS; Discovery E11-F12; owner question Q-13 | reviews: legal, owner-decision
- **(2) Functional requirement.** Each product, or its category, carries explicit permission to be shipped, exported from its source country and imported into a destination. A product with no such record is not eligible for any foreign destination, because the system never assumes a product may ship abroad.
- **(3) Exists now in code (main).** Nothing at store checkout. Discovery E11-F12 found no product shipping, export or import flag in app, Modules, routes or migrations (re-searched on main: none). The nearest code is RegulatedProductGate, which governs sales-channel listing only. Products carry no weight, HS code or origin country (weight_kg exists only on provider and channel items).
- **(4) Done in the draft PRs.** Nothing. #47 and #64 add products.storefront_country (a product belongs to one country) and an admin country filter; neither records a shipping, export or import permission.
- **(5) What remains.** (1) additive schema for permissions (flags, or a rule table keyed by product or category and destination); (2) admin form, bulk default and audit; (3) the engine reads it and denies by default for foreign destinations; (4) customs data (HS code, origin, weight) for H1-09; (5) who maintains the lists.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `none` none for product shipping, export or import permission (-)
- **(8) Dependencies and blockers.** Owner decision on who maintains the lists (Q-13). Read by the engine of H1-06; shares its data with H1-04 and H1-09.
- **(9) Acceptance criteria.**
  - [MISSING] A product with no permission record is refused for a destination outside its country (no record exists)
  - [MISSING] An authorised admin sets shipping, export and import permission per product or category and destination, and the change is audited (no form, no table)
  - [MISSING] Weight and customs data (HS code, origin country) exist on the product for rates and duty (no such column on products)
- **(10) Next coding task.** Stacked on #64: additive migration for a product_destination_permissions table (product_id, destination_country, may_ship, may_export, may_import, recorded_by, recorded_at, unique on product and destination) and a Product relation; no UI. tests/Feature/Storefront/ProductDestinationPermissionTest, which runs on SQLite and in the Storefront MySQL 8 and MariaDB group that #64's workflow has (or stack on #72 and add the Catalog group to that branch's ci.yml): no row means denied, a row allows only its destination, the unique key holds.
- **Open owner decisions.** Who decides and maintains the permission lists, and whether the default is deny for every foreign destination (Discovery Q-13, option a).
- **Verifier note (not applied automatically).** next_task corrected by the verifier (applied above).

#### H1-04. Regulated health and pharmaceutical goods fail closed, whatever the courier covers

- **State: IN PROGRESS** | plan lines 268 | GCC-16; DECISIONS ('Checkout validates product eligibility independently of courier coverage'); Discovery E11-F12; owner question Q-13 | reviews: legal, owner-decision
- **(2) Functional requirement.** A medicine, supplement or medical device, or any product nobody classified, cannot cross a border unless a recorded clearance exists for its class and the destination. A carrier that covers the route never overrides this.
- **(3) Exists now in code (main).** main: RegulatedProductGate, default off, for sales-channel listings only. The owner's lists (categories, brands, name patterns, per channel) mark a product as regulated; strict mode refuses an unclassified product and requires a clearance with a reference, recorded by a person whose role holds the sales_channels module. No store checkout, REST or POS path calls it.
- **(4) Done in the draft PRs.** Nothing for storefront checkout.
- **(5) What remains.** (1) the owner's answer on which classes may cross which border and who approves the list; (2) a storefront gate reusing the fail-closed pattern (no category means refused); (3) a clearance record per class and destination with reference and person; (4) Arabic and English refusal text; (5) the same gate at REST, POS and dispatch.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` ListingPublishingTest: the channel gate refuses an unclassified product and needs a recorded clearance (channel listings only) (PR 83 8ff3410 and PR 75 47b78ab: Sales-channel and cost tests (sales channels A-M) MySQL 8.0 and MariaDB 11.4.2)
- **(8) Dependencies and blockers.** Owner answer Q-13 and a competent legal source for the class lists; needs the engine of H1-06 and the permission data of H1-03.
- **(9) Acceptance criteria.**
  - [MISSING] A regulated or unclassified product is refused for a foreign destination even when a carrier covers the route (no storefront gate)
  - [MISSING] A recorded clearance (reference and person) for the class and destination lets the product through; an admin id alone does not (clearances exist for channel listing drafts only)
  - [MET] The channel-listing gate refuses an unclassified product and needs a recorded clearance in strict mode (channel listings only, default off) (ListingPublishingTest::test_the_gate_itself_refuses_an_unclassified_product_whatever_the_rest_says; PR 83 8ff3410 and PR 75 47b78ab, Sales-channel and cost tests (sales channels A-M) MySQL 8.0 and MariaDB 11.4.2)
  - [MISSING] The refusal reason is shown in Arabic and English on web, REST and both apps (no reason key)
- **(10) Next coding task.** On the H1-03 branch: App\Services\Shipping\RegulatedShippingGate modelled on RegulatedProductGate (modes off and strict, owner lists in config gcc.shipping.regulated, no category means refused, a recorded clearance table). tests/Feature/Storefront/RegulatedShippingGateTest: unclassified refused, listed class refused, clearance allows, mode off passes. Not wired to checkout until H1-06.
- **Open owner decisions.** Q-13: which regulated classes may be shipped across each border, who approves that list (a competent authority or counsel), and that an unclassified product is refused (option a, closed by default).

#### H1-05. Destination address accepted: country-aware rules and carrier serviceability

- **State: IN PROGRESS** | plan lines 26, 269 | GCC-16; docs/address/README.md; Discovery E11-F04, E11-F11; A2-12 holds the saved-address half | reviews: legal, native-arabic
- **(2) Functional requirement.** A destination address is accepted only when its country is deliverable, it is complete under that country's address rules (region, city, district, postal code, phone format) and a carrier can serve its city. Today only Saudi addresses have structure; every other country keeps free text.
- **(3) Exists now in code (main).** main: global country and ZIP allow-lists enforced at web checkout, REST address add and update and REST orders; the central address system (unified_address_mode off, soft, strict) gives Saudi addresses a region, city, district and National Address (AddressCountry::isSaudi); other countries keep a free-text city and ZIP; the importer writes country SA only; AddressCatalog has no country filter.
- **(4) Done in the draft PRs.** Nothing for acceptance: app/Services/Address is identical to main in both integration trees. #22 touches StorefrontAddressService only to check that a phone is the order owner's.
- **(5) What remains.** (1) address rules per country (regions and cities for AE, KW, QA, BH, OM from a licensed source, postal code and phone formats); (2) a per-storefront destination list instead of one global list (H1-01); (3) serviceability: refuse at checkout a city no carrier serves, not only hold at dispatch; (4) tests per country; (5) Arabic and English messages.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` StorefrontCheckoutTest (country list, ZIP list, Saudi structured address, other countries keep free text) and ApiAddressCrudTest (country list, ZIP pause, an address abroad is never asked for ids) (PR 83 8ff3410 and PR 75 47b78ab: Address tests MySQL 8 and MariaDB 11.4.2, Tests SQLite (PHP 8.2, 8.3))
- **(8) Dependencies and blockers.** Licensed address lists for five countries (docs/address/data-sources.md settles Saudi Post only); phone and postal formats per country; per-country carrier coverage (Q-33).
- **(9) Acceptance criteria.**
  - [MET] A destination country outside the delivery-country list is refused at web checkout and at REST address creation (StorefrontCheckoutTest::test_the_country_allow_list_still_judges_the_posted_country; ApiAddressCrudTest::test_the_country_restriction_still_applies_to_a_structured_address; PR 83 8ff3410 and PR 75 47b78ab, Address tests MySQL 8 and MariaDB 11.4.2)
  - [MET] A ZIP restriction keeps judging the ZIP and pauses the structured address (ApiAddressCrudTest::test_a_zip_restriction_pauses_the_structured_address_and_keeps_judging_the_zip; same jobs)
  - [MET] With the mode soft or strict a Saudi address needs a region, a listed city and a valid National Address; a free-text city is refused (StorefrontCheckoutTest::test_soft_a_free_text_city_is_not_accepted_for_a_saudi_address; same jobs)
  - [MISSING] An address in each of the other five countries is checked against that country's regions, cities, postal code and phone format (the importer writes SA only; other countries keep free text)
  - [MISSING] An address whose city no carrier serves is refused at checkout with a translated reason (only the dispatcher holds it today (hold_city_unmapped))
- **(10) Next coding task.** On a branch from main: a country table for the six markets (code, English and Arabic names, calling code, postal pattern) beside AddressCountry (isSaudi stays), a ShippingAddressService rule that validates phone and postal code per country, and a country filter on AddressCatalog. tests/Feature/Address/SixCountryAddressTest: each country accepts a well-formed and refuses a malformed address; Saudi payloads byte for byte unchanged.
- **Open owner decisions.** The licensed source of region and city lists for AE, KW, QA, BH and OM, which postal and phone formats to enforce per country, and the unified address mode per storefront (Q-23).

#### H1-06. One shipping eligibility engine decides local or cross-border and refuses with reasons

- **State: NOT STARTED** | plan lines 26, 262-271, 275 | GCC-16; DECISIONS; Discovery E11-F12; Charter section 3 (new behaviour behind default-off flags) | reviews: security, native-arabic
- **(2) Functional requirement.** A single service answers, for a storefront, a cart and a destination, whether the order is local or cross-border and whether it may proceed, with every refusal reason. Web, REST and POS checkout ask it before payment and dispatch asks it again; its decision is stored with the order.
- **(3) Exists now in code (main).** main has patterns, not the engine: OrderEligibility (statuses and payment, re-evaluated at routing and dispatch), CheckoutCodGuard (a pre-payment guard for mixed cash on delivery) and the HoldReason constants (hold_city_unmapped, hold_phone_invalid). None looks at the destination country, a route, a product permission or a duty.
- **(4) Done in the draft PRs.** Nothing. The only gate in a draft, the legal-readiness sale gate of #42 and #49 (StorefrontSellingGate in the PR 83 tree), never looks at the destination.
- **(5) What remains.** (1) the service and a decision value object (local or cross_border, allowed, reasons); (2) wiring in web checkout, REST checkout, POS and the dispatch preflight; (3) the decision stored on the order; (4) refusal text in Arabic and English in every locale file; (5) a kill switch; (6) the customer app showing the server's reason.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `none` no test for a destination-aware eligibility decision (-)
- **(8) Dependencies and blockers.** Needs H1-01 to H1-05 as inputs, the payment-eligibility hook of D2, the storefront chain (#53) and the currency seam (GCC-05) for quotes. Web, REST and POS must share one service so the surfaces stay in step.
- **(9) Acceptance criteria.**
  - [MISSING] For a storefront, cart and destination the engine returns local or cross-border and a list of refusal reasons (no engine)
  - [MISSING] Web checkout, REST checkout and POS refuse before payment with the same reason for the same cart and address (only the global country list is checked, surface by surface)
  - [MISSING] Dispatch preflight asks the engine again and holds, never sends, an order that now fails (OrderEligibility checks status and payment only)
  - [MISSING] The decision and its reasons are stored with the order and do not change when settings change later (orders carry no country or decision)
  - [MISSING] With the switch off checkout behaves exactly as today, and every reason key exists in every locale file (no switch, no keys)
- **(10) Next coding task.** After H1-01: App\Services\Shipping\ShippingEligibility returning EligibilityDecision(local|cross_border, allowed, reasons[]), composing CrossBorderPolicy and leaving product, route, address and payment as interfaces that fail closed. Wire it into Customer\SystemController::getChooseShippingAddressOther behind GCC_CROSS_BORDER_SHIPPING. tests/Feature/Storefront/ShippingEligibilityTest: local passes, unopened pair refused, flag off unchanged.
- **Open owner decisions.** Whether staff may place a cross-border order by hand from the admin panel or POS. The pairs, regulated classes and duty rules are decided under H1-01, H1-04 and H1-09.

#### H1-07. A foreign destination never changes the stock owner, seller or selling company

- **State: IN PROGRESS** | plan lines 273 | GCC-16; GCC-13; DECISIONS ('Source storefront inventory and seller remain fixed'); Discovery E11-F10; owner questions Q-10, Q-39 | reviews: finance, legal, owner-decision
- **(2) Functional requirement.** A cross-border order is created in the source storefront. Its lines keep the source product row's stock, seller and fulfillment provider, and the order is sold by the source selling company. The destination country only affects shipping, tax and duty and is stored as a separate fact.
- **(3) Exists now in code (main).** main: a product is one row, OrderManager takes stock from that row by id and never reads a country, the line's provider is frozen when the order is placed (OrderItemOwnershipSnapshotter) and the destination sits in the order's address copy. Orders carry no storefront, country, currency or legal entity of their own: an order follows from its lines (GCC-14C doc in the PR 83 tree).
- **(4) Done in the draft PRs.** ... #48 announces the order-level stamp as the next step. In the pack it does not exist; its storefront and currency half is coded on gcc/p1-storefront-currency (PR 87, commit 8d500a66, not in PR 83 or PR 75, CI unread). The selling entity and the destination snapshot are not coded anywhere.
- **(5) What remains.** (1) stamp the source storefront and selling entity on the order at creation and keep the destination as a separate snapshot; (2) tests with a foreign address proving the stock row, seller and provider are unchanged (shared with C-16); (3) refunds and returns going back to the source entity and currency (D2); (4) the entity model decision.
- **(6) PRs and commits.** [lavkoo#47](https://github.com/fullstackfull/lavkoo/pull/47) `e0dd5dc`; [lavkoo#48](https://github.com/fullstackfull/lavkoo/pull/48) `4a821d3`; [lavkoo#53](https://github.com/fullstackfull/lavkoo/pull/53) `7e32bba`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`
- **(7) Tests.**
  - `ci` StorefrontCartGuardTest (another country's product is refused and leaves the cart empty) and StorefrontCartIsolationTest (one cart group per storefront) (PR 83 8ff3410: Storefront tests MySQL 8 and MariaDB 11.4.2, Tests SQLite (PHP 8.2, 8.3))
  - `ci` StorefrontPaymentCallbackTest: a paid Kuwait cart becomes an order although the callback names no storefront (PR 83 8ff3410: Storefront tests MySQL 8 and MariaDB 11.4.2, Tests SQLite (PHP 8.2, 8.3))
- **(8) Dependencies and blockers.** Owner decisions on the first legal-entity model (Q-10) and on the one-entity cart against seller split (Q-39). CartManager.php and both CartControllers are changed by the security set and by the chain; the combined tree (PR 75 with PR 83) does not exist.
- **(9) Acceptance criteria.**
  - [CODED] A cart holds only the products of its own storefront, so a foreign-destination order is always built from the source storefront's rows (StorefrontCartGuardTest::test_adding_another_countrys_product_to_the_cart_is_refused_and_leaves_the_cart_empty; PR 83 8ff3410, Storefront tests MySQL 8, MariaDB 11.4.2 and Tests SQLite)
  - [MET] A payment started in one storefront is finished in that storefront whatever the callback request says (StorefrontPaymentCallbackTest::test_a_paid_kuwait_cart_becomes_an_order_although_the_callback_names_no_storefront; PR 83 8ff3410, same jobs)
  - [CODED] A foreign destination address never changes the product row whose stock is taken, the seller of the line or the provider snapshot (OrderManager never reads a country and decrements the product row; no test uses a foreign address (C-16 to pin it))
  - [MISSING] The order records its source storefront and selling entity separately from the destination country (orders carry no country or entity; #48 defers the stamp)
- **(10) Next coding task.** Stack on PR 87 (gcc/p1-storefront-currency) and do not add a second orders.storefront_country. Add only the destination (orders.destination_country, or read it from the address snapshot) and the selling entity, behind the storefront flag, plus tests/Feature/Storefront/CrossBorderOwnershipTest on SQLite and the Storefront MySQL group: a Saudi-storefront order to a Kuwait address keeps the Saudi product row, stock, seller and provider snapshot. Share fixtures with C-16.
- **Open owner decisions.** Q-10 first legal-entity model; Q-39 one-entity cart against seller split; which entity invoices a cross-border sale (the source entity, by GCC-16).
- **Verifier note (not applied automatically).** next_task corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** done_in_drafts corrected by the verifier (applied above).

#### H1-08. Shipping cost per destination and route, in the store's currency

- **State: IN PROGRESS** | plan lines 26, 271 | GCC-16; GCC-05 (open); Discovery E11-F03, E11-F12; owner questions Q-07, Q-08 | reviews: finance
- **(2) Functional requirement.** The shipping charge for a local or cross-border order follows the source storefront, the destination (country, region, city), weight and value, is in the storefront's currency without automatic conversion, is shown before payment and is stored on the order.
- **(3) Exists now in code (main).** main: three modes (order-wise ShippingMethod cost, category-wise, product-wise) in CartManager; ShippingZone and ShippingRateService (country, region, base cost, per-kg, free-over) behind zone_wise_shipping, default off, consulted by web checkout only, weight fixed at 0 because products have no weight; REST keeps the method cost. ShippingMethod has no country or currency. No test mentions zone shipping in any tree. A merchant-side carrier cost estimate by destination zone exists (OperationalCosts).
- **(4) Done in the draft PRs.** #48 limits the shipping choice to the caller's own cart group in the storefront and the zone sum to its rows. No draft adds a rate, a currency or a cross-border route.
- **(5) What remains.** (1) characterization tests for zone shipping; (2) the REST shipping step using the same destination rate; (3) per-storefront methods and zones in local currency, including 3-decimal KWD, BHD and OMR (needs the GCC-05 seam); (4) a cross-border rate by route and weight; (5) product weight; (6) the quote shown before payment and snapshotted with its currency.
- **(6) PRs and commits.** [lavkoo#48](https://github.com/fullstackfull/lavkoo/pull/48) `4a821d3`
- **(7) Tests.**
  - `ci` ShipmentCostServiceTest::test_a_city_outside_every_zone_is_a_missing_cost (carrier cost to the merchant) (PR 83 8ff3410 and PR 75 47b78ab: Sales-channel and cost tests (costs) MySQL 8.0 and MariaDB 11.4.2)
- **(8) Dependencies and blockers.** GCC-05 storefront currency seam (PR 87, gcc/p1-storefront-currency, slices 1-4 pushed, CI unread; shipping costs and free-delivery thresholds are explicitly left to later slices)
- **(9) Acceptance criteria.**
  - [CODED] With zone_wise_shipping on, web checkout charges the destination zone's rate (country, region, free-over, base plus per kg) and falls back to the method cost when no zone matches (ShippingRateService and SystemController::zoneShippingCost; no test mentions ShippingZone in any tree)
  - [CODED] With the setting off, checkout charges the chosen method's cost unchanged (SystemController::zoneShippingCost returns the method cost; no test)
  - [MISSING] The REST shipping step uses the same destination rate as the website (the method docblock says only web checkout consults zones)
  - [MISSING] A cross-border route has its own rate by source, destination, weight and value in the storefront's currency, with three-decimal currencies (ShippingZone has no source, currency or route; amounts are two-decimal)
  - [MISSING] The quote is shown before payment and stored on the order with its currency and rate source (orders store shipping_cost only)
  - [MET] The carrier's cost to the merchant is estimated from the contract by destination country and city zone, and a city outside every zone is a missing cost, not free (ShipmentCostServiceTest::test_a_city_outside_every_zone_is_a_missing_cost; PR 83 8ff3410 and PR 75 47b78ab, Sales-channel and cost tests (costs) MySQL 8.0 and MariaDB 11.4.2)
- **(10) Next coding task.** Test-only branch from main: tests/Feature/Shipping/ShippingZoneRateTest pinning ShippingRateService today (a specific zone beats the catch-all, region list, free-over, per-kg at weight 0, no zone returns the fallback) and SystemController::zoneShippingCost with the setting on and off. Then design the REST parity on the same branch.
- **Open owner decisions.** Q-07 decimals and rounding per currency; Q-08 conversion policy for new stores (no automatic FX is decided); who sets cross-border shipping rates.
- **Verifier note (not applied automatically).** deps_blockers corrected by the verifier (applied above).
- **Update after the assessment.** PR 87 (GCC-05 slice 5): the minimum order and the free-delivery threshold of a storefront with its own currency are its own (a business setting storefront_money_<country>, written only by gcc:storefront:money, neutral 0 when nothing is given, never the platform's riyal figure); StorefrontAmountSettingsTest (35) and StorefrontMoneyCommandTest (31); the shipping cost itself is product-wise only there (see H1-14). Local runs on SQLite and MySQL 8.0.46 strict, CI of the head not read.

#### H1-09. Tax and duties on a cross-border order

- **State: NOT STARTED** | plan lines 26, 271, 507 | GCC-16; DECISIONS (taxes not inherited across companies); Discovery E09-F06; owner question Q-11 | reviews: finance, legal, owner-decision
- **(2) Functional requirement.** For a cross-border order the system decides which tax regime applies (the selling entity's VAT or the destination's), computes or declares customs duty and who pays it, shows both before payment and stores them on the order and invoice. If tax or duty cannot be determined the order is refused, not charged zero.
- **(3) Exists now in code (main).** main: TaxModule with a nullable country_code on its setup and rates, but config taxmodule.country_type is the constant 'single' and getTaxSystemType reads only the default setup; CalculateTaxService accepts a countryCode that only the REST tax calculation endpoint passes; per-order tax rows exist (order_taxes). No duty, customs, HS code, origin or de minimis concept anywhere. No test pins checkout tax or any country selection.
- **(4) Done in the draft PRs.** Nothing in tax logic. #65 puts the tax settings and reports behind the menu permission and #82 narrows the tax reports to a restricted employee's countries; both are security or scope work, not rates.
- **(5) What remains.** (1) the owner decision on tax and invoice per entity (Q-11) and a tax adviser per market; (2) a resolver from (selling entity, destination) to a regime; (3) duty rules and who pays; (4) customs data on the product (H1-03); (5) tax and duty lines on the order and invoice; (6) tests per route.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `none` none for checkout tax, country selection or duty (-)
- **(8) Dependencies and blockers.** Q-11 and an external tax adviser per market; the entity model (Q-10); product customs data (H1-03); the destination known at quote time (H1-05, H1-06).
- **(9) Acceptance criteria.**
  - [MISSING] Checkout tax follows the selling entity's setup chosen by country, not one global default (country_type is fixed to single; getTaxSystemType reads the default setup only)
  - [MISSING] A cross-border order shows which tax applies and the duty, or that the buyer pays it on delivery, before payment (no duty concept)
  - [MISSING] Tax and duty lines (rate, basis, payer) are stored on the order and printed on the invoice (order_taxes stores tax lines only; no duty line)
  - [MISSING] A route whose tax or duty cannot be determined refuses the order and never charges zero (no engine)
- **(10) Next coding task.** Test-only on main first: tests/Unit/TaxModule/CalculateTaxServiceCountryTest pinning that countryCode selects a SystemTaxSetup and that getTaxSystemType ignores it. Then, stacked on the H1-06 branch, a CrossBorderTaxQuote value object whose default is 'not quoted', which the engine treats as refused (fail closed).
- **Open owner decisions.** Q-11: tax and invoice per entity and who approves rates; whether the seller or the buyer pays duty per route; which entity invoices a cross-border sale.

#### H1-10. Address-to-carrier city mapping that works in every country

- **State: IN PROGRESS** | plan lines 254, 269 | GCC-16; docs/address/README.md and torod-city-mapping.md; Discovery E11-F11 | reviews: owner-decision
- **(2) Functional requirement.** Our regions and cities, in any country, map to a carrier's own city ids only through approved mappings keyed by carrier and country. A missing, pending or ambiguous mapping holds the shipment with a reviewable reason and is never sent to a nearest city.
- **(3) Exists now in code (main).** main: address_regions (country_code default SA), address_cities, a carrier-neutral provider_city_mappings (one approved mapping per carrier and city), ProviderCityMappingService, TorodCityResolver (exact or hold), the Torod city import and an admin matching screen. country_code on mappings is never written, candidates are not filtered by country, the city alias is unique across all countries, and only Saudi regions and cities are imported.
- **(4) Done in the draft PRs.** Nothing in the mapping or address code: both integration trees leave app/Services/Address and ProviderCityMappingService identical to main. #72 and #76 narrow Torod product matching to the default storefront, not city mapping.
- **(5) What remains.** (1) write and filter provider_city_mappings.country_code from the address region; (2) an alias unique per country; (3) regions and cities for AE, KW, QA, BH, OM from a licensed source; (4) a mapping flow for each new carrier (M5AZN sends an Arabic city name); (5) matching screens per country; (6) tests with same-named cities in two countries.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` TorodCityResolutionTest (no mapping, pending, ambiguous or dropped city holds and no request is made) and TorodCityMappingServiceTest (one approved mapping per carrier and city; an id of one provider is never taken for another's) (PR 83 8ff3410 and PR 75 47b78ab: Address tests MySQL 8 and MariaDB 11.4.2, Tests SQLite (PHP 8.2, 8.3))
- **(8) Dependencies and blockers.** City lists and licences for five countries; carrier coverage (Q-33); the address mode decision (Q-23).
- **(9) Acceptance criteria.**
  - [MET] An order's city reaches Torod only through an approved mapping; an unmapped, pending or ambiguous city holds the shipment and no request is sent (TorodCityResolutionTest::test_no_order_request_is_ever_made_while_the_city_is_not_settled; PR 83 8ff3410 and PR 75 47b78ab, Address tests MySQL 8 and MariaDB 11.4.2)
  - [MET] The database allows a single approved mapping per carrier and city, and an id of one provider is never taken for another's (TorodCityMappingServiceTest::test_the_database_itself_allows_one_approved_mapping_per_provider_and_city; same jobs)
  - [MISSING] A mapping and its candidates carry the country of the city and are filtered by it (country_code is never written)
  - [MISSING] Two same-named cities in different countries can both be aliased and mapped without a collision (address_city_aliases.normalized_alias is unique across all countries)
  - [MISSING] Regions and cities of the other five countries exist in the address master (AddressImporter writes country SA only)
- **(10) Next coding task.** On a branch from main: ProviderCityMappingService writes the country of the address region on every proposal and approval and filters candidates by it; an additive migration makes the alias unique per country; tests/Feature/Address/MappingCountryTest with a Riyadh in SA and a same-named test city in AE (test-only rows). Saudi mappings and payloads stay byte for byte the same.
- **Open owner decisions.** The licensed source for the five countries' lists and who approves city mappings per country; whether a Saudi mapping may be reused on a cross-border route.

#### H1-11. Carrier adapters open to providers in other countries

- **State: IN PROGRESS** | plan lines 249-250, 254 | DECISIONS (support future country-specific providers); GCC-16; Discovery E11-F01, E11-F02, E11-F04, E11-F13; owner questions Q-24, Q-33 | reviews: security, owner-decision
- **(2) Functional requirement.** A provider for the UAE, Kuwait, Bahrain, Qatar or Oman is added as one adapter class plus one configuration entry, with its own credentials, served countries, phone and currency rules. Routing, dispatch, retries, webhooks and the order summary need no change.
- **(3) Exists now in code (main).** main: a provider-neutral ProviderAdapter contract (readiness, preflight, submit, cancel, status), ProviderRegistry resolving the class from config, preflight holds with named reasons, a dry run that sends nothing. But ProviderCode is a closed two-case enum, SaudiPhone accepts Saudi mobiles only, the Torod payload uses 00966, M5AZN's country comes from one setting, fulfillment currency is SAR, and a legacy Delivery Syria hook sits in OrderManager.
- **(4) Done in the draft PRs.** Nothing changes the contract, the enum, the phone rules or the config. #72 and #76 only narrow matching and import to the default storefront.
- **(5) What remains.** (1) a third provider registered by class and config only, proved by a fake adapter; (2) phone, country and currency from the destination and the provider account; (3) one provider code running for two countries with separate credentials; (4) keep Delivery Syria out of GCC storefronts; (5) real providers per market (Q-33).
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` DispatcherTest: a dry run validates without sending; an unready provider holds with what is missing; the global kill switch stops everything (PR 83 8ff3410 and PR 75 47b78ab: Fulfillment + security tests MySQL 8 and MariaDB 11.4.2, Tests SQLite (PHP 8.2, 8.3))
- **(8) Dependencies and blockers.** Q-33 (real providers, contracts and company ids per market) and the provider-account model shared with C-12; GCC-05 for the provider currency. Do the fake third adapter once, together with C-12.
- **(9) Acceptance criteria.**
  - [MISSING] A third provider registers with one adapter class and one config entry, and the router, dispatcher, webhook and order summary handle it unchanged (ProviderCode is a closed enum; no test registers a third adapter)
  - [MET] Every adapter sits behind one contract; a failed preflight holds with a named reason and a dry run sends nothing (DispatcherTest::test_a_dry_run_validates_without_sending_and_never_claims_submission, ::test_an_unready_provider_holds_with_what_is_missing; PR 83 8ff3410 and PR 75 47b78ab, Fulfillment + security jobs)
  - [MISSING] The phone, country and currency sent to a carrier follow the destination address and the provider account, not Saudi constants (SaudiPhone, the 00966 prefix, M5AZN country setting SA, fulfillment currency SAR)
  - [MISSING] The same provider code can run for two countries with separate credentials and city lists (one integration_settings row per code; provider_cities unique by provider and external id)
  - [MISSING] A GCC storefront never reaches the legacy Delivery Syria hook in checkout (the hook keys on a shipping method named DeliverySyria; no per-storefront methods (H1-14))
- **(10) Next coding task.** Together with C-12, from main: tests/Feature/Fulfillment/ThirdProviderTest with a test-only FakeAeAdapter registered through config: route, preflight, dry-run dispatch and order summary. It will show the closed ProviderCode enum is the blocker; then replace the enum by a registry of codes or add a case per provider.
- **Open owner decisions.** Q-33: which provider serves which country under which contract; Q-24: whether Delivery Syria stays disabled in Gulf storefronts (proposed: yes).

#### H1-12. Torod Point and M5AZN integrations kept working as carriers

- **State: MERGED** | plan lines 254 | DECISIONS core foundation and guardrail 1; docs/integrations; C-18 and C-10 judge the same code from the stock side | reviews: none
- **(2) Functional requirement.** Torod Point (warehouse order, city mapping, webhooks) and M5AZN (order, status polling) keep working as built for Saudi orders, with every provider call faked in tests, an order sent at most once and nothing leaving the host while the two environment switches are off.
- **(3) Exists now in code (main).** main: TorodPointAdapter with its fulfillment and lastmile clients, city resolver, status map and webhook processing; M5aznAdapter, client and payload builder; FulfillmentRouter, the dispatcher and reconciliation; docs/integrations. Their suites (tests/Feature/Fulfillment, tests/Feature/Address) are in main and ran green on both integration trees.
- **(4) Done in the draft PRs.** Nothing changes the adapters. #1 repairs, test-only, the one clock-dependent Torod shipment-discovery test that is red on main (sales channels, not the adapters); #72 and #76 narrow M5AZN import and Torod suggestions to the default storefront with the switch off. The security set changes SaudiPhone only for identity matching.
- **(5) What remains.** Merge the one-line Torod fixture repair of #1 (it also edits CLAUDE.md, an owner choice) so main's own CI is green; a byte-for-byte payload test with storefronts on and off; live verification against the real providers is an owner-run step no repository shows (UNVERIFIED).
- **(6) PRs and commits.** [lavkoo#1](https://github.com/fullstackfull/lavkoo/pull/1) `d664ab4`; [lavkoo#72](https://github.com/fullstackfull/lavkoo/pull/72) `1c779d2`; [lavkoo#76](https://github.com/fullstackfull/lavkoo/pull/76) `0ad49d2`; [lavkoo#75](https://github.com/fullstackfull/lavkoo/pull/75) `47b78ab`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`
- **(7) Tests.**
  - `ci` TorodAdapterTest, TorodWebhookTest, M5aznAdapterTest, DispatcherTest, RouterTest (provider calls faked) (PR 83 8ff3410 and PR 75 47b78ab: Fulfillment + security tests MySQL 8 and MariaDB 11.4.2, Tests SQLite, Strict-mode tests MariaDB 11.4.2)
  - `ci` TorodCityResolutionTest and TorodCityMappingServiceTest (PR 83 8ff3410 and PR 75 47b78ab: Address tests MySQL 8 and MariaDB 11.4.2, Tests SQLite (PHP 8.2, 8.3))
- **(8) Dependencies and blockers.** No file of the adapters, clients or payload builders is changed by both integration sets, but the combined tree (PR 75 with PR 83) does not exist. Main alone is red on the Torod discovery fixture until #1 merges.
- **(9) Acceptance criteria.**
  - [MET] A Torod warehouse order is built once from the order, the last-mile create call is never made, and a repeated webhook event is applied once (TorodAdapterTest::test_the_lastmile_create_shipment_call_is_never_made_because_it_would_ship_twice; TorodWebhookTest::test_a_repeated_delivery_of_the_same_event_is_stored_and_applied_exactly_once; PR 83 8ff3410 and PR 75 47b78ab, Fulfillment + security jobs)
  - [MET] An M5AZN order body holds only that provider's items in the documented shape, and a 2xx without a reference is never re-sent (M5aznAdapterTest::test_the_create_order_body_matches_the_documented_shape_and_contains_only_this_providers_items, ::test_a_2xx_without_a_reference_is_not_acceptance_and_is_never_resent; same jobs)
  - [MET] The provider is asked exactly once however many times the job runs, and a timeout after sending is never answered with a second send (DispatcherTest::test_the_provider_is_asked_exactly_once_however_many_times_the_job_runs, ::test_a_timeout_after_sending_is_never_answered_with_a_second_send; same jobs)
  - [MET] An order whose city has no approved Torod mapping is held and no request is made (TorodCityResolutionTest::test_no_order_request_is_ever_made_while_the_city_is_not_settled; PR 83 8ff3410 and PR 75 47b78ab, Address tests MySQL 8 and MariaDB 11.4.2)
  - [MET] Nothing leaves the host while the kill switch or the external-writes switch is off (ExternalWriteGuardTest::test_a_write_is_refused_before_anything_leaves_the_host; DispatcherTest::test_the_global_kill_switch_stops_everything; same Fulfillment + security jobs)
- **(10) Next coding task.** On a branch stacked on the storefront chain (for example #53, or the PR 83 integration branch, where config/gcc.php and GCC_STOREFRONTS_ENABLED exist) add tests/Feature/Fulfillment/TorodPayloadStorefrontOnTest (shared with C-18): build one Torod and one M5AZN order payload with the flag off and on (Saudi default) and assert the payloads are byte for byte equal; then run tests/Feature/Fulfillment and tests/Feature/Address on the combined tree when it exists.
- **Open owner decisions.** Merging #1 also changes CLAUDE.md on main (its own note): the owner merges it, or only its Torod test line is cherry-picked.
- **Verifier note (not applied automatically).** next_task corrected by the verifier (applied above).

#### H1-13. Shipping and tax trace for local and international orders, pinned by scenario tests

- **State: IN PROGRESS** | plan lines 501-507 | Charter section 7 (done means code and tests); Discovery E09, E10, E11; plan Report 10 (international shipping tests) | reviews: finance
- **(2) Functional requirement.** The path checkout, payment, webhook, order confirmation, fulfillment, refund and reconciliation is traced for local and cross-border orders, in each market's currency, with tax, regulatory limits and merchant accounts, and every case is pinned by a scenario test that runs in CI. The plan's audit wording is superseded; the requirement stays.
- **(3) Exists now in code (main).** The trace is written in the Discovery evidence on the audit branch (E09 checkout, E10 payments, E11 shipping: one fulfillment currency, two decimals, Saudi phone, no eligibility check); it is not in main. Tests pin the Saudi path only: OrderScenarioMatrixTest (shipping and tax allocation across providers), PilotServerMoneyTest (a non-riyal store is held) and ShipmentCostServiceTest (carrier cost by zone).
- **(4) Done in the draft PRs.** Nothing for cross-border scenarios. No draft adds a shipping or fulfillment test with a foreign destination or a Gulf currency other than the riyal.
- **(5) What remains.** (1) a scenario matrix: local SA, local AE in AED, cross-border SA to KW in KWD (3 decimals), prepaid and cash on delivery, regulated and ordinary lines; (2) one expected outcome per case from checkout to refund (decision, payment completion in the source storefront, dispatch hold or send, refund in the original currency and account); (3) cases that need the engine skipped with a reason until H1-06 exists.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` OrderScenarioMatrixTest (shipping, tax model and cash on delivery split across providers add up to what the customer paid) and PilotServerMoneyTest (a non-riyal single-currency store is held) (PR 83 8ff3410 and PR 75 47b78ab: Fulfillment + security tests MySQL 8 and MariaDB 11.4.2, Tests SQLite (PHP 8.2, 8.3))
  - `ci` ShipmentCostServiceTest (carrier cost by zone, a missing cost is never free) (PR 83 8ff3410 and PR 75 47b78ab: Sales-channel and cost tests (costs) MySQL 8.0 and MariaDB 11.4.2)
- **(8) Dependencies and blockers.** Needs the engine (H1-06), the currency seam (GCC-05) and the payment, refund and entity pieces owned by other units; weight and duty data are absent.
- **(9) Acceptance criteria.**
  - [MET] A Saudi domestic order's shipping, tax model and cash on delivery split across providers add up to what the customer paid (OrderScenarioMatrixTest (items 80 + shipping 15 = what the customer paid); PR 83 8ff3410 and PR 75 47b78ab, Fulfillment + security jobs)
  - [MET] A single-currency store whose currency is not the riyal has its provider shipments held, not sent (PilotServerMoneyTest::test_a_single_currency_store_whose_default_currency_is_not_the_riyal_is_held_even_with_the_alias; same jobs)
  - [MISSING] A cross-border order (foreign destination, regulated and ordinary lines, KWD, BHD or OMR amounts) has an end-to-end scenario from checkout to refund (no shipping or fulfillment test uses a foreign destination or a Gulf currency other than the riyal)
  - [MISSING] A return and refund of a cross-border order restocks the source row and refunds in the original currency to the original merchant account (ReturnLogisticsService has no country or currency; no scenario)
- **(10) Next coding task.** Test-only branch from main: tests/Feature/Fulfillment/ShippingScenarioMatrixTest (inside the Fulfillment + security group paths, so it runs on SQLite, MySQL 8 and MariaDB) with a data provider (local SA, local AE in AED, cross-border SA to KW in KWD, prepaid and cash on delivery, regulated and ordinary). Cases that need the engine are skipped with the reason 'pending H1-06'.
- **Verifier note (not applied automatically).** next_task corrected by the verifier (applied above).

#### H1-14. Local shipping rules belong to a storefront and are never inherited

- **State: IN PROGRESS** | plan lines 260 | GCC-16; DECISIONS ('shipping rules are NOT automatically inherited across companies'); Discovery E11-F12; owner question Q-24 | reviews: owner-decision
- **(2) Functional requirement.** Local shipping methods, costs, delivery times and zones belong to a storefront and its selling company: a store lists and charges only its own, sellers change only their own, staff change only their country's, and copying from another store creates a draft instead of sharing.
- **(3) Exists now in code (main).** ... The shipping_methods table has only a cost-column modify migration, and shipping_zones and return_shipments have none (E11-F09); shipping_types and category_shipping_costs do have create migrations (2022_03_13_111914, 2022_03_13_121514).
- **(4) Done in the draft PRs.** #48: a shipping method can be chosen only for the caller's own cart group in the storefront, on web and REST. #52 (in the PR 75 tree): a seller changes only their own methods and category costs. Customer app #7: the shipping methods offered and chosen are dropped when the storefront changes. No draft gives a method a country.
- **(5) What remains.** (1) an additive storefront column (or table) on methods, zones and category costs, legacy rows kept as the Saudi store's; (2) checkout and REST listings filtered to the storefront; (3) staff country scope on the shipping screens (GCC-14c slice 6); (4) copy as a draft; (5) seller methods per market.
- **(6) PRs and commits.** [lavkoo#48](https://github.com/fullstackfull/lavkoo/pull/48) `4a821d3`; [lavkoo#52](https://github.com/fullstackfull/lavkoo/pull/52) `ead8e2a`; [lavkoo#75](https://github.com/fullstackfull/lavkoo/pull/75) `47b78ab`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`; [lavkooapp#7](https://github.com/fullstackfull/lavkooapp/pull/7) `56deea7`
- **(7) Tests.**
  - `ci` StorefrontCartEdgesTest: a shipping method cannot be chosen for the cart group of another storefront or another customer, on web and REST (PR 83 8ff3410: Storefront tests MySQL 8 and MariaDB 11.4.2, Tests SQLite (PHP 8.2, 8.3))
  - `ci` SellerApiShippingMethodOwnershipTest and VendorCategoryShippingCostOwnershipTest (PR 75 47b78ab: Fulfillment + security tests MySQL 8 and MariaDB 11.4.2, Tests SQLite (PHP 8.2, 8.3))
  - `ci` storefront_cart_reload_test (customer app): the shipping methods offered and chosen are dropped on a storefront change (lavkooapp PR 7 56deea7: CI job storefront-header (success))
- **(8) Dependencies and blockers.** the live schema of shipping_methods and shipping_zones is UNVERIFIED (no create migration; the tests build their own tables); shipping_types and category_shipping_costs are defined by create migrations
- **(9) Acceptance criteria.**
  - [MET] A shipping method can be chosen only for the caller's own cart group in the storefront, on web and REST (StorefrontCartEdgesTest::test_a_shipping_method_cannot_be_chosen_for_the_cart_group_of_another_storefront, ::test_a_shipping_method_cannot_be_chosen_for_the_cart_group_of_another_customer; PR 83 8ff3410, Storefront tests MySQL 8, MariaDB 11.4.2 and Tests SQLite)
  - [MET] A seller can read, change, switch off or delete only their own shipping methods and category shipping costs (SellerApiShippingMethodOwnershipTest and VendorCategoryShippingCostOwnershipTest; PR 75 47b78ab (PR 52 ead8e2a), Fulfillment + security jobs MySQL 8 and MariaDB 11.4.2)
  - [MET] The customer app drops the shipping methods offered and chosen when the customer changes storefront (lavkooapp test/storefront_cart_reload_test.dart (the shipping methods offered and chosen are dropped); lavkooapp PR 7 56deea7, CI job storefront-header; flag off by default)
  - [MISSING] A storefront lists and charges only its own shipping methods, with delivery time and cost in its local currency (ShippingMethod has no country, region or currency)
  - [MISSING] A staff member restricted to a country can change only that country's shipping methods, zones and rates (the shipping screens are in no GCC-14c slice (integrations and costs are slice 6))
  - [MISSING] Copying a shipping rule between storefronts creates a draft and nothing is inherited (no copy tool)
- **(10) Next coding task.** Stacked on #48: additive nullable storefront_country on shipping_methods (guarded by Schema::hasTable and hasColumn like products.storefront_country), ShippingMethod::scopeForStorefront, and the web set-shipping-method and REST choose-for-order accepting only the storefront's methods (legacy null rows belong to the default storefront). tests/Feature/Storefront/ShippingMethodStorefrontTest on SQLite and the Storefront MySQL group.
- **Open owner decisions.** Q-24: which carriers and shipping methods each market uses, and whether the Delivery Syria method stays off in Gulf storefronts.
- **Verifier note (not applied automatically).** exists_now corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** deps_blockers corrected by the verifier (applied above).
- **Update after the assessment.** PR 87 (GCC-05 slice 5, head 5f685059): while a storefront's own currency is in force every read of ShippingType answers product_wise, so no platform shipping table (method, category or zone, all in the platform's money) reaches a total or a method prompt, and the in-house type is cached raw; the panel edit of an order prices its shipping in the order's own money. StorefrontShippingTypeTest (14, nine fail on the unchanged source) and StorefrontOrderEditMoneyTest (13, eleven fail unchanged); local runs, CI of that head not read. A storefront still has no shipping methods or tables of its own: its shipping is the cost typed on each product.

#### H2-01. Amazon integration kept and working for the Saudi marketplace

- **State: MERGED** | plan lines 432, 434 | DECISIONS foundation (keep marketplace/channel integrations); guardrail 1 (no rewrite of sales channels) | reviews: none
- **(2) Functional requirement.** An Amazon seller account is connected from the admin panel and, only through allow-listed Selling Partner API operations, reads orders, listings, FBA stock and finances, publishes approved stock and price, confirms shipments and creates listings. Preserved as built.
- **(3) Exists now in code (main).** In origin/main (c5c04b7b): app/Services/SalesChannels/Amazon (24 files); AmazonConnector covers orders, listings, FBA stock, finances, price/quantity PATCH after VALIDATION_PREVIEW, shipment confirmation, listing create, catalog match. Bulk feeds and listing reconciliation have their own switches (off). 10 test classes; AmazonConnectorTest has 41 methods.
- **(4) Done in the draft PRs.** Nothing changes the connector. #76 only maps two Amazon marketplace ids to a country for product matching (H2-06).
- **(5) What remains.** No coding. Live-account verification and deployment are outside coding: nothing ever ran against a real Amazon account. UNVERIFIED for Saudi Arabia: confirmShipment carrier codes, value_with_tax price attribute, FeeType list. Notifications need the owner's AWS queue. Market gaps: H2-06, H2-07, H2-08.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` AmazonConnectorTest (41) plus Amazon Feed, FinanceGroups, Reports, ReportStream, Refunds, CatalogMatch classes; ChannelHttpTest; ChannelOperationHygieneTest (PR 83 head 8ff3410: Tests SQLite 8.2+8.3; MySQL 8.0+MariaDB 11.4.2 sales-channel/cost jobs (A-M); also green on PR 1 d664ab4 and PR 75 47b78ab)
- **(8) Dependencies and blockers.** The owner's Amazon seller account and roles for any live check. The red 'Deployment overlay' job on every main-based head is a known tooling result, not a test failure.
- **(9) Acceptance criteria.**
  - [MET] A price or quantity is sent only after a VALIDATION_PREVIEW; an update the preview rejects is never sent (AmazonConnectorTest::test_a_price_is_previewed_then_patched_with_the_documented_body; PR83 8ff3410 CI green (A-M))
  - [MET] Orders, listings, FBA stock, fees and shipment confirmation use registered operations only; an unregistered call is refused before leaving the host (ChannelHttpTest::test_an_unregistered_operation_or_a_wrong_method_is_refused_not_treated_as_harmless; PR83 8ff3410 CI green (A-M))
  - [MET] A fee transaction counts once, a bank transfer is a payout never revenue, a redelivered transaction keeps its identity (AmazonConnectorTest::test_a_transfer_to_the_bank_is_a_payout_never_revenue; PR83 8ff3410 CI green (A-M))
  - [MET] A refused refresh token marks the account revoked and stops its work; the connection test names each missing role (AmazonConnectorTest::test_a_refresh_token_amazon_refuses_marks_the_account_and_stops_the_work; PR83 8ff3410 CI green (A-M))
  - [MET] The bulk listings feed ships switched off and the shipped default is pinned (AmazonFeedSubmissionTest asserts shipped amazon.bulk_feeds_enabled is false; PR83 8ff3410 CI green (A-M))
- **(10) Next coding task.** No code change. When the owner supplies credentials: on a staging copy run the connection test and one dry-run price patch, and record the three UNVERIFIED Saudi facts in docs/sales-channels/api-reference.md.

#### H2-02. Noon integration kept and operable from the admin panel

- **State: IN PROGRESS** | plan lines 432, 435 | DECISIONS foundation; sales-channels decisions.md section 8 (noon joins shared stock per location, approved 2026-11-10) | reviews: native-arabic
- **(2) Functional requirement.** A noon seller account is created and configured from the admin panel and, through allow-listed noon operations, reads FBPI orders, reads and writes stock and price per warehouse and country, creates listings and shipments; noon-held (FBN) stock never joins our pool.
- **(3) Exists now in code (main).** In origin/main: app/Services/SalesChannels/Noon (8 files). NoonConnector implements orders, recipient, stock/price, listing create and shipments; noon joins the stock pool only at a location a person admitted. 7 test classes. Not built: listing read, finance (noon publishes none), polled returns. docs/sales-channels/README.md still says 'noon is not built' (stale).
- **(4) Done in the draft PRs.** Nothing. #72 only reads noon's country_code setting for product matching (H2-06).
- **(5) What remains.** (1) Admin account form for noon: AccountForm SECRET_FIELDS and SETTING_FIELDS and the create/show views know only amazon and trendyol, so a noon account cannot be created or opened from the panel (read from code, not run). (2) Listing read, so noon listings can be linked. (3) Arabic labels. Live checks need the owner's key file.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` Noon Catalogue, Connection, Orders, Shipments, StockPricing, PoolMembership, ProductGroups classes; NoonOrderConcurrencyTest; Unit NoonItemStatus, NoonShipmentCheck (PR 83 head 8ff3410: Tests SQLite 8.2+8.3; MySQL 8.0+MariaDB 11.4.2 sales-channel/cost jobs (N-Z) and (concurrency))
  - `none` No test creates or opens a noon account through the admin form (AdminSalesChannelsAccountsTest offers Amazon and Trendyol only)
- **(8) Dependencies and blockers.** The owner's noon key file and project code for any live call. Whether an FBPI seller may create products by API is settled only by a real account's roles.
- **(9) Acceptance criteria.**
  - [MET] noon signs in, proves identity, reads and writes stock per (warehouse, partner_sku) and price per (partner_sku, country); a 200 is read as a per-item status (NoonConnectionTest, NoonStockPricingTest; PR83 8ff3410 CI green (N-Z))
  - [MET] FBN units never join the shared pool; noon takes stock only from a location a person admitted (NoonPoolMembershipTest::test_stock_the_channel_itself_holds_never_joins_the_pool_whichever_channel_holds_it; PR83 8ff3410 CI green (N-Z))
  - [MET] A shipment create is fenced before the request leaves and verified by reading it back (NoonShipmentsTest; NoonOrderConcurrencyTest; PR83 8ff3410 CI green (N-Z, concurrency))
  - [MISSING] An administrator can create a noon account, enter key file and project code, set warehouse and country, and open its page (AccountForm::SECRET_FIELDS has no noon key; account-create.blade.php shows Trendyol text for non-Amazon cards; show() indexes SECRET_FIELDS by channel (read, not run))
  - [MISSING] noon listings are read into the product-mapping table so they can be linked (NoonConnector implements no ReadsListings (class declaration))
- **(10) Next coding task.** Branch from main: add noon to AccountForm (secrets key_id, private_key, project_code; settings warehouse_code, country_code, use_sandbox, sync_fbn_inventory), the create/show Blade cards with translate() keys, and AdminSalesChannelsAccountsTest cases (created disabled in dry run, secrets write-only, country validated). Flag the Arabic strings.
- **Open owner decisions.** none open for coding. Who handles an FBPI return is unresolved in noon's own documentation.

#### H2-03. Trendyol integration kept and working for the Gulf storefronts it already supports

- **State: MERGED** | plan lines 432, 436 | DECISIONS foundation; sales-channels decisions.md items 1 and 2 (awaiting owner approval) | reviews: owner-decision
- **(2) Functional requirement.** A Trendyol seller account (storefront SA, AE or KW) reads orders by shipment package, returns and settlements, publishes stock and price in batches whose results are followed, creates and updates products, confirms shipments and decides each return item once.
- **(3) Exists now in code (main).** In origin/main: app/Services/SalesChannels/Trendyol (12 files); TrendyolConnector (listings, orders, returns, finances, stock/price, product create/update, shipments, brands), package splits, return decisions, key-checked webhooks with polling as the source; storefront validated to SA, AE, KW. 8 test classes; TrendyolConnectorTest has 51 methods.
- **(4) Done in the draft PRs.** Nothing changes the connector. #72 only reads storefront_code for product matching (H2-06).
- **(5) What remains.** No coding. Live-account verification. UNVERIFIED: which of two return-endpoint paths the gateway serves. Statement rows carry no currency (account currency assumed) and Gulf payouts may settle in another. Invoice links and Unsupplied cancellation are excluded pending owner approval. QA, BH, OM: H2-07.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` TrendyolConnectorTest (51), PackageSplits, ReturnDecisions, Webhook, ProductCreate, Lookups, CategoryRequirements; PackageSplitConcurrencyTest; ReturnDecisionConcurrencyTest (PR 83 head 8ff3410: Tests SQLite 8.2+8.3; MySQL 8.0+MariaDB 11.4.2 sales-channel/cost jobs (N-Z) and (concurrency))
- **(8) Dependencies and blockers.** The owner's Trendyol stage and production keys, and the server address allow-listed by Trendyol, for any live check.
- **(9) Acceptance criteria.**
  - [MET] A split or return decision is recorded and fenced before the request, never re-sent after an unknown outcome; a split is confirmed by reading the order (TrendyolPackageSplitsTest::test_a_fenced_row_is_never_sent_again_however_many_times_it_is_run; PR83 8ff3410 CI green (N-Z, concurrency))
  - [MET] A repeated or stale webhook delivery changes nothing; polling stays the source of truth (TrendyolWebhookTest::test_a_delivery_repeated_by_trendyol_changes_nothing; PR83 8ff3410 CI green (N-Z))
  - [MET] A product create comes back as 'taken for processing', never as a listing; an unreadable list is unknown, not absent (TrendyolProductCreateTest::test_a_create_comes_back_as_taken_for_processing_and_never_as_a_listing; PR83 8ff3410 CI green (N-Z))
  - [MET] A storefront code outside SA, AE, KW is refused when the account is saved (AdminSalesChannelsAccountsTest bad-input case 'storefront that does not exist'; PR83 8ff3410 CI green (A-M))
  - [MET] A rehearsing account sends no product write and claims nothing (TrendyolProductCreateTest::test_a_rehearsing_account_sends_nothing_and_claims_nothing; PR83 8ff3410 CI green (N-Z))
- **(10) Next coding task.** No code change. With the owner's stage keys: connection test and one dry-run price batch on a staging copy; record the return endpoint the gateway serves in docs/sales-channels/api-reference.md. Put items 1 and 2 of docs/sales-channels/decisions.md to the owner.
- **Open owner decisions.** Item 1: leave Trendyol invoices to the seller panel (Gulf endpoint needs a public 10-year link; recorded, awaiting approval). Item 2: no Unsupplied item cancellation from the panel (awaiting approval).

#### H2-04. Odoo accounting integration kept and wired end to end (connect, preflight, map, draft, reconcile)

- **State: IN PROGRESS** | plan lines 432, 437 | DECISIONS foundation (keep Odoo); sales-channels decisions.md items 3 and 4 (no posting from this app; OperationalCosts the only cost engine) | reviews: finance, owner-decision
- **(2) Functional requirement.** Odoo is the accounting record, not a cost engine: a settled cost becomes a draft journal entry in the customer's Odoo through a mapped chart of accounts and a duplicate-proof outbox, and is compared in a three-way reconciliation. Final posting stays out by owner decision.
- **(3) Exists now in code (main).** In origin/main: app/Services/Accounting/Odoo (23 files); 3 tables (account mappings, posting queue, reconciliations); 4 admin screens; odoo:reconcile command; own switch family (4 switches, off). 192 feature, 59 unit and 5 concurrency test methods. docs/sales-channels/odoo-accounting.md line 3 still says 'not built' (stale). The connection row is made by OdooConnectionController::settings with SalesChannelAccount::create (enabled=false, dry_run=true, capabilities connection_test and financial_reconciliation already held, no audit record), not by ChannelAccountService. No Odoo screen enables it, turns dry run off or records the accounting_posting approval. ChannelAccountService::approveScope and setWriteEnabled refuse until connection_status is ok, which no Odoo code path records (only the Amazon, Trendyol and noon connectors do; config sales_channels.connectors has no 'odoo'). Every Odoo test builds the row already enabled, approved and live (tests/Support/BuildsOdooWorld.php odooConnection()). A connection saved on the screen and then tested is refused by ChannelHttp::assertAllowed (account disabled).
- **(4) Done in the draft PRs.** Nothing. No remote branch of lavkoo changes app/Services/Accounting, config/odoo.php or the Odoo tests (diff against merge-base, all branches).
- **(5) What remains.** (0) The application cannot take the Odoo connection from created to working: no enable, dry-run-off or posting-approval control on the Odoo screens, and the generic accounts.* routes refuse the approval and the write permission because the Odoo test never records connection_status=ok. Once the row is enabled, channels:sync --due (every 5 minutes) throws at that row (ChannelSyncService::permitted calls connectors->supports for the preset financial_reconciliation capability; no connector for 'odoo') and stops the run for every account with a higher id (Discovery E12-F23, P1, open, no test). (1) OdooOutbox::enqueue has no caller outside tests, so no cost reaches the queue by itself; the drain is a button and odoo:reconcile is not scheduled. (2) JSON-2 create is refused (body UNVERIFIED), so drafts need XML-RPC. (3) Live check on a customer instance. Per-entity wiring: H2-10, H2-11.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` tests/Feature/Accounting (10 classes, 192 methods), tests/Unit/Accounting (4, 59), OdooPostingConcurrencyTest (5) (PR 83 head 8ff3410: Tests SQLite 8.2+8.3; MySQL 8.0+MariaDB 11.4.2 sales-channel/cost jobs (costs) and (concurrency))
  - `none` No test asserts that a settled cost is queued by the application or that a schedule drains the queue
- **(8) Dependencies and blockers.** Odoo's external API exists only on the customer's Custom plan; keys last at most 3 months; a real instance is needed to verify. Whether estimates are ever posted is the owner's accounting policy.
- **(9) Acceptance criteria.**
  - [MET] Connection screen saves host and company, stores the API key write-only; the connection test reads the instance and writes nothing (AdminOdooScreensTest::test_the_connection_test_reads_the_instance_and_writes_nothing; PR83 8ff3410 costs group green)
  - [MET] A mapping to account, journal and tax is used only when approved and is never created in Odoo (OdooDraftBuilderTest::test_a_mapping_nobody_approved_is_not_used; PR83 8ff3410 costs group green)
  - [MET] A settled cost becomes a balanced two-line draft with a unique reference; one fact and version queues once; an ambiguous create waits for a person (OdooOutboxTest::test_a_second_run_after_an_unknown_outcome_sends_no_second_create; OdooPostingConcurrencyTest MySQL+MariaDB; PR83 8ff3410)
  - [MET] The three-way reconciliation shows differences and no control closes one (AdminOdooScreensTest::test_there_is_no_control_that_closes_a_difference; PR83 8ff3410 costs group green)
  - [MISSING] A settled cost is queued for Odoo by the application itself, and queue drain and reconciliation run on a schedule behind the Odoo switches (OdooOutbox::enqueue is called only from tests (git grep app, routes, Modules, database, config); no Odoo entry in ScheduleDefinition; drain is a POST button)
- **(10) Next coding task.** On main, in this order: (1) give the Odoo connection an enable, dry-run-off and accounting_posting approval path through ChannelAccountService (record the Odoo connection test as connection_status, audited, with only connection_test preset); (2) make channels:sync, channels:publish and ChannelSyncService::permitted skip accounts whose channel has no registered connector (or filter SalesChannelAccount::enabled() to SalesChannel::connectable()), with a test that runs channels:sync --due and channels:publish --only=settle with an enabled Odoo row; (3) add odoo:enqueue (settled actual/reconciled cost entries only, idempotent by source and version) and schedule it, the drain and odoo:reconcile in ScheduleDefinition behind ODOO_ENABLED and a new ODOO_AUTO_ENQUEUE switch (off); tests in tests/Feature/Accounting plus one MySQL concurrency case.
- **Open owner decisions.** Closed: final posting not enabled (approved item 3). Open: who enqueues (schedule or button), whether estimates are ever posted (today only settled), Odoo plan and key renewal.
- **Verifier note (not applied automatically).** exists_now corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** remaining corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** ac (add two criteria): The criteria list has no criterion for enabling the connection or for the channel-command interference, so 'wired end to end' is under-tested. Proposed: Add (a) 'An administrator can take the Odoo connection from created (disabled, rehearsing) to enabled and, after a recorded connection test, to an approved write-enabled connection through the application, with an audit trail' = MISSING; evidence: OdooConnectionController has no enable or approval action, ChannelAccountService::approveScope/setWriteEnabled need connection_status ok, nothing in app/Services/Accounting or app/Http/Controllers/Admin/Accounting records it (git grep recordConnection|connection_status finds none), tests/Support/BuildsOdooWorld.php builds the row already live. Add (b) 'An enabled Odoo connection row leaves channels:sync --due and channels:publish --only=settle working for every channel ac
- **Verifier note (not applied automatically).** next_task corrected by the verifier (applied above).

#### H2-05. Each channel and integration account is independent, with its own operating scope

- **State: IN PROGRESS** | plan lines 439 | DECISIONS foundation (Sales Channel Account is its own entity) | reviews: none
- **(2) Functional requirement.** Every Amazon, Noon, Trendyol and Odoo connection is its own account with its own credentials, environment, switches, approvals, sync state and listings, and moves from disabled to working one deliberate, logged step at a time.
- **(3) Exists now in code (main).** In origin/main: SalesChannelAccount with five gate columns re-read before every call; ChannelAccountService (created disabled, in dry run, no capability; capability ladder; recorded approvals; connection test under 30 days old; identity change withdraws proofs and sync state); credentials encrypted per account; module:sales_channels on every route.
- **(4) Done in the draft PRs.** Nothing.
- **(5) What remains.** Channel accounts (Amazon, Trendyol, noon): nothing for the Saudi baseline. Odoo connection: created outside ChannelAccountService with two capabilities preset and no audit record; it cannot be enabled or approved through the application; once enabled it stops channels:sync --due at its row (E12-F23). Shared with H2-04. The unique key (channel, environment, external_account_id), the missing country and legal-entity fields, and noon's country_code and warehouse_code sitting outside the identity set are H2-06.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` ChannelAccountServiceTest, AdminSalesChannelsAccountsTest, SyncStateTest, ChannelSyncServiceTest, ModulePermissionsTest (PR 83 head 8ff3410: Tests SQLite 8.2+8.3; MySQL 8.0+MariaDB 11.4.2 sales-channel/cost jobs (A-M) and (N-Z))
- **(8) Dependencies and blockers.** none
- **(9) Acceptance criteria.**
  - [MET] A new account is created disabled, in dry run, with no capability and no write permission (ChannelAccountServiceTest::test_a_new_account_does_nothing_until_each_step_is_taken; PR83 8ff3410 CI green (A-M))
  - [MET] A capability needs those below it and a proven connection; a write capability also needs a recorded approval and a test under 30 days old (ChannelAccountServiceTest::test_a_write_capability_needs_a_reason_and_a_recent_proven_connection; PR83 8ff3410 CI green (A-M))
  - [CODED] Changing seller, environment, storefront or marketplaces withdraws every proof and approval and clears what was read for the old identity (ChannelAccountServiceTest::test_pointing_the_account_at_another_seller_withdraws_everything_that_was_proven_or_approved_for_the_old_one; PR83 8ff3410 CI green (A-M))
  - [MET] Credentials are stored per account, encrypted, and never leave the store (ChannelAccountServiceTest::test_credentials_are_stored_encrypted_and_never_leave_the_store; PR83 8ff3410 CI green (A-M))
  - [MET] Every channel route sits behind the sales_channels permission and the migration guard (ModulePermissionsTest::test_every_route_of_each_module_sits_behind_that_modules_permission_and_the_migration_guard; PR83 8ff3410 CI green (A-M))
- **(10) Next coding task.** On main (shared with H2-04): make the channel commands skip accounts with no registered connector, test channels:sync --due and channels:publish --only=settle with an enabled Odoo row, and create the Odoo connection through ChannelAccountService (no capability beyond connection_test, audited). Rule for H2-06: a new account field (country, legal entity) joins the identity set in ChannelAccountService so changing it withdraws approvals; add that test with the field.
- **Verifier note (not applied automatically).** remaining corrected by the verifier (applied above).
- **Verifier note (not applied automatically).** ac (add one criterion): No criterion covers the integration (Odoo) side of 'each channel and integration account is independent'. Proposed: Add 'An Odoo connection is created, enabled and approved through ChannelAccountService with the same ladder and audit as a channel account, and an enabled Odoo row never stops or throws in the channel sync, publish and settle commands' = MISSING; evidence: OdooConnectionController::settings uses SalesChannelAccount::create with capabilities preset; SalesChannelsSyncCommand + ChannelSyncService::permitted + ChannelConnectorRegistry::for throw for channel 'odoo'; no test runs a channel command with an Odoo row; Discovery E12-F23.
- **Verifier note (not applied automatically).** next_task corrected by the verifier (applied above).

#### H2-06. A channel account belongs to one storefront country and legal entity

- **State: IN PROGRESS** | plan lines 439 | DECISIONS foundation (Country, Storefront, Legal Entity, Sales Channel Account are distinct); GCC-14 | reviews: owner-decision, security
- **(2) Functional requirement.** Each channel account is tied to the storefront country and legal entity it sells for, so each of the six countries runs its own Amazon, Noon and Trendyol accounts side by side, and staff, listings and orders can be scoped by that tie.
- **(3) Exists now in code (main).** In origin/main the account has the platform seller_id (0 = the platform's own), market (a copy of the Trendyol storefront_code or the Amazon region, empty for noon; it is part of the account identity), marketplace_ids, currency and settings: no explicit storefront country and no legal entity; one unique key (channel, environment, external_account_id), where external_account_id is the channel's own seller id. noon's country_code and warehouse_code are settings outside the identity set, so changing a noon account's country withdraws no proof or approval (E12-F02). The only legal-entity value in any tree is one global string, GCC_LEGAL_ENTITY (config/gcc.php), used for the legal acceptance receipts and shown by the platform admin API market directory, both in PR 83 only.
- **(4) Done in the draft PRs.** #72 (1c779d2): ChannelAccountCountry reads Trendyol storefront_code and noon country_code; #76 (0ad49d2) adds Amazon marketplace ids for SA and AE. Product matching and SKU routing rules narrow to that country behind GCC_COUNTRY_SCOPED_CODES (off). No column or entity is added to the account.
- **(5) What remains.** (1) An explicit country and legal-entity field on the account instead of guessing from vendor settings. (2) Two accounts of one seller id on different storefronts (today refused by the unique key). (3) Staff country scope over channel screens: slice 6 of GCC-14c, not built. (4) Accounts of unknown country stay un-narrowed.
- **(6) PRs and commits.** [lavkoo#72](https://github.com/fullstackfull/lavkoo/pull/72) `1c779d2`; [lavkoo#76](https://github.com/fullstackfull/lavkoo/pull/76) `0ad49d2`
- **(7) Tests.**
  - `ci` tests/Feature/Catalog/ChannelProductMatcherCountryTest (country from the channel's setting, Amazon SA/AE, unknown stays a conflict, switch off unchanged) and SixCountryChannelMatcherTest (PR 76 head 0ad49d2: Tests SQLite 8.2+8.3; PR 83 head 8ff3410: Catalog tests MySQL 8 and MariaDB 11.4.2)
  - `none` No test for an account country or entity column, two accounts of one seller id, or staff scope on channel screens
- **(8) Dependencies and blockers.** Owner: does one Amazon or Trendyol seller id serve several GCC storefronts, or has each legal entity its own accounts (UNVERIFIED). The legal-entity model is another unit's. #72/#76 sit on the storefront chain (#64). No file here is touched by the security set, so the missing combined tree does not cap it.
- **(9) Acceptance criteria.**
  - [MET] An account's country is read from its own setting: Trendyol storefront_code, noon country_code, or Amazon marketplaces that all name one Gulf country (ChannelProductMatcherCountryTest::test_the_country_of_an_account_is_read_from_the_setting_its_channel_keeps_it_in; PR76 0ad49d2 SQLite; PR83 8ff3410 Catalog MySQL 8+MariaDB 11.4.2)
  - [MET] With the switch on a listing is proposed only its account's country's products; an account of unknown country stays ambiguous; switch off changes nothing (ChannelProductMatcherCountryTest::test_with_the_switch_on_an_account_of_no_known_country_keeps_a_shared_code_a_conflict; SixCountryChannelMatcherTest; same CI)
  - [MISSING] The account carries an explicit storefront country and legal entity that cannot change without withdrawing its approvals (sales_channel_accounts migration 2026_11_07_100100 has no such column; identity set unchanged in PR 83)
  - [MISSING] One seller id can have an account per storefront (Trendyol SA and AE; Amazon SA and AE) (unique key sca_channel_env_external_unique; AdminSalesChannelsAccountsTest::test_one_seller_id_is_one_account_per_channel_and_environment pins the opposite)
  - [MISSING] A restricted employee (country scope) sees only the accounts, listings and orders of their countries (GCC-14C doc on #82 lists ChannelProductController, ChannelListingController, ChannelInventoryController, ChannelOrderController, ChannelRoutingController as slice 6 (not built))
- **(10) Next coding task.** Extend #76: additive migration adding nullable storefront_country and legal_entity_ref to sales_channel_accounts; add both to the account form and ChannelAccountService's identity set; backfill from ChannelAccountCountry only by an explicit command; tests in tests/Feature/Catalog and SalesChannels. Settle the one-seller-two-storefronts key with the owner first.
- **Open owner decisions.** Whether one seller id serves several storefronts or each legal entity has its own accounts; which channels each country uses.
- **Verifier note (not applied automatically).** exists_now corrected by the verifier (applied above).

#### H2-07. Each channel's market rules cover the six GCC markets it can serve

- **State: IN PROGRESS** | plan lines 439 | DECISIONS (never assume a provider supports every country or method); E12 section 7 question 4 | reviews: owner-decision
- **(2) Functional requirement.** Which of SA, AE, KW, QA, BH, OM each channel serves is held as data and checked against the channel's own documentation, with its channel-specific rules (storefront code, marketplace id, country, carrier list, destination).
- **(3) Exists now in code (main).** In origin/main the lists are code constants: Trendyol storefronts SA, AE, KW and carrier list SA, AE only; Amazon marketplace ids are checked for shape only and every write goes to the first one; noon country_code defaults to sa and is only checked non-empty when a price is published; managed dispatch refuses any destination but SA.
- **(4) Done in the draft PRs.** #76 (0ad49d2) knows two Amazon Gulf marketplaces (SA, AE) for product matching only. No draft touches the lists, the dispatch gate or noon's country.
- **(5) What remains.** (1) Which channels serve KW, QA, BH, OM: UNVERIFIED here (vendor documents not read); then move the lists to data. (2) An Amazon account of several marketplaces must write to the row's own marketplace (its listing key has none). (3) Validate noon country_code. (4) Dispatch beyond SA needs per-country phone and address rules (carrier unit).
- **(6) PRs and commits.** [lavkoo#76](https://github.com/fullstackfull/lavkoo/pull/76) `0ad49d2`
- **(7) Tests.**
  - `ci` ChannelDispatchTest pins any non-Saudi destination as refused; AdminSalesChannelsAccountsTest and ChannelAccountServiceTest pin the SA, AE, KW list (PR 83 head 8ff3410: Tests SQLite 8.2+8.3; MySQL 8.0+MariaDB 11.4.2 sales-channel/cost jobs (A-M))
  - `none` No test for QA, BH or OM, a multi-marketplace Amazon write, or noon country validation
- **(8) Dependencies and blockers.** Vendor documentation reads (not possible in this assessment). Which countries each channel serves is the owner's market-entry fact. GCC-14B doc: the Amazon list knows no marketplace of Kuwait, Qatar, Bahrain or Oman.
- **(9) Acceptance criteria.**
  - [MET] A Trendyol account accepts only the storefronts SA, AE, KW and refuses others (AdminSalesChannelsAccountsTest bad-input case 'storefront that does not exist'; PR83 8ff3410 CI green (A-M))
  - [MET] A channel order destined outside Saudi Arabia is never dispatched (today's rule, pinned) (ChannelDispatchTest::test_what_the_channel_ships_itself_or_cash_on_delivery_or_another_country_is_never_dispatched; PR83 8ff3410 CI green (A-M))
  - [MISSING] The markets each channel serves are data verified against vendor documentation; QA, BH, OM accepted where the vendor serves them (ChannelSettings::TRENDYOL_STOREFRONTS constant; GCC-14B doc: Amazon list knows no KW, QA, BH or OM marketplace)
  - [MISSING] An Amazon account with several marketplaces writes price, stock and shipment confirmation to the marketplace of the listing (AmazonConnector::primaryMarketplace() is used by every write (docs/sales-channels/operations.md known limits))
  - [MISSING] A noon account's country is validated against the countries noon serves (ChannelSettings::validate has no noon branch (default 'sa'); NoonConnector::publishPrices refuses only an empty country_code)
- **(10) Next coding task.** Branch from main: record each vendor's official market list (Amazon marketplaces, Trendyol storefrontCode, noon country_code) with its source in docs/sales-channels/api-reference.md, then move TRENDYOL_STOREFRONTS and an Amazon marketplace-to-country map into config/sales_channels.php with tests; SA stays the default.
- **Open owner decisions.** Which channels the owner uses per country; Amazon accounts of other marketplaces or of two marketplaces (open in #76); whether dispatch may ever leave SA (carrier unit).
- **Verifier note (not applied automatically).** ac[0].t: 'accepts only the storefronts SA, AE, KW' is false as written: ChannelSettings::validate and AccountForm (settings.storefront_code nullable) also accept an empty storefront, the Turkish marketplace. The cited test only proves that 'XX' is refused. Proposed: A Trendyol storefront code other than SA, AE, KW or empty (the Turkish marketplace) is refused when the account is saved (test: 'storefront that does not exist' = XX).

#### H2-08. Channel prices, currency and tax follow each market's currency and decimals

- **State: IN PROGRESS** | plan lines 439 | DECISIONS (local pricing, not auto-FX; three-decimal KWD, BHD, OMR); GCC-05 storefront currency | reviews: finance
- **(2) Functional requirement.** Prices and amounts sent to or read from a channel use the market's currency at its own decimals (two for SAR and AED, three for KWD, BHD, OMR); channel prices are set per market and never converted from Saudi prices; included VAT is read per market.
- **(3) Exists now in code (main).** In origin/main only a price a person set per listing is published and the storefront price is refused; rounding is fixed at 2 decimals in the publisher and the three connectors; amounts are stored at 6 decimals; an Amazon price patch falls back to 'SAR'; Trendyol prices are VAT-inclusive by default; Trendyol statement rows carry no currency (account currency assumed).
- **(4) Done in the draft PRs.** Nothing in the open drafts changes channel money handling.
- **(5) What remains.** (1) Price scale by currency (3 decimals for KWD, BHD, OMR) in StockPricePublisher and the three connectors. (2) Remove the 'SAR' fallbacks: a non-Saudi account must carry its currency. (3) Statement currency per account instead of assumed. (4) Check VAT-inclusive pricing per market with each channel.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` StockPricePublisherTest (manual price only, storefront price refused) and TrendyolConnectorTest money case, all SAR at two decimals (PR 83 head 8ff3410: Tests SQLite 8.2+8.3; MySQL 8.0+MariaDB 11.4.2 sales-channel/cost jobs (N-Z))
  - `none` No test publishes or reads a three-decimal price or a non-SAR account
- **(8) Dependencies and blockers.** GCC-05 (an order records neither country nor currency) is another unit's; channel VAT rules per market are for the owner and finance to confirm.
- **(9) Acceptance criteria.**
  - [MET] Only a price a person set is published; the storefront price is refused; no conversion from Saudi prices; a price that rounds to zero is never sent (StockPricePublisherTest::test_only_a_price_a_person_set_is_published; PR83 8ff3410 CI green (N-Z))
  - [CODED] The tax a price already contains is derived from the line's rate and the account's included-tax setting (TrendyolConnectorTest::test_a_package_is_mapped_with_its_money_and_nothing_personal (15 % VAT, SAR only); PR83 8ff3410 CI green (N-Z))
  - [MISSING] A KWD, BHD or OMR price keeps its third decimal in the publisher and in the Amazon, Trendyol and noon payloads (StockPricePublisher PRICE_SCALE = 2; TrendyolConnector round(..., 2); NoonConnector round(scale: 2))
  - [MISSING] A non-Saudi account never falls back to SAR for a price update or a statement row (AmazonConnector 'currency' ?? 'SAR'; TrendyolFinanceMapper sets currency_assumed (no test found))
- **(10) Next coding task.** Branch from main: make PRICE_SCALE a function of the account currency (2 for SAR, AED, QAR; 3 for KWD, BHD, OMR) used by StockPricePublisher and the three connectors; drop the 'SAR' fallbacks for accounts with a country; red-first KWD, BHD, OMR cases in StockPricePublisherTest and each connector test.
- **Open owner decisions.** Price decimals per currency; whether channel prices ever follow storefront prices (today manual only, by design); VAT-inclusive pricing per market.

#### H2-09. The pharmacy (regulated-product) gate on channel listings follows each market's rules

- **State: IN PROGRESS** | plan lines 439 | GCC-16 (eligibility checked independently of carriers); DECISIONS (regulated pharmacy and health goods); E12-F09 | reviews: legal, owner-decision
- **(2) Functional requirement.** Before a product is put on a channel the shop's own rules say whether it is regulated and a recorded clearance is needed; the rules can differ per market and per channel, fail closed, and the person recording a clearance is traceable.
- **(3) Exists now in code (main).** In origin/main: RegulatedProductGate is gate 8 of 11, mode off or strict, rules by category, brand and name pattern, global or per channel (listing.regulated.channels.<channel>); a clearance is a recorded reference plus the admin id; it ships in mode 'off'; noon falls back to the global rules.
- **(4) Done in the draft PRs.** Nothing.
- **(5) What remains.** (1) Rules per (market, channel) held as data instead of environment variables. (2) The Saudi and Gulf requirements per country (owner and legal). (3) The launch value of the mode per country. (4) noon-specific keys.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` ListingPublishingTest (regulated and clearance cases), AdminChannelListingScreenTest (clearance permission and reference) (PR 83 head 8ff3410: Tests SQLite 8.2+8.3; MySQL 8.0+MariaDB 11.4.2 sales-channel/cost jobs (A-M))
  - `none` No test for a market-specific rule
- **(8) Dependencies and blockers.** Which products are regulated, and what clearance a channel listing needs, in each of the six markets is a legal question; nothing in the repository decides it.
- **(9) Acceptance criteria.**
  - [MET] In strict mode an unclassified product is refused, a regulated product needs a recorded clearance, and an admin id on a command is not one (ListingPublishingTest::test_a_regulated_product_needs_a_recorded_clearance_and_an_admin_id_is_not_one; PR83 8ff3410 CI green (A-M))
  - [MET] Only an admin whose role holds the sales_channels module can record a clearance, and it needs a reference (ListingPublishingTest::test_only_an_admin_whose_role_carries_the_module_may_record_a_clearance; PR83 8ff3410 CI green (A-M))
  - [MISSING] Regulated rules can differ per market and per channel (config/sales_channels.php listing.regulated is keyed by channel only; no country dimension)
- **(10) Next coding task.** Branch from main: key listing.regulated rules by (country, channel) in config/sales_channels.php, resolve the country from the account (H2-06), keep strict mode fail-closed; tests per country with SA unchanged; leave the per-country product lists as owner and legal input.
- **Open owner decisions.** Which products are regulated in each market and what clearance a channel listing needs there; the launch value of the mode per country.

#### H2-10. Odoo posts each legal entity's records into that entity's Odoo company

- **State: IN PROGRESS** | plan lines 441 | DECISIONS foundation (separate legal selling company per country; preserve historical snapshots); sales-channels decisions.md items 3 and 4 | reviews: finance, legal, owner-decision
- **(2) Functional requirement.** Every record sent to Odoo is attributed to the legal entity of the market it came from and posted only into that entity's Odoo company, through that entity's own connection, chart mapping and approvals; the Saudi books are untouched.
- **(3) Exists now in code (main).** In origin/main the company is a data column (odoo_company_id on mappings, queue and reconciliations) and a connection setting; preflight refuses several companies with none chosen and never takes the first. But there is one Odoo connection for the whole platform (first row of channel 'odoo'), no legal-entity model, and cost entries carry no entity.
- **(4) Done in the draft PRs.** Nothing in Odoo or the cost ledger. PR 83 adds one global GCC_LEGAL_ENTITY string used for legal acceptance receipts, not for accounting.
- **(5) What remains.** (1) A legal-entity record per country linked to one Odoo company and connection. (2) Several Odoo connections chosen by entity in screens, commands and outbox. (3) Entity attribution of cost entries, channel orders and statements from the account's country (H2-06). (4) An entity marker in the Odoo reference and narration for new entries only; existing PKSA-COST references must not change.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` OdooPreflightRunTest, OdooDraftBuilderTest, OdooUniquenessTest, AdminOdooScreensTest (company chosen, never assumed; same fact in another company is its own mapping) (PR 83 head 8ff3410: Tests SQLite 8.2+8.3; MySQL 8.0+MariaDB 11.4.2 sales-channel/cost jobs (costs))
  - `none` No test for two connections, an entity-to-company map, or entity-tagged references
- **(8) Dependencies and blockers.** The legal-entity model and GCC-05 (an order records neither country nor currency) belong to other units. The owner must fix how many Odoo databases and companies exist.
- **(9) Acceptance criteria.**
  - [MET] Mappings, queue rows and reconciliations carry the Odoo company id; the same fact in another company is its own mapping (OdooUniquenessTest::test_the_same_fact_in_another_odoo_company_is_its_own_mapping; PR83 8ff3410 costs group green)
  - [MET] The company is chosen on the connection or is the only one; several with none chosen are refused and the first row is never assumed (OdooPreflightRunTest::test_several_companies_and_no_choice_is_refused_rather_than_resolved_to_the_first_row; PR83 8ff3410 costs group green)
  - [MET] A mapping cannot be saved before a company is chosen (AdminOdooScreensTest::test_a_mapping_cannot_be_saved_before_a_company_is_chosen; PR83 8ff3410 costs group green)
  - [MISSING] A legal entity maps to exactly one Odoo company and connection; an entry for entity B never posts into entity A's company (OdooConnectionController::connection() returns the first channel='odoo' row; no entity model)
  - [MISSING] Several Odoo connections coexist, each with its own switches, approvals and credentials, chosen by entity (OdooConnectionController, OdooOutboxController and OdooReconcileCommand all take the first Odoo row)
  - [MISSING] A cost entry, channel order or statement row is attributed to its account's entity, and the Odoo reference identifies the entity for new entries (ChannelOrderMaterializer books channel orders under seller_id 1 / admin; OdooPostingKey reference is a fixed 'PKSA-COST-' prefix)
- **(10) Next coding task.** After the legal-entity record exists (other unit): choose the Odoo connection by entity in OdooConnectionController, OdooOutboxController and OdooReconcileCommand (several channel='odoo' rows, own switches), add an entity argument to OdooOutbox::enqueue, and test that an entry for entity B never reaches entity A's connection. Until then write the design note and tests against the in-memory Odoo fake.
- **Open owner decisions.** How many Odoo databases and companies (one per legal entity?); which entity books each channel order; whether the Saudi company's posted references are frozen.

#### H2-11. Accounting separation by entity, currency and channel, with settlements reconciled

- **State: IN PROGRESS** | plan lines 441 | DECISIONS foundation (invoicing, taxes, settlements and accounting per legal company); sales-channels decisions.md items 3 and 4 | reviews: finance
- **(2) Functional requirement.** Entries, reversals and settlement reconciliations are kept apart per legal entity, currency and channel; amounts are written at the posting currency's decimals; a channel's statement, our ledger and Odoo are compared per period and no machine closes a difference.
- **(3) Exists now in code (main).** In origin/main: balanced single-channel draft entries carrying the original-currency figure; reversals and returns as new linked entries (no edit or delete operation exists); three-way reconciliation per period, channel, currency, company; Amazon and Trendyol statements become costs once. But OdooMoney writes 2 decimals (OdooDraftBuilder never passes the currency's), odoo:reconcile defaults --currency=SAR, and the cost base currency is one value.
- **(4) Done in the draft PRs.** Nothing.
- **(5) What remains.** (1) Pass the posting currency's decimals (3 for KWD, BHD, OMR) from the Odoo currency read into OdooMoney. (2) Reconcile and post per entity in the entity's currency (no SAR default). (3) Cost-ledger base currency per entity. (4) Statement currency per account (Trendyol rows carry none). (5) Payout and bank settlement per entity.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` OdooDraftBuilderTest, OdooUndoTest, OdooReconciliationTest, Unit OdooMoneyTest, AmazonFinanceGroupsTest, ChannelSyncServiceTest statement cases (PR 83 head 8ff3410: Tests SQLite 8.2+8.3; MySQL 8.0+MariaDB 11.4.2 sales-channel/cost jobs (costs) and (A-M))
  - `none` No test posts or reconciles a KWD, BHD or OMR amount (OdooMoneyTest checks other decimals at unit level only)
- **(8) Dependencies and blockers.** Needs the legal-entity model and the entity-aware Odoo connections of H2-10. The cost engine's base currency is the finance unit's. Odoo documents no res.currency page: decimals must be read from the instance and proved, as the currency list is.
- **(9) Acceptance criteria.**
  - [MET] A settled cost becomes one balanced draft with its channel's mapping and original-currency figure; estimates, disputed and undetermined costs are never posted (OdooDraftBuilderTest::test_a_foreign_currency_cost_carries_its_original_figure_and_odoos_own_currency_id; PR83 8ff3410 costs group green)
  - [MET] A correction is a new linked entry (reversal or return), never an edit or delete; a reversal waits while its original is queued (OdooUndoTest::test_a_reversal_is_not_created_while_the_entry_it_reverses_is_still_queued; PR83 8ff3410 costs group green)
  - [MET] The three-way reconciliation per period, channel, currency and company shows a difference, closes none, and leaves a missing leg null not zero (OdooReconciliationTest::test_odoo_that_could_not_be_read_leaves_its_leg_null_and_not_zero; PR83 8ff3410 costs group green)
  - [MISSING] Amounts reach Odoo at the posting currency's decimals (three for KWD, BHD, OMR) (OdooMoney DEFAULT_DECIMALS = 2; OdooDraftBuilder calls OdooMoney::debitAndCredit($amount) without decimals)
  - [MISSING] Posting and reconciliation use the legal entity's currency and base currency, not a global SAR (odoo:reconcile option --currency=SAR; config/operational_costs.php base_currency defaults to SAR)
  - [CODED] A statement row without a currency is flagged and the account currency used (TrendyolFinanceMapper sets 'currency_assumed' => true; no test found)
- **(10) Next coding task.** Branch from main: read res.currency decimal places in OdooPreflightRun (proved like the currency list) and pass them to OdooMoney in OdooDraftBuilder; add KWD, BHD, OMR cases to OdooDraftBuilderTest and OdooMoneyTest; replace the --currency=SAR default of odoo:reconcile with the connection company's currency.
- **Open owner decisions.** Per-entity cost base currency and exchange-rate source; whether estimated costs may ever be posted (today only settled).

#### H2-12. Safety locks and kill switches

- **State: IN PROGRESS** | plan lines 443 | DECISIONS guardrail 1; charter section 4; E12-F16, E12-F22 | reviews: security, owner-decision
- **(2) Functional requirement.** No call leaves the host unless every lock holds: registered operation, global switch, enabled account, held capability and, for a write, the environment switch, the account's write permission and a recorded approval; locks are re-read before each call; the channel and Odoo switch families are independent.
- **(3) Exists now in code (main).** In origin/main: ChannelHttp::assertAllowed enforces the chain and re-reads the five account switches before each call; SALES_CHANNELS_ENABLED, SALES_CHANNELS_EXTERNAL_WRITES_ENABLED and four ODOO_* switches ship off in separate families; money-moving approvals are human-only; listing publish, managed dispatch, buyer-data, shipment discovery and Amazon feed/reconciliation switches ship off.
- **(4) Done in the draft PRs.** Nothing.
- **(5) What remains.** (1) A per-market stop: deactivating or pausing a country must stop its channel accounts' calls (today only global or per account). (2) Pin the shipped default of five unpinned switches (listing publish, managed dispatch, buyer-data, shipment discovery, listings reconciliation). (3) Decide what a paused storefront does to channel stock publishing.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` ChannelHttpTest, EverythingStartsOffTest, OdooSwitchesTest (PR 83 head 8ff3410: Tests SQLite 8.2+8.3; MySQL 8.0+MariaDB 11.4.2 sales-channel/cost jobs (A-M); OdooSwitchesTest in the costs group)
  - `none` No test pins the shipped default of the five switches above, or a per-market stop
- **(8) Dependencies and blockers.** H2-06 (country on the account) before a per-market stop can be built. What pausing means (GCC-12) is the storefront-launch unit's.
- **(9) Acceptance criteria.**
  - [MET] Every global master switch (channels, external writes, four Odoo) ships off (EverythingStartsOffTest::test_every_master_switch_ships_off; OdooSwitchesTest::test_draft_creation_and_final_posting_are_separate_switches_and_both_ship_off; PR83 8ff3410 CI green)
  - [MET] A call needs registration, the global switch, an enabled account, a held capability and, for a write, an approval; a person's stop reaches a running job at its next call (ChannelHttpTest::test_switching_the_account_off_stops_the_next_call_of_a_run_that_is_already_going; PR83 8ff3410 CI green (A-M))
  - [MET] Turning channels on permits no Odoo call and turning Odoo on permits no channel call (OdooSwitchesTest::test_the_sales_channels_being_on_does_not_permit_a_single_odoo_call; PR83 8ff3410 costs group green)
  - [CODED] Listing publish, managed dispatch, buyer-data transfer, shipment discovery and listings reconciliation ship off (config/sales_channels.php defaults are false; only amazon.bulk_feeds_enabled has a shipped-default test)
  - [MISSING] Deactivating or pausing a country's storefront stops every call of that country's channel accounts (nothing under app/Services/SalesChannels reads storefront activation (PR 83 tree); STOREFRONT_FLAG_RUNBOOK: channel order materializer and webhooks are not behind the sale gate)
- **(10) Next coding task.** Branch from main: add shipped-default assertions for the five unpinned switches to EverythingStartsOffTest (red-first). After H2-06: a market gate in ChannelHttp::assertAllowed refusing accounts whose storefront country is not active (allow when no country is set), with per-country tests.
- **Open owner decisions.** What pausing a storefront does to its channel accounts (stock, prices, orders); orders and webhooks must keep being received (GCC-12).

#### H2-13. Dry run (rehearsal) for every outward write

- **State: MERGED** | plan lines 443 | DECISIONS guardrail 1; E12 section 2.8 | reviews: none
- **(2) Functional requirement.** A new account or Odoo connection rehearses: the exact request is logged and nothing is sent even with every switch on, until a person turns dry run off with an explicit confirmation; a running job becomes a rehearsal the moment dry run is turned back on.
- **(3) Exists now in code (main).** In origin/main: dry_run defaults true on accounts and on a new Odoo connection; the transport returns a rehearsal for any write; the stock and price publisher, shipment notifier, return decisions, package splits, Amazon preview and Odoo create honour it; turning it off needs a confirmation in the panel.
- **(4) Done in the draft PRs.** Nothing.
- **(5) What remains.** Nothing for coding. Documented limit: a rehearsal does not remember what it rehearsed, so each cycle logs the same requests again.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` ChannelHttpTest, StockPricePublisherTest, TrendyolProductCreateTest, AdminSalesChannelsAccountsTest, OdooSwitchesTest, OdooOutboxTest (PR 83 head 8ff3410: Tests SQLite 8.2+8.3; MySQL 8.0+MariaDB 11.4.2 sales-channel/cost jobs (A-M) and (N-Z) and (costs))
- **(8) Dependencies and blockers.** none
- **(9) Acceptance criteria.**
  - [MET] A write on a dry-run account is logged and not sent, even with writes on everywhere (ChannelHttpTest::test_a_dry_run_logs_the_request_it_would_have_made_and_sends_nothing_even_with_writes_off; PR83 8ff3410 CI green (A-M))
  - [MET] A new Odoo connection starts in dry run; a rehearsing connection posts and claims nothing (OdooSwitchesTest::test_a_rehearsing_connection_sends_no_write_even_with_everything_on; PR83 8ff3410 costs group green)
  - [MET] Stock and price publishing and product create rehearse and remember nothing as sent (StockPricePublisherTest::test_a_dry_run_account_only_rehearses_and_remembers_nothing; PR83 8ff3410 CI green (N-Z))
  - [MET] Going back to dry run turns the next write of a running job into a rehearsal (ChannelHttpTest::test_going_back_to_a_rehearsal_turns_the_next_write_of_a_running_job_into_a_rehearsal; PR83 8ff3410 CI green (A-M))
  - [MET] Dry run is turned off only with an explicit confirmation (AdminSalesChannelsAccountsTest::test_dry_run_is_turned_off_only_with_an_explicit_confirmation; PR83 8ff3410 CI green (A-M))
- **(10) Next coding task.** No code change. When H2-06 adds the country field, add one test that an account created for any of the six countries is disabled and rehearsing.
- **Verifier note (not applied automatically).** ac[0].e: The cited test is the writes-off variant (external_writes_enabled=false, write_enabled=false, no approvals), while the criterion says 'even with writes on everywhere'. Proposed: ChannelHttpTest::test_going_back_to_a_rehearsal_turns_the_next_write_of_a_running_job_into_a_rehearsal (writes allowed and approved, dry run then on: the next write is a rehearsal and one request is sent in all) and OdooSwitchesTest::test_a_rehearsing_connection_sends_no_write_even_with_everything_on; test_a_dry_run_logs_the_request_it_would_have_made_and_sends_nothing_even_with_writes_off covers the writes-off side; PR83 8ff3410 CI green (A-M, costs).

#### H2-14. Preflight checks before anything is switched on

- **State: MERGED** | plan lines 443 | DECISIONS guardrail 1 (expand and protect); E12 section 2.8 | reviews: none
- **(2) Functional requirement.** Before a write is approved or an entry mapped the system checks what it talks to by reading only: a channel connection test (granted and missing permissions, 30-day freshness), an Odoo preflight (version, transport, models, fields, company, currencies), listing readiness, and that migrations have run.
- **(3) Exists now in code (main).** In origin/main: each connector's testConnection; ChannelAccountService requires a successful test under 30 days old for a write approval; OdooPreflightRun reads models, required fields, company and currencies and refuses by name; listing requirements are read from the channel; every pipeline says 'not migrated' instead of failing; channels:check-platform-schema.
- **(4) Done in the draft PRs.** Nothing.
- **(5) What remains.** Nothing for the preserved preflights. A per-country launch checklist for channel accounts belongs to the storefront-launch unit (cross-axis notes).
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` AmazonConnectorTest connection cases, ChannelAccountServiceTest, OdooPreflightRunTest, AdminOdooScreensTest, ListingPublishingTest, EverythingStartsOffTest, UnmigratedModuleTest (PR 83 head 8ff3410: Tests SQLite 8.2+8.3; MySQL 8.0+MariaDB 11.4.2 sales-channel/cost jobs (A-M) and (costs))
- **(8) Dependencies and blockers.** none
- **(9) Acceptance criteria.**
  - [MET] A channel connection test reads only and lists granted and missing permissions; a write capability needs a recent proven test (AmazonConnectorTest::test_a_connection_test_reads_the_marketplaces_and_probes_each_permission; PR83 8ff3410 CI green (A-M))
  - [MET] The Odoo preflight reads only, names a missing model or field, and refuses several companies with none chosen (OdooPreflightRunTest::test_every_call_it_makes_is_a_read_and_the_write_switches_stay_off; PR83 8ff3410 costs group green)
  - [MET] A listing draft is checked against the requirements the channel returns; an unreadable list is not read as 'none' (ListingPublishingTest::test_a_channel_that_will_not_say_what_it_requires_is_not_read_as_requiring_nothing; PR83 8ff3410 CI green (A-M))
  - [MET] Every pipeline says 'not migrated' instead of failing when its tables are absent (EverythingStartsOffTest::test_every_new_pipeline_says_so_instead_of_failing_when_its_table_is_absent; PR83 8ff3410 CI green (A-M))
- **(10) Next coding task.** No code change. Hand the per-country channel-readiness list (account country and entity set, mapping approved, regulated rules loaded, decimals set, dry run on) to the storefront-launch unit once H2-06 to H2-11 exist.
- **Verifier note (not applied automatically).** tests[0].where: UnmigratedModuleTest sits in the N-Z partition (the filter is the first letter of the class name) but the group list omits it. Proposed: PR 83 head 8ff3410: Tests SQLite 8.2+8.3; MySQL 8.0+MariaDB 11.4.2 sales-channel/cost jobs (A-M), (N-Z: UnmigratedModuleTest) and (costs)

#### H2-15. Protection of channel and Odoo secrets

- **State: MERGED** | plan lines 443 | DECISIONS guardrail 1; docs/gcc/DEFERRED_RISKS.md DR-1 and DR-2 (open, owner-deferred) | reviews: security
- **(2) Functional requirement.** Credentials are entered only in the admin panel, stored encrypted per account, never rendered back, never in logs, config or responses; the log redactor covers every stored credential field including Odoo's positional API key.
- **(3) Exists now in code (main).** In origin/main: credentials in one encrypted blob per account (Crypt::encryptString), column hidden from serialisation, write-only forms; a test reads ChannelCredentialStore::SECRET_KEYS so a new credential field fails until masked; Odoo XML-RPC bodies log byte counts only; CI job 'No secret or personal data added' runs on every head.
- **(4) Done in the draft PRs.** Nothing.
- **(5) What remains.** Nothing in code. DR-1 (credentials tracked in the repository) and DR-2 (the live application key may be the example key) are owner-deferred and not remediated: stored credentials are only as safe as the application key. Only referenced here.
- **(6) PRs and commits.** none
- **(7) Tests.**
  - `ci` CredentialsNeverReachTheLogTest, SecretRedactorCoversEveryStoredSecretTest, ChannelAccountServiceTest, AdminOdooScreensTest, tests/Unit/Deploy/LeakGuardTest (PR 83 head 8ff3410: Tests SQLite 8.2+8.3; MySQL 8.0+MariaDB 11.4.2 sales-channel/cost jobs (A-M) and (N-Z) and (costs); job 'No secret or personal data added' success on 8ff3410)
- **(8) Dependencies and blockers.** DR-1 and DR-2 stay open by owner decision; the application key protects every stored credential.
- **(9) Acceptance criteria.**
  - [MET] A stored credential is encrypted, hidden from serialisation and never shown back on a screen (ChannelAccountServiceTest::test_credentials_are_stored_encrypted_and_never_leave_the_store; AdminOdooScreensTest::test_the_api_key_is_write_only_and_never_reaches_a_screen; PR83 8ff3410 CI green)
  - [MET] No credential of any channel or Odoo reaches the call log; the guard itself fails when one is written down (CredentialsNeverReachTheLogTest::test_the_guard_itself_fails_when_a_credential_is_written_down; PR83 8ff3410 CI green (A-M))
  - [MET] Every stored credential field name is masked by the redactor, so a new field fails the test until covered (SecretRedactorCoversEveryStoredSecretTest::test_every_credential_field_name_is_masked_wherever_it_appears; PR83 8ff3410 CI green (N-Z))
  - [MET] A change that adds a secret or personal data to the repository fails CI (CI job 'No secret or personal data added' success on PR83 8ff3410 (scripts/ci/leak-guard.php, tests/Unit/Deploy/LeakGuardTest.php))
- **(10) Next coding task.** No code change. Keep the rule that a new credential field joins ChannelCredentialStore::SECRET_KEYS in the same commit as its form field, so the redactor test covers it.
- **Open owner decisions.** DR-1 and DR-2 are decided: no rotation now, open and not remediated (DEFERRED_RISKS.md).

#### H2-16. No unintended publishing or sending to a channel, provider or ledger

- **State: IN PROGRESS** | plan lines 443 | DECISIONS guardrail 1; charter section 4; sales-channels listings.md and dispatch.md | reviews: none
- **(2) Functional requirement.** Nothing is published, shipped, confirmed or booked by accident: each outward effect needs its own opt-in and approval, an uncertain write is fenced before it leaves and no machine repeats it, and a product of one country cannot be published on another country's account.
- **(3) Exists now in code (main).** In origin/main: 11 ordered listing gates behind SALES_CHANNELS_LISTING_PUBLISH_ENABLED (off), and no price or quantity ever leaves the listing pipeline; stock and price publish only for listings opted in one by one and only a price a person set; managed dispatch needs three environment switches, a master-admin approval and provider gates; may_have_sent and may_have_posted fences are written before the request.
- **(4) Done in the draft PRs.** #76 (0ad49d2) names each product's country in the listing-link picker (a visual aid, not a refusal).
- **(5) What remains.** A refusal in the manual link, the listing draft and the stock/price publisher when a product's country differs from the account's country (needs the country on the account, H2-06). Nothing else for the Saudi baseline.
- **(6) PRs and commits.** [lavkoo#76](https://github.com/fullstackfull/lavkoo/pull/76) `0ad49d2`
- **(7) Tests.**
  - `ci` ListingPublishingTest, StockPricePublisherTest, ChannelDispatchTest, ChannelShipmentSendsTest, TrendyolReturnDecisionsTest, OdooOutboxTest and the concurrency tests (PR 83 head 8ff3410: Tests SQLite 8.2+8.3; MySQL 8.0+MariaDB 11.4.2 sales-channel/cost jobs (A-M) and (N-Z) and (costs) and (concurrency))
  - `none` No test refuses a link, listing or stock push across countries
- **(8) Dependencies and blockers.** H2-06 for the account country. No file here is touched by the security set.
- **(9) Acceptance criteria.**
  - [MET] With the host switch off nothing leaves; a listing create needs the product_sync capability and a recorded approval, and never sends a price or quantity (ListingPublishingTest::test_with_the_host_switch_off_nothing_leaves_the_host; PR83 8ff3410 CI green (A-M))
  - [MET] Stock and price publish only for listings a person opted in; a policy never opts one in (StockPricePublisherTest::test_an_account_policy_never_opts_a_listing_in; PR83 8ff3410 CI green (N-Z))
  - [MET] A write whose outcome is unknown is fenced before the request leaves and no machine repeats it (Odoo entries, shipments, return decisions, splits) (OdooOutboxTest::test_a_run_that_dies_after_odoo_receives_the_create_leaves_no_room_for_a_second_one; ChannelShipmentSendsTest::test_only_proof_clears_the_fence; PR83 8ff3410 concurrency group)
  - [MET] Managed dispatch of a channel order needs three environment switches, a master-admin approval, account settings and provider gates (ChannelDispatchTest::test_without_the_masters_approval_or_the_dispatch_capability_the_order_waits; PR83 8ff3410 CI green (A-M))
  - [MISSING] A listing, price or stock push is refused when the product's country differs from the account's country (ChannelProductController::link, ListingDraftService and StockPricePublisher read no country in the PR 83 tree; #76 only labels the picker)
- **(10) Next coding task.** After the country field of H2-06: one guard used by ChannelProductController::link, ListingDraftService and StockPricePublisher that refuses a product whose country differs from the account's when GCC_COUNTRY_SCOPED_CODES is on; six-country tests and a switch-off twin identical to today.

#### H2-17. One product on several marketplaces, its stock from the same fulfillment source

- **State: IN PROGRESS** | plan lines 258 | DECISIONS foundation (one fulfillment source per product per country; keep pools, reservations, outbox); E12-F03, E12-F04 | reviews: none
- **(2) Functional requirement.** A product can be listed on the storefront and on several marketplace accounts while its sellable quantity is computed once from one fulfillment source (provider shelf or own warehouse): channels together never show more than the pool's net, a sale on one lowers the others, channel-held stock stays separate.
- **(3) Exists now in code (main).** In origin/main: pools keyed by (location, product, variant) across accounts (PoolMembers, PoolNet, StockAllocator), per-location admission of each channel, FBA and FBN never pooled, channel holds subtracted from the storefront figure, outbox with quick-run reductions. Amazon FBM, Trendyol and (after admission) noon share one pool.
- **(4) Done in the draft PRs.** #72 and #76 narrow which products a channel listing is proposed for by the account's country (H2-06); no draft changes pools, locations or the publisher.
- **(5) What remains.** (1) The three inventory locations are global fixed rows without country or storefront: add the dimension so a pool never mixes two countries' sources (E12-F03). (2) Refuse linking a listing to a product of another country (H2-16). (3) The stock outbox and its lock are global (E12-F04). Fulfillment source assignment per product is unit C's.
- **(6) PRs and commits.** [lavkoo#72](https://github.com/fullstackfull/lavkoo/pull/72) `1c779d2`; [lavkoo#76](https://github.com/fullstackfull/lavkoo/pull/76) `0ad49d2`
- **(7) Tests.**
  - `ci` StockAllocationTest, SharedProviderStockTest, NoonPoolMembershipTest, StockPricePublisherTest, StockOutboxTest, PoolNetTest, tests/Feature/Concurrency/StockConcurrencyTest (PR 83 head 8ff3410: Tests SQLite 8.2+8.3; MySQL 8.0+MariaDB 11.4.2 sales-channel/cost jobs (N-Z) and (concurrency))
  - `none` No test for a country on a location or a cross-country pool
- **(8) Dependencies and blockers.** Needs unit C's decision on inventory locations per country and the country on the account (H2-06). No file here is touched by the security set.
- **(9) Acceptance criteria.**
  - [MET] Two accounts listing one product on one pool are never shown more in total than the pool's net (StockAllocationTest::test_two_accounts_on_one_pool_are_never_shown_more_in_total_than_the_net; StockConcurrencyTest (MySQL, MariaDB); PR83 8ff3410)
  - [MET] A sale on one channel lowers what every other channel and the storefront show in one quick run (StockAllocationTest::test_a_sale_on_one_channel_lowers_what_every_channel_is_shown_in_one_quick_run; PR83 8ff3410 CI green (N-Z))
  - [MET] Stock a channel holds itself (FBA, FBN) never joins the pool and a location admits each channel explicitly (NoonPoolMembershipTest::test_stock_the_channel_itself_holds_never_joins_the_pool_whichever_channel_holds_it; PR83 8ff3410 CI green (N-Z))
  - [MET] The same provider shelf feeds the storefront and the marketplaces with each unit counted once (SharedProviderStockTest::test_store_orders_and_channel_orders_both_come_off_and_neither_covers_for_the_other; PR83 8ff3410 CI green (N-Z))
  - [MISSING] An inventory location and its pool belong to one country storefront and refuse another country's listings (InventoryLocationService makes three global locations; PoolMembers::of has no country filter (PR 83 tree))
- **(10) Next coding task.** With unit C: additive migration with nullable storefront_country on inventory_locations (backfilled SA by an explicit command), filter PoolMembers::of by the account's country, and a test that a KW account's listing is not a member of an SA location's pool; PoolNet formula unchanged.
- **Open owner decisions.** Whether inventory locations are per country or global with a country filter (unit C's decision).

#### H2-18. Integration and pull-request dependency map (Report 07)

- **State: IN PROGRESS** | plan lines 615-623 | DECISIONS legacy references (old PRs #12 Amazon, #13 Trendyol, #14 Noon, #15 Odoo, #16 guards); OWNER_PR_GUIDE sections 4 and 8 | reviews: none
- **(2) Functional requirement.** One current map classifies Torod, M5AZN, MyFatoorah, Paymob, Tabby, Tamara, Amazon, Noon, Trendyol and Odoo as Implemented, Partially Implemented, Open PR, Planned or Unverified (code in main, legacy PR, production), and lists conflicts among open pull requests and the proposed merge order.
- **(3) Exists now in code (main).** On the handoff branch (not in main): Discovery report 06 section 5 table of 2026-11-12 with the three-way split and OWNER_PR_GUIDE sections 4 and 8 (stacks, pairwise conflicts, merge order of the 86 drafts). Amazon, Trendyol, Noon and Odoo are all code in main; no open PR exists for them.
- **(4) Done in the draft PRs.** No integration pull request exists for these four. The only open drafts touching them are #72 and #76 (country narrowing of matching); #83 is CI evidence only.
- **(5) What remains.** (1) Refresh the table at the 86-draft state with the plan's five labels (the Discovery table uses VERIFIED-in-lavkoo-main, present-partially, MISSING-in-lavkoo, UNKNOWN): Amazon and Trendyol Implemented (dormant, fixtures only), Noon Partially (no admin form, no listing read), Odoo Partially (no enqueue, no way to enable or approve, one company, 2 decimals, E12-F23), #72/#76 as Open PR. (2) Correct the stale lines: docs/sales-channels/README.md:3 (says Odoo is only an extension point and noon is not built at all), odoo-accounting.md:3 ('not built'), api-reference.md:52 (says claim approve/reject is not built; ChannelReturnDecisions builds it), account-handoff.md:5 (says nothing is merged to main) and the DynamicFulfillmentRoutingEngine docblock lines 29-30 (managed dispatch 'not available in this version'; DispatchGate and ChannelOrderMaterializer exist) (Discovery E12-F12, E13-F20). (3) Torod and M5AZN rows by H1 (the Discovery table has none); MyFatoorah, Paymob, Tabby, Tamara by D2 (the Discovery table has them).
- **(6) PRs and commits.** [lavkoo#72](https://github.com/fullstackfull/lavkoo/pull/72) `1c779d2`; [lavkoo#76](https://github.com/fullstackfull/lavkoo/pull/76) `0ad49d2`; [lavkoo#83](https://github.com/fullstackfull/lavkoo/pull/83) `8ff3410`
- **(7) Tests.**
  - `none` None: a document; its facts are the file and pattern triples listed
- **(8) Dependencies and blockers.** Rows for the other six integrations depend on units H1 and D2. Production state of every row is UNKNOWN until the authorized administrator reads the server (not done).
- **(9) Acceptance criteria.**
  - [MISSING] All ten integrations carry one of the five classifications with file and SHA evidence (Discovery 06-git-lineage-legacy-prs.md section 5 (2026-11-12): Amazon, Trendyol VERIFIED-in-main; Noon present-partially; Odoo VERIFIED (posting not run); Tabby, Tamara MISSING)
  - [CODED] The map separates code in main, legacy pull-request state and production state (same table: columns (a) code in main, (b) legacy PR, (c) production (UNKNOWN in every row))
  - [CODED] Conflicts among open pull requests and the proposed merge order are listed (OWNER_PR_GUIDE sections 4 and 8 (four conflicting pairs, the stacks); none involves channel or Odoo files)
  - [MISSING] The map is current at 2026-11-18 and names the open drafts that touch these integrations (the Discovery table predates the 86 drafts; #72 and #76 are absent from it)
- **(10) Next coding task.** Write docs/gcc/INTEGRATION_MAP.md on a docs branch from this tracker's H2 rows plus the H1 and D2 rows (five-way classification, three-way split, Open PR rows #72 and #76); fix the two stale lines in docs/sales-channels; link it from OWNER_PR_GUIDE and the next handoff.
- **Verifier note (not applied automatically).** remaining corrected by the verifier (applied above).

#### H2-V1. A fulfilment provider (Torod Point, M5AZN, own warehouse) and a sales channel are separate concepts:...

- **State: MERGED** | plan lines 258 | added by the independent verifier: the assessor of this unit omitted it | reviews: none
- **(2) Functional requirement.** A fulfilment provider (Torod Point, M5AZN, own warehouse) and a sales channel are separate concepts: a provider's shelf is offered to a channel only by an explicit per-location admission, and a channel order reaches a provider only through a recorded routing decision, never by the channel itself. (First clause of line 258, 'separate clearly between the fulfilment provider and the sales channel'; H2-17 covers only the shared-pool half.)
- **(3) Exists now in code (main).** In origin/main: InventoryLocation provider and publishable_channels; InventoryLocationService::ensureDefaults (noon not admitted); StockListingGate 'location_not_for_this_channel'; DynamicFulfillmentRoutingEngine; DispatchGate. Pinned by NoonPoolMembershipTest, SharedProviderStockTest, RoutingEngineTest::test_a_rule_that_names_a_provider_which_does_not_hold_the_goods_does_not_send_them_there and ::test_an_order_the_channel_ships_itself_is_never_given_to_a_provider_whatever_the_rules_say, ChannelDispatchTest. CI as H2-17 and H2-16 (N-Z, A-M and concurrency jobs green on PR 83 8ff3410). Per-country provider and source assignment stays with unit C.
- **(4) Done in the draft PRs.** See the evidence note above.
- **(5) What remains.** Its acceptance criteria are not written yet; until they are, it counts as one criterion.
- **(6) PRs and commits.** none
- **(7) Tests.** none
- **(8) Dependencies and blockers.** See the evidence note.
- **(9) Acceptance criteria.**
  - [MET] A fulfilment provider (Torod Point, M5AZN, own warehouse) and a sales channel are separate concepts: a provider's shelf is offered to a channel only by an expli (one placeholder criterion for a requirement found by the verifier; write the real ones before relying on this line)
- **(10) Next coding task.** Write its acceptance criteria and its next coding task (added by the verifier).

#### H2-V2. Listing content follows each marketplace's own requirements per market: the store's central categori...

- **State: IN PROGRESS** | plan lines 439 | added by the independent verifier: the assessor of this unit omitted it | reviews: none
- **(2) Functional requirement.** Listing content follows each marketplace's own requirements per market: the store's central categories and brands map to each channel's categories, product types and brand ids per (channel, market), instead of manual entry per draft (the 'comply with market requirements' clause; Discovery E12-F19 and the GCC-08 and GCC-18 rows, classified New).
- **(3) Exists now in code (main).** Some code: DescribesListingRequirements reads each channel's requirements, Trendyol ResolvesChannelBrands and ListingDraftService brand resolution work per account, and the draft takes a product_type typed per draft. Missing: no category or brand mapping table or service in origin/main (git grep for category_map, channel_categor, category_mapping, marketplace_categor in app and database finds none); no test. Not in any H2 requirement.
- **(4) Done in the draft PRs.** See the evidence note above.
- **(5) What remains.** Its acceptance criteria are not written yet; until they are, it counts as one criterion.
- **(6) PRs and commits.** none
- **(7) Tests.** none
- **(8) Dependencies and blockers.** See the evidence note.
- **(9) Acceptance criteria.**
  - [MISSING] Listing content follows each marketplace's own requirements per market: the store's central categories and brands map to each channel's categories, product type (one placeholder criterion for a requirement found by the verifier; write the real ones before relying on this line)
- **(10) Next coding task.** Write its acceptance criteria and its next coding task (added by the verifier).
