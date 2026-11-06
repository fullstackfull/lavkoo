<?php

namespace App\Services\Fulfillment\Verification;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Providers\TorodPoint\TorodFulfillmentClient;
use App\Services\Fulfillment\Providers\TorodPoint\TorodLastmileClient;
use App\Services\Fulfillment\Support\FieldMap;
use App\Services\Fulfillment\Support\HttpResult;

/**
 * The read-only questions that settle Torod Point's field maps and the one open question behind automatic inventory: which field of the
 * warehouse product list means "available to sell". The product list (page 1 and page 2) shows every numeric field that looks like
 * stock with its range, and, for SKUs of products the warehouse has physically counted, their values side by side.
 *
 * Creating a warehouse order, pushing products and cancelling a package are deliberately not here. Every call below is a GET.
 */
final class TorodChecks
{
    private const PAGE_SIZE = 50;

    private const STOCK_NAME = '/(stock|qty|quantit|available|onhand|reserved|inventory|balance)/i';

    private const KEYS_THAT_NAME_A_PRODUCT = ['sku', 'code', 'barcode', 'externalId', 'id'];

    public function __construct(
        private readonly TorodFulfillmentClient $fulfillment,
        private readonly TorodLastmileClient $lastmile,
    ) {
    }

    /** @return list<VerificationCheck> */
    public function checks(): array
    {
        return [
            new VerificationCheck(
                id: 'T-1',
                label: 'verification_check_torod_products',
                operation: 'fulfillment_product_list',
                request: ['method' => 'GET', 'path' => '/public/fulfillment/product', 'query' => ['page' => 1, 'pageSize' => self::PAGE_SIZE]],
                call: fn (CheckContext $context): HttpResult => $this->fulfillment->listProducts(1, self::PAGE_SIZE),
                analyse: function (HttpResult $result, CheckContext $context): array {
                    $context->remember('torod_page_1', $this->items($result));

                    return [];
                },
            ),
            new VerificationCheck(
                id: 'T-2',
                label: 'verification_check_torod_products_page_2',
                operation: 'fulfillment_product_list',
                request: ['method' => 'GET', 'path' => '/public/fulfillment/product', 'query' => ['page' => 2, 'pageSize' => self::PAGE_SIZE]],
                call: fn (CheckContext $context): HttpResult => $this->fulfillment->listProducts(2, self::PAGE_SIZE),
                analyse: fn (HttpResult $result, CheckContext $context): array => $this->secondPage($this->items($result), (array) $context->recall('torod_page_1', []), $context),
            ),
            new VerificationCheck(
                id: 'T-3',
                label: 'verification_check_torod_categories',
                operation: 'fulfillment_product_categories',
                request: ['method' => 'GET', 'path' => '/public/fulfillment/product/category', 'query' => ['page' => 1, 'pageSize' => 50]],
                call: fn (CheckContext $context): HttpResult => $this->fulfillment->categories(1, 50),
            ),
            new VerificationCheck(
                id: 'T-4',
                label: 'verification_check_torod_orders',
                operation: 'fulfillment_order_list',
                request: ['method' => 'GET', 'path' => '/public/fulfillment/order', 'query' => ['page' => 1, 'pageSize' => 20]],
                call: fn (CheckContext $context): HttpResult => $this->fulfillment->listOrders(1, 20),
                strict: true,
                analyse: fn (HttpResult $result): array => ['warehouse_order_barcodes' => $this->barcodeShape($this->items($result))],
            ),
            new VerificationCheck(
                id: 'T-5',
                label: 'verification_check_torod_cities',
                operation: 'lastmile_cities',
                request: ['method' => 'GET', 'path' => '/addresses/cities', 'query' => ['returnAll' => 'true']],
                call: fn (CheckContext $context): HttpResult => $this->lastmile->cities(),
            ),
            new VerificationCheck(
                id: 'T-6',
                label: 'verification_check_torod_villages',
                operation: 'fulfillment_villages',
                request: ['method' => 'GET', 'path' => '/addresses/villages', 'query' => ['search' => 'Riyadh']],
                call: fn (CheckContext $context): HttpResult => $this->fulfillment->villages('Riyadh'),
            ),
            new VerificationCheck(
                id: 'T-7',
                label: 'verification_check_torod_package_status',
                operation: 'lastmile_package_status',
                request: ['method' => 'GET', 'path' => '/guests/packages/status', 'query' => ['barcode' => '{the newest package of ours}']],
                call: function (CheckContext $context): ?HttpResult {
                    $barcode = ProviderFulfillment::where('provider', ProviderCode::TorodPoint->value)->whereNotNull('provider_secondary_reference')->latest('id')->value('provider_secondary_reference');
                    if ($barcode === null) {
                        $context->cannotRunBecause('No package of ours exists yet (a warehouse order creates its package later), so there is nothing to ask about.');

                        return null;
                    }

                    return $this->lastmile->packageStatus((string) $barcode);
                },
                strict: true,
                expectsList: false,
            ),
        ];
    }

