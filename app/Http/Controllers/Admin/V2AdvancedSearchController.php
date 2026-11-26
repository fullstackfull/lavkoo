<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Repositories\RecentSearchRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Packages\AdvanceSearch\AdvanceSearch;
use App\Services\AdvancedSearchPermissionFilter;
use App\Services\AdvancedSearchService;
use App\Services\Staff\StaffCountryScope;
use App\Services\Staff\StaffSearchHits;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Companion to AdvancedSearchController for the v2 sidebar modal. Same
 * response shape and view as v1, and the same permission filter on the
 * result groups and the recent searches; on top of it, it drops the
 * entries of the Auction add-on while that add-on is not published.
 */
class V2AdvancedSearchController extends BaseController
{
    public function __construct(
        private readonly RecentSearchRepositoryInterface $recentSearchRepo,
        private readonly AdvancedSearchService           $advancedSearchService,
        private readonly AdvancedSearchPermissionFilter  $permissionFilter,
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
            $result = $this->permissionFilter->filterByPermission(grouped: collect($advanceSearch->searchAllList())->toArray());
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
        $sorted = $this->permissionFilter->filterByPermission(grouped: $sorted);
        // The same narrowing as the classic header's, for the same reason (G-06): a recent hit saved before the employee was restricted
        // still names, titles and links the row it named then.
        $sorted = app(StaffSearchHits::class)->keepRecent(
            grouped: $sorted,
            countries: app(StaffCountryScope::class)->countriesOf(admin: auth('admin')->user()),
        );
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
}
