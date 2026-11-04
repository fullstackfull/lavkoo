<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Models\ProductCompare;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompareController extends Controller
{
    public function __construct(
        private readonly ProductCompare $product_compare,
    )
    {
    }

    public function list(Request $request): JsonResponse
    {
        $compare_lists = $this->product_compare
            ->with(['product' => function ($query) {
                return $query->with(['rating', 'brand', 'reviews'])
                    ->withCount(['reviews' => function ($query) {
                        return $query->where(['status' => 1]);
                    }]);
            }])
            ->whereHas('product')
            ->where('user_id', $request->user()->id)
            ->get();

        $compare_lists->map(function ($data) {
            $data['product'] = Helpers::product_data_formatting($data['product']);
            return $data;
        });

        return response()->json(['compare_lists' => $compare_lists], 200);
    }

    public function compare_product_store(Request $request): JsonResponse
    {
        $compareList = $this->product_compare->where(['user_id' => $request->user()->id, 'product_id' => $request['product_id']])->first();
        if ($compareList) {
            $compareList->delete();
            $productCount = $this->product_compare->where(['product_id' => $request['product_id']])->count();
            return response()->json(['total_product_add' => $productCount, 'message' => 'Product removed from the compare list'], 200);
        } else {
            $countCompareListExist = $this->product_compare->where('user_id', $request->user()->id)->count();
            if ($countCompareListExist == 3) {
                $this->product_compare->where('user_id', $request->user()->id)->orderBY('id')->first()->delete();
            }

            $compareList = new ProductCompare;
            $compareList->user_id = $request->user()->id;
            $compareList->product_id = $request['product_id'];
            $compareList->save();

            $productCount = $this->product_compare->where(['product_id' => $request['product_id']])->count();
            return response()->json(['total_product_add' => $productCount, 'message' => 'Successfully added'], 200);
        }
    }

    public function compare_product_replace(Request $request): JsonResponse
    {
        $newCompareList = $this->product_compare->find($request['compare_id']);
        if ($newCompareList) {
            $newCompareList->product_id = $request['product_id'];
            $newCompareList->save();
        } else {
            $compareList = $this->product_compare->where(['user_id' => $request->user()->id, 'product_id' => $request['product_id']])->first();
            if ($compareList) {
                return response()->json(['message' => 'Product already eadded'], 403);
            }

            $this->product_compare->insert([
                'user_id' => auth('customer')->id(),
                'product_id' => $request['product_id']
            ]);
        }
        return response()->json(['message' => 'Successfully added'], 200);
    }

    public function clear_all(Request $request): JsonResponse
    {
        $this->product_compare->where('user_id', $request->user()->id)->delete();
        return response()->json(['message' => 'Compare list removed'], 200);
    }
}
