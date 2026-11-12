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

## 2026-11-12 — Limited owner-authorized coding exception (feature branch ONLY)

The older statement above that all GCC work is DISCOVERY ONLY has been superseded **only for this branch's first bounded Phase 0-B workstream**, by the explicit owner authorization in [`docs/gcc/PHASE0-B-SAUDI-REGRESSION-CI-AUTHORIZATION.md`](docs/gcc/PHASE0-B-SAUDI-REGRESSION-CI-AUTHORIZATION.md). This permits new Saudi regression/characterization tests and evidence-based **test/fixture/CI-only** fixes, after examining failing GitHub Actions, running tests solely in proven isolated ephemeral CI/test environments, and publishing reviewed atomic commits immediately to the feature branch. A **draft PR** may be opened for owner review. **NO** production runtime code edits, migrations, actual production access, secrets handling, seller version change, merges, deployments, GCC phases 1–17, or GCC-20 implementation. If a real runtime defect is found, write a reproducer and STOP for owner authorization before fixing production code. `main` remains unaffected. This narrow exception does NOT retroactively authorize unrelated actions.
