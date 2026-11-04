<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Repositories\RecentSearchRepositoryInterface;
use App\Services\AdvancedSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\BaseController;
use App\Packages\AdvanceSearch\AdvanceSearch;

class AdvancedSearchController extends BaseController
{

    public function __construct(
        private readonly RecentSearchRepositoryInterface $recentSearchRepo,
        private readonly AdvancedSearchService           $advancedSearchService,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View|RedirectResponse Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View|RedirectResponse
    {
        //
    }


    public function getSearch(Request $request): JsonResponse
    {
        $userId = auth('admin')->user()->id;
        $userType = 'admin';
        // `?keyword[]=x` makes AdvanceSearch's `?string $keyword` a TypeError, and `?? ''`
        // never catches it because an array is not null. This omnibox lives in the admin
        // header, so a 500 here is one crafted link away from any admin — a keyword nobody
        // can spell is simply not searched for, and the panel falls back to recent searches.
        $value = $request->input('keyword');
        $keyword = is_string($value) ? trim($value) : '';
        $advanceSearch = new AdvanceSearch('admin', $keyword);

        if (!empty($keyword)) {
            $result = collect($advanceSearch->searchAllList());
            return response()->json([
                'keyword' => $keyword,
                'result' => $result,
                'htmlView' => view('layouts.admin.partials._advance-search-result', [
                    'result' => $result,
                    'keyword' => $keyword,
                    'recent' => false,
                ])->render()
            ]);
        }

        $recentSearches = $this->recentSearchRepo->getListWhere(orderBy: ['created_at' => 'desc'], filters: [
            'user_id' => $userId,
            'user_type' => $userType
        ], dataLimit: 10);

        $finalData = $this->advancedSearchService->getSortRecentSearchByType($recentSearches);
        return response()->json([
            'keyword' => $keyword,
            'result' => $finalData,
            'htmlView' => view('layouts.admin.partials._advance-search-result', [
                'result' => $finalData,
                'keyword' => $keyword,
                'recent' => count($finalData) > 0 ? true : false,
            ])->render()
        ]);

    }


    public function recentSearch(Request $request): RedirectResponse
    {
        $userId = auth('admin')->id();
        $userType = "admin";
        $title = $request['routeName'];
        $routeUri = $request['routeUri'];
        $routeFullUrl = $request['routeFullUrl'];
        $searchKeyword = $request['searchKeyword'];
        $response = $request['response'];

        $params = [
            'user_id' => $userId,
            'user_type' => $userType,
            'route_uri' => $routeUri,
        ];

        $data = [
            'title' => $title,
            'keyword' => $searchKeyword,
            'response' => json_encode($response),
            'route_full_url' => isset($searchKeyword) ? $routeFullUrl . '?keyword=' . $searchKeyword : $routeFullUrl,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->recentSearchRepo->updateOrInsert(params: $params, data: $data);

        $userClicksCount = $this->recentSearchRepo->getListWhere(filters: ['user_id' => $userId, 'user_type' => $userType])->count();
        if ($userClicksCount >= 10) {
            $recentItem = $this->recentSearchRepo->getListWhere(orderBy: ['created_at' => 'asc'], filters: ['user_id' => $userId, 'user_type' => $userType], dataLimit: 1);
            if ($recentItem->first()) {
                $this->recentSearchRepo->delete(params: ['id' => $recentItem->first()?->id]);
            }
        }
        $redirectUrl = $request['routeFullUrl'] . '?keyword=' . urlencode($searchKeyword);
        return redirect($redirectUrl);
    }
}
