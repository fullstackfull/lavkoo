# Lavkoo GCC — Current Project Status & Handoff Index

**Last update:** 2026-11-18, evening. **Current phase:** autonomous implementation under the owner's charters: continuous development and release preparation on draft pull requests, with nothing merged, deployed or run on production data. The "Discovery preparation" text of 2026-11-12 further down is kept as history and is **superseded**: GCC application code now exists on 115 draft pull requests of `lavkoo` (and 18 of `lavkooapp`, 10 of `lavkooappseller`), all dormant behind switches that ship off.

## Read these first

| What | Where |
|---|---|
| Every requirement of the plan (axes A to M), its state, the tests that passed, what is missing, and the percentages with their formulas | [MASTER_IMPLEMENTATION_TRACKER.md](MASTER_IMPLEMENTATION_TRACKER.md) and `tracker/` |
| Every pull request with its branch, base, head and the requirements that name it; `tools/check_pr_ledger.py` says when it is out of date | [PR_LEDGER.md](PR_LEDGER.md) |
| The order in which the pull requests can merge, their conflicts and the files that need a hand deployment | [MERGE_ORDER.md](MERGE_ORDER.md) |
| The deferred risks DR-1 to DR-5 (keys and credentials are **not** rotated and the register says so) | [DEFERRED_RISKS.md](DEFERRED_RISKS.md) |
| The security release in stages for the live server | [SECURITY_RELEASE_PLAN.md](SECURITY_RELEASE_PLAN.md), [SERVER_PREFLIGHT.md](SERVER_PREFLIGHT.md), [OWNER_PR_GUIDE.md](OWNER_PR_GUIDE.md) |
| The staging plan with invented data for six countries and the journeys to run | [STAGING_PLAN.md](STAGING_PLAN.md) |
| The reviews a change needs before it merges (finance, security, native Arabic, legal), who reads what, and where the outcome is written; the Arabic lines the pull requests add | [REVIEW_GATES.md](REVIEW_GATES.md), [ARABIC_TEXT_FOR_REVIEW.md](ARABIC_TEXT_FOR_REVIEW.md) |
| The audit of the all-in-one integration branch (CI per job, what was reconciled) | [audits/2026-11-18-all-in-one-integration/README.md](audits/2026-11-18-all-in-one-integration/README.md) |
| The tracker generator (it holds the evidence of every criterion) and its data, with the rules for editing it | [tools/tracker-generator/README.md](tools/tracker-generator/README.md) |
| The latest handoff of each session | [handoffs/](handoffs/) |

## What is true today

