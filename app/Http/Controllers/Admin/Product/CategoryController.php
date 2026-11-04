<?php

namespace App\Http\Controllers\Admin\Product;

use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\SeoMetaInfoRepositoryInterface;
use App\Contracts\Repositories\TranslationRepositoryInterface;
use App\Exports\CategoryListExport;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\CategoryAddRequest;
use App\Http\Requests\Admin\CategoryUpdateRequest;
use App\Services\CategoryService;
use App\Services\ProductService;
use App\Services\SeoMetaInfoService;
use App\Traits\PaginatorTrait;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Modules\TaxModule\app\Traits\VatTaxManagement;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CategoryController extends BaseController
{
    use PaginatorTrait;
    use VatTaxManagement;

    public function __construct(
        private readonly CategoryRepositoryInterface    $categoryRepo,
        private readonly ProductRepositoryInterface     $productRepo,
        private readonly ProductService                 $productService,
        private readonly SeoMetaInfoService             $seoMetaInfoService,
        private readonly CategoryService                $categoryService,
        private readonly SeoMetaInfoRepositoryInterface $seoMetaInfoRepo,
        private readonly TranslationRepositoryInterface $translationRepo,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        $taxData = $this->getTaxSystemType();
        $categoryWiseTax = $taxData['categoryWiseTax'];
        $taxVats = $taxData['taxVats'];

        $categories = $this->categoryRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['position' => 0],
            relations: $categoryWiseTax ? ['taxVats' => function ($query) {
                return $query->with(['tax'])->wherehas('tax', function ($query) {
                    return $query->where('is_active', 1);
                });
            }] : [],
            dataLimit: getWebConfig(name: 'pagination_limit')
        );

        $categoriesWithTrans = $this->categoryRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['position' => 0],
            relations: $categoryWiseTax ? ['taxVats' => function ($query) {
                return $query->with(['tax'])->wherehas('tax', function ($query) {
                    return $query->where('is_active', 1);
                });
            }, 'translations', 'seo'] : ['translations', 'seo'],
            dataLimit: getWebConfig(name: 'pagination_limit')
        );

        $languages = getWebConfig(name: 'pnc_language') ?? null;
        $defaultLanguage = $languages[0];
        return view('admin-views.category.view', [
            'categories' => $categories,
            'categoriesWithTrans' => $categoriesWithTrans,
            'languages' => $languages,
            'defaultLanguage' => $defaultLanguage,
            'taxVats' => $taxVats,
            'categoryWiseTax' => $categoryWiseTax,
            'categoryPosition' => 0,
        ]);
    }

    public function getUpdateView(Request $request): View|RedirectResponse
    {
        $category = $this->categoryRepo->getFirstWhere(params: ['id' => $request['id']], relations: ['translations']);

        // Opened without an id, or with one that no longer exists (a deleted category, a stale
        // bookmark, the bare /category/update URL): the form reads $category['position'] and
        // fatals on null, so the admin saw a 500 instead of "not found".
        if (!$category) {
            ToastMagic::error(translate('category_not_found') . '!');
            return redirect()->route('admin.category.view');
        }

        $languages = getWebConfig(name: 'pnc_language') ?? null;
        $defaultLanguage = $languages[0];

        return view('admin-views.category.category-edit', [
            'category' => $category,
            'languages' => $languages,
            'defaultLanguage' => $defaultLanguage,
            // A sub-category's form offers the main category it sits under, so it can be moved.
            // The view has always looped over this list; the controller never passed it, which
            // fatal'd ("Undefined variable $parentCategories") on every sub-category edit.
            'parentCategories' => $category && $category['position'] > 0
                ? $this->categoryRepo->getListWhere(
                    orderBy: ['name' => 'asc'],
                    filters: ['position' => 0],
                    relations: ['translations'],
                    dataLimit: 'all',
                )
                : collect(),
        ]);
    }

    public function add(CategoryAddRequest $request): RedirectResponse|JsonResponse
    {
        $dataArray = $this->categoryService->getAddData(request: $request);
        $savedCategory = $this->categoryRepo->add(data: $dataArray);
        $this->translationRepo->add(request: $request, model: 'App\Models\Category', id: $savedCategory->id);

        $request->merge(['meta_index' => 'index']);
        $request->merge(['meta_no_follow' => 0]);

        $seoMetaData = $this->seoMetaInfoService->getModelSEOData(request: $request, seoMetaInfo: $savedCategory?->seo, type: 'App\Models\Category', modelId: $savedCategory->id, action: 'add');
        $this->seoMetaInfoRepo->add(data: $seoMetaData);

        if ($savedCategory['position'] == 0) {
            $this->getAddTaxData(
                taxableType: \App\Models\Category::class,
                taxableId: $savedCategory->id,
                taxIds: $request['tax_ids'] ?? []
            );
        }

        app(\App\Services\EntityPageBannerService::class)->sync(entity: 'category', resourceId: (int) $savedCategory->id, image: $request->file('page_banner'));

        updateSetupGuideCacheKey(key: 'category_setup', panel: 'admin');

        if ($request->ajax()) {
            return response()->json([
                'status' => 'success',
                'message' => translate('category_added_successfully'),
                'redirect_url' => route('admin.category.view'),
            ]);
        }

        ToastMagic::success(translate('category_added_successfully'));
        return back();
    }

    public function update(CategoryUpdateRequest $request): RedirectResponse
    {
        $category = $this->categoryRepo->getFirstWhere(params: ['id' => $request['id']], relations: ['seo']);

        if ($category['position'] == 1 && $category['parent_id'] != $request['parent_id']) {
            $this->productRepo->updateByParams(
                ['sub_category_id' => $category['id']],
                [
                    'category_id' => $request['parent_id'],
                    // parent_id is interpolated into raw SQL, so it MUST be an integer cast — otherwise a
                    // crafted parent_id is a SQL injection. Category ids are integers, so the cast is lossless.
                    'category_ids' => DB::raw("JSON_SET(CAST(category_ids AS JSON), '$[0].id', '" . (int) $request['parent_id'] . "')")
                ]
            );
        }

        $oldSlug = $category->slug ?? null;
        $dataArray = $this->categoryService->getUpdateData(request: $request, data: $category);
        $this->categoryRepo->update(id: $request['id'], data: $dataArray);
        $this->translationRepo->update(request: $request, model: 'App\Models\Category', id: $request['id']);

        // Preserve SEO value on a slug change: auto-create a 301 from the old category URL to the new one.
        app(\App\Services\Seo\SlugRedirectSuggester::class)->suggest('category', $oldSlug, $dataArray['slug'] ?? $oldSlug);

        $request->merge(['meta_index' => 'index']);
        $request->merge(['meta_no_follow' => 0]);

        $seoMetaData = $this->seoMetaInfoService->getModelSEOData(request: $request, seoMetaInfo: $category?->seo, type: 'App\Models\Category', modelId: $category->id, action: 'update');
        $this->seoMetaInfoRepo->updateOrInsert(params: ['seoable_type' => 'App\Models\Category', 'seoable_id' => $category['id']], data: $seoMetaData);

        $taxVatIds = $category?->taxVats?->pluck('tax_id')->toArray() ?? [];
        $this->getUpdateTaxData(
            taxableType: \App\Models\Category::class,
            taxableId: $category['id'],
            taxIds: $request['tax_ids'] ?? [],
            oldTaxIds: $taxVatIds
        );

        app(\App\Services\EntityPageBannerService::class)->sync(entity: 'category', resourceId: (int) $category->id, image: $request->file('page_banner'));

        updateSetupGuideCacheKey(key: 'category_setup', panel: 'admin');

        if ($category['position'] == 1) {
            ToastMagic::success(translate('Sub_Category_updated_successfully'));
        } elseif ($category['position'] == 2) {
            ToastMagic::success(translate('Sub_Sub_Category_updated_successfully'));
        } else {
            ToastMagic::success(translate('category_updated_successfully'));
        }
        return back();
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $statusType = $request['status_type'] ?? 'home_status';
        $allowedStatusTypes = ['home_status', 'auction_home_status'];
        abort_unless(in_array($statusType, $allowedStatusTypes, true), 400);

        $data = [
            $statusType => $request->boolean($statusType),
        ];

        $this->categoryRepo->update(id: $request['id'], data: $data);
        updateSetupGuideCacheKey(key: 'category_setup', panel: 'admin');
        return response()->json([
            'success' => 1,
            'message' => $statusType == 'home_status' ? translate('Status_updated_successfully!') :  translate('Auction home status updated successfully!')
        ], 200);
    }

    public function delete(Request $request): RedirectResponse
    {
        $this->productRepo->updateByParams(params: ['category_id' => $request['id']], data: ['category_ids' => json_encode($this->productService->getCategoriesArray(request: $request)), 'category_id' => $request['category_id'], 'sub_category_id' => null, 'sub_sub_category_id' => null]);
        $category = $this->categoryRepo->getFirstWhere(params: ['id' => $request['id']], relations: ['childes.childes']);
        $this->categoryService->deleteImages(data: $category);
        $this->categoryRepo->delete(params: ['id' => $request['id']]);
        ToastMagic::success(translate('deleted_successfully'));
        return redirect()->back();
    }

    public function getExportList(Request $request): BinaryFileResponse
    {
        $taxData = $this->getTaxSystemType();
        $categoryWiseTax = $taxData['categoryWiseTax'];
        $taxVats = $taxData['taxVats'];

        $categories = $this->categoryRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['position' => 0],
            relations: $categoryWiseTax ? ['taxVats' => function ($query) {
                return $query->with(['tax'])->wherehas('tax', function ($query) {
                    return $query->where('is_active', 1);
                });
            }] : [],
            dataLimit: 'all');
        $active = $categories->where('home_status', 1)->count();
        $inactive = $categories->where('home_status', 0)->count();
        return Excel::download(new CategoryListExport([
            'categories' => $categories,
            'title' => 'category',
            'search' => $request['searchValue'],
            'active' => $active,
            'inactive' => $inactive,
            'category_wise_tax' => $categoryWiseTax,
        ]), 'category-list.xlsx'
        );
    }
}
