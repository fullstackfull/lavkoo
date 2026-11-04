<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryShippingCost;
use App\Models\ShippingType;
use App\Utils\Convert;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;


class shippingController extends Controller
{
    public function get_shipping_type(Request $request):JsonResponse
    {
        $seller = $request->seller;
        $shippingMethod = getWebConfig(name: 'shipping_method');

        $seller_shipping = ShippingType::where('seller_id', $seller['id'])->first();

        $shippingType = isset($seller_shipping) == true ? $seller_shipping->shipping_type : 'order_wise';

        return response()->json([
            'type' => $shippingType
        ]);

    }

    public function selected_shipping_type(Request $request):JsonResponse
    {
        $seller = $request->seller;
        $seller_id = $seller['id'];

        $seller_shipping = ShippingType::where('seller_id', $seller_id)->first();

        if (isset($seller_shipping)) {

            $seller_shipping->shipping_type = $request->shipping_type;
            $seller_shipping->save();
        } else {
            $new_shipping_type = new ShippingType;
            $new_shipping_type->seller_id = $seller_id;
            $new_shipping_type->shipping_type = $request->shipping_type;
            $new_shipping_type->save();

        }

        return response()->json([
            'message' => translate('successfully updated')
        ]);
    }

    public function all_category_cost(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $allCategoryIds = Category::where(['position' => 0])->pluck('id')->toArray();
        $categoryShippingCostIds = CategoryShippingCost::where('seller_id', $seller['id'])->pluck('category_id')->toArray();
        if (isset($allCategoryIds)) {
            foreach ($allCategoryIds as $id) {
                if (!in_array($id, $categoryShippingCostIds)) {
                    $newCategoryShippingCost = new CategoryShippingCost;
                    $newCategoryShippingCost->seller_id = $seller['id'];
                    $newCategoryShippingCost->category_id = $id;
                    $newCategoryShippingCost->cost = 0;
                    $newCategoryShippingCost->save();
                }
            }
        }
        $allCategoryShippingCost = CategoryShippingCost::with('category')
            ->where('seller_id', $seller['id'])
            ->whereHas('category')
            ->get();
        return response()->json([
            'all_category_shipping_cost' => $allCategoryShippingCost
        ]);
    }

    public function set_category_cost(Request $request):JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required',
            'cost' => 'required',
            'multiply_qty' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)]);
        }
        if (isset($request->ids)) {
            foreach ($request->ids as $key => $id) {

                $category_shipping_cost = CategoryShippingCost::find($id);
                $category_shipping_cost->cost = Convert::usd($request->cost[$key]);
                $category_shipping_cost->multiply_qty = $request->multiply_qty[$key];
                $category_shipping_cost->save();
            }
        }

        return response()->json([
            'success' => translate('successfully_updated')
        ]);

    }
}
