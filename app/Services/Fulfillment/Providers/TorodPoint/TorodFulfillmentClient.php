<?php

namespace App\Services\Fulfillment\Providers\TorodPoint;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\Support\HttpResult;
use App\Services\Fulfillment\Support\ProviderConfig;
use App\Services\Fulfillment\Support\ProviderHttp;

/**
 * Torod Point / LogesTechs **Fulfillment API v5** — the warehouse (WMS) side. Authenticated by three
 * request headers (`company-id`, `username`, `password`), unlike the Lastmile API.
 *
 *   POST /public/fulfillment/product/bulk      add or update warehouse products (keyed by our externalId)
 *   GET  /public/fulfillment/product           ?page &pageSize
 *   GET  /public/fulfillment/product/category  ?page &pageSize &search
 *   POST /public/fulfillment/order             create a warehouse order (picked, packed AND shipped by them)
 *   GET  /public/fulfillment/order             ?page &pageSize
 *   GET  /addresses/villages                   ?search
 *
 * The document specifies no response schema for the list endpoints or for order creation; callers read
 * the raw JSON through verified field maps.
 */
class TorodFulfillmentClient
{
    public function __construct(
        private readonly ProviderHttp $http,
        private readonly ProviderConnectionManager $connections,
    ) {
    }

    public function listProducts(int $page = 1, int $pageSize = 100): HttpResult
    {
        return $this->send('product_list', 'GET', '/public/fulfillment/product', query: ['page' => $page, 'pageSize' => $pageSize]);
    }

    /** @param list<array<string, mixed>> $list */
    public function upsertProducts(array $list): HttpResult
    {
        return $this->send('product_upsert', 'POST', '/public/fulfillment/product/bulk', json: ['list' => $list]);
    }

    public function categories(int $page = 1, int $pageSize = 50, ?string $search = null): HttpResult
    {
        return $this->send('product_categories', 'GET', '/public/fulfillment/product/category', query: array_filter(
            ['page' => $page, 'pageSize' => $pageSize, 'search' => $search],
            static fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    /** @param array<string, mixed> $payload */
    public function createOrder(array $payload, ?int $fulfillmentId = null): HttpResult
    {
        return $this->send('order_create', 'POST', '/public/fulfillment/order', json: $payload, fulfillmentId: $fulfillmentId);
    }

    public function listOrders(int $page = 1, int $pageSize = 50): HttpResult
    {
        return $this->send('order_list', 'GET', '/public/fulfillment/order', query: ['page' => $page, 'pageSize' => $pageSize]);
    }

    public function villages(string $search = ''): HttpResult
    {
        return $this->send('villages', 'GET', '/addresses/villages', query: ['search' => $search]);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $query
     */
    private function send(string $operation, string $method, string $path, ?array $json = null, array $query = [], ?int $fulfillmentId = null): HttpResult
    {
        $config = $this->connections->config(ProviderCode::TorodPoint);

        return $this->http->send(
            provider: ProviderCode::TorodPoint,
            operation: 'fulfillment_' . $operation,
            method: $method,
            url: $config->baseUrl . $path,
            headers: $this->headers($config),
            json: $json,
            query: $query,
            fulfillmentId: $fulfillmentId,
        );
    }

    /** @return array<string, string> */
    private function headers(ProviderConfig $config): array
    {
        return [
            'company-id' => $config->string('company_id'),
            'username' => (string) $config->secret('fulfillment_username'),
            'password' => (string) $config->secret('fulfillment_password'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}
