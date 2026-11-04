<?php

namespace App\Http\Controllers\Admin\ThirdParty;

use App\Http\Controllers\BaseController;
use App\Models\DeliverySyriaGovernorateRate;
use App\Services\DeliverySyria\DeliverySyriaClient;
use App\Services\DeliverySyria\DeliverySyriaConfigService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin configuration for the optional Delivery Syria courier integration.
 *
 * Mirrors the third-party convention: a status toggle plus credential inputs stored in a
 * business_settings row, read/written through DeliverySyriaConfigService (which encrypts the secrets).
 * "Verify & Sync" is the connect action — it proves the Secret against the courier and caches the
 * governorate price list. The secrets are never echoed back to the browser; a blank secret field on
 * save means "keep the current one".
 */
class DeliverySyriaController extends BaseController
{
    public function __construct(
        private readonly DeliverySyriaConfigService $config,
        private readonly DeliverySyriaClient $client,
    ) {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $data = [
            'enabled' => $this->config->isEnabled(),
            'hasSecret' => $this->config->secretToken() !== '',
            'hasWebhookToken' => $this->config->webhookToken() !== '',
            'baseUrl' => $this->config->baseUrl(),
            'platform' => $this->config->platform(),
            'hubId' => $this->config->get('hub_id'),
            'deliveryTypeId' => $this->config->get('delivery_type_id', '1'),
            'packagingId' => $this->config->get('packaging_id', '1'),
            'pickupPhone' => $this->config->get('pickup_phone'),
            'pickupAddress' => $this->config->get('pickup_address'),
            'pickupLat' => $this->config->get('pickup_lat'),
            'pickupLong' => $this->config->get('pickup_long'),
            'inboundPlatform' => DeliverySyriaConfigService::INBOUND_PLATFORM,
            'webhookUrl' => url('/api/delivery-syria/orders/update-status'),
            'rates' => DeliverySyriaGovernorateRate::orderBy('governorate')->get(),
        ];

        return view('admin-views.third-party.delivery-syria-index', $data);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'status' => 'nullable|in:0,1',
            'secret_token' => 'nullable|string|max:500',
            'webhook_token' => 'nullable|string|max:500',
            'base_url' => 'nullable|url|max:255',
            'platform' => 'nullable|string|max:100',
            'hub_id' => 'nullable|string|max:100',
            'delivery_type_id' => 'nullable|string|max:100',
            'packaging_id' => 'nullable|string|max:100',
            'pickup_phone' => 'nullable|string|max:100',
            'pickup_address' => 'nullable|string|max:255',
            'pickup_lat' => 'nullable|string|max:100',
            'pickup_long' => 'nullable|string|max:100',
        ]);

        $status = (int) ($request['status'] ?? 0);

        // Cannot enable without a Secret — either supplied now or already stored.
        if ($status === 1 && empty($request['secret_token']) && $this->config->secretToken() === '') {
            ToastMagic::error(translate('please_provide_the_delivery_syria_secret_before_enabling'));

            return back();
        }

        $this->config->save([
            'status' => $status,
            'secret_token' => $request['secret_token'] ?? null,
            'webhook_token' => $request['webhook_token'] ?? null,
            'base_url' => $request['base_url'] ?? DeliverySyriaConfigService::DEFAULT_BASE_URL,
            'platform' => $request['platform'] ?? DeliverySyriaConfigService::DEFAULT_PLATFORM,
            'hub_id' => $request['hub_id'] ?? '',
            'delivery_type_id' => $request['delivery_type_id'] ?? '1',
            'packaging_id' => $request['packaging_id'] ?? '1',
            'pickup_phone' => $request['pickup_phone'] ?? '',
            'pickup_address' => $request['pickup_address'] ?? '',
            'pickup_lat' => $request['pickup_lat'] ?? '',
            'pickup_long' => $request['pickup_long'] ?? '',
        ]);

        ToastMagic::success(translate('updated_successfully'));

        return back();
    }

    /** Verify the Secret against the courier and sync the governorate price list. */
    public function verifySync(): RedirectResponse
    {
        if (!$this->config->isEnabled() || $this->config->secretToken() === '') {
            ToastMagic::error(translate('please_provide_the_delivery_syria_secret_before_enabling'));

            return back();
        }

        $result = $this->client->verifyAndSyncRates();
        $result['success'] ? ToastMagic::success($result['message']) : ToastMagic::error($result['message']);

        return back();
    }
}
