# Lavkoo GCC — Approved Architecture & Decision Register

**Canonical project:** Lavkoo GCC (earlier name: PharmacyKSA GCC)  
**Date:** 2026-11-12  
**Phase:** Approved functional planning; code architecture and migration remain **proposed until audit**.

## Core foundation (decided in previous planning discussions)

- One Laravel website/backend/admin, one Flutter **customer** app, and one Flutter **seller** app; support six GCC markets from the first architectural stage: Saudi Arabia (SA/SAR), UAE (AE/AED), Kuwait (KW/KWD), Qatar (QA/QAR), Bahrain (BH/BHD), Oman (OM/OMR). Countries are activated independently.
- One web domain with intended paths `/sa`, `/ae`, `/kw`, `/qa`, `/bh`, `/om`; visitors may explicitly switch stores. Manual choice takes precedence over IP inference.
- Distinguish **Country**, **Storefront**, **Legal Entity**, **Seller/Vendor/Shop**, **Warehouse/Inventory Location**, **Fulfillment Account**, **Sales Channel Account**, **Customer**. Do **not** repurpose legacy `shop_id` or `seller_id` for a country storefront without reviewing semantics.
- Separate legal selling company per country: invoicing, taxes, bank accounts, gateway accounts, settlements and accounting. Preserve historical order/legal snapshots.
- Independent products/catalogs, prices, SKUs, availability and inventories by country; deliberate admin-only cross-country product copying may exist, without shared stock or automatic price sync.
- One **fulfillment provider/source per product per country storefront**; no automatic fallback to another provider. Retain existing Torod, M5AZN, pools, reservations, synchronization, safety buffers, outbox and fulfillment adapters; support future country-specific providers. Order lines keep the chosen historical execution source.
- Payment integrations: preserve existing Paymob and MyFatoorah and examine work on Tabby/Tamara. Configure accounts by legal entity/store, market capability and merchant eligibility; never assume a provider supports every country/method.
- Keep marketplace/channel integrations and Odoo; review old and new branches before modifying.

## Approved numbered decisions

| ID | Decision | Meaning |
|---|---|---|
| GCC-07 | Independent theme per country; shared component library | Independent drafts/publishing and optional theme-copy; no cross-country accidental publication. |
| GCC-08 | One central category tree | Country-specific product catalog; category visibility may differ without cloning the hierarchy. |
| GCC-09 | Shared identity/components with web/app customization | Web and customer app can customize sections/layout separately. Audit seller-app theme/API impact too. |
| GCC-10 | Shared brand identity with country overrides | Global name/logo by default; override storefront display name and branding; legal entity identity stays separate. |
| GCC-11 | Legal policies approved per country | Copying a template creates a draft; each storefront needs approved country-specific published documents before selling. |
| GCC-12 | Per-store configurable pause | Browse-only, maintenance page, or redirect to default; preserve prior orders, payments, refund, support, and webhook processing. |
| GCC-13 | One customer identity across countries | One login; separate carts, orders, wallets and financial flows by storefront/legal entity. |
| GCC-14 | One staff login with multi-country/entity scopes | Keep existing role/module authorization while adding data/action scopes; enforce in backend and APIs. |
| GCC-15 | Country-local loyalty now | Independent points; future interoperability possible but **off** until specifically approved. |
| GCC-16 | Local and cross-border shipping by eligibility | Source storefront inventory and seller remain fixed; verify shipping provider, product restrictions, import/export, tax and destination. |
| GCC-17 | Central campaigns, local coupon rules | Central campaign may select markets; each store has its own amounts, currency, limits, eligible items and redemption. |
| GCC-18 | Central brands, shared base attributes with local extensions | Catalog products stay independent; category hierarchy central under GCC-08. |
| GCC-19 | Saudi Arabia initial default storefront | Admin can change default later, but selected default must be valid and operational. |

## Additional provisionally accepted operational constraints

- **Single-store / single-selling-entity checkout**: no cart containing stores of different countries/legal sellers in a single checkout. Audit legacy multi-vendor checkout/order split compatibility before final schema design.
- **Local pricing, not auto-FX**: each storefront has independent catalog prices in its market currency; no automatic conversion from Saudi prices to set another market's catalog.
- One customer can view own orders across countries where allowed, but local admins must not access unrelated-country customer transactions. Marketing/legal consents have correct scopes.
- Wallet balances and transaction ledgers are separate by store/legal entity/currency, with careful reconciliation of historical Saudi balances. No automatic transfers between legal companies.
- Legal, payment, taxes, shipping rules and sensitive accounts are NOT automatically inherited across companies. Safe branding/templates may inherit configurable global defaults.
- Checkout validates product eligibility independently of courier coverage, particularly regulated pharmacy/health goods; no automatic bypass on cross-border delivery.
- A store may be configured while inactive. Launch requires readiness and deliberate approval. Store suspension must not break previously created obligations.
- Shared caching, queue workers, routes, app-local caches, notifications, inventory and SEO must all be market-scoped; verify rather than assume this is implemented.

