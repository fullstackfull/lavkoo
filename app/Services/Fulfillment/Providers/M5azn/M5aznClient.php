<?php

namespace App\Services\Fulfillment\Providers\M5azn;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\Support\HttpResult;
use App\Services\Fulfillment\Support\ProviderConfig;
use App\Services\Fulfillment\Support\ProviderHttp;

/**
 * The nine operations M5AZN's official API documentation describes, and nothing else.
 *
 *   POST /api/v1/auth/login                    {email, password} → data.accessToken
 *   GET  /api/v1/products                      the M5AZN catalog
 *   GET  /api/v1/seller/myproductlist          the products THIS seller account has selected
 *   GET  /api/v1/product/details/{id}
 *   GET  /api/v1/products/qty-changed          ?days=1-7 &hours=1-24
 *   GET  /api/v1/catalog/categories
 *   POST /api/v1/orders                        create order
 *   GET  /api/v1/orders
 *   GET  /api/orders/{id}                      (documented without the /v1 segment)
 *
 * Every call carries the documented headers (X-Lang, X-Website, X-Api, X-Device, X-Version) and a Bearer
 * token obtained by logging in with the seller account. The token is stored encrypted and reused until it
 * is close to expiry; a 401 triggers exactly one re-login and one retry (safe even for a write: a 401 means
 * the request was refused before it was processed).
 *
 * The documentation gives no response schema for most of these, so this class returns raw decoded JSON and
 * leaves interpretation to field maps an administrator verifies.
 */
class M5aznClient
{
    private const LOGIN_PATH = '/api/v1/auth/login';
    private const TOKEN_REFRESH_MARGIN_SECONDS = 86400;

    public function __construct(
        private readonly ProviderHttp $http,
        private readonly ProviderConnectionManager $connections,
    ) {
    }

    public function login(): HttpResult
    {
        $config = $this->config();

        return $this->http->send(
            provider: ProviderCode::M5azn,
            operation: 'login',
            method: 'POST',
            url: $config->baseUrl . self::LOGIN_PATH,
            headers: $this->baseHeaders($config),
            json: ['email' => $config->secret('email'), 'password' => $config->secret('password')],
        );
    }

    /** @param array<string, mixed> $query */
    public function catalog(array $query = []): HttpResult
    {
        return $this->authenticated('catalog_list', 'GET', '/api/v1/products', query: $query);
    }

    /** @param array<string, mixed> $query */
    public function myProducts(array $query = []): HttpResult
    {
        return $this->authenticated('my_products', 'GET', '/api/v1/seller/myproductlist', query: $query);
    }

    public function productDetails(string $productId): HttpResult
    {
        return $this->authenticated('product_details', 'GET', '/api/v1/product/details/' . rawurlencode($productId));
    }

    public function quantityChanges(?int $days = null, ?int $hours = null): HttpResult
    {
        return $this->authenticated('qty_changed', 'GET', '/api/v1/products/qty-changed', query: array_filter(
            ['days' => $days, 'hours' => $hours],
            static fn ($value): bool => $value !== null,
        ));
    }

    public function categories(): HttpResult
    {
        return $this->authenticated('categories', 'GET', '/api/v1/catalog/categories');
    }

    /** @param array<string, mixed> $payload the documented create-order body */
    public function createOrder(array $payload, ?int $fulfillmentId = null): HttpResult
    {
        return $this->authenticated('create_order', 'POST', '/api/v1/orders', json: $payload, fulfillmentId: $fulfillmentId);
    }

    /** @param array<string, mixed> $query */
    public function orders(array $query = []): HttpResult
    {
        return $this->authenticated('order_list', 'GET', '/api/v1/orders', query: $query);
    }

    public function orderDetails(string $orderId): HttpResult
    {
        return $this->authenticated('order_details', 'GET', '/api/orders/' . rawurlencode($orderId));
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $query
     */
    private function authenticated(string $operation, string $method, string $path, ?array $json = null, array $query = [], ?int $fulfillmentId = null): HttpResult
    {
        $result = $this->attempt($operation, $method, $path, $json, $query, $fulfillmentId);

        // 401 = refused before processing, so one re-login and one retry cannot duplicate anything.
        if ($result->kind === HttpResult::AUTH_ERROR && $this->refreshToken() !== null) {
            $result = $this->attempt($operation, $method, $path, $json, $query, $fulfillmentId);
        }

        return $result;
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $query
     */
    private function attempt(string $operation, string $method, string $path, ?array $json, array $query, ?int $fulfillmentId): HttpResult
    {
        $config = $this->config();
        $token = $this->validToken($config) ?? $this->refreshToken();
        if ($token === null) {
            return new HttpResult(HttpResult::AUTH_ERROR, null, null, '', 'M5AZN login did not return an access token.', 0, '');
        }

        return $this->http->send(
            provider: ProviderCode::M5azn,
            operation: $operation,
            method: $method,
            url: $config->baseUrl . $path,
            headers: $this->baseHeaders($config) + ['Authorization' => 'Bearer ' . $token],
            json: $json,
            query: $query,
            fulfillmentId: $fulfillmentId,
        );
    }

    /** Logs in and stores the token; returns it, or null when M5AZN did not issue one. */
    public function refreshToken(): ?string
    {
        $result = $this->login();
        $token = $result->get('data.accessToken');
        if (!$result->ok() || !is_string($token) || $token === '') {
            return null;
        }

        $this->connections->putRuntimeSecret(ProviderCode::M5azn, 'access_token', $token);
        $expiresAt = $this->tokenExpiry($token);
        $this->connections->putRuntimeSecret(ProviderCode::M5azn, 'token_expires_at', $expiresAt === null ? null : (string) $expiresAt);

        return $token;
    }

    private function validToken(ProviderConfig $config): ?string
    {
        $token = $config->secret('access_token');
        if ($token === null) {
            return null;
        }

        $expires = $config->secret('token_expires_at');
        if ($expires !== null && is_numeric($expires) && (int) $expires - time() < self::TOKEN_REFRESH_MARGIN_SECONDS) {
            return null;
        }

        return $token;
    }

    /** The `exp` claim of the JWT, read without verifying it (we only need to know when to log in again). */
    private function tokenExpiry(string $token): ?int
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            return null;
        }

        $claims = json_decode((string) base64_decode(strtr($segments[1], '-_', '+/'), false), true);

        return is_array($claims) && isset($claims['exp']) && is_numeric($claims['exp']) ? (int) $claims['exp'] : null;
    }

    /** @return array<string, string> */
    private function baseHeaders(ProviderConfig $config): array
    {
        return [
            'X-Lang' => $config->string('language', 'en'),
            'X-Website' => 'M5AZN',
            'X-Api' => $config->string('api_version', '1.0'),
            'X-Device' => $config->string('device', 'Android'),
            'X-Version' => $config->string('app_version', '1.0'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    private function config(): ProviderConfig
    {
        return $this->connections->config(ProviderCode::M5azn);
    }
}
