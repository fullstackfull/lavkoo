# Breadcrumb System Documentation

## Overview

A fully automated, server-side breadcrumb system for the admin and vendor panels. Breadcrumbs are generated at build time by an Artisan command, stored as PHP arrays, and rendered in Blade templates with zero JavaScript.

```
php artisan breadcrumbs:generate --group=admin
php artisan breadcrumbs:generate --group=vendor
```

---

## Architecture

```
                            Artisan Command
                     breadcrumbs:generate --group=admin
                                 |
          ┌──────────────────────┼──────────────────────┐
          |                      |                      |
    [1] RouteCollector    [2] ProjectScanner     [3] ParentResolver
     Collects all named    Scans project files    Determines parent-child
     admin.* routes,       for route() calls      relationships via naming,
     filters GET-only,     to build a usage map   URI depth, controller
     skips auth/AJAX       (optional, cached)     siblings, co-occurrence
          |                      |                      |
          └──────────┬───────────┘                      |
                     |                                  |
              [4] BladeViewResolver                     |
               Reads controller files to find           |
               return view('...'), then reads           |
               @section('title', translate('key'))      |
               from blade files. Also detects           |
               non-page routes (redirect/back/json)     |
                     |                                  |
              [5] SidebarParser                         |
               Parses the v2 sidebar blade files        |
               to extract real navigation hierarchy:    |
               section titles, group headings,          |
               nav item labels, Request::is() patterns  |
                     |                                  |
                     └──────────┬───────────────────────┘
                                |
                     [6] BreadcrumbBuilder
                      Combines all data into the final
                      PHP breadcrumb map with:
                      title, section, crumbs[]
                                |
                                v
                      app/Breadcrumbs/admin.php
                      app/Breadcrumbs/vendor.php
```

---

## Runtime Flow (Every Page Load)

```
  Request hits admin route (e.g. admin/system-setup/app-settings)
       |
  AppServiceProvider registers View Composer
       |
  AdminBreadcrumbComposer::compose()
       |
       ├─ Detects current route name (admin.system-setup.app-settings)
       ├─ Detects group from prefix (admin.* → "admin")
       ├─ Loads app/Breadcrumbs/admin.php (cached per-request)
       ├─ Looks up route in the map
       ├─ Resolves crumb URLs using route() + current request params
       └─ Shares $v2Breadcrumbs with the header partial
       |
  _header.blade.php renders the <nav> breadcrumbs
       |
       ├─ Non-last crumbs → <a> links
       └─ Last crumb → <span> (current page, no link)
```

---

## File Reference

### Command & Pipeline

| File | Purpose |
|------|---------|
| `app/Console/Commands/GenerateBreadcrumbsCommand.php` | Orchestrator — runs all 6 pipeline steps |
| `app/Console/Commands/Breadcrumbs/RouteCollector.php` | Collects named routes, filters auth/AJAX/ghost routes |
| `app/Console/Commands/Breadcrumbs/ProjectScanner.php` | Scans for `route('name')` call-sites (optional step) |
| `app/Console/Commands/Breadcrumbs/ParentResolver.php` | Resolves parent routes via 4 strategies |
| `app/Console/Commands/Breadcrumbs/BladeViewResolver.php` | Extracts page titles from blade `@section('title')` and detects non-page routes |
| `app/Console/Commands/Breadcrumbs/SidebarParser.php` | Parses sidebar blade files for navigation hierarchy |
| `app/Console/Commands/Breadcrumbs/BreadcrumbBuilder.php` | Combines all data into the final output |

### Runtime

| File | Purpose |
|------|---------|
| `app/Breadcrumbs/admin.php` | Generated PHP array — admin breadcrumb map |
| `app/Breadcrumbs/vendor.php` | Generated PHP array — vendor breadcrumb map |
| `app/Http/View/Composers/AdminBreadcrumbComposer.php` | View composer — resolves crumbs on every request |
| `app/Traits/HasBreadcrumbs.php` | Optional trait for controllers to override breadcrumbs |

### Views

