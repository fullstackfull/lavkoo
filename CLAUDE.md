## CURRENT OWNER INSTRUCTION — STANDING DEVELOPMENT DELEGATION (2026-11-12)

**Read first, before the older DISCOVERY ONLY text below:** https://github.com/fullstackfull/lavkoo/blob/main/docs/gcc/AUTONOMOUS_DEVELOPMENT_CHARTER.md

The owner has approved **continuous independent development** on isolated feature branches across all three LAVKOO repositories. This later written authorization overrides earlier Discovery-only / single-test-PR approval limits **for branch coding, isolated testing, CI repair, and reviewed immediate pushes/draft PRs**. Do **not** repeatedly ask the owner to approve routine coding decisions. Choose safe reversible technical solutions, implement real code in small increments, self-review, commit and push immediately, open draft PRs, then continue another independent task.

**Still forbidden without separate approval:** merging into main, production deploy/release, production DB access/migration/export/backup, real payments/shipping, credentials rotation, force-push or history rewrite, destructive financial/data changes, weakening security gates. G0 stays open and GCC implementation must not merge/release before its gates. For financial/security-sensitive work, development in a feature branch is permitted, but merging/release requires appropriate review. No seller app version change without verified release/minimum evidence. Preserve Saudi behavior and six-market approved design.

This instruction is **active now**. The historical restrictions and existing audit requirements below are preserved for record, but any conflicting blanket ban on isolated coding is superseded by this signed owner delegation.

---
# CLAUDE.md

## Project Rules
@.claude/rules/translate.md
@.claude/rules/response-style.md
@.claude/rules/coding-standards.md
@.claude/rules/auction-ui.md

## Commands

### Artisan
- `php artisan serve` — dev server
- `php artisan migrate` — apply pending migrations (see Database note below)
- `php artisan optimize:clear` — clear all caches
- `php artisan generate:entity {name}` — scaffold model + repo interface + impl
- `php artisan file:permission` — fix storage permissions after deployment

### Frontend (Laravel Mix 5)
- `npm run dev` / `npm run watch` / `npm run prod`

## Database
**Critical:** Never run `php artisan migrate` on an empty database. Import `installation/backup/database.sql` first, then migrate.

## Architecture

**Flow:** Controller → Service → Repository → Model. Controllers are thin; logic lives in `app/Services/`. Repos in `app/Contracts/Repositories/`. Helpers in `app/Utils/`.

**Routes** (non-standard, loaded by `app/Providers/RouteServiceProvider.php`):
- Admin: `routes/admin/routes.php`
- Vendor: `routes/vendor/routes.php`
- Web: `routes/web/routes.php`
- API v1/v2: `routes/rest_api/v{1,2}/api.php`
- API v3 seller: `routes/rest_api/v3/seller.php`
- Each `Modules/` dir has its own RouteServiceProvider.

**Modules** (`nwidart/laravel-modules`): `Modules/AI`, `Modules/Auction`, `Modules/Blog`, `Modules/TaxModule` — each is a self-contained mini-app.

**Bootstrap:** `AppServiceProvider` loads themes, settings, payment configs, and add-on states from DB at boot. Many behaviors are DB-driven, not code-driven.

**AI Module:** `Modules/AI/AIProviders/AIProviderManager.php` selects active provider (OpenAI/Claude) from DB config.

**Addresses:** one central system — regions/cities live in `address_regions` / `address_cities` (ids, never names, are keys); couriers, warehouses and marketplaces are only mapped to them through `provider_city_mappings`. Saudi addresses use region + city + district + National Address; `zip` stays and is never derived from the National Address. Mode `off|soft|strict` is the `unified_address_mode` setting (`App\Services\Address\AddressSchema`); code ships dormant until its migrations run. All writes go through `ShippingAddressService`, orders keep an immutable copy (`AddressSnapshot`). Read `docs/address/README.md` before touching any address, city or provider-mapping code.

**Frontend:** Vue 2 + Bootstrap 4 + Laravel Mix 5 — intentionally legacy, be conservative.

## Lavkoo GCC — Shared Planning and Multi-Claude Sessions

**Current repo name:** `fullstackfull/lavkoo` (formerly planned as PharmacyKSA GCC). Related Flutter apps: `fullstackfull/lavkooapp` (customer) and `fullstackfull/lavkooappseller` (seller).

**Mandatory first read for any GCC task:** `docs/gcc/README.md`, `docs/gcc/DECISIONS.md`, `docs/gcc/STATUS.md`, `docs/gcc/COLLABORATION.md`, `docs/gcc/DISCOVERY_PROMPT_01.md` and latest `docs/gcc/handoffs/`.

**Currently approved stage is DISCOVERY ONLY.** No implementation, application source edits, database changes, production writes, merging or deployment. Audit Markdown reports/handoffs are the only permitted write exception. Commit and **publish to GitHub immediately** after each meaningful documentation checkpoint and report exact remote commit SHA, link and branch. Preserve other Claude accounts' work, read latest remote state first, and never force push. Git push is not deployment. Full rules: `docs/gcc/COLLABORATION.md`.

## 2026-11-12 — Coupon-boundary owner-approved exception, THIS BRANCH ONLY

The older DISCOVERY ONLY statement above is superseded **solely for this narrow coupon task** by the owner's explicit approval at `docs/gcc/PHASE0-COUPON-GTE-SELLER-VERSION-OWNER-APPROVAL.md` on this branch. Permitted: isolated regression tests for coupon eligibility below/equal/above `min_purchase`, and **only if rendered text is demonstrably misleading**, a narrowly localized customer-facing wording correction. Existing `OrderManager::getTotalCouponAmount()` already uses `min_purchase > eligible total`, so do not change this condition. Do not confuse admin's `min_purchase > discount amount` rule with eligibility. Prevent `translate()` from writing into `resources/lang` during tests. Seller app version verification is read-only and documented on audit branch, with no seller app/version/server gate changes. Before every commit check diff/security, then push immediately after review; draft PR only; NO merge, main modifications, production, migrations, other business logic changes, GCC phases or G0 approval.
