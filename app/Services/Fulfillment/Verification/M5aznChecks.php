<?php

namespace App\Services\Fulfillment\Verification;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\Providers\M5azn\M5aznClient;
use App\Services\Fulfillment\Support\FieldMap;
use App\Services\Fulfillment\Support\HttpResult;

/**
 * The read-only questions that settle M5AZN's field maps: the catalogue and the seller's own list (products), the stock-change
 * feed (inventory), the order list and one order's detail (orders and the status vocabulary), and the categories.
 *
 * Creating an order is deliberately not here. Every call below is a GET, or the login that only returns a token.
 */
final class M5aznChecks
{
    private const ID_KEYS = ['id', 'productId', 'product_id', 'orderId', 'order_id', 'reference', 'order_number'];

    public function __construct(
        private readonly M5aznClient $client,
        private readonly ProviderConnectionManager $connections,
    ) {
    }

    /** @return list<VerificationCheck> */
    public function checks(): array
    {
        $productType = $this->connections->config(ProviderCode::M5azn)->string('catalog_product_type', 'local');

        return [
            new VerificationCheck(
                id: 'M-1',
                label: 'verification_check_m5azn_login',
                operation: 'login',
                request: ['method' => 'POST', 'path' => '/api/v1/auth/login'],
                call: fn (CheckContext $context): HttpResult => $this->client->login(),
                strict: true,
                expectsList: false,
                analyse: static fn (HttpResult $result): array => is_string($result->get('data.accessToken')) && $result->get('data.accessToken') !== ''
                    ? ['token_issued' => true]
                    : ['token_issued' => false, '_verdict' => ['status' => CheckResult::FAIL, 'summary' => 'The login was answered but carried no access token (data.accessToken).']],
            ),
            new VerificationCheck(
                id: 'M-2',
                label: 'verification_check_m5azn_catalog',
                operation: 'catalog_list',
                request: ['method' => 'GET', 'path' => '/api/v1/products'],
                call: fn (CheckContext $context): HttpResult => $this->client->catalog(),
                analyse: fn (HttpResult $result, CheckContext $context): array => $this->rememberFirst($result, $context, 'product_id'),
            ),
            new VerificationCheck(
                id: 'M-3',
                label: 'verification_check_m5azn_my_products',
                operation: 'my_products',
                request: ['method' => 'GET', 'path' => '/api/v1/seller/myproductlist', 'query' => ['product_type' => $productType]],
                call: fn (CheckContext $context): HttpResult => $this->client->myProducts(['product_type' => $productType]),
                analyse: fn (HttpResult $result, CheckContext $context): array => $this->rememberFirst($result, $context, 'selected_product_id'),
            ),
            new VerificationCheck(
                id: 'M-4',
                label: 'verification_check_m5azn_product_details',
                operation: 'product_details',
                request: ['method' => 'GET', 'path' => '/api/v1/product/details/{id}'],
                call: function (CheckContext $context): ?HttpResult {
                    $id = $context->recall('selected_product_id') ?? $context->recall('product_id');
                    if ($id === null) {
                        $context->cannotRunBecause('No product id was found in the catalogue or the seller list to ask about.');

                        return null;
                    }

                    return $this->client->productDetails((string) $id);
                },
                expectsList: false,
            ),
            new VerificationCheck(
                id: 'M-5',
                label: 'verification_check_m5azn_inventory',
                operation: 'qty_changed',
                request: ['method' => 'GET', 'path' => '/api/v1/products/qty-changed', 'query' => ['days' => 1]],
                call: fn (CheckContext $context): HttpResult => $this->client->quantityChanges(days: 1),
            ),
            new VerificationCheck(
                id: 'M-6',
                label: 'verification_check_m5azn_categories',
                operation: 'categories',
                request: ['method' => 'GET', 'path' => '/api/v1/catalog/categories'],
                call: fn (CheckContext $context): HttpResult => $this->client->categories(),
            ),
            new VerificationCheck(
                id: 'M-7',
                label: 'verification_check_m5azn_orders',
                operation: 'order_list',
                request: ['method' => 'GET', 'path' => '/api/v1/orders'],
                call: fn (CheckContext $context): HttpResult => $this->client->orders(),
                strict: true,
                analyse: fn (HttpResult $result, CheckContext $context): array => $this->rememberFirst($result, $context, 'order_id'),
            ),
            new VerificationCheck(
                id: 'M-8',
                label: 'verification_check_m5azn_order_details',
                operation: 'order_details',
                request: ['method' => 'GET', 'path' => '/api/orders/{id}'],
                call: function (CheckContext $context): ?HttpResult {
                    $id = $context->recall('order_id');
                    if ($id === null) {
                        $context->cannotRunBecause('The seller has no order yet, so there is no order whose detail can be read.');

                        return null;
                    }

                    return $this->client->orderDetails((string) $id);
                },
                strict: true,
                expectsList: false,
            ),
        ];
    }

    /**
     * Remembers the id of the first record, for the check that asks about one record. The id is used for the next call only; it is
     * not written to the evidence.
     *
     * @return array<string, mixed>
     */
    private function rememberFirst(HttpResult $result, CheckContext $context, string $key): array
    {
        $first = $result->json === null ? null : (FieldMap::extractItems($result->json)[0] ?? null);
        foreach (self::ID_KEYS as $candidate) {
            if (is_array($first) && isset($first[$candidate]) && is_scalar($first[$candidate])) {
                $context->remember($key, $first[$candidate]);

                break;
            }
        }

        return [];
    }
}
