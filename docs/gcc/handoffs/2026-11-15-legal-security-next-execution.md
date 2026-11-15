# GCC-11 / Web business-page security — engineering continuation (2026-11-15)

**Purpose:** Reduce needless owner decision loops. This is an engineering plan, not legal advice or final regulatory approval. Work is on independent feature branches and draft PRs only. No merge, live DB access, production change or deployment. G0 remains OPEN.

## Verified GitHub baseline

- `main`: `c5c04b7bf32ceeacdac06d179dbed869c28479a4` at the time of verification.
- PR #37: `gcc/p1-storefront-country-context` at `2344fa303c3801a653b5ee58f066fb62e247d9d9`, base `gcc/p1-storefront-api-header`.
- PR #38: `gcc/p1-storefront-legal-readiness` at `53ad482a2ba3a0bde0726a85931f298704cc648a`, base #37.
- PR #39: `gcc/p1-storefront-sellable-list` at `7bb90819f1bce4278b9fe2ff6b40d4f6270e3924`, base #38.
- `docs/gcc/DECISIONS.md`, GCC-11: each storefront requires approved country-specific published legal policies before selling. A copied template remains draft. Non-sellable/inactive storefronts may be configured.
- `app/Http/Controllers/Web/PageController.php:26-33` in main: public `getPageView` omits `status=1` if `source=admin` is supplied by requester. Public route `GET business-page/{slug}` uses this controller. This is a confirmed unpublished-page disclosure path, not merely theoretical missing test.

## Immediate security work (not contingent on legal choices)

1. Create separate `main`-based security fix branch for the public business page. Require `status=1` on the public route regardless of user-supplied `source=admin`.
2. Identify legitimate preview use. If preview is needed, create a **separate authenticated admin-only** route using existing admin auth and permission middleware and a dedicated view/handler; never use a query parameter as authorization. The simplest immediate branch fix should remove the bypass without changing unrelated pages.
3. Test published page visible, unpublished page denied both normally and with `?source=admin`, and admin preview only through authenticated and authorized admin route if implemented. Audit other `source=admin` calls before retiring behavior and document compatibility.
4. Self-review, isolated tests, commit/push, draft PR. Do not merge/deploy without security review; do not wait to code for a separate owner authorization.

## Engineering defaults for PR #38/#39 and next GCC-11 slices

These are *safe configurable development defaults*, not final legal determinations:

- **Document types:** keep six types currently used by #38 (`terms`, `privacy`, `refund`, `return`, `cancellation`, `shipping`) in configuration, *not hard-coded regulatory sufficiency*. A qualified country/legal-entity reviewer will confirm actual country-specific list before release.
- **Locale:** require an approved country-specific **Arabic** version for each type in the initial non-Saudi readiness configuration; where English is presented to customers, require an independently approved English version as well. Do not treat an English-only approved document as sufficient by default. Make required locales per storefront configurable; do not assume every market has identical legally required languages.
- **Versions:** readiness must be determined by the **current effective approved/published version**, not the existence of any old approved version. Superseded, revoked, draft or future-effective text cannot make a store sellable. Keep approval history; do not delete legacy records.
- **Checkout evidence:** design additive test-only schema/code for acceptance receipts referencing storefront, legal entity, document type, immutable version/hash, language, customer/guest identity permitted by privacy policy, time, and applicable terms; only capture *legally required explicit acceptance*, not assume all policies require a checkbox. Keep evidence minimal, privacy-aware, and auditable. No production activation without legal/privacy review.
- **Storefront list:** keep non-sellable countries visible as coming-soon/browse-only with `sellable=false`; never allow the flag or a public query/header to bypass checkout eligibility. Preserve SA legacy behavior while flag is OFF. Do not equate listing with permission to sell.
- **Enforcement:** once designed, guard every *order-creating or payment-initiating* path for new GCC stores at server-side domain/service boundaries, with storefront/legal entity context, including web and customer APIs, POS where relevant. Refunds, cancellation, support, callbacks and old obligations **must continue** for paused/non-sellable countries. Keep new gates default-OFF while G0 is open. A legal-documents table absent from a future enabled installation must fail closed rather than emit an unhandled 500.
- **Database:** only additive migrations proposed as code; validate index lengths and constraints on isolated MySQL, MariaDB and SQLite. No live migrations.

## Implementation priority and workflow

1. **Security:** fix public unpublished business page exposure with regression tests in separate PR.
2. **PR #38/#39:** build readiness tests around configured locales, current effective approved version, explicit approval separation, and safe missing-table behavior. PR #38/#39 may remain draft; do not alter active Saudi behavior.
3. **GCC-11 next:** add checkout legal-readiness enforcement behind the default-OFF country flag; do not block legacy orders/returns/refunds/callbacks, preserve existing SA.
4. **GCC-02/03:** characterize Saudi price/stock flows, then implement independent country-specific price and stock seams behind flags.
5. **Flutter:** extend app storefront list to respect `sellable` as browse-only state, without implying eligibility to pay. CI-only verification when SDK unavailable locally.

At each task: fetch latest remote heads; code; run tests in isolation; self-review; atomic commit and push; open/update draft PR; move on. Lack of owner legal sign-off blocks **merge/release**, not sandbox development. Escalate only true legal policy changes or irreversible production steps. Never bypass permissions, hooks or CI.