    /** @return list<array<array-key, mixed>> */
    private function items(HttpResult $result): array
    {
        return $result->json === null ? [] : (FieldMap::extractItems($result->json) ?? []);
    }

    /**
     * Whether page 2 really is the next page, and the stock comparison for the SKUs the person named.
     *
     * @param list<array<array-key, mixed>> $second
     * @param list<array<array-key, mixed>> $first
     * @return array<string, mixed>
     */
    private function secondPage(array $second, array $first, CheckContext $context): array
    {
        $ids = static fn (array $items): array => array_values(array_filter(array_map(static fn (array $item): mixed => $item['id'] ?? null, $items), 'is_scalar'));
        $overlap = count(array_intersect($ids($first), $ids($second)));
        $findings = [
            'page_2_records' => count($second),
            'records_also_on_page_1' => $overlap,
            'paging' => $second === [] ? 'page 2 is empty: the list fits on one page, or the page parameter is ignored' : ($overlap > 0 ? 'page 2 repeats records of page 1: the page parameter is not what we assume' : 'page 2 holds different records: paging works as assumed'),
        ];

        $skus = $context->skus();

        return $skus === [] ? $findings : $findings + ['stock_comparison' => $this->compare([...$first, ...$second], $skus)];
    }

    /**
     * The numeric stock-like fields of the products whose SKU the person named, side by side, to be compared with a physical count.
     *
     * @param list<array<array-key, mixed>> $items
     * @param list<string> $skus
     * @return list<array<string, mixed>>
     */
    private function compare(array $items, array $skus): array
    {
        $rows = [];
        foreach ($skus as $sku) {
            $match = null;
            foreach ($items as $item) {
                foreach (self::KEYS_THAT_NAME_A_PRODUCT as $key) {
                    if (isset($item[$key]) && is_scalar($item[$key]) && (string) $item[$key] === $sku) {
                        $match = $item;

                        break 2;
                    }
                }
            }

            $rows[] = $match === null
                ? ['sku' => $sku, 'found' => false, 'note' => 'not on the two pages examined']
                : ['sku' => $sku, 'found' => true, 'numeric_fields' => array_filter($match, static fn (mixed $value, int|string $key): bool => is_numeric($value) && preg_match(self::STOCK_NAME, (string) $key) === 1, ARRAY_FILTER_USE_BOTH)];
        }

        return $rows;
    }

    /**
     * What the warehouse order's reference looks like: our documents say the barcode starts with "O" and the order carries our invoice number.
     *
     * @param list<array<array-key, mixed>> $items
     * @return array<string, int>
     */
    private function barcodeShape(array $items): array
    {
        $barcodes = array_values(array_filter(array_column($items, 'barcode'), 'is_string'));

        return [
            'orders' => count($items),
            'with_barcode' => count($barcodes),
            'barcode_starts_with_O' => count(array_filter($barcodes, static fn (string $barcode): bool => str_starts_with($barcode, 'O'))),
            'with_invoice_number' => count(array_filter($items, static fn (array $item): bool => isset($item['invoiceNumber']) && $item['invoiceNumber'] !== '')),
        ];
    }
}