## Non-negotiable migration / compatibility guardrails

1. **No rewrite of working inventory/fulfillment, theme editor, Flutter dynamic theme, wallet/loyalty, payments or sales channels.** Expand and protect them.
2. Never change production or merge outstanding PRs without explicit approval. Preserve existing Saudi customers, order IDs, inventory histories, balances, payments, invoices and seller data.
3. Identify legacy `shop_id`, `seller_id`, `wallet_balance`, `loyalty_point`, active theme assumptions, SAR assumptions, global settings and cache keys.
4. Preserve vendor/seller business behavior, including the seller Flutter app; audit API v3/seller and seller app version gate.
5. Do not confuse old-repository PRs with PRs in new repos. Validate commits, branch ancestry, changesets and production deployment independently.
6. Audit first, document findings with file/path/SHA evidence, propose steps/tests/rollback, stop for owner approval.

## Legacy references for investigation (not current write targets)

- Older Laravel repository: https://github.com/fullstackfull/pharmacyksa98
- Older Flutter customer repository: https://github.com/fullstackfull/ksaapp98
- Legacy backend PRs #9 (channels), #10 (MyFatoorah), #11 (stock) reported merged there; #12 (Amazon), #13 (Trendyol), #14 (Noon), #15 (Odoo), #16 (guards) reported open there at last check. **Verify live status, and whether any commits exist in current `lavkoo`.**
- Legacy Flutter customer PR #1 (MyFatoorah) reported merged there. Verify transfer to `lavkooapp`.

Do not invent numbered GCC-01–06 labels from memory: their agreed *principles* are captured in the foundation section above.

## GCC-20 — Platform Super Admin in Flutter Seller App (approved business intent)

The existing seller app `fullstackfull/lavkooappseller` must also provide a **platform-owner Super Admin mode** with central oversight of all six country storefronts, drill-down per country/legal entity, KPIs/alerts, and access to expressly authorized administrative tasks. **This is a separate platform-admin principal**, not a seller or seller-staff token with expanded access. Preserve ordinary seller workflows and seller/staff data isolation. Authenticate via the backend's admin identity and appropriately secured admin mobile endpoints, with proper authorization and auditable sensitive actions. Begin with read-only monitoring; operational and critical actions require separate implementation and production approvals. Full scope and audit requirements: [GCC-20-SUPER-ADMIN-MOBILE.md](./GCC-20-SUPER-ADMIN-MOBILE.md).

## Owner decisions, 2026-11-22 — grading rule, webhook isolation, money defects, completion target

Recorded verbatim in substance from the owner's instruction of 2026-11-22. These are **approvals and definitions**, not merge or release authority: "لا Merge إلى main ولا Deploy ولا ترحيلات إنتاج أو تدوير مفاتيح دون موافقتي" stands unchanged.

### 1. The MET definition is settled (grading rule, applies to the whole tracker)

A criterion implemented in a **draft** pull request **may be recorded MET** when a **named test that covers its behaviour passed on a named commit** — **even if the feature ships disabled by default**. Dormancy is no longer a reason to hold a criterion at CODED.

This settles the question this project had left open and had been asked three times: D1-04 index 2 and D1-05 index 4 had been ruled MET on that footing while D2-07…D2-10 were held at CODED for it, and the register now agrees with itself.

**What it does NOT do, stated by the owner in the same breath:** it is not approval to merge or to deploy, and **"ولا ترفع أي معيار دون دليل فعلي"** — no criterion is raised without actual evidence. Three things therefore still hold a criterion at CODED, and each is a *different* reason from dormancy:

- **No test covers the criterion's wording** — the ordinary case, and the reason for the overwhelming majority of CODED criteria.
- **A criterion's own wording is only partly covered** — e.g. a criterion naming three verbs where one verb reads the wrong row.
- **The test manufactures the state it is meant to observe.** A test that creates the column with `Schema::table()` inside itself, on a schema the repository ships no migration for, proves the code and not the platform. This is the owner's earlier standing rule — *"لا تعتمد على اختبارات تصنع الحالة التي يفترض أن تكتشفها"* — and the MET definition does not override it.

