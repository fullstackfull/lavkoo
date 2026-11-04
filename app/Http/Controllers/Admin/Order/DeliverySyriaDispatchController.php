<?php

namespace App\Http\Controllers\Admin\Order;

use App\Http\Controllers\Controller;
use App\Models\DeliverySyriaGovernorateRate;
use App\Models\DeliverySyriaParcel;
use App\Models\Order;
use App\Services\DeliverySyria\DeliverySyriaClient;
use App\Services\DeliverySyria\DeliverySyriaConfigService;
use App\Services\DeliverySyria\DeliverySyriaRateCalculator;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Dispatches an order to the Delivery Syria courier (optional integration).
 *
 * This is the outbound "ship it" action. It reuses the platform's existing external-courier fields —
 * on success the order is marked delivery_type=third_party_delivery, delivery_service_name='Delivery
 * Syria', and its tracking id is the courier's tracking_number — exactly the columns the manual
 * third-party form already writes. The integration's own parcel row keeps the courier's parcel_id and
 * wallet outcome.
 *
 * Idempotent: the order id is sent as the courier's unique invoice_no, so a repeat click (or a retry
 * after a timeout) is refused locally instead of double-shipping — the courier rejects a reused
 * invoice_no too. Nothing here touches order_status, stock or earnings.
 */
class DeliverySyriaDispatchController extends Controller
{
    public function __construct(
        private readonly DeliverySyriaConfigService $config,
        private readonly DeliverySyriaClient $client,
        private readonly DeliverySyriaRateCalculator $calculator,
    ) {
    }

    public function dispatchOrder(Request $request): RedirectResponse
    {
        $request->validate([
            'order_id' => 'required',
            'governorate_code' => 'required|integer',
            'weight' => 'required|numeric|min:0.001',
        ]);

        if (!$this->config->isReadyForOutbound()) {
            ToastMagic::error(translate('please_provide_the_delivery_syria_secret_before_enabling'));

            return back();
        }

        $order = Order::find($request['order_id']);
        if ($order === null) {
            ToastMagic::error(translate('order_not_found'));

            return back();
        }

        $invoiceNo = (string) $order->id;

        // Idempotency: a parcel already created for this order must not be re-shipped.
        $existing = DeliverySyriaParcel::where('invoice_no', $invoiceNo)->first();
        if ($existing !== null && !empty($existing->parcel_id)) {
            ToastMagic::error(translate('this_order_has_already_been_dispatched_to_delivery_syria'));

            return back();
        }

        $rate = DeliverySyriaGovernorateRate::where('code', (int) $request['governorate_code'])->first();
        if ($rate === null) {
            ToastMagic::error(translate('unknown_governorate_sync_rates_first'));

            return back();
        }

        $weight = (float) $request['weight'];
        $estimate = $this->calculator->estimate($weight, (int) $request['governorate_code']) ?? 0.0;
        $payload = $this->buildPayload($order, $rate->governorate, $weight, $invoiceNo, $estimate);

        $response = $this->client->createParcel($payload);

        if (empty($response['success'])) {
            ToastMagic::error(translate('delivery_syria_dispatch_failed') . ': ' . ($response['message'] ?? ''));

            return back();
        }

        $trackingNumber = $response['tracking_number'] ?? null;
        $walletStatus = $response['wallet_status'] ?? null;
        $calculatedCharge = $response['calculation']['calculated_delivery_charge'] ?? null;

        // Record the courier parcel in the integration's ledger.
        DeliverySyriaParcel::updateOrCreate(
            ['invoice_no' => $invoiceNo],
            [
                'order_id' => $order->id,
                'parcel_id' => $response['parcel_id'] ?? null,
                'tracking_number' => $trackingNumber,
                'wallet_status' => $walletStatus,
                'delivery_charge' => $calculatedCharge,
                'company_type' => $response['calculation']['company_type'] ?? null,
                'governorate' => $rate->governorate,
                'weight' => $weight,
            ],
        );

        // Reflect onto the order using the platform's existing third-party-delivery fields. Query-builder
        // update: no model events, so no stock/earnings orchestration is touched.
        Order::where('id', $order->id)->update([
            'delivery_type' => 'third_party_delivery',
            'delivery_service_name' => 'Delivery Syria',
            'third_party_delivery_tracking_id' => $trackingNumber,
            'delivery_man_id' => null,
            'updated_at' => now(),
        ]);

        if ($walletStatus === DeliverySyriaParcel::WALLET_PENDING) {
            ToastMagic::warning(translate('parcel_created_but_courier_wallet_balance_is_insufficient_payment_pending'));
        } else {
            ToastMagic::success(translate('order_dispatched_to_delivery_syria_successfully'));
        }

        return back();
    }

    /**
     * Build the create-parcel body from truthful order data + configured defaults. Values we cannot
     * derive from the order are taken from the admin's saved defaults; none are fabricated. chargeDetails
     * carries the store's own estimate only — the courier recomputes and returns the authoritative
     * charge, so this is non-binding metadata.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(Order $order, string $governorate, float $weight, string $invoiceNo, float $estimate): array
    {
        $address = $order->shipping_address_data;
        $isCod = $order->payment_status !== 'paid';
        $codAmount = $isCod ? (float) $order->order_amount : 0.0;

        return [
            'hub_id' => $this->config->get('hub_id'),
            'delivery_type_id' => $this->config->get('delivery_type_id', '1'),
            'packaging_id' => $this->config->get('packaging_id', '1'),
            'weight' => (string) $weight,
            'pickup_phone' => $this->config->get('pickup_phone'),
            'pickup_address' => $this->config->get('pickup_address'),
            'pickup_lat' => $this->config->get('pickup_lat'),
            'pickup_long' => $this->config->get('pickup_long'),
            'invoice_no' => $invoiceNo,
            'cash_collection' => (string) $codAmount,
            'cod_amount2' => (string) $codAmount,
            'selling_price' => (string) (float) $order->order_amount,
            'customer_name' => (string) ($address->contact_person_name ?? ''),
            'customer_address' => trim((string) ($address->address ?? '') . ' ' . (string) ($address->city ?? '')),
            'lat' => (string) ($address->latitude ?? ''),
            'long' => (string) ($address->longitude ?? ''),
            'customer_phone' => (string) ($address->phone ?? ''),
            'note' => (string) ($order->order_note ?? ''),
            'Governorate' => $governorate,
            'chargeDetails' => json_encode([
                'deliveryChargeAmount' => number_format($estimate, 2, '.', ''),
                'totalDeliveryChargeAmount' => number_format($estimate, 2, '.', ''),
                'currentPayable' => number_format($estimate, 2, '.', ''),
                'VatAmount' => '0.00',
                'vatTex' => 0,
                'codChargeAmount' => '0.00',
                'packagingAmount' => '0.00',
                'liquidFragileAmount' => '0.00',
            ]),
        ];
    }
}