- **Nothing is merged, deployed or verified in production.** `main` is `c5c04b7b` (2026-11-12) and has none of the GCC work; the live server runs an older baseline described by the release documents. A merge, a deployment, a key change, a migration on production or a change of a live financial or legal rule needs the owner's separate approval (charter section 4).
- **The all-in-one integration (a CI-only branch, never merged) is green.** Run 293 on `8598331b` finished success: 28 of 29 jobs on SQLite, MySQL 8.0 and MariaDB 11.4.2 and the strict-mode job, one skipped by design, read job by job ([audit](audits/2026-11-18-all-in-one-integration/README.md)). The integration has since been rebuilt (`706ac87c`: PR 87 to 104, the later GCC slices, the tax, refund and baseline pull requests) and run 324 is dispatched on it; run 319, on the head before, was cancelled before it could be read. The pull requests' own runs since 293 (302, 307, 308, 310) are in the audit with the three repairs they led to.
- **Since run 293 the integration was rebuilt and re-dispatched.** Run 334 (`42c071bb`, PR 87 to 106) was cancelled by hand at 15:42 UTC after 25 jobs finished green, one red on MariaDB (a test that compared tied rows in the engine's order; repaired on #82) and one cancelled by the SQLite job's own 45-minute ceiling (raised to 110 in the integration workflow). **Run 342 (id 44338693813, head `a5f7de59`) replaces it** and holds that repair, #107, #108, #110 and #111, but not #109 (ledger attribution), which conflicts with the wallet pull requests and awaits the finance review. **Run 342 finished: 30 jobs success and 2 skipped by design, read job by job.** The integration branch was saved at `772f1c36` and now also holds #112, #113, #114 and #115 and the stop-journey test; **no run has been dispatched on it** (about 30 jobs; the account runs about 20 jobs at once across the three repositories). The pull requests of the afternoon are #107 to #111 and lavkooapp #14 (see [the handoff of 15:30](handoffs/2026-11-18-1530-master-plan-claude-exciting-shannon.md)). A store now needs a person to open it (the lifecycle of #111).
- **Midnight of 2026-11-18, 23:59 UTC:** the runs of lavkoo #111 (run 355, `7e19d284`) and #96 (run 356, `e624e43e`) were read per job: 'Tests, SQLite' on PHP 8.2 and 8.3 (the whole suite), 'Storefront tests' on MySQL 8 and MariaDB 11.4.2 (941 and 852 tests, none skipped) are success; the deployment overlay is red by design. They met **five criteria**: one customer signs in through the API and the website from each of the six storefronts with one users row (M2-04.0, `StorefrontCustomerSignInTest`), a Kuwaiti coupon is applied and redeemed in dinars through the real checkout (I1-12.3, `StorefrontCouponRedemptionTest`), earlier orders and the notices of their gateways and couriers are served in every stop mode (I2-16.2 and I2-16.3, `StorefrontStopContinuityTest`, 65 tests), and the update gate is pinned for a switch that is not on or a minimum that is blank (J-17.1, lavkooapp #14, run 20). I2-16.4 stays CODED (a refund decision, a payout, a courier submission and a settlement run are not driven while a storefront is stopped) and M2-14.4 moves from MISSING to CODED. The review fixes of #114 (`c440a9f1`) and #115 (`d5755860`) and the theme pins of #112 (`eaa04e2f`, 316 new tests) are pushed; their runs are queued and not claimed. The tracker has **1,675 criteria: 762 MET, 327 CODED, 586 MISSING**. Four more waves of pins and slices (seller money, outbox owner and market selector; the coupon, wallet, stop-mode and legal-store pins; the customer app and legacy backend pins; the order-writer list) are being implemented and reviewed, none is pushed. **No approval was recorded by Claude or by CI.**
- **Night of 2026-11-18, 21:15 UTC** ([handoff](handoffs/2026-11-18-2115-seller-integration-run-and-gap-closing-claude-exciting-shannon.md)): the seller app has an integration branch (`claude/seller-all-integration-ci`, `f695f8c`, the heads of lavkooappseller #1 to #10) that runs each feature's tests as a named step; run 29 was read per step (every named step success, the full run red only for the known version gate test), which met **twenty seller criteria** (K-01, K-02, K-05, K-07, K-10, K-12, K-13, K-16 and K-05.4, a scan that finds nothing now tells the seller), and a burst of 401s signing the seller out once is pinned by its own pull request (lavkooappseller #11, run 31: K-12.1). The tracker has **1,675 criteria: 757 MET, 331 CODED, 587 MISSING** (implemented 65.0%, proven 45.2%, release 7.7%). The stop-journey tests of an order placed before a stop (#111, 57 tests) are pushed and their run is not read yet. **No approval was recorded by Claude or by CI.**
- **Night of 2026-11-18, 19:40 UTC (numbers superseded by the line above)** ([handoff](handoffs/2026-11-18-1940-flips-audit-and-fixes-claude-exciting-shannon.md)): an independent audit of the 76 criteria that moved (45 supported, 31 flagged) sent **7 criteria back** (I2-16.4, I2-07.2, I1-12.3, M2-04.0 and F-02.3 to CODED; J-08.5 and K-07.4 until fixed, and both are met again), rewrote the evidence of 24 more so that it says only what ran (the strict-mode job does not run the Storefront and Address tests; the admin and vendor POS do not stamp orders), and found a live seller-app screen that still averaged unapproved reviews (fixed). An independent review found a **high defect in the seller POS** (a refused sale emptied the priced cart, so the retry recorded an order of no amount); it is fixed with behaviour tests, together with the queue of unsent sales, the sheet and the banner, a push that opened a screen for a signed-out seller, and the wallet reload of the customer app (lavkooappseller #8, #9, #10, lavkooapp #18; CI read per step). The tracker has **1,675 criteria: 736 MET, 350 CODED, 589 MISSING** (implemented 64.8%, proven 43.9%, release 7.7%): five criteria closed on new proof (K-06.1, K-06.3, E-11.4, K-V3.0, E-05.2), five reopened by the audit and still open. **No approval was recorded by Claude or by CI.**
- **Evening of 2026-11-18 (numbers superseded by the line above)** ([handoff](handoffs/2026-11-18-1830-master-plan-claude-exciting-shannon.md)): the tracker has **1,675 criteria: 736 MET, 350 CODED, 589 MISSING** (implemented 64.8%, proven 43.9%, release 7.7%); 70 criteria moved since the 15:30 handoff and 71 since the first tracker of the day. **A country can now be opened from the admin panel** (#115: three permissions, the signed-in admin as the person; no step-up and no scope by country yet), the legal texts have one audited writer (#114, which also fixes a UTC time-zone defect), and the seller app has seven fixes in drafts (#7 to #10: no theme call, the key of a sale, who a sale is for, a 200 refusal, camera formats, sales that are never dropped, push taps in any language, approved-only ratings, CI for stacked pull requests), and the customer app drops the wallet and points of a storefront the customer leaves (lavkooapp #18). No whole requirement is ready for owner review: no reviewer is named, and **no approval was recorded by Claude or by CI**.
- Every pull request is a draft. A percentage is shown only where acceptance criteria that a test could assert were counted; "implemented in a draft" is not "done" and "code complete" is not "released" (see section 4 of the tracker).
- The CI of a head is read per job, never inferred: see the tracker and the audit reports, and the checks page of each pull request. Runs are queued and are sometimes cancelled by a newer push to the same pull request.
- The earlier statement that **no application code is approved** is superseded for the draft pull requests above; the coupon-boundary branch has its own narrower approval (`PHASE0-COUPON-GTE-SELLER-VERSION-OWNER-APPROVAL.md`).

---

# History: planning status of 2026-11-12 (superseded)

**Planning update of 2026-11-12.** At that date the phase was discovery preparation and no GCC application code existed; that is no longer true (see above).

## Current canonical repositories

- Backend/web/admin: https://github.com/fullstackfull/lavkoo
- Flutter customer: https://github.com/fullstackfull/lavkooapp
- Flutter seller: https://github.com/fullstackfull/lavkooappseller

Product/repository rename: previous project referred to as PharmacyKSA GCC. The user says application files/code are the same overall but names/repos changed. Do **not** automatically rewrite internal Dart package names, API endpoint values, application IDs, bundle IDs, Firebase config, release versions or legacy string constants. Verify each current setting first.

## Confirmed planning inputs

- 6 countries: SA, AE, KW, QA, BH, OM, independent enablement.
- Saudi first-launch default, admin-configurable thereafter.
- Unified category tree and brand registry; independently owned products/stock/prices; shared core attributes with local extensions.
- Country-isolated theme drafts and publication; reusable components; shared branding with store overrides; web and Flutter customer display customization.
- One fulfillment source per product/store; local and eligible cross-border delivery without shared inventory.
- One customer login; local cart/order/wallet, local loyalty; staff roles scoped by store/legal entity.
- Each country requires approved legal policies to start selling.
- Central campaigns with per-store coupons; separate merchant accounts and entity financial ledgers.
- A checkout belongs to one storefront and one selling legal entity (provisionally adopted, compatibility audit required).
- Flexible store pause; don't break historic webhooks, orders or refunds.
- No automatic country price conversion.

See [DECISIONS.md](./DECISIONS.md) for authoritative details.

## Verified narrow repository checks

- All three current repositories were accessible to the GitHub connector on 2026-11-12. They use branch `main`.
- `lavkoo` has `CLAUDE.md`, Laravel code, admin/API routes and existing docs; `lavkooapp` and `lavkooappseller` have Flutter source.
- The previous and current Laravel `app/Services/Theme/ThemeManager.php` matched in a spot-check; previous/current customer Flutter dynamic theme controller matched in a spot-check. **This is not a full equivalence audit.**
- The legacy repository `pharmacyksa98` PR #12–#16 must be investigated; those numbers are **not PRs #12–#16 in `lavkoo`**. Earlier reported states: #9–#11 merged in legacy; #12–#16 open, with #16 based on `claude/integration-cdef`. Recheck later; do not assume inclusion in new repo.
- Seller Flutter application is a third codebase requiring API/permissions, multi-country seller listings, seller finance and app version/force-update audit. Its current `pubspec.yaml` internally declares `syriacosmeticsmanger`; customer internally declares `syriapharmacy`; do not rename without a separate migration plan.

## Top unresolved engineering risks

1. Country context in APIs, queues, jobs, cache and theme publishing.
2. Financial separation: legacy customer wallet and loyalty balances, refunds and legal entity attribution.
3. Historic Saudi IDs/order snapshots, marketplace outbox and inventory reservations.
4. Existing seller/multi-vendor and seller-app workflows vs single-entity checkout.
5. Public/private repo name transitions and old PR ancestry not confirmed.
6. Cross-border product legality, merchant eligibility, taxation and shipment coverage.
7. One active theme/platform assumption and API compatibility across **both** Flutter apps.

## Next agent task

Read `README.md`, `DECISIONS.md`, `COLLABORATION.md`, and `DISCOVERY_PROMPT_01.md`. Run **read-only static discovery** across all three current repositories. Store only audit reports/hand-offs as Markdown Git commits and immediately push; **no application edits and no deploys**.

## Handoffs

Place new handoffs in `docs/gcc/handoffs/YYYY-MM-DD-HHMM-<agent-or-session>-<slug>.md`. Never overwrite someone else's handoff or its evidence. Link the new file in a short changelog commit and mention the commit SHA and branch when reporting.

Update this STATUS file only after fetching latest `main`, preserving concurrent work; never force-push.

- **2026-11-12 Discovery-01** (`claude/exciting-shannon-4mh2ok`, AUDIT ONLY): [handoff](./handoffs/2026-11-12-0115-discovery-01-claude-exciting-shannon.md) · [audit index](./audits/2026-11-12-0115-discovery-01/README.md). Phase 0 is Go with conditions; implementation phases are No-Go until G0 closes. GCC-20 not yet evaluated.
- **2026-11-12 Discovery-02 follow-up** (`claude/exciting-shannon-4mh2ok`, AUDIT ONLY): [handoff](./handoffs/2026-11-12-1240-discovery-02-followup-claude-exciting-shannon.md) · [audit index](./audits/2026-11-12-1240-discovery-02-followup/README.md). Phase 0 remains NOT authorised; implementation phases No-Go; seller version gate and CI flagged for owner decision; Q-30 open.
- **2026-11-12 Phase 0 D-01(B) limited** (`claude/exciting-shannon-4mh2ok`, READ/DOCUMENT ONLY): [handoff](./handoffs/2026-11-12-1419-phase0-d01b-claude-exciting-shannon.md) · [index](./audits/2026-11-12-1419-phase0-d01b-limited/README.md). P0-A, P0-C and P0-E documented, NOT closed; every isolated measurement BLOCKED (no authorised copy supplied); pharmacyksa98 access refused; G0 not closed; Phase 1-17 No-Go.

## Open questions for audit

- Exact branch/commit relationship between legacy and renamed repositories.
- Which end-user and seller app versions/builds are currently deployed, if observable without production access.
- Whether seller app shares sufficient auth, scope, themes and multi-country context; no assumption of parity.
- Multi-seller checkout internals and the impact of the provisional one-legal-entity checkout.
- Scope strategy for global categories/brands/local attribute overrides.

## 2026-11-12 — GCC-20 new owner request

The project owner added a **global Super Admin dashboard inside the existing Flutter seller application** for owner-level monitoring and country-specific oversight/actions. This is a distinct platform-admin mode, not seller account impersonation or an expanded seller token. Scope recorded in [GCC-20-SUPER-ADMIN-MOBILE.md](./GCC-20-SUPER-ADMIN-MOBILE.md); add platform admin mobile auth, country-level KPIs, country drill-down, safety approvals, notification isolation and regression/authorization tests to Discovery. **No application code changes authorized.**