| File | Purpose |
|------|---------|
| `resources/views/layouts/admin/partials/v2/_header.blade.php` | Renders `$v2Breadcrumbs` in the admin header |
| `resources/views/layouts/vendor/partials/v2/_header.blade.php` | Renders `$v2Breadcrumbs` in the vendor header |
| `resources/views/layouts/admin/partials/v2/_side-bar.blade.php` | Source of truth for admin sidebar navigation |
| `resources/views/layouts/vendor/partials/v2/_side-bar.blade.php` | Source of truth for vendor sidebar navigation |

---

## Pipeline Steps in Detail

### Step 1: Route Collection (`RouteCollector`)

Collects all named `admin.*` or `vendor.*` routes from Laravel's router. Applies three exclusion filters:

- **Ghost routes** — names ending with `.` (e.g. `admin.`)
- **Auth routes** — segments containing: `auth`, `login`, `logout`, `register`, `password`, `otp`, `verify`
- **AJAX routes** — segments containing: `search`, `ajax`, `quick-view`, `fetch`, `load-more`, `status-update`, `auto-fill`

Each route is stored with: `name`, `uri`, `method`, `controller`, `action`, `parameters`, `is_page` (true for GET).

### Step 2: Usage Scan (`ProjectScanner`) — Optional

Enabled with `--scan-usage`. Scans all `.php`, `.blade.php`, `.js`, `.vue` files in `app/`, `resources/views/`, `resources/js/` for `route('name')` patterns. Builds a map of which route names appear in which files. Used by `ParentResolver` strategy 4 (co-occurrence).

Results cached to `storage/app/temp/{group}_usage.json`. Use `--fresh` to force re-scan.

### Step 3: Parent Resolution (`ParentResolver`)

Determines parent-child route relationships using 4 strategies (first match wins):

1. **Naming convention** — last segment is a known action keyword (`edit` → look for `list`, `add` → look for `list`)
2. **URI depth** — strip last URI segment and find a matching GET route
3. **Controller sibling** — same controller with a `list`/`index` action
4. **Usage co-occurrence** — routes appearing in the same files (requires `--scan-usage`)

Two-pass computation: Pass 1 finds parents, Pass 2 computes depths with cycle guard.

### Step 4: Blade Title Resolution (`BladeViewResolver`)

For each route with a controller:

1. Finds the controller PHP file via namespace+class scanning
2. Locates the action method by name
3. Scopes to the method body (stops at next `public/protected/private function`)
4. Looks for `return view('view.name', ...)`
5. If found: reads the blade file, extracts `@section('title', translate('key'))`, resolves via English translations
6. If not found: checks for `return back()`, `return redirect()`, `return response()->json()` → marks as **non-page** for exclusion

**Supported blade title patterns:**
- `@section('title', translate('key'))` — most common, resolved from `resources/lang/en/messages.php`
- `@section('title', 'Plain text')` — used directly
- `@section('title', translate('A') . ' - ' . translate('B'))` — both keys resolved

**Non-page detection** — routes excluded when controller method contains:
- `return back()` / `return redirect()`
- `return response()->json()`
- Return type hint `: RedirectResponse` / `: JsonResponse`

### Step 5: Sidebar Parsing (`SidebarParser`)

Parses the sidebar blade files (`_side-bar.blade.php`) as the **ground truth** for navigation hierarchy. This is the most critical step for accurate breadcrumbs.

**What it extracts:**

| Sidebar element | Data extracted | Used for |
|----------------|----------------|----------|
| `data-section="settings"` | Section key | Grouping |
| `v2-ctx-title` translate key | Section title (e.g. "Settings") | `section` field |
| `v2-ctx-group-head` translate key | Group heading (e.g. "System Settings") | Context |
| `v2-nav-label` translate key | Nav item label (e.g. "System Setup") | Parent crumb label |
| `route('...')` in href | Route name | Direct route matching |
| `Request::is('...')` patterns | URI patterns | URI-based matching |
| `v2-nav-child-label` translate key | Child item label | Child crumb label / intermediate parent |
| `v2-nav-child` route + patterns | Child's own route & `Request::is()` | Child-as-parent breadcrumb |

**Three-phase matching:**

1. **Direct route match** — nav item's `route('...')` href matches the route name exactly
2. **URI pattern match** — route's URI matches a `Request::is()` pattern from the sidebar (supports wildcards, suffix matching for aliased URIs)
3. **Route-name prefix match** — route shares a common dot-prefix with a sidebar-matched route (e.g. `admin.brand.add-new` inherits from `admin.brand.list`)

