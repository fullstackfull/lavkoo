# Changes to files that existed before this work

Everything else is new (new directories `app/Services/SalesChannels`, `app/Services/OperationalCosts`, the migrations dated `2026_11_07_*`, models, enums, commands, tests, docs). The Torod Point / M5AZN workstream (`app/Services/Fulfillment/**`, `app/Http/Controllers/Admin/Fulfillment/**`, `routes/admin/fulfillment.php`, `RouteServiceProvider`) is **not modified**. `main` (including its Hotfix 2) was merged into this branch cleanly; the combined Fulfillment, sales-channel and cost suites pass.

| File | Change | Why |
|---|---|---|
| `bootstrap/providers.php` | one line: `SalesChannelsServiceProvider` | registers the module's routes, rate limiter and null extension points without touching `RouteServiceProvider` |
| `app/Enums/GlobalConstant.php` | two keys in `EMPLOYEE_ROLE_MODULE_PERMISSION`: `sales_channels`, `operational_costs` | the new permissions appear in the role editor |
| `app/Console/ScheduleDefinition.php` | one block: `channels:sync --due` (5 min), `channels:publish --only=stock,price,shipments` (15 min), `channels:publish --only=settle` (10 min), `costs:refresh --days=3` (hourly), `costs:accrue-storage` (daily), `channels:prune` (daily) | the channel commands do nothing until `SALES_CHANNELS_ENABLED` is on and an account is enabled; the cost commands and `channels:prune` have no switch (they write only to the modules' own tables once the migrations and the Fulfillment tables are in place); each command no-ops before that |
| `resources/views/layouts/admin/partials/v2/_rail.blade.php`, `_side-bar.blade.php` | a rail button and a sidebar section "Sales Channels" (shown only to holders of the two permissions, links guarded by `Route::has`) | navigation |
| `resources/lang/{en,sa,ae}/new-messages.php` | added keys only (English, Saudi Arabic, Emirati Arabic) | every visible phrase is translated; a test fails on any missing key |
| `bootstrap/app.php` | `'secrets'` added to the existing `dontFlash` list | the account form's `secrets[...]` inputs are never flashed back into the session after a validation error |
| `.github/workflows/ci.yml` | a new job `tests-mysql-sales-channels` added at the end of the file: `tests/Feature/SalesChannels`, `tests/Feature/OperationalCosts`, `tests/Unit/OperationalCosts` and `CiDatabaseTest` on MySQL 8, in two groups with a 75-minute limit (the Fulfillment workstream's `tests-mysql` job is untouched) | the module list is long (about half an hour on a fast MySQL), so it has its own job and limit instead of lengthening the Fulfillment job |

No `composer.*`, `Modules/` or `public/` file is touched, so the deploy overlay builder accepts the release.
