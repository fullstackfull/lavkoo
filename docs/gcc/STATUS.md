# Lavkoo GCC — Current Project Status & Handoff Index

**Last planning update:** 2026-11-12  
**Current phase:** Discovery preparation. **No GCC application code has been implemented or approved for deployment as part of this plan.**

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

## Open questions for audit

- Exact branch/commit relationship between legacy and renamed repositories.
- Which end-user and seller app versions/builds are currently deployed, if observable without production access.
- Whether seller app shares sufficient auth, scope, themes and multi-country context; no assumption of parity.
- Multi-seller checkout internals and the impact of the provisional one-legal-entity checkout.
- Scope strategy for global categories/brands/local attribute overrides.

## 2026-11-12 — GCC-20 new owner request

The project owner added a **global Super Admin dashboard inside the existing Flutter seller application** for owner-level monitoring and country-specific oversight/actions. This is a distinct platform-admin mode, not seller account impersonation or an expanded seller token. Scope recorded in [GCC-20-SUPER-ADMIN-MOBILE.md](./GCC-20-SUPER-ADMIN-MOBILE.md); add platform admin mobile auth, country-level KPIs, country drill-down, safety approvals, notification isolation and regression/authorization tests to Discovery. **No application code changes authorized.**
