# Lavkoo GCC — Master Prompt 01 (Discovery Only + Immediate GitHub Handoffs)

> Current name: **Lavkoo**. Previous planning name: **PharmacyKSA GCC**.  
> READ FIRST: `docs/gcc/README.md`, `DECISIONS.md`, `STATUS.md`, `COLLABORATION.md`.
>
> **Code/data/production: STRICT READ-ONLY.** One narrowly scoped exception: audit reports and handoffs are Markdown documentation that must be committed and immediately pushed to GitHub as described below. No application implementation is approved.

## Role and goal

You are Principal Software Architect and Laravel/Flutter auditor. Examine the **existing** cross-repo application, document how to safely evolve it into a six-country GCC commerce system, and preserve all existing fulfillment, inventory, payments, themes, customer/seller, and channel behavior. Do **not** build or modify features yet.

## CANONICAL repositories — do not use outdated repo names as active code targets

1. Backend / Website / Admin / APIs: `fullstackfull/lavkoo` — https://github.com/fullstackfull/lavkoo
2. Flutter customer app: `fullstackfull/lavkooapp` — https://github.com/fullstackfull/lavkooapp
3. Flutter seller app: `fullstackfull/lavkooappseller` — https://github.com/fullstackfull/lavkooappseller

All default branches observed as `main` at plan creation. **Reverify** the current HEAD of each repository and record SHAs. GitHub is persistent shared memory for multiple Claude accounts.

Historic repositories **for reference only**:
- `fullstackfull/pharmacyksa98` and `fullstackfull/ksaapp98`.
- Backend legacy PRs #9–#11 were reported merged; #12–#16 reported open in the **old backend repository** (Amazon, Trendyol, Noon, Odoo, safeguards), with legacy PR #16 based on `claude/integration-cdef`.
- Do NOT infer those PR numbers, branches, commits, or merged states exist in `fullstackfull/lavkoo`. Inspect ancestry, compare Git SHAs and file changes across renamed/legacy repos; show where code actually exists. Do not merge.

Do not rename existing internal Flutter package IDs, application/bundle IDs, Firebase projects, API hosts or release version gates merely because the product's public/repository name changed.

## Permissions and restrictions

### Allowed
- Read source code and Git history, migrations, tests, modules, configs with secrets redacted, documentation, PRs, CI results and APIs' source contracts.
- Inspect `git status`, HEAD refs and diffs **without altering tracked or untracked files**.
- Create ONLY Markdown reports/handoff notes in `fullstackfull/lavkoo/docs/gcc/audits/` and `docs/gcc/handoffs/`, or approved GCC documentation updates.
- Commit each completed documentation checkpoint and **push it immediately** to an appropriate remote branch. Verify remote commit SHA and provide GitHub URL in response. Documentation-only updates to canonical docs on `main` are permitted by project owner; avoid collisions and protect concurrent updates.
- If unable to push, report failure and do not claim remote persistence.

### Forbidden without a new explicit owner authorization
- Any application code edit in Laravel, Flutter customer, or Flutter seller.
- Any DB mutation, migration, seeder, destructive/test write, data backfill or querying production DB.
- Running build/install/change commands, writing caches, running unsafe tests, switching/resetting/merging code branches.
- Changing `.env`, secrets, gateway modes, shipping settings, scheduler state or remote production.
- Sending messages, live provider calls, marketplace offers, settlement/payment/fulfillment calls.
- Creating or merging feature PRs, deploying, force pushing or enabling auto-merge.
- Copying PII, real credentials, secret values or customer exports into reports.

If a strict read-only requirement conflicts with committing audit documentation, **documentation writing is the sole permitted exception**. Code and runtime remain read-only.

## Hard requirements — authoritative decisions

See `docs/gcc/DECISIONS.md` for full authoritative GCC-07…GCC-19 decisions and earlier approved principles; do not reinterpret them. Key requirements:

- Saudi Arabia SA/SAR, UAE AE/AED, Kuwait KW/KWD, Qatar QA/QAR, Bahrain BH/BHD, Oman OM/OMR — six country markets and independently enabled storefronts.
- One domain with intended localized storefront paths `/sa`, `/ae`, `/kw`, `/qa`, `/bh`, `/om`; Saudi first-launch default, later configurable; explicit country selection beats IP inference.
- Distinguish Country, Storefront, Legal Entity, Seller, Shop, Fulfillment Provider, Warehouse, Sales Channel Account, Customer. Existing `seller_id`/`shop_id` are not country identifiers.
- **Independent legal company, inventory, products, SKUs, prices, fulfillment, payments and invoices** by country. Independent local prices; never automatically FX-convert Saudi catalog prices into other markets.
- **Unified category hierarchy** and **central brands**, shared base attributes with optional local attributes.
- One fulfillment provider/source per product/store; no auto supplier fallback. Preserve existing stock pools, reservations, outbox, safety, integrations (including Torod/M5AZN), and historical execution snapshots.
- Local or eligible cross-border delivery; product export/import restrictions, courier coverage, checkout currency, tax/duties, merchant eligibility and payment availability validated.
- One customer identity/login, but **separate per-store carts, orders, wallets, loyalty and financial records**. Local customer transaction visibility; staff login can have multiple store/legal-entity permission scopes.
- Store-independent theme publishing, drafts, preview and caching; shared component library and default branding with country overrides; separately customizable website/customer Flutter compositions. Audit seller Flutter app for impacts.
- Published and approved legal policies per country before activation; configurable Email/SMS/WhatsApp/Push identities/accounts/templates; marketing audience isolation.
- Central marketing campaigns with store-specific coupon rules. One-store/one-selling-entity checkout provisionally agreed; audit historic multi-vendor order behavior and propose backward-compatible approach.
- Store pause modes Browse Only/Maintenance/Redirect default, with historical orders, webhooks, refunds and fulfillment still working.
- Keep Paymob/MyFatoorah and review Tabby/Tamara, Amazon/Noon/Trendyol and Odoo; tie accounts to authorized legal entity/market, preserve safety guards.
- Preserve legacy Saudi IDs, stock/order history, wallet and loyalty balances, taxes, receipts and all existing fulfillment integrations.

## Audit coverage — inspect all three repositories

**A. Code and repo map.** Routes, Laravel service/repository architecture, Models, migrations, guards/middleware, config, Jobs, queues, APIs v1/v2 and seller v3, modules, automated tests, CI, differences between old and new repositories, current PRs.

**B. Store context and data ownership.** Design a source-of-truth ownership matrix mapping actual models/tables to Global, Country, Storefront, Legal Entity, Seller, Warehouse, Provider Account, Sales Channel Account and Customer. Trace propagation through web, APIs, queue jobs, webhooks, cache keys, search index, session, app-local storage and scheduled tasks. Do not blindly add `country_id` to every table.

**C. Catalog and discovery.** Category parent tree, brands, attributes, independent products/variants, SKU/barcode, prices, currency assumptions, sales channel listings, category-to-marketplace mappings, SEO, promotions, coupons.

**D. Inventory, payments, shipping and accounting.** Trace Provider Stock Sync → Pool/Reservation → Cart → Checkout → Order → Payment Verification/Webhook → Fulfillment → Refund/Reconciliation → channel stock updates. Check race/idempotency, buffers, SAR defaults and country account binding. Verify legal seller snapshots and independent tax/fiscal output. Cross-border shipping eligibility for regulated healthcare products.

**E. Theme and web/mobile integration.** Inspect ThemeManager, ThemeBuilderService, ThemeDelivery, StorefrontThemeRenderer, ViewerContext, publish/revision/checksum, cache isolation, fallback, server-controlled Flutter components, app version compatibility, locale vs country differentiation. Also inspect seller app feature/config/force-update gate and its API constraints; do not assume it shares customer theme architecture.

**F. Customer/staff/loyalty.** `User`, Customer auth, Wallet Transactions, loyalty ledger, existing `users.wallet_balance` and `users.loyalty_point`, coupons, local cart, addresses, consents, wallets, support. AdminRole/module_access plus store/legal entity data scopes; export/report authorization and cross-store privacy.