**Child-as-parent pattern:**

When a `v2-nav-child` has `Request::is()` patterns that cover multiple routes (not just itself), the child acts as an **intermediate breadcrumb parent** for routes matching those patterns. The SidebarParser stores `childLabel` and `childRoute` alongside the parent's `navLabel`/`navRoute`.

Example — the "Pages & Media" parent has three children with broad patterns:

```blade
{{-- "Business Pages" child covers: list, page*, privacy-policy, about-us, helpTopic/index, etc. --}}
<a class="v2-nav-child {{ (Request::is('admin/pages-and-media/list') || ...) ? 'v2-is-on' : '' }}"
   href="{{ route('admin.pages-and-media.list') }}">

{{-- "Social Media Links" child covers: pages-and-media/social-media --}}
<a class="v2-nav-child {{ Request::is('admin/pages-and-media/social-media') ? ... }}"
   href="{{ route('admin.pages-and-media.social-media') }}">

{{-- "Vendor Registration" child covers: pages-and-media/vendor-registration-settings/* --}}
<a class="v2-nav-child {{ Request::is('admin/pages-and-media/vendor-registration-settings/*') ? ... }}"
   href="{{ route('admin.pages-and-media.vendor-registration-settings.index') }}">
```

This produces breadcrumbs like:
- `admin.pages-and-media.privacy-policy` → `Business Pages (link) › Privacy Policy`
- `admin.pages-and-media.vendor-registration-settings.edit` → `Vendor Registration (link) › Edit`
- `admin.pages-and-media.social-media` → `Social Media Links` (child IS the page, no intermediate)

**Skipped content:**
- Pinned groups (`v2-is-pinned`)
- Addon loops (`@foreach ($v2AuctionRoutes ...)`, `$v2TaxRoutes`, etc.)

### Step 6: Build & Write (`BreadcrumbBuilder`)

Combines all data into the final PHP array. For each route:

**When sidebar data is available** (96% of admin, 100% of vendor):
```php
'admin.system-setup.app-settings' => [
    'title'   => 'App Settings',         // from BladeViewResolver
    'section' => 'Settings',             // from SidebarParser (v2-ctx-title)
    'crumbs'  => [
        ['label' => 'System Setup', 'route' => 'admin.system-setup.environment-setup'],
        ['label' => 'App Settings'],     // last crumb = current page (no route)
    ],
],

// Child-as-parent: route matched by a child's Request::is() patterns
'admin.pages-and-media.privacy-policy' => [
    'title'   => 'Privacy Policy',
    'section' => 'Settings',
    'crumbs'  => [
        ['label' => 'Business Pages', 'route' => 'admin.pages-and-media.list'],  // childLabel → childRoute
        ['label' => 'Privacy Policy'],
    ],
],
```

**Trail construction rules:**
- If current route is a child of a nav item → `[Nav Label → link] › [Page Title]`
- If current route is matched by a nav-child's patterns → `[Child Label → child route link] › [Page Title]`
- If current route IS the nav item AND title matches label → `[Page Title]`
- If current route IS the nav item AND title differs → `[Nav Label] › [Page Title]`
- If both parent and child would appear AND parent route equals child route → skip parent (avoids redundant crumb)

**When no sidebar data** (fallback):
Uses `ParentResolver` relationships and heuristic section detection.

---

## Generated Output Format

`app/Breadcrumbs/admin.php` and `app/Breadcrumbs/vendor.php`:

```php
<?php

/**
 * Admin breadcrumb map — auto-generated on 2026-04-22 23:16.
 * Regenerate: php artisan breadcrumbs:generate --group=admin
 * DO NOT edit this file manually.
 */

return [

    'admin.dashboard.index' => [
        'title'   => 'Dashboard',
        'section' => 'Overview',
        'crumbs'  => [
            ['label' => 'Dashboard'],
        ],
    ],

    'admin.orders.details' => [
        'title'   => 'Order',
        'section' => 'Order Management',
        'crumbs'  => [
            ['label' => 'Orders', 'route' => 'admin.orders.list', 'params' => ['status' => '']],
            ['label' => 'Order'],
        ],
    ],

];
```

