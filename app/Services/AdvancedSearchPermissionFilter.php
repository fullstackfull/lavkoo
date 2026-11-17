<?php

namespace App\Services;

use App\Packages\AdvanceSearch\AdvanceSearch;
use App\Utils\Helpers;

/**
 * Drops from the header search what the signed-in employee's role does not hold. The search package filters only its menu hits by module;
 * the customers, vendors, orders, coupons and the rest it finds in the database come back for every employee, so both search controllers
 * (the classic header and the v2 sidebar modal) run what they answer, fresh results and recent searches alike, through here.
 */
class AdvancedSearchPermissionFilter
{
    /**
     * Map of result-group "type" → module-permission key. Types come from
     * the keys returned by AdvanceSearch::searchAllList() / getModels() and
     * the buckets in AdvancedSearchService::getSortRecentSearchByType().
     */
    private const TYPE_TO_MODULE = [
        'products' => 'catalog',
        'categories' => 'catalog',
        'brands' => 'catalog',
        'orders' => 'orders',
        'refund_requests' => 'orders',
        'users' => 'people',
        'sellers' => 'people',
        'delivery_men' => 'people',
        'subscriptions' => 'people',
        'coupons' => 'marketing',
        'blogs' => 'marketing',
        'contacts' => 'help_and_support',
        'business_page' => 'business_settings',
    ];

    /**
     * Lazy-built URI → module-permission key map, derived per-request from
     * AdvanceSearch::adminMenuWithRoutes(). Each menu entry there already
     * declares its "module" — that's the same field
     * AdminMenuWithRoutesTrait::getAdminMenuWithRoutes() filters on, so we
     * inherit a single source of truth and avoid maintaining a parallel
     * hard-coded list that drifts whenever a menu item is added or moved.
     *
     * @var array<string, string>|null
     */
    private ?array $uriToModuleCache = null;

    /**
     * Drop items the current employee isn't permitted to see. Operates on
     * the grouped-by-type array shape used by both searchAllList() and
     * getSortRecentSearchByType(). Empty groups are removed entirely so
     * the view doesn't render empty section headers.
     */
    public function filterByPermission(array $grouped): array
    {
        foreach ($grouped as $type => $items) {
            $kept = [];
            foreach ($items as $item) {
                $itemArr = is_array($item) ? $item : (array)$item;
                $uri = (string)($itemArr['uri'] ?? $itemArr['route_uri'] ?? '');
                $module = $this->resolveModule((string)$type, $uri);
                if ($module === null || Helpers::module_permission_check($module)) {
                    $kept[] = $item;
                }
            }
            if (empty($kept)) {
                unset($grouped[$type]);
            } else {
                $grouped[$type] = array_values($kept);
            }
        }

        return $grouped;
    }

    /**
     * Resolve a module key for a result item. Returns null when neither
     * the entity type nor the URI matches anything we know — caller treats
     * null as "allow" so unmapped routes (e.g. third-party addon pages)
     * stay searchable instead of silently disappearing.
     */
    private function resolveModule(string $type, string $uri): ?string
    {
        if (isset(self::TYPE_TO_MODULE[$type])) {
            return self::TYPE_TO_MODULE[$type];
        }

        $uri = ltrim($uri, '/');
        if ($uri === '') {
            return null;
        }

        $map = $this->getUriToModuleMap();
        if (isset($map[$uri])) {
            return $map[$uri];
        }

        // Longest-prefix fallback for dynamic URIs that share a path root
        // with a menu entry (e.g. admin/products/update/15 picks up the
        // module from admin/products/list/in-house's "catalog").
        $bestPrefix = '';
        $bestModule = null;
        foreach ($map as $menuUri => $module) {
            $root = $this->uriRoot($menuUri);
            if ($root !== '' && str_starts_with($uri, $root . '/') && strlen($root) > strlen($bestPrefix)) {
                $bestPrefix = $root;
                $bestModule = $module;
            }
        }

        return $bestModule;
    }

    /**
     * Build the URI → module map by reading AdvanceSearch's menu table —
     * the same data getAdminMenuWithRoutes() filters by permission, so
     * adding a new sidebar entry there flows straight through to advance
     * search permission filtering with no controller change required.
     *
     * Entries with an empty "module" (dashboard, etc.) are skipped: those
     * pages are visible to everyone, so leaving them out makes the resolver
     * fall through to "allow" rather than gating them on a non-existent key.
     *
     * @return array<string, string>
     */
    private function getUriToModuleMap(): array
    {
        if ($this->uriToModuleCache !== null) {
            return $this->uriToModuleCache;
        }
        $map = [];
        $advanceSearch = new AdvanceSearch('admin', '');
        foreach ($advanceSearch->adminMenuWithRoutes() as $entry) {
            $uri = ltrim((string)($entry['uri'] ?? ''), '/');
            $module = (string)($entry['module'] ?? '');
            if ($uri !== '' && $module !== '' && !isset($map[$uri])) {
                $map[$uri] = $module;
            }
        }
        return $this->uriToModuleCache = $map;
    }

    /**
     * Trim the trailing detail segment (status/id) off a menu URI so it can
     * be used as a path prefix for dynamic detail routes — e.g. the menu
     * entry "admin/orders/list/all" yields the root "admin/orders" so a
     * search hit on "admin/orders/details/15" resolves to the same module.
     */
    private function uriRoot(string $uri): string
    {
        $parts = explode('/', $uri);
        if (count($parts) <= 2) {
            return $uri;
        }
        return $parts[0] . '/' . $parts[1];
    }
}
