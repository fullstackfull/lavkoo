<?php

namespace App\Http\Controllers\Admin\Promotion;

use App\Contracts\Repositories\BannerRepositoryInterface;
use App\Contracts\Repositories\BrandRepositoryInterface;
use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\ShopRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\BannerAddRequest;
use App\Http\Requests\Admin\BannerUpdateRequest;
use App\Services\BannerService;
use App\Services\CategoryService;
use App\Traits\FileManagerTrait;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BannerController extends BaseController
{
    use FileManagerTrait {
        delete as deleteFile;
        update as updateFile;
    }

    public function __construct(
        private readonly BannerRepositoryInterface   $bannerRepo,
        private readonly CategoryRepositoryInterface $categoryRepo,
        private readonly ShopRepositoryInterface     $shopRepo,
        private readonly BrandRepositoryInterface    $brandRepo,
        private readonly ProductRepositoryInterface  $productRepo,
        private readonly BannerService               $bannerService,
        private readonly CategoryService             $categoryService,
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
        $bannerTypes = $this->bannerService->getBannerTypes();
        $banners = $this->bannerRepo->getListWhereIn(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['theme' => theme_root_path()],
            whereInFilters: ['banner_type' => array_keys($bannerTypes)],
            dataLimit: getWebConfig(name: 'pagination_limit'),
        );
        // A category page banner heads whichever page the shopper is standing on —
        // main, sub or sub-sub — so the picker offers the whole tree, each entry
        // labelled with its ancestry.
        $categories = $this->categoryService->getHierarchicalOptions(
            categories: $this->categoryRepo->getListWhere(orderBy: ['priority' => 'asc'], dataLimit: 'all')
        );
        $shops = $this->shopRepo->getListWithScope(scope: 'active', filters: ['author_type' => 'vendor'],  dataLimit: 'all');
        $inhouseShop = getInHouseShopConfig();
        $shops = $shops->prepend($inhouseShop);
        $brands = $this->brandRepo->getListWhere(dataLimit: 'all');
        $products = $this->productRepo->getListWithScope(scope: 'active', dataLimit: 'all');
        $themeUsage = app(\App\Services\Theme\ThemeBannerLink::class)->usage();
        // The organized panel: every theme section that shows banners, with its images laid out as
        // the storefront lays them — so "which picture is the mosaic's large tile" is answered on
        // THIS screen, not on a page the merchant has to know exists.
        $themeGroups = app(\App\Services\Theme\ThemeBannerLink::class)->themeLayout();

        // Resource names for the placement tag: "Category page — skin care" reads at a glance,
        // "resource_id 7" does not.
        $resourceNames = [
            'category' => \App\Models\Category::pluck('name', 'id'),
            'brand'    => \App\Models\Brand::pluck('name', 'id'),
        ];

        return view('admin-views.banner.view', compact('banners', 'categories', 'shops', 'brands', 'products', 'bannerTypes', 'themeUsage', 'themeGroups', 'resourceNames'));
    }

    /**
     * Wireframes of where each banner type lands and what each layout looks like,
     * so a merchant (or a developer wiring the apps) can see the shape before
     * uploading anything.
     */
    public function getPlacementGuide(): View
    {
        return view('admin-views.banner.placement-guide', [
            'bannerTypes' => $this->bannerService->getBannerTypes(),
        ]);
    }

    /**
     * The theme's banners arranged exactly as the storefront lays them out (mosaic spans, grid
     * columns, hero slides) — so a merchant can tell which listed image is which tile, and jump
     * straight to editing either the banner or its section in the builder.
     */
    public function getThemeLayoutView(): View
    {
        return view('admin-views.banner.theme-layout', [
            'groups'    => app(\App\Services\Theme\ThemeBannerLink::class)->themeLayout(),
            'placements' => $this->builtInPlacements(),
        ]);
    }

    /**
     * Every banner of the BUILT-IN placements (category/brand pages, home promo, main slider…),
     * unpublished ones included — shown dimmed with the reason, so "I added it but it does not
     * show" is answered by this screen instead of a support ticket.
     *
     * @return array<int, array{type:string,label:string,shape:string,cards:array}>
     */
    private function builtInPlacements(): array
    {
        $definitions = [
            'Main Banner'             => ['shape' => 'hero',  'label' => translate('main_Banner'),            'where' => translate('home_page_top_slider')],
            'Category Banner'         => ['shape' => 'strip', 'label' => translate('category_Banner'),        'where' => translate('heads_the_page_of_its_category')],
            'Brand Banner'            => ['shape' => 'strip', 'label' => translate('brand_Banner'),           'where' => translate('heads_the_page_of_its_brand')],
            'Category Section Banner' => ['shape' => 'strip', 'label' => translate('category_Section_Banner'),'where' => translate('above_its_category_row_on_the_home_page')],
            'Home Promo Banner'       => ['shape' => 'grid',  'label' => translate('home_Promo_Banner'),      'where' => translate('the_home_page_promo_grid')],
            'Main Section Banner'     => ['shape' => 'strip', 'label' => translate('main_Section_Banner'),    'where' => translate('the_home_page_middle_section')],
            'Footer Banner'           => ['shape' => 'grid',  'label' => translate('footer_Banner'),          'where' => translate('above_the_footer')],
            'Popup Banner'            => ['shape' => 'grid',  'label' => translate('popup_Banner'),           'where' => translate('the_welcome_popup')],
        ];

        $banners = $this->bannerRepo->getListWhereIn(
            orderBy: ['id' => 'desc'],
            filters: ['theme' => theme_root_path()],
            whereInFilters: ['banner_type' => array_keys($definitions)],
            dataLimit: 'all',
        );

        $categoryNames = \App\Models\Category::pluck('name', 'id');
        $brandNames = \App\Models\Brand::pluck('name', 'id');

        // A published composed home replaces the built-in page, so its banner slots stand down
        // unless a "banners from dashboard" section covers the type — flag the ones that dropped.
        $renderer = app(\App\Services\Theme\StorefrontThemeRenderer::class);
        $themedHomeLive = $renderer->sectionsFor('home') !== null;
        $homeSlotTypes = ['Main Banner', 'Main Section Banner', 'Home Promo Banner', 'Category Section Banner'];

        $groups = [];
        foreach ($banners->groupBy('banner_type') as $type => $rows) {
            $definition = $definitions[$type];
            $groups[] = [
                'type'  => $type,
                'label' => $definition['label'],
                'where' => $definition['where'],
                'shape' => $definition['shape'],
                'dropped_by_theme' => $themedHomeLive
                    && in_array($type, $homeSlotTypes, true)
                    && !$renderer->pageSectionsRenderBannerType(page: 'home', bannerType: $type),
                'cards' => $rows->map(function ($banner) use ($categoryNames, $brandNames) {
                    $place = null;
                    if ($banner->resource_type === 'category' && $banner->resource_id) {
                        $place = translate('category') . ': ' . ($categoryNames[$banner->resource_id] ?? ('#' . $banner->resource_id));
                    } elseif ($banner->resource_type === 'brand' && $banner->resource_id) {
                        $place = translate('brand') . ': ' . ($brandNames[$banner->resource_id] ?? ('#' . $banner->resource_id));
                    }

                    return [
                        'banner_id' => $banner->id,
                        'image'     => getStorageImages(path: $banner->photo_full_url, type: 'banner'),
                        'title'     => $banner->title,
                        'place'     => $place,
                        'published' => (int) $banner->published === 1,
                    ];
                })->values()->all(),
            ];
        }

        return $groups;
    }

    /**
     * A placement banner that points at the wrong kind of resource names no page and
     * would silently never render, so the save is refused with an explanation.
     */
    private function getResourceMismatchError($request): ?string
    {
        $required = $this->bannerService->getRequiredResourceType($request['banner_type']);
        if ($required === null || $request['resource_type'] === $required) {
            return null;
        }

        // One whole sentence per case rather than concatenated fragments: a message
        // stitched from pieces cannot be translated into a language that orders them
        // differently.
        return $required === 'brand'
            ? translate('this_banner_type_must_point_at_a_brand')
            : translate('this_banner_type_must_point_at_a_category');
    }

    public function add(BannerAddRequest $request): RedirectResponse
    {
        $mismatch = $this->getResourceMismatchError(request: $request);
        if ($mismatch) {
            ToastMagic::error($mismatch);
            return redirect()->route('admin.banner.list');
        }

        $banner = $this->getBannerUrl(request: $request);
        if (!empty($banner['error'])) {
            ToastMagic::error($banner['error']);
            return redirect()->route('admin.banner.list');
        }

        $data = $this->bannerService->getProcessedData(request: $request, bannerUrl: $banner['banner_url']);
        $this->bannerRepo->add(data: $data);
        ToastMagic::success(translate('banner_added_successfully'));
        return redirect()->route('admin.banner.list');
    }

    public function getBannerUrl($request): array
    {
        $data = [];
        if ($request['resource_type'] == 'product') {
            $product = $this->productRepo->getFirstWhere(params: ['id' => $request['product_id']]);
            $bannerUrl = $product ? route('product', ['slug' => $product['slug']]) : null;
            $data = [
                'banner_url' => $bannerUrl,
                'error' => $product ? null : (empty($request['product_id']) ? translate('Please select a product.') : translate('The product does not exist.')),
            ];
        } else if ($request['resource_type'] == 'category') {
            $category = $this->categoryRepo->getFirstWhere(params: ['id' => $request['category_id']]);
            $bannerUrl = $category ? route('products', ['category_id' => $category['id'], 'data_from' => 'category', 'page' => 1]) : null;
            $data = [
                'banner_url' => $bannerUrl,
                'error' => $category ? null : (empty($request['category_id']) ? translate('Please select a category.') : translate('The category does not exist.')),
            ];
        } else if ($request['resource_type'] == 'shop') {
            $shop = $this->shopRepo->getFirstWhere(params: ['id' => $request['shop_id']]);
            $bannerUrl = route('vendor-shop', ['slug' => $shop['slug']]);
            $data = [
                'banner_url' => $bannerUrl,
                'error' => $shop ? null : (empty($request['shop_id']) ? translate('Please select a shop.') : translate('The shop does not exist.')),
            ];
        } else if ($request['resource_type'] == 'brand') {
            $brand = $this->brandRepo->getFirstWhere(params: ['id' => $request['brand_id']]);
            $bannerUrl = route('products', ['brand_id' => $brand['id'], 'data_from' => 'brand', 'page' => 1]);
            $data = [
                'banner_url' => $bannerUrl,
                'error' => $brand ? null : (empty($request['brand_id']) ? translate('Please select a brand.') : translate('The brand does not exist.')),
            ];
        } else {
            $data = [
                'banner_url' => $request['url'],
                'error' => null,
            ];
        }

        return $data;
    }

    public function getUpdateView($id): View
    {
        $bannerTypes = $this->bannerService->getBannerTypes();
        $banner = $this->bannerRepo->getFirstWhere(params: ['id' => $id]);
        // A category page banner heads whichever page the shopper is standing on —
        // main, sub or sub-sub — so the picker offers the whole tree, each entry
        // labelled with its ancestry.
        $categories = $this->categoryService->getHierarchicalOptions(
            categories: $this->categoryRepo->getListWhere(orderBy: ['priority' => 'asc'], dataLimit: 'all')
        );
        $shops = $this->shopRepo->getListWithScope(scope: 'active', filters: ['author_type' => 'vendor'] , dataLimit: 'all');
        $inhouseShop = getInHouseShopConfig();
        $shops = $shops->prepend($inhouseShop);
        $brands = $this->brandRepo->getListWhere(dataLimit: 'all');
        $products = $this->productRepo->getListWithScope(scope: 'active', dataLimit: 'all');
        return view('admin-views.banner.edit', compact('banner', 'categories', 'shops', 'brands', 'products', 'bannerTypes'));
    }

    public function update(BannerUpdateRequest $request, $id): RedirectResponse
    {
        $mismatch = $this->getResourceMismatchError(request: $request);
        if ($mismatch) {
            ToastMagic::error($mismatch);
            return redirect()->route('admin.banner.list');
        }

        $resource = $this->getBannerUrl(request: $request);
        if (!empty($resource['error'])) {
            ToastMagic::error($resource['error']);
            return redirect()->route('admin.banner.list');
        }

        $banner = $this->bannerRepo->getFirstWhere(params: ['id' => $id]);
        // The freshly resolved link, not $banner['banner_url'] — there is no such column,
        // so every edit used to null the banner's click-through url.
        $data = $this->bannerService->getProcessedData(
            request: $request,
            bannerUrl: $resource['banner_url'],
            image: $banner['photo'],
            mobileImage: $banner['mobile_photo'],
        );
        $this->bannerRepo->update(id: $banner['id'], data: $data);
        ToastMagic::success(translate('banner_updated_successfully'));
        return redirect()->route('admin.banner.list');
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $status = $request->get('status', 0);
        $banner = $this->bannerRepo->getFirstWhere(params: ['id' => $request['id']]);

        // Only one popup can be live at a time, so publishing one retires the others.
        // Previously this ran for EVERY banner type: turning on a footer or promo banner
        // silently unpublished the merchant's popup.
        if ($status == 1 && $banner && $banner['banner_type'] == 'Popup Banner') {
            $popupBanners = $this->bannerRepo->getListWhere(filters: ['banner_type' => 'Popup Banner'], dataLimit: 'all');
            foreach ($popupBanners as $popupBanner) {
                if ($popupBanner['id'] != $request['id']) {
                    $this->bannerRepo->update(id: $popupBanner['id'], data: ['published' => 0]);
                }
            }
        }

        $this->bannerRepo->update(id: $request['id'], data: ['published' => $status]);
        return response()->json([
            'message' => $status == 1 ? translate("banner_published_successfully") : translate("banner_unpublished_successfully"),
        ]);
    }

    public function delete(Request $request): JsonResponse
    {
        $banner = $this->bannerRepo->getFirstWhere(params: ['id' => $request['id']]);
        $this->deleteFile(filePath: '/banner/' . $banner['photo']);
        if (!empty($banner['mobile_photo'])) {
            $this->deleteFile(filePath: '/banner/' . $banner['mobile_photo']);
        }
        $this->bannerRepo->delete(params: ['id' => $request['id']]);
        // The repository deletes through the query builder, so the model's deleted event —
        // which is what drops the banner caches — never fires. Without this a deleted
        // banner keeps rendering for up to three hours.
        cacheRemoveByType(type: 'banners');
        return response()->json(['message' => translate('banner_deleted_successfully')]);
    }
}
