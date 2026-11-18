# Merge order and conflict map of the open pull requests

Generated 2026-11-18 from the heads of the open pull requests of `fullstackfull/lavkoo` by dry-run merges (`git merge-tree`, nothing checked out, nothing written to a branch) of **every pair**: 88 pull requests, 3828 pairs, 126 of which conflict textually. It is the answer to "in which order, and what meets what"; it does not replace the tests: a pair that merges cleanly can still break a guard written for the other (the semantic conflicts are in [`audits/2026-11-18-all-in-one-integration/README.md`](audits/2026-11-18-all-in-one-integration/README.md)).

Left out on purpose: #75 (CI evidence of the security set), #83 (CI evidence of the GCC stack); and the baseline hotfix line (#78, #81, #85, #86), which is cut from the server baseline `698aa1e` and deployed in stages, never merged with the main-based set (see `SECURITY_RELEASE_PLAN.md`). **Merge none of the CI evidence or deployment branches.**

## 1. Reading the conflicts

- 126 pairs conflict; **99 of them are inherited**: a pull request stacked on another one conflicts with whatever its base conflicts with, in the same files. They need no second look: settling the lower pair settles them.
- **27 root pairs** conflict in their own right (section 4). The pull request merged second settles the pair. Every one of them was settled once in the all-in-one integration branch (`gcc/release/all-integration-ci` and its base) or in the integration of the security set, and the commit that did it is named, so the owner can see the resolution and the tests that followed.
- Everything not in section 4 merges cleanly with everything else, in any order that respects the stacks of section 2.

## 2. Stacks (what to merge first)

A stacked pull request has its base branch set to another pull request's branch, so GitHub shows only its own commits; **merge the base first**. Depth 0 is based on `main`.

