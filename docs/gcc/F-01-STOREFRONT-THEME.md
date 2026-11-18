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

- **F-02, publish per storefront: slice 1 is done (below), the rest is not.** A publish still announces on the one FCM topic (every app is woken, and each asks `/theme/version` for its own storefront's revision), and a draft edit still pushes into the one banners table (`ThemeBannerLink`).
- **F-02 slice 1 (branch `gcc/p1-theme-publish-per-storefront`).** While themes are kept by storefront, a publish, an activation or a restore of one storefront's theme touches that storefront only: `ThemeManager::publish` archives the previous version of the same theme and so leaves another storefront's published version, revision and checksum alone (a test pins it, and that two storefronts count their revisions apart); `StorefrontThemeRenderer::flush` and `ThemeDelivery::flush` take the storefronts to forget (`StorefrontThemeResolver::storefrontsAffectedBy`: only the theme's own, unless it is the default storefront's, which every storefront without a theme of its own falls back to, or themes are not kept by storefront, where every storefront is forgotten as it always was); and the audit lines `theme.published`, `theme.activated` and `theme.restored` carry `storefront` (and carry nothing new while themes are not kept by storefront). The other callers of the two flushes (the campaign and experiment screens and the campaign tick) still forget every storefront: their caches are global (F-04).
- **F-02 slice 1, scheduled publishes (same branch).** The audit lines of a scheduled publish carry `storefront` the same way. `theme:publish-due` adds it to `theme.scheduled_publish_cancelled` (a due publish that no longer passes the check, with its reasons) and to `theme.scheduled_publish_failed` (with its error), and the admin's schedule and unset actions, which wrote no line before, now write `theme.scheduled_publish_set` and `theme.scheduled_publish_unset` (the moment before and after, the admin as the actor, `theme_id` and `storefront` in the context). Only the `storefront` key depends on themes being kept by storefront: the two admin lines are written on every installation, with `theme_id` alone while themes are not kept by storefront. A lookup of the storefront that fails is reported and leaves its line without it. A failed publish still ends the run, as it always did, so the due publish of another storefront waits for the next run (`StorefrontThemeScheduledPublishTest` pins it, for two storefronts in both creation orders).
- **F-04 and F-09, caches and the app.** The flush is not per storefront, the beacon is not per storefront, and the app is not told to drop what it holds when a storefront gets a theme of its own.
- **F-07, preview: F-07.4 is done; F-07.2 is not met for the app, nor F-07.1 for `/theme/sections`.** While themes are kept by storefront, a preview token and the builder's session preview of a draft of another storefront's theme are ignored, as a token that is not live is, and the published page of the request's own storefront is drawn (`StorefrontThemeResolver::mayPreview`, asked by `ThemePreviewToken::version` and by the session branch of `StorefrontThemeRenderer`); while they are not, or before the migration, nothing changes and the token format is the same (`StorefrontThemePreviewStorefrontTest`). F-07.0 is tested for the token, the app's two routes and the website, and F-07.3 for the website (`StorefrontThemePreviewTokenTest`, `StorefrontThemePreviewWebTest`). Three defects of the app's routes were found by those tests and are left as they are, each a skipped test in `StorefrontThemePreviewTokenTest` that says what the route answers today: `/api/v1/theme/home?preview[]=x` answers 500 (`ThemePreviewToken::version` takes a string); the app's responses that carry a token have no `X-Robots-Tag` (`NoIndexThemePreview` is in the web group only, and `/theme/home` takes `preview`, not `theme_preview`); `/api/v1/theme/sections?theme_preview=` serves the draft with `Cache-Control: no-cache, private`, not `no-store`. A URL that matches no route never enters the web group and is not marked. The builder's own links (the builder frame, the preview button and the link minted for a phone) name no storefront, so for a draft of another storefront they show the default storefront's published page while themes are kept by storefront, until their URLs carry the storefront. See decision 6.
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
6. Previewing a draft of a storefront that is not open yet. While storefronts are off, and for a storefront that is closed while others are open, every request for it is the default storefront's, so a draft of its theme cannot be previewed at all; and a storefront with no theme of its own (Kuwait, say) is drawn the default storefront's published page but is still another storefront, so the default storefront's drafts are not previewed on it. `StorefrontThemePreviewStorefrontTest` pins each; say so if a draft should be previewable there. The three defects of the app's routes under F-07 above are decisions too.

## Tests

On a hand-built theme schema (`tests/Support/BuildsThemeWorld.php`), in `tests/Feature/Storefront` and `tests/Unit/Storefront`:

- `StorefrontThemeActivationTest` (F-01.0): activating one storefront's theme leaves the other's active, and the reverse; only the same storefront's themes compete; NULL and the default country are one storefront; off, or before the migration, every other theme is deactivated.
- `StorefrontThemeServingTest` (F-01.1): through `/api/v1/theme/version`, `/home` and `/sections` with `X-Storefront`, and through the renderer and the delivery; the fallback; a storefront given its own theme at once; no copy of one storefront served to another; the built-in pages for a theme with nothing published; the default storefront's theme for a payment callback bound to another country while storefronts are off.
- `StorefrontThemeReadersTest` (F-01.0 to F-01.2): every reader, from every kind of storefront, on and off.
- `ActiveThemeReadersTest` (F-01.2): the scan, its own proof that it sees each form of a direct read, and that every reader asks the resolver.
- `AdminThemeStorefrontTest` (F-01.3): the list, the table of what each storefront serves, the create form and the controller, and nothing of it while off.
- `StorefrontThemeSwitchTest`: the statements while off, the storefront and the schema not read, dormant before the migration, the column asked for once.
- `StorefrontThemeMigrationTest`: appends and touches nothing, runs twice, does nothing without the table, rolls back, refuses while a theme names a country, collation on MySQL.
