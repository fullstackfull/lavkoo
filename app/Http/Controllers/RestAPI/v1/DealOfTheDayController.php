<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Models\DealOfTheDay;
use App\Models\Product;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealOfTheDayController extends Controller
{
    public function getDealOfTheDayProduct(Request $request): JsonResponse
    {
        $dealOfTheDay = DealOfTheDay::where(['status' => 1])->whereHas('product')->where('deal_of_the_days.status', 1)->first();

        if (isset($dealOfTheDay)) {
            $product = Product::active()->with(['rating', 'clearanceSale' => function ($query) {
                    return $query->active();
                }])
                ->withCount(['reviews' => function ($query) {
                    $query->active()->whereNull('delivery_man_id');
                }])->find($dealOfTheDay->product_id);

            if (!isset($product)) {
                $product = Product::active()->with(['rating', 'clearanceSale' => function ($query) {
                        return $query->active();
                    }])
                    ->withCount(['reviews' => function ($query) {
                        $query->active()->whereNull('delivery_man_id');
                    }])->inRandomOrder()->first();
            }
        } else {
            $product = Product::active()->with(['rating', 'clearanceSale' => function ($query) {
                    return $query->active();
                }])
                ->withCount(['reviews' => function ($query) {
                    $query->active()->whereNull('delivery_man_id');
                }])->inRandomOrder()->first();
        }
        $product = $product ? Helpers::product_data_formatting($product) : [];

        // Additive: whether this is the merchant's configured deal or the legacy random stand-in.
        // The web hides the section when no deal is live; the theme renderer in the app reads
        // this flag to do the same, while every stock screen keeps its old behavior untouched.
        if (!empty($product)) {
            $product = json_decode(json_encode($product), true) ?: [];
            $product['is_configured_deal'] = isset($dealOfTheDay);
        }

        return response()->json($product, 200);

    }
}
