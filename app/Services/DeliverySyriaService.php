<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\ShippingMethod;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeliverySyriaService
{
    public static function send(array $orderIds): void
    {
        if (empty($orderIds)) {
            return;
        }

        try {

            $orders = Order::whereIn('id', $orderIds)->get();

            /*
             * شرط Delivery Syria
             * اتركه كما هو
             */
            $deliverySyria = ShippingMethod::where('name', 'DeliverySyria')->first();

            if (
                $deliverySyria &&
                $orders->contains('shipping_method_id', $deliverySyria->id)
            ) {

                /*
                 * فقط الطلبات التابعة لـ DeliverySyria
                 */
                $deliveryOrders = $orders->where(
                    'shipping_method_id',
                    $deliverySyria->id
                );

                foreach ($deliveryOrders as $order) {

                    /*
                     * عنوان العميل
                     */
                    $shippingAddress = $order->shipping_address_data;

                    // في حال كان مخزن JSON string
                    if (is_string($shippingAddress)) {
                        $shippingAddress = json_decode($shippingAddress, true);
                    }

                    /*
                     * تفاصيل المنتجات لحساب الوزن
                     */
                    $orderDetails = OrderDetail::with('product')
                        ->where('order_id', $order->id)
                        ->get();

                    $totalWeight = 0;

                    foreach ($orderDetails as $detail) {

                        $quantity = (int) ($detail->qty ?? 1);

                        /*
                         * إذا المنتج عنده weight نستخدمه.
                         * وإلا كل قطعة = 200 gram = 0.2 kg
                         */
                        $productWeight = isset($detail->product?->weight)
                            && is_numeric($detail->product->weight)
                            && $detail->product->weight > 0
                                ? (float) $detail->product->weight
                                : 0.2;

                        $totalWeight += $productWeight * $quantity;
                    }

                    /*
                     * Safety fallback
                     */
                    if ($totalWeight <= 0) {
                        $totalQuantity = max(
                            1,
                            (int) $orderDetails->sum(
                                fn ($detail) => $detail->qty ?? 1
                            )
                        );

                        $totalWeight = $totalQuantity * 0.2;
                    }

                    /*
                     * إذا Cash On Delivery:
                     * شركة التوصيل تجمع قيمة الطلب.
                     *
                     * إذا Stripe / prepaid / wallet:
                     * cash_collection = 0
                     */
                    $cashCollection =
                        $order->payment_method === 'cash_on_delivery'
                            ? (float) $order->order_amount
                            : 0;

                    $body = [

                        'hub_id' => '2',

                        'delivery_type_id' => '1',

                        'weight' => (string) round($totalWeight, 3),

                        /*
                         * بيانات الاستلام من Pharmacy Syria
                         */
                        'pickup_phone' => '0991234567',

                        'pickup_address' => 'Damascus, Malki',

                        'pickup_lat' => '33.5200',

                        'pickup_long' => '36.2900',

                        /*
                         * رقم الطلب
                         */
                        'invoice_no' => (string) $order->id,

                        /*
                         * المبلغ المطلوب تحصيله
                         */
                        'cash_collection' => (string) $cashCollection,

                        /*
                         * سعر الطلب
                         */
                        'selling_price' => (string) $order->order_amount,

                        /*
                         * بيانات العميل
                         */
                        'customer_name' => (string) data_get(
                            $shippingAddress,
                            'contact_person_name',
                            ''
                        ),

                        'customer_address' => (string) data_get(
                            $shippingAddress,
                            'address',
                            ''
                        ),

                        'lat' => (string) data_get(
                            $shippingAddress,
                            'latitude',
                            ''
                        ),

                        'long' => (string) data_get(
                            $shippingAddress,
                            'longitude',
                            ''
                        ),

                        'customer_phone' => (string) data_get(
                            $shippingAddress,
                            'phone',
                            ''
                        ),

                        'note' => (string) ($order->order_note ?? ''),

                        'Governorate' => 'damascus',
                    ];

                    /*
                     * ضع التوكن الحقيقي هنا
                     */
                  $token = \App\Models\BusinessSetting::where(
    'type',
    'delivery_syria_webhook_token'
)->value('value');





Log::info('Delivery Syria outgoing request', [
    'url' => 'https://deliverysyria.com/api/parcel/create-with-security',

    'headers' => [
        'X-Platform' => 'Pharmacy',
        'Authorization' => 'Bearer ' . (
            $token
                ? substr($token, 0, 6) . '***'
                : 'EMPTY'
        ),
    ],

    'token_exists' => !empty($token),
    'token_length' => strlen((string) $token),

    'body' => $body,
]);







                    $response = Http::timeout(20)
                        ->acceptJson()
                        ->withHeaders([
                            'X-Platform' => 'Pharmacy',
                            'Authorization' => 'Bearer ' . $token,
                        ])
                        ->post(
                            'https://deliverysyria.com/api/parcel/create-with-security',
                            $body
                        );

                    /*
                     * LOG
                     */
                    if ($response->successful()) {

                        Log::info('Delivery Syria parcel created', [
                            'order_id' => $order->id,
                            'status' => $response->status(),
                            'response' => $response->json(),
                            'payload' => $body,
                        ]);

                    } else {

                        Log::error('Delivery Syria parcel creation failed', [
                            'order_id' => $order->id,
                            'status' => $response->status(),
                            'response' => $response->body(),
                            'payload' => $body,
                        ]);
                    }
                }
            }

        } catch (\Throwable $e) {

            Log::error('Delivery Syria service failed', [
                'order_ids' => $orderIds,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}