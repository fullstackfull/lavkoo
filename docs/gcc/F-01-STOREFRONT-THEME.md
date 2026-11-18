# F-01: one active theme per storefront

Status: coded and tested on SQLite and MySQL 8 (branch `gcc/p1-storefront-theme-isolation`), behind one switch that is off by default (`GCC_STOREFRONT_THEMES`). Nothing here has been run on the pilot server or on production, and nothing is MET until CI has run on a pushed head.

## The rule

Each storefront serves its own active, published theme. Activating the theme of one storefront never deactivates the theme of another, and a storefront that has no active theme of its own is served the default storefront's. Before this slice the platform had one active theme for everybody: `ThemeManager::activate` deactivated every other theme, and fourteen places asked "which theme is active" with their own query.

## What it does

While `gcc.storefronts.themes.enabled` is on and the migration has run:

- A theme belongs to the storefront its `themes.storefront_country` names. NULL, or the default country, is the default storefront's, so every theme that exists today already is, and there is nothing to back-fill. If the default country is ever changed, the themes that name none follow it.
- `ThemeManager::activate` deactivates only the other active themes of the same storefront.
- A request is served the active theme of the storefront the middleware bound it to, else the default storefront's. The admin panel, a queue and a console command are bound to no storefront, so they are the default storefront's. While `GCC_STOREFRONTS_ENABLED` is off every request is the default storefront's, even one a payment callback bound to the country of its order, because the theme caches are keyed by storefront only while storefronts are on.
- Creating a theme in the admin offers a select of the configured storefronts, open or not, with the default one chosen (a theme is prepared before its storefront opens). The choice is made once and cannot be changed, like the country of a product or a coupon. Importing a theme always makes a default-storefront theme.
- The theme list names the storefront of every theme, and a table above it shows what each storefront that a request can reach is served, marking a storefront that falls back to the default storefront's theme, or that has no theme to show (the built-in pages).

While it is off, or before the migration has run, nothing changes: the resolver asks `select * from themes where is_active = ? limit 1`, activation runs the statement it always ran, and not even the storefront or the schema is read (`StorefrontThemeSwitchTest` pins the statements). It needs no other switch: while `GCC_STOREFRONTS_ENABLED` is off the only storefront a request can reach is the default one, so the themes of the other storefronts can be prepared, and activated, before they open.

## The one resolver

`App\Services\Theme\StorefrontThemeResolver` is the only place that decides which theme a storefront serves (`activeTheme()`, `publishedVersion()`), which themes an activation competes with (`themesSharingStorefrontWith()`), and what a new theme records (`newThemeRules()`, `newThemeAttributes()`). `tests/Unit/Storefront/ActiveThemeReadersTest.php` scans `app`, `routes`, `Modules`, `config`, `database` and the views, and fails on a statement that names the Theme model or the themes table and holds `is_active` to a true value anywhere else. `ThemeManager` needs no exception, because the activation asks the resolver for the competing themes too.

### The fourteen readers, all routed

| Reader | What it serves |
|---|---|
| `StorefrontThemeRenderer::publishedVersion` | the website: page structure and the colours of the page head |
| `ThemeDelivery::publishedVersion` | the app: revision, checksum and every payload |
| `ThemeSectionController::servablePage` | `/api/v1/theme/sections` and `/home`: which pages the storefront has |
| `ExperiencePageController::show` | the custom pages `/p/{slug}` on the website |
| `ThemeManager::activeThemePublishedVersion` | the builder and the theme settings: the draft they open |
| `ThemeBannerLink::activeThemeSections` | Banner Setup: where each banner appears |
| `ThemeBuilderController::resources`, `resolveThemeFor` | the builder pickers and the theme of a request that names no version |
| `AppBuilderController::activeTheme` | the App Builder |
| `CampaignController::index`, `servablePageSlugs` | the campaign screen and its page list |
| `ExperimentController::publishedVersionId` | the experiment screen |
| `ExperienceHealth::publishedVersionId` | the health findings |
| `ThemeRepository::getActiveTheme` | no caller today |

`Theme::scopeActive` had no caller and could not be made storefront-aware, so it is removed. `StorefrontThemeReadersTest` calls every reader from the admin panel, from the default storefront, from one with a theme of its own and from one without, with the switch on and off.

### The readers that cache (their semantics are not changed)

`StorefrontThemeRenderer::sectionsFor` (`storefront_theme_page_{page}`) and `publishedGlobalSettings` (`storefront_theme_global_settings`), and `ThemeDelivery::revision` (`theme_delivery_revision`) and `payload` (`theme_delivery_{page}_{fingerprint}`), for 600 seconds. They already write under `StorefrontContext::scopedCacheKey` (PR 37), so each storefront caches what the resolver answered for it, and `StorefrontThemeServingTest` shows that one storefront never reads another's copy. Every publish and every activation still flushes every storefront's copy (F-04, F-02). The caches that hold what a theme's pages are asked (`theme_section_reach`, `commerce_campaign_overrides_{page}`, `commerce_experiments_{page}`) are not readers of the active theme and stay global (F-04).

