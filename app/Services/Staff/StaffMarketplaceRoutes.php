<?php

namespace App\Services\Staff;

use App\Http\Controllers\Admin\Marketplace\BatchController;
use App\Http\Controllers\Admin\Marketplace\InventoryAdjustmentController;
use App\Http\Controllers\Admin\Marketplace\PurchaseOrderController;
use App\Http\Controllers\Admin\Marketplace\WarehouseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The marketplace STOCK screens of the admin panel that a staff country scope covers (GCC-14c, slice 5): the warehouses, the inventory
 * adjustments, the batches and the purchase orders -- the four screens that move stock or buy it (G1-11).
 *
 * These are writes, not lists, which is why they come before the rest of slice 5. A restricted employee could place another market's
 * product on a shelf, adjust its balance, write off its batch and receive a purchase order of it, and every one of those changes a figure
 * the other market sells against.
 *
 * `ScopeMarketplaceToStaffCountries` reads the rule for an employee who is restricted to some countries, and a route without a rule is
 * REFUSED to that employee rather than let through. What a part can say:
 *
 *   none                     the action names no product and lists none (a warehouse's own name and address, the supplier picker)
 *   list                     it returns products, or rows that belong to a product -- a stock movement, a batch, a shelf balance, a
 *                            purchase-order line: the product scope and `StaffCountryProductRowScope` narrow them
 *   product.input:{n}        one product id in the request (`{n}?` when the screen may be opened without one)
 *   product.items:{n}        the product ids inside a posted list of LINES (`items.*.product_id`): all of them must be in scope, and a line
 *                            that names no product names nothing, because a purchase order may buy something that is not in the catalogue
 *   batch.route:{n}          a batch row id in the path: the product it is a batch of must be in scope
 *   purchase_order.route:{n} a purchase order id in the path: EVERY line's product must be in scope, and it must have at least one -- the
 *                            rule an ORDER follows (StaffOrderVisibility), for the same reason: a purchase order can buy two markets'
 *                            goods, so only an employee who holds both may open it, and one holding no catalogue product at all belongs to
 *                            no market and is refused rather than guessed at
 *
 * What is NOT covered, and why: a WAREHOUSE has no market of its own. `inventory_locations` gained one (`2026_12_27_100000`, G1-05) and the
 * marketplace's own `warehouses` table did not, so what can be told apart here is the product whose units are being moved, never the shelf.
 * Giving that table a market belongs with the inventory lane; until then a restricted employee still SEES every warehouse on the list, and
 * the survey says so.
 */
final class StaffMarketplaceRoutes
{
    /** @var array<class-string, array<string, list<string>>> */
    private const RULES = [
        WarehouseController::class => [
            // The list also breaks one product's stock down per shelf when the screen is asked about one.
            'index' => ['product.input:product_id?', 'list'],
            'store' => ['none'],
            'update' => ['none'],
            'place' => ['product.input:product_id'],
            'transfer' => ['product.input:product_id'],
        ],
        InventoryAdjustmentController::class => [
            'index' => ['product.input:product_id?', 'list'],
            'adjust' => ['product.input:product_id'],
        ],
        BatchController::class => [
            'index' => ['product.input:product_id?', 'list'],
            'store' => ['product.input:product_id'],
            'writeOff' => ['batch.route:id'],
        ],
        PurchaseOrderController::class => [
            'index' => ['list'],
            'create' => ['none'],
            'store' => ['product.items:items'],
            'show' => ['purchase_order.route:id', 'list'],
            'place' => ['purchase_order.route:id'],
            'receive' => ['purchase_order.route:id'],
            'cancel' => ['purchase_order.route:id'],
        ],
    ];

    /** @return list<class-string> the controllers whose actions a staff country scope covers in slice 5 */
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
     * Whether every product the rule says this request names is one the employee may work with.
     *
     * @param list<string> $rule
     */
    public static function isCovered(Request $request, array $rule, StaffCountryScope $scope): bool
    {
        if ($rule === []) {
            return false;
        }

        foreach ($rule as $part) {
            $productIds = self::productIdsOf(request: $request, part: $part);

            if ($productIds === null || !$scope->coversEveryProduct(productIds: $productIds)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<int>|null null when what the part asks for is missing, is not an id, names a row that does not exist or a purchase order
     *                       that buys nothing from the catalogue, or is a part nobody understands
     */
    public static function productIdsOf(Request $request, string $part): ?array
    {
        if ($part === 'none' || $part === 'list') {
            return [];
        }

        [$subject, $where] = array_pad(explode('.', $part, 2), 2, '');
        [$kind, $name] = array_pad(explode(':', $where, 2), 2, '');
        $optional = str_ends_with($name, '?');
        $name = rtrim($name, '?');

        if ($name === '' || !in_array($kind, ['route', 'input', 'items'], true)) {
            return null;
        }

        $value = $kind === 'route' ? $request->route($name) : $request->input($name);

        return match (true) {
            $subject === 'product' && $kind === 'items' => self::productsOfPostedLines(lines: $value),
            $subject === 'product' && $optional && StaffRouteIds::blank(value: $value) => [],
            $subject === 'product' => StaffRouteIds::one(value: $value),
            $subject === 'batch' && $kind === 'route' => self::productOfABatch(batchId: $value),
            $subject === 'purchase_order' && $kind === 'route' => self::productsOfAPurchaseOrder(purchaseOrderId: $value),
            default => null,
        };
    }

    /**
     * The products a posted list of purchase-order lines names. A line with no `product_id` buys something that is not in the catalogue --
     * a service, a pallet, a packaging run -- and names no product, so it is not a refusal.
     *
     * @return list<int>|null
     */
    private static function productsOfPostedLines(mixed $lines): ?array
    {
        if (!is_array($lines)) {
            return null;
        }

        $named = [];
        foreach ($lines as $line) {
            $productId = is_array($line) ? ($line['product_id'] ?? null) : null;

            if (StaffRouteIds::blank(value: $productId)) {
                continue;
            }

            $id = StaffRouteIds::one(value: $productId);
            if ($id === null) {
                return null;
            }
            $named[] = $id[0];
        }

        return array_values(array_unique($named));
    }

    /** @return list<int>|null */
    private static function productOfABatch(mixed $batchId): ?array
    {
        $batch = StaffRouteIds::one(value: $batchId);
        if ($batch === null) {
            return null;
        }

        $productId = DB::table('product_batches')->where('id', $batch[0])->value('product_id');

        return $productId === null ? null : [(int) $productId];
    }

    /**
     * Every product a purchase order buys from the catalogue. An order with no line at all, or whose every line names no catalogue product,
     * belongs to no market: it answers null and a restricted employee is refused, which is the rule an ORDER with no line follows.
     *
     * @return list<int>|null
     */
    private static function productsOfAPurchaseOrder(mixed $purchaseOrderId): ?array
    {
        $order = StaffRouteIds::one(value: $purchaseOrderId);
        if ($order === null) {
            return null;
        }

        $productIds = DB::table('purchase_order_items')
            ->where('purchase_order_id', $order[0])
            ->whereNotNull('product_id')
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $productIds === [] ? null : $productIds;
    }
}
