# Lavkoo GCC — Current Project Status & Handoff Index

**Last update:** 2026-11-18. **Current phase:** autonomous implementation under the owner's charters: continuous development and release preparation on draft pull requests, with nothing merged, deployed or run on production data. The "Discovery preparation" text of 2026-11-12 further down is kept as history and is **superseded**: GCC application code now exists on 101 draft pull requests of `lavkoo` (and 9 of `lavkooapp`, 6 of `lavkooappseller`), all dormant behind switches that ship off.

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
| The latest handoff of each session | [handoffs/](handoffs/) |

## What is true today

- **Nothing is merged, deployed or verified in production.** `main` is `c5c04b7b` (2026-11-12) and has none of the GCC work; the live server runs an older baseline described by the release documents. A merge, a deployment, a key change, a migration on production or a change of a live financial or legal rule needs the owner's separate approval (charter section 4).
- **The all-in-one integration (a CI-only branch, never merged) is green.** Run 293 on `8598331b` finished success: 28 of 29 jobs on SQLite, MySQL 8.0 and MariaDB 11.4.2 and the strict-mode job, one skipped by design, read job by job ([audit](audits/2026-11-18-all-in-one-integration/README.md)). The integration has since moved to include the later GCC slices and the tax pull requests, and one more run is dispatched on its head.
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
