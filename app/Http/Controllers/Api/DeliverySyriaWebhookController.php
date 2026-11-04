<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliverySyriaParcel;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Services\DeliverySyria\DeliverySyriaStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Delivery Syria's order-status webhook (POST /api/delivery-syria/orders/update-status).
 *
 * Authentication is handled by the deliverysyria_auth middleware. This controller's job is to record
 * the courier's reported status against the right order — safely.
 *
 * "Safely" is the whole design: the platform's order_status is financially loaded (delivered matures
 * earnings/commission and marks paid; canceled/returned/failed restock inventory), and that
 * orchestration lives in the order controllers, not in a save(). So this webhook:
 *   - always records the courier status in the parcel ledger and the order's status history (auditable,
 *     visible), and
 *   - reflects the status into orders.order_status only for the side-effect-free intermediate states
 *     (pending/confirmed/processing/out_for_delivery), and only when the order is not already in a
 *     terminal state.
 * It never moves an order to delivered/canceled from an external call — finalizing an order, and moving
 * marketplace money, stays with the platform's own order flow by design.
 */
class DeliverySyriaWebhookController extends Controller
{
    /** Platform statuses whose transitions carry stock/earnings side effects — never touched here. */
    private const TERMINAL_ORDER_STATUSES = ['delivered', 'canceled', 'returned', 'failed'];

    public function updateStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_order_id' => 'required|string',
            'status' => 'required|integer',
            'note' => 'nullable|string',
        ]);

        $code = (int) $validated['status'];
        if (!DeliverySyriaStatus::isValid($code)) {
            return response()->json([
                'success' => false,
                'message' => 'Unknown status code',
            ], 422);
        }

        [$order, $parcel] = $this->resolve($validated['product_order_id']);

        if ($order === null) {
            Log::warning('DeliverySyria webhook: order not found', ['product_order_id' => $validated['product_order_id']]);

            return response()->json([
                'success' => false,
                'message' => 'Order not found in Pharmacy Syria',
            ], 404);
        }

        $label = DeliverySyriaStatus::label($code);
        $note = $validated['note'] ?? null;

        // 1) Record the courier status in the integration's own ledger (create the row if this order was
        //    not dispatched through us but the courier still reports on it).
        $this->recordParcel($order->id, $parcel, $validated['product_order_id'], $code, $label, $note);

        // 2) Reflect into order_status only for the safe intermediate states, and only when the order is
        //    not already terminal (so a webhook can never resurrect a canceled/delivered order).
        $applied = false;
        $currentStatus = $order->order_status;
        if (!DeliverySyriaStatus::isFinancialTransition($code) && !in_array($currentStatus, self::TERMINAL_ORDER_STATUSES, true)) {
            $target = DeliverySyriaStatus::toOrderStatus($code);
            if ($target !== null && $target !== $currentStatus) {
                // Query-builder update: persists the column without firing model events, so none of the
                // controller-level stock/earnings orchestration can be triggered by this external call.
                Order::where('id', $order->id)->update(['order_status' => $target, 'updated_at' => now()]);
                $applied = true;
            }
        }

        // 3) Always leave an audit trail on the order timeline.
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'user_id' => 0,
            'user_type' => 'delivery_syria',
            'status' => $applied ? DeliverySyriaStatus::toOrderStatus($code) : $currentStatus,
            'cause' => trim('Delivery Syria: ' . $label . ($note ? ' — ' . $note : '')),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order status recorded successfully in Pharmacy Syria',
            'order_id' => $order->id,
            'status_code' => $code,
            'status_label' => $label,
            'order_status_applied' => $applied,
        ], 200);
    }

    /**
     * Find the order the courier is reporting on. `product_order_id` may be the invoice_no we sent, the
     * courier tracking number we stored, the platform order id we echoed, or the order's third-party
     * tracking id — try each. Returns [Order|null, DeliverySyriaParcel|null].
     *
     * @return array{0: Order|null, 1: DeliverySyriaParcel|null}
     */
    private function resolve(string $productOrderId): array
    {
        $parcel = DeliverySyriaParcel::where('invoice_no', $productOrderId)
            ->orWhere('tracking_number', $productOrderId)
            ->first();
        if ($parcel !== null) {
            return [Order::find($parcel->order_id), $parcel];
        }

        $order = null;
        if (ctype_digit($productOrderId)) {
            $order = Order::find((int) $productOrderId);
        }
        if ($order === null) {
            $order = Order::where('third_party_delivery_tracking_id', $productOrderId)->first();
        }

        return [$order, null];
    }

    private function recordParcel(int $orderId, ?DeliverySyriaParcel $parcel, string $invoiceNo, int $code, string $label, ?string $note): void
    {
        $attributes = [
            'courier_status' => $code,
            'courier_status_label' => $label,
            'status_updated_at' => now(),
        ];
        if ($note !== null && $note !== '') {
            $attributes['note'] = $note;
        }

        if ($parcel !== null) {
            $parcel->fill($attributes)->save();

            return;
        }

        DeliverySyriaParcel::create(array_merge($attributes, [
            'order_id' => $orderId,
            'invoice_no' => $invoiceNo,
        ]));
    }
}
