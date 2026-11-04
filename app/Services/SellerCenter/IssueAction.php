<?php

namespace App\Services\SellerCenter;

/**
 * Where an issue's primary action goes, and what its button says.
 *
 * The core loop of the product is: the server detects a problem, the card states it with a count,
 * and one button lands the seller on **a pre-filtered list whose count equals the number in the
 * card** (handoff 09 §1). A mismatch there is the most damaging bug this product can have — a
 * seller told "8 products rejected" who lands on a list of 126 stops believing the number.
 *
 * So the mapping lives in one place, keyed by the server's own `action_key`, and it carries the
 * issue's `action_params` into the destination's filters rather than sending the seller to an
 * unfiltered screen and hoping.
 */
class IssueAction
{
    /**
     * @param  array<string, mixed>|null  $params
     * @return array{label: string, href: ?string, ids: array<int, int|string>}
     */
    public static function resolve(?string $actionKey, ?array $params = null): array
    {
        $params ??= [];

        return match ($actionKey) {
            'open_orders' => self::listing(
                'seller.orders.index',
                translate('open_order_queue'),
                self::ids($params, 'order_ids'),
                ['ids' => self::idQuery($params, 'order_ids'), 'view' => $params['state'] ?? null],
            ),
            'open_order' => self::detail(
                'seller.orders.show',
                translate('open_order'),
                $params['order_id'] ?? null,
            ),
            'open_products' => self::listing(
                'seller.products.index',
                translate('fix_products'),
                self::ids($params, 'product_ids'),
                ['ids' => self::idQuery($params, 'product_ids'), 'issues' => 'any'],
            ),
            'open_product' => self::detail(
                'seller.products.index',
                translate('fix_product'),
                null,
                ['ids' => (string) ($params['product_id'] ?? '')],
            ),
            'open_returns' => self::listing(
                'seller.returns.index',
                translate('answer_returns'),
                self::ids($params, 'return_ids'),
                ['ids' => self::idQuery($params, 'return_ids')],
            ),
            'open_refund' => self::detail(
                'seller.refunds.index',
                translate('open_refund'),
                null,
                ['ids' => (string) ($params['refund_request_id'] ?? '')],
            ),
            'open_statement' => self::detail('seller.finance.statements', translate('open_statement'), null),
            'open_brand_claims' => self::detail(
                'seller.brands.index',
                translate('open_brand_registry'),
                null,
                array_filter(['brand_id' => $params['brand_id'] ?? null]),
            ),
            default => ['label' => translate('details'), 'href' => null, 'ids' => []],
        };
    }

    /**
     * The ids an issue names, so a destination can narrow to exactly them.
     *
     * @return array<int, int|string>
     */
    public static function ids(array $params, string $key): array
    {
        $ids = $params[$key] ?? [];

        return is_array($ids) ? array_values($ids) : [];
    }

    private static function idQuery(array $params, string $key): ?string
    {
        $ids = self::ids($params, $key);

        return $ids === [] ? null : implode(',', $ids);
    }

    /**
     * A list destination. When the issue names its subjects, the link carries them so the
     * destination's count matches the card's; when it does not, the link carries the view instead.
     */
    private static function listing(string $route, string $label, array $ids, array $query): array
    {
        $query = array_filter($query, static fn ($value) => $value !== null && $value !== '');

        return [
            'label' => $label,
            'href' => Shell::route($route, $query),
            'ids' => $ids,
        ];
    }

    private static function detail(string $route, string $label, int|string|null $id, array $query = []): array
    {
        if ($id !== null) {
            return ['label' => $label, 'href' => Shell::route($route, $id), 'ids' => [$id]];
        }

        $query = array_filter($query, static fn ($value) => $value !== null && $value !== '');

        return ['label' => $label, 'href' => Shell::route($route, $query), 'ids' => []];
    }
}
