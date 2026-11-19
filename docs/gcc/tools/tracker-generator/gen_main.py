#!/usr/bin/env python3
"""Writes docs/gcc/MASTER_IMPLEMENTATION_TRACKER.md and docs/gcc/tracker/*.md from the assessor and verifier results.

usage: gen_main.py <docs-gcc-dir> <asof-date>

It only reads the workflow journals and the git blobs named as evidence; it writes inside <docs-gcc-dir>.
"""
import collections
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import gen_tracker as T  # noqa: E402

EXTRA_WORKFLOWS = ['wf_715683c5-017']
PRIORITY = {'M1': 'P0 security and release', 'A1': 'P1 core', 'A2': 'P1 core', 'B': 'P1 core', 'C': 'P1 core', 'D1': 'P1 core', 'D2': 'P1 core', 'E': 'P1 core',
            'F': 'P2 admin, themes, marketing, integrations, shipping', 'G': 'P2 admin, themes, marketing, integrations, shipping',
            'H1': 'P2 admin, themes, marketing, integrations, shipping', 'H2': 'P2 admin, themes, marketing, integrations, shipping',
            'I1': 'P2 admin, themes, marketing, integrations, shipping', 'I2': 'P2 admin, themes, marketing, integrations, shipping',
            'J': 'P3 apps and GCC-20', 'K': 'P3 apps and GCC-20', 'L': 'P3 apps and GCC-20',
            'M2': 'P4 end to end and release readiness', 'M3': 'P4 end to end and release readiness'}
UPDATES = {
    'D1-06': 'PR 87 (GCC-05 slice 2, head 4af1c305): orders.storefront_country, currency_code and currency_rate are written by OrderStorefrontSnapshot right after the order lines, in OrderManager::generateOrder and the seller app POS; OrderStorefrontSnapshotTest (18) and StorefrontCheckoutSnapshotTest (10, a cash-on-delivery checkout from each of the six storefronts) pass on SQLite and the Storefront group on MySQL 8.0.46 (local runs; CI of the head not read). Order lines and money rows still carry no currency.',
    'D2-05': 'PR 87 (GCC-05 slice 3): GatewayCurrencyPolicy, asked in Payment::generate_link() before any payment row is written, refuses a gateway whose currency list lacks the storefront currency; a three-decimal currency (KWD, BHD, OMR) only goes to a gateway verified for it, and the verified list is empty. 19 tests (GatewayCurrencyPolicyTest). The capability registry per gateway, market and merchant account is still missing.',
    'D2-10': 'PR 87 (GCC-05 slice 3): the payment request carries the storefront currency and the refusal is decided before the row exists; the row still carries no market or company.',
    'B-03': 'PR 87 (GCC-05 slices 1 and 4): a non-default storefront shows its own currency symbol and decimals (getWebConfig seam) and the admin product list prints each row in its own country\'s currency; the apps and the exports still format with the platform settings.',
    'B-02': 'PR 87 (GCC-05 slice 4): an amount typed in the admin product form for a country is stored as typed in that country\'s currency (money.market middleware); bulk import and bulk price tools still use the platform settings.',
    'M1-03': 'The combined tree exists and its third run is green: gcc/release/security-gcc-integration-ci (f6fb03af) and gcc/release/all-integration-ci (82 of the 86 main-based pull requests, PR 87 to 94 and the reconciliation commits; 15 of 35 merges conflicted in 28 files; see audits/2026-11-18-all-in-one-integration). Run 279 (c30390d1) found test hygiene on real servers, a precision failure and two statements ONLY_FULL_GROUP_BY refuses (fixed in PR 89); all were reconciled on the integration branch only. Run 293 (head 8598331b, read job by job) finished success: 28 of 29 jobs green on SQLite 8.2 and 8.3, MySQL 8.0 and MariaDB 11.4.2 (Address, Catalog, the checkout, payment and permission directories, Fulfillment and security, Staff scope, Storefront, the five sales-channel and cost groups on each engine) and in the strict-mode job on MariaDB 11.4.2; one job is skipped by design (the overlay does not run on a dispatch). The integration has moved since: PR 87\'s later slices, PR 90\'s invoice step and PR 95 to 97 are in f70234e8 and not in that run. The five resolutions are not each pinned by a test yet.',
    'M2-15': 'Run 293 (8598331b) is green (see M1-03): the Catalog, Staff scope and Storefront groups, the twenty-three directories PR 94 names and the strict-mode job passed together in one tree on SQLite, MySQL 8.0 and MariaDB 11.4.2. PR 89 (the strict-SQL repairs), PR 92 (every test starts from an empty database on a real server) and PR 93 passed their own runs (291, 294 and 295: 20 of 21 jobs each, the overlay job red by design). The six-country journey matrix is not yet a CI group, and the run of PR 94 itself is still being read.',
    'H1-14': 'PR 87 (GCC-05 slice 5, head 5f685059): while a storefront\'s own currency is in force every read of ShippingType answers product_wise, so no platform shipping table (method, category or zone, all in the platform\'s money) reaches a total or a method prompt, and the in-house type is cached raw; the panel edit of an order prices its shipping in the order\'s own money. StorefrontShippingTypeTest (14, nine fail on the unchanged source) and StorefrontOrderEditMoneyTest (13, eleven fail unchanged); local runs, CI of that head not read. A storefront still has no shipping methods or tables of its own: its shipping is the cost typed on each product.',
    'H1-08': 'PR 87 (GCC-05 slice 5): the minimum order and the free-delivery threshold of a storefront with its own currency are its own (a business setting storefront_money_<country>, written only by gcc:storefront:money, neutral 0 when nothing is given, never the platform\'s riyal figure); StorefrontAmountSettingsTest (35) and StorefrontMoneyCommandTest (31); the gate proof (StorefrontMinimumOrderGateTest, 21, a real API checkout for each of the five storefronts) shows the minimum refusing, equal and above passing; the shipping cost itself is product-wise only there (see H1-14). Local runs on SQLite and MySQL 8.0.46 strict, CI of the head not read.',
    'M1-02': 'docs/gcc/MERGE_ORDER.md (docs branch) now covers lavkoo pull requests 1 to 93 with the depth of each base (generated from the heads by dry-run merges of every pair: 87 pull requests, 3741 pairs, 27 root conflicts, each with the commit where the all-in-one integration settled it), the waves, the files that need a hand deployment and, in section 7, the stacks of the two app repositories (lavkooapp #1 to #8, lavkooappseller #1 to #6). It is a document produced by a script; no test asserts it.',
    'M1-15': 'DEFERRED_RISKS.md now points DR-4 at docs/gcc/BRANCH_PROTECTION.md (draft PR 91) and DR-2 at SERVER_PREFLIGHT.md section E (docs branch, 954cfbac): the read-only checklist DR-2 referred to exists. The register is still not linked from the canonical documents on main.',
    'M1-16': 'PR 91 (draft, documents and CODEOWNERS only, 2a175311): docs/gcc/BRANCH_PROTECTION.md gives the exact ruleset for a person to apply (one approving review, code owners, approval of the last push, no force push) and a CODEOWNERS file; nothing is applied, main is unprotected and DR-4 stays open. The head moved only to carry the one-line fix of a clock-dependent test (the Torod discovery fixture) that made the SQLite jobs of every main-based branch without it red.',
    'M1-07': 'All of PR 7, 16, 20 to 23, 27 and 29 to 36 are merged into gcc/release/all-integration-ci with their conflicts against the security set and the GCC chain resolved (the resolutions and the tests that had to follow are written in the commit messages of that branch); the release path itself is still the staged hotfix of the baseline for the security set only.',
    'M2-07': 'PR 87 exists now (GCC-05 slices 1 to 4, 93 new tests including every storefront from SA to OM); the three-decimal tests for KWD, BHD and OMR are inside StorefrontCurrencyTest, OrderStorefrontSnapshotTest and GatewayCurrencyPolicyTest.',
    'A1-18': None,
    'A2-15': 'PR 93 (draft, main based, 5e128f06): the per-order debug file that recorded customer, guest, address and order ids is no longer written, and the CI of its head (run 295, read job by job) is green: 20 of 21 jobs, the overlay job red by design. Whoever operates a server that ran the old code should delete storage/logs/order_generate_test.txt. PR 100 (draft, main based, 9908240c): a customer embedded in an order, refund request, review, chat message, order transaction, restock request or support ticket is serialized without the wallet balance, loyalty points and referral code (App\\Traits\\HidesCustomerBalances on seven models, 12 tests, a source guard for every model with a customer() relation); the attributes stay readable and the customer\'s own payload keeps them. Account retirement, the seller customer search and the data export are untouched.',
    'M1-14': 'docs/gcc/STAGING_PLAN.md (docs branch, 53526c1e) fixes the environment, the invented data for six countries, the order in which the switches are turned on with the way back, the journey matrix S1 to S12 (which cell a test proves today, which needs a person) and the manual checks on both apps. Nothing is built or run: a staging reverses the pilot deployment text (the owner decides) and a seeder cannot be validated without the real schema.',
    'I1-12': 'PR 87 (GCC-05 slice 5, head ea8ed21c, off by default): coupons.storefront_country (NULL is the default storefront\'s coupon in the platform\'s money, a country code a coupon typed in that storefront\'s currency as typed, at rate one), the admin routes of coupons run under money.market:coupon (a new coupon in the money of the storefront its form names, an existing one in its own), CouponService stamps a new coupon, CouponAddRequest refuses a storefront with no active currency, and usdToDefaultCurrency stops losing a fils inside a storefront\'s money. StorefrontCouponAdminTest (25), StorefrontCouponEligibilityTest, CouponStorefrontMigrationTest and CouponFindersAreScopedTest pass on SQLite and MySQL 8.0.46; the CI of the head is not read. The admin form, list, quick view and export still show the platform currency.',
    'I1-13': 'PR 87 (GCC-05 slice 5, head ea8ed21c): a coupon belongs to one storefront and the eligibility lookups, the REST coupon list, applicable list and seller-wise list, my coupons, the home strip and the in-house coupons of the top vendors ask only for the storefront\'s coupons (a code of another storefront is no coupon); the reason shown for a refusal is still the generic invalid-coupon message. No product, category or brand rule yet.',
    'E-01': 'PR 87 (GCC-05 slice 5b, head 44b53616, off by default): while a storefront trades in its own currency the wallet is closed there (wallet_status and add_funds_to_wallet answer 0 through the seam, and the two checkout handlers that pay an order from the wallet sit behind storefront.wallet, 403 before anything is read or written), so a dinar order can never take riyals from the one balance. This is not a per-market wallet: the Saudi balance, the ledgers and the writers are unchanged. StorefrontWalletClosedTest (17).',
    'E-03': "PR 95 (draft, main based, 56a2280a): WalletBalanceWritersTest reads the source of app, Modules, routes and config and fails when code other than the four writers of users.wallet_balance and the two of users.loyalty_point writes either column; passes locally on main and on the integration tree, a temporary writer is caught. Run 307 on ce4d2950 was red on the SQLite jobs, the strict-mode job and the sales-channel jobs N to Z for one clock-dependent test that is not this pull request's (TorodShipmentDiscoveryTest: its fixture ingested an order at a fixed instant that is now older than the 36 hour window); the one-line fix that the other branches carry is cherry-picked on 56a2280a, CI of that head not read.",
    'M1-17': 'PR 97 (draft, main based, 177d80bc) adds golden answers of the checkout (cash on delivery, wallet, insufficient wallet, offline payment, the order list) recorded from main; the unchanged files pass on the integration tree on SQLite and MySQL 8.0.46 with and without strict mode. PR 104 (draft, stacked on PR 97, c1aac4f7) makes them a named CI job and refuses a pull request that changes one. CI of these heads not read.',
    'D1-05': 'PR 87 (GCC-05 slices 1 to 5, head 44b53616): a cart of a storefront with its own currency is priced, gated and saved in that currency at rate one: StorefrontMinimumOrderGateTest (21) drives a real API checkout for each of the five storefronts (a cart below the minimum its storefront was given is refused with 403 and saves no order, equal and above pass) and StorefrontCheckoutSnapshotTest saves each order with the price of its own catalog and its currency.',
    'D1-07': "PR 98 (draft, main based, 718edb99): OrderTaxRecordedAtPlacementTest pins what an order keeps of its tax on main and fixes the empty string written into the integer column taxable_id, which a strict SQL server refuses. PR 99 (draft, stacked on PR 87, f2942370, off by default): a storefront other than the default one is taxed by the active default setup whose country_code is its own and, when it has none, by nothing (a setup built in memory and never saved): the platform's Saudi setup is never used for it, whether or not the currency switch is on (StorefrontTax; the module trait and its twin helper, five reads of the panel order edit, the admin screen of the system tax setup and the module calculator never read a storefront's row as the platform's; one cache entry for each storefront). StorefrontTaxApproval and the selling gate: a storefront does not sell before a person with the authority has approved its setup (a fingerprint of the rates, type, included flag and shipping tax, stale when any of them changes) or recorded, in a document, that it charges no tax; gcc:storefront:tax-approve records, withdraws and shows the decision with an audit line (an approver that names a tool or an assistant is refused); the readiness report marks a storefront without one UNSAFE; 123 tests in the tax classes, a mutation of each mechanism fails the matching tests. PR 102 (draft, stacked on PR 98, d2d130f8, awaiting the finance review): the rows of order_taxes added up to a share of the order's tax in a cart of several lines (half in the case of two lines, a third of the shipping tax); new orders write rows that add up, orders already placed keep theirs, and orders:tax-rows-reconcile lists the orders whose rows differ without writing anything; 29 tests. Still missing: the tax of a tax-included price is recorded nowhere, and the rates, the model and the registration of each country, and who approves them, are the owner's.",
    'M1-13': "docs/gcc/REVIEW_GATES.md (docs branch) is the register of the reviews: six rows (finance for seller money access, security of GCC-20 on the server and in the seller app, security of hotfix stages 1 and 4, the native Arabic reader, legal and finance for the GCC money and the selling companies) with the pull requests, what to read first, the reviewer role, and an empty Requested and Outcome. Each review now has a short practical file in docs/gcc/reviews (R1 to R6: the pull requests and their heads, what changes for money or security, the evidence and its CI state, the questions the reviewer decides, an empty outcome table), and the register says who can be asked: nobody is named, the only collaborator of lavkoo is its owner, so no review has been requested; the owner is asked to name one person for each role. docs/gcc/ARABIC_TEXT_FOR_REVIEW.md is generated by docs/gcc/tools/list_arabic_strings.py: 80 lines with Arabic text in 10 pull requests of lavkoo, unchanged after pull requests 95 to 103 were opened, one table for each, listed once under the lowest number. No review has been requested or done; the apps' strings are not in the list.",
    'D1-10': "PR 101 (draft, stacked on PR 90, 1447d433): a refund request and a refund transaction copy from their order's own record the storefront, the currency, the rate and the selling company when the row is made (App\\\\Services\\\\Storefront\\\\RefundOrderMoney; migration 2026_12_03_100000: four nullable columns on both tables, a rollback refused while a refund carries a record; the customer API, the customer web and RefundTransactionService::getData ask it; RefundTransaction::$fillable names the columns). 16 tests, three mutations each caught. PR 103 (draft, stacked on PR 101, d100dd01, prepared and not adopted): RefundFromOriginalPayment works out what a refund gives back as its share of the original payment (the one paid gateway record of the order, else the order's own currency and rate), in the currency it was paid in, so the exchange rate is not an input; the refunds of an order add up to exactly what was paid (cumulative rounding in the decimals of the currency, three for the dinar and the rial), a refund that would take more than the order is capped, and the arithmetic is on exact decimals; refunds:preview-original-payment prints it next to today's conversion and writes nothing; no refund calls it (a test fails for any file that does); 26 tests. Still missing: the refund flow using it (the owner's policy across currency movements), the merchant account of the company (D2).",
    'D1-04': 'PR 87 and PR 90 (3eb54456): an order now carries storefront_country (GCC-05) and legal_entity_id with a frozen entity_snapshot (GCC-13), both separate from seller_id and shop_id; the key table and the guard test that no GCC code derives a country or company from seller_id or shop_id are still missing.',
    'M2-01': "PR 97 (draft, main based, 177d80bc): the golden answers of the Saudi checkout, recorded from main (SaudiGoldenResponsesTest and tests/Fixtures/SaudiGolden), pass unchanged on the all-in-one integration tree. PR 104 (draft, stacked on PR 97, c1aac4f7): they are a named CI job on both real engines ('Saudi baseline pack, MySQL 8' and 'Saudi baseline pack, MariaDB 11.4.2', a group of the tests-mysql matrix that runs the golden test file with phpunit.mysql.xml), and a job 'Saudi baseline expectations unchanged' fails a pull request that modifies, deletes or moves away an existing golden file (adding one is fine) unless its title carries [saudi-baseline-change], written by a person; SaudiBaselineJobTest (10) runs the guard against real git histories and reads the wiring, six mutations each fail it, the job's own command passes on MySQL 8.0.46; CI of these heads not read. It does not protect main by itself: a branch protection rule that requires these checks is the owner's (DR-4). Still missing: the structure-only copy of the Saudi schema (no authorised isolated copy).",
}
NEXT_CURRENT = {
    'M1-03': 'Read the CI of run 288 (all-integration-ci, 515d5bfa) per job; fix any red job on the integration branch only, or on the pull request that owns the defect; add the result to docs/gcc/audits/2026-11-18-all-in-one-integration/README.md; then run the six-country matrix on the three engines.',
    'M2-07': 'Extend PR 87 with slice 5 (the amount-bearing settings and coupons per storefront, then the tax and refund snapshot); see docs/gcc/GCC-05-STOREFRONT-CURRENCY.md.',
    'M2-15': 'Add the six-country matrix as a CI group on the all-in-one branch once run 288 is read.',
    'D1-06': 'Slice 5 of PR 87: currency columns on order lines and money rows (payments, refunds, transactions), invoices, e-mails and exports reading the order\'s own record, and the Saudi backfill after the production currency model is read by the administrator.',
    'M1-15': 'Link DEFERRED_RISKS.md from docs/gcc/README.md in the pull request that brings the documents to main (the docs branch is not a pull request and main is untouched).',
    'M1-02': 'Regenerate MERGE_ORDER.md whenever a pull request opens or moves; the dry runs of every pair are a script over the open heads.',
    'I1-12': 'Step 9 of the GCC-05 slice 5 plan on PR 87: the conditional country select and the currency labels on the admin coupon add form, the coupon list, quick view and export in the coupon\'s own money; then the round-trip matrix for KWD, BHD and OMR under the single and the multi-currency model.',
    'D1-07': "Read the CI of PR 99, PR 98 and PR 102 per job; the finance review of PR 102 (R6); with the owner's decision on whether a tax-included price must record its tax, compute and record it behind its own default-off switch (an additive record, not a change of the order's amounts); the owner gives each storefront its setup (gcc:storefront:tax) and a person approves it (gcc:storefront:tax-approve) before it opens.",
    'D1-10': "Read the CI of PR 101 and PR 103; with the owner's rule on refunds across currency movements, wire RefundFromOriginalPayment into the refund flow behind its own default-off switch, and route a refund through the merchant account of the company that took the payment once D2 has the accounts.",
    'M1-07': 'Read the CI of the all-in-one run 288; then decide with the owner which of these fixes join the staged hotfix as net changes (the method of the hotfix plan) and which wait for the merge of the main-based set.',
    'M2-01': 'Read the CI of PR 97 and PR 104 per job (the two named jobs and the guard job must appear and pass); the owner applies a branch protection rule that requires them (DR-4); the structure-only copy of the Saudi schema needs an authorised isolated copy.',
}
# Requirements that are inside a tree whose CI was green and read per job: every pull request they name has its current head in the tree of run 293 (head 8598331b, found by
# `git merge-base --is-ancestor` against the fetched pull request refs), they name no app pull request, and every criterion is MET. A requirement with a criterion that no test
# asserts, or one that is a document, is not listed: the gate says tested.
# A requirement that had no code when the assessors read it and has some now, with a criterion still missing.
STATE_AFTER = {'D1-10': 'IN PROGRESS'}
INTEGRATED_IN_RUN_293 = ['A1-07', 'E-06', 'E-09', 'E-10', 'G-03', 'G-04']
INTEGRATED_NOTE = ('Inside the all-in-one integration tree: every pull request this requirement names has its current head in the tree of CI run 293 (head 8598331b), which finished success '
                   '(28 of 29 jobs green on SQLite, MySQL 8.0, MariaDB 11.4.2 and the strict-mode job, one skipped by design, read job by job), and every criterion is MET. '
                   'See docs/gcc/audits/2026-11-18-all-in-one-integration/README.md, The third run.')
# A requirement whose state or criteria changed after the assessors read the code: only what a named head and a named test or job support.
OVERRIDES = {
    'E-06': {
        'state': 'IMPLEMENTED IN DRAFT',
        'links': [{'repo': 'lavkoo', 'pr': 88, 'sha': 'b5a3b5d1'}],
        'tests': [{'kind': 'feature', 'what': 'WalletReconcileCommandTest, 30 tests: equal balances, residue at the default and at a widened and a narrowed tolerance, unexplained differences fail the run, the evidence file, classes A, B and C, the loyalty ledger, repeated POS refunds, the legacy tables, debit matching over 405 customers, no personal data, refusals', 'where': 'tests/Feature/Fulfillment/WalletReconcileCommandTest.php; passed on SQLite and MySQL 8.0.46 locally and, in CI run 285 on b5a3b5d1, in the Fulfillment jobs on MySQL 8 and on MariaDB 11.4.2 and in both SQLite jobs (PHP 8.2 and 8.3)'}],
        'done_in_drafts': 'PR 88 (draft, main based, b5a3b5d1): php artisan wallet:reconcile compares users.wallet_balance with the wallet ledger and users.loyalty_point with the loyalty ledger in database decimals up to the highest ledger row at the start, classes A to F (only F fails the run, exit 1), an evidence file of user ids for class E, a tolerance that defaults to a millionth of a unit and is written into the report when widened, POS cancellations refunded more than once, wallet debits matched to the order group they paid (customer, amount, time), the legacy customer_wallets tables reported. The session is locked read-only and the report proves it; counts and sums only; refused in production without --allow-production-read-only and on a database other than --expect-database. Guide: docs/gcc/WALLET-RECONCILE.md.',
        'remaining': 'a run on a restored copy by a named operator (charter section 4); the storage unit of the old balances (E04-F02) is still unknown; the debit match uses customer, amount and time because the ledger holds no order id, so a converted or edited amount counts as unmatched; the legacy tables are reported, not reconciled.',
        'next_task': 'E-07 step 1: the expand-only per-market account tables behind GCC_MARKET_WALLETS, which may not backfill before a clean wallet:reconcile run exists on a restored copy.',
        'ac': {
            0: ('MET', 'BalanceReconciler compares both ledgers with the stored balances in database decimals; WalletReconcileCommandTest passes in CI run 285 on b5a3b5d1: Fulfillment on MySQL 8 and on MariaDB 11.4.2 and both SQLite jobs'),
            1: ('MET', 'classes A to F exist and only F fails the run; the evidence file makes class E; a test for each, passing in the same CI jobs'),
            2: ('MET', 'WalletPaymentMatcher matches by customer, amount and time (the ledger holds no order id, so no reference is used); unmatched debits and groups are counted; tests for the multi-order checkout, window, amount tolerance and 405 customers pass in the same CI jobs'),
            3: ('MET', 'the session is locked read-only and the report proves it; counts and sums only; refused in production without the flag and for another database; a test asserts no name, contact detail or note in the output (same CI jobs)'),
            4: ('MET', 'the legacy tables are reported with their row counts and sums; they are not part of the comparison and not declared frozen; tests for absent, empty and populated legacy tables pass in the same CI jobs'),
        },
    },
}

OVERRIDES['D1-03'] = {
    'state': 'IN PROGRESS',
    'links': [{'repo': 'lavkoo', 'pr': 90, 'sha': '26995055'}],
    'tests': [{'kind': 'feature', 'what': 'LegalEntityRegistryTest (20), OrderLegalEntitySnapshotTest (stamping for each of the six storefronts, written once, a later change never rewrites it, the switches, before either migration), LegalEntityCommandsTest (11), LegalEntitySellingGateTest (5)', 'where': 'tests/Feature/Storefront; 451 tests of the Storefront group on SQLite and 70 legal entity and snapshot tests on MySQL 8.0.46 strict, locally on 3eb54456; CI of that head not read'},
              {'kind': 'feature', 'what': 'OrderInvoiceIssuerTest (26: ten shapes of an order with no usable record, each of the six companies printed from the order\'s own copy, only the facts a company has, trimming, an order as an array, an object or with a record already decoded, a later change of the registry never reaching a stamped order, both switches, the partial\'s markup with and without a record, escaping) and, in the Address directory, three end-to-end cases each for the admin and vendor invoice (PanelInvoiceTest) and the customer invoice (StorefrontOrderPagesTest)', 'where': 'tests/Feature/Storefront and tests/Feature/Address: 62 tests pass locally on 26995055 on SQLite and on MySQL 8.0.46 with and without strict mode; 9 of them fail on the unchanged invoice views and three mutations fail 14, 2 and 3 of them; tests/Feature/Storefront, tests/Feature/Address and tests/Unit/Deploy (1,694 tests) pass on SQLite; CI of that head not read'}],
    'done_in_drafts': 'PR 43 adds the single global label gcc.storefronts.legal.legal_entity recorded on legal-acceptance rows; PR 71 shows the same label in the platform-admin markets list. PR 90 (draft, stacked on #87, 3eb54456) adds the registry: legal_entities (one row for each country: legal name, trade name, tax and commercial registration, invoice series key, address, active flag; ships empty), orders.legal_entity_id and orders.entity_snapshot (nullable, additive, rollbacks refused while data exists), LegalEntityRegistry and OrderLegalEntitySnapshot (asked from OrderStorefrontSnapshot, so every order path stamps the same thing; written once), the commands gcc:legal-entity:upsert and gcc:legal-entity:list, and an optional rule that a storefront without a company may not sell (GCC_LEGAL_ENTITIES_REQUIRED, off). Off by default behind GCC_LEGAL_ENTITIES. Step 2 (26995055): the header of the admin, vendor and customer invoice is one partial that prints the company frozen on the order (name, address, tax and commercial registration, each only when it has it) and nothing of the shop\'s own address or business identity beside it; an order with no usable record, and every order while legal entities are off, prints what it always printed.',
    'remaining': 'CI of the head; the invoice printing the currency the order was sold in and numbered per company with Saudi order numbers unchanged (the series needs the owner\'s rule); the POS receipts; POS and marketplace channel paths other than the seller app POS; per-company accounts (D2, E, H2); the companies themselves (owner and counsel); an admin screen; replacing the single label recorded on legal acceptances.',
    'next_task': 'Read the CI of PR 90 per job; then the owner\'s rule for the invoice number of each company (format, restart, gaps, tax authority) and the series on top of invoice_series_key; then the company on the payment request (D2-07).',
    'ac': {
        0: ('CODED', 'legal_entities has one row per country (unique) with the facts of the company; the storefront-to-company mapping is the country itself; tests pass locally, CI of the head not read'),
        1: ('CODED', 'orders.legal_entity_id and entity_snapshot are stamped at placement by OrderStorefrontSnapshot for every order path; OrderLegalEntitySnapshotTest; CI not read'),
        3: ('MET', 'StorefrontLegalAcceptanceTest::test_the_acceptance_names_the_legal_entity_from_configuration (unchanged)'),
    },
    'ac_split': {
        2: [
            ('The invoice prints the order\'s frozen entity identity and tax number, and an order with no record prints what it printed before', 'CODED', 'PR 90 (26995055): OrderInvoiceIssuer and partials/invoice/_issuer.blade.php, included by the admin, vendor and customer invoices; OrderInvoiceIssuerTest and nine end-to-end cases pass locally on SQLite and MySQL 8.0.46 (normal and strict), nine of them fail on the unchanged views; CI not read'),
            ('The invoice prints the currency the order was sold in', 'MISSING', 'the invoice still prints the amounts in the currency of the request that prints it (D1-06 slice 5 and the order\'s own record)'),
            ('The invoice is numbered per entity, with Saudi order numbers unchanged', 'MISSING', 'the invoice still carries the order id; the series key is registered and frozen on the order but no series exists, and the format is the owner\'s decision'),
        ],
    },
}

OVERRIDES['J-05'] = {
    'state': 'IN PROGRESS',
    'links': [{'repo': 'lavkooapp', 'pr': 8, 'sha': '97399d4f'}, {'repo': 'lavkooapp', 'pr': 9, 'sha': '45bfed30'}],
    'tests': [{'kind': 'unit', 'what': 'storefront_money_test (16: decimals of the six currencies, grouping, rounding, signs, a missing amount, the mark on either side, which storefronts it writes) and price_converter_storefront_test (6: the pins of the old output with storefronts off; the Kuwaiti, Emirati, Bahraini and Omani prices, an absent config and the default storefront with them on)', 'where': 'lavkooapp test/; CI job storefront-header of lavkooapp #8 on 97399d4f (run 44268303722): flutter test of the default build 428 passed, 20 skipped (the old-output pins run, the five flag-on cases skip) and flutter test --dart-define=GCC_STOREFRONTS_ENABLED=true 258 passed, 9 skipped (the five flag-on cases run); the two render-matrix tests that fail on the base are not in the CI list'},
              {'kind': 'unit', 'what': 'lavkooapp #9: five more cases in price_converter_storefront_test (the currency to send and to show: the chosen one with storefronts off, the storefront\'s own on Kuwaiti, Bahraini and Omani storefronts, the chosen one on the default storefront) and storefront_currency_sites_test (only the listed files may read the currency chosen in the settings; red on the code before the change, green after)', 'where': 'lavkooapp test/ on 45bfed30, the three commands of the repository workflow run locally with Flutter 3.47.0: analyze exit 0, default build 431 passed and 23 skipped, flag-on build 261 passed and 11 skipped; the whole suite 525 passed, 23 skipped, 2 failed (the two render-matrix tests, failing on the base too); CI of that head (run 44271553978): job storefront-header green in about four minutes'}],
    'done_in_drafts': 'lavkooapp #1 keeps the currency code in StorefrontCountry.currency (no code read it). lavkooapp #8 (draft, stacked on #7, 97399d4f) adds StorefrontMoney, a pure Dart writer: the decimals of the currency (ISO 4217: three for KWD, BHD, OMR, two for the rest), grouping, the mark of the server currency list on the side the config says, a space for a mark of letters. PriceConverter.convertPrice, convertPriceWithoutSymbol and getUnitCurrency use it only when the build has storefronts on and the customer is on a storefront other than the default one (a compile-time flag, so with it off nothing changes), and the exchange-rate currency choice is not offered there. lavkooapp #9 (draft, stacked on #8, 45bfed30) routes the eight other places that read the currency the customer chose (the code sent with an order, a wallet top-up and a due payment, three analytics events, the marks beside the top-up amount and the change amount) through four PriceConverter accessors that answer with the storefront currency when it has one, and a test that fails when a new file reads the chosen currency directly.',
    'remaining': 'The default storefront keeps whole numbers (two decimals there is a finance decision); symbol per language and position in Arabic (finance and a native reader); the loyalty points converter shows its conversion through the exchange rate of the chosen currency (loyalty per market is E); the CI of the app repository runs only the storefront test list and flutter analyze on the files it names, not the whole suite; a golden pack of the screens that show an amount; the backend /config answering symbol and decimals for the storefront asked. A survey of all 51 toStringAsFixed uses found that every amount already goes through PriceConverter (the others are ratings, health calculators, file sizes and loyalty points), so the earlier claim of 48 amount sites outside it was wrong; no test asserts that no screen formats an amount by itself.',
    'next_task': 'A golden pack of the customer screens that show an amount (cart, checkout, order details, wallet, coupons) for the six currencies with hand-written server answers, and a flutter test job over the whole suite once the two render-matrix tests are repaired (owner of that test); then J-07 and the storefront-aware config contract with the backend.',
    'ac': {
        0: ('MET', 'StorefrontMoney writes KWD, BHD and OMR with three decimals and the mark of the server list; storefront_money_test and price_converter_storefront_test pass in CI on 97399d4f (job storefront-header, both builds)'),
        1: ('MISSING', 'AE and QA print two decimals with their own mark (tested and green in CI); the default storefront SA keeps whole numbers by design until finance decides, so the criterion as written (SA, AE and QA two decimals) is not met'),
        2: ('MET', 'no exchange-rate multiplication and no currency choice on a storefront with its own currency while storefronts are on; the glue tests pass in the build with the flag on (CI, 97399d4f)'),
        3: ('CODED', 'every amount goes through PriceConverter (survey of all 51 toStringAsFixed uses at 45bfed30: the others are ratings, health, file sizes and loyalty points) and lavkooapp #9 (45bfed30, CI job storefront-header green in run 44271553978) routes the eight places that read the chosen currency; no test asserts that no screen formats an amount by itself'),
    },
}

# Single criteria whose status changed after the assessors read the code; the card keeps everything else. Only what a named head and test support.
CRITERIA = {
    'I1-12': {
        3: ('CODED', 'PR 87 (ea8ed21c): a coupon added for KW is stamped KW and stored as typed through the admin route (StorefrontCouponAdminTest::test_the_route_stores_a_kuwaiti_coupon_as_typed_and_a_saudi_one_converted), redeemed in dinars at checkout (StorefrontCouponEligibilityTest, the group-wise coupon priced as typed); the admin list, quick view and export still show the platform currency and the form has no country select; CI not read'),
        4: ('CODED', 'PR 87: the migration is additive and touches no row, NULL is the default storefront\'s coupon (CouponStorefrontMigrationTest, StorefrontCouponEligibilityTest: a coupon with no country is offered by the default storefront as before); passes locally on SQLite and MySQL 8.0.46, CI not read'),
    },
    'I1-13': {
        1: ('CODED', 'PR 87 (ea8ed21c): CouponCountry::narrow in both eligibility lookups; StorefrontCouponEligibilityTest::test_a_storefront_meets_the_coupon_of_another_by_code_as_no_coupon; the customer sees the generic invalid-coupon message, not a storefront-specific one; CI not read'),
        2: ('CODED', 'PR 87: Coupon::forStorefront() on the REST list, applicable list and seller-wise list (CouponFindersAreScopedTest at source level, StorefrontCouponEligibilityTest at model level); no HTTP-level test of the three endpoints; CI not read'),
    },
    'M2-15': {3: ('MET', 'run 293 on 8598331b: the security set, the GCC chain through PR 87 and the main-based set sit in one tree and the Catalog, Staff scope, Storefront and platform groups are green on MySQL 8.0 and MariaDB 11.4.2 and SQLite 8.2 and 8.3, with the strict-mode job on MariaDB 11.4.2'), 5: ('MET', 'PR 94 (draft, main based, a0289e75) adds CiEveryTestDirectoryRunsOnARealEngineTest: every test directory runs on a real MySQL or MariaDB or is listed as SQLite-only with a reason (red for a new directory and for a typo in the workflow, shown locally); on the all-in-one integration it names exactly the twenty-three directories no real-engine job ran, and the integration adds the group that runs them (8598331b). CI run 296 of PR 94 on a0289e75 (finished 12:23 UTC, read job by job): 20 of 21 jobs green, the SQLite jobs on PHP 8.2 and 8.3 where the guard runs among them, the overlay job red by design; and the integration run 293 passed with the group that runs the twenty-three directories on MySQL 8 and MariaDB 11.4.2')},
    'M2-01': {2: ('CODED', 'PR 97 (draft, main based, 177d80bc): SaudiGoldenResponsesTest compares the JSON answer and the rows left by a cash-on-delivery, a wallet, an insufficient-wallet and an offline-payment order, and the order list the app reads, with golden files recorded from main; the same files pass unchanged on the all-in-one integration tree (every switch at its default) on SQLite and on MySQL 8.0.46 with and without strict mode, and fail when the wallet debit changes by one unit; the payment start and the cart are not pinned; CI of the head not read'), 3: ('CODED', "PR 104 (draft, stacked on PR 97, c1aac4f7): 'Saudi baseline pack, MySQL 8' and 'Saudi baseline pack, MariaDB 11.4.2' are a group of the tests-mysql matrix running SaudiGoldenResponsesTest with phpunit.mysql.xml, and the job 'Saudi baseline expectations unchanged' runs .github/scripts/saudi-baseline-guard.sh, which fails a pull request that modifies, deletes or moves away an existing golden file unless its title carries [saudi-baseline-change]; SaudiBaselineJobTest (10) reads the wiring and runs the guard against real git histories, six mutations each fail it, the job's own command passes on MySQL 8.0.46 and the CI structure tests (28) pass on the integration tree; CI of the head not read; no branch protection rule requires the jobs yet (DR-4)")},
    'M2-04': {
        0: ('CODED', 'PR 96 (draft, stacked on #87, 6f55384e): CustomerAcrossStorefrontsTest shows the info endpoint answering the same id and e-mail from each of the six storefronts and no user created for a storefront; the sign-in with credentials is not exercised; passes locally on SQLite and on the integration tree, CI not read'),
        3: ('CODED', 'PR 96 (6f55384e): one order from each storefront and the order list answering the same six orders from each of the six storefront headers and from none (CustomerAcrossStorefrontsTest); passes locally on SQLite and on the integration tree, CI not read'),
    },
    'M2-02': {3: ('CODED', 'PR 96 (draft, stacked on #87, 08f58eb6): StorefrontSixCountryIsolationTest runs five isolation checks (catalog, cart add, two carts, price and stock answers, cache key) for each of the thirty ordered pairs of the six countries; 151 tests pass locally on SQLite and on the integration tree; 90 fail with the catalog restriction removed; CI of the head not read')},
    'M1-01': {1: ('CODED', 'docs/gcc/STATUS.md on the docs branch now opens with the current state (2026-11-18: autonomous implementation on draft pull requests, nothing merged or deployed, where to read) and keeps the 2026-11-12 discovery text as superseded history; STATUS.md on main is unchanged')},
    'M3-17': {4: ('CODED', 'docs/gcc/STATUS.md and docs/gcc/README.md on the docs branch now state the charter and mark the discovery-only wording historical; the three CLAUDE.md files and main\'s README and STATUS are untouched (main is not modified by the autonomous sessions)')},
    'E-03': {3: ('CODED', 'PR 95 (draft, main based, 56a2280a): WalletBalanceWritersTest fails when code other than the four writers of users.wallet_balance and the two of users.loyalty_point writes either column; passes locally on main and on the integration tree, a temporary writer is caught; CI of the head not read')},
    'D1-05': {4: ('CODED', 'PR 87 (44b53616): the cart gate, the checkout and the saved order of a storefront with its own currency read and keep that currency at rate one (StorefrontMinimumOrderGateTest 21, StorefrontCheckoutSnapshotTest 10); passes locally on SQLite and MySQL 8.0.46, CI not read')},
    'M1-02': {3: ('CODED', 'MERGE_ORDER.md orders every lavkoo pull request by the depth of its base and lists the two app stacks in section 7; generated by script from the heads, no test asserts it')},
    'M1-15': {2: ('CODED', 'DR-2 points to SERVER_PREFLIGHT.md section E and DR-4 to BRANCH_PROTECTION.md (draft PR 91); no test checks the links')},
    'D1-03': {
        0: ('MET', 'legal_entities has one row per country (unique) with the facts of the company, the storefront-to-company mapping is the country itself (PR 90, 26995055; the registry and command tests of tests/Feature/Storefront); run 302 on 26995055 (read job by job on 2026-11-18): 22 of 23 jobs success, the deployment-overlay job red by design; the Storefront tests (SQLite 8.2 and 8.3, MySQL 8, MariaDB 11.4.2) and the Address tests (MySQL 8, MariaDB 11.4.2) are among the green ones'),
        1: ('MET', 'orders.legal_entity_id and entity_snapshot are stamped at placement by OrderStorefrontSnapshot for every order path (OrderLegalEntitySnapshotTest, PR 90, 26995055); run 302 on 26995055 (read job by job on 2026-11-18): 22 of 23 jobs success, the deployment-overlay job red by design; the Storefront tests (SQLite 8.2 and 8.3, MySQL 8, MariaDB 11.4.2) and the Address tests (MySQL 8, MariaDB 11.4.2) are among the green ones'),
        2: ('MET', 'PR 90 (26995055): OrderInvoiceIssuer and partials/invoice/_issuer.blade.php, included by the admin, vendor and customer invoices; OrderInvoiceIssuerTest (26) and the nine end-to-end invoice cases (PanelInvoiceTest, StorefrontOrderPagesTest); run 302 on 26995055 (read job by job on 2026-11-18): 22 of 23 jobs success, the deployment-overlay job red by design; the Storefront tests (SQLite 8.2 and 8.3, MySQL 8, MariaDB 11.4.2) and the Address tests (MySQL 8, MariaDB 11.4.2) are among the green ones'),
    },
    'D1-04': {
        2: ('MET', 'orders carry storefront_country, currency_code and currency_rate (PR 87, OrderStorefrontSnapshot) and legal_entity_id and entity_snapshot (PR 90), separate columns from seller_id, seller_is and shop_id, which keep their legacy meaning (OrderStorefrontSnapshotTest, OrderLegalEntitySnapshotTest); run 302 on 26995055 (read job by job on 2026-11-18): 22 of 23 jobs success, the deployment-overlay job red by design; the Storefront tests (SQLite 8.2 and 8.3, MySQL 8, MariaDB 11.4.2) and the Address tests (MySQL 8, MariaDB 11.4.2) are among the green ones'),
    },
    'D1-07': {
        0: ('CODED', 'PR 98 (718edb99): OrderTaxRecordedAtPlacementTest asserts orders.tax_type, tax_model and total_tax_amount, and order_details.tax and tax_model, for an order-wise setup, a quantity of three, a product-wise setup and a tax-included setup; passes on SQLite and on MySQL 8.0.46 in normal and strict mode (strict on the integration); CI of the head not read'),
        1: ('CODED', 'PR 98: one order_taxes row for each rate with its name, type, rate, amounts, quantity, payer, seller and setup, for an order-wise and a product-wise setup (the per-rate amounts of a cart of two lines keep half of each line\'s tax: a finding, pinned and not changed); a test fails on main for the empty string written into taxable_id and passes with the fix; CI of the head not read'),
        2: ('CODED', 'PR 99 (draft, stacked on PR 87, a97c9392): StorefrontTax makes the checkout, the cart, the panel order edit, the default theme\'s order summary and the module calculator read the setup of the storefront\'s own country, or the platform\'s when it has none (StorefrontTaxSetupTest, 20 tests, mutated once for each mechanism); off by default; passes on SQLite and MySQL 8.0.46, CI not read'),
        4: ('MET', 'PR 90 (26995055): the order\'s entity_snapshot freezes the company\'s tax_registration_number with the rest of its identity (OrderLegalEntitySnapshotTest, OrderInvoiceIssuerTest), and the three invoices print it; run 302 on 26995055 (read job by job on 2026-11-18): 22 of 23 jobs success, the deployment-overlay job red by design; the Storefront tests (SQLite 8.2 and 8.3, MySQL 8, MariaDB 11.4.2) and the Address tests (MySQL 8, MariaDB 11.4.2) are among the green ones'),
    },
    'A2-15': {2: ('CODED', 'PR 100 (draft, main based, 9908240c): EmbeddedCustomerBalancesTest (12 tests) shows that an order, a refund request, a review, a chat message, an order transaction, a restock request and a support ticket serialize their customer without wallet_balance, loyalty_point and referral_code, two levels down too, while the attributes stay readable and the customer\'s own payload keeps them; mutations of the trait and of the list fail it; the endpoints themselves are not called (the model-level rule covers every payload that embeds the relation), CI not read'), 1: ('MET', 'PR 93 (draft, main based, 5e128f06) removes the write of customer, guest, address and order ids to storage/logs/order_generate_test.txt and adds a test that fails with the write and passes without it; the CI of the head (run 295) is green: 20 of 21 jobs including Address tests on MySQL 8 and MariaDB 11.4.2, Fulfillment and security on both, strict-mode on MariaDB and SQLite 8.2 and 8.3 (the overlay job is red by design)')},
    'M1-03': {
        2: ('MET', 'gcc/release/security-gcc-integration-ci (f6fb03af) and gcc/release/all-integration-ci (head 8598331b for run 293, f70234e8 now) exist on GitHub and are never merged'),
        3: ('MET', 'run 293 on gcc/release/all-integration-ci 8598331b: 28 of 29 jobs success and one skipped by design, read job by job on SQLite 8.2 and 8.3, MySQL 8.0, MariaDB 11.4.2 and the strict-mode job (audits/2026-11-18-all-in-one-integration, The third run)'),
        4: ('MET', 'PR 7, 16, 20 to 23, 27 and 32 to 34 are merged into the integration (every head is an ancestor of 8598331b) and ran in run 293, which is green'),
    },
}
# Pull requests that a requirement now names although the assessors read it before they existed (the head each had when its evidence was written).
EXTRA_LINKS = {
    'E-01': [{'repo': 'lavkoo', 'pr': 87, 'sha': '44b53616'}],
    'E-03': [{'repo': 'lavkoo', 'pr': 95, 'sha': '56a2280a'}],
    'D1-05': [{'repo': 'lavkoo', 'pr': 87, 'sha': '44b53616'}],
    'I1-12': [{'repo': 'lavkoo', 'pr': 87, 'sha': '44b53616'}],
    'I1-13': [{'repo': 'lavkoo', 'pr': 87, 'sha': '44b53616'}],
    'M2-02': [{'repo': 'lavkoo', 'pr': 96, 'sha': '6f55384e'}],
    'M2-04': [{'repo': 'lavkoo', 'pr': 96, 'sha': '6f55384e'}],
    'M2-01': [{'repo': 'lavkoo', 'pr': 97, 'sha': '177d80bc'}, {'repo': 'lavkoo', 'pr': 104, 'sha': 'c1aac4f7'}],
    'M1-17': [{'repo': 'lavkoo', 'pr': 97, 'sha': '177d80bc'}, {'repo': 'lavkoo', 'pr': 104, 'sha': 'c1aac4f7'}],
    'D1-07': [{'repo': 'lavkoo', 'pr': 98, 'sha': '718edb99'}, {'repo': 'lavkoo', 'pr': 99, 'sha': 'f2942370'}, {'repo': 'lavkoo', 'pr': 102, 'sha': 'd2d130f8'}],
    'A2-15': [{'repo': 'lavkoo', 'pr': 100, 'sha': '9908240c'}],
    'D1-10': [{'repo': 'lavkoo', 'pr': 101, 'sha': '1447d433'}, {'repo': 'lavkoo', 'pr': 103, 'sha': 'd100dd01'}],
}
# A criterion that the work meets in part is split in two, so that no percentage moves for the part that is not done. Only what a named head or file supports.
# What sections 5 and 10 of a verifier's requirement say once its acceptance criteria have actually been written, replacing
# "Its acceptance criteria are not written yet" and "Write its acceptance criteria". Keyed by requirement id: (remaining, next_task).
WRITTEN_CRITERIA = {}

CRITERIA_SPLIT = {
    'D1-07': {
        1: [
            ('Per-rate tax rows (order_taxes) are written for an excluded-tax order', 'CODED', 'PR 98 (718edb99): one order_taxes row for each rate with its name, type, rate, amounts, quantity, payer, seller and setup, for an order-wise and a product-wise setup; a test fails on main for the empty string written into taxable_id and passes with the fix; CI of the head not read'),
            ('The rows of an order add up to the tax the order carries', 'CODED', "PR 102 (draft, stacked on PR 98, d2d130f8, awaiting the finance review): the tax of a line is shared among that line's rates and the shipping tax among each line's own (a cart of two lines wrote half of each line's tax and a third of the shipping tax); OrderTaxRecordedAtPlacementTest (12: the corrected rows pinned, a product-wise cart whose lines do not share their rates, the shipping tax of each line adding up to 34.5 of 34.5), the three cases fail on the code before the change; the order (30), its lines (20 and 10) and the amount are unchanged; the Address directory (1,074 tests) passes on SQLite; CI not read"),
            ('The orders already placed whose rows differ from their tax can be listed without rewriting them', 'CODED', "PR 102: orders:tax-rows-reconcile (OrderTaxRowsReconciler, in the tax module) classes each order of a period as reconciled, short, over or without rows by what its rows add up to against orders.total_tax_amount, names order ids and amounts only, exits 1 while any order differs and only selects (a test proves every statement is a select and no row changes); OrderTaxRowsReconcileTest (17), one case through a real checkout next to an order that kept the old rows; what to do with the orders already placed is the finance review's; CI not read"),
        ],
        2: [
            ('The tax setup is chosen by the storefront\'s country, and a storefront with none is never taxed with the platform\'s Saudi setup', 'CODED', "PR 99 (draft, stacked on PR 87, f2942370, off by default): StorefrontTax reads the active default setup whose country_code is the storefront's and, when it has none, a setup built in memory and never saved that charges nothing, whether or not the currency switch is on; the platform's row is the one no storefront owns (the admin screen, its status switch and the module calculator never read a storefront's row as the platform's); one cache entry for each storefront; the panel edit of an order is taxed under the setup the order was placed under; StorefrontTaxSetupTest (22), mutated for each mechanism; passes on SQLite and MySQL 8.0.46, CI not read"),
            ('A storefront cannot sell before its tax setup is approved or a documented decision that it charges no tax is recorded', 'CODED', "PR 99 (f2942370): StorefrontTaxApproval and StorefrontSellingGate: a storefront other than the default one sells only while the business setting storefront_tax_approval_<country> holds a decision that names what, who, where and when (setup, with the fingerprint of the rates, type, included flag and shipping tax, or no_tax), and it goes stale when what the storefront is taxed with changes; gcc:storefront:tax-approve records it with an audit line, withdraws it and shows it; the storefront list, every checkout, payment and top-up route and the readiness report follow; StorefrontTaxApprovalTest (47) and StorefrontTaxApproveCommandTest (16), six mutations each caught; SQLite: Storefront 673, Address 1,045, Security 99, MyFatoorah 137; MySQL 8.0.46: Storefront 673 normal, and 209 strict on the integration tree; nothing here approves a tax, the person, the document and the rates are the owner's; CI not read"),
        ],
    },
    'D1-10': {
        0: [
            ('A refund request or transaction stores the currency and the selling company of the original payment', 'CODED', 'PR 101 (draft, stacked on PR 90, 1447d433): RefundOrderMoney copies the order\'s storefront, currency, rate and legal_entity_id into the refund request (customer API and web) and the refund transaction (RefundTransactionService::getData); RefundOrderMoneyTest (12) and StorefrontRefundRecordTest (4, through the real API after a real checkout) pass on SQLite and MySQL 8.0.46, CI not read'),
            ('A refund request or transaction stores the merchant account of the original payment', 'MISSING', 'gateway accounts for each company belong to D2 and do not exist; nothing records which account took the payment'),
        ],
        1: [
            ('A computation of a refund from the original payment (its amount and currency) exists and does not move with the exchange rate', 'CODED', "PR 103 (draft, stacked on PR 101, d100dd01, prepared and not adopted): RefundFromOriginalPayment gives a refund as its share of the original payment, in the currency it was paid in (the one paid gateway record, else the order's own currency and rate; an order that recorded neither answers null); the exchange rate is not an input: in the test the computation stays 37.50 while the conversion of today moves from 37.50 to 35.00; exact decimals; RefundFromOriginalPaymentTest (26), mutations of the cumulative rounding, the cap, the cart share, the paid filter, the ambiguity rule and the rate each caught; CI not read"),
            ('The refund flow uses it, so that a refund is not re-converted at the refund-time rate', 'MISSING', "no refund calls the computation (a test fails for any file under app/ other than the preview command that refers to it): the owner's policy across currency movements is open (the original amount, or the amount at the current rate); a wallet refund still credits usdToDefaultCurrency of the stored amount"),
        ],
        3: [
            ('The computation returns exactly the amount paid across the refunds of an order, after a rate change and for a three-decimal currency', 'CODED', 'PR 103 (d100dd01): the refunds of one order, split in four ways and in thirds of a three-decimal currency (3.333, 3.334, 3.333 of 10.000 KWD), add up to exactly what was paid because each is the difference of two roundings of the cumulative share; a refund that would take more than the order is capped; a payment of several orders is shared by their stored amounts; CI not read'),
            ('A refund made through the flow after an exchange-rate or settings change returns exactly the amount paid', 'MISSING', "depends on the refund flow using the computation (the owner's policy) and on the merchant account of the company (D2); no refund in the flow is computed from the original payment"),
        ],
    },
    'M1-13': {
        1: [
            ('A register exists in which the outcome of a review is written, with the head that was read', 'CODED', 'docs/gcc/REVIEW_GATES.md (docs branch): six rows, each with the pull requests, what to read first, the reviewer role and a column for the outcome; no test asserts it'),
            ('Each review has a short practical file with its pull requests and heads, what changes for money or security, the evidence and its CI state, the questions the reviewer decides and an empty outcome table', 'CODED', 'docs/gcc/reviews/R1 to R6 (docs branch), linked from REVIEW_GATES.md, which also says who can be asked: nobody is named and the only collaborator of lavkoo is its owner, so no review is requested and no outcome is filled; no test asserts the files'),
            ('An outcome is recorded where merges are decided', 'MISSING', 'every Requested and Outcome cell of the register and of the six files is empty; no review was requested; main is unprotected (DR-4)'),
        ],
        2: [
            ('Every Arabic string a pull request of lavkoo adds is on a native-reader list', 'CODED', 'docs/gcc/ARABIC_TEXT_FOR_REVIEW.md, generated by docs/gcc/tools/list_arabic_strings.py from the pull request refs: 80 lines in 10 pull requests (42, 45, 52, 63, 64, 72, 74, 78, 87, 90), once each under the lowest number; nobody has read them'),
            ('The Arabic strings of the apps are on a native-reader list', 'MISSING', 'the list covers resources/lang of lavkoo only; the apps\' translation files are named in the register and not listed'),
        ],
    },
    'M1-01': {
        0: [
            ('A committed ledger lists every open pull request with its base, head and draft state', 'CODED', 'docs/gcc/PR_LEDGER.md (docs branch) lists all 96 pull requests of lavkoo with branch, base, head, state and the requirements that name each, generated by a read-only script from the remote\'s pull request refs; docs/gcc/tools/check_pr_ledger.py exits 1 when a head moved or a pull request is missing (run after the commit that added it: 96 recorded, 96 on the remote, 0 moved)'),
            ('The ledger gives the CI of each head per job and the time it was read', 'MISSING', 'the ledger deliberately claims no CI state; CI per job is read in the tracker and in the audit reports (run 285, run 293), not in the ledger'),
        ],
    },
}
APPLIED_FIELDS = ('functional', 'exists_now', 'done_in_drafts', 'remaining', 'deps_blockers', 'next_task', 'owner_decisions', 'name')

GAPS = [
    {
        'title': 'The money of a storefront is not yet end to end',
        'ids': 'B-02 B-03 D1-05 D1-06 D1-07 D1-10 D2-05 D2-10 E-16 H1-08 H1-09 H1-14 H2-08 I1-12 I1-14 J-05 K-10 L-08 M2-07 C-15'.split(),
        'blocks': 'orders with the right currency; prices that are the same in the app and on the web; wallets, coupons, shipping and tax that agree with the order',
        'why': 'GCC-05 (draft PR 87, slices 1 to 4 and most of slice 5, off by default) makes a non-default storefront single-currency in its own currency with the right decimals, records the storefront and currency on the order, refuses a gateway that cannot take that currency, makes the admin product screens work in the product\'s country, gives the storefront its own minimum order and free-delivery threshold (never the platform\'s riyal figure), prices its shipping from the product alone, lets the panel edit of an order read all of these in the order\'s money, and gives a coupon its storefront (stored, narrowed on every customer finder, and typed in the storefront\'s currency through the admin routes). Still reading the platform\'s one currency: the admin coupon form, list and export, coupon budgets, the tax snapshot, refunds in the original currency, channel prices, invoices, e-mails and exports, and both apps\' other formatters (the customer app has the price formatter and the currency it sends, lavkooapp #8 and #9).',
        'first': 'Step 9 of the GCC-05 slice 5 plan on PR 87: the country select and currency labels on the admin coupon form, and the coupon list, quick view and export in the coupon\'s own money; then the readiness lines (step 10) and the tax and refund snapshot.',
        'measured_note': ' as the assessors read it; slices 3 and 4 of PR 87 came after and are in the cards as updates',
    },
    {
        'title': 'Wallet and loyalty are one global balance',
        'ids': 'E-01 E-02 E-03 E-04 E-05 E-06 E-07 E-08 E-13 E-14 E-15 J-09 M2-05'.split(),
        'blocks': 'wallets and loyalty isolated per market; a customer app purchase paid from a wallet in the right currency',
        'why': 'A customer has one wallet balance and one loyalty balance on the user row and one ledger without a market, currency or selling company. The writers are now safe (row lock, overdraft floor, atomic checkout, whole-cent debit: drafts PR 9 to 12, 34 to 36) and a read-only reconciliation of the balances against the ledgers now exists (draft PR 88, wallet:reconcile, classes A to F), but nothing separates balances by market and nothing migrates the Saudi balances into per-market accounts.',
        'first': 'The expand-only per-market account tables behind a switch, the ledger attribution columns (E-05), and a Saudi backfill command that refuses to run unless wallet:reconcile shows zero unexplained on a restored copy.',
    },
    {
        'title': 'There is no selling company (legal entity) dimension',
        'ids': 'D1-03 D1-04 D2-07 D2-08 D2-09 D2-16 E-17 F-14 G-12 H2-06 H2-10 H2-11 L-08 A2-09 A2-13'.split(),
        'blocks': 'orders with the right legal company; reports and money by company; per-company gateway accounts and accounting',
        'why': 'The platform keys data by storefront country, shop and seller. A registry of selling companies (one for each country, empty until the owner supplies them) and an order stamp now exist in a draft (PR 90, behind GCC_LEGAL_ENTITIES), so an order can name its company and keep a frozen copy of its identity, and the three invoices print that company instead of the shop\'s settings. Still missing: the invoice currency and a number for each company, a company on the payment row, gateway accounts, ledger and settlement by company, an Odoo company per entity, staff scope by company, and the Super Admin money view grouped by company. The owner has not decided the entities (open question Q-11).',
        'first': 'The owner\'s rule for the invoice number of each company and the series on top of the registered key, then the company on the payment request and the accounts that follow it (D2, E, H2); the entities themselves are an owner decision.',
    },
]
RUNNERS_UP = [
    ('Seller markets and the seller app', 'sellers and seller staff have no market or entity scope (B-09, K-03, K-04, K-09, K-17); seller products are all Saudi today.'),
    ('Per-storefront themes and brand', 'a theme of its own for each storefront, its publish, schedule, preview and caches are built in drafts (PR 107 and 112, off by default); what is not started is the shared design identity with a brand override for each storefront (F-12.2, F-12.3, F-13), the copy of a theme between storefronts (F-11.2, F-11.3) and the banner rows of a draft (F-02.2).'),
    ('Shipping eligibility and carriers', 'the cross-border engine, carrier route declarations and regulated-goods refusal (H1-01 to H1-09, M2-09) are not started; cross-border stays off by default.'),
    ('Notifications and consent per store', 'sender accounts, templates, audiences and consent per store (I1-01 to I1-09, A2-13).'),
]


# ---- Batch of 2026-11-18, afternoon: the legal review of a market (PR 105), the one-time codes out of the log of main (PR 106), the CI read of PR 98 (run 314) ----
_RUN_314 = ("run 314 on 718edb99 (finished 14:41 UTC on 2026-11-18, read job by job): 20 of 21 jobs are success, among them the Address tests on MySQL 8 and on MariaDB 11.4.2, Tests on SQLite 8.2 and 8.3, "
            "the strict-mode job on MariaDB 11.4.2 and every sales-channel and cost group on both engines; the deployment-overlay job is red by design and is not a required check")
CRITERIA['D1-07'][0] = ('MET', 'PR 98 (718edb99): OrderTaxRecordedAtPlacementTest asserts orders.tax_type, tax_model and total_tax_amount, and order_details.tax and tax_model, for an order-wise setup, a quantity of three, a product-wise setup and a tax-included setup; ' + _RUN_314)
_split_rows = CRITERIA_SPLIT['D1-07'][1]
_split_rows[0] = (_split_rows[0][0], 'MET', 'PR 98 (718edb99): one order_taxes row for each rate with its name, type, rate, amounts, quantity, payer, seller and setup, for an order-wise and a product-wise setup; a test fails on main for the empty string written into taxable_id and passes with the fix; ' + _RUN_314)

CRITERIA['I2-02'] = {5: ('CODED', "PR 105 (draft, stacked on PR 99, 01cefbc1): StorefrontLegalReadiness::isReady answers no when the configuration requires no document type or no locale, and the grandfathered storefront still answers yes (StorefrontLegalReadinessTest: three cases; StorefrontSellingGateTest: a reviewed and taxed storefront stays closed); tests/Unit/Storefront and tests/Feature/Storefront (794 tests) pass on SQLite and MySQL 8.0.46; a mutation of the check fails the matching tests; CI not read")}
CRITERIA['I2-07'] = {
    2: ('CODED', "PR 105 (01cefbc1): StorefrontLegalReview keeps who reviewed a storefront's legal texts, where the opinion is written and the day, with an audit line in the same transaction; gcc:storefront:legal-review records, reports and withdraws it; a tool or an assistant is refused as reviewer, a day in the future or a row that does not say all of it counts for nothing (StorefrontLegalReviewTest, StorefrontLegalReviewCommandTest); no record exists for any country; CI not read"),
    3: ('CODED', "PR 105 (01cefbc1): StorefrontSellingGate::maySell asks for the review next to the texts, the currency and the tax decision, so the checkout, the payment start, the storefront list and the readiness report agree (StorefrontSellingGateTest, StorefrontListApiTest, StorefrontLegalLaunchReportTest); a mutation of the gate condition and one of the readiness line fail them; CI not read"),
}
CRITERIA['M1-04'] = {3: ('CODED', "PR 106 (draft, main based, a8069b1a): the five OTP log calls of the vendor password reset and the message of the Zender gateway are removed from main (the same change as stage 0 of the hotfix, PR 85); OtpLoggingTest scans every Log:: call of the two files and fails for a code, a token or a message that is logged; the gateway still logs the phone number and its own answer, which is the owner's call; CI not read")}
CRITERIA['M2-13'] = {4: ('CODED', "PR 106 (a8069b1a): no one-time code or message text is logged by the vendor password reset or the Zender gateway on main (OtpLoggingTest); PR 75 and PR 83 are main-based trees that do not hold the change; CI not read")}
CRITERIA['M2-18'] = {5: ('CODED', "PR 106 (a8069b1a) removes the one-time codes and their message from the log of main (OtpLoggingTest), PR 7 stops logging the Paymera callback body and PR 93 stops the order debug file that wrote customer, guest, address and order ids; the gateway still logs a phone number (the owner's call); none is merged, CI of PR 106 not read")}

EXTRA_LINKS['I2-02'] = [{'repo': 'lavkoo', 'pr': 105, 'sha': '01cefbc1'}]
EXTRA_LINKS['I2-07'] = [{'repo': 'lavkoo', 'pr': 105, 'sha': '01cefbc1'}]
EXTRA_LINKS['M1-04'] = [{'repo': 'lavkoo', 'pr': 106, 'sha': 'a8069b1a'}]
EXTRA_LINKS['M2-13'] = [{'repo': 'lavkoo', 'pr': 106, 'sha': 'a8069b1a'}]
EXTRA_LINKS['M2-18'] = [{'repo': 'lavkoo', 'pr': 106, 'sha': 'a8069b1a'}]

UPDATES['I2-02'] = "PR 105 (draft, stacked on PR 99, 01cefbc1, off by default): StorefrontLegalReadiness::isReady walked the required document types and locales and answered yes when none failed, so a configuration that required none made every storefront legally ready; it now answers no, and the grandfathered storefront still answers yes. Together with I2-07 below."
UPDATES['I2-07'] = "PR 105 (draft, stacked on PR 99, 01cefbc1, off by default): a storefront other than the grandfathered ones does not sell until an independent legal review of its texts is on record (StorefrontLegalReview: who reviewed, where the opinion is written, the day; an audit line in the same transaction; gcc:storefront:legal-review records, reports and withdraws it; the readiness report marks a storefront without one UNSAFE). It records what a person tells it and reviews nothing; a tool or an assistant is refused as reviewer; no record exists for any country. A recorded review does not go stale when a text changes (the owner's decision). Still missing: the scan of language files, views and API payloads for compliance claims, and a recorded review for each country."
UPDATES['M1-04'] = "PR 106 (draft, main based, a8069b1a): the same change as stage 0 of the baseline hotfix, applied to main: the five OTP log calls of the vendor password reset and the message of the Zender gateway are removed, and OtpLoggingTest scans every Log:: call of the two files. The gateway still logs the phone number and its own answer."
UPDATES['M2-13'] = "PR 106 (draft, main based, a8069b1a) removes the one-time codes and their message from the log of main (OtpLoggingTest); queued messages that carry no storefront are still the open part of the other criteria."
UPDATES['M2-18'] = "PR 106 (draft, main based, a8069b1a) with PR 7 and PR 93: no one-time code, callback body or order identifiers are logged by main once they merge; the gateway still logs a phone number."

# ---- the customer app, 2026-11-18 afternoon: lavkooapp #10, #11 and #12 (written without a Flutter toolchain; the app CI is the first run) ----
CRITERIA['J-01'] = {5: ('MET', "lavkooapp #10 (draft, stacked on #9, 0ec24fd): StorefrontSwitcher.followTheServersList asks the server for its list and moves a customer whose storefront the list no longer has to the default one through the same switchTo a choice goes through (saved, headers rebuilt, the dropped storefront's holders told); nothing is done while storefronts are off, before the first answer, for a listed (even browse-only) storefront or the default one; the splash screen and the launch from a link call it; six cases in storefront_switcher_test.dart; app CI run 11 on 0ec24fd (read on 2026-11-18): job storefront-header success, the analysis of the changed files with warnings fatal, Test storefront (flag off) and Test storefront with storefronts on, the first run of code written without a Flutter toolchain; no device run")}
CRITERIA['J-02'] = {5: ('MET', "lavkooapp #12 (draft, stacked on #11, abf7ed9): StorefrontController.choose waits for the server's answer about the new storefront for answerWait (five seconds) at most and returns, the answer being stored and the screens told when it comes; two cases in storefront_controller_test.dart (a choice returns, saved, before a silent server answers; the later answer is kept and tells the screens); app CI run 13 on abf7ed9 (read on 2026-11-18): job storefront-header success with the flag off and on; no device run on a real slow network")}
CRITERIA['J-03'] = {4: ('MET', "lavkooapp #11 (draft, stacked on #10, ba6f03b): DioClient.storefrontHeaders answers X-Storefront for the storefront the customer is on and nothing while storefronts are off; the six uploads (refund, profile, review, chat, two of support) add it; storefront_multipart_headers_test.dart (both build settings) and a source scan that fails for a file that builds more uploads than it asks the headers of; app CI run 12 on ba6f03b (read on 2026-11-18): job storefront-header success, including the analysis of the five upload repositories for errors and both test steps; the header on the wire is not exercised by a device or a server")}
EXTRA_LINKS['J-01'] = [{'repo': 'lavkooapp', 'pr': 10, 'sha': '0ec24fd'}]
EXTRA_LINKS['J-02'] = [{'repo': 'lavkooapp', 'pr': 12, 'sha': 'abf7ed9'}]
EXTRA_LINKS['J-03'] = [{'repo': 'lavkooapp', 'pr': 11, 'sha': 'ba6f03b'}]
UPDATES['J-01'] = "lavkooapp #10 (draft, stacked on #9, 0ec24fd, off by default): a customer left on a storefront the server no longer lists is moved to the default one (StorefrontSwitcher.followTheServersList, called at app start); the app CI (run 11) passed on its first run. Still missing: the fallback following the server's default_country."
UPDATES['J-02'] = "lavkooapp #12 (draft, stacked on #11, abf7ed9, off by default): a storefront choice waits five seconds at most for the server's answer and lets the customer through; the answer is stored and the screens told when it comes; the app CI (run 13) passed on its first run."
UPDATES['J-03'] = "lavkooapp #11 (draft, stacked on #10, ba6f03b, off by default): the six multipart uploads of the app carry X-Storefront through DioClient.storefrontHeaders, and a source scan fails for an upload that does not ask for it; the app CI (run 12) passed on its first run."

CRITERIA['J-18'] = {1: ('MET', "lavkooapp #13 (draft, stacked on #12, 9c276dd; a workflow file and a number, no application code): the job analyze-whole-project runs flutter analyze on the whole project and fails for an error or for more findings than tool/analyze_baseline.txt records (255); app CI run 17 on 9c276dd (read on 2026-11-18): success with 255 found and 255 recorded; run 18 on a throwaway branch with one unused import added (never merged): the same job failed with 'The analyzer found 256 findings (0 errors); the recorded state is 255'")}
EXTRA_LINKS['J-18'] = [{'repo': 'lavkooapp', 'pr': 13, 'sha': '9c276dd'}]
UPDATES['J-18'] = "lavkooapp #13 (draft, stacked on #12, 9c276dd): the whole project is analyzed in CI and no finding may be added to the 255 recorded (tool/analyze_baseline.txt); the gate passed on the head and failed on a throwaway branch with one finding added. Still missing: the whole flutter test suite in CI (two known failures in dynamic_theme_render_matrix_test), with the flag on (five flag-off assumptions fail), every app pull request getting a run, and a device run."

# ---- F-01: one active theme per storefront, PR 107 (built by a delegated agent, reviewed and run again here) ----
CRITERIA['F-01'] = {
    0: ('CODED', "PR 107 (draft, stacked on PR 87, 62b8e4ad, off by default: GCC_STOREFRONT_THEMES): themes.storefront_country is a nullable additive column (migration 2026_12_04_100000; NULL is the default storefront's theme, so every existing theme is Saudi's and nothing is back-filled) and ThemeManager::activate deactivates only the active themes of the same storefront (StorefrontThemeActivationTest: activating AE's theme leaves SA's active, the reverse, only the same storefront competes, NULL and the default country are one storefront; off, or before the migration, every other theme is deactivated as before); the seven new classes (247 tests) pass on SQLite and on MySQL 8.0.46, no MariaDB run; CI not read"),
    1: ('CODED', "PR 107 (62b8e4ad): a request is served the active theme of its storefront, else the default storefront's (StorefrontThemeResolver::activeTheme; StorefrontThemeServingTest through /api/v1/theme/version, /home and /sections with X-Storefront, through the renderer and the delivery; a storefront given its own theme is served it at once; one storefront never reads another's cached copy; a theme with nothing published is served as the built-in pages, which is an owner decision); CI not read"),
    2: ('CODED', "PR 107 (62b8e4ad): the fourteen direct readers of the active theme ask StorefrontThemeResolver (renderer, delivery, banner link, theme manager, theme API, /p/{slug}, experiments, campaigns, experience health, App Builder, builder, repository) and ActiveThemeReadersTest scans app, routes, Modules, config, database and the views and fails on a new direct read (the scan is proven on ten forms of one); CI not read"),
    3: ('CODED', "PR 107 (62b8e4ad): the admin theme list names the storefront of each theme, a table above it shows what each storefront serves (inherited from the default storefront, or the built-in pages), and a new theme is made for one storefront (AdminThemeStorefrontTest; nothing of it while the switch is off; five new texts in en, sa and ae through translate(), awaiting a native Arabic reader); CI not read"),
}
EXTRA_LINKS['F-01'] = [{'repo': 'lavkoo', 'pr': 107, 'sha': '62b8e4ad'}]
UPDATES['F-01'] = "PR 107 (draft, stacked on PR 87, 62b8e4ad, off by default): a theme belongs to one storefront (themes.storefront_country, additive and nullable) and activating one deactivates only the active theme of its own storefront; one StorefrontThemeResolver answers which theme a storefront serves for the fourteen readers, a scan test fails on a new direct read, and the admin list shows what each storefront serves. Not done: F-02 publish per storefront, F-04 and F-09 the cache flush and the app beacon per storefront, F-07 preview, F-11 copy, F-12 and F-13 brand, F-15 the selector and the staff scope."
NEXT_CURRENT['F-01'] = "Read the CI of PR 107 per job (MariaDB included); then F-02: publish, restore and the schedule of one storefront touch only that storefront (the cache flush, the announcement topic and the audit line name it); the owner's decisions on what a storefront without a theme serves and on the five Arabic texts."

# ---- K: the seller market contract, PR 108; E-05: ledger attribution, PR 109 (both built by delegated agents, reviewed and run again here) ----
CRITERIA['K-17'] = {1: ('CODED', "PR 108 (draft, stacked on PR 87, 11a9707f, off by default): GET /api/v3/seller/seller-center/security/me answers the caller and nobody else (principal_type owner or staff, seller_id, staff_id, role, the permission keys held now, market_scope unrestricted, allowed_markets null until K-09, storefronts_enabled), from the principal the token resolved to, with no credential or contact detail (SellerWhoAmITest, 17 tests: owner and staff, no role, a switched-off role, a role changed between two calls, the middleware chain, 401, a switched-off staff member, an issued key refused, GET only); the 55 tests of its two classes pass on SQLite and on MySQL 8 (strict included); the seller app does not call it yet; CI not read")}
CRITERIA['K-08'] = {3: ('CODED', "PR 108 (11a9707f): the route above tells the app who is signed in, owner or staff, the role and the permissions the server enforces (SellerWhoAmITest); the Flutter app does not read it yet, and hiding what a role cannot use (criterion 4) is app work; CI not read")}
CRITERIA['K-04'] = {0: ('CODED', "PR 108 (11a9707f, off by default): while storefronts are on, the seller order list and the lines of the order detail carry storefront_country and currency_code (null when the order has no record, never made up), and the list takes a storefront filter that only narrows the seller's own orders (an unknown or malformed value answers an empty list, never all orders; the default market also holds the orders with no record); while storefronts are off the answers are what they were, compared key for key with answers recorded from the controller before it changed, in two database states (SellerOrderMarketTest, 38 tests); the seller app does not show or filter by it yet; CI not read")}
CRITERIA['E-05'] = {
    1: ('CODED', "PR 109 (draft, stacked on PR 90, bb55ab83, dormant until migration 2026_12_05 has run): wallet and loyalty ledger rows carry source_type and source_id (order, order group, refund or payment), written by the six writers through LedgerAttribution; 24 callers pass an origin (checkout paid from the wallet, gateway top-up, refund credit, order edits, due, referral, points earned and taken back); reference is untouched; partial: point-of-sale debits and points conversions name nothing; 158 new tests pass on SQLite and the Storefront groups (635) on MySQL 8; CI not read"),
    2: ('CODED', "PR 109 (bb55ab83): a manual admin top-up records the signed-in administrator as actor_type and actor_id, read from the guard and never from the request (AdminTopUpActorTest through the real route, with a forged actor in the request); CI not read"),
    5: ('CODED', "PR 109 (bb55ab83): a wallet row carries its storefront, currency and selling company, copied from the order's own record, from the storefront the payment was started in for a gateway top-up, or from the request; loyalty rows carry storefront and company and no currency by design; with every switch off a Saudi row keeps the market columns empty (golden row); both models hide the columns so a customer's statement keeps its keys; CI not read"),
}
EXTRA_LINKS['K-17'] = [{'repo': 'lavkoo', 'pr': 108, 'sha': '11a9707f'}]
EXTRA_LINKS['K-08'] = [{'repo': 'lavkoo', 'pr': 108, 'sha': '11a9707f'}]
EXTRA_LINKS['K-04'] = [{'repo': 'lavkoo', 'pr': 108, 'sha': '11a9707f'}]
EXTRA_LINKS['E-05'] = [{'repo': 'lavkoo', 'pr': 109, 'sha': 'bb55ab83'}]
UPDATES['K-17'] = "PR 108 (draft, stacked on PR 87, 11a9707f, off by default): the seller app can ask who it is (GET /api/v3/seller/seller-center/security/me: owner or staff, shop, role, permission keys, market scope unrestricted and allowed_markets null until K-09). Not done: K-17.0 (X-Storefront or the principal's scope resolved on api/v3/seller), K-17.2 (seller config per market), the digital-file path, staff push tokens."
UPDATES['K-04'] = "PR 108 (draft, stacked on PR 87, 11a9707f, off by default): the seller order list and detail carry the storefront and currency of each order while storefronts are on and the list can be narrowed to one market. Not done: products created in the working market (K-04.1), per-market stock and finance totals (K-04.2), the other seller endpoints that return orders, and everything in the app."
UPDATES['E-05'] = "PR 109 (draft, stacked on PR 90, bb55ab83, dormant until its migration has run): wallet and loyalty ledger rows say what stands behind them (order, order group, refund or payment), who made a manual top-up, and the market they were made in; no amount, balance or reference changes. Not done: E-05.3 replay safety, E-05.4 the add-fund bonus in the ledger balance, point-of-sale debits naming their order, the backfill (E-07), any screen or report showing the columns."
NEXT_CURRENT['K-17'] = "Read the CI of PR 108 per job (MariaDB included); then K-17.0 (resolve X-Storefront or the principal's scope on api/v3/seller behind a default-off flag) with K-09 (markets on the seller principal); the Flutter app reads security/me (K-08); the owner's five decisions in docs/gcc/K-SELLER-MARKET-CONTRACT.md."
NEXT_CURRENT['E-05'] = "Read the CI of PR 109 per job on MySQL and MariaDB; the finance review of the ledger columns (R pack for the wallet); then E-05.3 (a replay-safe key on source_type and source_id) and E-05.4 (the add-fund bonus in the ledger balance) as draft PRs awaiting finance review."

# ---- J-17: the update gate is a class of its own and is tested (lavkooapp, branch gcc/p1-customer-version-gate-test, 49b63de) ----
CRITERIA.setdefault('J-17', {})[3] = ('MET', "lavkooapp (draft, stacked on #13, 49b63de): the rule moves unchanged out of the splash screen's state into UpdateGate.isRequired and UpdateGate.compare; test/update_gate_test.dart pins equal, lower, higher, switch off or not 1, missing row, blank, non-version and per-platform rows, and a table of 19 version pairs, and test/release_version_test.dart refuses an appVersion below the live floor of 14.1; run 20 of the app CI on 49b63de read job by job on 2026-11-18: storefront-header (analyze of the changed files, the errors-only analyses, Test storefront, Test storefront with storefronts on) and analyze-whole-project are success")
EXTRA_LINKS['J-17'] = [{'repo': 'lavkooapp', 'pr': 14, 'sha': '49b63de'}]
CRITERIA['J-17'][1] = ('MET', "lavkooapp (draft, stacked on #13, 49b63de): the same test/update_gate_test.dart pins that no update is forced when the switch is not on however high the minimum, when the shop sends no setting or no row for the platform, when the minimum is missing or blank and when it is not a version, and that each platform reads its own row; run 20 of the app CI on 49b63de, read job by job on 2026-11-18 (the steps named for criterion 3)")
CRITERIA['J-17'][0] = ('CODED', "the rule is pinned (UpdateGate.isRequired is true for a build below the minimum: test/update_gate_test.dart, run 20 of the app CI on 49b63de, read job by job on 2026-11-18); the order of the two route branches in the splash screen (the update screen before maintenance or login) is not pinned: no test pumps the splash screen")

# ---- I2-04: a storefront shows its own legal texts, PR 110 (built by a delegated agent, reviewed and run again here) ----
CRITERIA['I2-04'] = {
    0: ('CODED', "PR 110 (draft, stacked on PR 87, 13eda6f1, off by default): in a storefront other than the default one, the business page that stands for a legal document type shows that storefront's current text in the language the visitor reads the site in (StorefrontLegalPages, PageController::getPageView; StorefrontLegalPagesWebTest: the current text of that storefront, the language the visitor reads); CI not read"),
    1: ('CODED', "PR 110 (13eda6f1): GET /api/v1/business-pages returns the storefront's texts to the apps, only the body of a legal page differs from the default storefront's list (StorefrontLegalPagesApiTest); CI not read"),
    2: ('CODED', "PR 110 (13eda6f1): a storefront with no text in the visitor's language shows no page, never another country's text, the Saudi text or a text of another language; a revoked, unapproved, scheduled or draft text shows nothing and never the older one it superseded (StorefrontLegalVisibleTextsTest, the web and API tests); CI not read"),
    3: ('CODED', "PR 110 (13eda6f1): the business-page list is cached under a key for each storefront and language (the default storefront keeps its key), no longer than the moment a scheduled text takes effect, and is cleared when a text is saved or deleted through the model or the platform's pages are cleared (StorefrontLegalPagesCacheTest); CI not read"),
    4: ('CODED', "PR 110 (13eda6f1): the agreement links of the checkout summary and of the registration form go through businessPageUrl() and keep the visitor in the storefront (StorefrontLegalLinksTest renders the real markup); the footers, the e-mail templates and the seller registration link are as they were; CI not read"),
    5: ('CODED', "PR 110 (13eda6f1): the default storefront, storefronts that are off and any request bound to no storefront keep serving the platform's business pages unchanged (SaudiBusinessPagesUnchangedTest: 36 cases recorded from the unmodified code, which also pass on the base code); CI not read"),
}
EXTRA_LINKS['I2-04'] = [{'repo': 'lavkoo', 'pr': 110, 'sha': '13eda6f1'}]
UPDATES['I2-04'] = "PR 110 (draft, stacked on PR 87, 13eda6f1, off by default): in a storefront other than the default one, the business pages that stand for a legal document type show that storefront's own current text in the visitor's language, or nothing, on the website and in the page list of the apps; the list is cached per storefront and language; the agreement links of the checkout and the registration keep the visitor in the storefront. Not done: the admin publisher and import of the texts (I2-03), acceptance receipts (I2-05), footers, e-mail templates and the seller terms."
NEXT_CURRENT['I2-04'] = "Read the CI of PR 110 per job (MariaDB included); then I2-03 (the admin publishing workflow that writes the texts: draft from a template, a different approver, revoke) and I2-05 (the acceptance receipt on every order); the owner's four decisions in docs/gcc/I2-04-STOREFRONT-LEGAL-PAGES.md."

# ---- I2-11 to I2-17: the lifecycle of a storefront and its stop modes, PR 111 ----
CRITERIA['I2-11'] = {
    2: ('CODED', "PR 111 (draft, stacked on PR 105, d8f6a7ea, off by default): the state of each storefront (draft, ready, active, paused with a mode) is one business setting kept in the database, a draft for a storefront with no record and active for the default one (StorefrontLifecycle; StorefrontLifecycleTest, 40 tests; the state survives cache:clear); CI not read"),
    3: ('CODED', "PR 111 (d8f6a7ea): draft to ready needs what a storefront needs to sell (StorefrontPreflight: the texts, their independent review, the currency, the tax decision), ready to active needs a person and a reference and asks the preflight again, active to paused needs a mode, paused to active asks the preflight again (every refusal tested); a tool or an assistant is refused as the person; CI not read"),
    4: ('MISSING', "PR 111 (d8f6a7ea): every change is audited in the same transaction (storefront.lifecycle.ready, .activated, .paused and .resumed with who, from, to, mode, reason and reference), but it is not permission-gated: gcc:storefront:state is the operator's, with a name typed in, and the admin screen and its permission belong to the admin slice and to GCC-20"),
}
CRITERIA['I2-12'] = {
    1: ('CODED', "PR 111 (d8f6a7ea): php artisan gcc:storefront:state [country] status reports, for each configured storefront, its state and each prerequisite as ok or UNMET with the command that records it, with advisory counts (products that ship free, products of sellers), writes nothing, and exits 1 while a storefront is blocked (StorefrontLifecycleCommandTest); CI not read"),
    2: ('MISSING', "PR 111 (d8f6a7ea): the preflight checks the legal texts, their review, the currency and the tax decision (the selling company is the gate's own condition on the union tree), and the report adds advisory counts of products that ship free and of seller products; payment accounts, catalogue and theme are not checked"),
    3: ('CODED', "PR 111 (d8f6a7ea): marking ready and activating are refused while a prerequisite is unmet, and name each one (StorefrontLifecycleTest: a prerequisite withdrawn after ready stops the activation); CI not read"),
    4: ('CODED', "PR 111 (d8f6a7ea): every prerequisite is derived from stored texts, settings and decisions (StorefrontPreflight), so no flag can stand in for one; the selling gate asks the same questions plus the state; CI not read"),
}
CRITERIA['I2-13'] = {
    3: ('CODED', "PR 111 (d8f6a7ea): a person pauses an active storefront browse-only with a reason and a message for its customers; every new sale is refused with that message (422 storefront_not_sellable) and browsing is served (StorefrontStopModesTest, StorefrontLifecycleTest); CI not read"),
    4: ('CODED', "PR 111 (d8f6a7ea): the pause is persisted in the database, audited, and listed by GET /api/v1/storefronts as state paused with stop_mode, stop_message and stop_until (StorefrontStopModesTest); CI not read"),
}
CRITERIA['I2-14'] = {
    1: ('CODED', "PR 111 (d8f6a7ea): one storefront can be paused in maintenance while the others keep selling and being browsed (StorefrontLifecycleTest, StorefrontStopModesTest: KW in maintenance, AE, SA and a request with no header served); API only, because the website resolves no storefront from a request yet; CI not read"),
    2: ('CODED', "PR 111 (d8f6a7ea): the state is a business setting in the database and survives cache:clear (StorefrontLifecycleTest, StorefrontStopModesTest flush the cache); CI not read"),
    3: ('CODED', "PR 111 (d8f6a7ea): the maintenance gate (storefront.available) is on the cart, the product browse and the theme routes of API v1 and on nothing else, and the open routes (refunds, cancellations, due payments, the MyFatoorah status check) carry neither gate (StorefrontSalesRoutesTest pins both lists); the web maintenance of main is not touched; CI not read"),
    4: ('CODED', "PR 111 (d8f6a7ea): the storefront list carries each paused storefront's mode, message and end of the stop, and a browse request to a storefront in maintenance is answered 503 storefront_maintenance with the message and the end (StorefrontStopModesTest); the apps do not read it yet (J-08); CI not read"),
}
CRITERIA['I2-15'] = {
    1: ('MISSING', "PR 111 (d8f6a7ea): redirect is a stop mode that is stored, audited and listed with redirect_to (the default storefront), but the website resolves no storefront from a request yet (no route group under a country prefix), so no web GET is redirected"),
    4: ('CODED', "PR 111 (d8f6a7ea): the mode is chosen per country by the person who pauses it, stored and audited with who, from, to and why (StorefrontLifecycleTest); CI not read"),
}
CRITERIA['I2-16'] = {
    4: ('CODED', "PR 111 (d8f6a7ea): in every stop mode a new sale is refused and the routes that serve what a customer already has (refunds, cancellations, due payments, payment callbacks, the status check) carry neither the sale gate nor the maintenance gate (StorefrontSalesRoutesTest, StorefrontStopModesTest: a test for each mode of the refusal and of the list); settlements, shipments and support are not run for each mode; CI not read"),
}
CRITERIA['I2-17'] = {
    5: ('CODED', "PR 111 (d8f6a7ea): the default storefront can only be paused browse-only; maintenance and redirect are refused as stranding its own visitors (StorefrontLifecycleTest, with no audit line written); CI not read"),
}
for _requirement in ('I2-11', 'I2-12', 'I2-13', 'I2-14', 'I2-15', 'I2-16', 'I2-17'):
    EXTRA_LINKS[_requirement] = [{'repo': 'lavkoo', 'pr': 111, 'sha': 'd8f6a7ea'}]
UPDATES['I2-11'] = "PR 111 (draft, stacked on PR 105, d8f6a7ea, off by default, no migration): a storefront is a draft until a person says it is ready, ready until a person approves it with a reference, and only an active storefront sells; the state is one business setting per country, every change is audited with who, from, to and why, and the selling gate asks the preflight and the state. Not done: permission-gating (an admin screen and GCC-20)."
UPDATES['I2-12'] = "PR 111 (d8f6a7ea): StorefrontPreflight asks what a storefront needs to sell in one place and gcc:storefront:state status reports it per storefront, exiting 1 while blocked; activation is refused while a prerequisite is unmet. Not done: payment accounts, catalogue and theme in the checklist."
UPDATES['I2-13'] = "PR 111 (d8f6a7ea): a person pauses an active storefront browse-only with a reason and a customer message; the pause is stored, audited and listed as a stop mode."
UPDATES['I2-14'] = "PR 111 (d8f6a7ea): one storefront can be put in maintenance while the others sell; the state is in the database; the cart, product browse and theme routes of API v1 answer 503 with the message and the end of the stop, the routes that serve earlier orders are untouched; the list carries the status for the apps. Not done: the website (it resolves no storefront from a request yet) and the apps reading it (J-08)."
UPDATES['I2-15'] = "PR 111 (d8f6a7ea): redirect is a stop mode stored and audited per country and listed with redirect_to; no web GET is redirected because the website resolves no storefront from a request yet."
UPDATES['I2-16'] = "PR 111 (d8f6a7ea): every stop mode refuses a new sale and leaves the routes that serve earlier orders ungated, pinned by tests."
UPDATES['I2-17'] = "PR 111 (d8f6a7ea): the default storefront can only be paused browse-only. Not done: a new default must be active and legal-ready, and changing the default as an audited admin action (it is still GCC_DEFAULT_COUNTRY)."
NEXT_CURRENT['I2-11'] = "Read the CI of PR 111 per job (MariaDB included); then the admin screen with its permission and a step-up (GCC-20) for ready, activate and pause; the owner's four decisions in docs/gcc/I2-11-STORE-LIFECYCLE.md."
NEXT_CURRENT['I2-12'] = "Read the CI of PR 111; add payment accounts, catalogue and theme to the preflight once each has a per-country check."

# ---- F-02 slice 1: a publish of one storefront's theme touches that storefront only, PR 112 ----
CRITERIA['F-02'] = {
    0: ('CODED', "PR 112 (draft, stacked on PR 107, 5afda458, off by default): publishing in one storefront archives the previous version of that storefront's theme only and leaves another storefront's published version, revision and checksum as they were, and the revisions of two storefronts are counted apart (StorefrontThemePublishTest, 10 tests; four mutations fail it); the eight theme-related classes (259 tests) pass on SQLite; CI not read"),
    1: ('MISSING', "PR 112 (5afda458): a publish or an activation in one storefront forgets that storefront's cached pages (the renderer's and the delivery's keys) and no other's, and every storefront's for the default storefront's theme, which the others fall back to; a publish still announces on the one FCM topic, so every app is woken (each asks /theme/version for its own storefront's revision), and a topic per storefront needs the apps to subscribe to it"),
    3: ('CODED', "PR 112 (5afda458): the audit lines theme.published, theme.activated and theme.restored carry the storefront while themes are kept by storefront and carry nothing new otherwise (StorefrontThemePublishTest); the scheduled publish goes through ThemeManager::publish; CI not read"),
}
EXTRA_LINKS['F-02'] = [{'repo': 'lavkoo', 'pr': 112, 'sha': '5afda458'}]
UPDATES['F-02'] = "PR 112 (draft, stacked on PR 107, 5afda458, off by default): a publish, an activation or a restore of one storefront's theme touches that storefront only: another storefront's published version, revision and checksum are untouched, only the cached pages of the theme's own storefront are forgotten (every storefront's for the default storefront's theme), and the audit lines name the storefront. Not done: the one FCM topic that wakes every app, the global banners row a draft edit writes to (F-02.2), and F-04, F-07, F-09, F-11, F-15."
NEXT_CURRENT['F-02'] = "Read the CI of PR 112 per job (MySQL and MariaDB included); then F-02.2 (a draft edit of one storefront cannot change a banner row another storefront shows) and F-04 (the caches of the campaign, experiment and section-reach screens per storefront or declared global)."

# ---- afternoon batch of 2026-11-18: F-03 (PR 113), K-18 (lavkooappseller #7), I2-01 and I2-03 (PR 114), J-08 (lavkooapp #15), J-18 (lavkooapp #16) ----
CRITERIA.setdefault('F-03', {}).update({
    2: ('CODED', "PR 113 (draft, stacked on PR 112, 71c01d0d, needs no switch): ComponentCapabilityRegistry::servableToChannel() always said that the seller app draws nothing and the delivery never asked it, so a request naming the vendor_app channel (X-UI-Channel or ?channel=) was sent the page of the customer app, from the customer app's cache entry (the cache key held the platform and not the channel; probed: sections [spacer]). ThemeDelivery::build now sends a channel with no renderer no section, stored sections and campaign overlays alike, and names what it withheld (no_renderer_exists_for_this_channel); the channel joins the cache key. ThemeChannelDeliveryTest (12 tests, six mutations fail it; SQLite and MySQL 8.0.46). The seller app's side is pinned by lavkooappseller #7 (K-18). CI of PR 113 not read"),
    4: ('CODED', "PR 113 (71c01d0d): the stored checksum of a version holds the channels of a restricted section, sorted: a change of the channels alone moves it, the same channels in another order do not, and a section that is not restricted keeps the checksum it always gave (the test writes the old formula out, so republishing identical content costs no installed app a download); ThemeChannelDeliveryTest; CI not read"),
})
EXTRA_LINKS['F-03'] = [{'repo': 'lavkoo', 'pr': 113, 'sha': '71c01d0d'}]
UPDATES['F-03'] = "PR 113 (draft, stacked on PR 112, 71c01d0d): a channel with no renderer behind it (the seller app) is sent no theme section, a channel never shares a cached page with another, and the channels of a section move the stored checksum. Found by writing the test the tracker said was missing: the delivery never asked the registry. Not done: F-03.3 (web and app published separately for one storefront)."
NEXT_CURRENT['F-03'] = "Read the CI of PR 113 per job (MySQL and MariaDB included); then F-03.3 (a version is still one publish for every channel); the owner's decision on whether the seller app should ever be sent sections."

CRITERIA.setdefault('K-18', {}).update({
    2: ('MET', "lavkooappseller #7 (draft, test and one CI step, no application code, 3eba869): test/no_theme_call_test.dart fails if a file under lib names /api/v1/theme/sections, /home, /version or products/theme-collection (an import of a Dart file is not a call), and tests its own scan on four calls it must see and six lines it must leave alone. CI run 15 on 3eba869, read per step on 2026-11-18: the step 'Test that the seller app makes no theme call (F-03, K-18)' passed 3 tests; the full 'Test' step ended 144 passed, 1 failed, the one failure being the known app_version_gate_test (shipped 1.0.0+3 against the published minimum 2.0.1), which this change does not touch"),
})
EXTRA_LINKS['K-18'] = [{'repo': 'lavkooappseller', 'pr': 7, 'sha': '3eba869'}]
UPDATES['K-18'] = "lavkooappseller #7 (draft, 3eba869): a source test pins that the seller app makes no theme call, and CI runs it by name before the full run (which is red on main for the known version gate test, so a named step is the only way a new test can be read there). Not done: K-18.1 (the shop banner and logo rules still read the global active theme)."
NEXT_CURRENT['K-18'] = "K-18.1: the shop banner and logo rules in registration and shop update must not depend on the single global active_theme."

CRITERIA.setdefault('I2-01', {}).update({
    3: ('CODED', "PR 114 (draft, stacked on PR 105, 9f099f97, no migration, no switch): StorefrontLegalPublisher is the only writer of storefront_legal_documents; edit changes a draft (an approved text goes back to draft and its approval is void) and refuses a published, revoked or discarded text; a change of a published text is draftNewVersionOf, a new draft that starts as a copy of it while the old text stays in force; StorefrontLegalPublisherTest (36 tests, among them a moment given in UTC taking effect at that instant in an application set east of UTC, west of it and in UTC; eleven mutations of the service fail it; SQLite and MySQL 8.0.46); CI not read"),
})
CRITERIA.setdefault('I2-03', {}).update({
    3: ('CODED', "PR 114 (9f099f97): draftFromTemplate copies a template into a draft carrying the template's hash and never publishes it, a text that is still its template is not approved, and the readiness of a storefront reads exactly what the service wrote (StorefrontLegalPublisherTest; StorefrontLegalTextCommandTest, 11 tests); CI not read"),
    4: ('MISSING', "PR 114 (9f099f97): a draft is written by one admin, approved by a different one (the author is the last person who changed the text), published from a day that is now or later and revoked, and every step is audited in the same transaction with the admin's id and the hash of the words; the entry point is gcc:storefront:legal-text, which refuses an admin that does not exist. Not done: the permission gate and the admin screen, so the criterion as written (each step permission-gated) is not met"),
})
EXTRA_LINKS['I2-01'] = [{'repo': 'lavkoo', 'pr': 114, 'sha': '9f099f97'}]
EXTRA_LINKS['I2-03'] = [{'repo': 'lavkoo', 'pr': 114, 'sha': '9f099f97'}]
UPDATES['I2-01'] = "PR 114 (draft, stacked on PR 105, 9f099f97): a published legal text is never edited in place; a change is a new version. Not done: the Saudi baseline as version 1 and the retention rules."
UPDATES['I2-03'] = "PR 114 (draft, stacked on PR 105, 9f099f97): nothing wrote the legal texts a storefront's readiness reads; now one audited service does (copy a template into a draft, edit, approve by someone other than the author, publish from a day, revoke, discard) and a command is its entry point. Not done: the admin screen with its permission, and a step-up or dual approval for publishing and revoking (GCC-20 level 3)."
NEXT_CURRENT['I2-03'] = "Read the CI of PR 114 per job; then the admin screen with its permission and the step-up for publishing and revoking (GCC-20); where templates come from (owner)."

CRITERIA.setdefault('J-08', {}).update({
    5: ('MET', "lavkooapp #15 (draft, stacked on #14, 016fd71, off by default) and #17 (draft, stacked on #15, b7f48bf, off by default). Redirect: StorefrontCountry reads stop_mode, stop_message, stop_until and redirect_to defensively and StorefrontSwitcher.followTheServersList moves a customer out of a redirected storefront to the one it names when that one is listed and not redirected itself (14 cases); CI run 22 on 016fd71, read per job on 2026-11-18: both jobs green, the 14 cases passed. Maintenance: StorefrontController.inMaintenance reads the server's own entry for the storefront the customer is on and no other, and the dashboard builds StorefrontMaintenanceScreen instead of its content (the server's message, the end of the stop, a button that asks again, a way to choose another country when the shop lists more than one), gone as soon as a newer answer says otherwise; 11 cases (the controller for every stop, another storefront's maintenance, an unlisted storefront and the flag off; the screen with and without a message and an end, the chooser, asking again, Arabic right to left at 320 px and 2x text; the strings in five languages); CI run 28 on b7f48bf, read per step on 2026-11-18: all analyze steps and both test steps green, the 11 cases passed with the flag off and on, and the whole-project analysis added no finding. The website is not covered (it resolves no storefront from a request yet) and the screen is shown by the dashboard only"),
})
EXTRA_LINKS['J-08'] = [{'repo': 'lavkooapp', 'pr': 15, 'sha': '016fd71'}, {'repo': 'lavkooapp', 'pr': 17, 'sha': 'b7f48bf'}]
UPDATES['J-08'] = "lavkooapp #15 (016fd71) and #17 (b7f48bf), both draft and off by default: the app reads how the server has stopped a storefront, follows a redirect to the storefront it names, and shows a maintenance screen (the server's message, the end of the stop, ask again, choose another country) instead of the dashboard while its storefront is in maintenance; CI runs 22 and 28 green per job. Not done: the website, a page opened by a deep link, and a native read of the Arabic, Bengali, Spanish and Hindi strings."
NEXT_CURRENT['J-08'] = "The Arabic, Bengali, Spanish and Hindi maintenance strings need a native reader (R5); the screen is the dashboard's only, so a page opened some other way is not covered."

CRITERIA['J-18'][2] = ('MET', "lavkooapp #16 (draft, stacked on #14, 89eb8bd; two layout overflows fixed in the widgets, one workflow job): the job test-whole-suite runs flutter test on the whole project with storefronts off and the step is blocking. The measure before the fix (run 21, 09708b2) was +582 ~23 -2, exactly two cases of dynamic_theme_render_matrix_test (the statistics tile overflowed by 17 px, the shipping cutoff strip by 28 px); CI run 24 on 89eb8bd, read per step on 2026-11-18: 'Whole suite, storefronts off' succeeded with +584 ~23: All other tests passed, and run 25 on 26ed5ad again +584 ~25")
CRITERIA['J-18'][3] = ('MET', "lavkooapp #16 (b84de44): the same job runs the whole suite in a build made with GCC_STOREFRONTS_ENABLED=true and that step is blocking too. The measure before the fixes (run 24, 89eb8bd) was +587 ~11 -9: five cases that assert the build as it ships today (no storefront header, the flag is off, the service is off by default) are skipped in a build made with the flag and the flag has a twin case that holds only in it (config and service are on), and four render matrix cases failed with one exception, Provider<StorefrontController> not found, because the price helper asks the controller for the storefront's currency and the matrix host did not provide one; the host now does when the flag is on (found and checked with the pinned Flutter 3.47.0 run locally: the whole suite with the flag on, +593 ~16, no failure). CI run 26 on b84de44, job test-whole-suite read on 2026-11-18: both steps succeeded, 'storefronts off: +584 ~25: All other tests passed!' and 'storefronts on: +593 ~16: All other tests passed!'")
EXTRA_LINKS['J-18'] = EXTRA_LINKS.get('J-18', []) + [{'repo': 'lavkooapp', 'pr': 16, 'sha': 'b84de44'}]
UPDATES['J-18'] = "lavkooapp #16 (draft, stacked on #14, b84de44): the whole flutter test suite runs in CI in the build as it ships and in the build with storefronts on, and both steps block a branch; it was red for two layout overflows (statistics tile, shipping cutoff strip), five cases that assert the flag-off build and one missing provider in the render matrix host, all fixed. Still missing: every app pull request getting a run, and a device run."

CRITERIA.setdefault('M2-14', {}).update({
    4: ('MISSING', "The all-in-one integration branch (fe0a1b93, integration only, CI not run on it): StorefrontStopJourneyTest, 32 cases, passes on SQLite and on MySQL 8.0.46 here. A real checkout of the API in each of the six storefronts, then the lifecycle stops the storefront (the default one browse-only, every other one in each of the three modes): the order is listed, tracked and its history read through the real routes while no new sale is taken (maintenance answers the cart 503, the other modes answer a sale 422 storefront_not_sellable), another storefront keeps selling, and the stopped one sells again once it is resumed; two mutations of the routes fail 16 and 5 of the cases. Refunds, the support chat and the payment webhooks of an earlier order are not run in it, so the criterion as written is not met"),
})
CRITERIA.setdefault('M2-15', {}).update({
    4: ('MISSING', "The integration branch (fe0a1b93): the journey now exists for the cart, the checkout, the order and the stop in all six storefronts (StorefrontStopJourneyTest, SQLite and MySQL 8.0.46 here; CI not run on it). The payment, the refund and a strict-mode run of the GCC groups are not part of it, so the criterion as written is not met"),
})
NEXT_CURRENT['M2-14'] = "Run the integration branch's journey in CI (a dispatch of the head), then add the refund request, the support chat and a payment callback of an earlier order to it."

# ---- run 342 (the all-in-one integration, head a5f7de59), read per job on 2026-11-18: the criteria whose code is in that tree and whose tests ran in it flip to MET ----
import re as _re
RUN_342 = (" CI run 342 (the all-in-one integration, head a5f7de59, which holds this pull request's code), read per job on 2026-11-18: 'Tests, SQLite' on PHP 8.2 and on PHP 8.3 (the whole suite), "
           "the Storefront, Address, Catalog, Staff scope, Checkout/payment/permission, Fulfillment + security, Saudi baseline and Wallet/Loyalty groups on MySQL 8.0 and on MariaDB 11.4.2 and the "
           "strict-mode job on MariaDB, and the five sales-channel and cost groups on MySQL 8.0 and MariaDB 11.4.2 (the last to finish, group A to M on MySQL 8.0, at 16:46 UTC): success (32 jobs: 30 success, 2 skipped by design, the deployment overlay and the Saudi baseline expectations check). "
           "A job proves a test only when its path list holds the directory of that test: the strict-mode job's list holds neither tests/Feature/Storefront nor tests/Feature/Address, so what runs in those directories has run under strict mode only locally. The pull request's own run is not read")
FLIPPED_BY_RUN_342 = [
    ('A2-15', 2), ('D1-05', 4), ('D1-07', 1), ('D1-07', 2), ('E-03', 3),
    ('F-01', 0), ('F-01', 1), ('F-01', 2), ('F-01', 3),
    ('I1-12', 3), ('I1-12', 4), ('I1-13', 1), ('I1-13', 2), ('I2-02', 5),
    ('I2-04', 0), ('I2-04', 1), ('I2-04', 2), ('I2-04', 3), ('I2-04', 4), ('I2-04', 5),
    ('I2-07', 2), ('I2-07', 3),
    ('I2-11', 2), ('I2-11', 3), ('I2-12', 1), ('I2-12', 3), ('I2-12', 4), ('I2-13', 3), ('I2-13', 4),
    ('I2-14', 1), ('I2-14', 2), ('I2-14', 3), ('I2-14', 4), ('I2-15', 4), ('I2-16', 4), ('I2-17', 5),
    ('K-04', 0), ('K-08', 3), ('K-17', 1),
    ('M1-04', 3), ('M2-01', 2), ('M2-01', 3), ('M2-02', 3), ('M2-04', 0), ('M2-04', 3), ('M2-13', 4), ('M2-18', 5),
]
_NOT_READ = _re.compile(r'[;,]?\s*(?:the )?CI(?: of [^;.)]*)? not read')
for _rid, _idx in FLIPPED_BY_RUN_342:
    _state, _text = CRITERIA[_rid][_idx]
    assert _state == 'CODED', (_rid, _idx, _state)
    CRITERIA[_rid][_idx] = ('MET', _NOT_READ.sub('', _text).rstrip(' .;') + '.' + RUN_342)
# The state of a requirement follows its criteria: every criterion met in a tree that was integrated and tested is INTEGRATED AND TESTED; code on a branch with a criterion missing is IN PROGRESS, not NOT STARTED.
STATE_AFTER.update({'F-01': 'INTEGRATED AND TESTED', 'I2-04': 'INTEGRATED AND TESTED', 'F-02': 'IN PROGRESS', 'K-04': 'IN PROGRESS', 'E-03': 'IN PROGRESS'})
for _position, (_title, _state, _text) in enumerate(CRITERIA_SPLIT['D1-07'][2]):
    assert _state == 'CODED', (_title, _state)
    CRITERIA_SPLIT['D1-07'][2][_position] = (_title, 'MET', _NOT_READ.sub('', _text).rstrip(' .;') + '.' + RUN_342)
for _rid in {r for r, _ in FLIPPED_BY_RUN_342}:
    if _rid in UPDATES:
        UPDATES[_rid] = _NOT_READ.sub(" (the integration run 342 is read and green; the pull request's own run is not)", UPDATES[_rid])

# ---- PR 115: the admin screens of the lifecycle and of the legal texts, behind three permissions ----
CRITERIA['I2-11'][4] = ('CODED', "PR 115 (draft, base PR 111, f5913ab7, no migration, no switch): every change of the lifecycle is permission-gated and audited: /admin/storefronts takes the four steps (ready, activate, pause, resume) behind the role permission storefront_lifecycle (the master admin holds it, no other role until it is granted in the role form, a switched-off role holds none), the person of a change is the signed-in admin and a typed name is ignored, and the audit line of each step names the admin guard as its actor (AdminStorefrontLifecycleScreenTest, 17 tests; AdminStorefrontRoleFormTest, 3; twelve mutations of the slice each fail a test; SQLite here, MySQL and MariaDB not run); CI not read")
CRITERIA['I2-03'][4] = ('CODED', "PR 115 (f5913ab7, which contains PR 114 at 9f099f97): an admin who holds storefront_legal_write writes a draft from a template, a different admin who holds storefront_legal_approve approves it (the author is refused in the page and in the service, even when their role holds both permissions), sets the day it takes effect (read as UTC, never from the past) and can revoke it with a reason; each step is gated by the role permission and writes its audit line with the admin guard as its actor, and a text typed into the screen is cleaned of scripts and event handlers before it is kept (AdminStorefrontLegalTextScreenTest, 26 tests; SQLite here, MySQL and MariaDB not run); CI not read")
EXTRA_LINKS['I2-03'] = EXTRA_LINKS['I2-03'] + [{'repo': 'lavkoo', 'pr': 115, 'sha': 'f5913ab7'}]
EXTRA_LINKS['I2-11'] = EXTRA_LINKS['I2-11'] + [{'repo': 'lavkoo', 'pr': 115, 'sha': 'f5913ab7'}]
UPDATES['I2-11'] = UPDATES['I2-11'].replace("Not done: permission-gating (an admin screen and GCC-20).", "Not done: GCC-20 (a step-up for activating and pausing).") + " PR 115 (draft, base PR 111, f5913ab7): the screen /admin/storefronts takes the four steps behind the permission storefront_lifecycle, by the signed-in admin, with the service's own refusals and audit line."
UPDATES['I2-03'] = "PR 114 (draft, stacked on PR 105, 9f099f97): nothing wrote the legal texts a storefront's readiness reads; now one audited service does (copy a template into a draft, edit, approve by someone other than the author, publish from a day, revoke, discard) and a command is its entry point. PR 115 (f5913ab7, contains PR 114): the screen /admin/storefronts/{country}/legal-texts takes every step behind two permissions (write; approve, publish and revoke), by the signed-in admin, with the text cleaned of scripts before it is kept. Not done: a step-up or dual approval for publishing and revoking (GCC-20 level 3), a source of templates, a scope by country."
NEXT_CURRENT['I2-11'] = "Read the CI of PR 115 per job (and of PR 111); then the GCC-20 step-up for activate and pause; the owner's decisions in docs/gcc/I2-STOREFRONT-ADMIN-SCREENS.md."
NEXT_CURRENT['I2-03'] = "Read the CI of PR 114 and PR 115 per job; then the GCC-20 step-up for publishing and revoking, where templates come from (owner), and a scope by country."

# ---- F-14: the same behaviour as D1-03 criteria 1 and 2 (PR 90), read in the integration run 342 ----
_F14_PROOF_1 = (" CI: run 302 on 26995055 (22 of 23 jobs success, the deployment overlay red by design) and the integration run 342 (head a5f7de59, which holds PR 90), read job by job on 2026-11-18: "
                "'Tests, SQLite' on PHP 8.2 and 8.3 (the whole suite), the Storefront group (OrderInvoiceIssuerTest) and the Address group (PanelInvoiceTest, StorefrontOrderPagesTest) on MySQL 8.0 and MariaDB 11.4.2: success. The strict-mode job is green but does not run these tests and is not proof here. The same behaviour as D1-03, shown under this requirement because the plan names it twice.")
_F14_PROOF_2 = (" CI: run 302 on 26995055 and the integration run 342 (head a5f7de59, which holds PR 90), read job by job on 2026-11-18: "
                "'Tests, SQLite' on PHP 8.2 and 8.3 (the whole suite) and the Storefront group on MySQL 8.0 and MariaDB 11.4.2: success. The strict-mode job is green but does not run these tests and is not proof here. The same behaviour as D1-03, shown under this requirement because the plan names it twice.")
CRITERIA.setdefault('F-14', {}).update({
    1: ('MET', "PR 90 (draft, stacked on #87, 26995055): the issuer block of the admin, vendor and customer invoice (partials/invoice/_issuer, OrderInvoiceIssuer) prints the company frozen on the order: its name, address, tax and commercial registration numbers, each only when the company has one, and nothing of the shop's own address, tax number or business identity beside it (OrderInvoiceIssuerTest, 26 tests; PanelInvoiceTest and StorefrontOrderPagesTest: an order with a frozen company prints that company and not the identity of the shop). The logo and the heading of the invoice are not part of the block and are as they were. Only the admin, vendor and customer order invoices changed: the two POS invoice views still print the shop address, and the invoice number, series and currency are untouched. The frozen company is printed only while legal entities and storefronts are on and the order carries a snapshot; every other order, including admin and vendor POS orders, prints the shop settings as before." + _F14_PROOF_1),
    2: ('MET', "PR 90 (26995055): the order keeps the company it was placed with, stamped once at placement by OrderStorefrontSnapshot::record() (called by OrderManager::generateOrder for web and API checkout and by the seller-app POS), never rewritten by the service (a whereNull(legal_entity_id) guard in OrderLegalEntitySnapshot::record; there is no database constraint): a later change of the registry does not reach a stamped order (OrderLegalEntitySnapshotTest: written once, a later change never rewrites it, the switches; OrderInvoiceIssuerTest: the company is the one the order was placed with whatever the registry says later). The admin and vendor POS screens and the sales-channel imports do not stamp an order yet. The tests call record() directly on orders built in the test." + _F14_PROOF_2),
})
EXTRA_LINKS['F-14'] = [{'repo': 'lavkoo', 'pr': 90, 'sha': '26995055'}]
UPDATES['F-14'] = "PR 90 (26995055): the invoice prints the company frozen on the order and nothing of the shop's own identity beside it, and the order keeps that company; read green in runs 302 and 342. Not done: a test that changes the brand name after the order and reads the same legal name on the invoice (the block takes no brand setting, so it cannot change, but nothing pins it by name), and a brand per storefront (F-12, F-13)."
NEXT_CURRENT['F-14'] = "Write the one test that changes the brand name after an order and reads the same legal name on its invoice (on PR 90's branch); then F-12 and F-13 (brand per storefront)."

# ---- K-06 criteria 2 and 3: who a POS sale is for, and what a 200 answer means (lavkooappseller #8) ----
CRITERIA.setdefault('K-06', {}).update({
    1: ('CODED', "lavkooappseller #8 (draft, base main, head b2e4d04 (this change in 6ae226a), client only): the app sends one stable Idempotency-Key per sale and replays it unchanged from the offline outbox: PosIdempotencyKey makes a random version 4 UUID, the cart controller makes one key for a sale and sends it in the Idempotency-Key header (through the service and the repository, the credential and the other headers kept) and queues the sale with the same key when the request got no answer, the outbox stores the key with the sale on disk, replays the sale under it, keeps it through a failed replay, and gives a sale an earlier version queued a key, stored before it is sent (test/pos_idempotency_key_test.dart, 11 tests, the real repository through a recording Dio adapter; seven mutations each fail it; the whole seller suite passes except the known version gate test; the seller CI runs it by name after the full run); the server half is lavkoo #35 (PosSaleIdempotencyConcurrencyTest); CI not read"),
    3: ('CODED', "lavkooappseller #8 (draft, base main, head b2e4d04 (this change in cbf579d), client only): PosOrderAnswer says a POS sale is recorded only when the answer names the order and is not one of the refusals the server sends with a 200 (product_not_available, checkProductTypeForWalkingCustomer); the cart controller checks it before it shows success and on a refusal keeps the cart and shows the server's message, and the outbox counts a sale as synced only then and otherwise keeps it queued with the refusal as its last error (test/pos_order_answer_test.dart, 7 tests; four mutations each fail it; flutter analyze finds no new issue; the seller CI runs it by name after the full run); CI not read"),
    4: ('CODED', "lavkooappseller #8 (draft, base main, head b2e4d04 (this change in 3ed7ab5), client only): PosCustomerId is the one place that says who a POS sale is for: a registered customer has a positive id and is told to the server as themselves whatever the number of digits (1000 to 9999 included), a walk-in is told as 0, and a walk-in cart put on hold gets a negative id that is nobody's; the sale body, the digital-product check and the resume of a held cart ask it, and the carts an earlier version saved (a walk-in named Walk-In Customer-n that carries n as its id, which could be a registered customer's) are given the negative id when read (test/pos_customer_id_test.dart, 10 tests; six mutations each fail it; the seller CI runs it by name after the full run); CI not read"),
})
EXTRA_LINKS['K-06'] = [{'repo': 'lavkooappseller', 'pr': 8, 'sha': 'b2e4d04'}]
UPDATES['K-06'] = "lavkooappseller #8 (draft, b2e4d04): three wrong rules of the POS. The app sent no Idempotency-Key, so a sale whose answer was lost was recorded a second time on replay; it now sends one key per sale and replays it from the outbox. A walk-in cart put on hold carried a random number as its id and the POS recognised a walk-in by that number having four digits, so a registered customer with a four-digit id was sold to as a walk-in and a walk-in with a shorter number was told to the server as the registered customer who has that id; one helper now says who a sale is for, held walk-in carts get a negative id, and the carts an earlier version saved are converted when read. The server refuses a sale with a 200 and no order number, and the app showed it as placed, emptied the cart and counted a queued refusal as synced; the answer is now read, not the status. Not done: a queued sale replayed only under the seller and market that took it (K-06.2)."
NEXT_CURRENT['K-06'] = "Read the CI of lavkooappseller #8; then K-06.2 (a queued sale is replayed only under the seller and market that took it); K-18.3 (a queued sale is never dropped silently after ten replays)."

CRITERIA.setdefault('K-05', {}).update({
    5: ('CODED', "lavkooappseller #8 (draft, base main, head b2e4d04 (this change in 291a066), client only): the scan of a product asked the camera for Code 128 alone, so the retail codes of a pharmacy's packs were never read; productBarcodeFormats is now EAN-13, EAN-8, UPC-E and Code 128 (a UPC-A code is read as an EAN-13 with a leading zero by the scanning libraries of both platforms, and the plugin has no format of its own for it) and the scan reads that list (test/product_barcode_formats_test.dart, 3 tests; three mutations each fail it; flutter analyze finds no new issue; the seller CI runs it by name). That a camera decodes a pack is a check on a device, not done; a code stored with twelve digits against a scanned thirteen is the server's matching, not changed; CI not read"),
})
EXTRA_LINKS['K-05'] = EXTRA_LINKS.get('K-05', []) + [{'repo': 'lavkooappseller', 'pr': 8, 'sha': 'b2e4d04'}]
UPDATES['K-05'] = UPDATES.get('K-05', '') + " lavkooappseller #8 (draft, b2e4d04): the camera is asked for EAN-13, EAN-8, UPC-E and Code 128 instead of Code 128 alone. Not done: a scan that finds nothing in this market telling the seller (K-05.4), a device check."

# ---- PR runs 344 (PR 112) and 345 (PR 113), read per job on 2026-11-18 ----
_RUN_344 = (" CI: run 344 on cb4ca5f2 (PR 112's own run, which holds PR 107's code), read per job on 2026-11-18: 'Tests, SQLite' on PHP 8.2 and on PHP 8.3 (the whole suite) and 'Storefront tests' on MySQL 8 and on MariaDB 11.4.2: success; "
            "18 of 23 jobs were success when read, the deployment overlay red by design, and the strict-mode MariaDB job and three sales-channel MySQL jobs (which hold none of these tests) were still running.")
_RUN_345 = (" CI: run 345 on 71c01d0d (PR 113's own run, which holds PR 112's and PR 107's code), read per job on 2026-11-18: 'Tests, SQLite' on PHP 8.2 and on PHP 8.3 (the whole suite), 'Storefront tests' on MySQL 8 and on MariaDB 11.4.2 (871 tests, 2,491 assertions, read from the log) and the strict-mode MariaDB job: success; "
            "21 of 23 jobs were success when read, the deployment overlay red by design, and one sales-channel MySQL job (which holds none of these tests) was still running.")
for _rid, _idx, _run in (('F-02', 0, _RUN_344), ('F-02', 3, _RUN_344), ('F-03', 2, _RUN_345), ('F-03', 4, _RUN_345)):
    _state, _text = CRITERIA[_rid][_idx]
    assert _state == 'CODED', (_rid, _idx, _state)
    CRITERIA[_rid][_idx] = ('MET', _NOT_READ.sub('', _text.replace('5afda458', 'cb4ca5f2')).rstrip(' .;') + '.' + _run)
CRITERIA['F-02'][1] = ('MISSING', CRITERIA['F-02'][1][1].replace('5afda458', 'cb4ca5f2'))
EXTRA_LINKS['F-02'] = [{'repo': 'lavkoo', 'pr': 112, 'sha': 'cb4ca5f2'}]
UPDATES['F-02'] = UPDATES['F-02'].replace('5afda458', 'cb4ca5f2')
NEXT_CURRENT['F-02'] = "F-02.2 (a draft edit of one storefront cannot change a banner row another storefront shows) and F-04 (the caches of the campaign, experiment and section-reach screens per storefront or declared global); the CI of PR 112 is read (run 344)."
NEXT_CURRENT['F-03'] = "F-03.3 (a version is still one publish for every channel); the owner's decision on whether the seller app should ever be sent sections; the CI of PR 113 is read (run 345)."

# ---- K-V3 (added by the verifier): a queued POS sale is never dropped silently (lavkooappseller #8) ----
CRITERIA.setdefault('K-V3', {}).update({
    0: ('CODED', "lavkooappseller #8 (draft, base main, head b2e4d04 (this change in b2e4d04), client only): OutboxPolicy says that a queued sale that has used its ten attempts has failed, is no longer replayed by every return of the connection (so it blocks none behind it) and is never dropped: the outbox keeps it with its last error; the banner above the POS says how many sales could not be sent and opens FailedSalesSheet, which lists each with the customer, the amount, the day and the reason the server gave, lets the seller retry them all (each replayed under the key it was first sent with) and remove one only after an explicit yes (test/outbox_policy_test.dart, 9 tests; six mutations each fail it; flutter analyze finds no warning; the seller CI runs it by name). The sheet is not run on a device; four new strings in en and ar need a native reader; CI not read"),
})
EXTRA_LINKS['K-V3'] = EXTRA_LINKS.get('K-V3', []) + [{'repo': 'lavkooappseller', 'pr': 8, 'sha': 'b2e4d04'}]
UPDATES['K-V3'] = "lavkooappseller #8 (draft, b2e4d04): a queued POS sale that failed ten replays was deleted without a word; it now stays on the device with its last error, is listed by the banner and a sheet, and is retried or removed only by the seller. The criterion is still the one placeholder the verifier wrote; its real criteria (what the seller sees, how long a sale may wait, who may remove it) are not written."
NEXT_CURRENT['K-V3'] = "Read the CI of lavkooappseller #8; write the real criteria of K-V3; run the sheet on a device (it needs the providers of the app)."
STATE_AFTER['K-V3'] = 'IMPLEMENTED IN DRAFT'

# ---- the same behaviour as D1-04 criterion 2 (PR 87 and PR 90), under the requirements that name it again ----
_ORDER_RECORD_PROOF = (" The same behaviour as D1-04 criterion 2, shown here because the plan names it again. CI: run 302 on 26995055 (read job by job on 2026-11-18: 22 of 23 jobs success, the deployment overlay red by design; PR 90's head on its older PR 87 base, so it proves PR 90's stamp) and the integration run 342 (head a5f7de59, which holds PR 87 and PR 90, read job by job on 2026-11-18): "
                       "'Tests, SQLite' on PHP 8.2 and 8.3 (the whole suite) and the Storefront and Address groups on MySQL 8.0 and on MariaDB 11.4.2: success. The strict-mode job is green but does not run these directories, so it is not proof here (strict-mode runs of these tests are local and reported in the PR body only).")
_ORDER_RECORD = ("PR 87 (OrderStorefrontSnapshot) and PR 90 (OrderLegalEntitySnapshot): an order carries storefront_country, currency_code and currency_rate and legal_entity_id and entity_snapshot, as columns of their own beside seller_id, seller_is and shop_id, "
                 "stamped at placement by OrderStorefrontSnapshot::record(), which OrderManager::generateOrder (web and API checkout) and the seller-app POS controller call, written once and never rewritten. The admin and vendor POS screens (OrderRepository::add) and the sales-channel imports (ChannelOrderMaterializer) do not call it and carry no storefront, legal entity or currency record yet (whether POS and marketplace orders count as storefront orders is an open owner decision). "
                 "Nothing is stamped while the switches are off (the default); the currency is empty for an order of the default storefront on a multi-currency platform, and an order whose lines span two countries gets no storefront or company; a country with no active company gets no legal_entity_id. "
                 "OrderStorefrontSnapshotTest and OrderLegalEntitySnapshotTest call record() directly on orders built in the test (each of the six storefronts, written once, a later change never rewrites it, the switches, before either migration); the placement hook is shown end to end only for the API cash-on-delivery checkout of the six storefronts (StorefrontCheckoutSnapshotTest, storefront and currency; no test places an order and reads legal_entity_id, and the seller POS call site is read from code, not tested).")
for _rid, _idx in (('A1-10', 3), ('A2-09', 2), ('A2-09', 3)):
    CRITERIA.setdefault(_rid, {})[_idx] = ('MET', _ORDER_RECORD + _ORDER_RECORD_PROOF)
EXTRA_LINKS['A1-10'] = EXTRA_LINKS.get('A1-10', []) + [{'repo': 'lavkoo', 'pr': 90, 'sha': '26995055'}]
EXTRA_LINKS['A2-09'] = EXTRA_LINKS.get('A2-09', []) + [{'repo': 'lavkoo', 'pr': 90, 'sha': '26995055'}]

# ---- the same behaviour as A2-15 criterion 1 (PR 93, run 295) and as D1-03 criterion 0 (PR 90, runs 302 and 342), under the requirements that name it again ----
CRITERIA.setdefault('D2-17', {})[3] = ('MET', "PR 93 (draft, main based, 5e128f06) removes the write of customer, guest, address and order ids to storage/logs/order_generate_test.txt from the paid-order hook and adds a test that fails with the write and passes without it. The same behaviour as A2-15 criterion 1, shown here because the plan names it again. "
                                          "CI: run 295 on the head, read job by job on 2026-11-18: 20 of 21 jobs success including Address tests on MySQL 8 and MariaDB 11.4.2, Fulfillment and security on both, strict mode on MariaDB and SQLite 8.2 and 8.3 (the overlay job is red by design).")
EXTRA_LINKS['D2-17'] = EXTRA_LINKS.get('D2-17', []) + [{'repo': 'lavkoo', 'pr': 93, 'sha': '5e128f06'}]
CRITERIA.setdefault('A1-09', {})[2] = ('MET', "PR 90 (draft, stacked on #87, 26995055): the legal entity is a record of its own, legal_entities, with one row for each country (unique) holding the legal name, trade name, tax and commercial registration, invoice series key, address and active flag, separate from the storefront and from the country (LegalEntityRegistryTest, LegalEntityCommandsTest). "
                                          "The same behaviour as D1-03 criterion 0, shown here because the plan names it again. CI: run 302 on 26995055 and the integration run 342 (head a5f7de59, which holds PR 90), read job by job on 2026-11-18: the whole suite on SQLite 8.2 and 8.3, the Storefront groups on MySQL 8.0 and MariaDB 11.4.2 and the strict-mode job: success.")
EXTRA_LINKS['A1-09'] = EXTRA_LINKS.get('A1-09', []) + [{'repo': 'lavkoo', 'pr': 90, 'sha': '26995055'}]

# ---- PR 114 run 348 on 9f099f97, read job by job on 2026-11-18 ----
CRITERIA['I2-01'][3] = ('MET', "PR 114 (draft, stacked on PR 105, 9f099f97, no migration, no switch): StorefrontLegalPublisher is the only writer of storefront_legal_documents; edit changes a draft (an approved text goes back to draft and its approval is void) and refuses a published, revoked or discarded text; a change of a published text is draftNewVersionOf, a new draft that starts as a copy of it while the old text stays in force; StorefrontLegalPublisherTest (36 tests, among them a moment given in UTC taking effect at that instant in an application set east of UTC, west of it and in UTC; eleven mutations of the service fail it). "
                              "CI: run 348 on 9f099f97, read job by job on 2026-11-18: Tests, SQLite on PHP 8.2 (7,130 tests, 99 skipped) and PHP 8.3 success; Storefront tests on MySQL 8 and on MariaDB 11.4.2 success, 841 tests and 2,826 assertions each; Address tests, Fulfillment and security tests and the sales-channel jobs that had finished on both engines success; the secret scan success; the run finished at 18:40 UTC with 22 of 23 jobs success and the deployment overlay red by design, the strict-mode MariaDB job (success at 18:20) and the last sales-channel job (success at 18:40) among them; the strict-mode job does not run tests/Feature/Storefront and is not proof for these tests")
CRITERIA['I2-03'][3] = ('MET', "PR 114 (9f099f97): draftFromTemplate copies a template into a draft carrying the template's hash and never publishes it, a text that is still its template is not approved, and the readiness of a storefront reads exactly what the service wrote (StorefrontLegalPublisherTest, 36 tests; StorefrontLegalTextCommandTest, 11 tests). "
                              "CI: run 348 on 9f099f97, read job by job on 2026-11-18: Tests, SQLite on PHP 8.2 and 8.3 success; Storefront tests on MySQL 8 and on MariaDB 11.4.2 success (841 tests, 2,826 assertions each, the two classes among them); the overlay job is red by design; the run finished with 22 of 23 jobs success, the strict-mode MariaDB job among them, which does not run tests/Feature/Storefront and is not proof for these two classes")
NEXT_CURRENT['I2-01'] = "The Saudi baseline as version 1 of each text (needs the owner's text) and the retention rules."
NEXT_CURRENT['I2-03'] = "Read the CI of PR 115 per job (queued behind PR 114 at the last read); then the GCC-20 step-up for publishing and revoking, where templates come from (owner), and a scope by country."

# ---- lavkooappseller #9 (8a25336d): push taps by data (K-11 criterion 3), ratings of approved reviews (K-07 criterion 4); E-11 criterion 4 is the idempotency key of #8 ----
CRITERIA.setdefault('K-11', {}).update({
    3: ('CODED', "lavkooappseller #9 (draft, base main, head 8a25336d (this change in 8dab1372), client only): NotificationRoute decides where a push opens from the data of the message (its type and its numbers) and never from the words of its title, for the tap of a local notification and for the opening of the app from a push alike, in the order the local tap always had (an order number opens the order unless the message is about a refund); the order number an older server also puts in the title key is read only when the data names no order; the handler no longer tests the English words Order, Theme or chatting in the title, no longer dereferences the notification part of a data message and no longer opens the order twice for a refund (test/notification_route_test.dart, 13 tests: every type, the order of the checks, an Arabic and an English title giving the same screen, the title key, the handler read as source; nine mutations each fail it; the whole seller suite passes except the known version gate test; the seller CI runs it by name; the screens are not run on a device); CI not read"),
})
CRITERIA.setdefault('K-07', {}).update({
    4: ('CODED', "lavkooappseller #9 (draft, base main, head 8a25336d (this change in 8a25336d), client only): the two places that averaged the reviews of a product (the POS product dialog and the product sheet of an order edit) took every review the product came with, failed on a review without a rating and divided by zero for a product with none; both now ask ProductHelper.getApprovedReviewAverage, which averages the approved reviews only (status 1, as Review::scopeActive on the server), skips a review with no rating and gives 0 when nothing was approved. The helper is the one lavkooappseller #3 adds, with its text and the line of the order-edit sheet identical to #3's, so the two pull requests merge without a conflict in those files, whichever comes first (test/approved_rating_screens_test.dart, 8 tests, among them a review that is not approved never moving the rating whatever its value and no hand-written sum of ratings left under lib; five mutations each fail it; the seller CI runs it by name); CI not read"),
})
CRITERIA.setdefault('E-11', {}).update({
    4: ('CODED', "lavkooappseller #8 (draft, base main, head b2e4d04 (this change in 6ae226a), client only): the seller app sends one stable Idempotency-Key per POS sale (PosIdempotencyKey, a random version 4 UUID) in the Idempotency-Key header through the cart service and repository, queues the sale with the same key when the request got no answer, stores the key with the sale on disk and replays the sale under it, which the server's POS sale writer already honours (PosSaleReplayedException); a sale an earlier version queued is given a key, stored before it is sent (test/pos_idempotency_key_test.dart, 11 tests, the real repository through a recording Dio adapter; seven mutations each fail it; the seller CI runs it by name). The same behaviour as K-06 criterion 1; CI not read"),
})
EXTRA_LINKS['K-11'] = EXTRA_LINKS.get('K-11', []) + [{'repo': 'lavkooappseller', 'pr': 9, 'sha': '8a25336d'}]
EXTRA_LINKS['K-07'] = EXTRA_LINKS.get('K-07', []) + [{'repo': 'lavkooappseller', 'pr': 9, 'sha': '8a25336d'}]
EXTRA_LINKS['E-11'] = EXTRA_LINKS.get('E-11', []) + [{'repo': 'lavkooappseller', 'pr': 8, 'sha': 'b2e4d04'}]
UPDATES['K-11'] = UPDATES.get('K-11', '') + " lavkooappseller #9 (draft, 8a25336d): a push opens the screen its data names in any language; before, the open of the app from a push looked for the English words Order, Theme and chatting in the title, so a seller who reads Arabic tapped a new order and nothing opened. Not done: the market of a push (K-11.1, K-11.2) and the lavkooseller:// link (K-11.4)."
UPDATES['K-07'] = UPDATES.get('K-07', '') + " lavkooappseller #9 (draft, 8a25336d): the rating of a product counts approved reviews only on the POS dialog and the order-edit sheet."
UPDATES['E-11'] = UPDATES.get('E-11', '') + " lavkooappseller #8 (draft, b2e4d04): the seller app sends an Idempotency-Key with each POS sale and replays it from the outbox. Not done: the admin and vendor POS charging the server-computed total (E-11.5)."

# ---- lavkooappseller #8, run 21 on b2e4d04, read per step on 2026-11-18: the evidence joins the coded criteria, which stay coded (their flows are pinned by source scans, not run end to end) ----
_SELLER_RUN_21 = (" CI: run 21 on b2e4d04 (PR #8's own run), read per step on 2026-11-18: install, analyze and the translation check success; the named step 'Test the POS rules' success, 40 tests in the five POS test files, this change's among them; "
                  "the full 'Test' step ended 181 passed, 1 failed, the one failure being the known app_version_gate_test (pubspec version 1.0.0 against the backend minimum 2.0.1), which this change does not touch; the Android build was skipped because that job is red for it.")
for _rid, _idx in (('K-06', 1), ('K-06', 3), ('K-06', 4), ('K-05', 5), ('K-V3', 0), ('E-11', 4)):
    _state, _text = CRITERIA[_rid][_idx]
    assert _state == 'CODED', (_rid, _idx, _state)
    CRITERIA[_rid][_idx] = (_state, _NOT_READ.sub('', _text).rstrip(' .;') + '.' + _SELLER_RUN_21)
NEXT_CURRENT['K-06'] = "K-06.2 (a queued sale is replayed only under the seller and market that took it; the market half needs the market choice of lavkooappseller #1 and #2); the CI of lavkooappseller #8 is read (run 21)."

# ---- PR 115 run 349 on f5913ab7, read job by job on 2026-11-18 ----
_RUN_349 = (" CI: run 349 on f5913ab7 (PR 115's own run, which holds PR 111 and PR 114), read job by job on 2026-11-18: Tests, SQLite on PHP 8.2 (7,264 tests, 99 skipped as on its base) and on PHP 8.3, and the Storefront tests on MySQL 8 and on MariaDB 11.4.2 (975 tests, 3,505 assertions each, the 6 skipped being the side-bar test, SQLite only by design): success; "
            "Address tests and Fulfillment and security tests on both engines and the secret scan success, and the strict-mode MariaDB job success (it ended at 18:18 UTC). The deployment overlay is red by design; two sales-channel MySQL jobs, which hold none of these tests, were still running when read and are not claimed.")
for _rid in ('I2-11', 'I2-03'):
    _state, _text = CRITERIA[_rid][4]
    assert _state == 'CODED', (_rid, _state)
    CRITERIA[_rid][4] = ('MET', _text.replace('; SQLite here, MySQL and MariaDB not run); CI not read', ').').replace('SQLite here, MySQL and MariaDB not run); CI not read', ').').rstrip(' .;') + '.' + _RUN_349)
NEXT_CURRENT['I2-11'] = "The GCC-20 step-up for activate and pause; the owner's decisions in docs/gcc/I2-STOREFRONT-ADMIN-SCREENS.md."
NEXT_CURRENT['I2-03'] = "The GCC-20 step-up for publishing and revoking (I2-03.5), where templates come from (owner), and a scope by country."

# ---- lavkooappseller #9, run 23 on 8a25336d, read per step on 2026-11-18 ----
_SELLER_RUN_23 = (" CI: run 23 on 8a25336d (PR #9's own run), read per step on 2026-11-18: install, analyze and the translation check success; the named step 'Test the push route and the rating rule (K-07, K-11)' success, 21 tests passed (the decision of the route and the rule of the rating are run, the handler and the two screens are read as source); "
                  "the full 'Test' step ended 162 passed, 1 failed, the one failure being the known app_version_gate_test (pubspec version 1.0.0 against the backend minimum 2.0.1), which this change does not touch; the Android build was skipped because that job is red for it. Nothing was run on a device.")
for _rid, _idx in (('K-11', 3), ('K-07', 4)):
    _state, _text = CRITERIA[_rid][_idx]
    assert _state == 'CODED', (_rid, _idx, _state)
    CRITERIA[_rid][_idx] = ('MET', _NOT_READ.sub('', _text).rstrip(' .;') + '.' + _SELLER_RUN_23)
NEXT_CURRENT['K-11'] = "K-11.1 and K-11.2 (the market of a topic, a token and a push; they need the market choice of lavkooappseller #1 and #2 and the server's topics), K-11.4 (the lavkooseller:// link has no Dart handler); read the CI of lavkooappseller #9 again after a push."
NEXT_CURRENT['K-07'] = "The edit icon's gate in order_top_section_widget.dart against the server rules; lavkooappseller #3 (the refusal handling) and #9 (the POS dialog) are to be merged together without a conflict."

# ---- lavkooapp #18 (7cc5e176): the wallet and the points follow a change of storefront (E-04 criterion 3) ----
CRITERIA.setdefault('E-04', {}).update({
    3: ('CODED', "lavkooapp #18 (draft, stacked on #17, 7cc5e176, off by default, client only): WalletController and LoyaltyPointController follow the storefront change notifier like the wishlist: what is held for the storefront the customer left (the ledger, the wallet bonuses, the filters) is dropped before the first await and the screens told, a signed-in customer's ledgers are asked for again and a guest's are not, and an answer is judged by the storefront the request was made for and dropped when the customer has moved on, however late it arrives; the container builds both with the notifier (test/storefront_wallet_reload_test.dart, 12 tests; the inventory and registry tests count both among the 20 that are told; eleven mutations each fail it; flutter analyze finds nothing in the changed files and the whole project stays at the 255 recorded); CI not read"),
})
EXTRA_LINKS['E-04'] = EXTRA_LINKS.get('E-04', []) + [{'repo': 'lavkooapp', 'pr': 18, 'sha': '7cc5e176'}]
UPDATES['E-04'] = UPDATES.get('E-04', '') + " lavkooapp #18 (draft, 7cc5e176): the app drops the wallet, the bonuses, the points and their filters when the customer moves to another storefront, asks for them again for a signed-in customer, and never shows an answer for the storefront they left. Not done: a wallet and points ledger per market on the server (E-01), the statement per storefront (E-04.0), the seller POS lookup (E-04.1), the staff scope of the reports (E-04.4)."

# ---- lavkooappseller #10 (1b6f9550): every pull request gets the CI run (K-15 criterion 1) ----
CRITERIA.setdefault('K-15', {}).update({
    1: ('CODED', "lavkooappseller #10 (draft, stacked on #9, 1b6f9550, one line of ci.yml): the workflow ran for pull requests whose base is main only, so a pull request stacked on another had no run; pull_request has no filter on its base now (pushes to main and claude/** run as before). #10 is itself a pull request whose base is a branch, not main; CI not read"),
})
EXTRA_LINKS['K-15'] = EXTRA_LINKS.get('K-15', []) + [{'repo': 'lavkooappseller', 'pr': 10, 'sha': '1b6f9550'}]
UPDATES['K-15'] = UPDATES.get('K-15', '') + " lavkooappseller #10 (draft, 1b6f9550): a pull request stacked on another gets the CI run, because the workflow no longer filters pull requests by their base. The older stacked pull requests (#2, #5, #6 and the others) get a run once their branch contains the line."

# ---- lavkooapp run 29 on 7cc5e176 and lavkooappseller run 24 on 1b6f9550, read per step on 2026-11-18 ----
_APP_RUN_29 = (" CI: run 29 on 7cc5e176 (lavkooapp #18's own run, on push to a gcc branch), read per step on 2026-11-18: both jobs success: the analysis of the changed files and the three errors-only analyses, the step 'Test storefront' with storefronts off "
               "(527 tests passed, 23 skipped), the step 'Test storefront with storefronts on' (305 tests passed, 11 skipped), and the analysis of the whole project, which stays at the 255 recorded findings. The wallet and points tests ran in both settings; the controllers' wiring in the container is read as source and nothing was run on a device.")
_state, _text = CRITERIA['E-04'][3]
assert _state == 'CODED'
CRITERIA['E-04'][3] = ('MET', _NOT_READ.sub('', _text).rstrip(' .;') + '.' + _APP_RUN_29)
_SELLER_RUN_24 = (" CI: run 24 on 1b6f9550 (lavkooappseller #10's own run, event pull_request, base gcc/p1-seller-push-route-and-rating and not main), read per step on 2026-11-18: the same job ran as for a pull request on main: install, analyze and the translation check success, the named step 'Test the push route and the rating rule' success (21 tests), "
                  "the full 'Test' step ended 162 passed, 1 failed, the one failure being the known app_version_gate_test; the Android build was skipped because that job is red for it. The older stacked pull requests (#2, #5, #6 and the others) get a run once their branch contains the line.")
_state, _text = CRITERIA['K-15'][1]
assert _state == 'CODED'
CRITERIA['K-15'][1] = ('MET', _NOT_READ.sub('', _text).rstrip(' .;') + '.' + _SELLER_RUN_24)
NEXT_CURRENT['E-04'] = "E-04.0 (a statement request under a storefront returns that storefront's ledger and balance: needs the per-market accounts of E-01), E-04.1 (the seller POS customer lookup), E-04.4 (the staff scope of the wallet reports)."
NEXT_CURRENT['K-15'] = "K-15.2 (the whole flutter test green on every head: the version-gate test is the owner's decision A, B or C), K-15.3 (a debug APK builds after the tests), K-15.4 (a named step for each feature); merge #10's line into the older stacked pull requests so that they have a run."

# ---- the audit of the criteria that moved since the 15:30 tracker of 2026-11-18 (wf_cb876f57-ad1): two verifiers for each unit, one on the CI and one on the tests, and a judge for every criterion either of them flagged ----
# 24 criteria keep their state and say only what ran; 6 go back to CODED and 1 to MISSING. data/flips-audit-2026-11-18.json holds each decision with its reason and the replacement text the judge wrote.
_AUDIT = json.load(open(T.MASTER + 'flips-audit-2026-11-18.json', encoding='utf-8'))['judged']
_KEEP_CI = ' [keep the CI run 342 sentence unchanged]'
_RUN_342_STOREFRONT = (" CI run 342 (the all-in-one integration, head a5f7de59, which holds this pull request's code), read per job on 2026-11-18: 'Tests, SQLite' on PHP 8.2 and 8.3 (the whole suite, which includes tests/Feature/Storefront) and "
                       "'Storefront tests, MySQL 8' and 'Storefront tests, MariaDB 11.4.2' (2,460 tests each, 2 skipped, none of them named here): success. The strict-mode job does not run tests/Feature/Storefront, so these tests have run under strict mode only locally; the pull request's own run is not read.")


def _judged(criterion):
    return _AUDIT[criterion]['replacement']


def _judged_from(criterion, marker):
    text = _judged(criterion)
    return text[text.index(marker):]


# The lifecycle (PR 111): what the command prints and what no test asserts, what the apps read, and a stop mode that has no continuity test.
CRITERIA['I2-12'][1] = ('MET', 'PR 111 (d8f6a7ea): ' + _judged('I2-12.1') + _RUN_342_STOREFRONT)
CRITERIA['I2-14'][4] = ('MET', _judged('I2-14.4') + " The pull request's own run is not read.")
CRITERIA['I2-16'][4] = ('CODED', _judged('I2-16.4') + _RUN_342_STOREFRONT)
UPDATES['I2-16'] = "PR 111 (d8f6a7ea): every stop mode refuses a new sale (a test for each mode, for the refusal and for the list), and the routes that serve earlier orders carry neither gate, pinned by a test that is not run in any one mode. Not done: a refund, a webhook, a payment callback, a settlement, a shipment or a support action run while a storefront is paused, in each mode."
NEXT_CURRENT['I2-16'] = "A continuity test per stop mode: an earlier order's refund request, payment callback, webhook, shipment status and support chat answered while the storefront is paused in browse-only, maintenance and redirect (criterion 4 goes back to MET with it)."

# The legal review (PR 105): the mechanism exists, the review does not.
CRITERIA['I2-07'][2] = ('CODED', _judged('I2-07.2'))
UPDATES['I2-07'] = UPDATES['I2-07'].replace('and a recorded review for each country.', 'and a recorded review for each country (criterion 2 is CODED: PR 105 adds the mechanism, and the criterion becomes MET only when the owner records a real review for each non-default country that is to sell).')
NEXT_CURRENT['I2-07'] = "The owner records an independent review (reviewer, reference, day) for each non-default country that is to sell, with gcc:storefront:legal-review; the scan of language files, views and API payloads for compliance claims."

# The currency, the tax and the coupons of PR 87 and PR 99.
CRITERIA['D1-05'][4] = ('MET', _judged('D1-05.4'))
_title, _state, _text = CRITERIA_SPLIT['D1-07'][2][0]
assert _state == 'MET' and _text.endswith(RUN_342)
CRITERIA_SPLIT['D1-07'][2][0] = (_title, _state, _text[:-len(RUN_342)] + ' ' + _judged('D1-07.4'))
_title, _state, _text = CRITERIA_SPLIT['D1-07'][2][1]
_routes = 'the storefront list, every checkout, payment and top-up route and the readiness report follow; StorefrontTaxApprovalTest (47)'
assert _state == 'MET' and _text.endswith(RUN_342) and _text.count(_routes) == 1
CRITERIA_SPLIT['D1-07'][2][1] = (_title, _state, _text[:-len(RUN_342)].replace(_routes, "the storefront list, every checkout, payment and top-up route (StorefrontSalesRoutesTest pins the sale gate on every route that starts a sale and keeps refunds, cancellations, callbacks and dues open; StorefrontTaxApprovalTest drives cash on delivery, wallet and the storefront list through it) and the readiness report (StorefrontTaxReadinessTest, 11) follow; StorefrontTaxApprovalTest (47)") + ' ' + _judged_from('D1-07.5', 'CI run 342'))
CRITERIA['I1-12'][3] = ('CODED', _judged('I1-12.3').replace('CODED: ', 'PR 87 (ea8ed21c): ', 1))
CRITERIA['I1-12'][4] = ('MET', _judged('I1-12.4'))
CRITERIA['I1-13'][1] = ('MET', _judged('I1-13.1'))
CRITERIA['I1-13'][2] = ('MET', _judged('I1-13.2'))
UPDATES['I1-12'] = UPDATES['I1-12'].replace('The admin form, list, quick view and export still show the platform currency.', "The admin edit form, quick view and export show a coupon in its own money (StorefrontCouponAdminViewTest) and the add form has the storefront select; the coupon list table was not checked. Redemption in dinars is not proven: no test applies a KW coupon to a KW cart and asserts the amounts (criterion 3 is CODED).")
NEXT_CURRENT['I1-12'] = "One test that applies a KW coupon to a KW cart (OrderManager::getTotalCouponAmount or /api/v1/coupon/apply in a KW request) and asserts the discount and the minimum in dinars (criterion 3 goes back to MET with it); then the round-trip matrix for KWD, BHD and OMR under the single and the multi-currency model."

# Embedded customers, the Saudi golden pack, the baseline guard, the logs and the sign-in across storefronts (PR 100, 97, 104, 106, 96).
CRITERIA['A2-15'][2] = ('MET', _judged('A2-15.2').replace(_KEEP_CI, ')') + RUN_342)
CRITERIA['M2-01'][2] = ('MET', _judged('M2-01.2').replace(_KEEP_CI, ')') + RUN_342)
_state, _text = CRITERIA['M2-01'][3]
_mutations = "six mutations each fail it, the job's own command passes on MySQL 8.0.46 and the CI structure tests (28) pass on the integration tree; no branch protection rule requires the jobs yet (DR-4)."
assert _state == 'MET' and _text.count(_mutations) == 1
CRITERIA['M2-01'][3] = (_state, _text.replace(_mutations, "six mutations each fail it (local runs, as are 'the job's own command passes on MySQL 8.0.46' and the head c1aac4f7, which is not an ancestor of a5f7de59: the code commit cbe92413 is, and the files are identical); the CI structure tests (28) pass on the integration tree. "
                                                              "The job 'Saudi baseline expectations unchanged' runs only on pull_request events: it was skipped in the integration run 342 (workflow_dispatch), and PR 104's own runs 323 and 328 were cancelled, so that job has not yet executed under GitHub Actions. Its rule is proven by SaudiBaselineJobTest, which reads the wiring and runs the same script against real git histories inside the SQLite suite of run 342; "
                                                              "the two 'Saudi baseline pack' jobs did run in run 342 and passed on MySQL 8 and MariaDB 11.4.2. The guard does not protect main until a branch protection rule requires the jobs (DR-4), and a person can bypass it with the title marker."))
CRITERIA['M2-04'][0] = ('CODED', "PR 96 (draft, stacked on #87, 6f55384e; 03311d46, an ancestor of a5f7de59, added the referred_by column the test depended on): CustomerAcrossStorefrontsTest authenticates with a token and reads /api/v1/customer/info with an X-Storefront header from each of the six storefronts (6 provider cases) and asserts the same id and e-mail and that no user was created for a storefront. "
                              "It never signs in: the sign-in with credentials and the registration are not exercised in any storefront (no customer login or registration test sends a storefront header), so the sentence's central behaviour (the same credentials sign in through any storefront) and its negative case are not proven. The code supports it: the customer auth controllers have no storefront reference and there is one global users table. "
                              "It goes back to MET when a test signs one customer in through two storefronts (API and web) and asserts one users row." + _RUN_342_STOREFRONT)
UPDATES['M2-04'] = "PR 96 (draft, 6f55384e): the customer's own account answers the same id and e-mail from each of the six storefronts and no user is created for a storefront (CustomerAcrossStorefrontsTest, through a token). Not proven: a sign-in or a registration through a storefront; criterion 0 is CODED until a test signs one customer in through two storefronts."
NEXT_CURRENT['M2-04'] = "A test that signs one customer in through two storefronts (API and web) with the same credentials and asserts one users row (criterion 0 goes back to MET with it)."
CRITERIA['M2-13'][4] = ('MET', _judged('M2-13.4').replace(_KEEP_CI, '') + RUN_342)
CRITERIA['M2-18'][5] = ('MET', _judged('M2-18.5').replace(_KEEP_CI, '') + RUN_342)

# The seller contract of PR 108: what ran locally and what ran in CI.
_state, _text = CRITERIA['K-17'][1]
_pass = 'the 55 tests of its two classes pass on SQLite and on MySQL 8 (strict included)'
assert _state == 'MET' and _text.count(_pass) == 1
CRITERIA['K-17'][1] = (_state, _text.replace(_pass, "the 55 tests of its two classes (SellerWhoAmITest 17, SellerOrderMarketTest 38) pass on SQLite and on MySQL 8.0.46 including strict mode in the author's local runs only (the pull request body: 247 assertions; a local run, not CI proof)") +
                       " In run 342 SellerWhoAmITest ran in Tests, SQLite (PHP 8.2 and 8.3, whole suite) and in Fulfillment + security tests (tests/Feature/Security) on MySQL 8 and MariaDB 11.4.2, and SellerOrderMarketTest ran in the same SQLite jobs and in Storefront tests (tests/Feature/Storefront) on MySQL 8 and MariaDB 11.4.2, all non-strict; "
                       "the strict-mode job on MariaDB is green but runs neither class (it lists neither tests/Feature/Security nor tests/Feature/Storefront), so these two classes have not run in strict mode in CI.")

# The theme (PR 112 and PR 113): a schedule that no test runs, and checks that did not run in strict mode.
CRITERIA['F-02'][3] = ('CODED', _judged('F-02.3'))
NEXT_CURRENT['F-02'] = "F-02.2 (a draft edit of one storefront cannot change a banner row another storefront shows), F-02.3 (pass the storefront into the two audit contexts of ThemePublishDue and test the command: a due publish, a blocked cancel, a failed publish; scheduling and cancelling a publish in the admin controller write no audit line) and F-04 (the caches of the campaign, experiment and section-reach screens per storefront or declared global); the CI of PR 112 is read (run 344)."
UPDATES['F-02'] = UPDATES['F-02'].replace('and the audit lines name the storefront.', "and the audit lines theme.published, theme.activated and theme.restored name the storefront (the two lines only the schedule writes, theme.scheduled_publish_failed and theme.scheduled_publish_cancelled, do not yet, and no test runs ThemePublishDue).")
for _criterion, _idx in (('F-03', 2), ('F-03', 4)):
    _state, _text = CRITERIA[_criterion][_idx]
    _from = _text.index('CI: run 345')
    CRITERIA[_criterion][_idx] = (_state, _text[:_from] + _judged('%s.%d' % (_criterion, _idx)))

# The customer app: what the wallet reload asserts, and the maintenance screen that no test builds.
_state, _text = CRITERIA['E-04'][3]
_held = '(the ledger, the wallet bonuses, the filters)'
_asserted = '(test/storefront_wallet_reload_test.dart, 12 tests; the inventory and registry tests count both among the 20 that are told; eleven mutations each fail it;'
assert _text.count(_held) == 1 and _text.count(_asserted) == 1
CRITERIA['E-04'][3] = (_state, _text.replace(_held, '(the ledger, the wallet bonuses, the wallet filter and dates, the points filter)').replace(_asserted, "(test/storefront_wallet_reload_test.dart, 12 tests: 6 wallet, 5 points, 1 flag off; it asserts, before any await, that the held ledger and bonuses are gone and that the wallet filter and dates and the points filter are reset, "
                       "that a signed-in customer's ledgers are asked for again and a guest's are not, and that a late answer for the storefront left is never shown (wallet ledger, wallet bonuses, points); the earn-by sets of both controllers and the points dates are reset in code and asserted null, but no test sets them first, so no test would fail if those resets were removed; "
                       "the inventory and registry tests count both among the 20 that are told and the container wiring is guarded by a source test; the eleven temporary mutations are the author's local run, reported in the commit message, not repeatable from the tree and not CI proof;"))
_state, _text = CRITERIA['J-08'][5]
CRITERIA['J-08'][5] = ('CODED', _text.rstrip(' .') + ". Proven in CI: the redirect half (14 cases, run 22 on 016fd71, both jobs success) and the maintenance controller and screen (11 cases, run 28 on b7f48bf, both test steps and all analyze steps success). Not proven: that the app shows the screen. "
                       "The dashboard branch (dashboard_screen.dart lines 137-141) is read as source and compiled by an errors-only analyze step; no test builds DashBoardScreen, so deleting the branch would fail nothing. The rebuild when a newer answer says otherwise is shown only on the controller, not on the dashboard. No device was run. The website is not covered.")
UPDATES['J-08'] = UPDATES['J-08'].replace('CI runs 22 and 28 green per job.', "CI runs 22 and 28 green per job for the redirect and for the controller and the screen; no test builds the dashboard, so that the dashboard shows the screen is read as source (criterion 5 is CODED).")
NEXT_CURRENT['J-08'] = "A test that guards or pumps the dashboard branch that builds StorefrontMaintenanceScreen (criterion 5 goes back to MET with it); the Arabic, Bengali, Spanish and Hindi maintenance strings need a native reader (R5); the screen is the dashboard's only, so a page opened some other way is not covered."

# The seller app's rating (lavkooappseller #9): the card of the product list still averaged every review at 8a25336d.
CRITERIA['K-07'][4] = ('CODED', "lavkooappseller #9 (draft, base main, head 673aa9cd, client only): the three places that average the reviews of a product take ProductHelper.getApprovedReviewAverage, which averages the approved reviews only (status 1, as Review::scopeActive on the server), skips a review with no rating and gives 0 when nothing was approved: the POS product dialog and the product sheet of an order edit (8a25336d) and the card of a product in the seller's own list, product_widget.dart (673aa9cd). "
                       "The audit of 2026-11-18 found product_widget.dart still adding up every review the product came with, with a force-unwrap, at 8a25336d, and a guard test that looked for one spelling of the sum; at 8a25336d the criterion was set back to MISSING. 673aa9cd fixes the card, draws it in three widget tests (unapproved reviews left out, no reviews, a review without a rating) and widens the guard to every statement under lib that sums, folds or reduces a rating (test/product_card_rating_test.dart, 3 tests; test/approved_rating_screens_test.dart, 8; two temporary mutations each killed). "
                       "The helper is the one lavkooappseller #3 adds, with its text and the line of the order-edit sheet identical to #3's, so the two pull requests merge without a conflict in those files, whichever comes first. CI of 673aa9cd not read")
UPDATES['K-07'] = " lavkooappseller #9 (draft, 673aa9cd): the rating of a product counts approved reviews only on the POS dialog, the order-edit sheet and the card of the seller's own product list (the card was found averaging every review by the audit of 2026-11-18 and is fixed)."
CRITERIA['A1-09'][2] = ('MET', CRITERIA['A1-09'][2][1][:CRITERIA['A1-09'][2][1].index(' CI: run 302 on 26995055')] + ' ' + _judged('A1-09.2'))

# ---- lavkooappseller #8, #9, #10 and lavkooapp #18 after the independent review and the audit: the heads and the CI read per step on 2026-11-18 ----
def _move_link(requirement, repo, pr, sha):
    EXTRA_LINKS[requirement] = [dict(link, sha=sha) if (link['repo'], link['pr']) == (repo, pr) else link for link in EXTRA_LINKS[requirement]]


_SELLER_RUN_28 = (" CI: run 28 on 7c20717 (PR #8's own run), read per step on 2026-11-18: install, the formatting check, analyze and the translation check success; the named step 'Test the POS rules' success, 70 tests in the seven POS test files, this change's among them; "
                  "the full 'Test' step ended 211 passed, 1 failed, the one failure being the known app_version_gate_test (pubspec version 1.0.0 against the backend minimum 2.0.1), which this change does not touch; the Android build was skipped because that job is red for it. Nothing was run on a device.")
_REVIEWED = "an independent review of the first commit found the POS screen emptying the priced lines of a refused sale, a replay that overwrote the sales queued, removed or reopened while it ran, a sheet that said everything was synced, and a Retry that did nothing when every sale had failed; they are fixed in 8a32ac7 and 7c20717"
CRITERIA['K-06'][1] = ('MET', "lavkooappseller #8 (draft, base main, head 7c20717 (this change in 6ae226a), client only): the app sends one stable Idempotency-Key per sale and replays it unchanged from the offline outbox, run on the real classes: the repository sends the key in the Idempotency-Key header with the credential and the other headers kept (test/pos_idempotency_key_test.dart, 11 tests, through a recording Dio adapter); "
                              "the cart controller sends one key to the service and, when the request got no answer, parks the sale in the outbox under that same key and clears the cart (test/pos_sale_outcome_test.dart, 5 tests); the outbox replays a sale under the key it was first sent with, keeps it through a failed replay, and gives a sale an earlier version queued a key that is on the disk before the sale is sent (test/offline_outbox_behaviour_test.dart, 22 tests, with a service that answers as the server does). "
                              "Temporary mutations (a key made again when the sale is parked, a key not stored before the send, a replay with no session, among 18 of the review fixes and the tests) are each killed by a named test. The server half is lavkoo #35 (PosSaleIdempotencyConcurrencyTest), which is in the integration branch and not in main: against main, whose POS writer ignores the header, this client change does nothing until #35 is merged." + _SELLER_RUN_28)
CRITERIA['K-06'][3] = ('MET', "lavkooappseller #8 (draft, base main, head 7c20717 (this change in cbf579d; " + _REVIEWED + "), client only): PosOrderAnswer.isPlacedAnswer(statusCode, data) says a POS sale is recorded only when the status is 200, the answer names the order and it is not one of the refusals the server sends with a 200 (product_not_available, checkProductTypeForWalkingCustomer). "
                              "Run on the real classes: the cart controller shows the server's words for a refusal (or the general message when it gave none), never the text of a placed order, keeps what the cashier typed and queues nothing (test/pos_sale_outcome_test.dart); the outbox counts a queued sale as synced only when the answer names the order and otherwise keeps it queued with one more attempt and the refusal as its last error (test/offline_outbox_behaviour_test.dart). "
                              "The POS screen's own cleanup after a sale asks the same rule, pinned by a scan of its source and not run (test/pos_order_answer_test.dart, 10 tests). The placed path of the cart controller (the invoice screen and the refresh of the order list) is not run, because it needs the order controller of the app." + _SELLER_RUN_28)
CRITERIA['K-06'][4] = ('CODED', CRITERIA['K-06'][4][1].replace('head b2e4d04', 'head 7c20717')[:CRITERIA['K-06'][4][1].index(' CI: run 21')] + " The helper is run on ids of every length and on held and legacy carts; that the POS screen (the sale body and the digital-product check) and the resume of a held cart ask it is pinned by scans of their source, not run, so this stays CODED." + _SELLER_RUN_28)
CRITERIA['K-05'][5] = ('CODED', CRITERIA['K-05'][5][1].replace('head b2e4d04', 'head 7c20717')[:CRITERIA['K-05'][5][1].index(' CI: run 21')] + _SELLER_RUN_28)
CRITERIA['K-V3'][0] = ('MET', "lavkooappseller #8 (draft, base main, head 7c20717 (this change in b2e4d04; " + _REVIEWED + "), client only): a queued POS sale that has failed ten replays is never dropped silently, it stays visible with its last error until the seller removes it. OutboxPolicy says that a sale that has used its ten attempts has failed and is no longer replayed by every return of the connection, so it blocks none behind it; "
                            "the outbox keeps it on the device and on the disk however many times it fails or is retried (run on the real controller: ten attempts, fifteen more passes send nothing, three rounds of retry, the sale is still queued); a sale queued, removed or reopened while a replay is on its way is kept, stays removed or stays reopened. "
                            "The banner above the POS says how many sales wait and how many could not be sent and offers the details only when one has failed; FailedSalesSheet lists each failed sale with the customer, the amount, the day and the reason the server gave (the general message when it gave none), says how many wait and never that all are synced after a retry has reopened them, lets the seller retry them (the banner's Retry does the same when every sale has failed) and removes one only when the seller says yes, a no leaving it in the queue and on the disk "
                            "(test/offline_outbox_behaviour_test.dart, 22 tests, widget tests with an injected amount format; test/outbox_policy_test.dart, 9 tests; 18 temporary mutations of the review fixes and the tests, each killed by a named test; flutter analyze finds no warning). The sheet is not run on a device, because the real amount format needs the providers of the app; four new strings in en and ar need a native reader. "
                            "How long a sale may wait and who may remove it are not written: K-V3 is still the one placeholder the verifier wrote and its requirement stays IMPLEMENTED IN DRAFT." + _SELLER_RUN_28)
CRITERIA['E-11'][4] = ('MET', "lavkooappseller #8 (draft, base main, head 7c20717 (this change in 6ae226a), client only): the seller app sends one stable Idempotency-Key per POS sale (PosIdempotencyKey, a random version 4 UUID) in the Idempotency-Key header through the cart service and repository, queues the sale with the same key when the request got no answer, stores the key with the sale on disk and replays the sale under it; a sale an earlier version queued is given a key that is stored before it is sent. "
                              "The server's POS sale writer honours the key in lavkoo #35 (7c75b7f, a draft pull request, PosSaleReplayedException, E-11 criterion 3): it is in the integration branch and not in main, whose POS writer ignores the header, so this client change does nothing against main until #35 is merged. Run on the real classes: the repository sends the header through a recording Dio adapter (test/pos_idempotency_key_test.dart, 11 tests), the cart controller parks the sale under the key it sent (test/pos_sale_outcome_test.dart), "
                              "and the outbox replays under the stored key and stores a legacy sale's new key before the send (test/offline_outbox_behaviour_test.dart). The same behaviour as K-06 criterion 1." + _SELLER_RUN_28)
for _requirement in ('K-06', 'K-05', 'K-V3', 'E-11'):
    _move_link(_requirement, 'lavkooappseller', 8, '7c20717')
UPDATES['K-06'] = UPDATES['K-06'].replace('(draft, b2e4d04)', '(draft, 7c20717)').replace('the answer is now read, not the status.', 'the answer is now read, not the status, by the cart controller, the outbox and the POS screen alike (an independent review found the screen emptying the priced lines of a refused sale, the outbox overwriting the sales queued during a replay and a sheet that said everything was synced; all fixed and run as behaviour).')
UPDATES['K-05'] = UPDATES['K-05'].replace('(draft, b2e4d04)', '(draft, 7c20717)')
UPDATES['K-V3'] = "lavkooappseller #8 (draft, 7c20717): a queued POS sale that failed ten replays was deleted without a word; it now stays on the device with its last error, is listed by the banner and a sheet, and is retried or removed only by the seller, run as behaviour on the real controller, sheet and banner. The criterion is still the one placeholder the verifier wrote; its real criteria (how long a sale may wait, who may remove it) are not written."
UPDATES['E-11'] = UPDATES['E-11'].replace('(draft, b2e4d04)', '(draft, 7c20717)')
NEXT_CURRENT['K-06'] = "K-06.2 (a queued sale is replayed only under the seller and market that took it; the market half needs the market choice of lavkooappseller #1 and #2); K-06.4 stays CODED until the POS screen and the resume of a held cart are run, not scanned. Lavkoo #35 must be merged for the key to do anything against the server."
NEXT_CURRENT['K-V3'] = "Write the real criteria of K-V3 (how long a sale may wait, who may remove it: the finance owner's decision); run the sheet on a device (it needs the providers of the app)."

def _seller_run_26(what_is_run):
    return (" CI: run 26 on 673aa9cd (PR #9's own run), read per step on 2026-11-18: install, the formatting check, analyze and the translation check success; the named step 'Test the push route and the rating rule (K-07, K-11)' success, 26 tests passed (" + what_is_run + "); "
            "the full 'Test' step ended 167 passed, 1 failed, the one failure being the known app_version_gate_test (pubspec version 1.0.0 against the backend minimum 2.0.1), which this change does not touch; the Android build was skipped because that job is red for it. Nothing was run on a device.")
CRITERIA['K-07'][4] = ('MET', CRITERIA['K-07'][4][1].replace(' CI of 673aa9cd not read', '').rstrip(' .;') + '.' + _seller_run_26('the rule of the rating and the card of a product are run; the POS dialog and the sheet of an order edit are read as source'))
CRITERIA['K-11'][3] = ('MET', CRITERIA['K-11'][3][1].replace('head 8a25336d (this change in 8dab1372)', 'head 673aa9cd (this change in 8dab1372)')[:CRITERIA['K-11'][3][1].index(' CI: run 23')] +
                       " A seller who is signed out is taken nowhere by a notification (673aa9cd: NotificationRoute.targetOf takes signedIn, and the one opener passes the real login state; found by an independent review: an old notification tapped on the login screen opened a screen that asked the server with no credential, a 401 and a session wipe over the login screen); test/notification_route_test.dart, 15 tests, test/approved_rating_screens_test.dart and test/product_card_rating_test.dart are run by the named step." + _seller_run_26('the decision of the route, with and without a signed-in seller, is run; the handler that asks it is read as source'))
_move_link('K-11', 'lavkooappseller', 9, '673aa9cd')
_move_link('K-07', 'lavkooappseller', 9, '673aa9cd')
UPDATES['K-11'] = UPDATES['K-11'].replace('(draft, 8a25336d)', '(draft, 673aa9cd)') + ' A seller who is signed out is taken nowhere by a notification.'
UPDATES['K-07'] = UPDATES['K-07'].replace('(draft, 673aa9cd)', '(draft, 673aa9cd, CI run 26 read)')
_SELLER_RUN_27 = (" The same job ran on 99927e8 (the merge of #9's later head, run 27, event pull_request, base gcc/p1-seller-push-route-and-rating): install, analyze and the translation check success, the named step success (26 tests), the full 'Test' step ended 167 passed, 1 failed, the one failure being the known app_version_gate_test; the Android build was skipped because that job is red for it.")
CRITERIA['K-15'][1] = ('MET', CRITERIA['K-15'][1][1].replace('1b6f9550', '99927e8', 1).rstrip(' .') + '.' + _SELLER_RUN_27)
_move_link('K-15', 'lavkooappseller', 10, '99927e8')

CRITERIA['E-04'][3] = ('MET', "lavkooapp #18 (draft, stacked on #17, 8de2259, off by default, client only): WalletController and LoyaltyPointController follow the storefront change notifier like the wishlist: what is held for the storefront the customer left (the ledger, the wallet bonuses, the wallet filter and dates, the points filter) is dropped before the first await and the screens told, a signed-in customer's ledgers are asked for again when they had been asked for (a customer who never opened the wallet or the points makes no request for them) and a guest's are not, "
                       "and an answer is judged by the storefront the request was made for and dropped when the customer has moved on, however late it arrives; the container builds both with the notifier. test/storefront_wallet_reload_test.dart, 16 tests (9 wallet, 6 points, 1 flag off), asserts, before any await, that the held ledger and bonuses are gone and that the wallet filter and dates and the points filter are reset, that a signed-in customer's ledgers are asked for again and a guest's are not, that only the half that was asked for is loaded again, and that a late answer for the storefront left is never shown (wallet ledger, wallet bonuses, points); "
                       "the earn-by sets of both controllers and the points dates are reset in code and asserted null, but no test sets them first, so no test would fail if those resets were removed; the inventory and registry tests count both among the 20 that are told and the container wiring is guarded by a source test; the temporary mutations (eleven of the first commit, four of the second) are the author's local runs, reported in the commit messages, not repeatable from the tree and not CI proof; flutter analyze finds nothing in the changed files and the whole project stays at the 255 recorded. "
                       "CI: run 30 on 8de2259 (lavkooapp #18's own run, on push to a gcc branch), read per step on 2026-11-18: both jobs success: the analysis of the changed files and the three errors-only analyses, the step 'Test storefront' with storefronts off (531 tests passed, 23 skipped), the step 'Test storefront with storefronts on' (309 tests passed, 11 skipped), and the analysis of the whole project, which stays at the 255 recorded findings. The wallet and points tests ran in both settings; the controllers' wiring in the container is read as source and nothing was run on a device. Run 29 on the first commit, 7cc5e176, was green as well.")
_move_link('E-04', 'lavkooapp', 18, '8de2259')
UPDATES['E-04'] = UPDATES['E-04'].replace('(draft, 7cc5e176)', '(draft, 8de2259)') + ' A customer who never opened the wallet or the points makes no request for them when the storefront changes.'

# ---- PR 115 run 350 on 2120fa3b (documentation-only commit after f5913ab7; it replaced run 349, which the push cancelled), read job by job on 2026-11-18 at 19:37 UTC ----
_RUN_350 = (" Run 350 on 2120fa3b (the same pull request after a documentation-only commit that counts the lifecycle screen's tests; it replaced run 349) finished at 19:51 UTC and was read job by job: 22 of 23 jobs success, the deployment overlay red by design: Tests, SQLite on PHP 8.2 and 8.3, the Storefront tests on MySQL 8 and on MariaDB 11.4.2, the Address tests, the Fulfillment and security tests, "
            "the sales-channel groups and the strict-mode MariaDB job (which does not run tests/Feature/Storefront and is not proof for these tests).")
for _rid in ('I2-11', 'I2-03'):
    _state, _text = CRITERIA[_rid][4]
    assert _state == 'MET' and _text.count('success, 975 tests') == 0
    CRITERIA[_rid][4] = (_state, _text.replace('(it ended at 18:18 UTC).', '(it ended at 18:18 UTC; it does not run tests/Feature/Storefront and is not proof for these tests).').rstrip(' .') + '.' + _RUN_350)
    _move_link(_rid, 'lavkoo', 115, '2120fa3b')

# ---- lavkooapp #17 (7713cd8) and #18 (4e3a895): the dashboard branch that shows the maintenance screen is pinned; runs 31 and 32 read per step on 2026-11-18 ----
_state, _text = CRITERIA['J-08'][5]
_cut = _text.index('. Proven in CI:')
assert _state == 'CODED' and _text.count('#17 (draft, stacked on #15, b7f48bf, off by default)') == 1
CRITERIA['J-08'][5] = ('MET', _text[:_cut].replace('#17 (draft, stacked on #15, b7f48bf, off by default)', '#17 (draft, stacked on #15, 7713cd8, off by default)') +
                       ". The dashboard itself is not built by any test (it needs the providers of the whole app): the branch that shows the screen in its build method is pinned by test/storefront_maintenance_dashboard_test.dart (3 tests, a source guard: the build answers with the maintenance screen before any of its content, only while storefronts are on and the customer's storefront is in maintenance, the controller is watched and not read once, and the branch is the whole of the answer; "
                       "four temporary mutations, the branch deleted, the controller read once, the flag dropped and a second return, are each killed in the author's local run), so deleting the branch now fails a test, which the audit of 2026-11-18 found it did not. CI: run 31 on 7713cd8 (#17's own run, on push to a gcc branch), read per step on 2026-11-18: both jobs success, the analysis of the changed files and the errors-only analyses of the cart and dashboard screens, the step 'Test storefront' with storefronts off (516 tests passed, 23 skipped), "
                       "'Test storefront with storefronts on' (294 passed, 11 skipped), the guard among them in both, and the analysis of the whole project; run 32 on 4e3a895 (#18, which contains it) the same (534 and 312 passed). The website is not covered, the screen is shown by the dashboard only, and no device was run.")
_move_link('J-08', 'lavkooapp', 17, '7713cd8')
UPDATES['J-08'] = UPDATES['J-08'].replace('no test builds the dashboard, so that the dashboard shows the screen is read as source (criterion 5 is CODED).', 'no test builds the dashboard, so the branch that shows the screen is pinned by a source test (3 tests, 4 mutations killed locally; criterion 5 is MET again).')
NEXT_CURRENT['J-08'] = "The Arabic, Bengali, Spanish and Hindi maintenance strings need a native reader (R5); the screen is the dashboard's only, so a page opened some other way is not covered; the website."
CRITERIA['E-04'][3] = ('MET', CRITERIA['E-04'][3][1].replace('Run 29 on the first commit, 7cc5e176, was green as well.', 'Run 29 on the first commit, 7cc5e176, was green as well, and so was run 32 on 4e3a895 (the same pull request after the merge of #17, which adds the pin of the dashboard branch: 534 and 312 tests passed).'))
_move_link('E-04', 'lavkooapp', 18, '4e3a895')
UPDATES['E-04'] = UPDATES['E-04'].replace('(draft, 8de2259)', '(draft, 4e3a895)')

# ---- PR 109 run 337 on bb55ab83 (the re-run of the cancelled first attempt), read per job on 2026-11-18 at 19:48 UTC ----
_RUN_337 = (" CI: run 337 on bb55ab83 (PR 109's own run, the re-run of its cancelled first attempt), finished at 20:06 UTC and read job by job on 2026-11-18: 22 of 23 jobs success, the deployment overlay red by design: 'Tests, SQLite' on PHP 8.2 and 8.3 (the whole suite, which includes tests/Feature/Storefront, where the six ledger classes are), 'Storefront tests, MySQL 8' and 'Storefront tests, MariaDB 11.4.2', the Address, Fulfillment and security and sales-channel groups and the strict-mode MariaDB job "
            "(which runs StrictModeTest, SalesChannels, OperationalCosts and Fulfillment, not tests/Feature/Storefront, so these tests have run under strict mode only locally). The finance review of the ledger columns still gates the merge.")
for _idx in (1, 2, 5):
    _state, _text = CRITERIA['E-05'][_idx]
    assert _state == 'CODED' and _text.endswith('CI not read')
    CRITERIA['E-05'][_idx] = ('MET' if _idx == 2 else 'CODED', _NOT_READ.sub('', _text).rstrip(' .;') + '.' + _RUN_337 + ('' if _idx == 2 else ' Kept CODED: ' + ("point-of-sale debits and points conversions still name nothing, so not every ledger row names what stands behind it." if _idx == 1 else "a loyalty row carries no currency by design and a row of the default storefront keeps the market columns empty while the switches are off, so not every row carries all three.")))
UPDATES['E-05'] = UPDATES['E-05'] + " CI: run 337 is read per job (22 of 23 jobs success, the overlay red by design); criterion 2 (the actor of a manual top-up) is met, criteria 1 and 5 stay coded because their sentences say every row and two kinds of row do not carry everything."
NEXT_CURRENT['E-05'] = "The finance review of the ledger columns (the R pack for the wallet); E-05.3 (a replay-safe key on source_type and source_id) and E-05.4 (the add-fund bonus in the ledger balance) as draft PRs awaiting finance review; point-of-sale debits and points conversions naming their order and the market columns of a default-storefront row, which would close criteria 1 and 5."

# ---- the seller integration branch claude/seller-all-integration-ci, run 29 on f695f8c, read per step on 2026-11-18 at 21:05 UTC ----
_SELLER_STEP_MARKET = "the named step 'Test the market scope, the market choice, the sign-in header and the version helper (K-01, K-02, K-10, K-12, K-14)' (32 tests)"
_SELLER_STEP_SCAN = "the named step 'Test the point-of-sale scan of country-scoped codes (K-05, K-13)' (109 tests: the whole of test/pos_scan)"
_SELLER_STEP_EDIT = "the named step 'Test the order edit refusal and the approved review average (K-07)' (17 tests)"
_SELLER_RUN_29 = (" CI: run 29 on f695f8c, read per step on 2026-11-18: the integration branch claude/seller-all-integration-ci, which merges the heads of #1 (964557e), #2 (e5fd716), #5 (83893fa), #3 (7e9f674), #4 (716f84d), #6 (e4d7d02), #7 (3eba869), #8 (7c20717), #9 (673aa9c) and #10 (99927e8) and runs every feature's tests as a named step before the full run. "
                  "Install, the formatting check, analyze and the translation check success; the named steps (market scope 32 tests, POS scan 109, order edit 17, platform admin shell with its flag on 724 and 1 skipped, no theme call 3, push route and rating 26) and, after the full run, 'Test the POS rules' (70 tests) success; the full 'Test' step ended 1110 passed, 1 failed, 1 skipped, "
                  "the one failure being the known app_version_gate_test (the shipped version against the published minimum), which no change touches; the Android build was skipped because that job is red for it. The head is a merge of the pull requests' heads: the stacked ones have no run of their own, and nothing was run on a device.")
_SELLER_STALE = ('; job analyze-and-test red, step UNVERIFIED', '; no CI run, PR-body run only', ', no run on record', '; no CI run proves them', '; CI step result UNVERIFIED, #5 has no CI', '(local run); ')

def _seller_clean(text):
    for stale in _SELLER_STALE:
        text = text.replace(stale, '')
    return text.replace('app#5 90b9d33', 'app#5 83893fa').rstrip(' .;')

_SELLER_BASE = {
    "K-01.0": "test/dio_storefront_header_test.dart on app#1 964557e; job analyze-and-test red, step UNVERIFIED",
    "K-01.2": "test/market_scope_test.dart; no aggregate helper in lib/features/storefront",
    "K-01.3": "bool.fromEnvironment(..., defaultValue: false) in storefront_config.dart; test 'the storefront flag is off by default'",
    "K-02.0": "seller_market_choice_test 'nothing chosen yet works in Saudi Arabia'; app#2 e5fd716, no run on record",
    "K-02.1": "test 'choosing a market saves it, and it survives a new instance'",
    "K-02.2": "tests 'All Markets is refused and writes nothing', 'an unknown stored code falls back to Saudi Arabia'",
    "K-02.3": "group 'while storefronts are off' in seller_market_choice_test",
    "K-05.0": "test/pos_scan/cart_repository_scan_test.dart on app#5 90b9d33; no CI run, PR-body run only",
    "K-05.1": "cart_repository_scan_test and pos_scan_source_rules_test (country query pinned)",
    "K-05.2": "ambiguous_code_sheet_test, barcode_scan_controller_test, product_scan_refusal_test",
    "K-07.0": "order_edit_submit_flow_test on app#3 7e9f674; job analyze-and-test red, step UNVERIFIED",
    "K-07.1": "order_edit_submit_flow_test 'a saved edit reloads the order and shows no error'",
    "K-07.2": "order_edit_submit_contract_test",
    "K-07.3": "product_review_average_test",
    "K-10.0": "test/market_scope_test.dart on app#1",
    "K-10.4": "MarketScope.allMarkets has no currency and no aggregate helper exists",
    "K-12.0": "test/dio_auth_header_test.dart on main",
    "K-13.0": "test/pos_scan/pos_scan_translations_test.dart; tool/check_translations.dart; app#5 90b9d33",
    "K-13.3": "app#5 body: OK 1228 used, 1849 defined (local run); CI step result UNVERIFIED, #5 has no CI",
    "K-16.0": "dio_storefront_header_test (#1) and cart_repository_scan_test (#5); no CI run proves them",
}

def _seller_met(requirement, index, step, what_is_run):
    base = _seller_clean(_SELLER_BASE.get('%s.%d' % (requirement, index), ''))
    CRITERIA.setdefault(requirement, {})[index] = ('MET', (base + '. ' if base else '') + 'Run by ' + step + ': ' + what_is_run + '.' + _SELLER_RUN_29)

_seller_met('K-01', 0, _SELLER_STEP_MARKET, "test/dio_storefront_header_test.dart, 'flag off sends no storefront header, even with a stored market' and 'flag off keeps the auth and language headers exactly as before'")
_seller_met('K-01', 2, _SELLER_STEP_MARKET, "test/market_scope_test.dart, 'All Markets has no country code and no currency, so amounts are never summed into one'")
_seller_met('K-01', 3, _SELLER_STEP_MARKET, "test/dio_storefront_header_test.dart, 'the storefront flag is off by default'")
_seller_met('K-02', 0, _SELLER_STEP_MARKET, "test/seller_market_choice_test.dart, 'nothing chosen yet works in Saudi Arabia'")
_seller_met('K-02', 1, _SELLER_STEP_MARKET, "test/seller_market_choice_test.dart, 'choosing a market saves it, and it survives a new instance'")
_seller_met('K-02', 2, _SELLER_STEP_MARKET, "test/seller_market_choice_test.dart, 'All Markets is refused and writes nothing', 'an unknown stored code falls back to Saudi Arabia' and 'a stored value that is not text falls back to Saudi Arabia'")
_seller_met('K-02', 3, _SELLER_STEP_MARKET, "test/seller_market_choice_test.dart, group 'while storefronts are off' ('the scope is All Markets whatever is stored', 'choosing a market writes nothing')")
_seller_met('K-05', 0, _SELLER_STEP_SCAN, "test/pos_scan/cart_repository_scan_test.dart, the request while storefronts are off carries the code alone")
_seller_met('K-05', 1, _SELLER_STEP_SCAN, "test/pos_scan/cart_repository_scan_test.dart (the country the market names, the country a seller chose in the sheet on that one rescan) and test/pos_scan/pos_scan_source_rules_test.dart")
_seller_met('K-05', 2, _SELLER_STEP_SCAN, "test/pos_scan/barcode_scan_controller_test.dart (409: several products, 422: the server does not know the country, every other failure), ambiguous_code_sheet_test.dart and product_scan_refusal_test.dart")
_seller_met('K-05', 4, _SELLER_STEP_SCAN, "lavkooappseller #5 (draft, 83893fa, client only): the server answers a code that no product of the market holds with 200 and an empty list, which the scan handed to the product reader, which refused it, and nobody caught that (nothing was shown and the scan was left loading). The scan now reads an answer that is not a product object as found nothing, shows the existing product_not_found text and ends; test/pos_scan/barcode_scan_controller_test.dart, group 'a scan that finds nothing' (4 tests: the message in English, in Arabic, under a market with the country asked for, and none when a product is found). Temporary mutations (the message skipped, another text) are killed by those tests")
_seller_met('K-07', 0, _SELLER_STEP_EDIT, "test/order_edit_submit_flow_test.dart, a refused edit keeps the screen open, stops the spinner, does not reload the order and shows the server's message or the generic one")
_seller_met('K-07', 1, _SELLER_STEP_EDIT, "test/order_edit_submit_flow_test.dart, a saved edit and an old server's 200 reload the order and close the screen")
_seller_met('K-07', 2, _SELLER_STEP_EDIT, "test/order_edit_submit_contract_test.dart, an empty, HTML or non-object 200 body never throws")
_seller_met('K-07', 3, _SELLER_STEP_EDIT, "test/product_review_average_test.dart, the sheet averages approved reviews only and survives a missing or empty reviews array")
_seller_met('K-10', 0, _SELLER_STEP_MARKET, "test/market_scope_test.dart, 'lists the six GCC markets with their own currencies'")
_seller_met('K-10', 4, _SELLER_STEP_MARKET, "test/market_scope_test.dart, 'All Markets has no country code and no currency, so amounts are never summed into one'; no aggregate helper exists in lib/features/storefront, and no screen shows All Markets figures yet")
_seller_met('K-12', 0, _SELLER_STEP_MARKET, "test/dio_auth_header_test.dart, 'a blank token never overwrites a working session' and 'clearAuthToken drops the credential so a signed-out client cannot reuse it'")
_seller_met('K-13', 0, _SELLER_STEP_SCAN, "test/pos_scan/pos_scan_translations_test.dart, and the step 'Check translation keys' (dart run tool/check_translations.dart) success")
_seller_met('K-16', 0, _SELLER_STEP_MARKET + ' and ' + _SELLER_STEP_SCAN, "test/dio_storefront_header_test.dart (flag off: the header map is main's) and test/pos_scan/cart_repository_scan_test.dart (flag off: the scan request is ?code= and nothing else)")

CRITERIA.setdefault('K-13', {})[3] = ('CODED', _seller_clean(_SELLER_BASE['K-13.3']) + ". The step 'Check translation keys' passed on f695f8c, the integration head that holds every pull request's keys (run 29), but no stacked pull request has a run of its own, so the check is not shown on the head of each one." )
CRITERIA.setdefault('K-15', {})[4] = ('CODED', "claude/seller-all-integration-ci (f695f8c): the CI file of the integration branch runs the tests of each feature as a named step before the full run (market scope and market choice, the POS scan, the order edit, the platform admin shell, no theme call, the push route and the rating, and the POS rules after it), each with if: always() so that the known red test cannot hide it; run 29 read per step. The pull requests themselves carry the step only for the platform admin shell (#4), the theme call (#7), the POS rules (#8) and the push route (#9): until the owner adopts the CI file of the integration branch, or each pull request adds its own, a merged main would not name the market, POS scan and order edit tests." + _SELLER_RUN_29)
_move_link('K-05', 'lavkooappseller', 8, '7c20717')
EXTRA_LINKS['K-05'] = EXTRA_LINKS['K-05'] + [{'repo': 'lavkooappseller', 'pr': 5, 'sha': '83893fa'}]
EXTRA_LINKS['K-12'] = EXTRA_LINKS.get('K-12', []) + [{'repo': 'lavkooappseller', 'pr': 11, 'sha': '6491b82'}]
for _requirement in ('K-01', 'K-02', 'K-10', 'K-12', 'K-13', 'K-16'):
    UPDATES[_requirement] = UPDATES.get(_requirement, '') + " CI: the tests of the market scope, the market choice, the sign-in header and the version helper, of the POS scan and of the order edit are run as named steps on the seller integration branch (run 29 on f695f8c, which merges the heads of #1 to #10): 32, 109 and 17 tests passed; the full run there is red only for the known version gate test."
UPDATES['K-05'] = UPDATES['K-05'] + " lavkooappseller #5 (draft, 83893fa): a scan that finds no product tells the seller (K-05.4); the empty answer used to reach the product reader, which refused it, and left the scan loading with nothing shown."
UPDATES['K-15'] = UPDATES['K-15'] + " claude/seller-all-integration-ci (f695f8c) merges the heads of #1 to #10 and runs each feature's tests as a named step before the full run (run 29: every named step success, the full run red for the known version gate test only): K-15.4 is coded on that branch, not on the pull requests."
UPDATES['K-05'] = UPDATES['K-05'].replace('a scan that finds nothing in this market telling the seller (K-05.4), ', '')
NEXT_CURRENT['K-15'] = NEXT_CURRENT['K-15'].replace("K-15.4 (a named step for each feature); merge #10's line into the older stacked pull requests so that they have a run.", "K-15.4 stays coded on the integration branch until each pull request carries its named step or the owner adopts the CI file of claude/seller-all-integration-ci; merge #10's line into the older stacked pull requests so that they have a run.")

# ---- lavkooappseller #11 (the 401 latch), run 31 on 6491b82, read per step on 2026-11-18 at 21:15 UTC ----
CRITERIA.setdefault('K-12', {})[1] = ('MET', "lavkooappseller #11 (draft, test and one CI step, no application code, 6491b82): test/api_checker_unauthorized_test.dart, 3 tests on the real ApiChecker with the real AuthController on a screen of the app (the wipe of the stored session is counted instead of run): twenty rejected requests in a row give one wipe of the session, one trip to the sign-in screen and the latch set; after the seller has signed in again the next rejected requests sign the seller out once more; a connection timeout never wipes the session, sets the latch or navigates. "
                                           "Temporary mutations (no latch, the latch guarding only the trip to the sign-in screen as before the fix, a timeout treated as a 401) are each killed by a named test. CI: run 31 on 6491b82 (#11's own run, event pull_request, base main), read per step on 2026-11-18: install, the formatting check, analyze and the translation check success; the named step 'Test that a burst of 401s signs the seller out once (K-12)' success, 3 tests passed; the full 'Test' step ended 144 passed, 1 failed, the one failure being the known app_version_gate_test (the shipped version against the published minimum), which this change does not touch; the Android build was skipped because that job is red for it. The sign-in screen is not built in the test (the trip to it is counted), and nothing was run on a device.")
UPDATES['K-12'] = UPDATES.get('K-12', '') + " lavkooappseller #11 (draft, 6491b82): a burst of 401s signs the seller out once is now pinned by a test that runs the real checker and the real controller (run 31 read per step)."

# ---- runs 355 (PR 111, 7e19d284) and 356 (PR 96, e624e43e), read per job on 2026-11-18 ----
_RUN_355 = (" CI: run 355 on 7e19d284 (PR 111's own run), read per job on 2026-11-18: 'Tests, SQLite' on PHP 8.2 and on PHP 8.3 (the whole suite: 7,230 tests, 99 skipped) and 'Storefront tests' on MySQL 8 and on MariaDB 11.4.2 "
            "(941 tests, 4,660 assertions, none skipped): success; 22 of the 23 jobs are success and the deployment overlay is red by design. The strict-mode MariaDB job is success but does not run tests/Feature/Storefront, so these tests have run under a strict server only locally, where the checkout-driven cases skip until PR 89.")
_RUN_356 = (" CI: run 356 on e624e43e (PR 96's own run), read per job on 2026-11-18: 'Tests, SQLite' on PHP 8.2 and on PHP 8.3 (the whole suite: 7,141 tests, 99 skipped) and 'Storefront tests' on MySQL 8 and on MariaDB 11.4.2 "
            "(852 tests, 3,371 assertions, none skipped): success; 22 of the 23 jobs are success and the deployment overlay is red by design. The strict-mode MariaDB job is success but does not run tests/Feature/Storefront, so the checkout-driven cases have not run under a strict server (they skip until PR 89).")
_CONTINUITY = ("StorefrontStopContinuityTest (65 tests): an order that the real checkout of the API placed before a stop, in every stop mode (browse only, maintenance and redirect on a non-default storefront, browse only on the default one) and "
               "without a stop on each of those two storefronts (the controls); a new sale is refused in the stopped cases and taken in the controls")
CRITERIA['I2-16'][2] = ('MET', "PR 111 (draft, stacked on PR 105, 7e19d284): the order is still listed, shown in full, tracked with its history, cancelled, asked a refund for (the request, not a decision on it), written to in support (a ticket reply and a chat message) and paid for by cash or by transfer in every stop mode, "
                               "and the same answers come without a stop (" + _CONTINUITY + "). 'Deactivated' is read as paused in each of the three modes: a storefront taken out of the configuration is not driven here (a payment started in one is, M2-14.1)." + _RUN_355)
CRITERIA['I2-16'][3] = ('MET', "PR 111 (7e19d284): the MyFatoorah webhook (signed, and repeated), the return of the customer and the Torod webhook that stores a courier status are answered as before in every stop mode (they settle a payment started before the stop and a shipment of an earlier order), "
                               "and no gateway callback, webhook or payment route of the route table carries the sale gate or the maintenance gate, whichever way the gate is spelled (a structural test over the whole table, itself proved by mutation: " + _CONTINUITY + "). "
                               "The other gateways and couriers (a whole address family each) and the marketplace webhooks are structural only, not driven." + _RUN_355)
CRITERIA['I2-16'][4] = ('CODED', "PR 111 (7e19d284): " + _CONTINUITY + ". Driven: the refund request, the cancellation, the list, details and tracking, the support ticket and chat, the due payment by cash and by transfer, the MyFatoorah payment (hosted page, signed webhook, repeat, return) and the Torod webhook with its worker step, "
                               "and the booking of the earnings of a delivered order by both writers (below the route). Structural only: the staff side of a refund, the routes that mark an order delivered, the due payment by wallet and by digital payment, the other gateways and couriers, and the withdrawal, payout and settlement routes carry neither stop gate. "
                               "Not driven, so the criterion stays CODED: a refund decision, a refund payout, the submission of a shipment to a courier and a settlement run while a storefront is stopped." + _RUN_355)
CRITERIA['M2-14'][4] = ('CODED', "PR 111 (7e19d284): the journey exists in the pull request's own CI: the real checkout of the API places the order, the lifecycle stops the storefront (the default one browse-only, any other in each of the three modes), and the order is listed, tracked, cancelled, asked a refund for, written to in support, "
                                 "its payment settled by the gateway's webhook and its courier status taken, while no new sale is taken and the controls keep selling (StorefrontStopContinuityTest, 65 tests). Not in it: a refund decision or payout, a courier submission and a settlement run; the criterion says 'refunded', so it stays CODED." + _RUN_355)
EXTRA_LINKS['I2-16'] = [{'repo': 'lavkoo', 'pr': 111, 'sha': '7e19d284'}]
EXTRA_LINKS['M2-14'] = EXTRA_LINKS.get('M2-14', []) + [{'repo': 'lavkoo', 'pr': 111, 'sha': '7e19d284'}]
UPDATES['I2-16'] = ("PR 111 (7e19d284): every stop mode refuses a new sale (a test for each mode, for the refusal and for the list), and an order placed before a stop is still listed, tracked, cancelled, asked a refund for, supported and paid for, its gateway and courier notices are answered and its earnings are booked, in every mode (StorefrontStopContinuityTest, 65 tests, run 355); "
                    "no gateway, webhook or payment route carries a stop gate. Not driven: a refund decision, a refund payout, a courier submission and a settlement run while a storefront is stopped.")
NEXT_CURRENT['I2-16'] = "A refund decision, a refund payout, a shipment submission and a settlement run driven while a storefront is stopped, in each mode (criterion 4 goes to MET with them)."
CRITERIA['M2-04'][0] = ('MET', "PR 96 (draft, stacked on #87, e624e43e): StorefrontCustomerSignInTest (61 tests) signs one customer in through the real customer login of the API (a token) and of the website (the session) from each of the six storefronts and from none, with the same credentials: every answer is a token for the same user id "
                               "(the claim of the token and the stored token agree) or the session of the same account, the one users row is unchanged and no row is created; a wrong password is refused alike everywhere (the API counts the failed attempts on the one row, the website refuses alike but counts none); an identity nobody registered creates no user; "
                               "a registration through a storefront, and through none, writes the same row but for what a registration makes unique, with no storefront in the referral code, the temporary token or any other column; and no migration of the application or of its modules may put a storefront on the users table "
                               "(a scan that is itself proved against each spelling it must catch and two it must leave alone). CustomerAcrossStorefrontsTest keeps the customer info and the order list across the six storefronts. Not covered: the sign-up of the website (only the registration of the API is exercised), a device." + _RUN_356)
UPDATES['M2-04'] = "PR 96 (e624e43e): one customer signs in through the API and through the website from each of the six storefronts and from none with the same credentials and is the same users row, nothing is created for a storefront, and the customer's own account and order list answer the same from each storefront (StorefrontCustomerSignInTest 61 tests, CustomerAcrossStorefrontsTest; run 356). Not proven: the sign-up of the website, marketing and legal consents per storefront, one canonical phone and e-mail key."
NEXT_CURRENT['M2-04'] = "M2-04.4 (phone and e-mail identity match across the six markets with one canonical key, no Saudi default) and M2-04.5 (marketing and legal consents per storefront and legal entity)."
CRITERIA['I1-12'][3] = ('MET', "PR 96 (e624e43e) with PR 87 (ea8ed21c): a coupon created for the KW store is stored as typed (StorefrontCouponAdminTest: KW min_purchase 10.505 and discount 1.505 unconverted, the Saudi and legacy rows divided by 3.75, storefront_country stamped) and redeemed as stored: StorefrontCouponRedemptionTest (30 tests) applies a coupon typed in dinars "
                               "(discount 1.505, minimum 10.505, storefront KW) to a cart of 30.000 dinars through the real apply route and the real checkout, on a platform that sells in one currency and on one that converts from a base currency with the riyal at 3.75: the answer is the amount as stored, the minimum is compared by the repository's own rule one step above, at and one step below the cart, "
                               "the Kuwaiti code is unknown to the Saudi storefront and the Saudi code to the Kuwaiti one, and a Saudi coupon keeps its answer; one case follows a coupon from the admin form to its redemption. The checkout-driven cases are skipped under a strict MySQL server until PR 89." + _RUN_356)
EXTRA_LINKS['M2-04'] = [{'repo': 'lavkoo', 'pr': 96, 'sha': 'e624e43e'}]
EXTRA_LINKS['I1-12'] = EXTRA_LINKS.get('I1-12', []) + [{'repo': 'lavkoo', 'pr': 96, 'sha': 'e624e43e'}]
NEXT_CURRENT['I1-12'] = "I1-12.0 and I1-12.1 (the single-currency and the multi-currency round trip of a coupon) and I1-12.2 (the round-trip matrix, three-decimal currencies included) are pinned by the coupon pins of wave 3 (gcc/p1-coupon-behaviour-pins) once they are pushed and run."

# ---- run 359 (PR 112, eaa04e2f), read per job on 2026-11-19: the theme of one storefront, its schedule, preview, caches, app contract, library, import and tokens ----
_RUN_359 = (" CI: run 359 on eaa04e2f (PR 112's own run), read per job on 2026-11-19: 'Tests, SQLite' on PHP 8.2 and on PHP 8.3 (the whole suite: 7,464 tests, 100 skipped) and 'Storefront tests' on MySQL 8 and on MariaDB 11.4.2 "
            "(1,175 tests, 5,169 assertions, none skipped): success. The deployment overlay is red by design; the strict-mode MariaDB job does not run tests/Feature/Storefront, so these tests have run under a strict server only locally (MySQL 8.0.46, normal and strict, 527 tests of the 19 theme classes).")
_P112 = "PR 112 (draft, stacked on PR 107, eaa04e2f, off by default): "
for _requirement in ('F-02', 'F-04', 'F-07', 'F-08', 'F-09', 'F-10', 'F-11', 'F-12', 'M2-10'):
    CRITERIA.setdefault(_requirement, {})
CRITERIA['F-02'][3] = ('MET', _P112 + "the audit lines theme.published, theme.activated and theme.restored name the storefront (ThemeManager, StorefrontThemePublishTest), and so do the two lines only the schedule writes, theme.scheduled_publish_failed and theme.scheduled_publish_cancelled (theme:publish-due), "
                              "and the two the admin's schedule and unset actions now write, theme.scheduled_publish_set and theme.scheduled_publish_unset (as the admin who did it); the storefront is the one of the theme of the version, whichever theme was created first, and a lookup that fails never fails the action (StorefrontThemeScheduledPublishTest, 51 tests: the command through Artisan for a due, a blocked, a failed, "
                              "a not yet due and a dry-run publish, for two storefronts at once, with the switch on, off and before the migration; the admin's schedule and unset through their route; an admin without theme_publish and the demo mode refused with nothing written). The two admin lines are written on every installation (with the theme alone while themes are not kept by storefront): gating them on the switch is one condition, an owner decision." + _RUN_359)
CRITERIA['F-04'][2] = ('MET', _P112 + "the keys the app delivery and the page renderer write under are those of the storefront that asked, written out from the documented scheme and not computed by the code under test, for the default storefront, one with a theme of its own and one served the default's, and with storefronts off (StorefrontThemeDeliveryCacheTest, 18 tests)." + _RUN_359)
CRITERIA['F-04'][3] = ('MET', _P112 + "a page cached for one language is never served for another and each language is served from its own entry (StorefrontThemeDeliveryCacheTest)." + _RUN_359)
CRITERIA['F-04'][4] = ('MET', _P112 + "a page cached for one storefront is never served to another for the same page and client, in either order, with the two themes as they are and with the same stamp on both, which leaves the scope of the storefront as the only thing that tells the entries apart (StorefrontThemeDeliveryCacheTest)." + _RUN_359)
CRITERIA['F-07'][0] = ('MET', _P112 + "a tampered id, a moved expiry, an expired token (a token minted two hours ago, and one a minute either side of its expiry), another application key's token and an unsigned or malformed one are ignored and the published page is drawn with no trace of the draft, on /api/v1/theme/home, /api/v1/theme/sections and the website; "
                              "a list sent as preview answered 500 on the app's home route and is now no token (StorefrontThemePreviewTokenTest, 49 tests; StorefrontThemePreviewWebTest, 44)." + _RUN_359)
CRITERIA['F-07'][1] = ('MET', _P112 + "a draft delivered by a live token carries Cache-Control no-store and private, no ETag, is never answered with a 304 and is never written to a cache, under its own key or under the shopper's, for the page body and for the page head's colours (StorefrontThemePreviewTokenTest, StorefrontThemePreviewWebTest, StorefrontThemePreviewStorefrontTest, 75)." + _RUN_359)
CRITERIA['F-07'][2] = ('MET', _P112 + "every response of a route the website serves that carries a token is noindex and no-store, the 404 of a matched route included, and the app's /theme/home and /theme/sections now mark a draft they serve the same way from one place (ThemeSectionController::markAsDraft: the app marked none of it before); a URL that matches no route never enters the web group and is not marked (StorefrontThemePreviewWebTest, StorefrontThemePreviewTokenTest)." + _RUN_359)
CRITERIA['F-07'][3] = ('MET', _P112 + "the session preview works only for an admin allowed to view themes: with the permission the draft is drawn, without it, without a session and in the demo it is not, and the draft is never written to the cache (StorefrontThemePreviewWebTest, StorefrontThemePreviewStorefrontTest)." + _RUN_359)
CRITERIA['F-07'][4] = ('MET', _P112 + "a token or a session preview of one storefront's draft is ignored on every other storefront, and on the app's calls for it, exactly as a token that is not live is (StorefrontThemeResolver::mayPreview, asked by ThemePreviewToken::version and by the session branch of StorefrontThemeRenderer): the published page of the request's storefront is drawn "
                              "(StorefrontThemePreviewStorefrontTest, 75 tests, with a deleted theme, a closed storefront and a storefront with no theme of its own). The builder's own links name no storefront, so a draft of another storefront shows the default storefront's published page there until their URLs carry it (owner decision 6 of docs/gcc/F-01-STOREFRONT-THEME.md)." + _RUN_359)
CRITERIA['F-08'][0] = ('MET', _P112 + "a due draft goes live through theme:publish-due, for the default storefront and the others, with the audit line naming the storefront, and a due publish of one storefront leaves the theme and the schedule of the other as they were (StorefrontThemeScheduledPublishTest, 51 tests)." + _RUN_359)
CRITERIA['F-08'][1] = ('MET', _P112 + "a due publish that is blocked is cancelled with its reasons and one that fails is recorded with its error (kept up to three hundred characters), neither retried every five minutes, and a blocked or failed publish of one storefront does not stop another's (StorefrontThemeScheduledPublishTest); a failed publish ends the run, so another storefront's due publish waits for the next run (pinned as it is)." + _RUN_359)
CRITERIA['F-08'][2] = ('MET', _P112 + "restoring an archived or the live version creates a new draft holding a copy of its sections, blocks and settings, with no schedule and the audience kept, and publishes nothing; the live and archived versions, the revision counter and the active theme of every one of the six storefronts are untouched (StorefrontThemeVersionLifecycleTest, 10 tests)." + _RUN_359)
CRITERIA['F-08'][3] = ('MET', _P112 + "a publish that fails inside its transaction (after the live version was archived, and after the draft was saved and stamped) leaves every row as it was, records nothing, keeps serving the live version, and the draft publishes once the fault is gone (StorefrontThemeVersionLifecycleTest)." + _RUN_359)
CRITERIA['F-09'][0] = ('MET', _P112 + "a client that declares nothing is sent every app-safe section (one of every section type the registry offers, the six it never sends named with their reasons), one that names its section types is not sent the others and is told why, and a type nobody decided about is withheld whatever a client declares (StorefrontThemeAppContractTest, 14 tests)." + _RUN_359)
CRITERIA['F-09'][1] = ('MET', _P112 + "the delivery cache key holds the platform, the device, the audience, the segments, the language, the engine, the schema, the components and, since this pull request, the channel (two clients that differed only in X-UI-Channel were served one cached page, in either order); the existing test restates the key from the documented scheme and a new one asks in both orders "
                              "(StorefrontThemeDeliveryCacheTest, StorefrontThemeDeliveryChannelTest, 2)." + _RUN_359)
CRITERIA['F-09'][2] = ('MET', _P112 + "the compatibility report names each section type the app cannot draw with how many there are and why, the publish check warns about each and does not stop the publish, the theme list shows both before the publish button, the builder is handed both, and a publish goes ahead with the warnings flashed (StorefrontThemeAppCompatibilityTest, 8 tests)." + _RUN_359)
CRITERIA['F-09'][5] = ('MET', _P112 + "the exact keys of /api/v1/theme/home and /api/v1/theme/version are pinned at the levels the customer app parses (the page, the design tokens, a section, a block, the compatibility block), with a theme published and with none, with storefronts off and on, and the developer portal names X-Storefront for both endpoints (StorefrontThemeAppContractTest)." + _RUN_359)
CRITERIA['F-10'][0] = ('CODED', _P112 + "a product_slider rail draws the products of the storefront that asks only, in the web page and in the endpoint the app payload names, for five sources, for a storefront served the default storefront's theme and for one that sells nothing yet (StorefrontThemeProductRailTest, 17 tests). "
                              "Not covered: the rail whose source is a merchant's own collection (it needs the merchandising tables) and the other section types that draw products, so the criterion stays CODED." + _RUN_359)
CRITERIA['F-10'][1] = ('MET', _P112 + "the section registry and the component capability registry answer the same for the six storefronts, and no type, block type, capability or section file carries a country suffix or a country's name as a word (StorefrontThemeSectionLibraryTest, 5 tests, the check itself proved against ae_hero_banner, hero_banner_kuwait and romance_banner)." + _RUN_359)
CRITERIA['F-11'][0] = ('MET', _P112 + "an import creates a new inactive theme with one draft, never overwrites a theme of the same name, never activates, and leaves the draft with no schedule and no publish by itself (StorefrontThemeImportTest, 7 tests)." + _RUN_359)
CRITERIA['F-11'][1] = ('MET', _P112 + "an import skips unknown section types with a warning and returns the settings in the registry's normal form (StorefrontThemeImportTest)." + _RUN_359)
CRITERIA['F-12'][0] = ('MET', _P112 + "a version's settings merge over the code defaults key by key, a key the defaults do not know comes through (in a group they hold, and a whole group), and the logo width and height a merchant sets reach the app as numbers (they arrived as null before: a defect of main) (StorefrontThemeDesignTokensTest, 16 tests)." + _RUN_359)
CRITERIA['F-12'][1] = ('CODED', _P112 + "the page head's CSS variables and the app payload's tokens carry the same values for a token the version overrides and one it does not (four tokens, nine variables and two RGB values; StorefrontThemeDesignTokensTest). That the layout renders the partial is a structural pin (a string match on the layout source) and the draft-preview branch of publishedGlobalSettings is not covered, so the criterion stays CODED." + _RUN_359)
CRITERIA['M2-10'][2] = ('MET', _P112 + "a storefront publishes its own theme draft without touching another's: another storefront's published version, revision and checksum are unchanged by a publish, revisions are counted apart, a publish and an activation forget only their own storefront's cached pages, "
                               "and a schedule of one storefront fires and fails without touching the other's (StorefrontThemePublishTest, StorefrontThemeScheduledPublishTest, StorefrontThemeVersionLifecycleTest)." + _RUN_359)
CRITERIA['M2-10'][3] = ('MET', _P112 + "backend tests cover the publish (revision, checksum, archive of the previous version only, a failed publish), the restore, the schedule, the fallback of a storefront with no theme of its own to the default storefront's, and old app versions (a client that declares nothing or fewer section types, the compatibility report and the publish warnings): "
                               "StorefrontThemePublishTest, StorefrontThemeVersionLifecycleTest, StorefrontThemeScheduledPublishTest, StorefrontThemeAppContractTest, StorefrontThemeAppCompatibilityTest, StorefrontThemeReadersTest." + _RUN_359)
EXTRA_LINKS['F-04'] = EXTRA_LINKS.get('F-04', []) + [{'repo': 'lavkoo', 'pr': 112, 'sha': 'eaa04e2f'}]
for _requirement in ('F-02', 'F-07', 'F-08', 'F-09', 'F-10', 'F-11', 'F-12', 'M2-10'):
    _links = [link for link in EXTRA_LINKS.get(_requirement, []) if link.get('pr') != 112]
    EXTRA_LINKS[_requirement] = _links + [{'repo': 'lavkoo', 'pr': 112, 'sha': 'eaa04e2f'}]
UPDATES['F-07'] = _P112 + "a preview token of one storefront's draft is ignored on every other storefront and on the app's calls for it; a tampered, expired or foreign token is ignored and the published page is drawn; a draft is never cached or ETagged and never served to anyone who may not view themes, and a request to the website's custom-page route that carries a token is marked noindex and no-store; the app's routes answered 500 to a list as a token and marked no draft, both fixed. Run 359. Not proven: that every response that carries a token is marked noindex and no-store (the audit keeps F-07.2 coded), and the builder's own links name no storefront."
UPDATES['F-08'] = _P112 + "the schedule of one storefront fires, cancels and fails on its own (and its audit lines name it), a restore creates a draft and publishes nothing, and a failed publish leaves every row as it was. Run 359. Not done: a schedule on the storefront's own clock (F-08.4)."
UPDATES['F-09'] = _P112 + "the delivery caches and the app contract are pinned through the app routes, the delivery cache key holds the channel (a defect of main: a section kept for the seller app could reach the customer app), and the developer portal names X-Storefront. Run 359. Not done: an app upgrade replacing a payload cached for the previous build (F-09.4)."
UPDATES['F-11'] = _P112 + "the import of a theme creates an inactive theme with one draft, never overwrites or activates, and skips unknown section types with a warning. Run 359. Not done: the copy of a theme to another storefront with fresh section ids, and what happens to its assets and banner rows (F-11.2, F-11.3)."
UPDATES['F-12'] = _P112 + "a version's settings merge over the code defaults key by key and the logo sizes a merchant sets now reach the app (a defect of main). Run 359. Not done: a shared identity with a storefront override (F-12.2, F-12.3)."
NEXT_CURRENT['F-07'] = "The builder's own links (the frame, the preview button and the phone link) carry the storefront, and a draft of a storefront that is not open can be previewed (owner decision 6 of docs/gcc/F-01-STOREFRONT-THEME.md)."
NEXT_CURRENT['F-08'] = "F-08.4: a schedule on each storefront's own clock, with the time zone of the storefront as an owner decision."
NEXT_CURRENT['F-09'] = "F-09.4: the engine and schema in the key of the app's cached page, so an app upgrade replaces a payload cached for the previous build (customer app, with a test)."
NEXT_CURRENT['F-11'] = "F-11.2 and F-11.3: copy a theme to another storefront as a draft with fresh section ids, and decide what happens to assets and banner rows."
NEXT_CURRENT['F-12'] = "F-12.2 and F-12.3: a shared design identity that a storefront inherits, and a storefront override that changes only that storefront."

# ---- run 358 (PR 115, d5755860), read per job on 2026-11-19: the admin screens after the review fixes ----
_RUN_358 = (" Run 358 on d5755860 (the same pull request after the review fixes: the hash the page names, the day in UTC, the upper bound, the board, the empty day and the Arabic message), read per job on 2026-11-19: 'Tests, SQLite' on PHP 8.2 and on PHP 8.3 (the whole suite: 7,419 tests, 99 skipped) and "
            "'Storefront tests' on MySQL 8 and on MariaDB 11.4.2 (1,130 tests, 6,168 assertions, the 6 skipped being the side-bar tests, SQLite only by design): success. The deployment overlay is red by design; the strict-mode MariaDB job does not run tests/Feature/Storefront and is not proof for these tests.")
for _rid in ('I2-11', 'I2-03'):
    _state, _text = CRITERIA[_rid][4]
    assert _state == 'MET', (_rid, _state)
    CRITERIA[_rid][4] = (_state, _text.rstrip(' .') + '.' + _RUN_358)
    _move_link(_rid, 'lavkoo', 115, 'd5755860')
CRITERIA.setdefault('M2-13', {})[2] = ('MET', "PR 115 (draft, base PR 111, d5755860, no migration, no switch): an administrator who holds storefront_legal_write creates a draft from a template and edits it, and a different administrator who holds storefront_legal_approve approves it, publishes it from a day (UTC) or now and revokes it with a reason, "
                                              "each step through the real routes of /admin/storefronts/{country}/legal-texts, the author refused as the approver in the page and in the service, the text cleaned of scripts before it is kept, every step audited with the admin guard as the actor, and the same steps by the console command (AdminStorefrontLegalTextScreenTest 46 tests, AdminStorefrontRoleFormTest, AdminStorefrontSafetyTest, "
                                              "on the publisher and the command of PR 114: StorefrontLegalPublisherTest 52, StorefrontLegalTextCommandTest 51)." + _RUN_358)
EXTRA_LINKS['M2-13'] = EXTRA_LINKS.get('M2-13', []) + [{'repo': 'lavkoo', 'pr': 115, 'sha': 'd5755860'}]

# ---- run 357 (PR 114, c440a9f1), read per job on 2026-11-19: the legal text publisher after the review fixes ----
_RUN_357 = (" Run 357 on c440a9f1 (PR 114's own run after the review fixes: the empty --hash, the switched-off admin on publish and revoke, the text revoked before it takes effect, the time zone), read per job on 2026-11-19: 'Tests, SQLite' on PHP 8.2 and on PHP 8.3 (the whole suite: 7,186 tests, 99 skipped) and "
            "'Storefront tests' on MySQL 8 and on MariaDB 11.4.2 (897 tests, 3,309 assertions, none skipped): success; 22 of the 23 jobs are success and the deployment overlay is red by design. The strict-mode MariaDB job does not run tests/Feature/Storefront and is not proof for these tests.")
for _rid, _idx in (('I2-01', 3), ('I2-03', 3)):
    _state, _text = CRITERIA[_rid][_idx]
    assert _state == 'MET', (_rid, _idx, _state)
    CRITERIA[_rid][_idx] = (_state, _text.rstrip(' .') + '.' + _RUN_357)
    _move_link(_rid, 'lavkoo', 114, 'c440a9f1')

# ---- the audit of the criteria flipped on 2026-11-19 (wf_029fd79b-3ce, wf_01709d87-16e): a skeptical verifier for each unit and a judge for every criterion the verifier flagged; data/flips-audit-2026-11-19.json holds each decision with its reason and the replacement text the judge wrote ----
_AUDIT_2026_11_19 = json.load(open(T.MASTER + 'flips-audit-2026-11-19.json', encoding='utf-8'))['judged']
_AUDIT_STATES = {'DOWNGRADE_TO_CODED': 'CODED', 'DOWNGRADE_TO_MISSING': 'MISSING', 'UPGRADE_TO_MET': 'MET', 'SET_CODED': 'CODED'}


def apply_audit_2026_11_19(only):
    for identifier in only:
        entry = _AUDIT_2026_11_19[identifier]
        if entry['decision'] == 'KEEP':
            continue
        requirement, index = identifier.rsplit('.', 1)
        existing = CRITERIA.setdefault(requirement, {}).get(int(index))
        state = _AUDIT_STATES.get(entry['decision']) or (existing[0] if existing else entry['current_state'].split()[0].strip(';,.:'))
        CRITERIA[requirement][int(index)] = (state, entry['replacement'] or (existing[1] if existing else ''))


# ---- the seller market work, lavkooappseller #12 (03821fe), #13 (8b6f679) and #14 (2c3082c); the CI evidence is run 32 of the integration branch claude/seller-all-integration-ci (5084461), read per step on 2026-11-19 ----
EXTRA_LINKS['K-01'] = EXTRA_LINKS.get('K-01', []) + [{'repo': 'lavkooappseller', 'pr': 13, 'sha': '8b6f679'}]
EXTRA_LINKS['K-03'] = EXTRA_LINKS.get('K-03', []) + [{'repo': 'lavkooappseller', 'pr': 13, 'sha': '8b6f679'}]
EXTRA_LINKS['K-10'] = EXTRA_LINKS.get('K-10', []) + [{'repo': 'lavkooappseller', 'pr': 12, 'sha': '03821fe'}]
EXTRA_LINKS['K-06'] = [link for link in EXTRA_LINKS.get('K-06', []) if link.get('pr') != 14] + [{'repo': 'lavkooappseller', 'pr': 14, 'sha': '2c3082c'}]
UPDATES['K-01'] = UPDATES.get('K-01', '') + " lavkooappseller #13 (draft, 8b6f679, stacked on #2, off by default): X-Storefront is built for each request from the stored market, and a missing, unknown or All Markets value falls back to SA (K-01.1 met on the run 32 of the integration branch)."
UPDATES['K-03'] = UPDATES.get('K-03', '') + (" lavkooappseller #13 (draft, 8b6f679, stacked on #2, off by default): the More screen has a Market tile with the six GCC markets and the pick is saved; the market code and currency show on the dashboard and the order list; the next request after a switch carries the new X-Storefront from the same client with no restart, and the nine uploads made with package:http carry it too (K-03.1 and K-03.2 met on the run 32 of the integration branch). "
                                              "Not done: the label on the other money screens (K-03.0 stays coded) and a queued sale keeping the market it was taken in: the audit found a sale queued under SA and sent after a switch to KW going out under KW (K-03.3 missing).")
UPDATES['K-10'] = UPDATES.get('K-10', '') + (" lavkooappseller #12 (draft, 03821fe, stacked on #1, off by default): while a market is stored and the flag is on, no PriceConverter call applies an exchange rate and the amount fields of the payout request, the bulk price change and the POS extra discount take the decimals of the market's currency. "
                                              "Coded, not met: money written outside PriceConverter (K-10.1), a build with the flag on and no stored market (K-10.2), the sending of the POS discount (K-10.3).")
UPDATES['K-06'] = UPDATES.get('K-06', '') + (" lavkooappseller #14 (draft, 2c3082c, stacked on #8): a queued sale carries the seller who took it and is replayed only under that seller, and sign-out deletes nothing silently. K-06.2 stays missing: a sale has no market (it is sent under the market chosen at replay) and a sale rung up before the profile answered, or queued by an older version, can be sent under the next seller who signs in.")
NEXT_CURRENT['K-03'] = "K-03.3 (a queued sale keeps the market it was taken in) and K-03.0 (the market label on the money screens other than the dashboard and the order list); the pull requests have no run of their own, the evidence is the integration branch."
NEXT_CURRENT['K-10'] = "K-10.1 (money written outside PriceConverter), K-10.2 (a build with the flag on and no stored market), K-10.3 (the POS discount applying); the pull request has no run of its own."
NEXT_CURRENT['K-06'] = "K-06.2: the market half and the sale with no seller; K-06.4 stays coded until the POS screen and the resume of a held cart are run, not scanned; lavkoo #35 must be merged for the idempotency key to do anything."

# ---- lavkooapp #19 (0ed2c844): six pins of behaviour the customer app had and no test; app CI run 33 (push) on that head, read per step on 2026-11-19, and the audit of 2026-11-19 (U10): no criterion goes to MET ----
for _requirement in ('J-01', 'J-05', 'J-09', 'J-11', 'J-13', 'I1-16', 'I2-10', 'I2-13'):
    EXTRA_LINKS[_requirement] = EXTRA_LINKS.get(_requirement, []) + [{'repo': 'lavkooapp', 'pr': 19, 'sha': '0ed2c844'}]
UPDATES['J-01'] = UPDATES.get('J-01', '') + " lavkooapp #19 (draft, stacked on #18, 0ed2c844, tests only): test/storefront_not_derived_test.dart (17 tests, app CI run 33 green) pins that the resolved storefront does not move with the device locale and that no storefront code reads a locale, a position or an IP; J-01.3 stays coded: the IP part is a list of service names, not a behaviour."
UPDATES['J-05'] = UPDATES.get('J-05', '') + " lavkooapp #19 (0ed2c844): test/money_formatter_inventory_test.dart (5 source scans, run 33 green) lists every use of toStringAsFixed (50 in 30 files) and fails on a new one; J-05.3 stays coded: the scans pin counts per file, an amount written by interpolation is not seen, and order_amount_calculation.dart rounds a POS total with toStringAsFixed(2) outside the formatter."
UPDATES['J-09'] = UPDATES.get('J-09', '') + " lavkooapp #19 (0ed2c844): test/storefront_payment_guard_test.dart (25 tests, run 33 green) drives the real checkout and wallet controllers on a browse-only storefront: no digital payment, cash, offline or wallet order and no wallet top-up request leaves the app. J-09.1 stays coded: the payment of what is still due on an existing order (OrderDetailsController.duePayment*) has no app-side guard, which is the owner's decision."
UPDATES['J-11'] = UPDATES.get('J-11', '') + " lavkooapp #19 (0ed2c844): test/order_push_routing_test.dart (12 tests, run 33 green) taps an order push on a storefront that sells, on a browse-only one and on one the server lists as browse-only and gets the same order route; J-11.3 stays coded: the route table is a stand-in and a push that started the app is routed by the splash screen, pinned by source."
UPDATES['J-13'] = UPDATES.get('J-13', '') + " lavkooapp #19 (0ed2c844): test/existing_orders_not_gated_test.dart (18 tests, run 33 green) pins that the sale gate is referenced only by the allow-listed files and drives the controllers of the six order features on a browse-only storefront; J-13.2 stays coded: the scan is structural for the screens."
UPDATES['I1-16'] = UPDATES.get('I1-16', '') + " lavkooapp #19 (0ed2c844): test/localization_direction_test.dart (10 tests, run 33 green) pins that the localization controller gives right-to-left for Arabic and left-to-right for English; the website half is lavkoo PR 122 (CI not read); I1-16.5 stays coded."
UPDATES['I2-10'] = UPDATES.get('I2-10', '') + " lavkooapp #19 (0ed2c844): the guards are driven through the real controllers (storefront_payment_guard_test.dart, run 33 green); the cart and checkout screens are not pumped, so the notice and the disabled Proceed are pinned by the text of their source: I2-10.5 stays coded."
UPDATES['I2-13'] = UPDATES.get('I2-13', '') + " lavkooapp #19 (0ed2c844): the notice and the blocked checkout are pinned through the real controllers and by the text of the screens' source, not by a pumped screen: I2-13.1 stays coded."

# ---- the draft pull requests lavkoo #116 to #123 and lavkooappseller #15: the evidence that exists when the agent capacity ran out (2026-11-19). The audit units that would judge their criteria (U11 to U15) could not run, so NO criterion changes state here: each requirement says what exists and what the CI read, and the pull request body carries the full evidence and the gaps. ----
_PR_EVIDENCE = {
    'I1-11': ((116, '8ab3a4dd'), (120, '2ab62bb5'), " PR 116 (draft, main based, 8ab3a4dd): the seller app list search, the seller app edit and switch of an admin coupon made for every vendor, the panel quick view and the panel delete of a coupon that is not the vendor's own are fixed (86 tests; CI run 360: the Fulfillment + security tests on MySQL 8 and MariaDB 11.4.2 and the SQLite jobs are green, the only red tests are the Torod test of main and the deployment overlay, red by design; one judgement call and one hazard need the owner). PR 120 (draft, tests only, 2ab62bb5, stacked on PR 96) pins the cap, free delivery, first-order coupon, per-customer limit and vendor bearer through the real apply route and checkout (CI run 364: the Storefront tests on MySQL 8 and MariaDB 926 tests, none skipped). Not audited: no state changes here."),
    'I1-12': ((120, '2ab62bb5'), None, " PR 120 (2ab62bb5, run 364): the coupon amounts of six platforms are pinned for the stored column, the edit form, the customer's list, apply, checkout and the minimum boundary; the admin edit form floors a stored amount (4.35 shown 4.34, 1.005 shown 1.004, multi-currency KWD 10.505 at 0.307 shown 10.504) and a three-decimal amount cannot round-trip at the decimals setting 2, reported and not fixed. Not audited: no state changes here."),
    'I1-14': ((120, '2ab62bb5'), None, " PR 120 (2ab62bb5, run 364): the per-customer limit (refused at the limit, another customer unaffected, only this coupon's orders count, a canceled order counts, one redemption per order group) and the first-order coupon are pinned through the real apply route and checkout; the checkout-driven cases skip on a strict SQL server until PR 89. Not audited: no state changes here."),
    'I2-09': ((117, '7a638ed1'), None, " PR 117 (draft, tests only, 7a638ed1, stacked on PR 111; CI run 361: 22 of 23 jobs, the overlay red by design): a scan lists every writer of orders (4 creators, 12 writers) and fails on a new one or on one with no gate, reasoned exemption or pending criterion; the marketplace dispatch is pinned as it is. Not met: the three point-of-sale routes carry no sale gate, the marketplace materializer has no owner rule, and the admin and vendor POS and the materializer record no storefront on the order. Not audited: no state changes here."),
    'I2-13': ((119, '71a05e62'), None, " PR 119 (draft, tests only, 71a05e62, stacked on PR 111; CI run 363: 22 of 23 jobs, Storefront tests on MySQL 8 and MariaDB 1,019 tests, none skipped): the cart, wishlist and catalogue of a storefront that is browse only, redirected, draft, ready or active without approved legal texts are served and only the checkout and the payment start are refused (50 tests). Structural only: the website's own cart and wishlist routes; not driven: cart remove-all, select, restock request, referral discount, guest-cart merge. Not audited: no state changes here."),
    'I2-14': ((119, '71a05e62'), None, " PR 119 (71a05e62, run 363): the platform maintenance closes the website, the vendor panel and the config answer for everyone but a signed-in administrator, and no admin or sign-in route carries the gate under any spelling (28 tests). The platform maintenance is cache-only (php artisan optimize:clear ends it while the setting says on) and the config flag is on for any ticked system. Not audited: no state changes here."),
    'E-04': ((118, '533e4cbf'), None, " PR 118 (draft, tests only, 533e4cbf, main based; CI run 362: the whole suite on SQLite, the workflow of main has no real-engine job for tests/Feature/Storefront): the profile, wallet list and loyalty list balances are the columns of the customer and not a sum of the ledger (13 tests). Not audited: no state changes here."),
    'E-08': ((118, '533e4cbf'), None, " PR 118 (533e4cbf, run 362): a scan of app/, Modules/ and routes/ (128 tests) fails on any statement that updates or deletes a wallet or loyalty ledger row; the sentence is FALSE on main: WalletTransactionRepository (lines 98 and 103) and LoyaltyPointTransactionRepository (lines 105 and 110) do, with no caller. The four are pinned by file and text, not excused: E-08.0 must not be read as met until the owner removes them or makes them throw."),
    'E-17': ((118, '533e4cbf'), None, " PR 118 (533e4cbf, run 362): the vendor ledger entry (VendorLedger::record and releaseMatured, 16 tests) stores the currency as passed, is idempotent for a repeated event and changes only status and updated_at; entries are edited outside the class (PayoutService, SettlementEngine) and a concurrent repeat can pass the existence check, so the sentence holds for the class's own methods only. Not audited: no state changes here."),
    'I2-01': ((121, 'af88f045'), None, " PR 121 (draft, tests only, af88f045, stacked on PR 115; CI run 365: 22 of 23 jobs, Storefront tests on MySQL 8 and MariaDB 1,250 tests, the 6 skipped being side-bar tests that are SQLite only by design): the legal document version key (country, type, locale, version) is the only unique key besides the primary key (11 tests). Not audited: no state changes here."),
    'I2-06': ((121, 'af88f045'), None, " PR 121 (af88f045, run 365): the 12 groups and the policy keys of PolicyRegistry are a hand-written list and no policy or group is named like a legal document type, judged by name only (27 tests); eleven existing names that hold a legal type word are allowed by name with a reason. The definitions of the policies are not pinned. Not audited: no state changes here."),
    'I2-07': ((121, 'af88f045'), None, " PR 121 (af88f045, run 365): a scan of the language files, views and answers (147 tests) fails on a phrase that claims legal compliance or approval, English and Arabic; it finds four staff-panel help sentences that do claim compliance, tolerated by name and not approved (owner decision); a claim in Hindi, Bengali or Spanish under a neutral key is not seen. Not audited: no state changes here."),
    'I1-03': ((122, 'ce8786b7'), None, " PR 122 (draft, tests and one CI group, ce8786b7, main based; CI run 367: 17 of 23 jobs, the rest are the overlay and the Torod test of main): the body of an order push is read from the recipient's own translation row for the customer, the seller, the delivery man and the promoter (14 tests); the title is in the language of the request, not of the recipient, and chat, wallet, cash-collect, withdraw, block and product-request pushes are not driven. Not audited: no state changes here."),
    'I1-05': ((122, 'ce8786b7'), None, " PR 122 (ce8786b7, run 367): the list of push topics and their names, scans that fail on a renamed topic or a new sender, and the wire call of the admin campaign and the maintenance switches (12 tests); the customer app's side is not covered. Not audited: no state changes here."),
    'I1-09': ((122, 'ce8786b7'), None, " PR 122 (ce8786b7, run 367): the abandoned-cart reminder ledger is pinned (5 tests): the real behaviour is AT MOST ONCE and a failed send looks like a sent one (the row is written before the mail, the stats count it as sent); the key of the live table is in no file. Not audited: no state changes here."),
    'I1-10': ((122, 'ce8786b7'), None, " PR 122 (ce8786b7): the notification and localization tests now run against MySQL 8 and MariaDB 11.4.2 as a third group of the real-engine job (run 367: 37 tests, 174 assertions, success on both), so the characterizations of PR 122 are proven on SQLite, MySQL 8 and MariaDB; chat, wallet, cash-collect, withdraw, block and product-request pushes have no characterization. Not audited: no state changes here."),
    'I1-16': ((122, 'ce8786b7'), None, " PR 122 (ce8786b7, run 367): the website switches between Arabic (rtl) and English (ltr) and the rendered maintenance page follows (6 tests); the other layouts are scan only and the maintenance page prints dir=\"\" when the session has no direction yet. Not audited: no state changes here."),
    'M1-05': ((122, 'ce8786b7'), None, " PR 122 (ce8786b7, run 367): ten places that issue a one-time code are driven through their routes (live mode random, demo and dev fixed) and two scans cover the rest (27 tests); two deciders are dead code and the older seller app route issues 1234 in dev mode only. Not audited: no state changes here."),
    'M2-06': ((122, 'ce8786b7'), None, " PR 122 (ce8786b7, run 367): an order of two sellers becomes one order each with one group id and the one currency an order row has on main (4 tests); skipped on a strict SQL server until PR 89. Not audited: no state changes here."),
    'I1-07': ((123, '53b1d22f'), None, " PR 123 (draft, tests only, 53b1d22f, stacked on PR 111; CI run 368: 22 of 23 jobs, Storefront tests on MySQL 8 and MariaDB 1,121 tests, none skipped): in nine states of a storefront the customer's device gets exactly one push for each status step, refund decision and returned amount of an order placed before the state (180 tests). The application sends NO e-mail for the status of an order, a refund or a due payment, and the due-payment push is never sent (its key has no entry in PushNotificationTrait::pushNotificationMessage), in a storefront that sells either. Not audited: no state changes here."),
}
for _requirement, (_first, _second, _text) in _PR_EVIDENCE.items():
    for _link in (_first, _second):
        if _link:
            EXTRA_LINKS[_requirement] = [link for link in EXTRA_LINKS.get(_requirement, []) if link.get('pr') != _link[0] or link.get('repo') != 'lavkoo'] + [{'repo': 'lavkoo', 'pr': _link[0], 'sha': _link[1]}]
    UPDATES[_requirement] = UPDATES.get(_requirement, '') + _text
EXTRA_LINKS['K-03'] = EXTRA_LINKS.get('K-03', []) + [{'repo': 'lavkooappseller', 'pr': 15, 'sha': 'c816274'}]
EXTRA_LINKS['K-06'] = EXTRA_LINKS.get('K-06', []) + [{'repo': 'lavkooappseller', 'pr': 15, 'sha': 'c816274'}]
UPDATES['K-03'] = UPDATES.get('K-03', '') + " lavkooappseller #15 (draft, c816274, stacked on #14, holds #13): a queued POS sale carries the market it was taken in and is replayed under it (16 tests, a real DioClient with a recording adapter). Not met: the market is read when the sale is queued, after the first request has failed, so a switch inside that window stamps the new market. CI: push run 33 of claude/seller-outbox-market-ci on the same commit, every named step success, the whole suite red only for the known version gate test."
UPDATES['K-06'] = UPDATES.get('K-06', '') + " lavkooappseller #15 (c816274): a sale that names no seller is sent by no session but the one that rang it up and is never deleted (52 owner tests in run 33). Not met: after an app restart a no-seller sale read from the disk is bound to the first session of the new run, so the next seller can send it under their name, and a no-seller sale of an ended session is sent by no session at all; both need the owner."

# J-17.1 was published as MET on 2026-11-18 and the audit does not agree: the rule is pinned, the splash screen's use of it is not (judge: DOWNGRADE_TO_CODED); J-17.0 keeps CODED and says what ran
apply_audit_2026_11_19(only=sorted(_AUDIT_2026_11_19))

def apply_override(requirement, override):
    requirement['state'] = override['state']
    for field in ('done_in_drafts', 'remaining', 'next_task'):
        requirement[field] = override[field]
    requirement['links'] = list(requirement.get('links', [])) + override['links']
    requirement['tests'] = override['tests']
    for index, (status, evidence) in override['ac'].items():
        requirement['ac'][index]['s'] = status
        requirement['ac'][index]['e'] = evidence
    for index, parts in sorted(override.get('ac_split', {}).items(), reverse=True):
        requirement['ac'][index:index + 1] = [{'t': text, 's': status, 'e': evidence} for text, status, evidence in parts]


def load_all():
    units = T.load_results(T.WORKFLOWS + EXTRA_WORKFLOWS)
    all_reqs = {}
    for unit, data in sorted(units.items()):
        if 'assess' not in data:
            continue
        reqs, notes = T.apply_verification(unit, data['assess'], data.get('verify'))
        verify = data.get('verify')
        verified = bool(verify)
        for r in reqs:
            r['unit'] = unit
            r['axis'] = T.UNIT_AXIS.get(unit, unit[0])
            r['verified_unit'] = verified
            r['evidence_failures'] = T.check_evidence(r)
            r['verifier_notes'] = []
            override = OVERRIDES.get(r['id'])
            if override:
                apply_override(r, override)
            r['links'] = list(r.get('links', [])) + EXTRA_LINKS.get(r['id'], [])
            for index, (status, evidence) in CRITERIA.get(r['id'], {}).items():
                r['ac'][index]['s'] = status
                r['ac'][index]['e'] = evidence
            for index, parts in sorted(CRITERIA_SPLIT.get(r['id'], {}).items(), reverse=True):
                r['ac'][index:index + 1] = [{'t': text, 's': status, 'e': evidence} for text, status, evidence in parts]
            if UPDATES.get(r['id']):
                r['updates'] = [UPDATES[r['id']]]
            if NEXT_CURRENT.get(r['id']):
                r['next_task_current'] = NEXT_CURRENT[r['id']]
            if r['id'] in STATE_AFTER:
                r['state'] = STATE_AFTER[r['id']]
            if r['id'] in INTEGRATED_IN_RUN_293 and r['state'] == 'IMPLEMENTED IN DRAFT':
                r['state'] = 'INTEGRATED AND TESTED'
                r['updates'] = list(r.get('updates', [])) + [INTEGRATED_NOTE]
        by_id = {r['id']: r for r in reqs}
        if verified:
            for cor in verify.get('field_corrections', []):
                r = by_id.get(cor['id'])
                if r and cor['field'] not in APPLIED_FIELDS:
                    r['verifier_notes'].append('%s: %s Proposed: %s' % (cor['field'], cor['problem'], cor['replacement']))
                elif r and cor['field'] in APPLIED_FIELDS:
                    r['verifier_notes'].append('%s corrected by the verifier (applied above).' % cor['field'])
            for number, miss in enumerate(verify.get('missing_requirements', []) or [], 1):
                state = miss.get('suggested_state') if miss.get('suggested_state') in T.STATES else 'IN PROGRESS'
                status = 'MET' if state in ('MERGED', 'INTEGRATED AND TESTED', 'READY FOR OWNER REVIEW') else ('CODED' if state == 'IMPLEMENTED IN DRAFT' else 'MISSING')
                text = miss['requirement'].replace('\n', ' ')
                reqs.append({
                    'id': '%s-V%d' % (unit, number), 'name': text[:100] + ('...' if len(text) > 100 else ''), 'plan_lines': str(miss.get('plan_lines', '')),
                    'refs': 'added by the independent verifier: the assessor of this unit omitted it', 'functional': text[:600],
                    'exists_now': miss.get('evidence_note', '')[:900], 'done_in_drafts': 'See the evidence note above.',
                    'remaining': 'Its acceptance criteria are not written yet; until they are, it counts as one criterion.', 'links': [], 'tests': [],
                    'deps_blockers': 'See the evidence note.', 'state': state, 'reviews_required': [], 'owner_decisions': 'none', 'evidence': [],
                    'ac': [{'t': text[:160], 's': status, 'e': 'one placeholder criterion for a requirement found by the verifier; write the real ones before relying on this line'}],
                    'next_task': 'Write its acceptance criteria and its next coding task (added by the verifier).',
                    'unit': unit, 'axis': T.UNIT_AXIS.get(unit, unit[0]), 'verified_unit': True, 'evidence_failures': [], 'verifier_notes': [], 'added_by_verifier': True,
                })
        for r in reqs:
            if r.get('added_by_verifier'):
                # A requirement the verifier added is appended after the overrides were applied: what a pull request did for it is applied here.
                r['links'] = list(r.get('links', [])) + EXTRA_LINKS.get(r['id'], [])
                for index, (status, evidence) in CRITERIA.get(r['id'], {}).items():
                    r['ac'][index]['s'] = status
                    r['ac'][index]['e'] = evidence
                # A verifier's requirement arrives with ONE placeholder criterion and a section 5 that says so. Splitting that placeholder is how
                # its real acceptance criteria get written, so the same CRITERIA_SPLIT the other requirements use has to reach these as well; a
                # card whose criteria are written must also stop saying they are not, which is what WRITTEN_CRITERIA replaces.
                for index, parts in sorted(CRITERIA_SPLIT.get(r['id'], {}).items(), reverse=True):
                    r['ac'][index:index + 1] = [{'t': text, 's': status, 'e': evidence} for text, status, evidence in parts]
                if r['id'] in WRITTEN_CRITERIA:
                    r['remaining'], r['next_task'] = WRITTEN_CRITERIA[r['id']]
                if UPDATES.get(r['id']):
                    r['updates'] = [UPDATES[r['id']]]
                if NEXT_CURRENT.get(r['id']):
                    r['next_task_current'] = NEXT_CURRENT[r['id']]
                if r['id'] in STATE_AFTER:
                    r['state'] = STATE_AFTER[r['id']]
        all_reqs[unit] = {'reqs': reqs, 'notes': notes, 'assess': data['assess'], 'verify': verify}
    return all_reqs


def md_escape(text):
    return text.replace('|', '\\|').replace('\n', ' ')


def card(r):
    text = T.card(r)
    extra = []
    if not r.get('verified_unit'):
        extra.append('- **Verification.** Assessor result, not independently verified yet; its percentages are the assessor\'s own reading.')
    for note in r.get('verifier_notes', []):
        extra.append('- **Verifier note (not applied automatically).** %s' % note[:900])
    for update in r.get('updates', []):
        extra.append('- **Update after the assessment.** %s' % update)
    if r.get('next_task_current'):
        extra.append('- **Next coding task today (supersedes item 10 above).** %s' % r['next_task_current'])
    if extra:
        text = text.rstrip('\n') + '\n' + '\n'.join(extra) + '\n'
    return text


def write_axis_files(out_dir, by_axis, asof):
    os.makedirs(out_dir + '/tracker', exist_ok=True)
    for axis in sorted(by_axis):
        reqs = sorted(by_axis[axis], key=lambda r: r['id'])
        m = T.metrics(reqs)
        verified = sorted({r['unit'] for r in reqs if r.get('verified_unit')})
        unverified = sorted({r['unit'] for r in reqs if not r.get('verified_unit')})
        head = [
            '# Axis %s: %s' % (axis, T.AXES[axis]), '',
            'Detail cards of the master tracker ([`MASTER_IMPLEMENTATION_TRACKER.md`](../MASTER_IMPLEMENTATION_TRACKER.md)), as of %s. Requirements: %d. Acceptance criteria: %d (MET %d, CODED %d, MISSING %d). Code completion %s, proven by a passing test %s, release readiness %s.' % (
                asof, m['n'], m['ac'], m['met'], m['coded'], m['ac'] - m['met'] - m['coded'], T.pct(m['implemented_pct']), T.pct(m['proven_pct']), T.pct(m['release_pct'])), '',
            'Independent verification: %s%s.' % ('done for unit(s) ' + ', '.join(verified) if verified else 'not done yet', ('; not done yet for ' + ', '.join(unverified)) if unverified and verified else ''), '',
            'Every card gives the ten fields of the directive: (1) id and name, (2) functional requirement, (3) what exists in main, (4) what the draft pull requests did, (5) what remains, (6) pull requests and commits, (7) tests that exist and where they ran, (8) dependencies and blockers, (9) acceptance criteria marked MET (a named passing test or CI job on a named head), CODED (code exists, no passing test cited) or MISSING, (10) the next coding task.', '',
        ]
        body = head + [card(r) for r in reqs]
        open(out_dir + '/tracker/' + T.AXIS_FILE[axis], 'w').write('\n'.join(body))


def gap_numbers(by_id, ids):
    rs = [by_id[i] for i in dict.fromkeys(ids) if i in by_id]
    ac = sum(len(r['ac']) for r in rs)
    miss = sum(1 for r in rs for a in r['ac'] if a['s'] == 'MISSING')
    not_started = sum(1 for r in rs if r['state'] == 'NOT STARTED')
    return rs, len(rs), not_started, ac, miss


def render_main(out_dir, all_reqs, asof, context):
    all_list = [r for d in all_reqs.values() for r in d['reqs']]
    by_id = {r['id']: r for r in all_list}
    by_axis = collections.defaultdict(list)
    for r in all_list:
        by_axis[r['axis']].append(r)
    write_axis_files(out_dir, by_axis, asof)
    total = T.metrics(all_list)
    lines = []
    w = lines.append
    w('# LAVKOO GCC: master implementation tracker')
    w('')
    w('As of %s. Generated from the independent assessment of every requirement of the original multi-country plan (13 axes, A to M) against the state of GitHub, then checked by independent verifiers. It replaces any reading of the pull request list as progress: **a pull request that is open is not a requirement that is done.**' % asof)
    w('')
    w('## 1. Read this first')
    w('')
    w('- **Nothing here is merged, deployed or verified in production.** All %d draft pull requests of `lavkoo`, %d of `lavkooapp` and %d of `lavkooappseller` are open; `main` has none of the GCC work. The live server is described by the release documents as running an older baseline (`698aa1e`), which no document proves from outside. No state in this document claims production.' % (context['lavkoo_prs'], context.get('lavkooapp_prs', 7), context.get('lavkooappseller_prs', 8)))
    w('- **A percentage is shown only where it is computed from acceptance criteria that a test could assert** (formulas in section 4). Code completion and release readiness are two different numbers and are never mixed: code can be 90% complete and still 0% released.')
    w('- **MET means a named test or CI job on a named head passed.** A limited unit test, a pull request body or a green job that does not cover the behaviour is not proof; the verifiers refuted such claims and the corrections are applied.')
    w('- The exposed keys and credentials stay in the register of unremediated risks (`DEFERRED_RISKS.md`, DR-1 to DR-5). Deferring their rotation does not close them.')
    pending_units = sorted(u for u in all_reqs if not all_reqs[u]['verify'])
    if pending_units:
        w('- Units whose independent verification is still running are marked below (%s); their numbers are the assessor\'s and can only go down when the verifier finishes.' % ', '.join(pending_units))
    else:
        w('- Every one of the %d units was read by an independent verifier that tried to refute its states, tests and evidence; the corrections are applied or shown on the card. Work done after the assessors read the trees (new pull requests, CI results) is added as "Update after the assessment" on the cards it touches.' % len(all_reqs))
    w('')
    w('## 2. Dashboard by axis')
    w('')
    w('| Axis | Requirements | Criteria | MET | CODED | MISSING | Code completion | Proven by tests | Release readiness | Verified |')
    w('|---|---:|---:|---:|---:|---:|---:|---:|---:|---|')
    for axis in sorted(by_axis):
        reqs = by_axis[axis]
        m = T.metrics(reqs)
        units = sorted({r['unit'] for r in reqs})
        ver = [u for u in units if all_reqs[u]['verify']]
        verified_text = 'all' if len(ver) == len(units) else ('none' if not ver else 'part (%s)' % ', '.join(ver))
        w('| [%s. %s](tracker/%s) | %d | %d | %d | %d | %d | %s | %s | %s | %s |' % (axis, T.AXES[axis], T.AXIS_FILE[axis], m['n'], m['ac'], m['met'], m['coded'], m['ac'] - m['met'] - m['coded'], T.pct(m['implemented_pct']), T.pct(m['proven_pct']), T.pct(m['release_pct']), verified_text))
    w('| **All axes (pooled)** | **%d** | **%d** | **%d** | **%d** | **%d** | **%s** | **%s** | **%s** | |' % (total['n'], total['ac'], total['met'], total['coded'], total['ac'] - total['met'] - total['coded'], T.pct(total['implemented_pct']), T.pct(total['proven_pct']), T.pct(total['release_pct'])))
    w('')
    w('The pooled row counts every criterion once; it is not weighted by importance or effort and is not a forecast. The two code figures are not release figures: with nothing merged, release readiness can only come from drafts and integration branches that have been tested.')
    w('')
    w('### Requirements by gate')
    w('')
    w('| Gate | Requirements |')
    w('|---|---:|')
    for state in T.STATES:
        w('| %s | %d |' % (state, total['states'].get(state, 0)))
    w('')
    w('## 3. The three functional gaps that block a complete multi-country LAVKOO')
    w('')
    w('Chosen from the table of requirements, not from the pull request list: each one is named by a completion criterion of the owner\'s directive, is mostly MISSING in the criteria, and has other requirements waiting on it. Numbers are read from this tracker.')
    w('')
    for number, gap in enumerate(GAPS, 1):
        rs, n, not_started, ac, miss = gap_numbers(by_id, gap['ids'])
        w('### Gap %d. %s' % (number, gap['title']))
        w('')
        w('- **Blocks:** %s.' % gap['blocks'])
        w('- **Where it stands:** %s' % gap['why'])
        w('- **Measured:** %d requirements (%d not started), %d criteria of which %d MISSING (%s)%s. Requirements: %s.' % (n, not_started, ac, miss, T.pct(100.0 * miss / ac if ac else 0), gap.get('measured_note', ''), ', '.join(r['id'] for r in rs)))
        w('- **First executable step:** %s' % gap['first'])
        w('')
    w('Next in line (not in the top three because they depend on these, or are optional until the owner opens them):')
    w('')
    for title, text in RUNNERS_UP:
        w('- **%s:** %s' % (title, text))
    w('')
    w('## 4. Formulas and definitions')
    w('')
    w('- **Requirement:** one independently verifiable obligation of the plan, with a stable id (`<unit>-<number>`); `V` in the number marks one the verifier added because the assessor omitted it (counted as one placeholder criterion until its real criteria are written).')
    w('- **Acceptance criterion:** one observable behaviour a test could assert. States: `MET` (a named test or CI job on a named head passed and covers it), `CODED` (the code exists and no passing test is cited), `MISSING`.')
    w('- **Code completion** = (MET + CODED) / all criteria. **Proven by tests** = MET / all criteria. Both use the same denominator, so the gap between them is exactly the untested code.')
    w('- **Release readiness** = sum of gate points / (5 x requirements). Gate points: NOT STARTED and IN PROGRESS 0, IMPLEMENTED IN DRAFT 1, INTEGRATED AND TESTED 2, READY FOR OWNER REVIEW 3, MERGED 4, DEPLOYED AND VERIFIED 5. It measures travel along the gates, not quality.')
    w('- **States:** NOT STARTED (no code on any branch); IN PROGRESS (some code, at least one criterion MISSING); IMPLEMENTED IN DRAFT (all criteria coded in a draft pull request, not yet in a tested integration); INTEGRATED AND TESTED (inside an integration tree whose CI is green on the engines that matter); READY FOR OWNER REVIEW (nothing known open, evidence listed); MERGED; DEPLOYED AND VERIFIED.')
    w('- **Evidence:** every card carries evidence triples (repository, ref, file, text) that a script checked mechanically against the git objects: %d requirements, %d failures.' % (len(all_list), sum(1 for r in all_list if r.get('evidence_failures'))))
    w('')
    w('## 5. Blockers and decisions that are the owner\'s')
    w('')
    owner = collections.defaultdict(list)
    for r in all_list:
        text = (r.get('owner_decisions') or '').strip()
        if text and text.lower() not in ('none', 'none.'):
            owner[r['axis']].append((r['id'], text))
    w('%d requirements name at least one open owner decision; they are listed on the card of each requirement under "Open owner decisions" and by axis in [`tracker/owner-decisions.md`](tracker/owner-decisions.md). The reviews that gate a merge or a release are: finance review of the wallet and rounding rules, an independent security review of the platform admin (GCC-20), a native Arabic reader for every new `sa` string, a legal review before any compliance claim, and the owner\'s choice of legal entities and gateway accounts per country.' % sum(len(v) for v in owner.values()))
    w('')
    lines_od = ['# Open owner decisions by axis', '', 'Taken from the "Open owner decisions" field of each requirement card, as of %s. Nothing in this list is decided by the code; each is a choice, a review or a fact only the owner or an outside party can supply.' % asof, '']
    for axis in sorted(owner):
        lines_od.append('## Axis %s: %s' % (axis, T.AXES[axis]))
        lines_od.append('')
        for rid, text in owner[axis]:
            lines_od.append('- **%s:** %s' % (rid, text.replace('\n', ' ')))
        lines_od.append('')
    open(out_dir + '/tracker/owner-decisions.md', 'w').write('\n'.join(lines_od))
    w('## 6. Next coding queue')
    w('')
    w('Executable on an isolated branch without production access, ordered by the priority classes of the owner\'s directive (P0 security and release, P1 core six-country functions, P2 admin, themes, marketing, integrations, shipping, P3 apps and GCC-20, P4 end to end and release readiness) and then by the number of MISSING criteria. The first 25 are shown; the full text of every task is in item 10 of its card. Several tasks written by the assessors are stale after today\'s work (the card then carries an "Update after the assessment" and the task of today).')
    w('')
    open_reqs = [r for r in all_list if r['state'] in ('NOT STARTED', 'IN PROGRESS') and any(a['s'] == 'MISSING' for a in r['ac'])]
    order = list(dict.fromkeys(PRIORITY.values()))
    open_reqs.sort(key=lambda r: (order.index(PRIORITY[r['unit']]), -sum(1 for a in r['ac'] if a['s'] == 'MISSING'), r['id']))
    w('| Priority | Id | State | Missing | Requirement | Next coding task |')
    w('|---|---|---|---:|---|---|')
    for r in open_reqs[:25]:
        w('| %s | [%s](tracker/%s) | %s | %d | %s | %s |' % (PRIORITY[r['unit']].split(' ')[0], r['id'], T.AXIS_FILE[r['axis']], r['state'], sum(1 for a in r['ac'] if a['s'] == 'MISSING'), md_escape(r['name'])[:90], md_escape(r.get('next_task_current', r['next_task']))[:230]))
    w('')
    w('## 7. Index of all requirements')
    w('')
    w('`M/C/X` is the count of MET, CODED and MISSING criteria.')
    w('')
    for axis in sorted(by_axis):
        w('### %s. %s' % (axis, T.AXES[axis]))
        w('')
        w('| Id | State | M/C/X | Requirement |')
        w('|---|---|---|---|')
        for r in sorted(by_axis[axis], key=lambda r: r['id']):
            met = sum(1 for a in r['ac'] if a['s'] == 'MET')
            coded = sum(1 for a in r['ac'] if a['s'] == 'CODED')
            miss = sum(1 for a in r['ac'] if a['s'] == 'MISSING')
            w('| [%s](tracker/%s) | %s | %d/%d/%d | %s |' % (r['id'], T.AXIS_FILE[axis], r['state'], met, coded, miss, md_escape(r['name'])[:120]))
        w('')
    w('## 8. Method, verification and what was not inspected')
    w('')
    w('Nineteen assessors (one per unit of the plan) read the original plan lines, `origin/main`, the draft pull request heads and the two CI-only integration trees, and wrote requirements with evidence triples. A second, independent verifier per unit then tried to refute every state, test claim and evidence line, and listed requirements the assessor missed. State refutations and criterion changes are applied; text corrections whose replacement is an instruction rather than a sentence are shown on the card as a verifier note and not applied. Units still waiting for their verifier are marked in the dashboard.')
    w('')
    w('| Unit | Axis | Requirements | Verified | State or criterion changes applied | Requirements added by the verifier |')
    w('|---|---|---:|---|---:|---:|')
    for unit in sorted(all_reqs):
        d = all_reqs[unit]
        applied = sum(1 for n in d['notes'] if ' state ' in n or 'criterion' in n or 'test claim' in n)
        added = sum(1 for r in d['reqs'] if r.get('added_by_verifier'))
        w('| %s | %s | %d | %s | %d | %d |' % (unit, T.UNIT_AXIS.get(unit, unit[0]), len(d['reqs']), 'yes' if d['verify'] else 'not yet', applied, added))
    w('')
    gaps = collections.OrderedDict()
    for unit in sorted(all_reqs):
        for g in all_reqs[unit]['assess'].get('coverage_gaps', []) or []:
            gaps.setdefault(g.strip(), []).append(unit)
    lines_gaps = ['# What could not be inspected, by unit', '', 'Coverage gaps stated by the assessors (section 8 of the master tracker), as of %s. Production, provider accounts, the real numbers behind owner decisions and anything outside the three repositories are not inspected by any unit.' % asof, '']
    for text, units in gaps.items():
        lines_gaps.append('- (%s) %s' % (', '.join(units), text.replace('\n', ' ')))
    open(out_dir + '/tracker/coverage-gaps.md', 'w').write('\n'.join(lines_gaps) + '\n')
    w('Coverage gaps (what no unit could inspect) are listed in [`tracker/coverage-gaps.md`](tracker/coverage-gaps.md). The common ones: production was not inspected, so no live setting is claimed; provider contracts, regulatory facts and customs rules are facts outside the repositories; CI logs are read per head, not re-run.')
    w('')
    w('## 9. State of GitHub this was measured against')
    w('')
    for item in context['github']:
        w('- %s' % item)
    w('')
    w('## 10. Changes since the assessors read the trees')
    w('')
    for item in context['since']:
        w('- %s' % item)
    w('')
    open(out_dir + '/MASTER_IMPLEMENTATION_TRACKER.md', 'w').write('\n'.join(lines))
    return total


# ---- M2-19: the Saudi literals of the platform are a closed list (PR 124, gcc/p1-saudi-literals-guard, 3a23dadc; CI run 369 read per job on 2026-11-19) ----
EXTRA_LINKS['M2-19'] = EXTRA_LINKS.get('M2-19', []) + [{'repo': 'lavkoo', 'pr': 124, 'sha': '3a23dadc'}]
CRITERIA.setdefault('M2-19', {})[2] = ('CODED', "PR 124 (draft, base main, head 3a23dadc, TESTS ONLY, no application file, no migration, no switch): tests/Support/SaudiLiteralScanner.php reads app, config and Modules with token_get_all and skips comments and docblocks, and tests/Unit/Architecture/SaudiLiteralsAllowListTest.php (5 tests, 150 assertions) holds the closed list of the 58 Saudi literals in 28 files in five categories: 24 a row of a world list (the riyal among every currency, the seven gateway currency lists GCC-05 itself reads to ask whether a gateway takes a storefront's currency), 7 not Saudi at all ('sa' is the ISO code of Sanskrit, of an older customer build's locale and of a right-to-left language), 10 a Saudi-only rule (SaudiPhone, the Torod payload, the MyFatoorah number split, the channel echo scrubber, the two dispatch comparisons that refuse a destination outside the Kingdom, Saudi address recognition and region import), 8 a platform money default and 9 the Saudi default of a per-account provider setting; one test asserts that the 17 defaults the plan still has to move stay counted, and the provider half confirms this requirement's own verifier note that those literals are the defaults of per-account settings rather than hard-wired values. The guard fails in both directions: a literal the list does not name, a count that changed, and a list entry nothing matches any more each fail it. Four mutations were run and all four caught: a new 'SAR' in a file the list already names, a literal in a file it does not name, a listed literal removed from the code, and the scanner made to return nothing, which fires the assertion that the scan found anything at all. CI run 369 (pull_request, id 44491075743, head 3a23dadc, 15 of 21 jobs success): the five tests ran inside 'Tests, SQLite' on PHP 8.3 and on PHP 8.2 (6205 tests, 792474 assertions, 97 skipped, one failure which is TorodShipmentDiscoveryTest, red on main; main alone runs 6200, cross-checked against runs 360, 362 and 367), the deployment overlay is red by design and four more jobs are red for that same Torod test. NOT covered: the second half of the criterion, that no Saudi literal reaches a non-Saudi order, is a behaviour no scan can show and no test asserts it, so the criterion stays CODED; and the scan reads app, config and Modules only, so a literal in resources, routes or database would not be found.")


_R125 = "CI run id 44517678226 (pull_request, head 6b1bf942), read job by job on 2026-11-19: 22 of 23 jobs success. Green on 'Storefront tests, MySQL 8' and 'Storefront tests, MariaDB 11.4.2', both 1118 tests / 6073 assertions; on the whole suite 'Tests, SQLite' 7407 tests / 798843 assertions on PHP 8.3 and on PHP 8.2; and on strict-mode MariaDB 11.4.2 (4021 / 35470), address (1045 / 4956 on both engines), fulfillment and security (1703 / 8897 MySQL, 8900 MariaDB), the ten sales-channel and cost shards, both PHP syntax jobs and the secret scan. The single red is 'Deployment overlay (artifact, deploys nothing)', red by design and not this branch's: its step refuses because firebase-messaging-sw.js, public/firebase-messaging-sw.js and robots.txt differ between the deploy baseline 698aa1e and the BASE branch gcc/p1-storefront-lifecycle, and this head adds none of those three files."
_R126 = "CI run id 44522423719 (pull_request, head 331ee671), read job by job on 2026-11-19: 22 of 23 jobs success. 'Storefront tests' 994 tests / 5237 assertions on MySQL 8 and on MariaDB 11.4.2; 'Tests, SQLite' 7283 / 798007 on PHP 8.3 and 8.2; strict-mode MariaDB 4021 / 35470; the address, fulfillment-and-security and ten sales-channel shards green. The one red is the deployment overlay, red by design for the three files of the base branch named above, none of which this head touches."
_R127 = "CI run id 44530335351 (pull_request, head 2976ebb2), read job by job on 2026-11-19: 22 of 23 jobs success. 'Storefront tests' 1299 tests / 5687 assertions on MySQL 8 and on MariaDB 11.4.2 (1284 before this head); 'Tests, SQLite' 7588 / 798456 on PHP 8.3 and 8.2; strict-mode MariaDB 4021 / 35470; address, fulfillment-and-security and the ten sales-channel shards green. The one red is the deployment overlay, red by design for three files of the base branch that this head does not touch."
_R20 = "app CI run id 44530243729 (head 80069745), read job by job on 2026-11-19: both jobs success. 'storefront-header' ran the named steps with the switch off and on (534 tests passed / 23 skipped and 312 passed / 11 skipped, plus the per-file steps); 'analyze-whole-project' reports 255 findings and 0 errors against the 255 recorded in tool/analyze_baseline.txt, so this head adds no analyzer finding. Flutter 3.47.0 / Dart 3.13.0, the CI pin."
_R16 = "seller CI run id 44530418881 (head 7538ad9b), read job by job on 2026-11-19: 'analyze-and-test' reports 325 tests passed, 1 failed; 'build-android' skipped. The single failure is test/app_version_gate_test.dart 'force-update gate the shipped version clears the backend minimum, so login is reachable', which is red on seller main as well, proved on main's own run 43229610276 job 131221083251 (141 passed, 1 failed, the same test), so this head adds 184 passing tests and no new red; git diff 83bd60f..HEAD -- test/app_version_gate_test.dart is empty."


# ---- Wave 5 finished: the four carried-over WIP branches reviewed, repaired and opened as drafts (2026-11-19) ----

for _req, _link in [('I1-07', {'repo': 'lavkoo', 'pr': 125, 'sha': '6b1bf942'}),
                    ('J-13', {'repo': 'lavkoo', 'pr': 126, 'sha': '331ee671'}),
                    ('J-13', {'repo': 'lavkooapp', 'pr': 20, 'sha': '80069745'}),
                    ('F-10', {'repo': 'lavkoo', 'pr': 127, 'sha': '2976ebb2'}),
                    ('F-12', {'repo': 'lavkoo', 'pr': 127, 'sha': '2976ebb2'}),
                    ('K-16', {'repo': 'lavkooappseller', 'pr': 16, 'sha': '7538ad9b'})]:
    EXTRA_LINKS[_req] = [l for l in EXTRA_LINKS.get(_req, [])
                         if not (l.get('repo') == _link['repo'] and l.get('pr') == _link['pr'])] + [_link]

CRITERIA.setdefault('I1-07', {})[3] = ('MET', "PR 125 (draft, base gcc/p1-storefront-lifecycle at 7e19d284, head 6b1bf942, behind GCC_STOREFRONTS_ENABLED which ships off): NotificationTypeRegistry classifies every notification type the code sends as necessary or marketing \u2014 68 push, 22 e-mail, 4 WhatsApp \u2014 and a scan test fails in BOTH directions: a type that is sent but not listed, and a listed type nothing sends. %s Carried over from the unreviewed branch claude/wip-unreviewed-marketing-stop-registry (72a568e1), which is left untouched; run, self-reviewed, read by two independent adversarial reviewers and repaired. NOT covered: the registry classifies, it does not yet gate \u2014 see criterion 3 for which senders ask it." % _R125)

CRITERIA['I1-07'][2] = ('CODED', "PR 125 (6b1bf942): the abandoned-cart reminder and the admin WhatsApp cart-marketing list skip a cart whose products belong to a storefront that may not sell, and a skipped cart writes NO ledger row, so it is reminded once the storefront sells again. Four defects were found and fixed, two of them by the reviewers: a storefront switched off in configuration alone was still invited (fixed in StorefrontMarketingStop, deliberately not in the shared StorefrontSellingGate, because a gate refusing an unserved country would refuse money already taken); an N+1 of one SELECT per cart group on the admin page, now one query per 500 ids; a cart group naming two countries judged by its first row alone, which both missed a stopped storefront and over-reached into a selling one; and the WhatsApp cart total and the admin list summing money of storefronts trading in different currencies into one unmarked figure, so while the storefront-currency switch is on a customer whose carts name more than one storefront is left out of the list and refused by the send. Mutations run and killed, including the reviewers' own surviving one (countryOfCartGroup reading a group's last row rather than its first). Saudi default-off proved by a query count of ZERO reads while storefronts are off \u2014 not a setting, not a table, not a cart. %s NOT MET: only two of the four marketing types ask the stop. The admin push broadcast (one FCM topic plus every approved seller) and the referral push (SendReferralRegistrationNotificationListener) do not, so a customer of a stopped market still receives them; scoping the broadcast needs a topic per country and matching subscriptions in both Flutter apps, i.e. a release. One message per storefront, and storefront decimals on the figure, are also left undone." % _R125)

CRITERIA.setdefault('J-13', {})[1] = ('CODED', "Both halves are now pinned. BACKEND, lavkoo PR 126 (draft, TESTS ONLY \u2014 no application file, no migration, no switch; base gcc/p1-storefront-lifecycle at 7e19d284, head 331ee671): the five customer order answers were enumerated from the route files and each driven over real HTTP in six states of the record (a Kuwaiti order, the default storefront on a single-currency platform, the default storefront on a converting platform, a record cleared, an order placed with the currency switch off, one placed with both switches off), proving the server really does send storefront_country, currency_code and currency_rate \u2014 by themselves, because the answers are raw Eloquent models (test_a_column_added_to_the_orders_table_appears_in_the_answer_by_itself). The migration is rolled back under the same order, so an app built before this degrades correctly. %s APP, lavkooapp PR 20 (draft, base gcc/p1-customer-pins at 0ed2c844, head 80069745, behind the storefront switch, no version change): convertOrderPrice fell through to the storefront the customer is on NOW, so a customer on Kuwait read a 12.50 SAR order as 'KD 12.500'; the first fix then routed it into _priceOfThePlatform, which multiplies by the chosen currency's rate and wrote 'SR47' \u2014 a wrong mark replaced by a plausible wrong amount. Both are fixed and pinned, together with a Country row that overflowed at phone width, the same order line written in two currencies on screens reached from the same place, and one screen showing two currencies at once. %s The app's 2 and 10 failures were verified against the base rather than accepted: identical at 0ed2c844, while this head passes 70 more tests, and several of the ten exist to assert flag-OFF behaviour. NOT MET: POST /api/v1/order/track-order answers 403 to every signed-in customer, because RestAPI/v1/OrderController::track_order scopes the lookup with auth('customer'), the website session guard an API token never fills; the 403 is pinned as what it answers today rather than claimed fixed, since that is a user-facing change. The offline payment screen is covered by a source scan alone. ChannelOrderMaterializer inserts an order and never records the storefront snapshot, so a marketplace-channel order carries no market." % (_R126, _R20))

CRITERIA.setdefault('F-10', {})[2] = ('MET', "PR 127 (draft, base gcc/p1-theme-publish-per-storefront at eaa04e2f, head 2976ebb2, behind GCC_STOREFRONT_THEMES which ships off): StorefrontThemeBannerCardsTest pins that the tiles of a section and of the web page linked to banners reach only the storefronts that may sell their product, that the dashboard banners a storefront is given are its own products' and those of no product, and that a section draws NOTHING rather than another country's banner when the narrowing read cannot be answered. Three defects were found and fixed, two by the reviewers: the folder decision was taken AFTER the narrowing, so a Kuwaiti storefront was served a retired offer out of a dead theme folder (nobody had caught it because every banner of the existing 53-test class belongs to the active folder, so the fallback was never exercised); bannersNotOfferedByTheStorefront wrapped its read in safely(), and an empty 'leave out' list means leave nothing out, so a broken read FAILED OPEN and delivered every banner, another country's included; and the not-offered set was computed over every product banner of the shop, hydrating a full Product for each. The storefront now narrows in the read itself, so the switched-off Saudi storefront pays nothing for the feature, pinned by a statement count. Saudi default-off proved by asserting the whole /api/v1/theme/home payload for SA identical with the switch on and off, from a flushed cache. Mutations A-E plus the reviewers' own all killed. %s" % _R127)

CRITERIA.setdefault('F-12', {})[2] = ('MET', "PR 127 (2976ebb2): StorefrontThemeSharedIdentityTest::test_a_storefront_that_overrides_nothing_is_given_the_shared_identity_and_a_change_of_it_reaches_the_storefront_whatever_it_had_cached and ::test_a_change_of_the_shared_identity_reaches_the_keys_a_storefront_inherits_and_leaves_the_key_it_overrides_alone, with ::test_the_identity_another_storefront_inherits_is_that_of_whichever_theme_the_default_storefront_has_activated, ::test_an_edit_of_the_default_storefronts_theme_that_is_not_published_reaches_no_other_storefront and ::test_a_storefront_has_no_identity_to_inherit_while_the_default_storefront_has_nothing_published. Writing the F-12 pin exposed a worse defect: ThemeManager::activate, handed a Theme loaded while that theme was still active, found nothing dirty after the deactivation statement had already cleared its row, so save() wrote nothing and BOTH of the default storefront's themes were left is_active = 0 \u2014 the storefront with no active theme, other storefronts inheriting nothing. Fixed with a refresh() before setting the flag, written that way because ActiveThemeReadersTest (F-01.2) forbids a statement outside StorefrontThemeResolver naming Theme with is_active true: the guard was not weakened, it rejected the first attempt. Removing the refresh() fails 4 tests. StorefrontThemeActivationTest pins that a theme switched back to stays active and that activating one deactivates the other themes of its own storefront only. %s OPEN OWNER DECISION, recorded as decision 7 in docs/gcc/F-01-STOREFRONT-THEME.md: the inheritance design itself is unconfirmed \u2014 the theme settings screen posts every field, so one save turns every inherited value into an override and the storefront silently stops following the default one, and no admin action deactivates a theme, so a storefront that took its own cannot go back." % _R127)

CRITERIA['F-12'][3] = ('MET', "PR 127 (2976ebb2): StorefrontThemeSharedIdentityTest::test_an_override_of_one_key_changes_that_key_of_that_storefront_and_nothing_of_any_other_and_the_keys_it_does_not_set_are_inherited, ::test_removing_the_override_returns_the_storefront_to_the_shared_identity_whatever_it_had_cached, ::test_the_logo_of_the_shared_identity_is_the_logo_of_a_storefront_until_it_sets_its_own_and_the_size_it_does_not_set_stays_the_shared_one and ::test_a_draft_of_the_override_is_resolved_as_the_storefront_will_be_given_it; with the switch off, ::test_while_themes_are_not_kept_by_storefront_a_version_is_its_own_settings_over_the_code_defaults_and_nothing_is_inherited. A mutation making the identity read the newest version instead of the published one is killed. Also fixed here: this branch had declared the cache key commerce_segment_ctx_* global, on the grounds that its metrics count every order whichever storefront took it \u2014 but one of them is total_spent, a sum of order_amount across the currencies the orders were taken in, riyals, dirhams and dinars added into one unitless number an admin segment rule compares against a threshold; the declaration is corrected. THE MONEY DEFECT ITSELF IS NOT FIXED and is an owner item: SegmentResolver still sums total_spent across currencies, and fixing it changes what existing admin segment rules match. %s" % _R127)

CRITERIA.setdefault('K-16', {})[4] = ('MET', "lavkooappseller PR 16 (draft, base main at d556fb4, head 7538ad9b, TESTS ONLY plus CI steps \u2014 no lib/ change, no app version change): six flow files under test/flows drive controllers, services, repositories and the app's own Dio client through a scripted HTTP adapter for orders, products, stock, withdrawals and reviews \u2014 174 flow tests, up from 164, with all 7 skips removed. They are not fixture-echo tests: each asserts the request the app really builds (method, path, exact body or query map, headers) and the encodings that live in app code rather than in the fixture (active -> [1], denied -> '2', a date -> '11/01/2026 09:30:00 AM', a removal -> a negative delta); the most important test of each of the six files was mutation-proved by breaking the lib/ code behind it. Two flows now run in Arabic with the request's lang header derived from the locale, where before not one test used the harness's locale support although most sellers in the six markets read the app in Arabic. The CI steps were TIGHTENED, not relaxed: the six named flow steps used if: always(), which also ran them after a run was cancelled, and are now if: ${{ !cancelled() }}; the YAML was parsed to confirm all six paths exist, none carries continue-on-error and the Flutter pin 3.47.0 is unchanged. %s TWELVE DEFECTS ARE PINNED AND NONE IS FIXED \u2014 a characterisation proves what the app does, not that it is right: a refusal inside a 200 with success: 0 read as success, so the seller is told 'Updated Successfully' over an order that never moved; a refused withdrawal leaving the typed values in inputValueList, so correcting an account number re-sends the old one (money); RestockController.getRestockProductList having no else and its only _isLoading = false inside the 200 branch, so a failure spins forever; ApiErrorHandler.getMessage's default: returning the object, so a 422 reaches the screen as Instance of 'ErrorResponse'; updateBalance reading .error off an ErrorResponse and throwing NoSuchMethodError on a status ApiErrorHandler does not name; searchProductReviewList dropping every filter the seller applied; setUpOrder sending a 202 down the else branch with error == null, destroying the server's reason; the re-read after an accepted status change hard-coding status 'all'; two screens showing the raw key no_internet_connection; and StockAdjustSheet's number row overflowing at 400 logical pixels. NOT covered: 403 and 404 with a non-JSON body would throw in both branches, and the screens themselves are mostly not built." % _R16)


# ---- Wave 6 CI read job by job, PRs 128 and 129 (2026-11-19, evening) ----

_R128 = ("CI run id 44545336093 (pull_request, head 2629b9f1), read job by job on 2026-11-19: 24 of 25 jobs success. "
         "The whole suite 'Tests, SQLite' 7337 tests / 797753 assertions, 106 skipped, on PHP 8.3 and on PHP 8.2; "
         "'Storefront tests' 941 / 4660 on MySQL 8 and on MariaDB 11.4.2; strict-mode MariaDB 11.4.2 4121 / 35792; "
         "address 1045 / 4956 on both engines; fulfillment and security 1703 / 8897 (MySQL) and 1703 / 8900 (MariaDB); "
         "the ten sales-channel and cost shards green on both engines. The job that matters most to the review is the NEW one: "
         "'Wallet tests' 100 tests / 322 assertions on MySQL 8 AND on MariaDB 11.4.2 \u2014 the directory the programme's own M2-15 guard "
         "named as running on no real engine now has its own group in the engine matrix, so the reviewers' MEDIUM finding is closed by CI "
         "rather than by assertion. The single red is 'Deployment overlay (artifact, deploys nothing)', red by design and not this branch's: "
         "its step refuses because firebase-messaging-sw.js, public/firebase-messaging-sw.js and robots.txt differ between the deploy baseline "
         "698aa1e and the BASE branch gcc/p1-storefront-lifecycle; git diff --name-only origin/gcc/p1-storefront-lifecycle...2629b9f1 names none "
         "of the three.")

_R129 = ("CI run id 44546598026 (pull_request, head 7ca87a9f), read job by job on 2026-11-19: 22 of 23 jobs success. "
         "'Storefront tests' 897 tests / 3602 assertions on MySQL 8 AND on MariaDB 11.4.2 \u2014 the pull request itself had claimed MariaDB 10.11 "
         "only and named CI as the authority for the other two engines, so this is the reading it asked for, and both agree. "
         "The whole suite 'Tests, SQLite' 7186 / 796372, 99 skipped, on PHP 8.3 and on PHP 8.2; strict-mode MariaDB 11.4.2 4021 / 35470; "
         "address 1045 / 4956 on both engines; fulfillment and security 1703 / 8897 (MySQL) and 1703 / 8900 (MariaDB); the ten sales-channel and "
         "cost shards green. One figure of the pull request body is corrected by this reading rather than reconciled quietly: the body reports the "
         "whole suite at 796378 assertions and CI reports 796372, a difference of six; CI is the authority for the count. The single red is the "
         "deployment overlay, red by design for three files of the base branch gcc/p1-storefront-six-country-isolation that this head does not touch.")

for _req, _link in [('E-01', {'repo': 'lavkoo', 'pr': 128, 'sha': '2629b9f1'}),
                    ('E-02', {'repo': 'lavkoo', 'pr': 128, 'sha': '2629b9f1'}),
                    ('I1-12', {'repo': 'lavkoo', 'pr': 129, 'sha': '7ca87a9f'})]:
    EXTRA_LINKS[_req] = [l for l in EXTRA_LINKS.get(_req, [])
                         if not (l.get('repo') == _link['repo'] and l.get('pr') == _link['pr'])] + [_link]

CRITERIA.setdefault('I1-12', {})[1] = ('MET', "PR 129 (draft, base gcc/p1-storefront-six-country-isolation at e624e43e, head 7ca87a9f). "
    "usdToDefaultCurrency() TRUNCATES, and the admin coupon edit form posts back what it shows, so a form showing 4.34 for a stored 4.35 wrote 4.34 on an "
    "Update that changed nothing: three edits in a row took three minor units off a coupon. Both coupon forms \u2014 admin and vendor, the product has two and "
    "leaving one would have let a seller's screen keep taking a halala off on every untouched Update \u2014 now use an editing helper that shows the amount that "
    "is STORED, and test_three_edits_in_a_row_take_nothing_from_a_coupon pins it. It is a repair, not a new rounding rule: a stored 2.675 at two decimals is "
    "still cut to 2.67, and the mutation that rounds instead of cutting kills that test. A reviewer HIGH is closed here too: StorefrontCurrency::within() "
    "restored the market and nothing else, so the first amount formatted inside a market re-filled four session currency keys from the overridden setting and "
    "corrupted EVERY conversion afterwards \u2014 measured on the real route, after one view of a Kuwaiti coupon's edit page localToDefaultCurrency(100) answered "
    "325.73 instead of 26.67; within() now snapshots the six keys and restores them in the same finally, and snapshots nothing while the currency switch is off. "
    "%s OWNER SIGN-OFF REQUIRED BEFORE MERGE: this changes two LIVE Saudi screens with every flag off (admin and vendor coupon edit forms now show 4.35 where "
    "they showed 4.34). NOT covered: the same fail-open remains on the coupon quick view and file-exports/coupon-list.blade.php, both pre-dating this branch and "
    "measured at base e624e43e; and 42 editable value= attributes in 28 other files still lose a minor unit on an untouched save." % _R129)

CRITERIA['I1-12'][2] = ('MET', "PR 129 (7ca87a9f): the round-trip matrix runs over min_purchase, the flat discount and max_discount, in two- and "
    "three-decimal currencies, in both coupon forms. StorefrontCouponFormMoneyTest 43 tests / 225 assertions and StorefrontCurrencyTest 34 / 74; the seven coupon "
    "files 135 tests on both engines. Two more reviewer findings are closed with the measurement that found them: with KWD switched off in the currency panel the "
    "new list cell printed 5.64SAR and 'Minimum purchase 39.39SAR' for a coupon holding 1.505/10.505 dinars \u2014 the exact string the branch's own assertion "
    "forbids \u2014 and the list now prints the stored amounts with the trading currency's code, never another storefront's figure, while the edit screen and the "
    "update route refuse a coupon whose storefront has lost the money its amounts were typed in; and an N+1 of 64 reads of the currencies table for twenty rows "
    "of two storefronts (against 10 for two rows of the same two) is now 10 for both pages, asserted as equal counts. A FIFTH reviewer finding is REFUTED with "
    "its measurement rather than accepted: making the list cell follow the form's figure moves the inconsistency instead of removing it, because the quick view "
    "the same row opens prints 1.12SAR and prints it with the storefront switch OFF as well; the suggestion was applied as a mutation and fails. Saudi default-off "
    "proved on four legs, each with its own killing mutation. %s NOT covered: only discount_on_purchase coupons are typed anywhere in the matrix; a platform whose "
    "own currency has three decimals while decimal_point_settings is 2 is uncovered; the vendor form keeps step=\".01\", so a browser refuses a three-decimal "
    "amount there even though the value round-trips, and no server-side test can see that." % _R129)



# ---- Wave 7: lavkooappseller 17, CI read job by job and step by step (2026-11-19, evening) ----

_R17S = ("seller CI run id 44566251838 (head 6becf35b), read job by job and then step by step on 2026-11-19: 'analyze-and-test' red, "
         "'build-android' skipped. The job holds TWO red steps and NEITHER is this branch's, each proved rather than asserted. "
         "(1) 'dart format --output=none --set-exit-if-changed lib/helper lib/theme lib/common/basewidgets/glass tool' reports "
         "'Formatted 24 files (20 changed)' \u2014 and this branch touches NONE of those four directories (git diff --name-only "
         "origin/main..HEAD names only lib/features/auth, lib/features/shop, lib/utill and test/shop_images). Running that exact command "
         "against origin/main's OWN files, extracted with git archive, prints the identical 20-file list and exits 1, so the step is red on "
         "main. (2) 'flutter test' reports 164 passed, 1 failed, the one failure being test/app_version_gate_test.dart 'force-update gate the "
         "shipped version clears the backend minimum, so login is reachable': pubspec 1.0.0 against a backend minimum of 2.0.1. That is red on "
         "main as well, proved on main's own run 43229610276 job 131221083251 (141 passed, 1 failed, the same test), and it is NOT fixed here, "
         "because a seller app version change needs verified release evidence the charter does not grant. The arithmetic closes: 164 - 141 = 23, "
         "and 23 is exactly the number of tests this branch adds \u2014 all 23 appear by name in the run's passing group, 12 from "
         "shop_image_rules_test.dart and 11 from shop_image_source_rules_test.dart. Flutter 3.47.0, the CI pin.")

for _req, _link in [('K-18', {'repo': 'lavkooappseller', 'pr': 17, 'sha': '6becf35b'})]:
    EXTRA_LINKS[_req] = [l for l in EXTRA_LINKS.get(_req, [])
                         if not (l.get('repo') == _link['repo'] and l.get('pr') == _link['pr'])] + [_link]

CRITERIA.setdefault('K-18', {})[1] = ('MET', "lavkooappseller PR 17 (draft, base main at 93e3f63, head 6becf35b, NO app version change, no package id, "
    "bundle id, Firebase setting or import renamed). Two commits, in that order deliberately: the first WRITES DOWN the image rules the two seller forms "
    "state today \u2014 registration asks for four slots and the shop form for four, registration states a 2 MB limit for all four while the shop form "
    "states 1 MB for the logo and the cover, and the two forms still DISAGREE about the shop banner, 3:1 against 4:1, which is recorded as a fact rather "
    "than quietly harmonised \u2014 so the refactor has something to be measured against; the second moves both forms to read every ratio, size and slot "
    "rule from the rules table instead of deciding them from the single global active_theme, which is what the criterion asks. With six storefronts an "
    "image rule that comes from one global theme is a rule no country can differ on; reading from the table is what makes a per-storefront answer "
    "possible, and this does not add one. THREE of the eleven source-rule tests exist to keep the scan honest rather than to assert the feature, which is "
    "the I2-09 lesson applied: the scan is made to SEE a theme read when one is written, to SEE a ratio or a size when one is written, and to LEAVE the "
    "app's own colour theme and names that only look like it alone. A scan that cannot fail is not evidence. %s NOT covered: no full seller-app suite "
    "beyond CI's own run is reported, and the per-storefront answer itself is not implemented \u2014 only the reading of the rule is moved." % _R17S)



# ---- The verifiers' requirements get their real acceptance criteria, batch 1 of 28 (2026-11-19, evening) ----
#
# Each placeholder is replaced by criteria read off origin/main and the branch heads named, not off the card's own evidence note.
# WRITING THESE RAISES THE MISSING COUNT and is meant to: a placeholder counted one criterion where the behaviour needs four or five.

_WRITTEN = ('Its acceptance criteria are written (batch 1 of the 28 requirements the verifiers added). What remains is in the criteria themselves.',
            'See the criteria: the unmet ones name the file and the line that has to change.')

CRITERIA_SPLIT['A2-V1'] = {0: [
    ('Orders, POS sales and refunds of another country are not listed to a country-scoped admin', 'CODED',
     "PRs 77 (81a2836) and 82 (d1fc5f4), behind GCC_STAFF_COUNTRY_SCOPES which ships off: StaffCountryOrderScope on Order, OrderDetail and "
     "OrderTransaction, StaffCountryRefundScope on RefundRequest and RefundTransaction, with ScopeOrdersToStaffCountries as the middleware. CODED "
     "rather than MET because the chain has no CI run read on a head that carries all of it."),
    ('The dashboard, the reports, the exports and the header search of a scoped admin cover only that admin\'s countries', 'CODED',
     "PR 82 (d1fc5f4): DashboardController and AdvanceSearch are scope-aware, and StaffCountryScope, StaffCountryAssignments and StaffOrderRoutes "
     "carry the assignment. Not MET: no run read, and the export paths are not separately pinned."),
    ("The customer's WALLET transaction screen shows a scoped admin only the markets they hold", 'MISSING',
     "Measured on the head of PR 82: `git grep -l StaffCountry -- app` names Product, RefundRequest, RefundTransaction and the three scope classes "
     "(order, product, refund) and NOTHING else. app/Models/WalletTransaction.php carries no scope, so a local admin of one market reads every "
     "market's wallet movements of a customer. This is the half of plan line 355 that the order and refund scopes do not reach."),
    ("The customer's LOYALTY transaction screen shows a scoped admin only the markets they hold", 'MISSING',
     "The same measurement: app/Models/LoyaltyPointTransaction.php carries no StaffCountry scope on any open branch. E-02 keeps points global "
     "(one users.loyalty_point), so there is also no market on the row to scope BY yet \u2014 this criterion depends on E-02's account key."),
]}
WRITTEN_CRITERIA['A2-V1'] = _WRITTEN

CRITERIA_SPLIT['D2-V1'] = {0: [
    ('A payment that starts on a storefront is quoted in that storefront\'s currency', 'CODED',
     "GCC-05 slice 3 on gcc/p1-storefront-currency: GatewayCurrencyPolicy with GatewayCurrencyPolicyTest. CODED, not MET: at the time the card was "
     "written the slice had no pull request and no CI; PR 87 now carries the branch."),
    ('A gateway that does not support the storefront\'s currency is REFUSED, not quietly given another currency', 'MISSING',
     "On origin/main, PaymentGatewayTrait::getPaymentGatewayCurrencyCode substitutes instead of refusing, and the substitution has four branches: the "
     "single supported currency, a paystack-specific GHS/NGN lookup, `Currency::whereIn($currencyCodes)->where(status 1)->first()` \u2014 the FIRST "
     "active currency of the gateway's list, in table order \u2014 and then a bare `return 'USD';` as the function's last statement. A Kuwaiti "
     "checkout whose gateway does not list KWD is therefore charged in whatever that first row is."),
    ('No payment path falls back to USD or to the first active currency', 'MISSING',
     "The literal `return 'USD';` is still the last line of getPaymentGatewayCurrencyCode on origin/main. Nothing on any open branch deletes it; the "
     "policy class refuses alongside it rather than replacing it, so the fallback stays reachable from any caller that does not ask the policy."),
    ('Amounts and fixed decimals follow the storefront\'s currency through the whole payment', 'MISSING',
     "GCC-05 slice 4 (prices, amounts, fixed decimals) is not done. The three-decimal currencies KWD, BHD and OMR are the cases that fail first, and "
     "the coupon work of PR 129 shows the shape of the defect this leaves: a truncating conversion that writes back what it displayed."),
]}
WRITTEN_CRITERIA['D2-V1'] = _WRITTEN

CRITERIA_SPLIT['E-V1'] = {0: [
    ('One credit per payment: a replayed gateway callback credits the wallet once', 'MISSING',
     "add_fund_to_wallet_success (app/Utils/module-helper.php) credits whenever `$payment_data['is_paid'] == 1` and holds no guard of its own, so a "
     "callback delivered twice calls CustomerManager::create_wallet_transaction twice. The two units point at each other on this \u2014 D2's cross "
     "note hands the gateway top-up hook to E, E's hands the replay guard to D2 \u2014 which is how it ended up with no owner."),
    ('The ledger row names the payment that produced it', 'MISSING',
     "create_wallet_transaction records `payment_method` alone, from `$payment_data['payment_method']`, and generates its own `transaction_id` with "
     "Str::uuid(). The gateway's own payment id is passed into the function and never stored, so a wallet credit cannot be traced back to the payment "
     "that paid for it. The column that would hold it is E-05's."),
    ('The add-fund bonus is applied once', 'MISSING',
     "The bonus is computed inside create_wallet_transaction for transaction_type 'add_fund' (admin_bonus = Helpers::add_fund_to_wallet_bonus). It is "
     "once PER ROW, which is not the same as once per payment: with no replay guard above it, a second callback writes a second row and a second "
     "bonus. PR 128's reconciler already has to count admin_bonus in the wallet net, which is how the column's weight was noticed."),
    ('A conversion credits what was paid', 'MISSING',
     "The amount is converted twice before it is credited: Convert::usdPaymentModule(amount, currency_code) to USD, then usdToDefaultCurrency() to the "
     "PLATFORM's default currency. usdToDefaultCurrency is the same TRUNCATING function PR 129 found cutting a minor unit off a coupon, so a top-up "
     "can credit less than was charged, and it converts to the platform's money rather than the market's."),
    ('The credit goes to the wallet of the market where it was made', 'MISSING',
     "create_wallet_transaction takes ($user_id, $amount, $transaction_type, $reference, $payment_data) and no market. PR 128 adds the dormant "
     "customer_market_wallet_accounts table and CustomerMarketBalances behind GCC_MARKET_ACCOUNTS, but nothing routes the top-up through it, which "
     "that pull request states of itself."),
]}
WRITTEN_CRITERIA['E-V1'] = _WRITTEN

CRITERIA_SPLIT['K-V5'] = {0: [
    ('The published per-platform status of the version gate is parsed from the configuration', 'MET',
     "lib/features/splash/domain/models/config_model.dart: class PlatformVersion carries `final int? status` and PlatformVersion.fromJson reads "
     "`int.tryParse(json['status']?.toString() ?? '0') ?? 0`. The switch the server sends already reaches the app."),
    ('The splash version gate consults that status before it refuses entry', 'MISSING',
     "lib/features/splash/screens/splash_screen.dart reads `appVersion?.forAndroid?.version` and `appVersion?.forIos?.version` and then gates on "
     "`AppVersion.isUpdateRequired(minimumVersion)`. The parsed `status` is named nowhere on that path, so the gate cannot be switched off from the "
     "server. The pattern that works is on the NEXT branch of the same if-chain: maintenance mode is gated on "
     "`config?.maintenanceModeData?.maintenanceStatus == 1`, so the app already honours a server switch \u2014 the version gate alone does not."),
    ('With the gate switched off, a seller whose shipped version is below the minimum still reaches the login form', 'MISSING',
     "This is the failure the repository's own red test describes: test/app_version_gate_test.dart fails with 'pubspec version 1.0.0 is older than the "
     "backend minimum 2.0.1 \u2014 the splash screen would show UpdateScreen and no seller could reach the login form', red on main's own run "
     "43229610276. A wrong minimum therefore locks out every seller with no way back that does not need a release."),
    ('Switching the gate off needs no app release', 'MISSING',
     "It follows from the criterion above and is listed separately because it is the point of the requirement (Discovery E19-F06, P1; GCC-20 item 8). "
     "No code or test change is proposed here: the charter forbids a seller app version change without verified release evidence, and this requirement "
     "is about the gate's OFF switch, not about the version number."),
]}
WRITTEN_CRITERIA['K-V5'] = _WRITTEN



# ---- The verifiers' requirements, batch 2 of 28 (2026-11-19, evening) ----

CRITERIA_SPLIT['A1-V2'] = {0: [
    ('The SEO surfaces exist at all', 'MET',
     "They do, on origin/main, and the card's note that there is 'no code anywhere' is about the GCC DIFF rather than about main: "
     "app/Services/Seo holds SeoTagRenderer, SitemapHreflangGenerator and StructuredDataBuilder, and ApplySeoRedirects is the admin-managed "
     "301/302 middleware (all marked 'Phase 1.6'). So this requirement is not 'build SEO', it is 'give the SEO that exists a market dimension', "
     "which is a much smaller piece of work than the placeholder implied."),
    ('The canonical URL and the hreflang set are scoped by MARKET, not only by language', 'MISSING',
     "SitemapHreflangGenerator is scoped by LANGUAGE: its own docblock says it exists so that 'the Arabic and English versions of a page' are "
     "grouped rather than competing. There is a trap here worth naming, because it makes the gap look closed when it is not: line 86 records that "
     "the platform stores 'country-ish codes such as \"sa\"/\"sy\"' which are passed through as hreflang REGION tags, so an existing `ar-SA` "
     "alternate is a language tag and not a market. Six markets selling different catalogues at different prices need a canonical per market."),
    ('The sitemap, robots and SEO redirects answer per market', 'MISSING',
     "ApplySeoRedirects resolves its rules globally. The word 'storefront' throughout app/Services/Seo means THE SHOP FRONT-END AS OPPOSED TO THE "
     "ADMIN PANEL \u2014 'panel and API/mobile prefixes are excluded' \u2014 and not one of the six GCC markets; the same word carries both "
     "meanings in this codebase and reading it the wrong way is how SEO gets recorded as market-aware when it is not."),
    ('A market that is not selling is not advertised to search engines', 'MISSING',
     "Follows from the three above and is listed on its own because it is the customer-visible harm: with the storefront lifecycle of PR 111 a "
     "market can be closed, and nothing in the SEO path reads that state, so a closed market's pages stay canonical and stay in the sitemap."),
]}
WRITTEN_CRITERIA['A1-V2'] = _WRITTEN

CRITERIA_SPLIT['D2-V2'] = {0: [
    ('Cash on delivery can be switched off for one market without the others', 'MISSING',
     "Read on origin/main: Admin/ThirdParty/PaymentMethodController passes `getWebConfig(name: 'cash_on_delivery')` to the view and "
     "RestAPI/v1/ConfigController answers `(boolean)getWebConfig(name: 'cash_on_delivery')['status']` to the apps. Both are platform-wide business "
     "settings read with no storefront argument, so switching it off anywhere switches it off in all six markets."),
    ('Digital payment can be switched off for one market without the others', 'MISSING',
     "The same two readers, the same line: `getWebConfig(name: 'digital_payment')` in the admin view and in the API config answer. No storefront "
     "dimension on either."),
    ('Offline payment can be switched off for one market without the others', 'MISSING',
     "`getWebConfig(name: 'offline_payment')` in Admin/ThirdParty/PaymentMethodController. Offline payment matters more than the other two here, "
     "because which offline methods are lawful differs by country \u2014 this is the one of the three with a legal reading behind it."),
    ('Wallet top-up limits are per market', 'MISSING',
     "No storefront-level switch exists in app/Services/Storefront or config/gcc.php on the integration head. It is recorded with the three above "
     "because the plan's line groups them, and it depends on E-01: a top-up limit per market needs a wallet per market first."),
]}
WRITTEN_CRITERIA['D2-V2'] = _WRITTEN

CRITERIA_SPLIT['J-V2'] = {0: [
    ("The customer app's points ledger belongs to the storefront the customer is on", 'CODED',
     "Sharper than the card's note, which says no J requirement covers the points: on lavkooapp PR 20's branch "
     "(gcc/p1-customer-order-currency) lib/features/loyaltyPoint/controllers/loyalty_point_controller.dart IS storefront-aware \u2014 it imports "
     "StorefrontChangeNotifier and StorefrontScope, calls followStorefrontChanges() in its constructor and reads StorefrontScope.current when it "
     "loads. It even avoids a wasted request: a storefront change reloads the points only for a customer who had asked for them. CODED rather than "
     "MET because the behaviour has no named test of its own in the run that was read."),
    ('Points are earned in the storefront the order was placed in, at that storefront\'s rate', 'MISSING',
     "The app can only show what the backend holds, and the backend holds one global users.loyalty_point with one platform-wide "
     "loyalty_point_exchange_rate (read inside CustomerManager::create_wallet_transaction). This criterion is E-02's to satisfy first; the app side "
     "cannot be proved until there is a per-market balance to show."),
    ('Point-to-wallet conversion credits the wallet of the same storefront, at that storefront\'s rate', 'MISSING',
     "PointsToWalletConversion (PR 10) converts inside one global account, which E-02 records. Nothing in the app or the backend names a market on "
     "either side of the conversion."),
    ('No points move between storefronts by themselves', 'MISSING',
     "With a single global balance there is nothing to move BETWEEN: the same number is shown in every market, which is the defect this criterion "
     "exists to forbid, and it will only become assertable once the per-market account of E-02 exists."),
]}
WRITTEN_CRITERIA['J-V2'] = _WRITTEN

CRITERIA_SPLIT['K-V4'] = {0: [
    ('Pausing one market does not replace the whole seller app', 'MISSING',
     "Read in lib/features/splash/screens/splash_screen.dart on the seller app: the only maintenance branch is "
     "`config?.maintenanceModeData?.maintenanceStatus == 1 && config?.maintenanceModeData?.selectedMaintenanceSystem?.vendorApp == 1`, and it "
     "pushReplacement()es MaintenanceScreen for EVERY seller. One global vendor_app switch, no market dimension, and a seller who trades in five "
     "other markets loses the app entirely."),
    ('Order handling of the markets that are not paused keeps working', 'MISSING',
     "MaintenanceScreen replaces the navigation stack, so no order screen of any market is reachable while the switch is on. There is no "
     "per-market state in the app to branch on."),
    ('Refunds and payouts of the markets that are not paused keep working', 'MISSING',
     "The same single screen. Payouts are the sharper half: a payout is money owed to the seller, and a global pause stops a seller collecting "
     "money earned in a market that was never paused."),
    ('The seller is told WHICH market is paused', 'MISSING',
     "MaintenanceScreen carries no market. The cross-axis note says K-04 and K-11 'need a paused-market state in the app' and no requirement or "
     "criterion carried it, which is why the verifier added this one."),
]}
WRITTEN_CRITERIA['K-V4'] = _WRITTEN



# ---- The verifiers' requirements, batch 3 of 28 (2026-11-19, evening) ----

CRITERIA_SPLIT['M1-V2'] = {0: [
    ('The backend repository refuses a pull request that adds a secret or personal data', 'MET',
     "origin/main .github/workflows/ci.yml has the job `leak-guard`, named 'No secret or personal data added', which runs "
     "`php scripts/ci/leak-guard.php --base=\"${{ github.event.pull_request.base.sha || github.event.before || 'origin/main' }}\"` \u2014 so it "
     "scans the pull request's own range, and a push to a branch is scanned against the commit before it. The scanner is on main "
     "(scripts/ci/leak-guard.php) and has its own test, tests/Unit/Deploy/LeakGuardTest.php."),
    ('The SELLER app repository refuses one too', 'MISSING',
     "lavkooappseller origin/main .github/workflows/ci.yml holds two jobs, `analyze-and-test` (install, verify formatting of changed Dart files, "
     "analyze, check translation keys, test) and `build-android`. Searching that workflow for leak, secret, gitleaks or trufflehog returns ZERO "
     "matches. A key committed to the seller app reaches main unchallenged."),
    ('The CUSTOMER app repository refuses one too', 'MISSING',
     "lavkooapp origin/main has NO .github directory at all \u2014 `git ls-tree -r --name-only origin/main | grep -c '^.github/'` is 0, so the "
     "repository has no CI of any kind on its default branch. Some gcc/* branches carry a workflow; the branch that would have to enforce it does "
     "not. This is the weakest of the three and the one a reader would most easily assume was covered by the other two."),
    ('What happens on detection is written down', 'MISSING',
     "SEC-E21-04 step 6 asks for 'a defined action on detection', not only a scan. The backend job fails the run, which is an action, but nothing "
     "records who is told, whether the key is treated as live, or that DR-1 defers ROTATION and not scanning \u2014 a distinction worth keeping, "
     "because a scan that finds a key the owner then cannot rotate is a half-closed door."),
]}
WRITTEN_CRITERIA['M1-V2'] = _WRITTEN

CRITERIA_SPLIT['M2-V6'] = {0: [
    ('Adding the country marker leaves every current_stock unchanged', 'MISSING',
     "M2-17 reconciles wallet, loyalty, order and payment totals only. No reconciler or test in any of the 94 origin branches compares stock "
     "balances before and after the marker; #24 characterises behaviour and #17 and #18 fix single paths."),
    ('Holds and the outbox reconcile against the movements that made them', 'MISSING',
     "Discovery-01 report 07 section 3.5 specifies the stock, reservation and outbox reconciliation and nothing implements it. This is the half "
     "that matters most on a live platform: a hold that survives its order, or an outbox row with no movement behind it, is stock a customer "
     "cannot buy and nobody is looking for."),
    ('Provider stock reconciles against the platform\'s own balance', 'MISSING',
     "H2-V1's machinery (InventoryLocationBalance, the shared-provider pool) is the thing to reconcile against, and no reconciler reads it."),
    ('Prices of existing products are unchanged by the marker', 'MISSING',
     "Named in the same plan line as the stock balances and uncovered by the same gap. It is the cheapest of the four to prove and the one whose "
     "failure a customer would see first."),
]}
WRITTEN_CRITERIA['M2-V6'] = _WRITTEN

CRITERIA_SPLIT['H2-V1'] = {0: [
    ("A provider's shelf reaches a sales channel only by an explicit per-location admission", 'MET',
     "On origin/main: InventoryLocation carries `provider` and `publishable_channels`, InventoryLocationService::ensureDefaults does NOT admit "
     "noon, and StockListingGate refuses with 'location_not_for_this_channel'. Pinned by NoonPoolMembershipTest and SharedProviderStockTest. "
     "(Path correction: the service is app/Services/SalesChannels/Inventory/InventoryLocationService.php, and the gate "
     "app/Services/SalesChannels/Publishing/StockListingGate.php \u2014 the card's note gives a shorter path that does not exist.)"),
    ('A channel order reaches a provider only through a recorded routing decision', 'MET',
     "DynamicFulfillmentRoutingEngine (app/Services/SalesChannels/Routing/) and DispatchGate (app/Services/SalesChannels/Dispatch/) on main, with "
     "RoutingEngineTest::test_a_rule_that_names_a_provider_which_does_not_hold_the_goods_does_not_send_them_there. The channel cannot route to a "
     "provider by itself, which is the second clause of plan line 258."),
    ('The two concepts stay separate as more channels and providers are added', 'MET',
     "The separation is structural rather than conventional: a channel is admitted to a LOCATION, and a location belongs to a provider, so a new "
     "channel is admitted nowhere until someone admits it, and a new provider offers its shelf to nothing. This is why the requirement reads MET "
     "on main with no GCC work at all \u2014 one of the two the verifier's placeholder was already satisfying before anyone looked."),
]}
WRITTEN_CRITERIA['H2-V1'] = _WRITTEN

# K-V3's first criterion is ALREADY MET on lavkooappseller #8's head 7c20717, with CI run 28 read per step; an earlier draft of this split
# downgraded it to CODED after reading the OLDER branch gcc/p1-seller-outbox-owner instead of that head. The evidence is reused verbatim rather
# than rewritten, and the two criteria added beneath it are the ones the requirement's own update text says are unwritten.
CRITERIA_SPLIT['K-V3'] = {0: [
    ('A queued POS sale that has failed its replays is never dropped silently: it stays visible with its last error until the seller removes it',
     'MET', CRITERIA['K-V3'][0][1]),
    ('How long a sale may wait before the app gives up on it', 'MISSING',
     "The limit is ten attempts (`_maxAttempts` in offline_sales_controller.dart), a number that is in the code rather than chosen: nothing records "
     "why ten, and on origin/main reaching it DELETED the sale \u2014 `if (retried.attempts < _maxAttempts) remaining.add(retried);` on the "
     "response path and again in the catch, then `_pending = remaining; await _persist();` wrote the shorter list. #8 stops the deletion; it does "
     "not answer how long a sale that represents money may sit unsent, which is the owner's to set."),
    ('Who may remove a failed sale', 'MISSING',
     "#8 lets the SELLER remove one after an explicit yes, which is what the app does; whether a seller should be able to remove a record of money "
     "taken on a shared device, or whether that belongs to a manager or to the platform, is the finance owner's decision and is recorded as the "
     "requirement's next task rather than settled here."),
]}
WRITTEN_CRITERIA['K-V3'] = _WRITTEN



# ---- The verifiers' requirements, batch 4 of 28 (2026-11-19, evening) ----

CRITERIA_SPLIT['G-V1'] = {0: [
    ('Four of the eight layers are written down: authorization, API, queries, exports and reports', 'CODED',
     "docs/gcc/GCC-14C-STAFF-COUNTRY-SCOPES.md on the integration branch is 984 lines and carries the switch, who is restricted, the queries of "
     "slices 1 to 3, and exports and reports inside those slices, with the platform API as a consumer of the staff scope. CODED, not MET: the "
     "document is a design, and the requirement asks that each layer name the slice, owner decision or requirement that builds it."),
    ('Jobs have a rule', 'MISSING',
     "The document's only statement on jobs is ONE sentence, line 32: a queue worker and a console command start unbound. That is the problem "
     "stated, not a rule \u2014 and it is the layer where an unbound worker silently crosses every market boundary the other seven layers hold."),
    ('Sensitive actions and audit logs have a rule', 'MISSING',
     "Searching the 984 lines for audit, sensitive, step-up and approval finds no section for any of them. These are the two layers a reviewer "
     "will ask about first, because they are what a country regulator asks about first."),
    ('The design is where the people who need it can find it', 'MISSING',
     "The document exists only on the integration branch: `git show origin/main:docs/gcc/GCC-14C-STAFF-COUNTRY-SCOPES.md` is empty, so it is not "
     "on main. A data-isolation design that lives on an unmerged branch is not yet the platform's design."),
]}
WRITTEN_CRITERIA['G-V1'] = _WRITTEN

CRITERIA_SPLIT['G-V2'] = {0: [
    ('The admin guard still protects every admin route when scope code is added', 'MET',
     "AdminRouteAuthenticationTest on main, and AdminMiddleware opens with `Auth::guard('admin')->check()`."),
    ('The module permission still runs BEFORE any scope middleware', 'MET',
     "PR 74: StaffProductIdRoutesTest::test_the_middleware_of_the_scope_runs_after_the_permission_of_the_route_on_every_covered_route. The order "
     "matters and not only the presence: a scope that ran first would answer 'not your country' to someone who should have been told 'not your "
     "module', which leaks which countries exist."),
    ('The admin sign-in throttle stays in force', 'MISSING',
     "It EXISTS \u2014 routes/admin/routes.php line 174 is "
     "`Route::group(['prefix' => 'login', 'middleware' => ['throttle:auth']], ...)` \u2014 and it is pinned by NO test on main, #75 or #83. The "
     "requirement is that the scope work does not disturb it, and nothing would currently notice if it did. This is the cheapest criterion in the "
     "whole batch to close: one test that asserts the login group carries throttle:auth."),
    ("The status check and its admin-1 exemption stay in force", 'MISSING',
     "AdminMiddleware lines 21-22 log out a signed-in admin whose `status != 1` while exempting `id() == 1`, so the first admin cannot lock "
     "themselves out. Unpinned on main, #75 and #83 alike: a scope change that reordered this middleware could switch the exemption off and no "
     "test would say so."),
]}
WRITTEN_CRITERIA['G-V2'] = _WRITTEN

CRITERIA_SPLIT['M2-V2'] = {0: [
    ('A change reaches the server by the overlay path, not by hand', 'CODED',
     "The rule is carried by tooling and runbooks rather than by a guard: tests/Unit/Deploy/OverlayDeploymentTest.php and "
     "tests/Unit/Deploy/BackupScriptsTest.php are on main, with the overlay build, the apply step and the drift check, and the runbooks say never "
     "a bare `php artisan migrate`. CODED because that is a practice a person follows, not a thing the repository refuses."),
    ('No direct SQL settles an architectural problem', 'MISSING',
     "No test forbids it. M2-15 maps plan lines 560-562 and its only criterion on them is 'no CI protection is weakened', which is a different "
     "promise. M1-18 and M1-11 hold the rule in the published tracker as prose."),
    ('Migrations are additive', 'CODED',
     "Every GCC migration read in this programme is expand-only and several refuse their own rollback while data would be lost \u2014 PR 130's "
     "sharing-key migration refuses `down()` once any account carries a key, and PR 128's refuses while any row carries a column it would drop. "
     "CODED rather than MET because it is true of the migrations that were read, and nothing makes it true of the next one somebody writes."),
]}
WRITTEN_CRITERIA['M2-V2'] = _WRITTEN

CRITERIA_SPLIT['M2-V3'] = {0: [
    ('Every table the plan touches is classed global or per market', 'MISSING',
     "Discovery-01 report 03 classifies tables as Global, Customer, Storefront or Legal Entity, and report 07's phases do not restate it, so the "
     "classification exists in one report and is carried into nothing. M2-17 has no criterion for it."),
    ('The class is checked, not just written down', 'MISSING',
     "No test marks which tables are global. This is the criterion that makes the first one worth anything: a classification nothing checks drifts "
     "the first time a column is added."),
    ('What is per market today is per market deliberately', 'CODED',
     "Measured on the integration head: exactly three migrations name `storefront_country` \u2014 products, storefront_legal_acceptances and "
     "admin_country_scopes. Everything else is global BY DEFAULT rather than by decision, `users`, `wallet_balance` and `loyalty_point` included, "
     "which is the same gap E-01, E-02 and A2-V1 each meet from their own side."),
]}
WRITTEN_CRITERIA['M2-V3'] = _WRITTEN



# ---- The verifiers' requirements, batch 5 of 28 (2026-11-19, evening) ----

CRITERIA_SPLIT['A2-V2'] = {0: [
    ('An acceptance is recorded against the TEXT VERSION that was accepted', 'CODED',
     "PR 43 (82b6dfa), migration 2026_11_24_110000_create_storefront_legal_acceptances_table: the row carries `legal_document_id`, "
     "`document_version` AND `document_hash` (char 64). The hash is stronger than the requirement asks for \u2014 a version number can be reused "
     "by an edit, a hash cannot \u2014 so a receipt says exactly which words were agreed to. CODED: a draft, not merged."),
    ('...against the STOREFRONT it was given on', 'CODED',
     "`storefront_country` (char 2) on the same row, with an index on (storefront_country, document_type, locale)."),
    ('...and against the SELLING LEGAL ENTITY', 'CODED',
     "`legal_entity` (string 64, nullable) on the same row. Nullable is right while GCC-13's registry is not merged, and it is also the part a "
     "legal reviewer should look at hardest: a receipt with a null entity cannot say which company the customer contracted with."),
    ('The receipt cannot be changed afterwards', 'CODED',
     "app/Models/StorefrontLegalAcceptance declares `public const UPDATED_AT = null;`, so Eloquent has no updated_at to touch and the row is "
     "append-only by construction. The customer is identified by `customer_id` OR `guest_hash`, and `order_id` links the receipt to the purchase "
     "it was taken for."),
]}
WRITTEN_CRITERIA['A2-V2'] = _WRITTEN

CRITERIA_SPLIT['F-V1'] = {0: [
    ('Every builder mutation acts on a DRAFT and never on a published version', 'MET',
     "Read in app/Services/Theme/ThemeBuilderService.php on origin/main: `isEditable($version)` is the first statement of addSection, "
     "updateSection, setDeliveryRules, setSectionVisibility, reorderSections and duplicateSection, and the class docblock states the intent \u2014 "
     "'the live storefront can never be edited by accident'. This half of the requirement holds on main with no GCC work."),
    ('A mutation acts only on the theme of the STOREFRONT being edited', 'MISSING',
     "The service has NO storefront scope at all: the only occurrence of the word in the whole file is the docblock's 'live storefront', meaning "
     "the shop rather than a market. Every method takes a ThemeVersion or a ThemeSection that the controller has already loaded BY ID, so the "
     "object decides the storefront and nothing checks that it is the editor's. With six markets this is the difference between editing your own "
     "draft and editing Kuwait's."),
    ("Writes into the global banners table stay inside that storefront", 'MISSING',
     "ThemeBannerLink::syncBlock writes into a table shared by every storefront, and the scope that would keep a write inside one market is the "
     "scope the criterion above says does not exist. F-10 covers which banners are DELIVERED; this is about which are WRITTEN."),
    ('The permission to edit is checked as well as the draft status', 'MISSING',
     "PR 66 records that section add, update and delete skip canEdit(). isEditable() asks whether the VERSION may be edited; canEdit() asks "
     "whether this PERSON may edit it, and the two are different questions \u2014 the first is pinned, the second is not."),
]}
WRITTEN_CRITERIA['F-V1'] = _WRITTEN

CRITERIA_SPLIT['I2-V1'] = {0: [
    ('The axis-J legal audit exists, with file evidence', 'CODED',
     "Discovery E06 section 2.6 with finding E06-F01 and its own independent verification log in section 9, risk R-017 in "
     "05-gap-risk-register.md and owner question Q-16, under docs/gcc/audits/2026-11-12-0115-discovery-01 and "
     "docs/gcc/audits/2026-11-12-1240-discovery-02-followup. It covers the data model, the workflow, distribution to web and apps, the link to the "
     "order, the gaps and the owner questions, which is what the requirement asks for."),
    ('It is where the people who need it can find it', 'MISSING',
     "Measured: `docs/gcc/audits` holds **62 files on the docs branch and 0 on origin/main**, and no pull request carries them. The audit that "
     "the legal review will be read against lives on one branch of one account. This is the same shape as G-V1's finding and the two should "
     "probably be closed together."),
    ("The audit's findings are carried into requirements that can be checked", 'CODED',
     "They largely are \u2014 I2-01 cites this audit in its plan_lines and refs, and the legal-store pins of PRs 114, 115, 121 and now 133 are "
     "its descendants. CODED rather than MET because the carrying is by citation: nothing fails when a finding has no requirement."),
]}
WRITTEN_CRITERIA['I2-V1'] = _WRITTEN

CRITERIA_SPLIT['J-V1'] = {0: [
    ('The BACKEND serves a draft from a signed preview token, uncached', 'CODED',
     "ThemeSectionController on the integration head takes `ThemePreviewToken $previews` and documents the contract itself: send `preview`, a "
     "signed token minted in the admin builder, to render an unpublished draft; the answer carries `preview: true` and is never cached; and an "
     "INVALID token is ignored and the published page served, so a stale preview link degrades into an ordinary page rather than an error. The "
     "preview path shares no cache and no validator with the published one. CODED: a draft pull request."),
    ('The CUSTOMER APP can send that token and render the draft', 'MISSING',
     "Measured on lavkooapp: `lib/features/dynamic_theme` contains the word 'preview' ZERO times on origin/main and zero on the customer branches "
     "checked. The backend half is built and has nothing to talk to, so no merchant can check a draft on a real phone \u2014 which is the whole "
     "point of the requirement."),
    ('A preview is per storefront, never cached and never given an ETag', 'MISSING',
     "The backend promises it; nothing asserts it from the app's side, and the app is where a cached preview would actually be shown. Until the "
     "app sends the token this cannot be proved end to end."),
]}
WRITTEN_CRITERIA['J-V1'] = _WRITTEN



# ---- The verifiers' requirements, batch 6 of 28 (2026-11-19, evening) ----

CRITERIA_SPLIT['H2-V2'] = {0: [
    ("Each channel's listing requirements are readable per market", 'CODED',
     "DescribesListingRequirements (app/Services/SalesChannels/Contracts/) reads each channel's requirements, and Trendyol's "
     "ResolvesChannelBrands plus ListingDraftService resolve brands per account. The shape is there; what it is missing is the mapping below."),
    ("The store's categories map to each channel's categories per (channel, market)", 'MISSING',
     "Measured on origin/main across app and database: `category_map`, `channel_categor`, `category_mapping` and `marketplace_categor` each "
     "return ZERO files. There is no mapping table and no mapping service, so a category is matched by hand on every draft."),
    ("The store's brands map to each channel's brand ids per (channel, market)", 'MISSING',
     "Brand resolution exists per ACCOUNT (Trendyol's ResolvesChannelBrands) and not per (channel, market), which is the pair the requirement "
     "asks for: the same brand can carry different ids in a marketplace's Saudi and Emirati catalogues."),
    ('Product type is chosen from the channel\'s own list, not typed per draft', 'MISSING',
     "The draft takes a product_type TYPED per draft. With six markets that is six chances to mistype the same product, and a rejected listing "
     "is the kind of failure a seller sees before the platform does."),
]}
WRITTEN_CRITERIA['H2-V2'] = _WRITTEN

CRITERIA_SPLIT['K-V1'] = {0: [
    ('The seller v3 answers the four unread pull requests change are compared against the app', 'MISSING',
     "The 2026-11-17 audit's section 0 lists only #44, #51, #52, #58, #61 and #63. Measured by git diff against each merge-base, FOUR more change "
     "seller v3: #20 (v3 OrderController, POSController and RefundController \u2014 order and refund lookups scoped to the seller), #32 (v3 "
     "SellerController, 65 lines, and the withdraw handling), #29 (v3 OrderController, the referral reward in the order status path) and #13 (v3 "
     "POSController, POS wallet atomicity). None of the four appears anywhere in assess-K.json."),
    ('The staged hotfix line is compared too', 'MISSING',
     "PRs 78, 81 and 86 each touch 21 seller v3 files and are outside the audit as well. A hotfix line is exactly where an answer changes shape "
     "without anybody re-reading the app that consumes it."),
    ('A change to a seller v3 answer cannot pass unnoticed by the app', 'MISSING',
     "This is the criterion that would stop the requirement recurring: today the comparison is an audit someone runs, so a pull request opened "
     "after the audit is unexamined by construction \u2014 which is how these four were missed, and how the next four will be."),
]}
WRITTEN_CRITERIA['K-V1'] = _WRITTEN

CRITERIA_SPLIT['K-V2'] = {0: [
    ('Payout and bank details are owner-only', 'MET',
     "routes/rest_api/v3/seller.php on origin/main: the payout-method add, update and updateDefault routes sit inside "
     "`Route::middleware('seller_owner')->group(...)`. Owner-only is in force today."),
    ('Account deletion and verification are owner-only', 'MET',
     "The same file: `account-delete` (GET and DELETE) is inside a seller_owner group, and SellerVerificationController's index, submit and "
     "document routes are inside another."),
    ('Those actions are scoped by MARKET as well as by owner', 'MISSING',
     "None of the three seller_owner groups takes a storefront, and no market dimension exists on any of them. An owner who sells in six markets "
     "has one set of bank details and one verification for all six, which is the opposite of what six legal entities need \u2014 and payout "
     "details are money."),
    ('Only the platform owner sees every market', 'MISSING',
     "K-09's four criteria cover authorization, X-Storefront, per-market permissions and exports, reports, jobs and audit logs, and none of them "
     "says who sees ALL markets. This is the criterion a platform-admin review will ask for first, and GCC-20's super-admin mode depends on it."),
]}
WRITTEN_CRITERIA['K-V2'] = _WRITTEN

CRITERIA_SPLIT['M1-V1'] = {0: [
    ('Each P0 of the security triage is closed by a named pull request with a test, or accepted by the owner', 'MISSING',
     "Measured on the triage itself (docs/gcc/audits/2026-11-12-1240-discovery-02-followup/01-security-triage-public.md): it carries **222 SEC "
     "rows and every one of them reads OPEN** \u2014 no row reads CLOSED and none reads ACCEPTED. The 23 explicit P0s are SEC-E04-01..06, "
     "E05-01..03, E14-01..04, 08, 09, E09-01, E10-01, 02, E16-01, E17-01, E19-01, 02 and E20-04, plus 4 conditional."),
    ('The mapping from SEC id to the thing that closed it exists', 'MISSING',
     "Fixes DO exist for several whole classes \u2014 stage 1 identity, stage 2 ownership, stage 3 permissions \u2014 so the gap is not that "
     "nothing was done; it is that the M1 cards map pull requests and stages and NEVER map SEC ids, so no one can say which of the 23 a given "
     "stage closed. That is the piece of work this criterion names, and it is reading rather than coding."),
    ("The owner's accepted risks are recorded as accepted", 'MISSING',
     "DEFERRED_RISKS.md covers five rows (DR-1 to DR-5). Whatever of the 23 the owner decides to accept rather than close needs to land there "
     "with a date and a reason, and nothing of the kind is recorded yet."),
    ('The restricted annex is read by someone', 'MISSING',
     "The public triage carries the ids; the DETAILS are in a restricted annex that no tracker unit read, and coverage gaps C, D1, D2, E, H1 and "
     "H2 are open because of it. A P0 whose detail nobody has read cannot honestly be called closed, which is why this is a criterion and not a "
     "note."),
]}
WRITTEN_CRITERIA['M1-V1'] = _WRITTEN



# ---- The verifiers' requirements, batch 7 of 28: the last four (2026-11-19, evening) ----

CRITERIA_SPLIT['A1-V1'] = {0: [
    ('One backend, one customer app and one seller app serve all six markets', 'CODED',
     "One Laravel backend, and both Flutter apps carry the storefront code in the drafts. CODED because it is true of the drafts and of nothing "
     "merged."),
    ('The website serves each market', 'MISSING',
     "The website has no country routes: A1-05's criteria are MISSING. One site that cannot be reached per country is not yet one platform "
     "serving six markets."),
    ('An operator can switch each market on and off separately', 'MISSING',
     "A1-02's criterion is MISSING. PR 111 adds the storefront lifecycle so that a store needs a person to open it; until that merges, no "
     "operator control opens or closes a market."),
    ('The apps learn their markets FROM the backend, not from a list inside themselves', 'MISSING',
     "Measured on lavkooappseller: `MarketScope` appears in 9 files on gcc/p1-seller-outbox-market and in NONE on main, and in that branch "
     "`SellerMarketController` answers `List<MarketScope> get markets => MarketScope.gccMarkets` \u2014 a constant list compiled into the app. "
     "So opening a seventh market, or closing one of the six, needs an app release in both stores, which is the opposite of 'each market "
     "switched on separately'. This is the criterion that makes the requirement worth having and it is the one nobody had written."),
]}
WRITTEN_CRITERIA['A1-V1'] = _WRITTEN

CRITERIA_SPLIT['M2-V1'] = {0: [
    ('Every existing feature carries a class', 'CODED',
     "Discovery-01 report 04 (04-preserve-extend-refactor-new-matrix.md) classifies 220 rows: Preserve 76, Extend 80, Refactor 33, New 20, "
     "Dependent-On-PR 11. The work was done and it is thorough."),
    ('The classes are the six the requirement names', 'MISSING',
     "The report uses FIVE: Preserve, Extend, Refactor, New and Dependent-On-PR. 'Reuse As-Is' and 'Unknown / Requires Verification' are not "
     "separate classes in it. The missing one that matters is **Unknown / Requires Verification**: without it, a feature nobody has checked is "
     "recorded as Preserve, which reads as a decision when it is an absence of one."),
    ('The classification is where the designers can find it', 'MISSING',
     "Report 04 is on the docs branch only and not in main \u2014 the same finding as G-V1 and I2-V1, now for the third time, which is itself "
     "worth the owner's attention: three separate requirements are blocked on the same unmerged documentation."),
    ('A feature is classified BEFORE it is designed', 'MISSING',
     "Nothing enforces the order. M2-16's fifth criterion covers new tables and historical relations only, and the published tracker holds the "
     "rule as M1-18, which is prose."),
]}
WRITTEN_CRITERIA['M2-V1'] = _WRITTEN

CRITERIA_SPLIT['M2-V4'] = {0: [
    ('Each step of the staged upgrade is expand-only', 'CODED',
     "True of every GCC migration read in this programme, and the storefront flag is the example: "
     "`storefront_country CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'SA'` with no index. CODED because it holds for the "
     "migrations that were read and nothing makes it hold for the next one."),
    ('The ALTER time is measured on EVERY engine in use', 'MISSING',
     "docs/gcc/STOREFRONT_FLAG_RUNBOOK.md (on the integration branch) records the measurement and its own gap in the same breath: 'Measured on "
     "MySQL 8.0.46 with 200,000 products (62 columns, 15 indexes): the column was added instantly. **MariaDB 11.4.2 (the pilot's engine) has not "
     "been run with this migration.**' The engine that has NOT been measured is the one the pilot will run on, which is the wrong way round."),
    ('Each step is rehearsed on a disposable copy before it touches live data', 'MISSING',
     "The runbook instructs it \u2014 read `@@version`, `@@lock_wait_timeout` and `@@alter_algorithm`, then rehearse on a staging copy \u2014 "
     "and the rehearsal is blocked: the structure-only schema copy (P0-C) is not available, and charter section 4 forbids touching live data. So "
     "this criterion waits on the owner for a copy, not on an engineer for code."),
    ('No migration is created and run on live data', 'MISSING',
     "Forbidden by charter section 4 and respected by this programme, but nothing records it as checked, which is what the criterion asks. The "
     "runbook's own instruction to read the three server variables before scheduling the ALTER is the shape the check should take."),
]}
WRITTEN_CRITERIA['M2-V4'] = _WRITTEN

CRITERIA_SPLIT['M2-V5'] = {0: [
    ('Digital files are on a private disk, and uploads are hardened', 'CODED',
     "M2-18 names both in done_in_drafts: PR 69 (private disk, shipped OFF) and PR 63 (uploads). CODED, and the 'off' matters \u2014 a private "
     "disk that is switched off is a plan, not a protection."),
    ('Deployment can be rolled back', 'CODED',
     "tests/Unit/Deploy/OverlayDeploymentTest.php on main, with the overlay build, apply and drift check."),
    ('The database can be rolled back', 'MISSING',
     "tests/Unit/Deploy/BackupScriptsTest.php covers the backup scripts, which is not the same as proving a restore. The sharper gap M2-18 "
     "records is that `down()` of the three legal-text migrations is untested \u2014 and a rollback nobody has run is a rollback nobody has."),
    ('All of it is audited for SIX markets, not one', 'MISSING',
     "None of M2-18's six criteria covers storage or rollback at all; the published tracker carries them in M1-09 and M1-11. Six markets means "
     "six legal entities whose digital files and backups may not be allowed to sit in one place \u2014 a legal reading this requirement needs "
     "and does not have."),
]}
WRITTEN_CRITERIA['M2-V5'] = _WRITTEN



# ---- E-02.3: the sharing key, CI read job by job AFTER the MariaDB repair (2026-11-19, evening) ----

_R130 = ("CI run id 44565065667 (pull_request, head 3152b0de), read job by job on 2026-11-19: 24 of 25 jobs success. This is the run that "
         "matters, because the run BEFORE it (44546747284, head b6723a1f) was RED on two jobs and the repair is what this one proves. The "
         "comparison is exact: 'Wallet tests' read `Tests: 114, Assertions: 365, Failures: 2` before and `Tests: 114, Assertions: 365` after "
         "\u2014 the same test count and the same assertion count, with the failures gone \u2014 on MariaDB 11.4.2 AND on MySQL 8; "
         "'Strict-mode tests, MariaDB 11.4.2' read `4135 / 35835, Failures: 2` before and `4135 / 35835` after. Nothing was skipped or deleted "
         "to make them pass. The whole suite 'Tests, SQLite' is 7351 tests / 797796 assertions, 106 skipped, on PHP 8.3 and on PHP 8.2, and "
         "7351 is PR 128's 7337 plus the 14 tests this head adds, EXACTLY \u2014 so the arithmetic claimed on both pull requests now holds in "
         "CI and not only in a local run. 'Storefront tests' 941 / 4660 on both engines. One figure is corrected rather than reconciled "
         "quietly: the local run reported 797802 assertions and CI reports 797796, a difference of six, the same six by which PR 129's local "
         "and CI whole-suite numbers differ; CI is the authority for the count. The single red is 'Deployment overlay (artifact, deploys "
         "nothing)', red by design and not this branch's: `git diff --name-only origin/gcc/p1-market-wallet-loyalty-accounts...3152b0de` names "
         "none of firebase-messaging-sw.js, public/firebase-messaging-sw.js or robots.txt.")

for _req, _link in [('E-02', {'repo': 'lavkoo', 'pr': 130, 'sha': '3152b0de'})]:
    EXTRA_LINKS[_req] = [l for l in EXTRA_LINKS.get(_req, [])
                         if not (l.get('repo') == _link['repo'] and l.get('pr') == _link['pr'])] + [_link]

CRITERIA.setdefault('E-02', {})[3] = ('MET', "PR 130 (draft, base gcc/p1-market-wallet-loyalty-accounts at 2629b9f1, head 3152b0de, expand "
    "only). One nullable column, `sharing_group`, on each of customer_market_wallet_accounts and customer_market_loyalty_accounts, which NO code "
    "anywhere reads or writes. All three halves of the criterion are asserted by MarketAccountSharingKeyTest (14 tests, 43 assertions) and each "
    "fails on its own. THE COLUMN IS THERE and is nullable with no default, so adding it changed no existing row. NOTHING NAMES IT: every PHP, "
    "JavaScript and Vue file of app, config, database, routes, resources, Modules and tests is read, PHP token by token so that the migration's "
    "own prose explanation does not count as a reader while a `->sharing_group` or a `'sharing_group'` does, and only two files may name it \u2014 "
    "the migration and that test. The scan fails for a file that merely WRITES the key as well as one that reads it, because a value written is a "
    "value something later believes, and it fails FROM THE OTHER SIDE too, for a migration or test that stops naming it, so the guard cannot be "
    "left asserting a column nobody adds. AND THE KEY DOES NOTHING: two markets of one customer carrying the SAME key still hold their own "
    "balances, and a market with nothing of its own is still refused the money of a market it is grouped with \u2014 so turning sharing on takes "
    "a code change a person writes and another reviews, which is what the criterion asks, and the scan then fails on that code until the list is "
    "changed deliberately. The rollback drops the column while nothing carries one and REFUSES once a row does, so a key a later slice has begun "
    "to fill is never silently forgotten. WHY A STRING AND NOT A FOREIGN KEY: a foreign key needs a table of groups, and the shape of a group is "
    "exactly what is undecided \u2014 it may be the legal entity, a currency union, or a list the owner writes by hand \u2014 so a nullable free "
    "string decides none of that and is cheap to replace while the column is empty. No index, because an index pays for a read and there is no "
    "reader. %s A DEFECT IN THE TEST ITSELF WAS FOUND BY CI AND IS WORTH RECORDING: the first head was green on a local SQLite run and failed "
    "twice on MariaDB with 'Failed asserting that \\'NULL\\' is null'. The migration was never wrong \u2014 SQLite returns PHP null for a "
    "column with no default and MariaDB returns the bare word NULL, which is what information_schema.COLUMN_DEFAULT holds \u2014 so assertNull "
    "failed a column that is exactly as this criterion asks. The repair reads either answer as 'none' through a named helper and hides no real "
    "default, proved on a real MariaDB rather than by reasoning: a column with no default records as NULL while DEFAULT 'NULL' records as 'NULL' "
    "WITH its quotes, and that mutation and DEFAULT 'grp' both still fail the fixed test. OPEN OWNER DECISION, unchanged by this: whether a "
    "customer's points, and later their money, may be shared across some markets is the owner's and a finance and legal reading's per country. "
    "The column is the room the structure leaves for that answer, not the answer." % _R130)



def main():
    out_dir = sys.argv[1]
    asof = sys.argv[2]
    context = json.load(open(T.MASTER + 'context.json'))
    all_reqs = load_all()
    total = render_main(out_dir, all_reqs, asof, context)
    fails = [(u, r['id'], f) for u, d in all_reqs.items() for r in d['reqs'] for f in r.get('evidence_failures', [])]
    print('requirements:', total['n'], 'criteria:', total['ac'], 'MET', total['met'], 'CODED', total['coded'], 'implemented %.1f%% proven %.1f%% release %.1f%%' % (total['implemented_pct'], total['proven_pct'], total['release_pct']))
    print('evidence failures:', len(fails))


if __name__ == '__main__':
    main()
