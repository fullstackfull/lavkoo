<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\ReviewRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Traits\FileManagerTrait;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReviewController extends Controller
{
    use FileManagerTrait;

    public function __construct(
        private readonly ReviewRepositoryInterface $reviewRepo,
        private readonly OrderRepositoryInterface  $orderRepo,
    )
    {
    }

    public function getReview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $user = Helpers::getCustomerInformation($request);
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['order_id'], 'customer_id' => $user['id'], 'payment_status' => 'paid']);
        if (!isset($order)) {
            return response()->json(['message' => translate('Invalid_order')], 403);
        }

        $review = $this->reviewRepo->getFirstWhere(params: [
            'delivery_man_id' => $order['delivery_man_id'],
            'customer_id' => $user['id'],
            'order_id' => $request['order_id'],
        ]);

        return response()->json($review, 200);
    }

    public function updateDeliveryManReview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'comment' => 'required',
            'rating' => 'required|min:1|max:5',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $user = Helpers::getCustomerInformation($request);
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['order_id'], 'customer_id' => $user['id'], 'payment_status' => 'paid']);
        if (!isset($order->delivery_man_id)) {
            return response()->json(['message' => translate('Invalid_review')], 403);
        }

        $review = $this->reviewRepo->getFirstWhere(params: [
            'delivery_man_id' => $order['delivery_man_id'],
            'customer_id' => $user['id'],
            'order_id' => $request['order_id'],
        ]);

        $dataArray = [
            'customer_id' => $user['id'],
            'delivery_man_id' => $order['delivery_man_id'],
            'order_id' => $request['order_id'],
            'comment' => $request['comment'],
            'rating' => $request['rating'],
            'updated_at' => now(),
        ];

        if (!$review) {
            $dataArray['created_at'] = now();
        }

        $this->reviewRepo->updateOrInsert(params: [
            'delivery_man_id' => $order['delivery_man_id'],
            'customer_id' => $user['id'],
            'order_id' => $request['order_id']
        ], data: $dataArray
        );

        return response()->json([
            'message' => $review ? translate('successfully_update_review') : translate('successfully_added_review')
        ], 200);
    }

}
