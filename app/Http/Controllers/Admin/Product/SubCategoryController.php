<?php

namespace App\Http\Controllers\Admin\Product;

use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Contracts\Repositories\SeoMetaInfoRepositoryInterface;
use App\Contracts\Repositories\TranslationRepositoryInterface;
use App\Exports\CategoryListExport;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\SubCategoryUpdateRequest;
use App\Http\Requests\Admin\SubCategoryAddRequest;
use App\Services\CategoryService;
use App\Services\SeoMetaInfoService;
use App\Traits\PaginatorTrait;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SubCategoryController extends BaseController
{
    use PaginatorTrait;

    public function __construct(
        private readonly CategoryRepositoryInterface    $categoryRepo,
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
     * @return View Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        $parentCategoryIDs = $request['categories'] ?? [];

        if ($request['sort_by'] == 'latest') {
            $orderBy = ['created_at' => 'desc'];
        } elseif ($request['sort_by'] == 'oldest') {
            $orderBy = ['created_at' => 'asc'];
        } elseif ($request['sort_by'] == 'a-z') {
            $orderBy = ['name' => 'asc'];
        } elseif ($request['sort_by'] == 'z-a') {
            $orderBy = ['name' => 'desc'];
        } else  {
            $orderBy = ['updated_at' => 'desc'];
        }

        $categories = $this->categoryRepo->getListWhereIn(
            orderBy: $orderBy,
            searchValue: requestString('searchValue') ?: null,
            filters: ['position' => 1],
            whereIn: ['parent_id' => $parentCategoryIDs],
            relations: ['parent'],
            dataLimit: getWebConfig(name: 'pagination_limit'));

        $categoriesWithTrans = $this->categoryRepo->getListWhereIn(
            orderBy: $orderBy,
            searchValue: requestString('searchValue') ?: null,
            filters: ['position' => 1],
            whereIn: ['parent_id' => $parentCategoryIDs],
            relations: ['translations', 'seo'],
            dataLimit: getWebConfig(name: 'pagination_limit'));

        $parentCategories = $this->categoryRepo->getListWhere(
            orderBy: ['name' => 'asc'],
            filters: ['position' => 0],
            dataLimit: 'all');

        $filterParentCategories = $this->categoryRepo->getListWhere(
            orderBy: ['name' => 'asc'],
            filters: ['position' => 0],
            dataLimit: $request['categories'] ? 1000 : 5);

        $languages = getWebConfig(name: 'pnc_language') ?? null;
        $defaultLanguage = $languages[0];

        return view('admin-views.category.sub-category-view', [
            'categories' => $categories,
            'categoriesWithTrans' => $categoriesWithTrans,
            'parentCategories' => $parentCategories,
            'filterParentCategories' => $filterParentCategories,
            'languages' => $languages,
            'defaultLanguage' => $defaultLanguage,
        ]);
    }

    public function add(SubCategoryAddRequest $request, CategoryService $categoryService): RedirectResponse
    {
        $dataArray = $categoryService->getAddData(request: $request);
        $savedCategory = $this->categoryRepo->add(data: $dataArray);
        $this->translationRepo->add(request: $request, model: 'App\Models\Category', id: $savedCategory->id);
        $seoMetaData = $this->seoMetaInfoService->getModelSEOData(request: $request, seoMetaInfo: $savedCategory?->seo, type: 'App\Models\Category', modelId: $savedCategory->id, action: 'add');
        $this->seoMetaInfoRepo->add(data: $seoMetaData);
        ToastMagic::success(translate('Sub_Category_Added_Successfully'));
        return back();
    }

    public function update(SubCategoryUpdateRequest $request, CategoryService $categoryService): JsonResponse
    {
        $category = $this->categoryRepo->getFirstWhere(params: ['id' => $request['id']]);
        $dataArray = $categoryService->getUpdateData(request: $request, data: $category);
        $this->categoryRepo->update(id: $request['id'], data: $dataArray);
        $this->translationRepo->update(request: $request, model: 'App\Models\Category', id: $request['id']);

        ToastMagic::success(translate('Sub_Category_Updated_Successfully'));
        return response()->json();
    }

    public function delete(Request $request): JsonResponse
    {
        $this->categoryRepo->delete(params: ['id' => $request['id']]);
        return response()->json(['message' => translate('Deleted_Successfully')]);
    }

    public function getExportList(Request $request): BinaryFileResponse
    {
        $parentCategoryIDs = $request['categories'] ?? [];
        if ($request['sort_by'] == 'latest') {
            $orderBy = ['created_at' => 'desc'];
        } elseif ($request['sort_by'] == 'oldest') {
            $orderBy = ['created_at' => 'asc'];
        } elseif ($request['sort_by'] == 'a-z') {
            $orderBy = ['name' => 'asc'];
        } elseif ($request['sort_by'] == 'z-a') {
            $orderBy = ['name' => 'desc'];
        } else  {
            $orderBy = ['updated_at' => 'desc'];
        }

        $subCategories = $this->categoryRepo->getListWhereIn(
            orderBy: $orderBy,
            searchValue: requestString('searchValue') ?: null,
            filters: ['position' => 1],
            whereIn: ['parent_id' => $parentCategoryIDs],
            dataLimit: 'all');

        $active = $subCategories->where('home_status', 1)->count();
        $inactive = $subCategories->where('home_status', 0)->count();
        return Excel::download(new CategoryListExport([
            'categories' => $subCategories,
            'title' => 'sub_category',
            'search' => $request['searchValue'],
            'active' => $active,
            'inactive' => $inactive,
        ]), 'sub-category-list.xlsx'
        );
    }

    public function loadMoreCategories(Request $request): JsonResponse
    {
        $oldCategories = $request['old_categories'] ? json_decode($request['old_categories']) : [];
        $page = $request->input('page', 1);
        $filterParentCategories = $this->categoryRepo->getListWhere(
            orderBy: ['name' => 'asc'],
            filters: ['position' => 0],
            dataLimit: 5,
            offset: $page);

        $visibleLimit = $filterParentCategories->perPage();
        $totalCategories = $filterParentCategories->total();
        $hiddenCount = $totalCategories - ($page * $visibleLimit);

        return response()->json([
            'html' => view('admin-views.category.offcanvas._parent-categories', [
                'filterParentCategories' => $filterParentCategories,
                'oldCategories' => $oldCategories,
            ])->render(),
            'visibleLimit' => $visibleLimit,
            'hiddenCount' => max(0, $hiddenCount),
            'totalCategories' => $totalCategories,
        ]);
    }
}