**G. Communications, compliance and activation.** Notifications, topics, email/SMS/WhatsApp, templates and consent, published legal pages vs platform operational policies, market readiness checks, multi-mode pause, routing, locale, country selection, SEO, links and historical order access.

**H. Legacy PR ancestry and integration status.** Review both old and renamed repos and branches. Map real file presence and changes: core, Amazon, Noon, Trendyol, Odoo, MyFatoorah, Tabby, Tamara, safeguards and stock logic. State Verified / Open PR / Missing / Unknown for each functionality; open PR != deployed production.

**I. Seller application (mandatory, not optional).** Analyze `lavkooappseller` login, vendor permissions, vendor-scoped products/inventory, payout display, order processing, delivery/fulfillment interactions, country/store selection, push topics, deep links, backend seller API v3 and release-version gating. Document any changes needed without touching code.

## Required output (Arabic, technical names retained)

Generate numbered reports with precise filename, filepath + line/function + branch SHA evidence. Mark findings `VERIFIED`, `INFERRED`, `UNKNOWN` and missing-access limitations.

1. Executive Summary, audit coverage and critical blockers.
2. Cross-Repo Current Architecture, API contracts and dependency graph.
3. Data Ownership/Isolation matrix, proposed target relationships, and compatibility risks (esp. `shop_id`, `seller_id`, `customer_id`, wallet/loyalty).
4. Preserve/Extend/Refactor/New/Dependent-On-PR matrix; recommend minimal additions.
5. Gap/Risk Register P0/P1/P2 (financial, data, security, cache, country context, integration).
6. Legacy and Current Git Repo/PR/Branch/Commit lineage and collisions.
7. Migration and rollback plan **only** with reconciliation of Saudi historical balances/orders.
8. Web, Customer Flutter and Seller Flutter impact matrix.
9. Test strategy: six-country isolation, checkout one entity, wallet, roles, FX, theme, fulfillment, partial refunds, cross-border, notifications, pause modes, existing Saudi regression.
10. Implementation Roadmap with small separately approved stages, PR ownership boundaries, acceptance tests, non-destructive rollback and Go/No-Go gates.
11. Unresolved decisions needing owner input; **reopen an approved business decision only if evidence proves a conflict**.
12. Final Go/No-Go recommendation for starting approved implementation later.

## Progress persistence instructions — must follow

After **each report or meaningful checkpoint**, save it as a new timestamped markdown file in canonical `lavkoo/docs/gcc/audits/`, commit, **push immediately**, and verify the GitHub remote SHA. In a multi-session environment, never overwrite another agent's report. Use stable descriptive names and cite prior reports from the next one.

At end, create a unique `docs/gcc/handoffs/YYYY-MM-DD-HHMM-<session>.md`, commit/push immediately, and include:
- task/phase; repo names/base SHAs; inspected files and PR refs;
- completed reports; evidence; unknowns; risks; tests NOT RUN;
- remote file links and verified commit SHAs;
- precise next step and outstanding approvals.

Keep `docs/gcc/STATUS.md` accurate only after checking for concurrent updates, and preserve other agents' work. Do not force push. If a tool only supports document commits rather than command-line push, remote commit confirmation satisfies the remote persistence check.

**Before ending:** verify no application code/production state changed. Provide links to all reports, and STOP. Do not implement anything.

## Mandatory new GCC-20 audit track — Super Admin mobile mode in seller app

The owner now requires a **main platform-owner Super Admin experience within `fullstackfull/lavkooappseller`**, while retaining the normal seller/seller-staff UX and privileges. Read the approved requirements in [GCC-20-SUPER-ADMIN-MOBILE.md](./GCC-20-SUPER-ADMIN-MOBILE.md). In the audit, inspect the backend distinct admin guard, `SellerApiAuthMiddleware`, seller/staff login, `SellerControlTowerController`, seller app auth/token/navigation/control tower and seller API v3 to propose a separate secure **admin mobile principal/API** design. Cover All Markets overview, six-country drilldown, country-company financial segregation, currency-safe KPIs, critical alerts, permissions, step-up approval, cache isolation, seller non-escalation, push audience isolation, and phased read-only monitoring before any write controls. **Do not implement these features; deliver architectural findings, API proposal, compatibility tests and risks only.**
