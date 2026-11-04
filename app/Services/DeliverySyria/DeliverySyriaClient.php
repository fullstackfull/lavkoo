<?php

namespace App\Services\DeliverySyria;

use App\Models\DeliverySyriaGovernorateRate;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The outbound half of the Delivery Syria integration: the store calling the courier.
 *
 * Three operations, matching the courier's API:
 *  - verifyAndSyncRates(): confirm the Secret and cache the per-governorate price list (this is what
 *    "the integration connects" means to the admin).
 *  - createParcel(): hand a shipment to the courier and read back parcel_id / tracking_number /
 *    wallet_status.
 *  - listParcels(): read shipments by paid/unpaid visibility.
 *
 * Every method fails soft: it never throws and never puts the Secret into a message or log. When the
 * integration is not enabled/configured, or the network is unavailable, it returns
 * ['success' => false, 'message' => ...] so callers branch on data, not exceptions. The Secret is read
 * from the config service (decrypted) and only ever leaves as an Authorization header / request body
 * over HTTPS — never rendered, never returned.
 */
class DeliverySyriaClient
{
    private const TIMEOUT = 20;

    private const CONNECT_TIMEOUT = 10;

    public function __construct(
        private readonly DeliverySyriaConfigService $config,
    ) {
    }

    /**
     * Verify the Secret against POST /api/secret/cheack and cache the returned governorate price list.
     * On success each governorate is upserted by the courier's `code`, so re-syncing updates prices in
     * place instead of duplicating.
     *
     * @return array{success: bool, message: string, synced?: int}
     */
    public function verifyAndSyncRates(): array
    {
        $secret = $this->config->secretToken();
        if (!$this->config->isEnabled() || $secret === '') {
            return ['success' => false, 'message' => 'Delivery Syria is not enabled or the Secret is not set.'];
        }

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->acceptJson()
                ->post($this->config->baseUrl() . '/api/secret/cheack', ['secret' => $secret]);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Could not reach Delivery Syria: ' . $this->safeError($e)];
        }

        if (!$response->successful()) {
            return ['success' => false, 'message' => 'Verification failed (HTTP ' . $response->status() . '). Check the Secret.'];
        }

        $data = $response->json('data');
        if (!is_array($data)) {
            return ['success' => false, 'message' => 'Unexpected response from Delivery Syria during verification.'];
        }

        $synced = 0;
        foreach ($data as $row) {
            if (!isset($row['code'])) {
                continue;
            }
            DeliverySyriaGovernorateRate::updateOrCreate(
                ['code' => (int) $row['code']],
                [
                    'governorate' => (string) ($row['governorate'] ?? ''),
                    'base_cost' => (float) ($row['base_cost'] ?? 0),
                    'synced_at' => now(),
                ],
            );
            $synced++;
        }

        return ['success' => true, 'message' => 'Verified. Synced ' . $synced . ' governorate rate(s).', 'synced' => $synced];
    }

    /**
     * Create a parcel via POST /api/parcel/create-with-security.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, message: string, parcel_id?: mixed, tracking_number?: mixed, wallet_status?: mixed, calculation?: mixed}
     */
    public function createParcel(array $payload): array
    {
        if (!$this->config->isReadyForOutbound()) {
            return ['success' => false, 'message' => 'Delivery Syria is not enabled or the Secret is not set.'];
        }

        try {
            $response = $this->authorized()->post(
                $this->config->baseUrl() . '/api/parcel/create-with-security',
                $payload,
            );
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Could not reach Delivery Syria: ' . $this->safeError($e)];
        }

        $body = $response->json();
        if (!is_array($body)) {
            return ['success' => false, 'message' => 'Unexpected response from Delivery Syria (HTTP ' . $response->status() . ').'];
        }

        // The courier returns success:false for a duplicate invoice_no and other business errors; pass
        // its own message through so the admin sees exactly why.
        return array_merge(
            ['success' => (bool) ($body['success'] ?? false), 'message' => (string) ($body['message'] ?? '')],
            array_intersect_key($body, array_flip(['parcel_id', 'tracking_number', 'wallet_status', 'calculation'])),
        );
    }

    /**
     * List parcels via GET /api/parcel/list-with-security. visible=0 unpaid/pending, visible=1 paid.
     *
     * @return array{success: bool, message: string, data?: mixed, meta?: mixed}
     */
    public function listParcels(int $visible = 1, int $page = 1): array
    {
        if (!$this->config->isReadyForOutbound()) {
            return ['success' => false, 'message' => 'Delivery Syria is not enabled or the Secret is not set.'];
        }

        try {
            $response = $this->authorized()->get(
                $this->config->baseUrl() . '/api/parcel/list-with-security',
                ['visible' => $visible, 'page' => $page],
            );
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Could not reach Delivery Syria: ' . $this->safeError($e)];
        }

        $body = $response->json();
        if (!is_array($body)) {
            return ['success' => false, 'message' => 'Unexpected response from Delivery Syria (HTTP ' . $response->status() . ').'];
        }

        return array_merge(
            ['success' => (bool) ($body['success'] ?? false), 'message' => (string) ($body['message'] ?? '')],
            array_intersect_key($body, array_flip(['data', 'meta', 'links'])),
        );
    }

    /** A pending request carrying the Secret and platform headers the parcel endpoints require. */
    private function authorized(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(self::TIMEOUT)
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->withHeaders([
                'X-Platform' => $this->config->platform(),
                'Authorization' => 'Bearer ' . $this->config->secretToken(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]);
    }

    /** An exception message with anything secret-looking stripped, safe to surface/log. */
    private function safeError(Throwable $e): string
    {
        $message = $e->getMessage();
        $secret = $this->config->secretToken();
        if ($secret !== '') {
            $message = str_replace($secret, '***', $message);
        }

        return mb_substr($message, 0, 180);
    }
}
