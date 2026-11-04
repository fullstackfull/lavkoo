<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Models\FlashDeal;
use App\Models\FlashDealProduct;
use App\Models\Product;
use App\Utils\Helpers;
use App\Utils\ProductManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlashDealController extends Controller
{
    public function getFlashDeal(): JsonResponse
    {
        $flashDeal = ProductManager::getPriorityWiseFlashDealsProductsQuery()['flashDeal'];
        return response()->json($flashDeal, 200);
    }

    public function getFlashDealProducts(Request $request, $deal_id): JsonResponse
    {
        $user = Helpers::getCustomerInformation($request);
        $userId = $user != 'offline' ? $user->id : '0';
        $limit = (int)($request['limit'] ?? 10);
        $offset = (int)($request['offset'] ?? 1);

        $products = ProductManager::getPriorityWiseFlashDealsProductsQuery(id: $deal_id, userId: $userId, dataLimit: 'all')['flashDealProducts'] ?? collect();
        $items = $products->forPage($offset, $limit)->values();

        return response()->json([
            'total_size' => $products->count(),
            'limit' => $limit,
            'offset' => $offset,
            'products' => Helpers::product_data_formatting($items, true),
        ], 200);
    }
}