Each entry has:
- `title` — page title (from blade `@section('title')` or heuristic)
- `section` — sidebar section name (from `v2-ctx-title`)
- `crumbs` — ordered array of breadcrumb items:
  - `label` — display text (always present)
  - `route` — Laravel route name for link generation (absent on last crumb / non-link crumbs)
  - `params` — route parameter hints (present when the route has `{param}` segments)

---

## View Composer (Runtime)

`AdminBreadcrumbComposer` runs on every admin/vendor page load:

1. Detects current route name from `Route::currentRouteName()`
2. Loads `app/Breadcrumbs/{group}.php` (cached in static property — loaded once per request)
3. Looks up the route in the map
4. For each crumb with a `route` key, generates a URL using `route($name, $params)`, filling parameter values from the current request
5. Shares `$v2Breadcrumbs` array with the header view

Controllers can override breadcrumbs using the `HasBreadcrumbs` trait:

```php
use App\Traits\HasBreadcrumbs;

class OrderController extends BaseController
{
    use HasBreadcrumbs;

    public function details($id)
    {
        $this->breadcrumb('Orders', route('admin.orders.list', ['all']))
             ->breadcrumb("Order #{$id}");

        return view('admin-views.order.order-details', ...);
    }
}
```

---

## Blade Rendering

Both `_header.blade.php` files (admin + vendor) render identical markup:

```blade
<nav class="v2-crumbs" id="v2-header-crumbs" aria-label="{{ translate('Breadcrumb') }}">
    @isset($v2Breadcrumbs)
        @foreach($v2Breadcrumbs as $v2Crumb)
            @if(!$loop->first)
                <span class="v2-crumb-sep" aria-hidden="true">›</span>
            @endif
            @if($loop->last)
                <span class="v2-crumb-current">{{ $v2Crumb['label'] }}</span>
            @elseif($loop->first)
                @if(!empty($v2Crumb['url']))
                    <a class="v2-primary-crumb" href="{{ $v2Crumb['url'] }}">{{ $v2Crumb['label'] }}</a>
                @else
                    <span class="v2-primary-crumb">{{ $v2Crumb['label'] }}</span>
                @endif
            @else
                @if(!empty($v2Crumb['url']))
                    <a href="{{ $v2Crumb['url'] }}">{{ $v2Crumb['label'] }}</a>
                @else
                    <span>{{ $v2Crumb['label'] }}</span>
                @endif
            @endif
        @endforeach
    @endisset
</nav>
```

---

## Command Usage

```bash
# Generate admin breadcrumbs (most common)
php artisan breadcrumbs:generate --group=admin

# Generate vendor breadcrumbs
php artisan breadcrumbs:generate --group=vendor

# Include usage scanning for better parent detection
php artisan breadcrumbs:generate --group=admin --scan-usage

# Force re-scan (ignore cached usage map)
php artisan breadcrumbs:generate --group=admin --scan-usage --fresh

# Preview without writing files
php artisan breadcrumbs:generate --group=admin --dry-run
```

**When to regenerate:**
- After adding/removing/renaming routes
- After changing sidebar navigation structure
- After changing blade `@section('title')` values
- After adding new controllers

---

## Registration (AppServiceProvider)

The View Composer is registered in `AppServiceProvider::boot()`:

```php
if (!App::runningInConsole() && (Request::is('admin') || Request::is('admin/*')
    || Request::is('vendor') || Request::is('vendor/*'))) {
    View::composer(
        [
            'layouts.admin.partials.v2._header',
            'layouts.vendor.partials.v2._header',
        ],
        AdminBreadcrumbComposer::class
    );
}
```

---

## Data Flow Summary

```
Sidebar blade files          Controller files           Blade view files
(section, group, nav)        (return view/redirect)     (@section title)
         |                          |                         |
    SidebarParser            BladeViewResolver          BladeViewResolver
         |                          |                         |
         |                    non-page routes            blade titles
         |                    (excluded)                      |
         |                          |                         |
         └──────────┬───────────────┴─────────────────────────┘
                    |
             BreadcrumbBuilder
                    |
         app/Breadcrumbs/{group}.php
                    |
         AdminBreadcrumbComposer (runtime)
                    |
             $v2Breadcrumbs
                    |
         _header.blade.php (rendered HTML)
```