| Depth | Pull request | Based on | Group | Change |
|---:|---|---|---|---|
| 0 | [#1](https://github.com/fullstackfull/lavkoo/pull/1) | `main` | P0 wallet, orders, identity and tests | Saudi baseline: test-only repair of Torod shipment discovery fixture (draft) |
| 0 | [#2](https://github.com/fullstackfull/lavkoo/pull/2) | `main` | P0 wallet, orders, identity and tests | Admin coupon minimum message: use the translation key the files define (draft) |
| 0 | [#3](https://github.com/fullstackfull/lavkoo/pull/3) | `main` | P0 wallet, orders, identity and tests | Remove two dead duplicate class declarations and guard against new ones (draft) |
| 0 | [#4](https://github.com/fullstackfull/lavkoo/pull/4) | `main` | P0 wallet, orders, identity and tests | Coupon minimum boundary: customer channels, eligible subtotal at least min_purchase (test-only,  |
| 0 | [#5](https://github.com/fullstackfull/lavkoo/pull/5) | `main` | P0 wallet, orders, identity and tests | Define two coupon refusal messages that were written into language files at runtime (draft) |
| 0 | [#6](https://github.com/fullstackfull/lavkoo/pull/6) | `main` | P0 wallet, orders, identity and tests | Saudi wallet: characterize the customer wallet ledger (test-only, draft) |
| 0 | [#7](https://github.com/fullstackfull/lavkoo/pull/7) | `main` | P0 wallet, orders, identity and tests | read Paymera header secret from config; stop logging the callback |
| 0 | [#8](https://github.com/fullstackfull/lavkoo/pull/8) | `main` | GCC storefronts and staff | storefront country resolution behind a default-off flag |
| 0 | [#9](https://github.com/fullstackfull/lavkoo/pull/9) | `main` | P0 wallet, orders, identity and tests | serialise customer wallet writes behind a row lock |
| 0 | [#16](https://github.com/fullstackfull/lavkoo/pull/16) | `main` | P0 wallet, orders, identity and tests | scope signed-in customer order access to registered orders |
| 0 | [#17](https://github.com/fullstackfull/lavkoo/pull/17) | `main` | P0 wallet, orders, identity and tests | receive a return once and restock its variant |
| 0 | [#18](https://github.com/fullstackfull/lavkoo/pull/18) | `main` | P0 wallet, orders, identity and tests | cover generateOrder stock refusal and lock order |
| 0 | [#20](https://github.com/fullstackfull/lavkoo/pull/20) | `main` | P0 wallet, orders, identity and tests | scope seller API order and refund lookups to the seller's own orders |
| 0 | [#21](https://github.com/fullstackfull/lavkoo/pull/21) | `main` | P0 wallet, orders, identity and tests | edit-order due payments act only for the order's owner or its guest order |
| 0 | [#22](https://github.com/fullstackfull/lavkoo/pull/22) | `main` | P0 wallet, orders, identity and tests | web due payments act only for the order's owner or a verified tracking session |
| 0 | [#23](https://github.com/fullstackfull/lavkoo/pull/23) | `main` | P0 wallet, orders, identity and tests | request identity comes only from the guard, the request's resolver or the session |
| 0 | [#24](https://github.com/fullstackfull/lavkoo/pull/24) | `main` | P0 wallet, orders, identity and tests | characterize stock and hold behaviour, four findings pinned as they are |
| 0 | [#25](https://github.com/fullstackfull/lavkoo/pull/25) | `main` | P0 wallet, orders, identity and tests | characterize the referral reward writers and pin their defects as they run today |
| 0 | [#26](https://github.com/fullstackfull/lavkoo/pull/26) | `main` | P0 wallet, orders, identity and tests | characterize who may change order status and decide refunds on each surface |
| 0 | [#27](https://github.com/fullstackfull/lavkoo/pull/27) | `main` | P0 wallet, orders, identity and tests | refuse rejected, pending and suspended seller tokens on v2 refunds |
| 0 | [#29](https://github.com/fullstackfull/lavkoo/pull/29) | `main` | P0 wallet, orders, identity and tests | grant the first-order referral reward at most once |
| 0 | [#30](https://github.com/fullstackfull/lavkoo/pull/30) | `main` | P0 wallet, orders, identity and tests | refuse a non-positive wallet paid amount in the v3 seller POS |
| 0 | [#31](https://github.com/fullstackfull/lavkoo/pull/31) | `main` | P0 wallet, orders, identity and tests | characterize seller withdraw creation boundaries on v3 and v2 |
| 0 | [#32](https://github.com/fullstackfull/lavkoo/pull/32) | `main` | P0 wallet, orders, identity and tests | scope seller withdraw updates and closes to the owning seller |
| 0 | [#33](https://github.com/fullstackfull/lavkoo/pull/33) | `main` | P0 wallet, orders, identity and tests | close withdraw requests on POST with CSRF instead of GET |
| 0 | [#34](https://github.com/fullstackfull/lavkoo/pull/34) | `main` | P0 wallet, orders, identity and tests | characterize checkout and POS wallet debit boundaries |
| 0 | [#40](https://github.com/fullstackfull/lavkoo/pull/40) | `main` | security | unpublished business pages no longer leak through source=admin |
| 0 | [#41](https://github.com/fullstackfull/lavkoo/pull/41) | `main` | security | unpublished posts and draft copies are no longer public |
| 0 | [#44](https://github.com/fullstackfull/lavkoo/pull/44) | `main` | security | public seller registration cannot approve itself, a seller who is not approved has no session, a |
| 0 | [#45](https://github.com/fullstackfull/lavkoo/pull/45) | `main` | security | social login confirmation needs the provider email of this session |
| 0 | [#46](https://github.com/fullstackfull/lavkoo/pull/46) | `main` | security | searches only return products a customer may see |
| 0 | [#51](https://github.com/fullstackfull/lavkoo/pull/51) | `main` | security | only the panels see unapproved reviews, not pages that start with the same letters |
| 0 | [#52](https://github.com/fullstackfull/lavkoo/pull/52) | `main` | security | the seller APIs act only on the seller's own products and reviews |
| 0 | [#54](https://github.com/fullstackfull/lavkoo/pull/54) | `main` | security | a cart row can only be changed by the customer it belongs to, into a variation of its own produc |
| 0 | [#55](https://github.com/fullstackfull/lavkoo/pull/55) | `main` | fixes | the first order of a referred customer no longer fails after it is committed |
| 0 | [#56](https://github.com/fullstackfull/lavkoo/pull/56) | `main` | fixes | a payment is settled for the payer it names, and leaves the process as it found it |
| 0 | [#57](https://github.com/fullstackfull/lavkoo/pull/57) | `main` | security | an admin edits only their own profile, never the account named in the URL |
| 0 | [#58](https://github.com/fullstackfull/lavkoo/pull/58) | `main` | security | a seller's password reset names one seller by equality, and only the server can issue the proof |
| 0 | [#59](https://github.com/fullstackfull/lavkoo/pull/59) | `main` | security | the customer web sign-in pages act only for the phone or account this browser verified |
| 0 | [#60](https://github.com/fullstackfull/lavkoo/pull/60) | `main` | security | the REST existing-account-check needs the token the social login gave, not just an email |
| 0 | [#61](https://github.com/fullstackfull/lavkoo/pull/61) | `main` | security | the vendor panel's shop pages act on the signed-in seller's shop, not on an id from the request |
| 0 | [#62](https://github.com/fullstackfull/lavkoo/pull/62) | `main` | security | customer and delivery-man password resets and order tracking name one account by equality, and o |
| 0 | [#63](https://github.com/fullstackfull/lavkoo/pull/63) | `main` | security | uploads are stored under a data extension, an SVG is never an image, stored names are plain, and |
| 0 | [#65](https://github.com/fullstackfull/lavkoo/pull/65) | `main` | security | the AI settings, the VAT and tax settings and the tax reports ask for the permission of their me |
| 0 | [#66](https://github.com/fullstackfull/lavkoo/pull/66) | `main` | security | three admin handlers use the list their own screen is built from |
| 0 | [#88](https://github.com/fullstackfull/lavkoo/pull/88) | `main` | P0 wallet, orders, identity and tests | E-06: wallet:reconcile, a read-only proof that wallet balances and loyalty points equal their le |
| 0 | [#89](https://github.com/fullstackfull/lavkoo/pull/89) | `main` | P0 wallet, orders, identity and tests | two checkout statements a strict SQL server refuses now name the column they group by |
| 0 | [#91](https://github.com/fullstackfull/lavkoo/pull/91) | `main` | docs and CI | the exact rule that protects main and the code owners, for a person to apply (documents only) |
| 0 | [#92](https://github.com/fullstackfull/lavkoo/pull/92) | `main` | docs and CI | every test starts from an empty database on a real server |
| 0 | [#93](https://github.com/fullstackfull/lavkoo/pull/93) | `main` | fixes | the per-order debug file that recorded customer, guest, address and order ids is no longer writt |
| 0 | [#94](https://github.com/fullstackfull/lavkoo/pull/94) | `main` | docs and CI | every test directory runs on a real MySQL or MariaDB, or says why it does not |
| 1 | [#10](https://github.com/fullstackfull/lavkoo/pull/10) | #9 | P0 wallet, orders, identity and tests | serialise point balance writes and make point-to-wallet conversion atomic |
| 1 | [#11](https://github.com/fullstackfull/lavkoo/pull/11) | #9 | P0 wallet, orders, identity and tests | refuse a debit that would take the balance below zero, inside the customer lock |
| 1 | [#19](https://github.com/fullstackfull/lavkoo/pull/19) | #8 | GCC storefronts and staff | add opt-in storefront middleware alias and per-country activation |
| 1 | [#36](https://github.com/fullstackfull/lavkoo/pull/36) | #34 | P0 wallet, orders, identity and tests | debit the USD order amount in whole cents in every store currency |
| 1 | [#67](https://github.com/fullstackfull/lavkoo/pull/67) | #65 | security | the admin routes that asked for no permission ask for the permission of their menu, part 2 (stac |
| 1 | [#68](https://github.com/fullstackfull/lavkoo/pull/68) | #52 | security | the AI helpers of the auction and product forms ask staff for their permission, and the barcode  |
| 1 | [#69](https://github.com/fullstackfull/lavkoo/pull/69) | #63 | security | paid digital files can be kept off the public disk and streamed through the checks the routes al |
| 2 | [#12](https://github.com/fullstackfull/lavkoo/pull/12) | #11 | P0 wallet, orders, identity and tests | checkout and return credit honour a refused wallet debit |
| 2 | [#13](https://github.com/fullstackfull/lavkoo/pull/13) | #11 | P0 wallet, orders, identity and tests | wallet POS sales and edited-order due payments are atomic and lock in product order |
| 2 | [#14](https://github.com/fullstackfull/lavkoo/pull/14) | #10 | P0 wallet, orders, identity and tests | run the wallet and loyalty tests on MySQL 8.0 and MariaDB 11.4.2 |
| 2 | [#15](https://github.com/fullstackfull/lavkoo/pull/15) | #10 | P0 wallet, orders, identity and tests | characterize refund, loyalty formula and idempotency guards |
| 2 | [#28](https://github.com/fullstackfull/lavkoo/pull/28) | #19 | GCC storefronts and staff | X-Storefront header and public storefront list, Saudi-only while off |
| 2 | [#70](https://github.com/fullstackfull/lavkoo/pull/70) | #67 | security | the classic admin header search drops what the employee's role does not hold, and its recent sea |
| 2 | [#73](https://github.com/fullstackfull/lavkoo/pull/73) | #67 | security | the module middleware takes a list of permissions, and the routes several screens call ask for t |
| 3 | [#35](https://github.com/fullstackfull/lavkoo/pull/35) | #13 | P0 wallet, orders, identity and tests | charge the seller app POS sale its server total, in the store currency |
| 3 | [#37](https://github.com/fullstackfull/lavkoo/pull/37) | #28 | GCC storefronts and staff | scope storefront-facing caches by market, bind the storefront on product, theme and page routes |
| 4 | [#38](https://github.com/fullstackfull/lavkoo/pull/38) | #37 | GCC storefronts and staff | GCC-11 legal readiness per storefront, not enforced |
| 5 | [#39](https://github.com/fullstackfull/lavkoo/pull/39) | #38 | GCC storefronts and staff | list whether each storefront may sell yet (GCC-11) |
| 6 | [#42](https://github.com/fullstackfull/lavkoo/pull/42) | #39 | GCC storefronts and staff | new-sale routes refuse a storefront that may not sell yet (GCC-11, default off) |
| 7 | [#43](https://github.com/fullstackfull/lavkoo/pull/43) | #42 | GCC storefronts and staff | record explicit legal acceptances against the text a customer saw (GCC-11, test-only) |
| 8 | [#47](https://github.com/fullstackfull/lavkoo/pull/47) | #43 | GCC storefronts and staff | products belong to one country, and a storefront shows only its own (GCC-02/03, default off) |
| 9 | [#48](https://github.com/fullstackfull/lavkoo/pull/48) | #47 | GCC storefronts and staff | a cart only holds and changes the rows of its storefront; by-id reads, banners and search follow |
| 10 | [#49](https://github.com/fullstackfull/lavkoo/pull/49) | #48 | GCC storefronts and staff | the web checkout and web payment routes refuse a storefront that may not sell (GCC-11, default o |
| 11 | [#50](https://github.com/fullstackfull/lavkoo/pull/50) | #49 | GCC storefronts and staff | caches built from a storefront's products are kept per storefront (GCC-02/03, default off) |
| 12 | [#53](https://github.com/fullstackfull/lavkoo/pull/53) | #50 | GCC storefronts and staff | a payment is finished in the storefront it was started in, and the flag runbook (GCC-02/03, defa |
| 13 | [#64](https://github.com/fullstackfull/lavkoo/pull/64) | #53 | GCC storefronts and staff | the admin chooses, shows and filters a product's country (GCC-14a, off by default) |
| 14 | [#71](https://github.com/fullstackfull/lavkoo/pull/71) | #64 | GCC storefronts and staff | platform admin API level 1, a read-only monitor for the platform owner (GCC-20, off by default;  |
| 14 | [#72](https://github.com/fullstackfull/lavkoo/pull/72) | #64 | GCC storefronts and staff | a SKU or barcode is unique inside a country, not across all of them (GCC-14b slice 1, switch off |
| 14 | [#74](https://github.com/fullstackfull/lavkoo/pull/74) | #64 | GCC storefronts and staff | staff country scopes for the product screens of the admin panel (GCC-14c slice 1, off by default |
| 14 | [#87](https://github.com/fullstackfull/lavkoo/pull/87) | #64 | GCC storefronts and staff | GCC-05 slices 1 to 4: a storefront trades in its own currency, its orders and payments respect i |
| 15 | [#76](https://github.com/fullstackfull/lavkoo/pull/76) | #72 | GCC storefronts and staff | GCC-14b slice 2: close the sites slice 1 left open, and prove the isolation between the six coun |
| 15 | [#77](https://github.com/fullstackfull/lavkoo/pull/77) | #74 | GCC storefronts and staff | GCC-14c slice 2: staff country scopes for the order, POS and refund screens of the admin panel ( |
| 15 | [#79](https://github.com/fullstackfull/lavkoo/pull/79) | #71 | GCC storefronts and staff | GCC-20 phase 2, delivery 1: second factor and session rules for the platform admin API (default  |
| 15 | [#90](https://github.com/fullstackfull/lavkoo/pull/90) | #87 | GCC storefronts and staff | GCC-13: the company that sells in each country is registered and stamped on the order (off by de |
| 16 | [#80](https://github.com/fullstackfull/lavkoo/pull/80) | #79 | GCC storefronts and staff | GCC-20 phase 2, delivery 2: grants that can end and be narrowed, a tamper-evident audit trail, i |
| 16 | [#82](https://github.com/fullstackfull/lavkoo/pull/82) | #77 | GCC storefronts and staff | GCC-14c slice 3: staff country scopes for the shared layout, the people, the dashboard, the repo |
| 17 | [#84](https://github.com/fullstackfull/lavkoo/pull/84) | #80 | GCC storefronts and staff | GCC-20 x GCC-14c: the platform admin API reaches the markets of the grant that the staff country |

## 3. Suggested waves

Each wave is dependency-valid in itself (merge its pull requests by depth, then number). The order between waves follows the owner guide: what closes a hole first, then money, then the storefronts.

- **Wave 1: the security set (merge and deploy together, see the owner guide):** #40, #41, #44, #45, #46, #51, #52, #54, #57, #58, #59, #60, #61, #62, #63, #65, #66, #67, #68, #69, #70, #73.
- **Wave 2: other fixes:** #55, #56, #93.
- **Wave 3: P0 wallet, orders, identity and the characterization tests:** #1, #2, #3, #4, #5, #6, #7, #9, #16, #17, #18, #20, #21, #22, #23, #24, #25, #26, #27, #29, #30, #31, #32, #33, #34, #88, #89, #10, #11, #36, #12, #13, #14, #15, #35.
- **Wave 4: the GCC storefronts, staff scopes, platform admin, GCC-05 and the selling-company registry (all off by default):** #8, #19, #28, #37, #38, #39, #42, #43, #47, #48, #49, #50, #53, #64, #71, #72, #74, #87, #76, #77, #79, #90, #80, #82, #84.
- **Anytime, first if possible: documents, the test base class and the CI coverage guard (#92 must come before the wallet and loyalty classes that rely on an empty database and conflicts with the storefront stack only on `tests/TestCase.php`, see section 4; #94 needs every pull request that adds a test directory to add its real-engine path, see its description):** #91, #92, #94.

## 4. The root conflicting pairs, and where each was settled

| Pair | Files | Settled in the all-in-one integration by |
|---|---|---|
| #1 with #4 | `CLAUDE.md` | `d4da4f63` (the merge of #4) |
| #5 with #41 | `resources/lang/en/new-messages.php` | `d582fee1` (the merge of #5) |
| #5 with #52 | `resources/lang/bd/new-messages.php`, `resources/lang/es/new-messages.php`, `resources/lang/in/new-messages.php`, `resources/lang/ks/new-messages.php`, `resources/lang/sa/new-messages.php` | `d582fee1` (the merge of #5) |
| #7 with #8 | `.env.example` | `0d6ab057` (the merge of #7) |
| #9 with #36 | `app/Utils/CustomerManager.php` | `4fe8e4ea` (the merge of #36) |
| #10 with #13 | `tests/Support/Concurrency/Operations.php` | `cc9529a0` (the merge of #13) |
| #12 with #36 | `app/Http/Controllers/RestAPI/v1/OrderController.php`, `app/Http/Controllers/Web/WebController.php`, `tests/Support/Concurrency/WalletCheckoutOperations.php` | `4fe8e4ea` (the merge of #36) |
| #12 with #69 | `app/Http/Controllers/Admin/Order/OrderController.php` | `7d353a9d` (the merge of #12) |
| #13 with #21 | `app/Http/Controllers/RestAPI/v1/OrderEditController.php` | `8b6fff75` (the merge of #21) |
| #13 with #30 | `app/Http/Controllers/RestAPI/v3/seller/POSController.php` | `cc9529a0` (the merge of #13); `756093d8` (the merge of #30) |
| #13 with #52 | `app/Http/Controllers/RestAPI/v3/seller/POSController.php` | `cc9529a0` (the merge of #13) |
| #13 with #87 | `app/Http/Controllers/RestAPI/v3/seller/POSController.php` | `cc9529a0` (the merge of #13); `8f3d0f38` (the merge of #87) |
| #20 with #52 | `app/Http/Controllers/RestAPI/v2/seller/RefundController.php`, `app/Http/Controllers/RestAPI/v3/seller/OrderController.php`, `app/Http/Controllers/RestAPI/v3/seller/RefundController.php` | `3c0671f5` (the merge of #20) |
| #22 with #62 | `app/Http/Controllers/Web/UserProfileController.php`, `tests/Feature/Address/StorefrontTrackOrderTest.php` | `83da41fa` (the merge of #22) |
| #23 with #56 | `app/Utils/module-helper.php` | `b0636c64` (the merge of #23) |
| #25 with #29 | `tests/Feature/Referral/ReferralRewardCharacterizationTest.php` | `f5a565f4` (the merge of #29) |
| #29 with #69 | `app/Http/Controllers/RestAPI/v3/seller/OrderController.php`, `app/Http/Controllers/Vendor/Order/OrderController.php` | `f5a565f4` (the merge of #29) |
| #32 with #52 | `app/Http/Controllers/RestAPI/v2/seller/SellerController.php`, `app/Http/Controllers/RestAPI/v3/seller/SellerController.php`, `app/Http/Controllers/Vendor/DashboardController.php` | `244c5361` (the merge of #32) |
| #36 with #69 | `app/Http/Controllers/RestAPI/v1/OrderController.php` | `4fe8e4ea` (the merge of #36) |
| #44 with #52 | `app/Http/Controllers/RestAPI/v2/seller/SellerController.php` | the integration of the security set (#75), `47b78ab8`: both versions of the return of `shop_info()` are kept |
| #47 with #92 | `tests/TestCase.php` | `1cb9e10b` (the merge of #92) |
| #48 with #54 | `app/Http/Controllers/RestAPI/v1/CartController.php`, `app/Utils/CartManager.php` | the base of the integration, `88d63c22` (security set with the GCC stack) |
| #53 with #56 | `app/Utils/module-helper.php` | the base of the integration, `88d63c22` (security set with the GCC stack) |
| #56 with #93 | `app/Utils/module-helper.php` | `657b26e7` (the merge of #93) |
| #63 with #72 | `app/Http/Controllers/RestAPI/v2/seller/ProductController.php`, `app/Http/Controllers/RestAPI/v3/seller/ProductController.php` | the base of the integration, `88d63c22` (security set with the GCC stack) |
| #71 with #87 | `bootstrap/app.php` | `8f3d0f38` (the merge of #87) |
| #71 with #90 | `.env.example` | `515d5bfa` (the merge of #90) |

## 5. Files that need a hand deployment

An overlay carries only `app`, `bootstrap`, `config`, `database`, `resources` and `routes`. A pull request that changes a file outside them (and outside tests, docs and CI files) needs that file deployed by hand, deliberately; the CI job "Deployment overlay" refuses such a release on purpose and is not bypassed.

| Pull request | Files outside the overlay directories |
|---|---|
| #41 | `Modules/Blog/app/Http/Controllers/Admin/BlogController.php`, `Modules/Blog/app/Http/Controllers/Web/FrontendBlogController.php`, `Modules/Blog/app/Services/Frontend/FrontendBlogService.php`, `Modules/Blog/resources/views/admin-views/blog/draft-edit.blade.php`, `Modules/Blog/routes/admin/routes.php` |
| #51 | `Modules/Blog/app/Models/Blog.php`, `Modules/Blog/app/Models/BlogCategory.php` |
| #52 | `Modules/AI/routes/vendor/routes.php`, `Modules/TaxModule/routes/vendor.php` |
| #63 | `.htaccess`, `Modules/AI/app/Services/AIContentGeneratorService.php`, `Modules/Blog/app/Http/Controllers/Admin/BlogDownloadAppController.php`, `public/assets/new/back-end/js/translate.js` |
| #65 | `Modules/AI/routes/admin/routes.php`, `Modules/TaxModule/routes/web.php` |
| #67 | `Modules/AI/routes/admin/routes.php` |
| #68 | `Modules/AI/routes/api.php`, `Modules/AI/routes/vendor/routes.php` |
| #69 | `.htaccess`, `public/assets/front-end/js/custom.js` |
| #82 | `Modules/TaxModule/app/Http/Controllers/Admin/Reports/AdminTaxReportController.php`, `Modules/TaxModule/app/Http/Controllers/Admin/Reports/VendorTaxReportController.php` |

## 6. Before the first merge

1. Read [`SERVER_PREFLIGHT.md`](SERVER_PREFLIGHT.md) with the administrator: the live values this plan cannot read (the currency model, the delivery-country restriction, the tax module, the enabled providers, the database engine) decide a few of the choices above.
2. After merging pull requests from two waves into `main`, apply the reconciliation commits named in the all-in-one report (tests and one guarded document, and three small code changes) so that the CI of `main` stays green.
3. The database migrations of the pull requests add nullable columns or tables and refuse a rollback that would lose data; rehearse them on a copy of the live database first. Nothing here runs a migration on production.

## 7. The two Flutter apps

Each app repository has its own stacks, read from the bases of its open pull requests (a pull request that is not stacked is based on `main`). Merging them releases nothing: a store build is a separate decision of the owner, and the storefront code is off by default in a build (`--dart-define=GCC_STOREFRONTS_ENABLED=true` turns it on). **Ship the customer app pull request #4 before the backend pull request #60** (the owner guide, section 1): an older app build only loses the one sheet that #60 changes.

| Repository | Depth | Pull request | Based on | Change |
|---|---:|---|---|---|
| `lavkooapp` | 0 | [#1](https://github.com/fullstackfull/lavkooapp/pull/1) | `main` | default-off X-Storefront header and six-storefront config |
| `lavkooapp` | 0 | [#4](https://github.com/fullstackfull/lavkooapp/pull/4) | `main` | the answer to "is this your account?" carries the token the server gave (security) |
| `lavkooapp` | 1 | [#2](https://github.com/fullstackfull/lavkooapp/pull/2) | #1 | read and save the chosen storefront only while storefronts are on |
| `lavkooapp` | 2 | [#3](https://github.com/fullstackfull/lavkooapp/pull/3) | #2 | browse-only storefronts, the sellable flag |
| `lavkooapp` | 3 | [#5](https://github.com/fullstackfull/lavkooapp/pull/5) | #3 | the app follows the server's "may this store sell yet" answer and refuses checkout |
| `lavkooapp` | 4 | [#6](https://github.com/fullstackfull/lavkooapp/pull/6) | #5 | cached answers keyed by storefront while storefronts are on |
| `lavkooapp` | 5 | [#7](https://github.com/fullstackfull/lavkooapp/pull/7) | #6 | storefront-aware caches, one change notifier and a country chooser |
| `lavkooapp` | 6 | [#8](https://github.com/fullstackfull/lavkooapp/pull/8) | #7 | J-05: prices of a storefront with its own currency written in that currency |
| `lavkooappseller` | 0 | [#1](https://github.com/fullstackfull/lavkooappseller/pull/1) | `main` | default-off market scope and X-Storefront header |
| `lavkooappseller` | 0 | [#3](https://github.com/fullstackfull/lavkooappseller/pull/3) | `main` | a refused order edit stays open and shows the server's message |
| `lavkooappseller` | 0 | [#4](https://github.com/fullstackfull/lavkooappseller/pull/4) | `main` | GCC-20: a read-only platform Super Admin shell (needs the security review before any release) |
| `lavkooappseller` | 1 | [#2](https://github.com/fullstackfull/lavkooappseller/pull/2) | #1 | a seller works in one GCC market at a time, only while storefronts are on |
| `lavkooappseller` | 1 | [#6](https://github.com/fullstackfull/lavkooappseller/pull/6) | #4 | GCC-20 step 2: second factor, capabilities and sessions (needs the security review) |
| `lavkooappseller` | 2 | [#5](https://github.com/fullstackfull/lavkooappseller/pull/5) | #2 | the point-of-sale scan reads the answers of the country-scoped codes |

## 8. Added after this list was generated (2026-11-18, afternoon)

The lists above were made from the pull requests that existed then (up to #90). What was found since, by merging the heads into the all-in-one integration (`gcc/release/all-integration-ci`, never merged), and what the later pull requests (#91 to #103) are stacked on. [`PR_LEDGER.md`](PR_LEDGER.md) has the base and head of each.

| Pull request | Based on | Order |
|---|---|---|
| #98 (tax characterization) | `main` | before #102 |
| #102 (the rows of `order_taxes` add up) | #98 | after #98; **awaits the finance review before any merge** ([R6](reviews/R6-legal-finance-gcc-money.md)) |
| #99 (a storefront's own tax, approved before it sells) | #87 | after #87; see the conflict below |
| #100 (a customer embedded in a payload carries no balances) | `main` | independent |
| #101 (a refund names the money and company of its order) | #90 | after #90 |
| #103 (a refund from the original payment, not adopted) | #101 | after #101 |
| #104 (the Saudi baseline as a named CI job) | #97 | after #97 |
| #105 (a market needs an independent legal review before it sells) | #99 | after #99; see the gate conflict below |
| #106 (one-time codes out of the log of `main`) | `main` | independent; the same lines are removed by stage 0 of the hotfix, which is not for `main` |

**Conflict by content, not by file: #99 with #90 and #101.** #99 and #90 both add a condition to `StorefrontSellingGate::maySell` (the tax decision; the registered company). They merge with one conflict in `app/Services/Storefront/StorefrontSellingGate.php`: keep both conditions and both constructor arguments (the integration does). With both in the tree, three tests of #90 (`LegalEntitySellingGateTest`) and three of #101 (`StorefrontRefundRecordTest`) must record a tax decision for the storefronts they open (`tests/Support/ApprovesStorefrontTax::approveNoTaxFor`, from #99): the integration carries that change in `2667bc39`, and **whichever of #90, #99 and #101 merges last carries it**.

**The gate has a third condition from #105.** #105 (stacked on #99) adds the independent legal review to the same `maySell` expression and constructor. Merged after #99 and #90 it conflicts in `StorefrontSellingGate.php` only in the docblock and in the list of conditions: keep all of them, in the order legal texts, legal review, currency, registered company, tax decision (the integration does, in `42c071bb`). The tests that open a storefront by publishing its legal texts (`BuildsStorefrontLegalDocuments::publishRequiredLegalDocuments`) now also record the review, so no other test file changes; the readiness tests that are about money or tax leave the legal texts out with `leaveTheLegalTextsOutOfThisTest()`.

**The repair of three readiness tests (#87, `fd40399c`) touches lines #99 already carries.** #99 holds its own copy of the same `Schema::dropIfExists('shipping_types')` line in `StorefrontCurrencyTest` and `StorefrontMoneyCommandTest`; merging #87's new head into #99 (and into #96 and the integration) conflicts only on the line that approves the tax there. Keep the tax line and the drop line once each (#99's head `9a57012e` and the integration do).

