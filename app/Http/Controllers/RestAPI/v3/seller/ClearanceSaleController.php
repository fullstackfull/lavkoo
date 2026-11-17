<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\StockClearanceProductRepositoryInterface;
use App\Contracts\Repositories\StockClearanceSetupRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Services\ClearanceSaleService;
use App\Services\ProductService;
use App\Services\StockClearanceProductService;
use App\Traits\SellerOwnedRecords;
use App\Utils\Helpers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClearanceSaleController extends Controller
{
    use SellerOwnedRecords;

    public function __construct(
        private readonly StockClearanceProductRepositoryInterface $stockClearanceProductRepo,
        private readonly ProductRepositoryInterface               $productRepo,
        private readonly StockClearanceProductService             $stockClearanceProductService,
        private readonly StockClearanceSetupRepositoryInterface   $stockClearanceSetupRepo,
        private readonly ClearanceSaleService                     $clearanceSaleService,
        private readonly ProductService                           $productService,
    )
    {
    }

    public function list(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $stockClearanceProducts = $this->stockClearanceProductRepo->getListWhere(
            filters: ['added_by' => 'vendor', 'user_id' => $seller->id],
            relations: ['product' => function ($query) {
                return $query->with(['brand', 'category', 'seller.shop', 'clearanceSale' => function ($query) {
                    return $query;
                }]);
            }],
            dataLimit: $request['limit'] ?? getWebConfig(name: 'pagination_limit')
        );

        $stockClearanceProducts->map(function ($item) {
            $item->product = Helpers::product_data_formatting($item['product'], false);
        });

        return response()->json([
            'total_size' => $stockClearanceProducts->total(),
            'limit' => (int)($request['limit'] ?? getWebConfig(name: 'pagination_limit')),
            'offset' => (int)$request['offset'],
            'products' => $stockClearanceProducts->items()
        ], 200);
    }

    public function addClearanceProduct(Request $request): JsonResponse
    {
        $seller = $request['seller'];
        $condition = [];
        $productIds = [];
        $requestData = [];

        foreach ($request['products'] as $product) {
            $productIds[] = $product['id'];
            $requestData['discount_amount'][$product['id']] = $product['discount_amount'];
            $requestData['discount_type'][$product['id']] = $product['discount_type'];
        }

        $clearanceProductLists = $this->productRepo->getListWhere(
            filters: [
                'productIds' => $productIds,
                'added_by' => 'seller',
                'seller_id' => $seller->id,
            ],
            relations: ['seller.shop'],
            dataLimit: 'all'
        );
        if ($clearanceProductLists->count() < count(array_unique($productIds))) {
            return $this->recordNotFound(code: 'product', message: 'product_not_found');
        }

        $clearanceConfig = $this->stockClearanceSetupRepo->getFirstWhere(params: ['setup_by' => 'vendor', 'user_id' => $seller->id]);
        if ($clearanceConfig) {
            foreach ($clearanceProductLists as $key => $clearanceProductList) {
                $stockClearanceProduct = $this->stockClearanceProductRepo->getFirstWhere(params: ['product_id' => $clearanceProductList->id]);
                if (!$stockClearanceProduct) {
                    $condition = $this->stockClearanceProductService->checkAddConditions(request: $requestData, product: $clearanceProductList, config: $clearanceConfig);
                    if (!$condition['status']) {
                        return response()->json([
                            'status' => $condition['status'],
                            'message' => $condition['message']
                        ], 200);
                    }
                }
            }

            foreach ($clearanceProductLists as $key => $clearanceProductList) {
                $stockClearanceProduct = $this->stockClearanceProductRepo->getFirstWhere(params: ['product_id' => $clearanceProductList->id]);
                $condition = $this->stockClearanceProductService->checkAddConditions(request: $requestData, product: $clearanceProductList, config: $clearanceConfig);
                if (!$stockClearanceProduct && $condition['status']) {
                    $dataArray = [
                        'added_by' => 'vendor',
                        'user_id' => $seller->id,
                        'shop_id' => $clearanceProductList?->seller->shop?->id,
                        'is_active' => 1,
                        'setup_id' => $clearanceConfig['id'],
                        'product_id' => $clearanceProductList->id,
                        'discount_type' => $clearanceConfig->discount_type == 'flat' ? 'percentage' : $requestData['discount_type'][$clearanceProductList->id],
                        'discount_amount' => $clearanceConfig->discount_type == 'flat' ? $clearanceConfig->discount_amount : ($requestData['discount_type'][$clearanceProductList->id] == 'flat' ? currencyConverter(amount: $requestData['discount_amount'][$clearanceProductList->id]) : $requestData['discount_amount'][$clearanceProductList->id] ?? 0),
                    ];
                    $this->stockClearanceProductRepo->add(data: $dataArray);
                }
            }
        }

        return response()->json([
            'status' => $condition['status'] ?? false,
            'message' => $condition['message'] ?? translate('Configuration_not_valid')
        ], 200);
    }

    public function getConfigData(Request $request): JsonResponse
    {
        $seller = $request['seller'];
        $clearanceConfig = $this->stockClearanceSetupRepo->getFirstWhere(params: ['setup_by' => 'vendor', 'user_id' => $seller->id]);
        return response()->json($clearanceConfig, 200);
    }

    public function updateClearanceProductStatus(Request $request): JsonResponse
    {
        $stockClearanceProduct = $this->ownedClearanceProduct(request: $request, relations: ['setup']);
        if (!$stockClearanceProduct) {
            return $this->recordNotFound(code: 'clearance_product', message: 'clearance_product_not_found');
        }
        if (!$this->productService->validateStockClearanceProductDiscount(stockClearanceProduct: $stockClearanceProduct)) {
            return response()->json([
                'message' => translate('Your_products_unit_price_is_lower_then_offer_price'),
            ], 403);
        }

        $this->stockClearanceProductRepo->updateByParams(params: ['id' => $stockClearanceProduct['id']], data: ['is_active' => $request['is_active'] ?? 0]);
        $message = $request['is_active'] ? translate('your_item_status_is_active_for_clearance_sale') : translate('your_item_status_is_temporary_off_for_clearance_sale');
        return response()->json(['message' => $message], 200);
    }

    public function updateClearanceProductDiscount(Request $request): JsonResponse
    {
        $seller = $request['seller'];
        $stockClearanceProduct = $this->ownedClearanceProduct(request: $request);
        if (!$stockClearanceProduct) {
            return $this->recordNotFound(code: 'clearance_product', message: 'clearance_product_not_found');
        }

        $clearanceConfig = $this->stockClearanceSetupRepo->getFirstWhere(params: ['setup_by' => 'vendor', 'user_id' => $seller->id]);
        $product = $this->productRepo->getFirstWhere(params: ['id' => $stockClearanceProduct['product_id'], 'added_by' => 'seller', 'user_id' => $seller->id]);
        $condition = $this->stockClearanceProductService->checkConditions(request: $request, product: $product, config: $clearanceConfig);

        if ($condition['status']) {
            $dataArray = [
                'discount_amount' => $request['discount_type'] == 'flat' ? currencyConverter(amount: $request['discount_amount']) : $request['discount_amount'],
                'discount_type' => $request['discount_type'],
            ];

            $this->stockClearanceProductRepo->updateByParams(params: ['id' => $stockClearanceProduct['id']], data: $dataArray);
        }
        return response()->json([
            'status' => $condition['status'],
            'message' => $condition['message']
        ]);
    }

    public function deleteClearanceProduct(Request $request): JsonResponse
    {
        $stockClearanceProduct = $this->ownedClearanceProduct(request: $request);
        if (!$stockClearanceProduct) {
            return $this->recordNotFound(code: 'clearance_product', message: 'clearance_product_not_found');
        }

        $this->stockClearanceProductRepo->delete(params: ['id' => $stockClearanceProduct['id']]);

        return response()->json([
            'message' => translate('clearance_product_removed_successfully')
        ], 200);
    }

    public function deleteAllClearanceProduct(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $this->stockClearanceProductRepo->delete(params: ['added_by' => 'vendor', 'user_id' => $seller->id]);

        return response()->json([
            'message' => translate('all_stock_clearance_products_removed_successfully')
        ], 200);
    }

    public function updateClearanceConfigStatus(Request $request): JsonResponse
    {
        $seller = $request['seller'];
        $clearanceConfig = $this->stockClearanceSetupRepo->getFirstWhere(params: ['setup_by' => 'vendor', 'user_id' => $seller->id]);
        if ($clearanceConfig) {
            $this->stockClearanceSetupRepo->updateByParams(
                params: ['setup_by' => 'vendor', 'user_id' => $seller->id],
                data: ['is_active' => $request->get('status', 0)]
            );
            return response()->json([
                'status' => 1,
                'message' => translate('Status_updated_successfully'),
            ]);
        }
        return response()->json([
            'status' => 0,
            'message' => translate('Please_setup_the_configuration_first'),
        ]);
    }

    public function updateConfigData(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $data = $this->clearanceSaleService->getConfigData(request: $request, setupBy: 'vendor', vendorId: $seller->id, shopId: $seller?->shop?->id);
        $config = $this->stockClearanceSetupRepo->updateOrCreate(params: ['setup_by' => 'vendor', 'user_id' => $seller->id], value: $data);

        if ($config['discount_type'] == 'flat') {
            $this->stockClearanceProductRepo->updateByParams(
                params: ['setup_id' => $config['id']],
                data: ['discount_type' => 'percentage', 'discount_amount' => $request['discount_amount']]
            );
        }

        return response()->json([
            'message' => translate('Setup_updated_successfully')
        ]);
    }

    private function ownedClearanceProduct(Request $request, array $relations = []): ?Model
    {
        return $this->stockClearanceProductRepo->getFirstWhere(
            params: ['product_id' => $request['product_id'], 'added_by' => 'vendor', 'user_id' => $request->seller->id],
            relations: $relations,
        );
    }
}
