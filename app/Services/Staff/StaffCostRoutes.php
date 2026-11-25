<?php

namespace App\Services\Staff;

use App\Http\Controllers\Admin\OperationalCosts\OverviewController;
use App\Http\Controllers\Admin\OperationalCosts\ProductCostController;
use App\Http\Controllers\Admin\OperationalCosts\ProfitabilityController;
use App\Http\Controllers\Admin\OperationalCosts\ReturnController;
use App\Services\OperationalCosts\Sales\SaleDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The OPERATIONAL COST screens of slice 6 that a staff country scope covers (GCC-14c; G-08, G-09): what a unit cost us, what a sale made,
 * and what a channel return was decided to be. The integrations are the other half of the slice and are `StaffIntegrationRoutes`; one
 * middleware reads both, because two would each refuse a route the other holds the rule for.
 *
 * What carries the market on each screen:
 *
 *  - a COST LAYER is the purchase price of one product in one warehouse (`inventory_cost_layers.product_id`), so it follows the product --
 *    the mirror of a channel listing, which is the SALE price of one, and the reason the integrations and the costs are one slice;
 *  - RECORDING a cost names both the product and the warehouse, and both are checked: a Kuwait employee writing a purchase cost onto a Saudi
 *    product changes the margin the Saudi store sells on, and writing it into a Saudi warehouse puts stock on a shelf they do not work with;
 *  - a RETURN DECISION is about a line of a channel order (`external_order_lines`), so it follows that order's channel account, which is the
 *    key such a line always has -- its `product_id` is null until the line is matched to one of ours;
 *  - a PROFIT page of one sale names an order of ours or a channel order, and is judged by whichever it names.
 *
 * Two screens are settled by a REFUSAL (`unavailable`), and each is a fact about the figures rather than a preference:
 *
 *  - the COST OVERVIEW adds up the profit of every order of every market. There is no market dimension to narrow -- the rollup has none
 *    (coverage-gaps records that for the analytics too) -- so an employee of one market would be shown a platform total labelled as theirs;
 *  - the PROFIT LIST and its export are the same figures a row at a time, read by `SaleDocumentReader` with the query builder, so no scope of
 *    a screen reaches them. Narrowing the page while the export of the same page hands over every market is the shape G-10 exists to stop,
 *    so both are refused together until the rollup has a market.
 */
final class StaffCostRoutes
{
    /** @var array<class-string, array<string, list<string>>> */
    private const RULES = [
        OverviewController::class => [
            'index' => ['unavailable'],
        ],
        ProductCostController::class => [
            'index' => ['list'],
            'show' => ['cost_layer.route:layer'],
            'store' => ['product.input:product_id', 'location.input:location_id'],
            'productSearch' => ['list'],
        ],
        ProfitabilityController::class => [
            'index' => ['unavailable'],
            'export' => ['unavailable'],
            'order' => ['document.route:type,id'],
        ],
        ReturnController::class => [
            'index' => ['list'],
            'decide' => ['return_line.route:line', 'location.input:location_id?'],
        ],
    ];

    /** @return list<class-string> the controllers whose actions a staff country scope covers among the operational costs of slice 6 */
    public static function controllers(): array
    {
        return array_keys(self::RULES);
    }

    /** @return array<string, list<string>> */
    public static function rulesOf(string $controller): array
    {
        return self::RULES[ltrim($controller, '\\')] ?? [];
    }

    /** @return list<string>|null */
    public static function ruleOf(string $controller, string $method): ?array
    {
        return self::rulesOf(controller: $controller)[$method] ?? null;
    }

    /**
     * The parts the cost screens add to the integration vocabulary. Everything else is `StaffIntegrationRoutes::claimOf()`'s, so there is
     * one place that reads a rule and one that reads an id.
     *
     * @return array{products?: list<int>, orders?: list<int>, locations?: list<int>, accounts?: list<int>}|null
     */
    public static function claimOf(Request $request, string $part): ?array
    {
        [$subject, $where] = array_pad(explode('.', $part, 2), 2, '');

        if ($subject === 'cost_layer' || $subject === 'return_line') {
            $id = StaffRouteIds::one(value: $request->route(substr($where, strlen('route:'))));

            if ($id === null) {
                return null;
            }

            return $subject === 'cost_layer'
                ? self::productOfACostLayer(layerId: $id[0])
                : self::accountOfAReturnLine(lineId: $id[0]);
        }

        if ($subject === 'document') {
            return self::marketOfASaleDocument(kind: (string) $request->route('type'), id: $request->route('id'));
        }

        return StaffIntegrationRoutes::claimOf(request: $request, part: $part);
    }

    /** @return array{products: list<int>}|null */
    private static function productOfACostLayer(int $layerId): ?array
    {
        $productId = DB::table('inventory_cost_layers')->where('id', $layerId)->value('product_id');

        return $productId === null ? null : ['products' => [(int) $productId]];
    }

    /**
     * The channel account of the order a return line belongs to. A line whose mapping is gone belongs to no order and so to no market.
     *
     * @return array{accounts: list<int>}|null
     */
    private static function accountOfAReturnLine(int $lineId): ?array
    {
        $accountId = DB::table('external_order_lines')
            ->join('external_order_mappings', 'external_order_mappings.id', '=', 'external_order_lines.mapping_id')
            ->where('external_order_lines.id', $lineId)
            ->value('external_order_mappings.account_id');

        return $accountId === null ? null : ['accounts' => [(int) $accountId]];
    }

    /**
     * The market of one sale: an order of ours is judged as an order, a channel order through the account of its mapping. A kind the screen
     * does not serve names nothing that can be checked and is refused.
     *
     * @return array{orders: list<int>}|array{accounts: list<int>}|null
     */
    private static function marketOfASaleDocument(string $kind, mixed $id): ?array
    {
        $row = StaffRouteIds::one(value: $id);

        if ($row === null) {
            return null;
        }

        if ($kind === SaleDocument::SUBJECT_ORDER) {
            return ['orders' => $row];
        }

        if ($kind !== SaleDocument::SUBJECT_CHANNEL_ORDER) {
            return null;
        }

        $accountId = DB::table('external_order_mappings')->where('id', $row[0])->value('account_id');

        return $accountId === null ? null : ['accounts' => [(int) $accountId]];
    }
}
