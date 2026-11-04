<?php

namespace App\Http\Controllers\Admin\Product;

use App\Contracts\Repositories\AttributeRepositoryInterface;
use App\Contracts\Repositories\AuthorRepositoryInterface;
use App\Contracts\Repositories\BannerRepositoryInterface;
use App\Contracts\Repositories\BrandRepositoryInterface;
use App\Contracts\Repositories\CartRepositoryInterface;
use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Contracts\Repositories\ColorRepositoryInterface;
use App\Contracts\Repositories\DealOfTheDayRepositoryInterface;
use App\Contracts\Repositories\DigitalProductAuthorRepositoryInterface;
use App\Contracts\Repositories\DigitalProductVariationRepositoryInterface;
use App\Contracts\Repositories\FlashDealProductRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\ProductSeoRepositoryInterface;
use App\Contracts\Repositories\PublishingHouseRepositoryInterface;
use App\Contracts\Repositories\RestockProductCustomerRepositoryInterface;
use App\Contracts\Repositories\RestockProductRepositoryInterface;
use App\Contracts\Repositories\ReviewRepositoryInterface;
use App\Contracts\Repositories\ShopRepositoryInterface;
use App\Contracts\Repositories\StockClearanceProductRepositoryInterface;
use App\Contracts\Repositories\StockClearanceSetupRepositoryInterface;
use App\Contracts\Repositories\TranslationRepositoryInterface;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Contracts\Repositories\WishlistRepositoryInterface;
use App\Enums\WebConfigKey;
use App\Events\ProductRequestStatusUpdateEvent;
use App\Exports\ProductListExport;
use App\Exports\RestockProductListExport;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\ProductDenyRequest;
use App\Http\Requests\ProductAddRequest;
use App\Http\Requests\ProductEditImageRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Repositories\DigitalProductPublishingHouseRepository;
use App\Services\ProductService;
use App\Traits\FileManagerTrait;
use App\Traits\ProductTrait;
use App\Utils\CartManager;
use App\Utils\ProductManager;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Modules\TaxModule\app\Models\SystemTaxSetup;
use Modules\TaxModule\app\Models\Taxable;
use Modules\TaxModule\app\Traits\VatTaxManagement;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductController extends BaseController
{
    /** Cap on one batch, so a crafted payload cannot walk the whole catalogue. */
    private const BULK_LIMIT = 200;

    use ProductTrait;
    use VatTaxManagement;

    use FileManagerTrait {
        delete as deleteFile;
        update as updateFile;
    }

    public function __construct(
        private readonly AuthorRepositoryInterface                  $authorRepo,
        private readonly DigitalProductAuthorRepositoryInterface    $digitalProductAuthorRepo,
        private readonly DigitalProductPublishingHouseRepository    $digitalProductPublishingHouseRepo,
        private readonly PublishingHouseRepositoryInterface         $publishingHouseRepo,
        private readonly CategoryRepositoryInterface                $categoryRepo,
        private readonly BrandRepositoryInterface                   $brandRepo,
        private readonly ProductRepositoryInterface                 $productRepo,
        private readonly CustomerRepositoryInterface                $customerRepo,
        private readonly RestockProductRepositoryInterface          $restockProductRepo,
        private readonly RestockProductCustomerRepositoryInterface  $restockProductCustomerRepo,
        private readonly DigitalProductVariationRepositoryInterface $digitalProductVariationRepo,
        private readonly StockClearanceProductRepositoryInterface   $stockClearanceProductRepo,
        private readonly StockClearanceSetupRepositoryInterface     $stockClearanceSetupRepo,
        private readonly ProductSeoRepositoryInterface              $productSeoRepo,
        private readonly VendorRepositoryInterface                  $sellerRepo,
        private readonly ColorRepositoryInterface                   $colorRepo,
        private readonly AttributeRepositoryInterface               $attributeRepo,
        private readonly TranslationRepositoryInterface             $translationRepo,
        private readonly CartRepositoryInterface                    $cartRepo,
        private readonly WishlistRepositoryInterface                $wishlistRepo,
        private readonly FlashDealProductRepositoryInterface        $flashDealProductRepo,
        private readonly DealOfTheDayRepositoryInterface            $dealOfTheDayRepo,
        private readonly ReviewRepositoryInterface                  $reviewRepo,
        private readonly BannerRepositoryInterface                  $bannerRepo,
        private readonly ShopRepositoryInterface                    $shopRepo,
        private readonly ProductService                             $productService,
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
        $type = $type == 'vendor' ? 'seller' : 'in_house';
        $filters = [
            'added_by' => $type,
            'status' => $request['status'],
            'request_status' => $request['request_status'],
            'seller_id' => $request['seller_id'],
            'filter_sort_by' => $request['filter_sort_by'] ?? 'latest',
            'filter_category_ids' => $request['filter_category_ids'] ?? [],
            'filter_sub_category_ids' => $request['filter_sub_category_ids'] ?? [],
            'filter_sub_sub_category_ids' => $request['filter_sub_sub_category_ids'] ?? [],
        ];

        $taxData = $this->getTaxSystemType();
        $productWiseTax = $taxData['productWiseTax'] && !$taxData['is_included'];
        $taxVats = $taxData['taxVats'];

        $subCategories = collect();
        $subSubCategories = collect();
        $paginationLimit = getWebConfig(name: WebConfigKey::PAGINATION_LIMIT) ? getWebConfig(name: WebConfigKey::PAGINATION_LIMIT) : 25;

        $filterWhereIn = ProductManager::getSortFilterWhereInArrays(request: $request);
        $products = $this->productRepo->getWebListWithScope(
            searchValue: requestString('searchValue') ?: null,
            filters: $filters,
            whereIn: $filterWhereIn,
            relations: ['clearanceSale' => function ($query) {
                return $query->active();
            }, 'taxVats' => function ($query) {
                return $query->with(['tax'])->wherehas('tax', function ($query) {
                    return $query->where('is_active', 1);
                });
            }], dataLimit: $paginationLimit);

        $sellers = $this->sellerRepo->getByStatusExcept(status: 'pending', relations: ['shop'], paginateBy: $paginationLimit);
        $brands = $this->brandRepo->getListWhere(orderBy: ['id' => 'desc'], dataLimit: 'all');
        $categories = $this->categoryRepo->getListWhere(filters: ['position' => 0], relations: ['childes.childes'], dataLimit: 'all');

        return view('admin-views.product.list', compact(
            'products',
            'sellers',
            'brands',
            'categories',
            'subCategories',
            'subSubCategories',
            'filters',
            'type',
            'productWiseTax'
        ));
    }

    public function getAddView(): View
    {
        $taxData = $this->getTaxSystemType();
        $productWiseTax = $taxData['productWiseTax'] && !$taxData['is_included'];
        $taxVats = $taxData['taxVats'];

        $categories = $this->categoryRepo->getListWhere(filters: ['position' => 0], dataLimit: 'all');
        $brands = $this->brandRepo->getListWhere(dataLimit: 'all');
        $brandSetting = getWebConfig(name: 'product_brand');
        $digitalProductSetting = getWebConfig(name: 'digital_product');
        $colors = $this->colorRepo->getList(orderBy: ['name' => 'desc'], dataLimit: 'all');
        $attributes = $this->attributeRepo->getList(orderBy: ['name' => 'desc'], dataLimit: 'all');
        $languages = getWebConfig(name: 'pnc_language') ?? null;
        $defaultLanguage = $languages[0];
        $digitalProductFileTypes = ['audio', 'video', 'document', 'software'];
        $digitalProductAuthors = $this->authorRepo->getListWhere(dataLimit: 'all');
        $publishingHouseList = $this->publishingHouseRepo->getListWhere(dataLimit: 'all');

        return view('admin-views.product.add.index', compact('categories', 'brands', 'brandSetting', 'digitalProductSetting', 'colors', 'attributes', 'languages', 'defaultLanguage', 'digitalProductFileTypes', 'digitalProductAuthors', 'publishingHouseList', 'productWiseTax', 'taxVats'));
    }

    public function add(ProductAddRequest $request, ProductService $service): JsonResponse|RedirectResponse
    {
        if ($request->ajax()) {
            return response()->json([], 200);
        }
        $shopId = getInHouseShopConfig('id');
        $dataArray = $service->getAddProductData(request: $request, addedBy: 'admin', shopId: $shopId);
        $savedProduct = $this->productRepo->add(data: $dataArray);
        $this->productRepo->addRelatedTags(request: $request, product: $savedProduct);
        $this->translationRepo->add(request: $request, model: 'App\Models\Product', id: $savedProduct->id);
        $this->updateProductAuthorAndPublishingHouse(request: $request, product: $savedProduct);

        $digitalFileArray = $service->getAddProductDigitalVariationData(request: $request, product: $savedProduct);
        foreach ($digitalFileArray as $digitalFile) {
            $this->digitalProductVariationRepo->add(data: $digitalFile);
        }

        $this->productSeoRepo->add(data: $service->getProductSEOData(request: $request, product: $savedProduct, action: 'add'));

        $this->getAddTaxData(
            taxableType: \App\Models\Product::class,
            taxableId: $savedProduct->id,
            taxIds: $request['tax_ids'] ?? []
        );

        updateSetupGuideCacheKey(key: 'add_new_product', panel: 'admin');
        ToastMagic::success(translate('product_added_successfully'));
        return redirect()->route('admin.products.list', ['in_house']);
    }

    public function updateProductAuthorAndPublishingHouse(object|array $request, object|array $product): void
    {
        if ($request['product_type'] == 'digital') {
            if ($request->has('authors')) {
                $authorIds = [];
                foreach ($request['authors'] as $author) {
                    $authorId = $this->authorRepo->updateOrCreate(params: ['name' => $author], value: ['name' => $author]);
                    $authorIds[] = $authorId?->id;
                }

                foreach ($authorIds as $author) {
                    $productAuthorData = ['author_id' => $author, 'product_id' => $product->id];
                    $this->digitalProductAuthorRepo->updateOrCreate(params: $productAuthorData, value: $productAuthorData);
                }

                $this->digitalProductAuthorRepo->deleteWhereNotIn(filters: ['product_id' => $product->id], whereNotIn: ['author_id' => $authorIds]);
            } else {
                $this->digitalProductAuthorRepo->delete(params: ['product_id' => $product->id]);
            }

            if ($request->has('publishing_house')) {
                $publishingHouseIds = [];
                foreach ($request['publishing_house'] as $publishingHouse) {
                    $publishingHouseId = $this->publishingHouseRepo->updateOrCreate(params: ['name' => $publishingHouse], value: ['name' => $publishingHouse]);
                    $publishingHouseIds[] = $publishingHouseId?->id;
                }

                foreach ($publishingHouseIds as $publishingHouse) {
                    $publishingHouseData = ['publishing_house_id' => $publishingHouse, 'product_id' => $product->id];
                    $this->digitalProductPublishingHouseRepo->updateOrCreate(params: $publishingHouseData, value: $publishingHouseData);
                }
                $this->digitalProductPublishingHouseRepo->deleteWhereNotIn(filters: ['product_id' => $product->id], whereNotIn: ['publishing_house_id' => $publishingHouseIds]);
            } else {
                $this->digitalProductPublishingHouseRepo->delete(params: ['product_id' => $product->id]);
            }
        } else {
            $this->digitalProductAuthorRepo->delete(params: ['product_id' => $product->id]);
            $this->digitalProductPublishingHouseRepo->delete(params: ['product_id' => $product->id]);
        }
    }

    public function getUpdateView(string|int $id): View|RedirectResponse
    {
        $taxData = $this->getTaxSystemType();
        $productWiseTax = $taxData['productWiseTax'] && !$taxData['is_included'];
        $taxVats = $taxData['taxVats'];

        $product = $this->productRepo->getFirstWhereWithoutGlobalScope(params: ['id' => $id], relations: ['digitalVariation', 'translations', 'seoInfo', 'digitalProductAuthors.author', 'digitalProductPublishingHouse.publishingHouse']);
        if (!$product) {
            ToastMagic::error(translate('product_not_found') . '!');
            return redirect()->route('admin.products.list', ['in_house']);
        }
        $productAuthorIds = $this->productService->getProductAuthorsInfo(product: $product)['ids'];
        $productPublishingHouseIds = $this->productService->getProductPublishingHouseInfo(product: $product)['ids'];

        $product['colors'] = json_decode($product['colors']);
        $categories = $this->categoryRepo->getListWhere(filters: ['position' => 0], dataLimit: 'all');
        $brands = $this->brandRepo->getListWhere(dataLimit: 'all');
        $brandSetting = getWebConfig(name: 'product_brand');
        $digitalProductSetting = getWebConfig(name: 'digital_product');
        $languages = getWebConfig(name: 'pnc_language') ?? null;
        $colors = $this->colorRepo->getList(orderBy: ['name' => 'desc'], dataLimit: 'all');
        $attributes = $this->attributeRepo->getList(orderBy: ['name' => 'desc'], dataLimit: 'all');
        $defaultLanguage = $languages[0];
        $digitalProductFileTypes = ['audio', 'video', 'document', 'software'];
        $digitalProductAuthors = $this->authorRepo->getListWhere(dataLimit: 'all');
        $publishingHouseList = $this->publishingHouseRepo->getListWhere(dataLimit: 'all');
        $taxVatIds = $product?->taxVats?->pluck('tax_id')->toArray() ?? [];

        return view('admin-views.product.update.index', compact('product', 'categories', 'brands', 'brandSetting', 'digitalProductSetting', 'colors', 'attributes', 'languages', 'defaultLanguage', 'digitalProductFileTypes', 'digitalProductAuthors', 'publishingHouseList', 'productAuthorIds', 'productPublishingHouseIds', 'productWiseTax', 'taxVats', 'taxVatIds'));
    }

    public function update(ProductUpdateRequest $request, ProductService $service, string|int $id): JsonResponse|RedirectResponse
    {
        if ($request->ajax()) {
            return response()->json([], 200);
        }
        $product = $this->productRepo->getFirstWhereWithoutGlobalScope(params: ['id' => $id], relations: ['digitalVariation', 'seoInfo']);
        $dataArray = $service->getUpdateProductData(request: $request, product: $product, updateBy: 'admin');
        $this->updateProductAuthorAndPublishingHouse(request: $request, product: $product);

        $this->productRepo->update(id: $id, data: $dataArray);
        $this->productRepo->addRelatedTags(request: $request, product: $product);
        $this->translationRepo->update(request: $request, model: 'App\Models\Product', id: $id);

        self::getDigitalProductUpdateProcess($request, $product);

        $this->productSeoRepo->updateOrInsert(
            params: ['product_id' => $product['id']],
            data: $service->getProductSEOData(request: $request, product: $product, action: 'update')
        );

        $updatedProduct = $this->productRepo->getFirstWhere(params: ['id' => $product['id']]);
        $this->updateRestockRequestListAndNotify(product: $product, updatedProduct: $updatedProduct);
        $this->updateStockClearanceProduct(product: $updatedProduct);

        // Preserve SEO value on a slug change: auto-create a 301 from the old product URL to the new one.
        app(\App\Services\Seo\SlugRedirectSuggester::class)->suggest('product', $product['slug'] ?? null, $updatedProduct['slug'] ?? null);

        $cartList = $this->cartRepo->getListWhere(filters: ['product_id' => $product['id']], dataLimit: 'all');
        CartManager::updateProductShippingCost(cartList: $cartList);

        $taxVatIds = $product?->taxVats?->pluck('tax_id')->toArray() ?? [];
        $this->getUpdateTaxData(
            taxableType: \App\Models\Product::class,
            taxableId: $product['id'],
            taxIds: $request['tax_ids'] ?? [],
            oldTaxIds: $taxVatIds
        );

        updateSetupGuideCacheKey(key: 'add_new_product', panel: 'admin');
        ToastMagic::success(translate('product_updated_successfully'));
        return redirect()->route('admin.products.view', ['addedBy' => $product['added_by'] == "admin" ? 'in-house' : $product['added_by'], 'id' => $product['id']]);
    }

    public function updateProductImages(ProductEditImageRequest $request, ProductService $productService, int|string $id): RedirectResponse
    {
        $product = $this->productRepo->getFirstWhereWithoutGlobalScope(params: ['id' => $id], relations: ['translations', 'seoInfo']);
        $storage = config('filesystems.disks.default') ?? 'public';
        $processedImages = $productService->getProcessedUpdateAdditionalImages(request: $request, product: $product);
        $updateData = [
            'color_image' => json_encode($processedImages['colored_image_names']),
            'images' => json_encode($processedImages['image_names']),
        ];
        if ($request->file('image')) {
            $updateData['thumbnail'] = $this->updateFile(
                dir: 'product/thumbnail/',
                oldImage: $product['thumbnail'],
                format: 'webp',
                image: $request['image'],
                fileType: 'image'
            );
            $updateData['thumbnail_storage_type'] = $storage;
        }
        $this->productRepo->update(id: $product['id'], data: $updateData);
        ToastMagic::success(translate('product_image_updated_successfully'));
        return back();
    }

    public function updateStockClearanceProduct($product): void
    {
        $config = $this->stockClearanceSetupRepo->getFirstWhere(params: [
            'setup_by' => $product['added_by'] == 'admin' ? $product['added_by'] : 'vendor',
            'shop_id' => $product['added_by'] == 'admin' ? 0 : $product?->seller?->shop?->id,
        ]);
        $stockClearanceProduct = $this->stockClearanceProductRepo->getFirstWhere(params: ['product_id' => $product['id']]);

        if ($config && $config['discount_type'] == 'product_wise' && $stockClearanceProduct && $stockClearanceProduct['discount_type'] == 'flat') {
            $minimumPrice = $product['unit_price'];
            foreach ((json_decode($product['variation'], true) ?? []) as $variation) {
                if ($variation['price'] < $minimumPrice) {
                    $minimumPrice = $variation['price'];
                }
            }

            if ($minimumPrice < $stockClearanceProduct['discount_amount']) {
                $this->stockClearanceProductRepo->updateByParams(params: ['product_id' => $product['id']], data: ['is_active' => 0]);
            }
        }
    }

    public function getDigitalProductUpdateProcess($request, $product): void
    {
        if ($request->has('digital_product_variant_key') && !$request->hasFile('digital_file_ready')) {
            $getAllVariation = $this->digitalProductVariationRepo->getListWhere(filters: ['product_id' => $product['id']]);
            $getAllVariationKey = $getAllVariation->pluck('variant_key')->toArray();
            $getRequestVariationKey = $request['digital_product_variant_key'];
            $differenceFromDB = array_diff($getAllVariationKey, $getRequestVariationKey);
            $differenceFromRequest = array_diff($getRequestVariationKey, $getAllVariationKey);
            $newCombinations = array_merge($differenceFromDB, $differenceFromRequest);

            foreach ($newCombinations as $newCombination) {
                if (in_array($newCombination, $request['digital_product_variant_key'])) {
                    $uniqueKey = strtolower(str_replace('-', '_', $newCombination));

                    $fileItem = null;
                    if ($request['digital_product_type'] == 'ready_product') {
                        $fileItem = $request->file('digital_files.' . $uniqueKey);
                    }
                    $uploadedFile = '';
                    if ($fileItem) {
                        $uploadedFile = $this->fileUpload(dir: 'product/digital-product/', format: $fileItem->getClientOriginalExtension(), file: $fileItem);
                    }
                    $this->digitalProductVariationRepo->add(data: [
                        'product_id' => $product['id'],
                        'variant_key' => $request->input('digital_product_variant_key.' . $uniqueKey),
                        'sku' => $request->input('digital_product_sku.' . $uniqueKey),
                        'price' => currencyConverter(amount: $request->input('digital_product_price.' . $uniqueKey)),
                        'file' => $uploadedFile,
                    ]);
                }
            }

            foreach ($differenceFromDB as $variation) {
                $variation = $this->digitalProductVariationRepo->getFirstWhere(params: ['product_id' => $product['id'], 'variant_key' => $variation]);
                if ($variation) {
                    $this->digitalProductVariationRepo->delete(params: ['id' => $variation['id']]);
                }
            }

            foreach ($getAllVariation as $variation) {
                if (in_array($variation['variant_key'], $request['digital_product_variant_key'])) {
                    $uniqueKey = strtolower(str_replace('-', '_', $variation['variant_key']));

                    $fileItem = null;
                    if ($request['digital_product_type'] == 'ready_product') {
                        $fileItem = $request->file('digital_files.' . $uniqueKey);
                    }
                    $uploadedFile = $variation['file'] ?? '';
                    $variation = $this->digitalProductVariationRepo->getFirstWhere(params: ['product_id' => $product['id'], 'variant_key' => $variation['variant_key']]);
                    if ($fileItem) {
                        $uploadedFile = $this->fileUpload(dir: 'product/digital-product/', format: $fileItem->getClientOriginalExtension(), file: $fileItem);
                    }
                    $this->digitalProductVariationRepo->updateByParams(params: ['product_id' => $product['id'], 'variant_key' => $variation['variant_key']], data: [
                        'variant_key' => $request->input('digital_product_variant_key.' . $uniqueKey),
                        'sku' => $request->input('digital_product_sku.' . $uniqueKey),
                        'price' => currencyConverter(amount: $request->input('digital_product_price.' . $uniqueKey)),
                        'file' => $uploadedFile,
                    ]);
                }

                if ($request['product_type'] == 'physical' || $request['digital_product_type'] == 'ready_after_sell') {
                    $variation = $this->digitalProductVariationRepo->getFirstWhere(params: ['product_id' => $product['id'], 'variant_key' => $variation['variant_key']]);
                    if ($variation && $variation['file']) {
                        $this->digitalProductVariationRepo->updateByParams(params: ['id' => $variation['id']], data: ['file' => '']);
                    }
                    if ($request['product_type'] == 'physical') {
                        $variation->delete();
                    }
                }
            }
        } else {
            $this->digitalProductVariationRepo->delete(params: ['product_id' => $product['id']]);
        }
    }

    public function getView(string $addedBy, string|int $id): View|RedirectResponse
    {
        $productActive = $this->productRepo->getFirstWhere(params: ['id' => $id], relations: ['digitalVariation', 'seoInfo']);
        if (!$productActive) {
            ToastMagic::error(translate('product_not_found') . '!');
            return redirect()->route('admin.products.list', ['in_house']);
        }

        $taxData = $this->getTaxSystemType();
        $productWiseTax = $taxData['productWiseTax'] && !$taxData['is_included'];
        $productWiseTaxRelation = $productWiseTax ? ['taxVats' => function ($query) {
            return $query->with(['tax'])->wherehas('tax', function ($query) {
                return $query->where('is_active', 1);
            });
        }] : [];

        $isActive = $this->productRepo->getWebFirstWhereActive(params: ['id' => $id]);
        $relations = ['category', 'brand', 'reviews', 'rating', 'orderDetails', 'orderDelivered', 'digitalVariation', 'seoInfo', 'clearanceSale' => function ($query) {
            return $query->active();
        }];
        $relations = array_merge($productWiseTaxRelation, $relations);

        $product = $this->productRepo->getFirstWhereWithoutGlobalScope(params: ['id' => $id], relations: $relations);

        $totalSellerProducts = $this->productRepo->getListWhere(filters: ['added_by' => 'seller', 'user_id' => $product['user_id']], dataLimit: 'all');
        $totalAdminProducts = $this->productRepo->getListWhere(filters: ['added_by' => 'in_house', 'user_id' => $product['user_id']], dataLimit: 'all');
        $product['priceSum'] = $product?->orderDelivered->sum('price') * $product?->orderDelivered->sum('qty');
        $product['total_qty_sold'] = $product?->orderDelivered->sum('qty');
        $product['discountSum'] = $product?->orderDelivered->sum('discount');
        $productColors = [];
        $colors = is_array($product['colors']) ? $product['colors'] : json_decode($product['colors'] ?? '', true);
        foreach ($colors as $color) {
            $getColor = $this->colorRepo->getFirstWhere(params: ['code' => $color]);
            if ($getColor) {
                $productColors[$getColor['name']] = $colors;
            }
        }
        $reviews = $this->reviewRepo->getListWhere(filters: ['product_id' => ['product_id' => $id], 'whereNull' => ['column' => 'delivery_man_id']], relations: ['customer', 'reply'], dataLimit: getWebConfig(name: 'pagination_limit'));
        return view('admin-views.product.view', compact('product', 'reviews', 'productActive', 'productColors', 'addedBy', 'isActive', 'totalSellerProducts', 'totalAdminProducts', 'productWiseTax'));
    }

    public function getSkuCombinationView(Request $request, ProductService $service): JsonResponse
    {
        $product = $this->productRepo->getFirstWhere(params: ['id' => $request['product_id']], relations: ['digitalVariation', 'seoInfo']);
        $combinationView = $service->getSkuCombinationView(request: $request, product: $product);
        return response()->json(['view' => $combinationView]);
    }

    public function getDigitalVariationCombinationView(Request $request, ProductService $service): JsonResponse
    {
        $product = $this->productRepo->getFirstWhere(params: ['id' => $request['product_id']], relations: ['digitalVariation', 'seoInfo']);
        $combinationView = $service->getDigitalVariationCombinationView(request: $request, product: $product);
        return response()->json([
            'view' => $combinationView['view'],
            'combination_count' => $combinationView['combination_count'],
        ]);
    }

    public function deleteDigitalVariationFile(Request $request, ProductService $service): JsonResponse
    {
        $variation = $this->digitalProductVariationRepo->getFirstWhere(params: ['product_id' => $request['product_id'], 'variant_key' => $request['variant_key']]);
        if ($variation) {
            $this->deleteFile(filePath: '/product/digital-product/' . $variation['file']);
            $this->digitalProductVariationRepo->updateByParams(params: ['id' => $variation['id']], data: ['file' => null]);
            return response()->json([
                'status' => 1,
                'message' => translate('delete_successful')
            ]);
        }
        return response()->json([
            'status' => 0,
            'message' => translate('delete_unsuccessful')
        ]);
    }

    public function updateFeaturedStatus(Request $request): JsonResponse
    {
        $status = $request['status'];
        $productId = $request['id'];
        $product = $this->productRepo->getFirstWhere(params: ['id' => $productId]);
        $updateData = [
            'featured' => is_null($product['featured']) || $product['featured'] == 0 ? 1 : 0
        ];
        $this->productRepo->update(id: $productId, data: $updateData);
        return response()->json([
            'status' => true,
            'message' => translate('Product_featured_status_updated_successfully')
        ]);
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $status = $request->get('status', 0);
        $productId = $request['id'];
        $product = $this->productRepo->getFirstWhere(params: ['id' => $productId]);

        $success = 1;
        if ($status == 1) {
            $success = $product->added_by == 'seller' && ($product['request_status'] == 0 || $product['request_status'] == 2) ? 0 : 1;
        }
        $updateData = ['status' => $status];
        $data = $success ? $this->productRepo->update(id: $productId, data: $updateData) : null;

        return response()->json([
            'status' => $success,
            'data' => $data,
            'message' => $success ? translate("status_updated_successfully") : translate("status_updated_failed") . ' ' . translate("Product_must_be_approved"),
        ], 200);
    }

    /**
     * Publish, unpublish or feature many products at once.
     *
     * Delegates to updateStatus() / updateFeaturedStatus() per product so the
     * approval rule lives in one place: a vendor product that is pending or denied
     * cannot be published, and that check must not be duplicated here where it
     * would quietly fall out of sync.
     *
     * "feature" toggles per product because updateFeaturedStatus() is itself a
     * toggle; the two explicit publish actions are absolute, which is what an
     * operator selecting thirty rows actually means.
     */
    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $ids = array_values(array_unique(array_filter((array) $request->input('ids', []))));
        $action = $this->stringOr($request->input('action'));

        if (empty($ids)) {
            return response()->json(['status' => 0, 'message' => translate('select_at_least_one_product')], 422);
        }
        if (!in_array($action, ['publish', 'unpublish', 'feature'], true)) {
            return response()->json(['status' => 0, 'message' => translate('unsupported_action')], 422);
        }
        if (count($ids) > self::BULK_LIMIT) {
            return response()->json([
                'status' => 0,
                'message' => translate('select_no_more_than') . ' ' . self::BULK_LIMIT . ' ' . translate('products'),
            ], 422);
        }

        $updated = 0;
        $skipped = [];

        foreach ($ids as $id) {
            if ($action === 'feature') {
                $this->updateFeaturedStatus(new Request(['id' => $id]));
                $updated++;
                continue;
            }

            $result = $this->updateStatus(new Request(['id' => $id, 'status' => $action === 'publish' ? 1 : 0]));
            $payload = $result->getData(true);

            if (!empty($payload['status'])) {
                $updated++;
            } else {
                $skipped[] = ['id' => $id, 'reason' => $payload['message'] ?? translate('could_not_be_updated')];
            }
        }

        return response()->json([
            'status' => $updated > 0 ? 1 : 0,
            'updated' => $updated,
            'skipped' => $skipped,
            'message' => $updated > 0
                ? $updated . ' ' . translate('products_updated')
                : translate('no_products_could_be_updated'),
        ]);
    }

    public function deleteImage(Request $request, ProductService $service): RedirectResponse
    {
        $this->deleteFile(filePath: '/product/' . $request['image']);
        $product = $this->productRepo->getFirstWhere(params: ['id' => $request['id']]);

        if (count(json_decode($product['images'])) < 2) {
            ToastMagic::warning(translate('you_can_not_delete_all_images'));
            return back();
        }

        $imageProcessing = $service->deleteImage(request: $request, product: $product);

        $updateData = [
            'images' => json_encode($imageProcessing['images']),
            'color_image' => json_encode($imageProcessing['color_images']),
        ];
        $this->productRepo->update(id: $request['id'], data: $updateData);

        ToastMagic::success(translate('product_image_removed_successfully'));
        return back();
    }

    public function getCategories(Request $request, ProductService $service): JsonResponse
    {
        $parentId = $request['parent_id'];
        $filter = ['parent_id' => $parentId];
        $categories = $this->categoryRepo->getListWhere(filters: $filter, dataLimit: 'all');
        $dropdown = $service->getCategoryDropdown(request: $request, categories: $categories);

        $childCategories = '';
        if (count($categories) == 1) {
            $subCategories = $this->categoryRepo->getListWhere(filters: ['parent_id' => $categories[0]['id']], dataLimit: 'all');
            $childCategories = $service->getCategoryDropdown(request: $request, categories: $subCategories);
        }

        return response()->json([
            'select_tag' => $dropdown,
            'sub_categories' => count($categories) == 1 ? $childCategories : '',
        ]);
    }

    public function exportList(Request $request, string $type): BinaryFileResponse
    {
        $filters = [
            'added_by' => $type == 'in-house' ? 'in_house' : $type,
            'request_status' => $request['request_status'],
            'status' => $request['status'],
            'seller_id' => $request['seller_id'],
            'filter_sort_by' => $request['filter_sort_by'] ?? 'latest',
            'filter_category_ids' => $request['filter_category_ids'] ?? [],
            'filter_sub_category_ids' => $request['filter_sub_category_ids'] ?? [],
            'filter_sub_sub_category_ids' => $request['filter_sub_sub_category_ids'] ?? [],
        ];

        $taxData = $this->getTaxSystemType();
        $productWiseTax = $taxData['productWiseTax'] && !$taxData['is_included'];
        $productWiseTaxRelation = $productWiseTax ? ['taxVats' => function ($query) {
            return $query->with(['tax'])->wherehas('tax', function ($query) {
                return $query->where('is_active', 1);
            });
        }] : [];

        $relations = ['category', 'brand', 'reviews', 'rating', 'orderDetails', 'orderDelivered', 'digitalVariation', 'seoInfo', 'translations', 'clearanceSale' => function ($query) {
            return $query->active();
        }];
        $relations = array_merge($productWiseTaxRelation, $relations);

        $filterWhereIn = ProductManager::getSortFilterWhereInArrays(request: $request);
        $products = $this->productRepo->getWebListWithScope(
            searchValue: requestString('searchValue') ?: null,
            filters: $filters,
            whereIn: $filterWhereIn,
            relations: $relations,
            dataLimit: 'all'
        );

        $category = (!empty($request['category_id']) && $request->has('category_id')) ? $this->categoryRepo->getFirstWhere(params: ['id' => $request['category_id']]) : 'all';
        $subCategory = (!empty($request->sub_category_id) && $request->has('sub_category_id')) ? $this->categoryRepo->getFirstWhere(params: ['id' => $request['sub_category_id']]) : 'all';
        $subSubCategory = (!empty($request->sub_sub_category_id) && $request->has('sub_sub_category_id')) ? $this->categoryRepo->getFirstWhere(params: ['id' => $request['sub_sub_category_id']]) : 'all';
        $brand = (!empty($request->brand_id) && $request->has('brand_id')) ? $this->brandRepo->getFirstWhere(params: ['id' => $request->brand_id]) : 'all';
        $seller = (!empty($request->seller_id) && $request->has('seller_id')) ? $this->sellerRepo->getFirstWhere(params: ['id' => $request->seller_id]) : '';
        $data = [
            'products' => $products,
            'category' => $category,
            'sub_category' => $subCategory,
            'sub_sub_category' => $subSubCategory,
            'brand' => $brand,
            'searchValue' => $request['searchValue'],
            'type' => $this->stringOr($request->type, $type),
            'seller' => $seller,
            'status' => $request->status ?? '',
            'productWiseTax' => $productWiseTax
        ];
        return Excel::download(new ProductListExport($data), ucwords($this->stringOr($request['type'], $type)) . '-' . 'product-list.xlsx');
    }

    public function getBarcodeView(Request $request, string|int $id): View|RedirectResponse
    {
        if ($request['limit'] > 270) {
            ToastMagic::warning(translate('you_can_not_generate_more_than_270_barcode'));
            return back();
        }
        $product = $this->productRepo->getFirstWhere(params: ['id' => $id]);
        $rangeData = range(1, $request->limit ?? 4);
        $barcodes = array_chunk($rangeData, 21);
        return view('admin-views.product.barcode', compact('product', 'barcodes'));
    }

    public function getStockLimitListView(Request $request, string $type): View
    {
        $stockLimit = getWebConfig(name: 'stock_limit');
        $sortOrderQty = $request['sortOrderQty'];
        $searchValue = $request['searchValue'];
        $withCount = ['orderDetails'];
        $status = $request['status'];
        $filters = [
            'added_by' => $type,
            'product_type' => 'physical',
            'request_status' => $request['status'],
            'current_stock' => getWebConfig(name: 'stock_limit'),
        ];

        $orderBy = [];
        if ($sortOrderQty == 'quantity_asc') {
            $orderBy = ['current_stock' => 'asc'];
        } else if ($sortOrderQty == 'quantity_desc') {
            $orderBy = ['current_stock' => 'desc'];
        } elseif ($sortOrderQty == 'order_asc') {
            $orderBy = ['order_details_count' => 'asc'];
        } elseif ($sortOrderQty == 'order_desc') {
            $orderBy = ['order_details_count' => 'desc'];
        } elseif ($sortOrderQty == 'default') {
            $orderBy = ['id' => 'asc'];
        }
        $products = $this->productRepo->getStockLimitListWhere(orderBy: $orderBy, searchValue: $searchValue, filters: $filters, withCount: $withCount, dataLimit: getWebConfig(name: WebConfigKey::PAGINATION_LIMIT));
        return view('admin-views.product.stock-limit-list', compact('products', 'searchValue', 'status', 'sortOrderQty', 'stockLimit'));
    }

    public function delete(string|int $id, ProductService $service): RedirectResponse
    {
        $product = $this->productRepo->getFirstWhere(params: ['id' => $id]);
        $addedBy = $product['added_by'];

        if ($product) {
            $this->translationRepo->delete(model: 'App\Models\Product', id: $id);
            $this->cartRepo->delete(params: ['product_id' => $id]);
            $this->wishlistRepo->delete(params: ['product_id' => $id]);
            $this->flashDealProductRepo->delete(params: ['product_id' => $id]);
            $this->dealOfTheDayRepo->delete(params: ['product_id' => $id]);
            $service->deleteImages(product: $product);
            $this->productRepo->delete(params: ['id' => $id]);
            $bannerIds = $this->bannerRepo->getListWhere(filters: ['resource_type' => 'product', 'resource_id' => $product['id']])->pluck('id');
            $bannerIds->map(function ($bannerId) {
                $this->bannerRepo->update(id: $bannerId, data: ['published' => 0, 'resource_id' => null]);
            });
            ToastMagic::success(translate('product_removed_successfully'));
        } else {
            ToastMagic::error(translate('invalid_product'));
        }
        if ($addedBy === "admin") {
            return redirect()->route('admin.products.list', ['type' => 'in_house']);
        } else {
            return redirect()->back();
        }
    }

    public function deleteRestock(string|int $id): RedirectResponse
    {
        $this->restockProductRepo->delete(params: ['id' => $id]);
        $this->restockProductCustomerRepo->delete(params: ['restock_product_id' => $id]);
        ToastMagic::success(translate('product_restock_removed_successfully'));
        return back();
    }

    public function getVariations(Request $request): JsonResponse
    {
        $product = $this->productRepo->getFirstWhere(params: ['id' => $request['id']]);
        $restockId = $request['restock_id'];
        $restockVariants = $this->restockProductRepo->getListWhereBetween(filters: ['product_id' => $request['id']])?->pluck('variant')->toArray() ?? [];

        return response()->json([
            'view' => view('admin-views.product.partials._update-stock', compact('product', 'restockId', 'restockVariants'))->render()
        ]);
    }

    public function updateQuantity(Request $request): RedirectResponse
    {
        $variations = [];
        $stockCount = $request['current_stock'];
        if ($request->has('type')) {
            foreach ($request['type'] as $key => $str) {
                $item = [];
                $item['type'] = $str;
                $item['price'] = currencyConverter(amount: abs($request['price_' . str_replace('.', '_', $str)]));
                $item['sku'] = $request['sku_' . str_replace('.', '_', $str)];
                $item['qty'] = abs($request['qty_' . str_replace('.', '_', $str)]);
                $variations[] = $item;
            }
        }
        $dataArray = [
            'current_stock' => $stockCount,
            'variation' => json_encode($variations),
        ];

        if ($stockCount >= 0) {
            $product = $this->productRepo->getFirstWhere(params: ['id' => $request['product_id']]);
            $this->productRepo->updateByParams(params: ['id' => $request['product_id']], data: $dataArray);
            $updatedProduct = $this->productRepo->getFirstWhere(params: ['id' => $request['product_id']]);
            $this->updateRestockRequestListAndNotify(product: $product, updatedProduct: $updatedProduct);

            ToastMagic::success(translate('product_quantity_updated_successfully'));
            return back();
        }
        ToastMagic::warning(translate('product_quantity_can_not_be_less_than_0_'));
        return back();
    }

    public function getBulkImportView(): View
    {
        return view('admin-views.product.bulk-import');
    }

    public function importBulkProduct(Request $request, ProductService $service): RedirectResponse
    {
        $request->validate([
            'products_file' => 'required'
        ]);
        $shopId = getInHouseShopConfig('id') ?? null;
        $dataArray = $service->getImportBulkProductData(request: $request, addedBy: 'admin', shopId: $shopId);
        if (!$dataArray['status']) {
            ToastMagic::error($dataArray['message']);
            return back();
        }
        $this->productRepo->addArray(data: $dataArray['products']);
        $products = \App\Models\Product::whereIn('code', array_keys($dataArray['productsTax']))->get();
        $SystemTaxVat = SystemTaxSetup::where('is_active', 1)->where('is_default', 1)->first();

        $allTaxIds = collect($dataArray['productsTax'])->flatten()->map(function ($ids) {
            return explode(',', $ids);
        })->flatten()->unique();
        $validTaxIds = \Modules\TaxModule\app\Models\Tax::whereIn('id', $allTaxIds)->pluck('id')->toArray();
        $rowsToInsert = [];
        \Modules\TaxModule\app\Models\Taxable::where('tax_for', 'tax_for_orders')->where('taxable_type', ' App\Models\Product')->whereIn('taxable_id', $products->pluck('id')->toArray() ?? [0])->delete();
        foreach ($products as $product) {
            $taxIds = explode(',', $dataArray['productsTax'][$product->code]);
            foreach ($taxIds as $taxId) {
                if (in_array($taxId, $validTaxIds)) {
                    $rowsToInsert[] = [
                        'taxable_type' => 'App\Models\Product',
                        'taxable_id' => $product->id,
                        'system_tax_setup_id' => $SystemTaxVat->id,
                        'tax_id' => $taxId,
                        'tax_for' => 'tax_for_orders',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }
        if (!empty($rowsToInsert)) {
            Taxable::insert($rowsToInsert);
        }
        ToastMagic::success($dataArray['message']);
        return back();

    }

    public function updatedProductList(Request $request, ?string $type = null): View
    {
        $type == 'vendor' ? 'seller' : 'in_house';
        $filters = [
            'added_by' => $type,
            'is_shipping_cost_updated' => 0,
        ];
        $searchValue = $request['searchValue'];
        $taxData = $this->getTaxSystemType();
        $productWiseTax = $taxData['productWiseTax'] && !$taxData['is_included'];
        $taxVats = $taxData['taxVats'];

        $subCategories = collect();
        $subSubCategories = collect();
        $paginationLimit = getWebConfig(name: WebConfigKey::PAGINATION_LIMIT) ? getWebConfig(name: WebConfigKey::PAGINATION_LIMIT) : 25;
        $sellers = $this->sellerRepo->getByStatusExcept(status: 'pending', relations: ['shop'], paginateBy: $paginationLimit);
        $brands = $this->brandRepo->getListWhere(orderBy: ['id' => 'desc'], dataLimit: 'all');
        $categories = $this->categoryRepo->getListWhere(filters: ['position' => 0], relations: ['childes.childes'], dataLimit: 'all');

        $products = $this->productRepo->getListWhere(orderBy: ['id' => 'desc'], searchValue: $searchValue, filters: $filters, dataLimit: getWebConfig(name: WebConfigKey::PAGINATION_LIMIT));
        return view('admin-views.product.updated-product-list', compact(
            'products',
            'searchValue',
            'sellers',
            'brands',
            'categories',
            'subCategories',
            'subSubCategories',
            'filters',
            'type',
            'productWiseTax'
        ));
    }

    public function updatedShipping(Request $request): JsonResponse
    {
        $product = $this->productRepo->getFirstWhere(params: ['id' => $request['id']]);
        $dataArray = ['is_shipping_cost_updated' => $request['status']];
        if ($request['status'] == 1) {
            $dataArray += [
                'shipping_cost' => $product['temp_shipping_cost']
            ];
        }
        $this->productRepo->update(id: $request['id'], data: $dataArray);

        return response()->json(['message' => translate('status_updated_successfully')], 200);
    }

    public function deny(ProductDenyRequest $request): JsonResponse
    {
        $dataArray = [
            'request_status' => 2,
            'status' => 0,
            'featured' => 0,
            'denied_note' => $request['denied_note'],
        ];
        $this->productRepo->update(id: $request['id'], data: $dataArray);
        $product = $this->productRepo->getFirstWhereWithoutGlobalScope(params: ['id' => $request['id']]);
        $vendor = $this->sellerRepo->getFirstWhere(params: ['id' => $product['user_id']]);
        if ($vendor['cm_firebase_token']) {
            ProductRequestStatusUpdateEvent::dispatch('product_request_rejected_message', 'seller', $vendor['app_language'] ?? getDefaultLanguage(), $vendor['cm_firebase_token']);
        }
        return response()->json(['message' => translate('product_request_denied') . '.']);
    }

    public function approveStatus(Request $request): JsonResponse
    {
        $product = $this->productRepo->getFirstWhereWithoutGlobalScope(params: ['id' => $request['id']]);
        $dataArray = [
            'request_status' => ($product['request_status'] == 0) ? 1 : 0
        ];
        $this->productRepo->update(id: $request['id'], data: $dataArray);
        $vendor = $this->sellerRepo->getFirstWhere(params: ['id' => $product['user_id']]);
        if ($vendor['cm_firebase_token']) {
            ProductRequestStatusUpdateEvent::dispatch('product_request_approved_message', 'seller', $vendor['app_language'] ?? getDefaultLanguage(), $vendor['cm_firebase_token']);
        }
        return response()->json(['message' => translate('product_request_approved') . '.']);
    }

    public function getSearchedProductsView(Request $request): JsonResponse
    {
        $searchValue = $request['searchValue'] ?? null;
        $products = $this->productRepo->getListWhere(
            searchValue: $searchValue,
            filters: [
                'category_id' => $request['category_id'],
                'code' => $request['name'],
                'has_reviews' => true
            ],
            dataLimit: 'all'
        );
        return response()->json([
            'count' => $products->count(),
            'result' => view('admin-views.partials._search-product', compact('products'))->render(),
        ]);
    }

    /**
     * Products as {id, name, thumbnail} for the pickers on the product form.
     *
     * The existing search endpoint answers with rendered HTML for the flash-deal dropdown; a
     * picker that keeps a LIST of chosen products needs data it can render as chips, so this
     * returns the rows themselves. Read-only and admin-scoped, capped like every other search.
     */
    public function getProductPickerOptions(Request $request): JsonResponse
    {
        $term = $this->stringOr($request->get('searchValue'));

        $products = $this->productRepo->getListWhere(
            searchValue: $term !== '' ? $term : null,
            filters: ['status' => 1],
            dataLimit: 20,
        );

        return response()->json([
            'options' => collect($products->items() ?? $products)->map(fn ($product) => [
                'value' => $product->id,
                'label' => $product->name,
                'thumbnail' => getStorageImages(path: $product->thumbnail_full_url, type: 'backend-product'),
            ])->values(),
        ]);
    }

    /** Names and thumbnails for already-picked ids, so a saved list shows products not numbers. */
    public function getProductPickerLabels(Request $request): JsonResponse
    {
        $requestedIds = $request->get('ids');
        // The picker posts its selection back comma-joined, but ?ids[]=1&ids[]=2 says the same thing
        // unambiguously, so honour both rather than blanking a list the operator has already chosen.
        $candidates = is_array($requestedIds) ? $requestedIds : explode(',', $this->stringOr($requestedIds));
        $ids = array_values(array_filter(array_map('intval', array_filter($candidates, 'is_scalar')), fn ($id) => $id > 0));
        if ($ids === []) {
            return response()->json(['options' => []]);
        }

        $products = \App\Models\Product::whereIn('id', $ids)->get(['id', 'name', 'thumbnail', 'thumbnail_storage_type']);

        return response()->json([
            'options' => $products
                ->sortBy(fn ($product) => array_search($product->id, $ids, true))
                ->map(fn ($product) => [
                    'value' => $product->id,
                    'label' => $product->name,
                    'thumbnail' => getStorageImages(path: $product->thumbnail_full_url, type: 'backend-product'),
                ])->values(),
        ]);
    }

    public function getSearchedAllProductsView(Request $request): JsonResponse
    {
        $searchValue = $request['searchValue'] ?? null;
        $products = $this->productRepo->getListWhere(
            searchValue: $searchValue,
            filters: [
                'status' => 1,
                'category_id' => $request['category_id'],
                'code' => $request['name'],
            ],
            dataLimit: 20
        );
        if (!empty($request['deal_id'])) {
            $flashDealProducts = $this->flashDealProductRepo->getListWhere(filters: ['flash_deal_id' => $request['deal_id']])->pluck('product_id')->toArray();
            $filteredProducts = $products->filter(function ($product) use ($flashDealProducts) {
                return !in_array($product->id, $flashDealProducts);
            });
            $products = $filteredProducts;
        }
        return response()->json([
            'count' => $products->count(),
            'result' => view('admin-views.partials._search-product', compact('products'))->render(),
        ]);
    }

    public function getProductGalleryView(Request $request): View
    {
        $searchValue = $request['searchValue'];
        $filters = [
            'searchValue' => $searchValue,
            'request_status' => 1,
            'product_search_type' => 'product_gallery',
            'seller_id' => ($request['vendor_id'] == 'in_house' || $request['added_by'] == 'in_house') ? '' : $request['vendor_id'],
            'filter_sort_by' => $request['filter_sort_by'] ?? 'latest',
            'filter_category_ids' => $request['filter_category_ids'] ?? [],
            'filter_sub_category_ids' => $request['filter_sub_category_ids'] ?? [],
            'filter_sub_sub_category_ids' => $request['filter_sub_sub_category_ids'] ?? [],
        ];

        if ($request['vendor_id'] != 'all' && $request['vendor_id'] != null) {
            $filters['added_by'] = ($request['vendor_id'] == 'in_house' || $request['added_by'] == 'in_house') ? 'in_house' : '';
        }

        $filterWhereIn = ProductManager::getSortFilterWhereInArrays(request: $request);
        $products = $this->productRepo->getWebListWithScope(
            searchValue: requestString('searchValue') ?: null,
            filters: $filters,
            whereIn: $filterWhereIn,
            relations: ['clearanceSale' => function ($query) {
                return $query->active();
            }, 'taxVats' => function ($query) {
                return $query->with(['tax'])->wherehas('tax', function ($query) {
                    return $query->where('is_active', 1);
                });
            }], dataLimit: getWebConfig(name: WebConfigKey::PAGINATION_LIMIT));

        $products->map(function ($product) {
            if ($product->product_type == 'physical' && count(json_decode($product->choice_options)) > 0 || count(json_decode($product->colors)) > 0) {
                $colorName = [];
                $colorsCollection = collect(json_decode($product->colors));
                $colorsCollection->map(function ($color) use (&$colorName) {
                    $colorName[] = $this->colorRepo->getFirstWhere(['code' => $color])->name;
                });
                $product['colorsName'] = $colorName;
            }
        });
        $vendors = $this->sellerRepo->getListWhere(filters: ['status' => 'approved'], relations: ['shop'], dataLimit: 'all');

        $productBrands = $this->brandRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            dataLimit: 'all'
        );

        $productStores = $this->shopRepo->getListWhere(
            orderBy: ['author_type' => 'asc'],
            dataLimit: empty($request['filter_shop_ids']) ? 8 : 99
        );

        $productCategories = $this->categoryRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            filters: ['position' => 0],
            relations: ['childes.childes'],
            dataLimit: 'all'
        );

        return view('admin-views.product.product-gallery', compact('products', 'vendors', 'productBrands', 'productCategories', 'searchValue', 'productStores'));
    }

    public function getStockLimitStatus(Request $request, string $type): JsonResponse
    {
        $filters = [
            'added_by' => $type,
            'product_type' => 'physical',
            'request_status' => $request['status'],
            'current_stock' => getWebConfig(name: 'stock_limit'),
        ];
        $products = $this->productRepo->getStockLimitListWhere(filters: $filters, dataLimit: 'all');
        if ($products->count() == 1) {
            $product = $products->first();
            $thumbnail = getStorageImages(path: $product->thumbnail_full_url, type: 'backend-product');
            return response()->json(['status' => 'one_product', 'product_count' => 1, 'product' => $product, 'thumbnail' => $thumbnail]);
        } else {
            return response()->json(['status' => 'multiple_product', 'product_count' => $products->count()]);
        }

    }

    public function getMultipleProductDetailsView(Request $request): JsonResponse
    {
        $selectedProducts = $this->productRepo->getListWhere(
            filters: [
                'productIds' => $request['productIds'],
            ],
            dataLimit: 'all'
        );
        return response()->json([
            'result' => view('admin-views.partials._select-product', compact('selectedProducts'))->render(),
        ]);
    }

    public function deletePreviewFile(Request $request): JsonResponse
    {
        $product = $this->productRepo->getFirstWhereWithoutGlobalScope(params: ['id' => $request['product_id']]);
        $this->productService->deletePreviewFile(product: $product);
        $this->productRepo->update(id: $request['product_id'], data: ['preview_file' => null]);
        return response()->json([
            'status' => 1,
            'message' => translate('Preview_file_deleted')
        ]);
    }

    public function getRequestRestockListView(Request $request): View|RedirectResponse
    {
        $filters = [
            'added_by' => 'in_house',
            'brand_id' => $request['brand_id'],
            'category_id' => $request['category_id'],
            'sub_category_id' => $request['sub_category_id'],
        ];

        $restockDate = $this->stringOr($request['restock_date']);
        $startDate = '';
        $endDate = '';
        $subCategories = collect();

        try {
            if (!empty($restockDate)) {
                $dates = explode(' - ', $restockDate);
                $startDate = Carbon::createFromFormat('d M Y', $dates[0])->startOfDay();
                $endDate = Carbon::createFromFormat('d M Y', $dates[1])->endOfDay();
            }
        } catch (\Exception $exception) {
            ToastMagic::error($exception->getMessage());
            return redirect()->back();
        }

        $restockProducts = $this->restockProductRepo->getListWhereBetween(
            orderBy: ['updated_at' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: $filters,
            relations: ['product'],
            whereBetween: 'created_at',
            whereBetweenFilters: $startDate && $endDate ? [$startDate, $endDate] : [],
            dataLimit: getWebConfig(name: WebConfigKey::PAGINATION_LIMIT),
        );
        $brands = $this->brandRepo->getListWhere(filters: ['status' => 1], dataLimit: 'all');
        $categories = $this->categoryRepo->getListWhere(filters: ['position' => 0], dataLimit: 'all');
        $subCategory = $this->categoryRepo->getFirstWhere(params: ['id' => $request['sub_category_id']]);
        $totalRestockProducts = $this->restockProductRepo->getListWhere(filters: $filters, dataLimit: 'all')->count();

        if ($request->has('category_id')) {
            $subCategories = $this->categoryRepo->getListWhere(filters: ['parent_id' => $request['category_id']]);
        }

        return view('admin-views.product.request-restock-list', compact(
            'restockProducts',
            'brands',
            'categories',
            'subCategory',
            'filters',
            'totalRestockProducts',
            'subCategories',
        ));
    }

    public function exportRestockList(Request $request): BinaryFileResponse
    {
        $filters = [
            'added_by' => 'in_house',
            'brand_id' => $request['brand_id'],
            'category_id' => $request['category_id'],
            'sub_category_id' => $request['sub_category_id'],
        ];

        $restockDate = $this->stringOr($request['restock_date']);
        $startDate = '';
        $endDate = '';
        if (!empty($restockDate)) {
            $dates = explode(' - ', $restockDate);
            $startDate = Carbon::createFromFormat('m/d/Y', $dates[0])->startOfDay();
            $endDate = Carbon::createFromFormat('m/d/Y', $dates[1])->endOfDay();
        }

        $restockProducts = $this->restockProductRepo->getListWhereBetween(
            orderBy: ['updated_at' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: $filters,
            relations: ['product'],
            whereBetween: 'created_at',
            whereBetweenFilters: $startDate && $endDate ? [$startDate, $endDate] : [],
            dataLimit: 'all',
        );
        $brand = (!empty($request->brand_id) && $request->has('brand_id')) ? $this->brandRepo->getFirstWhere(params: ['id' => $request->brand_id]) : 'all';
        $category = (!empty($request['category_id']) && $request->has('category_id')) ? $this->categoryRepo->getFirstWhere(params: ['id' => $request['category_id']]) : 'all';
        $subCategory = (!empty($request->sub_category_id) && $request->has('sub_category_id')) ? $this->categoryRepo->getFirstWhere(params: ['id' => $request['sub_category_id']]) : 'all';

        $data = [
            'products' => $restockProducts,
            'category' => $category,
            'subCategory' => $subCategory,
            'brand' => $brand,
            'searchValue' => $request['searchValue'],
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];
        return Excel::download(new RestockProductListExport($data), 'restock-product-list.xlsx');
    }

    /**
     * One request value as a string, or the fallback when it cannot be read as one.
     *
     * Every filter on these screens comes off a hand-editable URL and can be sent as an array —
     * `?searchValue[]=x`. Casting one to a string is a warning this application's handler turns
     * into a throw, so an unreadable filter would take the whole page down instead of simply not
     * being applied. A filter nobody can spell is not a filter.
     */
    private function stringOr(mixed $value, string $fallback = ''): string
    {
        return is_string($value) ? trim($value) : $fallback;
    }
}
