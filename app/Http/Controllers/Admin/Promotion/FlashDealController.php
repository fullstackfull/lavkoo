<?php

namespace App\Http\Controllers\Admin\Promotion;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Contracts\Repositories\FlashDealProductRepositoryInterface;
use App\Contracts\Repositories\FlashDealRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\SeoMetaInfoRepositoryInterface;
use App\Contracts\Repositories\TranslationRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\FlashDealAddRequest;
use App\Http\Requests\Admin\FlashDealUpdateRequest;
use App\Http\Requests\Admin\ProductIDRequest;
use App\Services\FlashDealService;
use App\Services\SeoMetaInfoService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class FlashDealController extends BaseController
{
    /**
     * @param ProductRepositoryInterface $productRepo
     * @param FlashDealProductRepositoryInterface $flashDealProductRepo
     * @param FlashDealRepositoryInterface $flashDealRepo
     * @param TranslationRepositoryInterface $translationRepo
     * @param BusinessSettingRepositoryInterface $businessSettingRepo
     * @param SeoMetaInfoService $seoMetaInfoService
     * @param SeoMetaInfoRepositoryInterface $seoMetaInfoRepo
     */
    public function __construct(
        private readonly ProductRepositoryInterface          $productRepo,
        private readonly FlashDealProductRepositoryInterface $flashDealProductRepo,
        private readonly FlashDealRepositoryInterface        $flashDealRepo,
        private readonly TranslationRepositoryInterface      $translationRepo,
        private readonly BusinessSettingRepositoryInterface  $businessSettingRepo,
        private readonly SeoMetaInfoService                  $seoMetaInfoService,
        private readonly SeoMetaInfoRepositoryInterface      $seoMetaInfoRepo
    )
    {
    }

    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        $flashDeals = $this->flashDealRepo->getListWithRelations(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['deal_type' => 'flash_deal'],
            withCount: ['products' => 'products'],
            dataLimit: getWebConfig('pagination_limit')
        );
        $flashDealPriority = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'flash_deal_priority'])['value']);
        return view('admin-views.deal.flash-index', compact('flashDeals', 'flashDealPriority'));
    }

    public function getAddView(Request $request): View
    {
        $language = getWebConfig(name: 'pnc_language') ?? null;
        $defaultLanguage = $language[0];
        return view('admin-views.deal.flash-add', compact('language', 'defaultLanguage'));
    }

    public function add(FlashDealAddRequest $request, FlashDealService $flashDealService): RedirectResponse
    {
        $dataArray = $flashDealService->getAddData(request: $request);
        $savedFlashDeal = $this->flashDealRepo->add(data: $dataArray);
        $this->translationRepo->add(request: $request, model: 'App\Models\FlashDeal', id: $savedFlashDeal->id);

        $seoMetaData = $this->seoMetaInfoService->getModelSEOData(request: $request, seoMetaInfo: $savedFlashDeal?->seo, type: 'App\Models\FlashDeal', modelId: $savedFlashDeal->id, action: 'add');
        $this->seoMetaInfoRepo->add(data: $seoMetaData);

        ToastMagic::success(translate('deal_added_successfully'));

        if ($request['deal_type'] == 'flash_deal') {
            return redirect()->route('admin.deal.flash');
        }
        return redirect()->route('admin.deal.feature');
    }

    public function getUpdateView($deal_id): View
    {
        $language = getWebConfig(name: 'pnc_language') ?? null;
        $defaultLanguage = $language[0];
        $deal = $this->flashDealRepo->getFirstWhereWithoutGlobalScope(params: ['id' => $deal_id], relations: ['translations', 'seo']);
        return view('admin-views.deal.flash-update', compact('language', 'defaultLanguage', 'deal'));
    }

    public function update(FlashDealUpdateRequest $request, $deal_id, FlashDealService $flashDealService): RedirectResponse
    {
        $deal = $this->flashDealRepo->getFirstWhere(params: ['id' => $deal_id], relations: ['seo']);
        $dataArray = $flashDealService->getUpdateData(request: $request, data: $deal);
        $this->flashDealRepo->update(id: $request['id'], data: $dataArray);
        $this->translationRepo->update(request: $request, model: 'App\Models\FlashDeal', id: $request['id']);

        $seoMetaData = $this->seoMetaInfoService->getModelSEOData(request: $request, seoMetaInfo: $deal?->seo, type: 'App\Models\FlashDeal', modelId: $deal->id, action: 'update');
        $this->seoMetaInfoRepo->updateOrInsert(params: ['seoable_type' => 'App\Models\FlashDeal', 'seoable_id' => $deal['id']], data: $seoMetaData);

        if ($request['deal_type'] == 'feature_deal') {
            ToastMagic::success(translate('feature_deal_updated_successfully'));
        } else {
            ToastMagic::success(translate('deal_updated_successfully'));
            return redirect()->route('admin.deal.flash');
        }
        return back();
    }

    public function updateStatus(Request $request): RedirectResponse
    {
        $this->flashDealRepo->updateWhere(params: ['status' => 1, 'deal_type' => 'flash_deal'], data: ['status' => 0]);
        $this->flashDealRepo->update(id: $request['id'], data: ['status' => $request->get('status', 0)]);
        ToastMagic::success(translate('Flash_deal_status_updated'));
        return redirect()->route('admin.deal.flash');
    }

    public function getAddProductView($deal_id): View
    {
        $products = $this->productRepo->getListWithScope(
            scope: "active",
            relations: ['brand', 'category', 'seller.shop'],
            dataLimit: 20);

        $flashDealProducts = $this->flashDealProductRepo
            ->getListWhere(filters: ['flash_deal_id' => $deal_id], dataLimit: 'all')
            ->pluck('product_id')->toArray();

        $deal = $this->flashDealRepo->getFirstWhere(params: ['id' => $deal_id], relations: ['products.product']);

        $dealProducts = $this->productRepo->getListWithScope(
            orderBy: ['id' => 'desc'],
            scope: "active",
            whereIn: ['id' => $flashDealProducts],
            relations: ['brand', 'category', 'seller.shop'],
            dataLimit: getWebConfig('pagination_limit'));

        if (!empty($deal_id)) {
            $assignedProductIds = $this->flashDealProductRepo->getListWhere(filters: ['flash_deal_id' => $deal_id])->pluck('product_id')->toArray();
            $productsNotInDeal = $products->filter(function ($product) use ($assignedProductIds) {
                return !in_array($product->id, $assignedProductIds);
            });
            $products = $productsNotInDeal;
        }
        return view('admin-views.deal.add-product', compact('deal', 'products', 'dealProducts', 'deal_id'));
    }

    public function addProduct(ProductIDRequest $request, $deal_id, FlashDealService $flashDealService): RedirectResponse
    {
        foreach ($request['product_id'] as $key => $productId) {
            $flashDealProducts = $this->flashDealProductRepo->getFirstWhere(params: ['flash_deal_id' => $deal_id, 'product_id' => $productId]);
            if (!$flashDealProducts) {
                $dataArray = $flashDealService->getAddProduct(request: $request, productId: $productId, id: $deal_id);
                $this->flashDealProductRepo->add(data: $dataArray);
            }
        }
        cacheRemoveByType(type: 'products');
        ToastMagic::success(translate('product_added_successfully'));
        return back();
    }

    public function delete(Request $request): JsonResponse
    {
        $this->flashDealProductRepo->delete(params: ['product_id' => $request['id']]);
        return response()->json(['message' => translate('product_removed_successfully')], 200);
    }

    public function search(Request $request): JsonResponse
    {
        $products = $this->productRepo->getListWithScope(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            scope: "active",
            relations: ['brand', 'category', 'seller.shop'],
            dataLimit: 'all');
        return response()->json([
            'result' => view('admin-views.partials._search-product', compact('products'))->render(),
        ]);
    }


}
