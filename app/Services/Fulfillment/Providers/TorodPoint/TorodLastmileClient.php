<?php

namespace App\Services\Fulfillment\Providers\TorodPoint;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\Support\HttpResult;
use App\Services\Fulfillment\Support\ProviderConfig;
use App\Services\Fulfillment\Support\ProviderHttp;

/**
 * Torod Point / LogesTechs **Lastmile API v2** — used ONLY for what the warehouse API does not cover:
 * the city list, package status, package cancel, and the air waybill.
 *
 * Its documented "create shipment" call (`POST /ship/request/by-email`) is deliberately absent. A warehouse
 * order already produces its own last-mile package (the `packageBarcode` the Fulfillment webhook reports);
 * creating a shipment here as well would ship the parcel twice.
 *
 *   GET  /addresses/cities?returnAll=true            (company-id header; `search` is the one documented filter, `page` and `pageSize` are
 *                                                      the paging names the same vendor's other list calls use and are only ever tried)
 *   GET  /guests/packages/status?barcode=            (company-id header)
 *   PUT  /guests/{companyId}/packages/{barcode}/cancel   body {email, password}
 *   POST /guests/{companyId}/packages/pdf                body {barcodes: [...]}  → {url}
 */
class TorodLastmileClient
{
    public function __construct(
        private readonly ProviderHttp $http,
        private readonly ProviderConnectionManager $connections,
    ) {
    }

    public function cities(?string $search = null, bool $returnAll = true, ?int $page = null, ?int $pageSize = null): HttpResult
    {
        return $this->send('cities', 'GET', '/addresses/cities', query: array_filter(
            ['returnAll' => $returnAll ? 'true' : null, 'search' => $search, 'page' => $page, 'pageSize' => $pageSize],
            static fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    public function packageStatus(string $barcode): HttpResult
    {
        return $this->send('package_status', 'GET', '/guests/packages/status', query: ['barcode' => $barcode]);
    }

    public function cancelPackage(string $barcode, ?int $fulfillmentId = null): HttpResult
    {
        $config = $this->config();

        return $this->send(
            'package_cancel',
            'PUT',
            '/guests/' . rawurlencode($config->string('company_id')) . '/packages/' . rawurlencode($barcode) . '/cancel',
            json: ['email' => $config->secret('lastmile_email'), 'password' => $config->secret('lastmile_password')],
            fulfillmentId: $fulfillmentId,
        );
    }

    /** @param list<string> $barcodes */
    public function airWaybill(array $barcodes, ?int $fulfillmentId = null): HttpResult
    {
        return $this->send(
            'awb',
            'POST',
            '/guests/' . rawurlencode($this->config()->string('company_id')) . '/packages/pdf',
            json: ['barcodes' => array_values($barcodes)],
            fulfillmentId: $fulfillmentId,
        );
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $query
     */
    private function send(string $operation, string $method, string $path, ?array $json = null, array $query = [], ?int $fulfillmentId = null): HttpResult
    {
        $config = $this->config();

        return $this->http->send(
            provider: ProviderCode::TorodPoint,
            operation: 'lastmile_' . $operation,
            method: $method,
            url: rtrim($config->string('lastmile_base_url'), '/') . $path,
            headers: ['company-id' => $config->string('company_id'), 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
            json: $json,
            query: $query,
            fulfillmentId: $fulfillmentId,
        );
    }

    private function config(): ProviderConfig
    {
        return $this->connections->config(ProviderCode::TorodPoint);
    }
}