### 2. GCC-21 — Payment confirmation isolated per company and gateway account (approved, staged)

Approved business intent: **a payment's expected confirmation address is bound to the payment when the payment is created**, and confirmations are isolated per selling company and per gateway account.

- The **public platform webhook address must stop confirming payments of companies that began under an independent company address**.
- Payments that **began under the platform address keep being confirmed there** — no historical payment is stranded.
- Delivery is **staged, with focused tests at each step**, and **the new refusal is not switched on until the initiation-side binding is complete**. Switching the refusal on first would strand live payments; this ordering is part of the approval, not an implementation detail.

### 3. Money defects to close before new axes

The refused order must take nothing, and no incorrect stock or return may be created: complete the PR 144 fix, and close the C-11 till and restock defects.

### 4. Completion target

After those defects close, continue every requirement of the six-country plan. The figure of record at the time of the decision was **532 MISSING**, to be reduced continuously until every programmatically implementable requirement is done.

### 5. Testing posture during development

Focused tests during development; the heavy comprehensive verification campaigns belong to the final phase. **A security or financial defect is never deferred for that reason.**

## 2026-11-23 — Owner decision: per-country wallets (GCC-15), and what it blocks

Recorded from the owner's instruction of this date. It settles a question two merged lanes had
answered differently, so read it before touching the wallet, the ledgers or storefront money.

1. **Each country has its own wallet in its own currency.** Paying across countries' currencies is
   forbidden, and so is converting a balance from one to another automatically. There is no single
   balance that spends everywhere.
2. **Wallet checkout stays closed in every country whose independent wallet is not implemented
   yet**, and the refusal is to be tested as the behaviour it is. This is already how main behaves:
   `EnsureWalletIsOpenInStorefront` answers 403 while a storefront trades in its own currency —
   because the balance is one number in the platform's money — and
   `tests/Feature/Storefront/StorefrontWalletClosedTest` pins it with nine tests, including that no
   order is saved and not a coin is taken. Nothing was rebuilt for this decision; it was already
   true and is now the written rule.
3. **E-05 ledger attribution is NOT to be merged until it agrees with the above.** It does not
   today: `WalletCheckoutLedgerTest` drives a wallet checkout in AE, KW, QA, BH and OM and expects
   200, which is the path item 2 forbids, so seven of its cases fail against main on both engines.
   Its migration also joins the list in `CustomerTablesAreNotRewrittenTest` of migrations that
   change a customer table the guard cannot name by reading the source — a deliberate gate, not a
   defect. Whoever resumes it should expect to replace those seven cases with the refusal, and to
   let the migration name its tables, rather than to weaken either guard.
4. Independent fixes merge once their required tests pass; **no production deployment.**

The seller app's version gate is a separate matter and is not a reason to lower a security
minimum: the shipped version is raised to meet the backend's published minimum, never the minimum
lowered to clear the lock.

## 2026-11-24 — Owner decision: the master storefront switch is not a kill switch (A1-02)

Asked which promise wins when `GCC_STOREFRONTS_ENABLED` is off — dormancy (the platform of today, nothing
narrowed anywhere) or a kill switch (the default market only) — the owner decided:

1. **The master switch off means legacy / dormancy**, to keep the Saudi behaviour compatible. The catalogue
   is not narrowed in that state, which is what every Saudi-compatibility test asserts today.
2. **A single market is closed through its own country settings and its storefront lifecycle**, never by
   turning the master switch off.
3. **Once more than one market is live, the master switch is not to be used as a kill switch.** That
   constraint is to be documented, and a check is to refuse operating actions that could expose another
   market's data.
4. **Orders, payments and refunds that already exist are still completed** even when a market stops taking
   new orders.
5. **No Saudi-compatibility test is to be changed to hide a conflict.**

What this settles, and what it costs: the state the switch creates once a second market holds rows — every
market's rows read as the default market's — is accepted as a state the platform must not be **operated**
in, rather than one the code silently corrects. So the remedy is procedural and guarded, not a change of
what the switch does: turn the switch back on, then close the country itself (`active` false in
`config/gcc.php`, or `php artisan gcc:storefront:state <CC> pause`). An attempt to make the switch narrow
the catalogue was written and backed out before this decision (handoff `2026-11-24-1900`); it is not to be
revived.

One finding from that attempt stands whatever the decision, and is recorded so nobody repeats it: the
narrowing must never be extended to `StorefrontCatalog::offers`, the cart's own gate. It compares a column
on a row the caller already holds in memory, so a row loaded without that column would be refused — which
would stop a Saudi installation selling its own products.
