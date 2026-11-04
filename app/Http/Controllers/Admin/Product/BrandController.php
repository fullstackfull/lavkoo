<?php

namespace App\Http\Controllers\Admin\Product;

use App\Contracts\Repositories\BrandRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\SeoMetaInfoRepositoryInterface;
use App\Contracts\Repositories\TranslationRepositoryInterface;
use App\Exports\BrandListExport;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\BrandAddRequest;
use App\Http\Requests\Admin\BrandUpdateRequest;
use App\Services\BrandService;
use App\Services\SeoMetaInfoService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BrandController extends BaseController
{
    public function __construct(
        private readonly BrandRepositoryInterface       $brandRepo,
        private readonly ProductRepositoryInterface     $productRepo,
        private readonly TranslationRepositoryInterface $translationRepo,
        private readonly SeoMetaInfoService             $seoMetaInfoService,
        private readonly SeoMetaInfoRepositoryInterface $seoMetaInfoRepo
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        $brands = $this->brandRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            relations: ['storage','seo'],
            dataLimit: getWebConfig(name: 'pagination_limit')
        );
        $language = getWebConfig(name: 'pnc_language') ?? [];
        $defaultLanguage = $language[0];
        return view('admin-views.brand.list', compact('brands', 'language', 'defaultLanguage'));
    }


    public function getAddView(): View
    {
        $language = getWebConfig(name: 'pnc_language') ?? null;
        $defaultLanguage = $language[0];
        return view('admin-views.brand.add-new', compact('language', 'defaultLanguage'));
    }

    public function getUpdateView(string|int $id): View|RedirectResponse
    {
        $brand = $this->brandRepo->getFirstWhere(params: ['id' => $id], relations: ['translations', 'storage', 'seo']);
        $language = getWebConfig(name: 'pnc_language') ?? null;
        $defaultLanguage = $language[0];
        return view('admin-views.brand.edit', compact('brand', 'language', 'defaultLanguage'));
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $data = [
            'status' => $request->get('status', 0),
        ];
        $this->brandRepo->update(id: $request['id'], data: $data);
        return response()->json(['success' => 1, 'message' => translate('status_updated_successfully')], 200);
    }

    public function delete(Request $request, BrandService $brandService): RedirectResponse
    {
        $products = $this->productRepo->getListWhere(filters: ['brand_id' => $request['id']], dataLimit: 'all');
        foreach ($products as $product) {
            $this->productRepo->updateByParams(params: ['id' => $product['id'], 'brand_id' => $request['id']], data: ['brand_id' => $request['brand_id'], 'sub_category_id' => null, 'sub_sub_category_id' => null]);
        }
        $brand = $this->brandRepo->getFirstWhere(params: ['id' => $request['id']]);
        $brandService->deleteImage(data: $brand);
        $this->translationRepo->delete(model: 'App\Models\Brand', id: $request['id']);
        $this->brandRepo->delete(params: ['id' => $request['id']]);
        ToastMagic::success(translate('brand_deleted_successfully'));
        return redirect()->back();
    }


    public function add(BrandAddRequest $request, BrandService $brandService): RedirectResponse
    {
        $dataArray = $brandService->getAddData(request: $request);
        $savedBrand = $this->brandRepo->add(data: $dataArray);
        $this->translationRepo->add(request: $request, model: 'App\Models\Brand', id: $savedBrand->id);

        $seoMetaData = $this->seoMetaInfoService->getModelSEOData(request: $request, seoMetaInfo: $savedBrand?->seo, type: 'App\Models\Brand', modelId: $savedBrand->id, action: 'add');
        $this->seoMetaInfoRepo->add(data: $seoMetaData);

        app(\App\Services\EntityPageBannerService::class)->sync(entity: 'brand', resourceId: (int) $savedBrand->id, image: $request->file('page_banner'));

        updateSetupGuideCacheKey(key: 'brand_setup', panel: 'admin');
        ToastMagic::success(translate('brand_added_successfully'));
        return redirect()->route('admin.brand.list');
    }

    public function update(BrandUpdateRequest $request, $id, BrandService $brandService): RedirectResponse
    {
        $brand = $this->brandRepo->getFirstWhere(params: ['id' => $request['id']], relations: ['storage', 'seo']);
        $oldSlug = $brand->slug ?? null;
        $dataArray = $brandService->getUpdateData(request: $request, data: $brand);
        $this->brandRepo->update(id: $request['id'], data: $dataArray);
        $this->translationRepo->update(request: $request, model: 'App\Models\Brand', id: $request['id']);

        // Preserve SEO value on a slug change: auto-create a 301 from the old brand URL to the new one.
        app(\App\Services\Seo\SlugRedirectSuggester::class)->suggest('brand', $oldSlug, $dataArray['slug'] ?? $oldSlug);

        $seoMetaData = $this->seoMetaInfoService->getModelSEOData(request: $request, seoMetaInfo: $brand?->seo, type: 'App\Models\Brand', modelId: $brand->id, action: 'update');
        $this->seoMetaInfoRepo->updateOrInsert(params: ['seoable_type' => 'App\Models\Brand', 'seoable_id' => $brand['id']], data: $seoMetaData);

        app(\App\Services\EntityPageBannerService::class)->sync(entity: 'brand', resourceId: (int) $brand->id, image: $request->file('page_banner'));

        updateSetupGuideCacheKey(key: 'brand_setup', panel: 'admin');
        ToastMagic::success(translate('brand_updated_successfully'));
        return redirect()->route('admin.brand.list');
    }

    public function exportList(Request $request): BinaryFileResponse
    {
        $brands = $this->brandRepo->getListWhere(searchValue: requestString('searchValue') ?: null, dataLimit: 'all');
        $active = $this->brandRepo->getListWhere(filters: ['status' => 1], dataLimit: 'all')->count();
        $inactive = $this->brandRepo->getListWhere(filters: ['status' => 0], dataLimit: 'all')->count();
        return Excel::download(new BrandListExport(
            [
                'brands' => $brands,
                'search' => $request['search'],
                'active' => $active,
                'inactive' => $inactive,
            ]), 'Brand-list.xlsx');
    }

    public function loadMoreBrands(Request $request): JsonResponse
    {
        $oldBrands = $request['filter_brand_old_ids'] ? json_decode($request['filter_brand_old_ids']) : [];
        $page = $request->input('page', 1);
        $filterBrands = $this->brandRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            filters: ['position' => 0],
            dataLimit: 8,
            offset: $page);

        $visibleLimit = $filterBrands->perPage();
        $totalBrands = $filterBrands->total();
        $hiddenCount = $totalBrands - ($page * $visibleLimit);


        return response()->json([
            'html' => view('admin-views.partials.product-filters._filter-brands', [
                'filterBrands' => $filterBrands,
                'oldBrands' => $oldBrands,
            ])->render(),
            'visibleLimit' => $visibleLimit,
            'hiddenCount' => max(0, $hiddenCount),
            'totalBrands' => $totalBrands,
        ]);
    }
}
