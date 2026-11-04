<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeliverySyriaWebhookController extends Controller
{
    public function updateStatus(Request $request): JsonResponse
    {
        /*
        |--------------------------------------------------------------------------
        | Security
        |--------------------------------------------------------------------------
        */

        $platform = $request->header('X-Platform');
        $authorization = $request->header('Authorization');

        $expectedToken =\App\Models\BusinessSetting::where(
    'type',
    'delivery_syria_webhook_token'
)->value('value');

        if ($platform !== 'deliverysyria') {
            return response()->json([
                'success' => false,
                'message' => 'Invalid platform',
            ], 401);
        }

        if (
            !$authorization ||
            $authorization !== 'Bearer ' . $expectedToken
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate payload
        |--------------------------------------------------------------------------
        */

        $validated = $request->validate([
            'product_order_id' => 'required',
            'status' => 'required|integer|in:0,1,2,3,4,5,6',
            'note' => 'nullable|string|max:2000',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Find Pharmacy Syria order
        |--------------------------------------------------------------------------
        |
        | نحن أرسلنا invoice_no = order.id إلى Delivery Syria
        |
        */

        $order = Order::find($validated['product_order_id']);

        if (!$order) {
            Log::warning('Delivery Syria order not found', [
                'product_order_id' => $validated['product_order_id'],
                'status' => $validated['status'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Map Delivery Syria status → Pharmacy Syria status
        |--------------------------------------------------------------------------
        */

        $statusMap = [
            0 => 'pending',
            4 => 'confirmed',
            5 => 'processing',
            6 => 'processing',
            3 => 'out_for_delivery',
            1 => 'delivered',
            2 => 'returned',
        ];

        $newStatus = $statusMap[$validated['status']];

        $oldStatus = $order->order_status;

        /*
        |--------------------------------------------------------------------------
        | Update order
        |--------------------------------------------------------------------------
        */

        $order->order_status = $newStatus;

        if (!empty($validated['note'])) {
            $order->order_note = $validated['note'];
        }

        /*
         * مهم إذا عندك تواريخ خاصة بالحالات.
         */
        if ($newStatus === 'delivered') {
            $order->payment_status = $order->payment_status;
        }

        $order->save();

        /*
        |--------------------------------------------------------------------------
        | Log
        |--------------------------------------------------------------------------
        */

        Log::info('Delivery Syria order status updated', [
            'order_id' => $order->id,
            'delivery_syria_status' => $validated['status'],
            'old_order_status' => $oldStatus,
            'new_order_status' => $newStatus,
            'note' => $validated['note'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully',

            'data' => [
                'order_id' => $order->id,
                'delivery_syria_status' => $validated['status'],
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ],
        ], 200);
    }
}