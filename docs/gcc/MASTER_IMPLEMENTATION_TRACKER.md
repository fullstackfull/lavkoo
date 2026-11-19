# LAVKOO GCC: master implementation tracker

As of 2026-11-19. Generated from the independent assessment of every requirement of the original multi-country plan (13 axes, A to M) against the state of GitHub, then checked by independent verifiers. It replaces any reading of the pull request list as progress: **a pull request that is open is not a requirement that is done.**

## 1. Read this first

- **Nothing here is merged, deployed or verified in production.** All 115 draft pull requests of `lavkoo`, 18 of `lavkooapp` and 8 of `lavkooappseller` are open; `main` has none of the GCC work. The live server is described by the release documents as running an older baseline (`698aa1e`), which no document proves from outside. No state in this document claims production.
- **A percentage is shown only where it is computed from acceptance criteria that a test could assert** (formulas in section 4). Code completion and release readiness are two different numbers and are never mixed: code can be 90% complete and still 0% released.
- **MET means a named test or CI job on a named head passed.** A limited unit test, a pull request body or a green job that does not cover the behaviour is not proof; the verifiers refuted such claims and the corrections are applied.
- The exposed keys and credentials stay in the register of unremediated risks (`DEFERRED_RISKS.md`, DR-1 to DR-5). Deferring their rotation does not close them.
- Every one of the 19 units was read by an independent verifier that tried to refute its states, tests and evidence; the corrections are applied or shown on the card. Work done after the assessors read the trees (new pull requests, CI results) is added as "Update after the assessment" on the cards it touches.

## 2. Dashboard by axis

| Axis | Requirements | Criteria | MET | CODED | MISSING | Code completion | Proven by tests | Release readiness | Verified |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---|
| [A. Six-country base and customer identity](tracker/axis-a-six-country-base-and-customer-identity.md) | 37 | 161 | 87 | 19 | 55 | 66% | 54% | 9% | all |
| [B. Catalogue and products](tracker/axis-b-catalogue-and-products.md) | 18 | 91 | 34 | 17 | 40 | 56% | 37% | 0% | all |
| [C. Inventory and fulfillment](tracker/axis-c-inventory-and-fulfillment.md) | 18 | 94 | 65 | 9 | 20 | 79% | 69% | 30% | all |
| [D. Orders, checkout and payments](tracker/axis-d-orders-checkout-and-payments.md) | 37 | 173 | 83 | 30 | 60 | 65% | 48% | 8% | all |
| [E. Wallets, loyalty and financial records](tracker/axis-e-wallets-loyalty-and-financial-records.md) | 18 | 84 | 35 | 5 | 44 | 48% | 42% | 7% | all |
| [F. Themes and brand](tracker/axis-f-themes-and-brand.md) | 16 | 74 | 19 | 29 | 26 | 65% | 26% | 4% | all |
| [G. Admin panel and staff permissions](tracker/axis-g-admin-panel-and-staff-permissions.md) | 20 | 96 | 57 | 7 | 32 | 67% | 59% | 15% | all |
| [H. Shipping and integrations](tracker/axis-h-shipping-and-integrations.md) | 34 | 152 | 79 | 10 | 63 | 59% | 52% | 16% | all |
| [I. Marketing, policies and operations](tracker/axis-i-marketing-policies-and-operations.md) | 37 | 183 | 79 | 36 | 68 | 63% | 43% | 3% | all |
| [J. Flutter customer app](tracker/axis-j-flutter-customer-app.md) | 20 | 93 | 42 | 27 | 24 | 74% | 45% | 5% | all |
| [K. Flutter seller app](tracker/axis-k-flutter-seller-app.md) | 23 | 91 | 36 | 16 | 39 | 57% | 40% | 3% | all |
| [L. GCC-20 Super Admin](tracker/axis-l-gcc-20-super-admin.md) | 18 | 94 | 45 | 18 | 31 | 67% | 48% | 7% | all |
| [M. Security, tests and deployment](tracker/axis-m-security-tests-and-deployment.md) | 63 | 289 | 100 | 105 | 84 | 71% | 35% | 3% | all |
| **All axes (pooled)** | **359** | **1675** | **761** | **328** | **586** | **65%** | **45%** | **8%** | |

The pooled row counts every criterion once; it is not weighted by importance or effort and is not a forecast. The two code figures are not release figures: with nothing merged, release readiness can only come from drafts and integration branches that have been tested.

### Requirements by gate

| Gate | Requirements |
|---|---:|
| NOT STARTED | 37 |
| IN PROGRESS | 252 |
| IMPLEMENTED IN DRAFT | 37 |
| INTEGRATED AND TESTED | 15 |
| READY FOR OWNER REVIEW | 0 |
| MERGED | 18 |
| DEPLOYED AND VERIFIED | 0 |

## 3. The three functional gaps that block a complete multi-country LAVKOO

Chosen from the table of requirements, not from the pull request list: each one is named by a completion criterion of the owner's directive, is mostly MISSING in the criteria, and has other requirements waiting on it. Numbers are read from this tracker.

### Gap 1. The money of a storefront is not yet end to end

