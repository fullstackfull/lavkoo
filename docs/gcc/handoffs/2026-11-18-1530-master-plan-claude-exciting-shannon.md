# Handoff: five slices built, one criterion met in CI, a person must now open a country, and the second integration head (2026-11-18, late afternoon)

Sanitized. Nothing here is a secret or personal data. Every pull request is a **Draft**. None is merged, none is deployed, no production data or key was touched, G0 stays open, and the deferred risks DR-1 to DR-5 are still open (no key was rotated). This continues [the handoff of 14:00](2026-11-18-1400-master-plan-claude-exciting-shannon.md).

## What moved since 14:00 (measured on the criteria, not on the pull request count)

Against the tracker published at `f2fa60d3` (1,675 criteria: 665 MET, 362 CODED, 648 MISSING) the regenerated tracker has **1,675 criteria: 666 MET, 394 CODED, 615 MISSING** (implemented 63.3%, proven by a passing test 39.8%, release readiness 7.5%).

| Movement | Count | Which |
|---|---:|---|
| MISSING to CODED, a whole criterion | 32 | F-01 criteria 0 to 3 (#107); F-02 criteria 0 and 3 (#112); I2-04 criteria 0 to 5 (#110); I2-11 criteria 2 and 3, I2-12 criteria 1, 3 and 4, I2-13 criteria 3 and 4, I2-14 criteria 1 to 4, I2-15 criterion 4, I2-16 criterion 4, I2-17 criterion 5 (#111); E-05 criteria 1, 2 and 5 (#109); K-04 criterion 0, K-08 criterion 3 and K-17 criterion 1 (#108) |
| MISSING to MET | 1 | J-17 criterion 3: the update gate is pinned by tests and `release_version_test.dart` exists (lavkooapp #14, app CI run 20 on `49b63de`, read job by job) |
| CODED to MET | 0 | the criteria that wait for a green job wait for the integration run |
| Partly moved, still MISSING | 3 | I2-11 criterion 4 (audited, not permission-gated), I2-12 criterion 2 (four of the six checks), I2-15 criterion 1 (no web redirect without country paths) |

No whole requirement became READY FOR OWNER REVIEW: every one still has criteria that are CODED but not proven on a green head, or MISSING, and no reviewer is named.

## What was built

- **#107, one active theme per storefront (`62b8e4ad`, stacked on #87).** `themes.storefront_country` and one `StorefrontThemeResolver` that fourteen readers ask; activating one storefront's theme no longer deactivates another's; the admin list shows what each storefront serves. Off by default (`GCC_STOREFRONT_THEMES`). Built by a delegated agent, then read in full and run again here.
- **#112, publish per storefront, slice 1 (`5afda458`, stacked on #107).** A publish, an activation or a restore of one storefront's theme touches that storefront only: another storefront's published version, revision and checksum stay as they are, only the cached pages of the theme's own storefront are forgotten (every storefront's for the default storefront's theme), and the audit lines name the storefront. Written here; ten tests and four mutations. The one FCM topic that wakes every app is not changed.
- **#108, the seller market contract (`11a9707f`, stacked on #87).** `GET api/v3/seller/seller-center/security/me` (who is signed in: owner or staff, role, permissions, markets), and the seller order list and detail carry `storefront_country` and `currency_code` with a market filter. Off by default. Built by an agent, read and run again here; one test of it was made independent of the seller scoping of #20.
- **#109, ledger attribution (`bb55ab83`, stacked on #90).** Thirteen nullable columns on the wallet and loyalty ledgers say what stands behind a row, who made a manual top-up and which market it was made in; recording only, dormant until its migration has run. **Awaits the finance review** ([R6](../reviews/R6-legal-finance-gcc-money.md)). Not in the integration (15 files conflict with the wallet pull requests, listed in [MERGE_ORDER.md](../MERGE_ORDER.md) section 8).
- **#110, legal pages per storefront (`13eda6f1`, stacked on #87).** A storefront other than the Saudi one shows its own current legal text in the visitor's language, or nothing, on the website and in the page list of the apps; the agreement links name the storefront. Nothing writes the texts yet (I2-03).
- **#111, the lifecycle of a storefront (`d8f6a7ea`, stacked on #105).** A storefront is a draft, ready, active or paused (browse only, maintenance, redirect), kept in the database and audited; **only an active storefront sells**; `gcc:storefront:state` reports what each one still needs and exits 1 while blocked. Written here, not by an agent. Details and limits: [I2-11-STORE-LIFECYCLE.md](../I2-11-STORE-LIFECYCLE.md).
- **lavkooapp #14 (`49b63de`).** The update gate is a class of its own and is tested.
- **Repair on #82 (`8a7e7baf`, test only).** The union run showed one case failing on MariaDB because tied rows came back in another order; the test now compares records as a set.

## CI read since 14:00 (per job; details in the [audit](../audits/2026-11-18-all-in-one-integration/README.md))

- **Run 314 (#98, `718edb99`) finished: 20 of 21 jobs green**, the overlay job red by design. That is the proof of D1-07's first two criteria.
- **lavkooapp run 20 (`49b63de`): both jobs green** (analyze of the changed files, the two errors-only analyses, both test steps, the whole-project analysis).
- **Run 334 (the integration, `42c071bb`), read at 15:44 and then cancelled by hand: 25 jobs green, one red** (`Staff scope tests, MariaDB`, a tie in the sort order, repaired on #82), **one cancelled by its own ceiling** (`Tests, SQLite (PHP 8.3)` at 45 minutes 16 seconds: the ceiling is raised to 110 in the integration workflow; the same raise is needed in the workflow that is merged), 3 running when cancelled. **Run 342 (id 44338693813, `a5f7de59`) replaces it** and holds #82's repair, #107, #108, #110 and #111.
- The 14 runs cancelled by hand at 14:13 (to unblock the queue) are still cancelled; re-run them once the integration run is read.

## Findings for the owner

1. **A country can no longer sell just because its documents are in place**: it needs a person to say it is ready and to approve it with a reference (#111). Decide who that person is and whether two are needed.
2. **The order model does not hide the three snapshot columns** (`storefront_country`, `currency_code`, `currency_rate`): once the orders migration of #87 has run, every API that serialises an order carries them (null for old orders) even with every switch off (#108, finding 1). One line on the model restores the old answers.
3. **The seller security desk's seven write endpoints answer 500 on `main`** (two missing imports; the security set fixes it) (#108, finding 2).
4. **Nothing writes the legal texts** of a storefront: #110 shows what is recorded and the table is filled by hand until I2-03 exists.
5. Still open from before: the refund policy across exchange rates, the included-tax amount, the three wallet decisions (E-01, E-02, E-07), the selling company of each country.

## The three biggest gaps to a complete six-country system (from the tracker's section 3)

1. **The money of a storefront is not end to end**: the refund flow, the included tax, the invoice currency, the gateway accounts. Unchanged by this batch.
2. **Wallet and loyalty are still one global balance** (E-01 to E-07, J-09). #109 is the first step: a ledger row now names its market, currency and company once its migration runs; the balances are still one number per customer.
3. **There is no selling company in the data** beyond the registry of #90, and nobody can yet run a country from the admin panel: the lifecycle of #111 is a command, with no screen, permission or step-up (GCC-20).

Behind them: the two Flutter apps (K and J: 77 criteria MISSING), the per-market notification, consent and marketing profiles (I1: 48), per-storefront publish and brand (F: 32), and the legal publishing workflow and the store lifecycle's admin screen (I2: 24).

## Things the next session must know

1. A test world that makes a storefront sell must also activate it: `activateStorefrontFor()` in `tests/Support/ActivatesStorefront.php`, already called by `publishRequiredLegalDocuments`, `leaveTheLegalTextsOutOfThisTest`, `BuildsStorefrontCart` and `BuildsSixStorefrontCheckoutWorld`. Two tests of #90 and #101 need the same call when #111 is merged after them (done in the integration).
2. The integration head after this handoff (`a5f7de59`, run 342) holds #82's repair, #107, #108, #110 and #111, and **not #109**. `StorefrontPreflight` there has five checks (the company is the fifth). The SQLite job of the workflow that gets merged needs a higher ceiling than 45 minutes too (run 334 hit it).
3. A test that compares two lists from a real engine must not depend on the order of rows with equal sort keys (the repair on #82 shows how).
4. `translate()` needs `business_settings`; a test that renders a page or an answer with a translated text must build it. A web error page needs variables the test world does not share, so a middleware that refuses a request answers JSON.
5. The tracker generator and the ledger check are in `docs/gcc/tools/`; the check must exit 0 before a push of this branch.

## Next, in order

1. Read the second integration run per job when it finishes; fix a red only on the pull request that owns it; flip the CODED criteria its green jobs prove, per head.
2. Re-run the cancelled per-pull-request runs once the queue is empty, and read each per job.
3. Resolve #109 into the integration after the finance review has read the ledger columns; then E-05 criteria 3 and 4 as drafts awaiting finance review.
4. The next missing criterion of each axis: the admin screen and permission for the lifecycle (I2-11 criterion 4, GCC-20), seller markets on the principal (K-09, K-17 criterion 0), publish per storefront (F-02), the publishing workflow of the legal texts (I2-03).
5. Name the reviewers: the owner is asked once, in the final message of the session, which person answers R1 to R6.
