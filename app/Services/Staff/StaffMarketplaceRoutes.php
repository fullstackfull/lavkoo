<?php

namespace App\Services\Staff;

use App\Http\Controllers\Admin\Marketplace\BatchController;
use App\Http\Controllers\Admin\Marketplace\BrandRegistryController;
use App\Http\Controllers\Admin\Marketplace\CommissionRuleController;
use App\Http\Controllers\Admin\Marketplace\FulfillmentController;
use App\Http\Controllers\Admin\Marketplace\InventoryAdjustmentController;
use App\Http\Controllers\Admin\Marketplace\PayoutController;
use App\Http\Controllers\Admin\Marketplace\PurchaseOrderController;
use App\Http\Controllers\Admin\Marketplace\ReturnLogisticsController;
use App\Http\Controllers\Admin\Marketplace\SellerScorecardController;
use App\Http\Controllers\Admin\Marketplace\SettlementController;
use App\Http\Controllers\Admin\Marketplace\WarehouseController;
use App\Http\Controllers\Admin\Product\ReviewController;
use App\Services\Marketplace\CommissionEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The screens of slice 5 that a staff country scope covers (GCC-14c): the marketplace stock screens -- the warehouses, the inventory
 * adjustments, the batches and the purchase orders, the four that move stock or buy it (G1-11) -- and the rest of the slice, which is made
 * of ORDERS rather than of products: the reviews, the returns, the fulfilments, the seller scorecard, the settlements, the brand registry
 * and the commission rules (G-09).
 *
 * The stock screens came first because they are writes, not lists: a restricted employee could place another market's product on a shelf,
 * adjust its balance, write off its batch and receive a purchase order of it, and every one of those changes a figure the other market
 * sells against.
 *
 * `ScopeMarketplaceToStaffCountries` reads the rule for an employee who is restricted to some countries, and a route without a rule is
 * REFUSED to that employee rather than let through. What a part can say:
 *
 *   none                     the action names no product and lists none (a warehouse's own name and address, the supplier picker)
 *   list                     it returns products, or rows that belong to a product -- a stock movement, a batch, a shelf balance, a
 *                            purchase-order line, a review, a commission rule: the product scope and `StaffCountryProductRowScope` narrow
 *                            them as soon as the request is bound
 *   list:{subject}           it returns rows that belong to an ORDER (`returns`, `fulfillments`): those are narrowed only where a screen
 *                            asked for it, so that an action which CHANGES one still runs on the real data -- opening a second fulfilment
 *                            of an order must see the first one whatever market it is in
 *   unavailable              it shows what cannot be told apart by country, or decides something that crosses every market: a restricted
 *                            employee is answered like for something that does not exist
 *   product.input:{n}        one product id in the request (`{n}?` when the screen may be opened without one)
 *   product.items:{n}        the product ids inside a posted list of LINES (`items.*.product_id`): all of them must be in scope, and a line
 *                            that names no product names nothing, because a purchase order may buy something that is not in the catalogue
 *   batch.route:{n}          a batch row id in the path: the product it is a batch of must be in scope
 *   purchase_order.route:{n} a purchase order id in the path: EVERY line's product must be in scope, and it must have at least one -- the
 *                            rule an ORDER follows (StaffOrderVisibility), for the same reason: a purchase order can buy two markets'
 *                            goods, so only an employee who holds both may open it, and one holding no catalogue product at all belongs to
 *                            no market and is refused rather than guessed at
 *   order.input:{n}          one order id in the request: every product of its lines must be in scope, and it must have a line (`{n}?` when
 *                            the screen may be opened without one)
 *   review.input:{n}         a review id in the request: the product it is about must be in scope
 *   return.route:{n}         a return id in the path, and `return.posted` the return a form asks for: a return names a product, an order or
 *                            both, and whichever it names must be in scope -- one that names neither belongs to no market
 *   fulfillment.route:{n}    a fulfilment id in the path: the order it ships must be in scope
 *   commission_rule.route:{n} a commission rule id in the path, and `commission_rule.posted` the rule a form asks for: only a rule that
 *                            names a PRODUCT can belong to a market, so a global, category or vendor rule -- which changes what every
 *                            market's sellers are charged -- is refused to a restricted employee rather than guessed at
 *
 * Why three screens of this slice are `unavailable` rather than narrowed, which is a decision and not an omission:
 *
 *  - the SELLER SCORECARD counts a seller's orders, refunds, reviews and strikes through `SellerScorecardService`, which reads them with
 *    the QUERY BUILDER (`DB::table('orders')`), so no scope reaches them; and the tier it derives is a figure about the seller on the whole
 *    platform. Which market's orders count towards a seller's tier is a question nobody has answered, so the screen says nothing rather
 *    than printing a platform total to an employee of one market;
 *  - a SETTLEMENT is a seller's money across every market. The vendor ledger is keyed by seller, currency and legal entity and not by
 *    market (E-17.2 ships off), so a settlement cannot be split by country at all; and the two platform controls on that page -- the
 *    approver-is-not-the-payer separation of duties and the payment terms the marketplace promises its sellers -- change what happens in
 *    every market;
 *  - a BRAND decision crosses every market by construction: approving an ownership claim says who may sell under that brand everywhere,
 *    and the exposure counts beside it are platform totals read with the query builder.
 *
 * A WAREHOUSE still has no market COLUMN of its own: `inventory_locations` gained one (`2026_12_27_100000`, G1-05) and the marketplace's own
 * `warehouses` table did not, because whether one warehouse may hold two markets' stock is the owner's open question on A1-18 and a single
 * column would answer it. The list is narrowed all the same, by what is ON the shelf: `App\Services\Marketplace\WarehouseMarkets` derives a
 * warehouse's markets from the markets of the products whose units it holds, so a shelf of one market is that market's, a shelf of two is
 * shown to employees of either -- the shape the owner's answer would take if it were "yes" -- and an EMPTY shelf names no market and is shown
 * to everybody, which is honest rather than a gap: hiding one would make a warehouse vanish from the screen of whoever had just created it.
 */
final class StaffMarketplaceRoutes
{
    /**
     * The ledger payouts, while `StaffOrderRoutes::MONEY_ACTIONS_SWITCH` is on (G-14, second criterion).
     *
     * This screen was outside the staff-scope regime altogether -- no scope middleware on any of its routes -- so a restricted employee
     * reached all six with the module permission alone, five of them money-moving POSTs that approve a transfer, mark it paid, mark it
     * failed, send it again or reject it and release the reservation. Each is now judged by the market of the SHOP whose money it moves,
     * which is the rule `StaffCountryScope::coversEverySeller()` reads from `SellerMarketScope` for a seller's withdrawal, read again and
     * not restated.
     *
     * The LIST is narrowed as well as the actions, through the same rule (`StaffCountryPayoutScope`, subject `payouts`). A screen whose
     * five buttons refuse another market's payout while the page beside them prints its amount, its bank details and its reference is a
     * half-closed gate that reads as closed, which is worse than an open one.
     *
     * The rules are separate from `RULES` and merged only while the money switch is on, so that with the switch off -- which is how it
     * ships -- every action keeps the rule `none` it has always effectively had: a restricted employee reaches the screen exactly as today
     * and the list is narrowed for nobody.
     *
     * @var array<class-string, array<string, list<string>>>
     */
    private const MONEY_RULES = [
        PayoutController::class => [
            'index' => ['list:payouts'],
            'approve' => ['seller_payout.route:id'],
            'markPaid' => ['seller_payout.route:id'],
            'markFailed' => ['seller_payout.route:id'],
            'reissue' => ['seller_payout.route:id'],
            'reject' => ['seller_payout.route:id'],
        ],
    ];

    /** @var array<class-string, array<string, list<string>>> */
    private const RULES = [
        // The ledger payouts while the money switch is off: the rule each action has always effectively had, so nothing moves until the
        // switch does. `MONEY_RULES` above replaces every one of them when it is on.
        PayoutController::class => [
            'index' => ['none'],
            'approve' => ['none'],
            'markPaid' => ['none'],
            'markFailed' => ['none'],
            'reissue' => ['none'],
            'reject' => ['none'],
        ],
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
        // The reviews of the catalogue. A review is about one product, so the market of that product is the market of the review: the list,
        // its export and the per-shop averages of the vendor picker are narrowed through it (`StaffCountryProductRowScope` on `Review`), and
        // publishing or replying to one is judged by the review named in the form. The customer picker names no product.
        ReviewController::class => [
            'index' => ['product.input:product_id?', 'list'],
            'exportList' => ['product.input:product_id?', 'list'],
            'updateStatus' => ['review.input:id'],
            'addReviewReply' => ['review.input:review_id'],
            'searchVendor' => ['list'],
            'search' => ['none'],
            'getCustomerList' => ['none'],
        ],
        // The returns queue. A return names an order, a line, a product or several of those, and is judged by what it names; the queue is
        // narrowed through the ORDER each return is of.
        ReturnLogisticsController::class => [
            'index' => ['list:returns', 'list'],
            'store' => ['return.posted'],
            'transit' => ['return.route:id'],
            'receive' => ['return.route:id'],
            'reject' => ['return.route:id'],
        ],
        // The pick, pack and ship queue. A fulfilment is a shipment of one order, so it follows that order.
        FulfillmentController::class => [
            'index' => ['list:fulfillments', 'order.input:order_id?'],
            'store' => ['order.input:order_id'],
            'advance' => ['fulfillment.route:id'],
            'cancel' => ['fulfillment.route:id'],
        ],
        // The commission rules. Only a rule that names a product belongs to a market; the preview beside the list prices a sample line, so
        // the product it names is checked as the pickers are.
        CommissionRuleController::class => [
            'index' => ['product.input:preview_product_id?', 'list'],
            'store' => ['commission_rule.posted'],
            'update' => ['commission_rule.route:id', 'commission_rule.posted'],
            'toggle' => ['commission_rule.route:id'],
            'destroy' => ['commission_rule.route:id'],
        ],
        // The three screens of the slice that cannot be told apart by country: see the note at the head of this file. Each action is named
        // rather than the controller, so that an action added to one of them has no rule and is refused until somebody decides what it is.
        SellerScorecardController::class => [
            'index' => ['unavailable'],
        ],
        SettlementController::class => [
            'index' => ['unavailable'],
            'show' => ['unavailable'],
            'ledger' => ['unavailable'],
            'calculate' => ['unavailable'],
            'approve' => ['unavailable'],
            'markPaid' => ['unavailable'],
            'cancel' => ['unavailable'],
            'toggleMakerChecker' => ['unavailable'],
            'updateTerms' => ['unavailable'],
        ],
        BrandRegistryController::class => [
            'index' => ['unavailable'],
            'document' => ['unavailable'],
            'approve' => ['unavailable'],
            'reject' => ['unavailable'],
            'revoke' => ['unavailable'],
            'updateEnforcement' => ['unavailable'],
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
        $controller = ltrim($controller, '\\');
        $rules = self::RULES[$controller] ?? [];

        // `+` keeps the left-hand entry for a key both sides hold, so a money action takes its own rule and every other action of the
        // controller keeps the one above. The same shape as `StaffOrderRoutes::rulesOf()`, so there is one way the switch works.
        return $rules !== [] && StaffOrderRoutes::moneyActionsAreScoped() ? (self::MONEY_RULES[$controller] ?? []) + $rules : $rules;
    }

    /** @return list<string>|null */
    public static function ruleOf(string $controller, string $method): ?array
    {
        return self::rulesOf(controller: $controller)[$method] ?? null;
    }

    /**
     * Whether everything the rule says this request names is something the employee may work with.
     *
     * Every part of this slice resolved to PRODUCT ids until the ledger payouts came inside the regime, and a payout names a SELLER and no
     * product: a part is now resolved to the ids of its own SUBJECT, and the subject decides which question the scope is asked. Only
     * `seller_payout.*` answers sellers; every other part answers products exactly as it did, through the same `productIdsOf()` the stock
     * screens' tests drive directly.
     *
     * @param list<string> $rule
     */
    public static function isCovered(Request $request, array $rule, StaffCountryScope $scope): bool
    {
        if ($rule === []) {
            return false;
        }

        foreach ($rule as $part) {
            if (!self::partIsCovered(request: $request, part: $part, scope: $scope)) {
                return false;
            }
        }

        return true;
    }

    private static function partIsCovered(Request $request, string $part, StaffCountryScope $scope): bool
    {
        if (str_starts_with($part, 'seller_payout.')) {
            $sellerIds = self::sellerIdsOf(request: $request, part: $part);

            return $sellerIds !== null && $scope->coversEverySeller(sellerIds: $sellerIds);
        }

        $productIds = self::productIdsOf(request: $request, part: $part);

        return $productIds !== null && $scope->coversEveryProduct(productIds: $productIds);
    }

    /**
     * The shops whose money the part of a rule says this request moves.
     *
     * @return list<int>|null null when what the part asks for is missing, is not an id, or names a payout that does not exist or one that
     *                        names no shop -- each answered like a payout that does not exist, so which ids belong to which market is not
     *                        something an employee can find out by asking
     */
    public static function sellerIdsOf(Request $request, string $part): ?array
    {
        [, $where] = array_pad(explode('.', $part, 2), 2, '');
        [$kind, $name] = array_pad(explode(':', $where, 2), 2, '');

        if ($name === '' || $kind !== 'route') {
            return null;
        }

        $payout = StaffRouteIds::one(value: $request->route($name));

        if ($payout === null || !Schema::hasTable('vendor_payout_requests')) {
            return null;
        }

        $sellerId = DB::table('vendor_payout_requests')->where('id', $payout[0])->value('seller_id');

        return $sellerId === null || (int) $sellerId <= 0 ? null : [(int) $sellerId];
    }

    /**
     * What a rule lists through an ORDER, which a request is narrowed for. A part that says `list` alone names no subject: the rows of a
     * product are narrowed as soon as the request is bound, so nothing has to ask for them.
     *
     * @param list<string> $rule
     * @return list<string>
     */
    public static function subjectsOf(array $rule): array
    {
        return array_values(array_map(
            static fn (string $part): string => substr($part, strlen('list:')),
            array_filter($rule, static fn (string $part): bool => str_starts_with($part, 'list:'))
        ));
    }

    /**
     * @return list<int>|null null when what the part asks for is missing, is not an id, names a row that does not exist, a purchase order
     *                       that buys nothing from the catalogue, an order with no line or a commission rule that belongs to no market, or
     *                       is `unavailable` or a part nobody understands
     */
    public static function productIdsOf(Request $request, string $part): ?array
    {
        if ($part === 'none' || $part === 'list' || str_starts_with($part, 'list:')) {
            return [];
        }

        if ($part === 'unavailable') {
            return null;
        }

        [$subject, $where] = array_pad(explode('.', $part, 2), 2, '');

        if ($where === 'posted') {
            return match ($subject) {
                'return' => self::productsOfAPostedReturn(request: $request),
                'commission_rule' => self::productOfACommissionRule(scopeType: $request->input('scope_type'), scopeId: $request->input('scope_id')),
                default => null,
            };
        }

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
            $subject === 'order' && $optional && StaffRouteIds::blank(value: $value) => [],
            $subject === 'order' => self::productsOfAnOrder(orderId: $value),
            $subject === 'review' => self::productOfARow(table: 'reviews', rowId: $value),
            $subject === 'return' && $kind === 'route' => self::productsOfAReturn(returnId: $value),
            $subject === 'fulfillment' && $kind === 'route' => self::orderOfAFulfillment(fulfillmentId: $value),
            $subject === 'commission_rule' && $kind === 'route' => self::productOfACommissionRuleRow(ruleId: $value),
            default => null,
        };
    }

    /**
     * Every product the lines of an order name. An order with no line belongs to no market and answers null, which is the rule
     * `StaffOrderVisibility` follows for the order screens: it fails closed rather than being guessed at.
     *
     * @return list<int>|null
     */
    private static function productsOfAnOrder(mixed $orderId): ?array
    {
        $order = StaffRouteIds::one(value: $orderId);
        if ($order === null) {
            return null;
        }

        $productIds = DB::table('order_details')
            ->where('order_id', $order[0])
            ->pluck('product_id')
            ->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $productIds === [] ? null : $productIds;
    }

    /** The product a row of a table points at through `product_id`, read with the query builder so no scope of a screen reaches it. */
    private static function productOfARow(string $table, mixed $rowId): ?array
    {
        $row = StaffRouteIds::one(value: $rowId);
        if ($row === null) {
            return null;
        }

        $productId = DB::table($table)->where('id', $row[0])->value('product_id');

        return $productId === null ? null : [(int) $productId];
    }

    /**
     * What a return is about: the product it names, or, when it names none, every product of the order it names. A return that names
     * neither belongs to no market.
     *
     * @return list<int>|null
     */
    private static function productsOfAReturn(mixed $returnId): ?array
    {
        $row = StaffRouteIds::one(value: $returnId);
        if ($row === null) {
            return null;
        }

        $return = DB::table('return_shipments')->where('id', $row[0])->first(['product_id', 'order_id', 'order_details_id']);

        return $return === null ? null : self::productsOfAReturnTarget(productId: $return->product_id, orderId: $return->order_id, lineId: $return->order_details_id);
    }

    /** The same question for the form that authorises a return, which posts the product, the order or the line. */
    private static function productsOfAPostedReturn(Request $request): ?array
    {
        return self::productsOfAReturnTarget(productId: $request->input('product_id'), orderId: $request->input('order_id'), lineId: $request->input('order_details_id'));
    }

    /** @return list<int>|null */
    private static function productsOfAReturnTarget(mixed $productId, mixed $orderId, mixed $lineId): ?array
    {
        if (!StaffRouteIds::blank(value: $productId)) {
            return StaffRouteIds::one(value: $productId);
        }

        if (!StaffRouteIds::blank(value: $orderId)) {
            return self::productsOfAnOrder(orderId: $orderId);
        }

        if (StaffRouteIds::blank(value: $lineId)) {
            return null;
        }

        $line = StaffRouteIds::one(value: $lineId);
        $productId = $line === null ? null : DB::table('order_details')->where('id', $line[0])->value('product_id');

        return $productId === null ? null : [(int) $productId];
    }

    /** The products of the order a fulfilment ships. A fulfilment of no order, or of an order with no line, belongs to no market. */
    private static function orderOfAFulfillment(mixed $fulfillmentId): ?array
    {
        $row = StaffRouteIds::one(value: $fulfillmentId);
        if ($row === null) {
            return null;
        }

        $orderId = DB::table('order_fulfillments')->where('id', $row[0])->value('order_id');

        return $orderId === null ? null : self::productsOfAnOrder(orderId: $orderId);
    }

    /** @return list<int>|null */
    private static function productOfACommissionRuleRow(mixed $ruleId): ?array
    {
        $row = StaffRouteIds::one(value: $ruleId);
        if ($row === null) {
            return null;
        }

        $rule = DB::table('commission_rules')->where('id', $row[0])->first(['scope_type', 'scope_id']);

        return $rule === null ? null : self::productOfACommissionRule(scopeType: $rule->scope_type, scopeId: $rule->scope_id);
    }

    /**
     * The product a commission rule charges for. A rule that is global, or that names a category or a vendor, changes what the sellers of
     * EVERY market are charged, so it belongs to no one market and a restricted employee may not write it.
     *
     * @return list<int>|null
     */
    private static function productOfACommissionRule(mixed $scopeType, mixed $scopeId): ?array
    {
        return $scopeType === CommissionEngine::SCOPE_PRODUCT ? StaffRouteIds::one(value: $scopeId) : null;
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
