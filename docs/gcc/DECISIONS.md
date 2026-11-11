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