- **Blocks:** orders with the right currency; prices that are the same in the app and on the web; wallets, coupons, shipping and tax that agree with the order.
- **Where it stands:** GCC-05 (draft PR 87, slices 1 to 4 and most of slice 5, off by default) makes a non-default storefront single-currency in its own currency with the right decimals, records the storefront and currency on the order, refuses a gateway that cannot take that currency, makes the admin product screens work in the product's country, gives the storefront its own minimum order and free-delivery threshold (never the platform's riyal figure), prices its shipping from the product alone, lets the panel edit of an order read all of these in the order's money, and gives a coupon its storefront (stored, narrowed on every customer finder, and typed in the storefront's currency through the admin routes). Still reading the platform's one currency: the admin coupon form, list and export, coupon budgets, the tax snapshot, refunds in the original currency, channel prices, invoices, e-mails and exports, and both apps' other formatters (the customer app has the price formatter and the currency it sends, lavkooapp #8 and #9).
- **Measured:** 20 requirements (2 not started), 100 criteria of which 50 MISSING (50%) as the assessors read it; slices 3 and 4 of PR 87 came after and are in the cards as updates. Requirements: B-02, B-03, D1-05, D1-06, D1-07, D1-10, D2-05, D2-10, E-16, H1-08, H1-09, H1-14, H2-08, I1-12, I1-14, J-05, K-10, L-08, M2-07, C-15.
- **First executable step:** Step 9 of the GCC-05 slice 5 plan on PR 87: the country select and currency labels on the admin coupon form, and the coupon list, quick view and export in the coupon's own money; then the readiness lines (step 10) and the tax and refund snapshot.

### Gap 2. Wallet and loyalty are one global balance

- **Blocks:** wallets and loyalty isolated per market; a customer app purchase paid from a wallet in the right currency.
- **Where it stands:** A customer has one wallet balance and one loyalty balance on the user row and one ledger without a market, currency or selling company. The writers are now safe (row lock, overdraft floor, atomic checkout, whole-cent debit: drafts PR 9 to 12, 34 to 36) and a read-only reconciliation of the balances against the ledgers now exists (draft PR 88, wallet:reconcile, classes A to F), but nothing separates balances by market and nothing migrates the Saudi balances into per-market accounts.
- **Measured:** 13 requirements (2 not started), 65 criteria of which 38 MISSING (58%). Requirements: E-01, E-02, E-03, E-04, E-05, E-06, E-07, E-08, E-13, E-14, E-15, J-09, M2-05.
- **First executable step:** The expand-only per-market account tables behind a switch, the ledger attribution columns (E-05), and a Saudi backfill command that refuses to run unless wallet:reconcile shows zero unexplained on a restored copy.

### Gap 3. There is no selling company (legal entity) dimension

- **Blocks:** orders with the right legal company; reports and money by company; per-company gateway accounts and accounting.
- **Where it stands:** The platform keys data by storefront country, shop and seller. A registry of selling companies (one for each country, empty until the owner supplies them) and an order stamp now exist in a draft (PR 90, behind GCC_LEGAL_ENTITIES), so an order can name its company and keep a frozen copy of its identity, and the three invoices print that company instead of the shop's settings. Still missing: the invoice currency and a number for each company, a company on the payment row, gateway accounts, ledger and settlement by company, an Odoo company per entity, staff scope by company, and the Super Admin money view grouped by company. The owner has not decided the entities (open question Q-11).
- **Measured:** 15 requirements (4 not started), 68 criteria of which 35 MISSING (51%). Requirements: D1-03, D1-04, D2-07, D2-08, D2-09, D2-16, E-17, F-14, G-12, H2-06, H2-10, H2-11, L-08, A2-09, A2-13.
- **First executable step:** The owner's rule for the invoice number of each company and the series on top of the registered key, then the company on the payment request and the accounts that follow it (D2, E, H2); the entities themselves are an owner decision.

Next in line (not in the top three because they depend on these, or are optional until the owner opens them):

- **Seller markets and the seller app:** sellers and seller staff have no market or entity scope (B-09, K-03, K-04, K-09, K-17); seller products are all Saudi today.
- **Per-storefront themes and brand:** one published theme per storefront, independent drafts and brand override (F-01, F-02, F-13) are not started.
- **Shipping eligibility and carriers:** the cross-border engine, carrier route declarations and regulated-goods refusal (H1-01 to H1-09, M2-09) are not started; cross-border stays off by default.
- **Notifications and consent per store:** sender accounts, templates, audiences and consent per store (I1-01 to I1-09, A2-13).

## 4. Formulas and definitions

- **Requirement:** one independently verifiable obligation of the plan, with a stable id (`<unit>-<number>`); `V` in the number marks one the verifier added because the assessor omitted it (counted as one placeholder criterion until its real criteria are written).
- **Acceptance criterion:** one observable behaviour a test could assert. States: `MET` (a named test or CI job on a named head passed and covers it), `CODED` (the code exists and no passing test is cited), `MISSING`.
- **Code completion** = (MET + CODED) / all criteria. **Proven by tests** = MET / all criteria. Both use the same denominator, so the gap between them is exactly the untested code.
- **Release readiness** = sum of gate points / (5 x requirements). Gate points: NOT STARTED and IN PROGRESS 0, IMPLEMENTED IN DRAFT 1, INTEGRATED AND TESTED 2, READY FOR OWNER REVIEW 3, MERGED 4, DEPLOYED AND VERIFIED 5. It measures travel along the gates, not quality.
- **States:** NOT STARTED (no code on any branch); IN PROGRESS (some code, at least one criterion MISSING); IMPLEMENTED IN DRAFT (all criteria coded in a draft pull request, not yet in a tested integration); INTEGRATED AND TESTED (inside an integration tree whose CI is green on the engines that matter); READY FOR OWNER REVIEW (nothing known open, evidence listed); MERGED; DEPLOYED AND VERIFIED.
- **Evidence:** every card carries evidence triples (repository, ref, file, text) that a script checked mechanically against the git objects: 359 requirements, 0 failures.

## 5. Blockers and decisions that are the owner's

276 requirements name at least one open owner decision; they are listed on the card of each requirement under "Open owner decisions" and by axis in [`tracker/owner-decisions.md`](tracker/owner-decisions.md). The reviews that gate a merge or a release are: finance review of the wallet and rounding rules, an independent security review of the platform admin (GCC-20), a native Arabic reader for every new `sa` string, a legal review before any compliance claim, and the owner's choice of legal entities and gateway accounts per country.

## 6. Next coding queue

Executable on an isolated branch without production access, ordered by the priority classes of the owner's directive (P0 security and release, P1 core six-country functions, P2 admin, themes, marketing, integrations, shipping, P3 apps and GCC-20, P4 end to end and release readiness) and then by the number of MISSING criteria. The first 25 are shown; the full text of every task is in item 10 of its card. Several tasks written by the assessors are stale after today's work (the card then carries an "Update after the assessment" and the task of today).

| Priority | Id | State | Missing | Requirement | Next coding task |
|---|---|---|---:|---|---|
| P0 | [M1-16](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 4 | Protection of main and CI cost and reliability | Keep PR 1 as the first change so main turns green. Then two drafts: (a) .github/CODEOWNERS and docs/gcc/BRANCH_PROTECTION.md with the exact ruleset and required checks for a person to apply; (b) ci.yml paths-ignore for docs-only c |
| P0 | [M1-13](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 3 | Review and approval gates: finance, security of GCC-20, native Arabic reader | On the docs branch add docs/gcc/REVIEW_GATES.md: per review (finance #52; security of stages 1 and 4 and of GCC-20 #71, #79, #80, #84 and seller #4, #6; native Arabic keys of seller #6, customer #7, #72, #74 and the added lines of |
| P0 | [M1-14](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 3 | Staging environment with fake data for six countries | Branch gcc/staging-fake-data: add database/seeders/GccStagingSeeder (six storefronts, invented sellers, customers, products per country, orders, wallets, reusing the test world builders), a guard that refuses to seed unless APP_EN |
| P0 | [M1-01](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 2 | Branch and PR ledger, CI read-only, truthful production claims | On claude/exciting-shannon-4mh2ok: commit docs/gcc/PR_LEDGER.md generated by a read-only script from the PR index and CI table (99 PRs), replace the stale STATUS.md section, and add a script that compares the ledger heads with git |
| P0 | [M1-03](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 1 | Combined integration of the security set and the GCC chain, tested on three engines | Read the CI of run 288 (all-integration-ci, 515d5bfa) per job; fix any red job on the integration branch only, or on the pull request that owns the defect; add the result to docs/gcc/audits/2026-11-18-all-in-one-integration/README |
| P0 | [M1-07](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 1 | Ownership, payment-identity and withdraw fixes that have no release path (PRs 7, 16, 20-23 | Read the CI of the all-in-one run 288; then decide with the owner which of these fixes join the staged hotfix as net changes (the method of the hotfix plan) and which wait for the merge of the main-based set. |
| P0 | [M1-09](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 1 | Stage 4: uploads and files cannot store or serve code or other people's files | Add flag-guarded calls to the authenticated digital-file routes in both Flutter apps (unit tests with hand-written server answers) so DIGITAL_FILES_PRIVATE can be turned on; on PR 63 add a read-only command that counts stored scri |
| P0 | [M1-11](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 1 | Deployment tooling: overlay build, drift check, apply, backup and rollback, and its CI job | Add a by-hand set to scripts/deploy: a manifest of files outside the six directories with baseline and new checksums, verify (CLEAN or DRIFTED), apply with backups and rollback, tested in tests/Unit/Deploy/OverlayDeploymentTest; k |
| P0 | [M1-15](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 1 | Register of deferred risks DR-1 to DR-5: open, not remediated | Link DEFERRED_RISKS.md from docs/gcc/README.md in the pull request that brings the documents to main (the docs branch is not a pull request and main is untouched). |
| P0 | [M1-17](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 1 | Saudi behaviour is unchanged while the GCC switches are off | Once the combined tree exists: run it with every switch off and add a Saudi golden-master pack (home, product list, cart, checkout, payment callback, order status, wallet) pinned byte for byte on SQLite, MySQL 8 and MariaDB; leave |
| P0 | [M1-18](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 1 | Engineering safety rules: classify, preserve, extend, isolate, verify; no direct SQL; no w | For each GCC switch (storefronts, country-scoped codes, staff scopes, platform admin) write down what turning it off after use does, add tests that turn it off with two countries' data present and assert no cross-country exposure  |
| P0 | [M1-V1](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 1 | Every P0 finding of the Discovery-02 security triage (23 explicit P0 plus 4 conditional P0 | Write its acceptance criteria and its next coding task (added by the verifier). |
| P0 | [M1-V2](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 1 | A secret and personal-data scan gates every repository's pull requests: the triage's urgen | Write its acceptance criteria and its next coding task (added by the verifier). |
| P1 | [B-13](tracker/axis-b-catalogue-and-products.md) | NOT STARTED | 5 | Show or hide a category or brand per store without deleting its central origin | After the owner decision on semantics: branch from #64 with an additive table storefront_visibilities (storefront_country, entity_type category or brand, entity_id, hidden; unique), a StorefrontVisibility service read by CategoryM |
| P1 | [B-17](tracker/axis-b-catalogue-and-products.md) | NOT STARTED | 5 | SEO, canonical and hreflang per storefront: meta, structured data, sitemap | Stack on #37: a StorefrontUrl helper (country path plus route) used by ProductSeoSupplementComposer (canonical, hreflang), StructuredDataBuilder (priceCurrency) and SiteMapController (one sitemap per active storefront through Site |
| P1 | [D1-06](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 5 | Order currency snapshot on orders, lines and money rows | Slice 5 of PR 87: currency columns on order lines and money rows (payments, refunds, transactions), invoices, e-mails and exports reading the order's own record, and the Saudi backfill after the production currency model is read b |
| P1 | [E-07](tracker/axis-e-wallets-loyalty-and-financial-records.md) | NOT STARTED | 5 | Saudi balances migrated into per-market accounts without loss | Write the migration pack as code only: expand-only migration, wallet:backfill-saudi-accounts --dry-run that exits unless wallet:reconcile reports zero unexplained, shadow dual-write behind GCC_MARKET_WALLETS, per-market read switc |
| P1 | [E-13](tracker/axis-e-wallets-loyalty-and-financial-records.md) | IN PROGRESS | 5 | Refunds, return amounts and cancellations paid to the wallet | Branch on PR 15 once the owner approves: conditional UPDATE claims inside the customer lock for RF-01, RF-03 and RF-04, refund id as reference, one transaction in updateRefundStatus, and un-skip the four RefundConcurrencyTest case |
| P1 | [A2-13](tracker/axis-a-six-country-base-and-customer-identity.md) | NOT STARTED | 4 | Marketing consents scoped by storefront, company and channel | New branch off PR 43 (gcc/p1-legal-acceptance-receipts): additive migration customer_marketing_consents and app/Services/Consent/MarketingConsentRecorder.php mirroring StorefrontLegalAcceptanceRecorder (write-once, withdrawal rows |
| P1 | [C-15](tracker/axis-c-inventory-and-fulfillment.md) | NOT STARTED | 4 | Country defaults of the fulfillment config and adapters (SAR and Saudi assumptions) | Test-only characterization on a branch from main: tests/Feature/Fulfillment/FulfillmentMoneyThreeDecimalsTest pins what a KWD 12.345 cash-on-delivery order does today (held, or rounded to two decimals) so the later fix flips it; a |
| P1 | [D1-10](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 4 | Historical refunds in the original currency and merchant account | Read the CI of PR 101 and PR 103; with the owner's rule on refunds across currency movements, wire RefundFromOriginalPayment into the refund flow behind its own default-off switch, and route a refund through the merchant account o |
| P1 | [D2-03](tracker/axis-d-orders-checkout-and-payments.md) | NOT STARTED | 4 | Tabby and Tamara reviewed; BNPL gateways only after owner approval and provider confirmati | After D2-05: list Tabby and Tamara in the gateway capability registry with confirmed=false for all six markets, and test that an unconfirmed gateway is never listed or accepted; build no adapter until Q-09 is answered. |
| P1 | [D2-07](tracker/axis-d-orders-checkout-and-payments.md) | NOT STARTED | 4 | Per-company gateway accounts bound to each payment | Branch gcc/core-payment-accounts from the PR 83 tree: additive migration payment_gateway_accounts guarded like the other GCC tables, GatewayAccountResolver reading the existing addon_settings row as the SA account, flag GCC_PAYMEN |
| P1 | [E-02](tracker/axis-e-wallets-loyalty-and-financial-records.md) | NOT STARTED | 4 | Loyalty points independent per market, sharing structure off | After E-01: add a points column and per-market settings to the account table, route LoyaltyPointTransactionRepository and CustomerManager::create_loyalty_point_transaction through the market account behind the same switch, and add |
| P1 | [E-14](tracker/axis-e-wallets-loyalty-and-financial-records.md) | IN PROGRESS | 4 | Loyalty redeem and earn: atomic conversion, one formula, one rule | Branch on PR 10: add one canonical points-to-USD helper (LF-1) used by CustomerManager, CustomerTrait and the web preview, one earn-rule service for admin, vendor, seller API and delivery-man deliveries, and a refund_order floor p |

## 7. Index of all requirements

`M/C/X` is the count of MET, CODED and MISSING criteria.

### A. Six-country base and customer identity

| Id | State | M/C/X | Requirement |
|---|---|---|---|
| [A1-01](tracker/axis-a-six-country-base-and-customer-identity.md) | INTEGRATED AND TESTED | 4/0/0 | Six GCC markets in one registry (SA, AE, KW, QA, BH, OM) |
| [A1-02](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 3/0/3 | Each market opens and closes on its own, with a safe way to switch one off |
| [A1-03](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 2/1/3 | Default storefront (GCC-19): Saudi Arabia first, changeable by the admin |
| [A1-04](tracker/axis-a-six-country-base-and-customer-identity.md) | INTEGRATED AND TESTED | 4/0/0 | One resolver chooses the storefront; flag off resolves every request to the default country, and the catalogue is not na |
| [A1-05](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 1/0/3 | Website paths /sa /ae /kw /qa /bh /om serve their market |
| [A1-06](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 3/0/3 | Manual store choice outranks IP and is kept |
| [A1-07](tracker/axis-a-six-country-base-and-customer-identity.md) | INTEGRATED AND TESTED | 3/0/0 | A visitor for an inactive market sees the default store |
| [A1-08](tracker/axis-a-six-country-base-and-customer-identity.md) | IMPLEMENTED IN DRAFT | 4/1/0 | Old Saudi URLs and API contracts keep working unchanged |
| [A1-09](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 1/2/2 | Nine concepts kept apart; shop_id and seller_id are never a storefront key |
| [A1-10](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 3/1/2 | Data-ownership model: an owner scope for every table, no blanket country_id |
| [A1-11](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 3/1/2 | Store context in HTTP: web, API v1, panels, seller APIs, headers |
| [A1-12](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 2/0/2 | Sessions, cookies and sign-in carry no storefront by accident |
| [A1-13](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 4/0/2 | Checkout, payment callbacks and webhooks finish in the storefront they started in |
| [A1-14](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 3/0/3 | Jobs, queue payloads, scheduled tasks and CLI: context carried, never leaked |
| [A1-15](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 3/1/1 | Cache keys that are not theme caches are market-scoped |
| [A1-16](tracker/axis-a-six-country-base-and-customer-identity.md) | IMPLEMENTED IN DRAFT | 4/0/0 | App-local caches in the Flutter apps are market-scoped |
| [A1-17](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 2/0/3 | Notifications are market-scoped (push topics, queued mail) |
| [A1-18](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 2/1/2 | Inventory is market-scoped: no shared stock between countries |
| [A1-V1](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 0/0/1 | One platform: one website, one customer Flutter app and one central control panel serve all six mark... |
| [A1-V2](tracker/axis-a-six-country-base-and-customer-identity.md) | NOT STARTED | 0/0/1 | SEO surfaces (canonical URL, hreflang, sitemap, robots, SEO redirects) are market-scoped. |
| [A2-01](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 1/2/2 | One customer record serves all six markets |
| [A2-02](tracker/axis-a-six-country-base-and-customer-identity.md) | IMPLEMENTED IN DRAFT | 2/2/0 | One login on the website and in the app |
| [A2-03](tracker/axis-a-six-country-base-and-customer-identity.md) | INTEGRATED AND TESTED | 5/0/0 | Code sign-in and password reset act only for the phone or account that proved itself |
| [A2-04](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 2/1/2 | Social login (OAuth) cannot confirm, detach or take over an account without provider proof |
| [A2-05](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 0/3/2 | One-time codes are random, expire, are rate-limited and are not logged |
| [A2-06](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 2/0/3 | Phone and e-mail identity matching across the six markets |
| [A2-07](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 4/0/1 | Request identity and order ownership come only from the authenticated principal |
| [A2-08](tracker/axis-a-six-country-base-and-customer-identity.md) | IMPLEMENTED IN DRAFT | 5/0/0 | An independent cart for each storefront |
| [A2-09](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 4/0/0 | Orders stay separate by storefront and selling company |
| [A2-10](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 1/2/2 | Order history across markets for the same customer |
| [A2-11](tracker/axis-a-six-country-base-and-customer-identity.md) | MERGED | 3/0/0 | Saved addresses belong to the account, and an order keeps an immutable copy |
| [A2-12](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 1/0/2 | Address eligibility checked per storefront |
| [A2-13](tracker/axis-a-six-country-base-and-customer-identity.md) | NOT STARTED | 0/0/4 | Marketing consents scoped by storefront, company and channel |
| [A2-14](tracker/axis-a-six-country-base-and-customer-identity.md) | IMPLEMENTED IN DRAFT | 3/1/0 | Existing accounts, ids and history are preserved |
| [A2-15](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 3/0/2 | Privacy of customer data: logs, payloads, retention and deletion |
| [A2-V1](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 0/0/1 | A local admin or employee of one market cannot see the customer's transactions of other markets with... |
| [A2-V2](tracker/axis-a-six-country-base-and-customer-identity.md) | IN PROGRESS | 0/0/1 | Policy consents are stored in the right scope: the explicit acceptance of a legal text is recorded a... |

### B. Catalogue and products

| Id | State | M/C/X | Requirement |
|---|---|---|---|
| [B-01](tracker/axis-b-catalogue-and-products.md) | IN PROGRESS | 4/0/2 | Country-owned products: each product row belongs to one country and a storefront shows only its own |
| [B-02](tracker/axis-b-catalogue-and-products.md) | IN PROGRESS | 1/2/1 | Independent local prices: each store prices its own copy, with no conversion or sync between countries |
| [B-03](tracker/axis-b-catalogue-and-products.md) | IN PROGRESS | 2/0/3 | Local price and availability display: a storefront shows its own market currency, decimals and stock |
| [B-04](tracker/axis-b-catalogue-and-products.md) | IN PROGRESS | 5/0/1 | SKU and barcode unique inside a country: form gates, seller APIs and importers (GCC-14b) |
| [B-05](tracker/axis-b-catalogue-and-products.md) | IN PROGRESS | 4/1/1 | SKU and barcode lookups are country-aware: POS scan, channel matcher, routing rules, provider pickers |
| [B-06](tracker/axis-b-catalogue-and-products.md) | IN PROGRESS | 2/0/2 | Variants: variant SKU, price and stock follow the product's country |
| [B-07](tracker/axis-b-catalogue-and-products.md) | IN PROGRESS | 1/2/3 | Deliberate cross-country product creation: explicit admin copy, no shared stock or price sync, nothing created automatic |
| [B-08](tracker/axis-b-catalogue-and-products.md) | IN PROGRESS | 5/0/1 | Admin product tools: choose, show and filter a product's country; staff limited to their countries (GCC-14a, 14c slice 1 |
| [B-09](tracker/axis-b-catalogue-and-products.md) | IN PROGRESS | 1/1/2 | Seller and vendor products belong to a market (seller markets) |
| [B-10](tracker/axis-b-catalogue-and-products.md) | IN PROGRESS | 1/2/1 | One central category tree for all countries: no parallel tree, depth examined |
| [B-11](tracker/axis-b-catalogue-and-products.md) | IN PROGRESS | 1/2/1 | Central brands: one brand table for all countries |
| [B-12](tracker/axis-b-catalogue-and-products.md) | IN PROGRESS | 0/1/3 | Reusable base attributes plus local attributes per country or store |
| [B-13](tracker/axis-b-catalogue-and-products.md) | NOT STARTED | 0/0/5 | Show or hide a category or brand per store without deleting its central origin |
| [B-14](tracker/axis-b-catalogue-and-products.md) | IN PROGRESS | 4/1/1 | Search and filters follow the storefront |
| [B-15](tracker/axis-b-catalogue-and-products.md) | IN PROGRESS | 3/0/2 | Banners per store |
| [B-16](tracker/axis-b-catalogue-and-products.md) | IN PROGRESS | 0/3/3 | Campaigns, deals and coupon display per store |
| [B-17](tracker/axis-b-catalogue-and-products.md) | NOT STARTED | 0/0/5 | SEO, canonical and hreflang per storefront: meta, structured data, sitemap |
| [B-18](tracker/axis-b-catalogue-and-products.md) | IN PROGRESS | 0/2/3 | Old product URLs, redirects, app links and deep links keep working next to the country paths |

### C. Inventory and fulfillment

| Id | State | M/C/X | Requirement |
|---|---|---|---|
| [C-01](tracker/axis-c-inventory-and-fulfillment.md) | IN PROGRESS | 3/1/1 | One fulfillment provider per product in a country storefront |
| [C-02](tracker/axis-c-inventory-and-fulfillment.md) | MERGED | 6/0/0 | No automatic fallback between fulfillment providers |
| [C-03](tracker/axis-c-inventory-and-fulfillment.md) | IN PROGRESS | 3/0/3 | Every product and listing tied to an explicit, visible source |
| [C-04](tracker/axis-c-inventory-and-fulfillment.md) | IN PROGRESS | 4/0/2 | Historical execution source frozen on order lines |
| [C-05](tracker/axis-c-inventory-and-fulfillment.md) | IN PROGRESS | 2/1/3 | Stock ownership per storefront and country, separate from seller_id |
| [C-06](tracker/axis-c-inventory-and-fulfillment.md) | MERGED | 5/0/0 | Inventory Locations and provider Inventory Sync retained and reused |
| [C-07](tracker/axis-c-inventory-and-fulfillment.md) | MERGED | 5/0/0 | Inventory Pools and Stock Safety Buffers retained |
| [C-08](tracker/axis-c-inventory-and-fulfillment.md) | MERGED | 5/0/0 | Reservations with idempotent holds retained |
| [C-09](tracker/axis-c-inventory-and-fulfillment.md) | MERGED | 5/0/0 | Transactional outbox for stock changes retained |
| [C-10](tracker/axis-c-inventory-and-fulfillment.md) | MERGED | 6/0/0 | Fulfillment adapters, provider preflight and kill switches retained |
| [C-11](tracker/axis-c-inventory-and-fulfillment.md) | IN PROGRESS | 4/1/1 | Idempotency, concurrency and oversell protection of stock writers |
| [C-12](tracker/axis-c-inventory-and-fulfillment.md) | IN PROGRESS | 2/0/3 | Room for country-specific fulfillment providers (UAE, Kuwait, Bahrain, Qatar, Oman) |
| [C-13](tracker/axis-c-inventory-and-fulfillment.md) | IMPLEMENTED IN DRAFT | 2/1/0 | No second inventory engine: country separation on the existing row |
| [C-14](tracker/axis-c-inventory-and-fulfillment.md) | IN PROGRESS | 5/0/1 | Fulfillment provider kept separate from sales channel; one source feeds every marketplace |
| [C-15](tracker/axis-c-inventory-and-fulfillment.md) | NOT STARTED | 0/0/4 | Country defaults of the fulfillment config and adapters (SAR and Saudi assumptions) |
| [C-16](tracker/axis-c-inventory-and-fulfillment.md) | IN PROGRESS | 3/2/1 | Cross-country overlap, oversell and duplicate-reservation protection |
| [C-17](tracker/axis-c-inventory-and-fulfillment.md) | IN PROGRESS | 0/3/1 | Axis E map: the inventory and fulfillment flow and where countries can overlap |
| [C-18](tracker/axis-c-inventory-and-fulfillment.md) | INTEGRATED AND TESTED | 5/0/0 | Torod Point and M5AZN integrations preserved for the Saudi store |

### D. Orders, checkout and payments

| Id | State | M/C/X | Requirement |
|---|---|---|---|
| [D1-01](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 3/1/1 | One storefront per checkout and per order |
| [D1-02](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 4/0/1 | No cart or checkout mixing countries or selling companies |
| [D1-03](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 4/0/2 | One selling company (legal entity) per order, with frozen identity |
| [D1-04](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 2/1/0 | Country, storefront, legal entity, seller and shop kept as separate keys |
| [D1-05](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 3/2/0 | Local independent pricing at checkout, frozen on the order lines |
| [D1-06](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 0/0/5 | Order currency snapshot on orders, lines and money rows |
| [D1-07](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 5/2/1 | Order tax snapshot by country and selling company |
| [D1-08](tracker/axis-d-orders-checkout-and-payments.md) | MERGED | 3/1/0 | Address snapshot on every order |
| [D1-09](tracker/axis-d-orders-checkout-and-payments.md) | MERGED | 3/1/0 | Fulfillment source frozen on each order line |
| [D1-10](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 0/3/4 | Historical refunds in the original currency and merchant account |
| [D1-11](tracker/axis-d-orders-checkout-and-payments.md) | IMPLEMENTED IN DRAFT | 2/2/0 | Operations on placed orders survive store state and run once |
| [D1-12](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 2/2/1 | Multi-vendor and sub-order behaviour kept inside one storefront and company |
| [D1-13](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 3/2/1 | Checkout and order concurrency: stock locks and order numbers |
| [D1-14](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 2/0/3 | Order creation is idempotent |
| [D1-15](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 5/0/1 | Checkout and order operations act only for their owner |
| [D1-16](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 3/1/2 | Sale refusal for a storefront that may not sell yet |
| [D1-17](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 2/1/2 | Purchase depends on delivery-address eligibility, not on the visitor's country |
| [D2-01](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 0/3/1 | Paymob kept working for the Saudi baseline and pinned by tests |
| [D2-02](tracker/axis-d-orders-checkout-and-payments.md) | MERGED | 4/0/0 | MyFatoorah kept working for the Saudi baseline, suite green on three engines |
| [D2-03](tracker/axis-d-orders-checkout-and-payments.md) | NOT STARTED | 0/0/4 | Tabby and Tamara reviewed; BNPL gateways only after owner approval and provider confirmation |
| [D2-04](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 2/1/1 | One gateway registry, no duplicate gateway or class |
| [D2-05](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 0/1/3 | Gateway, method and currency offered only where the provider supports the market |
| [D2-06](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 1/1/3 | Order-level payment-method eligibility, including cross-border orders |
| [D2-07](tracker/axis-d-orders-checkout-and-payments.md) | NOT STARTED | 0/0/4 | Per-company gateway accounts bound to each payment |
| [D2-08](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 3/0/2 | Per-company gateway secrets and separate test and live environments |
| [D2-09](tracker/axis-d-orders-checkout-and-payments.md) | NOT STARTED | 0/0/3 | Per-company webhook settings and webhook scope |
| [D2-10](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 2/1/2 | Payment transaction records carry market, company, account and exact amount |
| [D2-11](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 3/1/2 | Redirect verification: the customer's return is confirmed with the provider |
| [D2-12](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 4/0/1 | Webhook verification: only authentic notifications, each processed once |
| [D2-13](tracker/axis-d-orders-checkout-and-payments.md) | IMPLEMENTED IN DRAFT | 3/1/0 | Idempotent settlement: one payment, one order, for the payer it names |
| [D2-14](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 5/0/1 | A payment is finished, and the customer returns, in the storefront it started in |
| [D2-15](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 4/0/2 | Refunds through the original account and currency, linked to the order |
| [D2-16](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 2/1/2 | Payment reconciliation per market and company |
| [D2-17](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 4/0/1 | Payment security constraints: logs, hosts, rate limits, ownership |
| [D2-18](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 0/1/2 | Payment lifecycle (axis F) pinned end to end per market |
| [D2-V1](tracker/axis-d-orders-checkout-and-payments.md) | IN PROGRESS | 0/0/1 | Payment currency is the storefront's currency: no automatic conversion and no silent fallback to the... |
| [D2-V2](tracker/axis-d-orders-checkout-and-payments.md) | NOT STARTED | 0/0/1 | Per-storefront on/off of payment methods that are not gateways (cash on delivery, offline payment, d... |

### E. Wallets, loyalty and financial records

| Id | State | M/C/X | Requirement |
|---|---|---|---|
| [E-01](tracker/axis-e-wallets-loyalty-and-financial-records.md) | IN PROGRESS | 1/0/3 | Customer wallet separate per market (storefront, company, currency) |
| [E-02](tracker/axis-e-wallets-loyalty-and-financial-records.md) | NOT STARTED | 0/0/4 | Loyalty points independent per market, sharing structure off |
| [E-03](tracker/axis-e-wallets-loyalty-and-financial-records.md) | IN PROGRESS | 1/0/3 | No automatic transfer or conversion of balances between markets |
| [E-04](tracker/axis-e-wallets-loyalty-and-financial-records.md) | IN PROGRESS | 1/1/3 | Wallet and loyalty readers show one market's balance, API keys keep meaning |
| [E-05](tracker/axis-e-wallets-loyalty-and-financial-records.md) | IN PROGRESS | 2/2/2 | Ledger rows reconcilable: attribution, reference, actor, idempotency |
| [E-06](tracker/axis-e-wallets-loyalty-and-financial-records.md) | INTEGRATED AND TESTED | 5/0/0 | Historical balances reconciled against the ledgers (read-only proof) |
| [E-07](tracker/axis-e-wallets-loyalty-and-financial-records.md) | NOT STARTED | 0/0/5 | Saudi balances migrated into per-market accounts without loss |
| [E-08](tracker/axis-e-wallets-loyalty-and-financial-records.md) | IN PROGRESS | 1/1/1 | Old movements preserved: append-only ledgers, account deletion keeps records |
| [E-09](tracker/axis-e-wallets-loyalty-and-financial-records.md) | INTEGRATED AND TESTED | 4/0/0 | Balance writes serialised per customer (row lock), wallet and loyalty |
| [E-10](tracker/axis-e-wallets-loyalty-and-financial-records.md) | INTEGRATED AND TESTED | 5/0/0 | Overdraft floor inside the lock; callers honour a refused debit or credit |
| [E-11](tracker/axis-e-wallets-loyalty-and-financial-records.md) | IN PROGRESS | 5/0/1 | POS wallet sales: atomic, server total, guarded amount, idempotent |
| [E-12](tracker/axis-e-wallets-loyalty-and-financial-records.md) | IN PROGRESS | 5/0/1 | Seller wallet and withdrawals: scoped, locked, POST with CSRF, one conversion |
| [E-13](tracker/axis-e-wallets-loyalty-and-financial-records.md) | IN PROGRESS | 1/0/5 | Refunds, return amounts and cancellations paid to the wallet |
| [E-14](tracker/axis-e-wallets-loyalty-and-financial-records.md) | IN PROGRESS | 1/0/4 | Loyalty redeem and earn: atomic conversion, one formula, one rule |
| [E-15](tracker/axis-e-wallets-loyalty-and-financial-records.md) | IN PROGRESS | 2/0/4 | Referral rewards: once, to the referrer, one amount |
| [E-16](tracker/axis-e-wallets-loyalty-and-financial-records.md) | IN PROGRESS | 1/0/3 | One currency unit and rounding for wallet debits and credits |
| [E-17](tracker/axis-e-wallets-loyalty-and-financial-records.md) | IN PROGRESS | 0/1/4 | Financial records by legal entity and currency (ledgers, settlements, summaries) |
| [E-V1](tracker/axis-e-wallets-loyalty-and-financial-records.md) | IN PROGRESS | 0/0/1 | Wallet top-up (add fund) by gateway and by admin, the 'add' sites of the plan line 368 review: one c... |

### F. Themes and brand

| Id | State | M/C/X | Requirement |
|---|---|---|---|
| [F-01](tracker/axis-f-themes-and-brand.md) | INTEGRATED AND TESTED | 4/0/0 | One active published theme per country storefront |
| [F-02](tracker/axis-f-themes-and-brand.md) | IN PROGRESS | 1/1/2 | Independent drafts, versions and publish per storefront |
| [F-03](tracker/axis-f-themes-and-brand.md) | IN PROGRESS | 2/2/1 | Web and customer-app customisation, per-platform publish |
| [F-04](tracker/axis-f-themes-and-brand.md) | IN PROGRESS | 1/4/1 | Theme caches isolated per storefront |
| [F-05](tracker/axis-f-themes-and-brand.md) | IMPLEMENTED IN DRAFT | 2/4/0 | Storefront context reaches the theme API and the web theme |
| [F-06](tracker/axis-f-themes-and-brand.md) | IN PROGRESS | 5/0/1 | Customer app theme cache follows the storefront and the language |
| [F-07](tracker/axis-f-themes-and-brand.md) | IN PROGRESS | 0/4/1 | Theme preview isolated from shoppers, caches and other storefronts |
| [F-08](tracker/axis-f-themes-and-brand.md) | IN PROGRESS | 0/4/1 | Scheduled publish and rollback, safe and per storefront |
| [F-09](tracker/axis-f-themes-and-brand.md) | IN PROGRESS | 1/3/2 | App-version compatibility of the theme contract |
| [F-10](tracker/axis-f-themes-and-brand.md) | IN PROGRESS | 0/2/4 | Shared section library serving each storefront's own data |
| [F-11](tracker/axis-f-themes-and-brand.md) | IN PROGRESS | 0/2/2 | Template library and optional theme copy between storefronts |
| [F-12](tracker/axis-f-themes-and-brand.md) | IN PROGRESS | 0/2/2 | Shared design identity with per-storefront override |
| [F-13](tracker/axis-f-themes-and-brand.md) | NOT STARTED | 0/0/4 | Brand name, logo and identity: global default, country override |
| [F-14](tracker/axis-f-themes-and-brand.md) | IN PROGRESS | 3/0/1 | Visible brand separate from the legal company name on invoices and contracts |
| [F-15](tracker/axis-f-themes-and-brand.md) | IN PROGRESS | 0/1/3 | Existing Theme Editor extended with a storefront selector, not rebuilt |
| [F-V1](tracker/axis-f-themes-and-brand.md) | IN PROGRESS | 0/0/1 | Builder draft mutations (ThemeBuilderService: add, update, duplicate, delete and reorder sections an... |

### G. Admin panel and staff permissions

| Id | State | M/C/X | Requirement |
|---|---|---|---|
| [G-01](tracker/axis-g-admin-panel-and-staff-permissions.md) | MERGED | 4/0/0 | Admin, AdminRole, module_access and the module gate stay the authorization base |
| [G-02](tracker/axis-g-admin-panel-and-staff-permissions.md) | INTEGRATED AND TESTED | 5/0/0 | Staff country scope: table, resolution rules, console assignment, default-off switch, the server decides |
| [G-03](tracker/axis-g-admin-panel-and-staff-permissions.md) | INTEGRATED AND TESTED | 6/0/0 | Product screens, pickers, import and moderation follow the employee's countries (GCC-14a, 14c slice 1) |
| [G-04](tracker/axis-g-admin-panel-and-staff-permissions.md) | INTEGRATED AND TESTED | 6/0/0 | Order, POS and refund screens follow the employee's countries (GCC-14c slice 2) |
| [G-05](tracker/axis-g-admin-panel-and-staff-permissions.md) | IMPLEMENTED IN DRAFT | 3/3/0 | Dashboard, reports, shared layout counters, tax reports and analytics follow the employee's countries (GCC-14c slice 3) |
| [G-06](tracker/axis-g-admin-panel-and-staff-permissions.md) | IN PROGRESS | 3/0/2 | The admin header search shows only what the role and the country scope allow |
| [G-07](tracker/axis-g-admin-panel-and-staff-permissions.md) | IN PROGRESS | 3/0/2 | A local manager cannot see a customer's transactions of other countries without permission |
| [G-08](tracker/axis-g-admin-panel-and-staff-permissions.md) | IN PROGRESS | 1/1/3 | Stock and inventory screens follow the employee's countries |
| [G-09](tracker/axis-g-admin-panel-and-staff-permissions.md) | IN PROGRESS | 1/0/4 | Screens that still show every country: promotions, content, reviews, marketplace, integrations, costs (slices 4 to 6) |
| [G-10](tracker/axis-g-admin-panel-and-staff-permissions.md) | IN PROGRESS | 3/0/2 | Exports carry the same scope as the screen they come from |
| [G-11](tracker/axis-g-admin-panel-and-staff-permissions.md) | IN PROGRESS | 3/0/2 | Bulk operations and background jobs honour the scope |
| [G-12](tracker/axis-g-admin-panel-and-staff-permissions.md) | NOT STARTED | 0/0/3 | Different permissions per country and a company (legal entity) dimension on staff scopes |
| [G-13](tracker/axis-g-admin-panel-and-staff-permissions.md) | INTEGRATED AND TESTED | 6/0/0 | API principals reach only the markets that both their grant and their staff scope allow |
| [G-14](tracker/axis-g-admin-panel-and-staff-permissions.md) | IN PROGRESS | 2/0/3 | Sensitive actions are scoped, confirmed and audited |
| [G-15](tracker/axis-g-admin-panel-and-staff-permissions.md) | IN PROGRESS | 1/1/4 | Audit logs: who changed what, and in which country |
| [G-16](tracker/axis-g-admin-panel-and-staff-permissions.md) | IN PROGRESS | 3/1/2 | Provider secrets in the admin panel: who may read or change them and what is shown |
| [G-17](tracker/axis-g-admin-panel-and-staff-permissions.md) | IN PROGRESS | 2/1/3 | Super admin rules and the management of employees and roles |
| [G-18](tracker/axis-g-admin-panel-and-staff-permissions.md) | INTEGRATED AND TESTED | 5/0/0 | Every admin route asks for the permission of its menu, or is listed with a reason |
| [G-V1](tracker/axis-g-admin-panel-and-staff-permissions.md) | IN PROGRESS | 0/0/1 | A written data-isolation design (the concept the plan asks for) with a rule for each of the eight la... |
| [G-V2](tracker/axis-g-admin-panel-and-staff-permissions.md) | IN PROGRESS | 0/0/1 | The current security systems around staff stay in force, in the same order, when scope code is added... |

### H. Shipping and integrations

| Id | State | M/C/X | Requirement |
|---|---|---|---|
| [H1-01](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 1/0/3 | Cross-border selling is allowed per storefront by setting, default off |
| [H1-02](tracker/axis-h-shipping-and-integrations.md) | NOT STARTED | 0/0/4 | A carrier declares the routes it serves; checkout and dispatch honour them |
| [H1-03](tracker/axis-h-shipping-and-integrations.md) | NOT STARTED | 0/0/3 | Product shipping, export and import permission; no permission means no foreign shipping |
| [H1-04](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 1/0/3 | Regulated health and pharmaceutical goods fail closed, whatever the courier covers |
| [H1-05](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 3/0/2 | Destination address accepted: country-aware rules and carrier serviceability |
| [H1-06](tracker/axis-h-shipping-and-integrations.md) | NOT STARTED | 0/0/5 | One shipping eligibility engine decides local or cross-border and refuses with reasons |
| [H1-07](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 1/2/1 | A foreign destination never changes the stock owner, seller or selling company |
| [H1-08](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 1/2/3 | Shipping cost per destination and route, in the store's currency |
| [H1-09](tracker/axis-h-shipping-and-integrations.md) | NOT STARTED | 0/0/4 | Tax and duties on a cross-border order |
| [H1-10](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 2/0/3 | Address-to-carrier city mapping that works in every country |
| [H1-11](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 1/0/4 | Carrier adapters open to providers in other countries |
| [H1-12](tracker/axis-h-shipping-and-integrations.md) | MERGED | 5/0/0 | Torod Point and M5AZN integrations kept working as carriers |
| [H1-13](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 2/0/2 | Shipping and tax trace for local and international orders, pinned by scenario tests |
| [H1-14](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 3/0/3 | Local shipping rules belong to a storefront and are never inherited |
| [H2-01](tracker/axis-h-shipping-and-integrations.md) | MERGED | 5/0/0 | Amazon integration kept and working for the Saudi marketplace |
| [H2-02](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 3/0/2 | Noon integration kept and operable from the admin panel |
| [H2-03](tracker/axis-h-shipping-and-integrations.md) | MERGED | 5/0/0 | Trendyol integration kept and working for the Gulf storefronts it already supports |
| [H2-04](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 4/0/1 | Odoo accounting integration kept and wired end to end (connect, preflight, map, draft, reconcile) |
| [H2-05](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 4/1/0 | Each channel and integration account is independent, with its own operating scope |
| [H2-06](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 2/0/3 | A channel account belongs to one storefront country and legal entity |
| [H2-07](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 2/0/3 | Each channel's market rules cover the six GCC markets it can serve |
| [H2-08](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 1/1/2 | Channel prices, currency and tax follow each market's currency and decimals |
| [H2-09](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 2/0/1 | The pharmacy (regulated-product) gate on channel listings follows each market's rules |
| [H2-10](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 3/0/3 | Odoo posts each legal entity's records into that entity's Odoo company |
| [H2-11](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 3/1/2 | Accounting separation by entity, currency and channel, with settlements reconciled |
| [H2-12](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 3/1/1 | Safety locks and kill switches |
| [H2-13](tracker/axis-h-shipping-and-integrations.md) | MERGED | 5/0/0 | Dry run (rehearsal) for every outward write |
| [H2-14](tracker/axis-h-shipping-and-integrations.md) | MERGED | 4/0/0 | Preflight checks before anything is switched on |
| [H2-15](tracker/axis-h-shipping-and-integrations.md) | MERGED | 4/0/0 | Protection of channel and Odoo secrets |
| [H2-16](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 4/0/1 | No unintended publishing or sending to a channel, provider or ledger |
| [H2-17](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 4/0/1 | One product on several marketplaces, its stock from the same fulfillment source |
| [H2-18](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 0/2/2 | Integration and pull-request dependency map (Report 07) |
| [H2-V1](tracker/axis-h-shipping-and-integrations.md) | MERGED | 1/0/0 | A fulfilment provider (Torod Point, M5AZN, own warehouse) and a sales channel are separate concepts:... |
| [H2-V2](tracker/axis-h-shipping-and-integrations.md) | IN PROGRESS | 0/0/1 | Listing content follows each marketplace's own requirements per market: the store's central categori... |

### I. Marketing, policies and operations

| Id | State | M/C/X | Requirement |
|---|---|---|---|
| [I1-01](tracker/axis-i-marketing-policies-and-operations.md) | NOT STARTED | 0/0/4 | E-mail sender account, shared or separate per store |
| [I1-02](tracker/axis-i-marketing-policies-and-operations.md) | NOT STARTED | 0/0/4 | SMS, WhatsApp and push profiles per store |
| [I1-03](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 0/1/4 | Notification templates: global, with country and language overrides |
| [I1-04](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 1/0/4 | Marketing audiences are isolated by store |
| [I1-05](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 0/2/3 | Push topics do not overlap between markets |
| [I1-06](tracker/axis-i-marketing-policies-and-operations.md) | NOT STARTED | 0/0/4 | Order notifications are built from the store that created the order |
| [I1-07](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 1/1/2 | Necessary notifications for old orders continue when a store stops selling |
| [I1-08](tracker/axis-i-marketing-policies-and-operations.md) | NOT STARTED | 0/0/4 | Marketing sends respect consent |
| [I1-09](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 0/2/3 | Notification sends are idempotent and logged |
| [I1-10](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 3/1/1 | Existing notification classes and senders are kept and pinned |
| [I1-11](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 3/2/1 | Coupon and CouponService logic is kept, its boundary pinned and its messages defined |
| [I1-12](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 2/2/1 | Coupon amounts in each store's currency; legacy conversions verified |
| [I1-13](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 3/1/1 | Coupons name their eligible products and store |
| [I1-14](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 0/2/3 | Per-store coupon limits and budget |
| [I1-15](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 1/0/4 | Campaigns: central ones choose stores, local ones belong to one store |
| [I1-16](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 2/1/3 | Languages per market |
| [I1-17](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 3/0/2 | Customer-facing text is translated per market, and tests never rewrite language files |
| [I1-18](tracker/axis-i-marketing-policies-and-operations.md) | IMPLEMENTED IN DRAFT | 0/3/0 | Communications audit (axis J): e-mail, SMS, WhatsApp, push, audiences, templates |
| [I2-01](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 3/1/2 | Per-country legal text store: versions, approval and effective dates, history |
| [I2-02](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 5/1/0 | Legal readiness: current effective approved text for every required type and locale |
| [I2-03](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 5/0/1 | Legal publishing workflow: template copy is a draft, local text never auto-published |
| [I2-04](tracker/axis-i-marketing-policies-and-operations.md) | INTEGRATED AND TESTED | 6/0/0 | Storefront shows its own country legal text on web and in the apps |
| [I2-05](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 3/1/2 | Acceptance receipts: proof of the legal text a customer accepted, tied to the order |
| [I2-06](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 0/2/2 | Legal pages kept separate from the PolicyRegistry operating rules |
| [I2-07](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 1/3/1 | No claim of legal compliance without an independent official review |
| [I2-08](tracker/axis-i-marketing-policies-and-operations.md) | IMPLEMENTED IN DRAFT | 4/2/0 | Sale gate on the customer API and web checkout for a storefront that may not sell |
| [I2-09](tracker/axis-i-marketing-policies-and-operations.md) | NOT STARTED | 0/0/4 | Sale gate on POS, seller API and marketplace order writers |
| [I2-10](tracker/axis-i-marketing-policies-and-operations.md) | IMPLEMENTED IN DRAFT | 4/2/0 | Customer app follows the server answer: browse-only storefront cannot order or pay |
| [I2-11](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 5/0/0 | Store lifecycle states Draft, Ready, Active, Paused, managed by admin |
| [I2-12](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 4/0/1 | Readiness preflight before a country is activated |
| [I2-13](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 3/2/0 | Stop mode Browse Only |
| [I2-14](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 4/1/0 | Stop mode Maintenance per storefront |
| [I2-15](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 2/0/3 | Stop mode Redirect to the default storefront |
| [I2-16](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 4/1/0 | Stop never loses earlier orders or breaks webhooks, refunds, settlements, shipments, support |
| [I2-17](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 4/0/2 | Saudi Arabia is the default storefront; a default must stay valid and operational |
| [I2-18](tracker/axis-i-marketing-policies-and-operations.md) | IN PROGRESS | 3/1/2 | Safe first-launch migration plan that confirms current Saudi work continues |
| [I2-V1](tracker/axis-i-marketing-policies-and-operations.md) | IMPLEMENTED IN DRAFT | 0/1/0 | Audit axis J, legal half: the legal documents and their versions are reviewed with file evidence (da... |

### J. Flutter customer app

| Id | State | M/C/X | Requirement |
|---|---|---|---|
| [J-01](tracker/axis-j-flutter-customer-app.md) | IN PROGRESS | 4/1/1 | Default storefront and manual choice precedence |
| [J-02](tracker/axis-j-flutter-customer-app.md) | IN PROGRESS | 5/1/0 | Country chooser and storefront switch |
| [J-03](tracker/axis-j-flutter-customer-app.md) | IN PROGRESS | 5/1/0 | X-Storefront header on every API request |
| [J-04](tracker/axis-j-flutter-customer-app.md) | IMPLEMENTED IN DRAFT | 4/1/0 | Caches and local storage keyed by storefront |
| [J-05](tracker/axis-j-flutter-customer-app.md) | IN PROGRESS | 2/1/1 | Per-storefront currency and price formatting |
| [J-06](tracker/axis-j-flutter-customer-app.md) | IMPLEMENTED IN DRAFT | 4/2/0 | Theme rendering per storefront and platform |
| [J-07](tracker/axis-j-flutter-customer-app.md) | IMPLEMENTED IN DRAFT | 4/1/0 | Cart and wishlist per storefront |
| [J-08](tracker/axis-j-flutter-customer-app.md) | IN PROGRESS | 4/2/0 | Sellable gate and paused-storefront modes |
| [J-09](tracker/axis-j-flutter-customer-app.md) | IN PROGRESS | 0/4/2 | Payment and wallet flows per storefront |
| [J-10](tracker/axis-j-flutter-customer-app.md) | IN PROGRESS | 0/1/4 | Deep links, App Links and old links |
| [J-11](tracker/axis-j-flutter-customer-app.md) | IN PROGRESS | 0/1/4 | Push topics and notifications per storefront |
| [J-12](tracker/axis-j-flutter-customer-app.md) | IMPLEMENTED IN DRAFT | 1/3/0 | One login across storefronts and auth security compatibility |
| [J-13](tracker/axis-j-flutter-customer-app.md) | IN PROGRESS | 1/2/1 | Order history across storefronts |
| [J-14](tracker/axis-j-flutter-customer-app.md) | NOT STARTED | 0/0/3 | Address eligibility per storefront |
| [J-15](tracker/axis-j-flutter-customer-app.md) | IN PROGRESS | 0/1/3 | Legal policy screens and consent scopes |
| [J-16](tracker/axis-j-flutter-customer-app.md) | IMPLEMENTED IN DRAFT | 3/2/0 | Language, RTL and strings |
| [J-17](tracker/axis-j-flutter-customer-app.md) | IN PROGRESS | 1/3/1 | Version gate and update flow (never bypassed) |
| [J-18](tracker/axis-j-flutter-customer-app.md) | IN PROGRESS | 4/0/2 | App tests, CI and device verification |
| [J-V1](tracker/axis-j-flutter-customer-app.md) | NOT STARTED | 0/0/1 | Draft theme preview in the customer app: the app renders an unpublished draft of a storefront's home... |
| [J-V2](tracker/axis-j-flutter-customer-app.md) | NOT STARTED | 0/0/1 | Loyalty points in the customer app (points ledger, earn rules, point-to-wallet conversion) belong to... |

### K. Flutter seller app

| Id | State | M/C/X | Requirement |
|---|---|---|---|
| [K-01](tracker/axis-k-flutter-seller-app.md) | IMPLEMENTED IN DRAFT | 3/1/0 | Seller market scope and X-Storefront header (default off) |
| [K-02](tracker/axis-k-flutter-seller-app.md) | IMPLEMENTED IN DRAFT | 4/1/0 | Seller market choice: storage and manual precedence |
| [K-03](tracker/axis-k-flutter-seller-app.md) | NOT STARTED | 0/0/4 | Market selector, live header update and market-switch hygiene |
| [K-04](tracker/axis-k-flutter-seller-app.md) | IN PROGRESS | 1/0/2 | Per-market data in seller screens (orders, products, stock, finance) |
| [K-05](tracker/axis-k-flutter-seller-app.md) | IN PROGRESS | 5/1/0 | POS scan with country-scoped codes |
| [K-06](tracker/axis-k-flutter-seller-app.md) | IN PROGRESS | 3/1/1 | POS sale safety: retries, offline outbox and customer id |
| [K-07](tracker/axis-k-flutter-seller-app.md) | IN PROGRESS | 5/0/0 | Order edit refusal handling and approved-only review average |
| [K-08](tracker/axis-k-flutter-seller-app.md) | IN PROGRESS | 1/3/1 | Seller owner and staff roles keep working; the app shows what a role may use |
| [K-09](tracker/axis-k-flutter-seller-app.md) | NOT STARTED | 0/0/4 | Country and entity scopes for sellers and seller staff (GCC-14, seller side) |
| [K-10](tracker/axis-k-flutter-seller-app.md) | IN PROGRESS | 2/0/3 | Per-market currency, decimals and no app-side conversion |
| [K-11](tracker/axis-k-flutter-seller-app.md) | IN PROGRESS | 2/0/3 | Seller push: tokens, topics, payload market and routing |
| [K-12](tracker/axis-k-flutter-seller-app.md) | IN PROGRESS | 2/0/3 | Session and token handling in the seller app |
| [K-13](tracker/axis-k-flutter-seller-app.md) | IN PROGRESS | 1/1/2 | Arabic strings awaiting a native reader |
| [K-14](tracker/axis-k-flutter-seller-app.md) | IN PROGRESS | 0/2/3 | Version gate and force-update flow (record only) |
| [K-15](tracker/axis-k-flutter-seller-app.md) | IN PROGRESS | 1/3/2 | Tests and CI of the seller app |
| [K-16](tracker/axis-k-flutter-seller-app.md) | IN PROGRESS | 3/1/2 | Seller workflows keep working (orders, products, stock, POS, withdrawals, reviews) |
| [K-17](tracker/axis-k-flutter-seller-app.md) | IN PROGRESS | 1/1/4 | Backend contracts and decisions the seller app depends on |
| [K-18](tracker/axis-k-flutter-seller-app.md) | IN PROGRESS | 1/1/1 | Seller-app theme and API impact of per-country themes (GCC-09 audit) |
| [K-V1](tracker/axis-k-flutter-seller-app.md) | NOT STARTED | 0/0/1 | Seller app compatibility with the open backend PRs that change seller v3 answers but were not read i... |
| [K-V2](tracker/axis-k-flutter-seller-app.md) | NOT STARTED | 0/0/1 | Sensitive actions of a seller or seller staff (payout and bank details, staff management, account de... |
| [K-V3](tracker/axis-k-flutter-seller-app.md) | IMPLEMENTED IN DRAFT | 1/0/0 | A queued POS sale that has failed ten replays is never dropped silently: it stays visible with its l... |
| [K-V4](tracker/axis-k-flutter-seller-app.md) | NOT STARTED | 0/0/1 | Seller app behaviour when a market is paused: maintenance or pause of one market keeps order handlin... |
| [K-V5](tracker/axis-k-flutter-seller-app.md) | NOT STARTED | 0/0/1 | The seller version gate can be switched off per platform without a release (the published status hon... |

### L. GCC-20 Super Admin

| Id | State | M/C/X | Requirement |
|---|---|---|---|
| [L-01](tracker/axis-l-gcc-20-super-admin.md) | IMPLEMENTED IN DRAFT | 4/1/0 | Separate platform principal and dedicated admin API |
| [L-02](tracker/axis-l-gcc-20-super-admin.md) | IMPLEMENTED IN DRAFT | 5/1/0 | Second factor (MFA) for the platform principal |
| [L-03](tracker/axis-l-gcc-20-super-admin.md) | IMPLEMENTED IN DRAFT | 4/1/0 | Short-lived sessions, device list and remote revocation |
| [L-04](tracker/axis-l-gcc-20-super-admin.md) | IMPLEMENTED IN DRAFT | 4/2/0 | Tamper-evident account audit trail |
| [L-05](tracker/axis-l-gcc-20-super-admin.md) | IN PROGRESS | 5/0/1 | Authorization matrix: nobody else reaches the admin API, the platform token reaches nothing else |
| [L-06](tracker/axis-l-gcc-20-super-admin.md) | IMPLEMENTED IN DRAFT | 0/4/0 | Seller-app shell: separate navigation and state per principal |
| [L-07](tracker/axis-l-gcc-20-super-admin.md) | IN PROGRESS | 3/2/1 | All-markets overview: market state, orders, returns, stock health |
| [L-08](tracker/axis-l-gcc-20-super-admin.md) | IN PROGRESS | 2/1/2 | Money by legal entity and currency, never summed |
| [L-09](tracker/axis-l-gcc-20-super-admin.md) | NOT STARTED | 0/0/4 | Operational health: payments, integrations, staff issues and approvals |
| [L-10](tracker/axis-l-gcc-20-super-admin.md) | IN PROGRESS | 4/1/1 | Global alerts with deep links, dedup and privacy |
| [L-11](tracker/axis-l-gcc-20-super-admin.md) | IN PROGRESS | 2/2/1 | Market selector, propagation and cache isolation |
| [L-12](tracker/axis-l-gcc-20-super-admin.md) | IMPLEMENTED IN DRAFT | 4/1/0 | Country-scoped permissions for country managers |
| [L-13](tracker/axis-l-gcc-20-super-admin.md) | IN PROGRESS | 1/0/5 | Country drill-down content and storefront activation state |
| [L-14](tracker/axis-l-gcc-20-super-admin.md) | NOT STARTED | 0/0/4 | Reports and exports per market |
| [L-15](tracker/axis-l-gcc-20-super-admin.md) | NOT STARTED | 0/0/5 | Levels 2 and 3: write actions, step-up and approvals |
| [L-16](tracker/axis-l-gcc-20-super-admin.md) | NOT STARTED | 0/0/4 | Admin push notifications on their own topics |
| [L-17](tracker/axis-l-gcc-20-super-admin.md) | IN PROGRESS | 3/1/2 | Safe rollout: flag, rollback, version gate, Saudi and three-repo regression |
| [L-18](tracker/axis-l-gcc-20-super-admin.md) | IN PROGRESS | 4/1/1 | Bounded performance and visible data freshness |

### M. Security, tests and deployment

| Id | State | M/C/X | Requirement |
|---|---|---|---|
| [M1-01](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 1/4/2 | Branch and PR ledger, CI read-only, truthful production claims |
| [M1-02](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 1/5/0 | Dependency and conflict map of all open PRs, and one safe merge order for main |
| [M1-03](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 5/0/1 | Combined integration of the security set and the GCC chain, tested on three engines |
| [M1-04](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 3/1/0 | Stage 0: open maintenance routes removed and one-time codes kept out of the log |
| [M1-05](tracker/axis-m-security-tests-and-deployment.md) | IMPLEMENTED IN DRAFT | 4/2/0 | Stage 1: account takeover closed for customer, delivery man, seller, website and app |
| [M1-06](tracker/axis-m-security-tests-and-deployment.md) | IMPLEMENTED IN DRAFT | 5/1/0 | Stage 2: sellers and customers act only on their own rows; hidden products stay hidden |
| [M1-07](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 5/0/1 | Ownership, payment-identity and withdraw fixes that have no release path (PRs 7, 16, 20-23, 27, 32-34) |
| [M1-08](tracker/axis-m-security-tests-and-deployment.md) | IMPLEMENTED IN DRAFT | 5/0/0 | Stage 3: the permissions the admin menus promise are enforced |
| [M1-09](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 5/0/1 | Stage 4: uploads and files cannot store or serve code or other people's files |
| [M1-10](tracker/axis-m-security-tests-and-deployment.md) | IMPLEMENTED IN DRAFT | 4/0/0 | Stage 5: unpublished business pages and blog posts are not public |
| [M1-11](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 5/0/1 | Deployment tooling: overlay build, drift check, apply, backup and rollback, and its CI job |
| [M1-12](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/6/0 | Security release plan and rollback plan: two routes, stages 0 to 5, runbook, smoke tests, what cannot be undone |
| [M1-13](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/5/3 | Review and approval gates: finance, security of GCC-20, native Arabic reader |
| [M1-14](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/1/3 | Staging environment with fake data for six countries |
| [M1-15](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/3/1 | Register of deferred risks DR-1 to DR-5: open, not remediated |
| [M1-16](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 1/1/4 | Protection of main and CI cost and reliability |
| [M1-17](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 4/1/1 | Saudi behaviour is unchanged while the GCC switches are off |
| [M1-18](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 1/4/1 | Engineering safety rules: classify, preserve, extend, isolate, verify; no direct SQL; no weakened protection |
| [M1-V1](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/0/1 | Every P0 finding of the Discovery-02 security triage (23 explicit P0 plus 4 conditional P0 of 222 SE... |
| [M1-V2](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/0/1 | A secret and personal-data scan gates every repository's pull requests: the triage's urgent item SEC... |
| [M2-01](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 4/0/1 | Saudi continuity: the Saudi baseline stays as it was (test plan 1) |
| [M2-02](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 4/0/1 | Isolation of the six countries (test plan 2) |
| [M2-03](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 4/1/1 | Staff permissions and country scopes (test plan 3) |
| [M2-04](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 4/0/2 | Unified customer account across countries (test plan 4) |
| [M2-05](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 4/0/2 | Carts, wallets and loyalty per storefront (test plan 5) |
| [M2-06](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 2/1/2 | Checkout in one store and one selling company (test plan 6) |
| [M2-07](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 2/1/2 | Independent prices and currencies (test plan 7) |
| [M2-08](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 3/0/3 | Concurrent stock reservation and sync (test plan 8) |
| [M2-09](tracker/axis-m-security-tests-and-deployment.md) | NOT STARTED | 0/0/4 | International shipping and product eligibility (test plan 9) |
| [M2-10](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 2/0/3 | Themes and Flutter caches (test plan 10) |
| [M2-11](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 3/0/2 | Payments, refunds and webhooks (test plan 11) |
| [M2-12](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 2/0/3 | Sales channels and stock sync (test plan 12) |
| [M2-13](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 2/1/2 | Notifications and legal policies (test plan 13) |
| [M2-14](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 3/1/1 | Stopping a store and handling its earlier orders (test plan 14) |
| [M2-15](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 4/0/2 | Six-country integration test matrix and CI gates |
| [M2-16](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/4/2 | Migration files and change discipline: Preserve, Extend, Isolate, Verify |
| [M2-17](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 1/3/2 | Historical Saudi data: ids preserved, backfill, reconciliation, staged upgrade, rollback |
| [M2-18](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 4/0/2 | Operational services: logs, queues, scheduler, caches, search, storage, metrics, sync, rollback |
| [M2-19](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/2/3 | Saudi assumptions, old links and integrations (axis L) |
| [M2-V1](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/0/1 | Every existing Laravel and Flutter feature is classified as Reuse As-Is, Extend Existing, Refactor C... |
| [M2-V2](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/0/1 | No quick production change and no direct SQL is used to settle an architectural problem: changes shi... |
| [M2-V3](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/0/1 | Global data and per-market data are told apart in the migration plan and in code: every table the pl... |
| [M2-V4](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/0/1 | The staged upgrade needs no downtime and no migration is created-and-run on live data: each step is ... |
| [M2-V5](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/0/1 | Storage and rollback capabilities (axis K bullets) are audited and tested for six markets: private d... |
| [M2-V6](tracker/axis-m-security-tests-and-deployment.md) | NOT STARTED | 0/0/1 | Stock balances (current_stock, holds, outbox, provider stock) and prices of existing products reconc... |
| [M3-01](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/3/1 | Report 01 Executive Assessment: state of the project and readiness for GCC, tagged Verified / Inferred / Unknown |
| [M3-02](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/3/1 | Report 02 Current Architecture Map: browser and Flutter to Laravel APIs, services, storage, integrations |
| [M3-03](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/2/1 | Report 03 Reuse Matrix: what exists, what to keep, what to extend, with file and SHA evidence |
| [M3-04](tracker/axis-m-security-tests-and-deployment.md) | IMPLEMENTED IN DRAFT | 0/4/0 | Report 04 GCC Data Ownership Matrix: owner, isolation level, impact, and the meanings of shop_id, seller_id, customer_id |
| [M3-05](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/2/2 | Report 05 Proposed Target Architecture: shared systems, independent data, alternatives and reasons |
| [M3-06](tracker/axis-m-security-tests-and-deployment.md) | IMPLEMENTED IN DRAFT | 0/3/0 | Report 06 Database Migration Strategy: document present (content measured in M2) |
| [M3-07](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/2/2 | Report 07 Integration and PR Dependency Map: ten integrations in five states, PR conflicts, merge order |
| [M3-08](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 1/2/2 | Report 08 Security and Isolation Threat Model: nine threat classes |
| [M3-09](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/3/2 | Report 09 Proposed Implementation Phases: small phases with scope, files, dependencies, risks, acceptance tests, rollbac |
| [M3-10](tracker/axis-m-security-tests-and-deployment.md) | IMPLEMENTED IN DRAFT | 0/3/0 | Report 10 Regression and Test Plan: document present (content measured in M2) |
| [M3-11](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/4/1 | Report 11 Risks, Blockers and Unresolved Questions: P0/P1/P2, approved decisions not reopened |
| [M3-12](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/2/2 | Report 12 Final Go / No-Go Recommendation: the seven answers and the state today |
| [M3-13](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/4/1 | Quality rule: conclusions carry evidence (file, class or function, branch or SHA, line); reports in Arabic, not essays |
| [M3-14](tracker/axis-m-security-tests-and-deployment.md) | IMPLEMENTED IN DRAFT | 0/4/0 | Quality rule: existing, planned and open-branch code are never confused with production; green CI is not proof of a live |
| [M3-15](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/3/1 | Quality rule: what could not be inspected is stated as a coverage gap |
| [M3-16](tracker/axis-m-security-tests-and-deployment.md) | IMPLEMENTED IN DRAFT | 1/4/0 | Quality rule: preserve existing work; no radical change before impact analysis on web, apps and current sales |
| [M3-17](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 0/5/0 | Discovery stop conditions (end of task) are superseded by the owner charter; what the charter keeps |
| [M3-18](tracker/axis-m-security-tests-and-deployment.md) | IN PROGRESS | 1/4/1 | CI state of the open branches is read per head SHA, without re-running |

## 8. Method, verification and what was not inspected

Nineteen assessors (one per unit of the plan) read the original plan lines, `origin/main`, the draft pull request heads and the two CI-only integration trees, and wrote requirements with evidence triples. A second, independent verifier per unit then tried to refute every state, test claim and evidence line, and listed requirements the assessor missed. State refutations and criterion changes are applied; text corrections whose replacement is an instruction rather than a sentence are shown on the card as a verifier note and not applied. Units still waiting for their verifier are marked in the dashboard.

| Unit | Axis | Requirements | Verified | State or criterion changes applied | Requirements added by the verifier |
|---|---|---:|---|---:|---:|
| A1 | A | 20 | yes | 6 | 2 |
| A2 | A | 17 | yes | 5 | 2 |
| B | B | 18 | yes | 6 | 0 |
| C | C | 18 | yes | 2 | 0 |
| D1 | D | 17 | yes | 13 | 0 |
| D2 | D | 20 | yes | 7 | 2 |
| E | E | 18 | yes | 3 | 1 |
| F | F | 16 | yes | 6 | 1 |
| G | G | 20 | yes | 3 | 2 |
| H1 | H | 14 | yes | 3 | 0 |
| H2 | H | 20 | yes | 5 | 2 |
| I1 | I | 18 | yes | 3 | 0 |
| I2 | I | 19 | yes | 7 | 1 |
| J | J | 20 | yes | 7 | 2 |
| K | K | 23 | yes | 2 | 5 |
| L | L | 18 | yes | 2 | 0 |
| M1 | M | 20 | yes | 7 | 2 |
| M2 | M | 25 | yes | 6 | 6 |
| M3 | M | 18 | yes | 7 | 0 |

Coverage gaps (what no unit could inspect) are listed in [`tracker/coverage-gaps.md`](tracker/coverage-gaps.md). The common ones: production was not inspected, so no live setting is claimed; provider contracts, regulatory facts and customs rules are facts outside the repositories; CI logs are read per head, not re-run.

## 9. State of GitHub this was measured against

- `fullstackfull/lavkoo`: 96 open draft pull requests, none merged; `main` is `c5c04b7b` (2026-11-12). `fullstackfull/lavkooapp`: 9 open drafts. `fullstackfull/lavkooappseller`: 6 open drafts. [PR_LEDGER.md](PR_LEDGER.md) lists every pull request of `lavkoo` with its branch, base and head.
- CI-only integration trees (never merged): the security set `gcc/release/security-integration` (PR 75, `47b78ab8`); the GCC stack `gcc/release/gcc-integration-ci` (PR 83, `8ff34109`); both together `gcc/release/security-gcc-integration-ci` (`f6fb03af`); and every main-based open pull request together, `gcc/release/all-integration-ci` (head `1365cdad`, which merges the later drafts 87 to 96; the four main-based pull requests not in it are the baseline hotfix line below). Its run 293 (on the earlier head `8598331b`, which already held PR 88 to 94 and the real-engine group of CI) is the latest proof of the tree: it is read per job in `audits/2026-11-18-all-in-one-integration/README.md`.
- The baseline hotfix line (cut from the server baseline `698aa1e`, deployed in stages): PR 78 `gcc/release/security-hotfix` (`fafa1a2e`), PR 81 `gcc/release/security-hotfix-ci` (`0f512114`), PR 85 `gcc/release/security-hotfix-stage0` (`b83fd189`), PR 86 `gcc/release/security-hotfix-ci-mariadb` (`49e170fe`).
- GCC-05, the currency of a storefront: PR 87 `gcc/p1-storefront-currency` (`44b53616`), slices 1 to 5 (the amounts a storefront compares with, its shipping, the panel order edit, the coupons of a storefront, the readiness lines, the minimum-order gate proof and the closed wallet), stacked on PR 64.
- Drafts of 2026-11-18, all dormant, tests or documents: lavkoo PR 88 `wallet:reconcile` (`b5a3b5d1`), PR 89 three statements a strict SQL server refuses (`ea6827da`), PR 90 the selling-company registry, the order stamp and the invoice that prints it (`26995055`, stacked on 87), PR 91 the branch protection rule and CODEOWNERS (`faa06167`, documents only), PR 92 every test starts from an empty database on a real server (`cdf825b2`), PR 93 the per-order debug file removed (`5e128f06`), PR 94 every test directory runs on a real engine or says why not (`a0289e75`), PR 95 the writers of the wallet and loyalty balances are guarded by a source scan (`ce4d2950`), PR 96 six-country isolation for every ordered pair and one customer across the storefronts (`6f55384e`, stacked on 87); lavkooapp #8 the price formatter of a storefront with its own currency (`97399d4f`, stacked on #7) and #9 the code and symbol the app sends and shows (`45bfed30`, stacked on #8).
- Documents: branch `claude/exciting-shannon-4mh2ok` (`docs/gcc/`), including this tracker, `STAGING_PLAN.md` and the audit of the all-in-one integration.

## 10. Changes since the assessors read the trees

- GCC-05 is **PR 87**. The assessors read the branch `gcc/p1-storefront-currency` at slice 3 (`25f83fe5`) when it had no pull request, so cards that say "no pull request" for it are out of date, and slices 4 and 5 are not in their reading. They touch B-02, B-03, D1-05, D1-06, D2-05, D2-10, E-01, H1-08, H1-14, I1-12 and I1-13; the verifiers of D1, D2 and H1 corrected the cards they read, and the others carry an "Update after the assessment" where it matters.
- The CI runs of the hotfix line were read per head: PR 78 (`fafa1a2e`): both SQLite suites, syntax checks and the secret scan green, the MySQL job cancelled by the baseline workflow's time limit, the overlay job red by design; PR 81 (`0f512114`, 45-minute limit): all test jobs green including MySQL 8; PR 86 (`49e170fe`): all test jobs green including MySQL 8 and MariaDB 11.4.2. In all three the overlay job refuses the release because 17 files lie outside the directories an overlay carries (`.htaccess`, three `Modules/*` areas and two public scripts); they are deployed by hand deliberately and listed in the release plan.
- The all-in-one integration branch `gcc/release/all-integration-ci` was built after the assessment. Of its 35 first merges, 15 conflicted (32 file instances, 28 files), all resolved in the branch only; a set of tests and one guarded document pinned behaviour that a sibling pull request changes on purpose, and each is reconciled and listed in [`audits/2026-11-18-all-in-one-integration/README.md`](audits/2026-11-18-all-in-one-integration/README.md). It found one real defect of the security set (a private digital file row read without its id raised a TypeError when serialized; fixed on PR 69 in `4096f1be`, still to be taken into stage 4 of the hotfix line). Run 279 (`c30390d1`) was read per job and showed three more things, all now in drafts of their own: test classes that only ever ran on SQLite collide on a real server (PR 92), two statements an ONLY_FULL_GROUP_BY server refuses in the coupon use count and the checkout cart group (PR 89, a defect of `main` itself), and two characterization tests of unfixed behaviour. The reconciled head `515d5bfa` runs as run 288, which is not read yet, so the criteria that rest on "a tree contains both sets and is green" (M1-03, M2-15) stay MISSING.
- Cards changed after the assessment (they show an "Update after the assessment"): E-01 (the wallet closed on a storefront with its own currency), E-03 (PR 95), E-06 (PR 88, `wallet:reconcile`, CI read in run 285), D1-03 and D1-04 (PR 90, the selling-company registry, the order stamp and the invoice), D1-05 and D1-06 (PR 87), I1-12 and I1-13 (the coupons of a storefront, PR 87), J-05 (lavkooapp #8 and #9, green in the app repository's own CI), M2-02 and M2-04 (PR 96), A2-15 (PR 93), M1-03, M1-07, M1-14 (the staging plan), M1-16 (PR 91) and M2-15.
