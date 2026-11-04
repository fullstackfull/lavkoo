<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Repositories\RecentSearchRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Packages\AdvanceSearch\AdvanceSearch;
use App\Services\AdvancedSearchService;
use App\Utils\Helpers;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Permission-aware companion to AdvancedSearchController for the v2 sidebar
 * modal. Same response shape and view as v1, but result groups + recent
 * searches are filtered by the signed-in employee's module_access so a
 * limited-role employee never sees rows for sections their role hides.
 */
class V2AdvancedSearchController extends BaseController
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

    public function __construct(
        private readonly RecentSearchRepositoryInterface $recentSearchRepo,
        private readonly AdvancedSearchService           $advancedSearchService,
    )
    {
    }

    public function index(Request|null $request, ?string $type = null): View|RedirectResponse
    {
        //
    }

    public function getSearch(Request $request): JsonResponse
    {
        $userId = auth('admin')->id();
        $userType = 'admin';
        // `?keyword[]=x` hands the request an ARRAY, and casting one to string is a PHP
        // warning the error handler turns into a throw. This omnibox lives in the admin
        // header, so a 500 here is one crafted link away from any admin — a keyword nobody
        // can spell is simply not searched for, and the panel falls back to recent searches.
        $value = $request->input('keyword');
        $keyword = is_string($value) ? trim($value) : '';

        $auctionAvailable = function_exists('getCheckAddonPublishedStatus')
            && (bool)getCheckAddonPublishedStatus(moduleName: 'Auction');

        if ($keyword !== '') {
            if (!$auctionAvailable && str_contains(strtolower($keyword), 'auction')) {
                // Non-auction admin pages carry "auction" tokens in their search
                // keyword JSON (dashboard widget, category form, push templates,
                // settings copy), so without this short-circuit a user typing
                // "auction" while the addon is uninstalled gets a flood of
                // unrelated hits driven purely by keyword pollution.
                $result = [];
                return response()->json([
                    'keyword' => $keyword,
                    'result' => $result,
                    'htmlView' => view('layouts.admin.partials._advance-search-result', [
                        'result' => $result,
                        'keyword' => $keyword,
                        'recent' => false,
                    ])->render(),
                ]);
            }

            $advanceSearch = new AdvanceSearch('admin', $keyword);
            $result = $this->filterByPermission(collect($advanceSearch->searchAllList())->toArray());
            if (!$auctionAvailable) {
                $result = $this->stripAuctionEntries($result);
            }

            return response()->json([
                'keyword' => $keyword,
                'result' => $result,
                'htmlView' => view('layouts.admin.partials._advance-search-result', [
                    'result' => $result,
                    'keyword' => $keyword,
                    'recent' => false,
                ])->render(),
            ]);
        }

        $recentSearches = $this->recentSearchRepo->getListWhere(
            orderBy: ['created_at' => 'desc'],
            filters: ['user_id' => $userId, 'user_type' => $userType],
            dataLimit: 10,
        );
        $sorted = $this->advancedSearchService->getSortRecentSearchByType($recentSearches);
        $sorted = $this->filterByPermission($sorted);
        if (!$auctionAvailable) {
            $sorted = $this->stripAuctionEntries($sorted);
        }

        return response()->json([
            'keyword' => $keyword,
            'result' => $sorted,
            'htmlView' => view('layouts.admin.partials._advance-search-result', [
                'result' => $sorted,
                'keyword' => $keyword,
                'recent' => count($sorted) > 0,
            ])->render(),
        ]);
    }

    /**
     * Drop items the current employee isn't permitted to see. Operates on
     * the grouped-by-type array shape used by both searchAllList() and
     * getSortRecentSearchByType(). Empty groups are removed entirely so
     * the view doesn't render empty section headers.
     */
    private function filterByPermission(array $grouped): array
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
     * Drop any result item whose URI lives under an auction path. Used when
     * the Auction addon is not published — those routes are unregistered, so
     * surfacing them in search would lead to a broken link.
     */
    private function stripAuctionEntries(array $grouped): array
    {
        foreach ($grouped as $type => $items) {
            if ($type === 'auctions') {
                unset($grouped[$type]);
                continue;
            }
            $kept = [];
            foreach ($items as $item) {
                $itemArr = is_array($item) ? $item : (array)$item;
                $uri = ltrim((string)($itemArr['uri'] ?? $itemArr['route_uri'] ?? ''), '/');
                if ($uri === '' || !$this->isAuctionUri($uri)) {
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

    private function isAuctionUri(string $uri): bool
    {
        return str_starts_with($uri, 'admin/auction/')
            || str_starts_with($uri, 'vendor/auction/')
            || $uri === 'admin/business-settings/auction-config';
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
