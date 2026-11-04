<?php

namespace App\Http\Controllers\Admin\Promotion;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Contracts\Repositories\FlashDealRepositoryInterface;
use App\Http\Controllers\BaseController;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class FeaturedDealController extends BaseController
{
    /**
     * @param FlashDealRepositoryInterface $flashDealRepo
     * @param BusinessSettingRepositoryInterface $businessSettingRepo
     */
    public function __construct(
        private readonly FlashDealRepositoryInterface       $flashDealRepo,
        private readonly BusinessSettingRepositoryInterface $businessSettingRepo,
    )
    {
    }

    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        $flashDeals = $this->flashDealRepo->getListWithRelations(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['deal_type' => 'feature_deal'],
            withCount: ['products' => 'products'],
            dataLimit: getWebConfig('pagination_limit')
        );
        $featureDealPriority = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'feature_deal_priority'])['value']);
        return view('admin-views.deal.feature-index', compact('flashDeals', 'featureDealPriority'));
    }

    public function getAddView(Request $request): View
    {
        $language = getWebConfig(name: 'pnc_language') ?? null;
        $defaultLanguage = $language[0];
        return view('admin-views.deal.feature-add', compact('language', 'defaultLanguage'));
    }

    public function getUpdateView($deal_id): View
    {
        $language = getWebConfig(name: 'pnc_language') ?? null;
        $defaultLanguage = $language[0];
        $deal = $this->flashDealRepo->getFirstWhereWithoutGlobalScope(params: ['id' => $deal_id], relations: ['seo']);
        return view('admin-views.deal.feature-update', compact('language', 'defaultLanguage', 'deal'));
    }

    public function update(Request $request): JsonResponse
    {
        $this->flashDealRepo->update(id: $request['id'], data: ['featured' => $request['featured']]);
        cacheRemoveByType(type: 'products');
        return response()->json([
            'success' => 1,
        ], 200);
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $this->flashDealRepo->updateWhere(params: ['status' => 1, 'deal_type' => 'feature_deal'], data: ['status' => 0]);
        $this->flashDealRepo->update(id: $request['id'], data: ['status' => $request->get('status', 0)]);
        cacheRemoveByType(type: 'products');
        return response()->json([
            'success' => 1,
            'message' => translate('Feature_deal_status_updated!')
        ], 200);
    }
}