## Before turning it on

1. Run the migration (`2026_12_04_100000_add_storefront_country_to_themes_table`). No migration of this repository creates the themes table, so it does nothing on a database that has none, and the code stays dormant until `SHOW COLUMNS FROM themes LIKE 'storefront_country'` answers. It appends one nullable `CHAR(2)` (`ascii_bin` on MySQL and MariaDB, so `sa` is not `SA`), with no index. It cannot be rolled back while a theme names a country, because every such theme would become the default storefront's and several could be active at once.
2. Turn `GCC_STOREFRONT_THEMES` on and clear the config cache.
3. Clear the application cache once, or wait ten minutes. The pages and payloads cached while the switch was off hold the one global theme for up to 600 seconds, and an activation or a publish flushes them anyway.

Rollback: turn the switch off and clear the caches the same way. Before that, leave only the theme that is to be served active, because while off the platform reads the first active theme (the lowest id) for every storefront, and the next activation deactivates all the others. The `storefront_country` values stay in the table and are ignored.

## What is not done

- **F-02, publish per storefront.** `ThemeManager::publish`, `restoreVersion` and `ThemePublishDue` take a version, so they already touch one theme, but a publish still flushes every storefront's caches, announces on the one FCM topic, and its audit line names no storefront. A draft edit still pushes into the one banners table (`ThemeBannerLink`).
- **F-04 and F-09, caches and the app.** The flush is not per storefront, the beacon is not per storefront, and the app is not told to drop what it holds when a storefront gets a theme of its own.
- **F-07, preview.** A preview token and the builder's session preview name a version, not a storefront, so a draft of one storefront can still be previewed on another's pages.
- **F-11, copy to a storefront.** There is no way to move or copy a theme to another storefront.
- **F-12 and F-13, brand**, and **F-15, the selector and the staff scope.** The builder, the App Builder, the theme settings, the campaigns and the experiments edit the default storefront's theme, because the admin panel is bound to no storefront. Their fallbacks still look across storefronts: `AppBuilderController::activeTheme` falls back to the newest theme, and `ThemeBuilderController::resolveEditableDraft` and `ThemeSettingsController::resolveDraft` to the newest draft, when the default storefront has nothing published. Narrowing them belongs with the selector. Any staff member who may publish may activate for any storefront.
- **One active theme per storefront is kept in a transaction, not by the database.** The table has no rule that forbids two, and activation takes no lock, as before: two activations of the same storefront at the same moment could both win.
- Activating a theme does not check that its storefront is open, or that the theme has a published version.

## Decisions that remain with the owner

1. What a storefront without a theme of its own serves. This slice serves the default storefront's theme. A storefront whose own theme is active but has nothing published is served the built-in pages, not the default storefront's theme (`StorefrontThemeServingTest` pins both); say so if it should fall back instead.
2. Which existing theme is Saudi's. All of them are, by NULL; nothing is back-filled.
3. Q-19: whether a copy to another storefront copies or shares banners and assets (F-11), and whether the website and the customer app are published together or separately (F-03).
4. Whether a theme may change storefront after it is created. Today it cannot.
5. The five new texts (`theme_storefront` and four more, in `en`, `sa` and `ae`) need a native Arabic reader.

## Tests

On a hand-built theme schema (`tests/Support/BuildsThemeWorld.php`), in `tests/Feature/Storefront` and `tests/Unit/Storefront`:

- `StorefrontThemeActivationTest` (F-01.0): activating one storefront's theme leaves the other's active, and the reverse; only the same storefront's themes compete; NULL and the default country are one storefront; off, or before the migration, every other theme is deactivated.
- `StorefrontThemeServingTest` (F-01.1): through `/api/v1/theme/version`, `/home` and `/sections` with `X-Storefront`, and through the renderer and the delivery; the fallback; a storefront given its own theme at once; no copy of one storefront served to another; the built-in pages for a theme with nothing published; the default storefront's theme for a payment callback bound to another country while storefronts are off.
- `StorefrontThemeReadersTest` (F-01.0 to F-01.2): every reader, from every kind of storefront, on and off.
- `ActiveThemeReadersTest` (F-01.2): the scan, its own proof that it sees each form of a direct read, and that every reader asks the resolver.
- `AdminThemeStorefrontTest` (F-01.3): the list, the table of what each storefront serves, the create form and the controller, and nothing of it while off.
- `StorefrontThemeSwitchTest`: the statements while off, the storefront and the schema not read, dormant before the migration, the column asked for once.
- `StorefrontThemeMigrationTest`: appends and touches nothing, runs twice, does nothing without the table, rolls back, refuses while a theme names a country, collation on MySQL.
