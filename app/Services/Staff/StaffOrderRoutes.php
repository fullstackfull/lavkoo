<?php

namespace App\Services\Staff;

use App\Enums\SessionKey;
use App\Http\Controllers\Admin\Customer\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\Deliveryman\DeliveryManController;
use App\Http\Controllers\Admin\ExpenseTransactionReportController;
use App\Http\Controllers\Admin\InhouseProductSaleController;
use App\Http\Controllers\Admin\Order\DeliverySyriaDispatchController;
use App\Http\Controllers\Admin\Order\OrderController;
use App\Http\Controllers\Admin\Order\OrderEditController;
use App\Http\Controllers\Admin\Order\RefundController;
use App\Http\Controllers\Admin\POS\CartController;
use App\Http\Controllers\Admin\POS\POSController;
use App\Http\Controllers\Admin\OrderReportController;
use App\Http\Controllers\Admin\POS\POSOrderController;
use App\Http\Controllers\Admin\ProductReportController;
use App\Http\Controllers\Admin\ProductStockReportController;
use App\Http\Controllers\Admin\ProductWishlistReportController;
use App\Http\Controllers\Admin\Report\RefundTransactionController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\Telemetry\AnalyticsController;
use App\Http\Controllers\Admin\TransactionReportController;
use App\Http\Controllers\Admin\VendorProductSaleReportController;
use App\Http\Controllers\Admin\Customer\CustomerLoyaltyController;
use App\Http\Controllers\Admin\Customer\CustomerWalletController;
use App\Http\Controllers\Admin\Vendor\VendorController;
use App\Services\OrderEditService;
use Modules\TaxModule\app\Http\Controllers\Admin\Reports\AdminTaxReportController;
use Modules\TaxModule\app\Http\Controllers\Admin\Reports\VendorTaxReportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;

/**
 * The screens of the admin panel that a staff country scope covers for orders, and what each of their actions names or lists: the order, POS and refund screens (slice 2), and the
 * screens that read orders without managing them, such as the people, the dashboard and the reports (slice 3).
 *
 * Every action of these controllers has a rule: a list of parts. The middleware of the controllers (ScopeOrdersToStaffCountries) reads it for an employee who is restricted
 * to some countries, and a route without a rule is refused to that employee instead of being let through. A test walks the route table and fails for an action that has none.
 * What a part can say:
 *
 *   none             the action names no order, refund request or product and lists none (a session flag, a customer picker, a cart kept in the session)
 *   list:orders      it returns order rows (a list, a counter, an export, the neighbours of one order): the scope of the orders narrows them
 *   list:refunds     it returns refund request rows: the scope of the refund requests narrows them
 *   list:order_lines, list:order_transactions, list:refund_transactions
 *                    it reads the lines of orders, the money records of orders, or the money records of refunds (a sum, a count, a report): each is narrowed through the order or
 *                    the order line it belongs to, and a screen names the ones it reads
 *   list:wallet_transactions, list:loyalty_point_transactions
 *                    it reads a customer's wallet or loyalty ledger: each row records the market it belongs to, so the rows are narrowed by
 *                    that column. These two came later than `unavailable` below and are the reason its wording is now narrower than it was:
 *                    a wallet COULD not be told apart by country until the ledgers carried one
 *   unavailable      it shows what cannot be told apart by country (a total of every order): a restricted employee is answered like for something that does not exist
 *   order.route:n    one order id in the path; order.input:n one in the request; order.inputs:n a list in the request, all of which must be visible
 *   line.input:n     one order LINE id in the request: the order it belongs to must be visible; line.route:n one in the path
 *   refund.route:n   one refund request id in the path; refund.input:n one in the request
 *   product.input:n  one product id in the request: it must be one of the employee's (the product screens' rule, for the POS cart and the order edit)
 *   cart             every product in the POS cart that is in use must be one of theirs
 *   edit:n           every product in the edit of the order named by input n must be one of theirs
 *
 * An action that only changes an order runs on the real data, so it lists nothing: the count of a customer's delivered orders decides a referral bonus, the number of orders
 * gives the next POS order id, the uses of a coupon limit it. Only what a screen lists is narrowed, and an action that changes an order is let in only when the order is visible.
 *
 * Something out of the employee's countries is answered like something that does not exist (a 404), for an id of a route, of the input and of a list, so that belonging to a
 * country is not something an employee can find out by asking.
 */
final class StaffOrderRoutes
{
    /** The switch that puts the panel's own money actions inside the country scope (G-14, second criterion). Off as it ships. */
    public const MONEY_ACTIONS_SWITCH = 'gcc.staff.money_actions.scoped';

    /**
     * The money actions of these screens, and what each one is checked against while `MONEY_ACTIONS_SWITCH` is on (G-14, second criterion).
     *
     * The card's second criterion is that a withdrawal or payout approval, a wallet adjustment and a coupon change are checked against the
     * employee's country. The coupon half has been enforced since `StaffCouponRoutes`; these three actions are the rest of it that lives
     * inside this registry, and each has carried the rule `none` -- which means "names nothing that belongs to a market", and for a money
     * action that was a false reassurance rather than a fact:
     *
     *   `VendorController::withdrawStatus`   approves or denies a seller's withdrawal: moves the seller's wallet and writes a payout entry
     *   `VendorController::getWithdrawView`  the one request's screen, which shows the amount and the bank details it would be paid to
     *   `CustomerWalletController::addFund`  credits a customer's wallet by hand
     *
     * The first two name a market through the SELLER, by the rule `StaffCountryScope::coversEverySeller()` reads from
     * `SellerMarketScope`. The third names NO market and cannot: `users.wallet_balance` is one global balance, a customer belongs to no
     * market in this schema, and the per-market accounts of GCC-15 are dormant -- so a hand-made credit is a platform act, and the honest
     * scope for an employee of one market is that they may not make it. That is `unavailable`, the same answer this registry already gives
     * a restricted employee for a platform total they cannot be shown a share of.
     *
     * **Off as it ships, and what is still NOT covered when it is on**, said plainly because a half-closed money gate read as closed is
     * worse than an open one: the withdraw LIST and its export (`getWithdrawListView`, `exportWithdrawList`) are not narrowed, so a
     * restricted employee still SEES every market's withdrawal requests and can only decide their own; and two whole screens are outside
     * this regime altogether, with no scope middleware on them at all -- `Admin\Marketplace\PayoutController` (approve, mark paid, mark
     * failed, reissue, reject) and `Admin\Deliveryman\DeliverymanWithdrawController`. A delivery man belongs to no market in this schema
     * either, which is why that one is named here rather than guessed at.
     *
     * @var array<class-string, array<string, list<string>>>
     */
    private const MONEY_RULES = [
        VendorController::class => [
            'getWithdrawView' => ['seller_payout.route:withdrawId'],
            'withdrawStatus' => ['seller_payout.route:id'],
        ],
        CustomerWalletController::class => [
            'addFund' => ['unavailable'],
        ],
    ];

    private const RULES = [
        OrderController::class => [
            'index' => ['list:orders'],
            'exportList' => ['list:orders'],
            'generateInvoice' => ['order.route:id'],
            'getView' => ['order.route:id', 'list:orders'],
            'updateAddress' => ['order.input:order_id'],
            'updateDeliverInfo' => ['order.input:order_id'],
            'addDeliveryMan' => ['order.route:order_id'],
            'updateAmountDate' => ['order.input:order_id'],
            'getCustomers' => ['none'],
            'updatePaymentStatus' => ['order.input:id'],
            'filterInHouseOrder' => ['none'],
            'uploadDigitalFileAfterSell' => ['line.input:order_id'],
            'downloadDigitalFile' => ['line.route:id'],
            'updateStatus' => ['order.input:id'],
            'bulkUpdateStatus' => ['order.inputs:ids'],
            'orderReturnAmountToCustomer' => ['order.input:order_id'],
            'orderDueAmountSwitchToCOD' => ['order.input:order_id'],
            'orderDueAmountMarkAsPaid' => ['order.input:order_id'],
        ],
        OrderEditController::class => [
            'getSearchEditOrderProductsView' => ['order.input:order_id'],
            'getEditOrderProductModalView' => ['order.input:order_id', 'product.input:product_id'],
            'checkProductVariantPrice' => ['order.input:order_id', 'product.input:product_id'],
            'addEditOrderProduct' => ['order.input:order_id', 'product.input:product_id'],
            'removeEditOrderProduct' => ['order.input:order_id', 'product.input:product_id'],
            'updateEditOrderProductList' => ['order.input:order_id'],
            'generateEditOrderByProductList' => ['order.input:order_id', 'edit:order_id'],
        ],
        DeliverySyriaDispatchController::class => [
            'dispatchOrder' => ['order.input:order_id'],
        ],
        RefundController::class => [
            'index' => ['list:refunds'],
            'exportList' => ['list:refunds'],
            'getDetailsView' => ['refund.route:id'],
            'updateRefundStatus' => ['refund.input:id'],
        ],
        POSController::class => [
            'index' => ['list:orders'],
            'changeCustomer' => ['none'],
            'updateDiscount' => ['none'],
            'getCouponDiscount' => ['none'],
            'getQuickView' => ['product.input:product_id'],
            'getSearchedProductsView' => ['none'],
        ],
        CartController::class => [
            'getVariantPrice' => ['product.input:id'],
            'updateQuantity' => ['product.input:key'],
            'getCartIds' => ['none'],
            'clearSessionCartIds' => ['none'],
            'addToCart' => ['product.input:id'],
            'removeCart' => ['none'],
            'emptyCart' => ['none'],
            'changeCart' => ['none'],
            'addNewCartId' => ['none'],
        ],
        POSOrderController::class => [
            'index' => ['order.route:id'],
            'placeOrder' => ['cart'],
            'cancelOrder' => ['none'],
            'getAllHoldOrdersView' => ['none'],
        ],
    ];

    /** The screens that read orders without managing them (slice 3): what each action lists, and what it cannot show a restricted employee. */
    private const READ_RULES = [
        // A customer's money history in the panel (A2-V1). The ledgers carry a market now, so a restricted employee reads the movements of
        // their OWN markets rather than all of them or none; the bonus setup is the platform's own configuration and names no customer and no
        // market, so it lists nothing.
        CustomerWalletController::class => [
            'index' => ['list:wallet_transactions'],
            'exportList' => ['list:wallet_transactions'],
            'addFund' => ['none'],
            'getBonusSetupView' => ['none'],
            'addBonusSetup' => ['none'],
            'updateStatus' => ['none'],
            'deleteBonus' => ['none'],
            'getUpdateView' => ['none'],
            'update' => ['none'],
        ],
        CustomerLoyaltyController::class => [
            'index' => ['list:loyalty_point_transactions'],
            'exportList' => ['list:loyalty_point_transactions'],
        ],
        CustomerController::class => [
            'index' => ['list:orders'],
            'getView' => ['list:orders', 'list:refunds'],
            'exportOrderList' => ['list:orders'],
            'updateStatus' => ['none'],
            'bulkUpdateStatus' => ['none'],
            'deleteCustomer' => ['none'],
            'getSubscriberListView' => ['none'],
            'exportSubscribersList' => ['none'],
            'exportList' => ['list:orders'],
            'getCustomerList' => ['none'],
            'getCustomerListWithoutAllCustomerName' => ['none'],
            'add' => ['none'],
            'updateProfile' => ['none'],
            'getCustomerSettingsView' => ['none'],
            'updateCustomer' => ['none'],
        ],
        DeliveryManController::class => [
            'index' => ['list:orders'],
            'getAddView' => ['none'],
            'add' => ['none'],
            'updateStatus' => ['none'],
            'exportList' => ['list:orders'],
            'getUpdateView' => ['none'],
            'update' => ['none'],
            'delete' => ['none'],
            'getEarningOverview' => ['unavailable'],
            'getOrderWiseEarningView' => ['list:orders'],
            'getOrderWiseEarningListByFilter' => ['list:orders'],
            'getOrderHistoryList' => ['list:orders'],
            'getOrderHistoryListExport' => ['list:orders'],
            'getRatingView' => ['unavailable'],
            'getOrderStatusHistory' => ['order.route:order'],
        ],
        VendorController::class => [
            'index' => ['list:orders'],
            'getAddView' => ['none'],
            'add' => ['none'],
            'exportOrderList' => ['list:orders'],
            'updateStatus' => ['none'],
            'bulkUpdateStatus' => ['none'],
            'exportList' => ['list:orders'],
            'updateSalesCommission' => ['none'],
            'getOrderDetailsView' => ['order.route:order_id', 'list:orders'],
            'getView' => ['list:orders', 'list:order_transactions'],
            'updateSetting' => ['none'],
            'getWithdrawListView' => ['none'],
            'exportWithdrawList' => ['none'],
            'getWithdrawView' => ['none'],
            'withdrawStatus' => ['none'],
            'loadMoreStores' => ['none'],
            'destroy' => ['none'],
        ],
        DashboardController::class => [
            'index' => ['list:orders', 'list:order_lines', 'list:order_transactions', 'list:refunds'],
            'getOrderStatus' => ['list:orders'],
            'getEarningStatistics' => ['list:order_transactions'],
            'getOrderStatistics' => ['list:orders'],
            'getRealTimeActivities' => ['list:orders'],
        ],
        OrderReportController::class => [
            'order_list' => ['list:orders', 'list:order_transactions'],
            'orderReportExportExcel' => ['list:orders', 'list:order_transactions'],
            'exportOrderReportInPDF' => ['list:orders', 'list:order_transactions'],
        ],
        ReportController::class => [
            'admin_earning' => ['list:orders', 'list:order_transactions', 'list:refund_transactions'],
            'exportAdminEarning' => ['list:orders', 'list:order_transactions', 'list:refund_transactions'],
            'admin_earning_duration_download_pdf' => ['list:orders', 'list:order_transactions', 'list:refund_transactions'],
            'vendorEarning' => ['unavailable'],
            'exportVendorEarning' => ['list:orders', 'list:order_transactions', 'list:refund_transactions'],
            'set_date' => ['none'],
        ],
        TransactionReportController::class => [
            'order_transaction_list' => ['list:orders', 'list:order_lines', 'list:order_transactions'],
            'pdf_order_wise_transaction' => ['order.input:order_id', 'list:orders', 'list:order_lines', 'list:order_transactions'],
            'orderTransactionExportExcel' => ['list:orders', 'list:order_lines', 'list:order_transactions'],
            'order_transaction_summary_pdf' => ['list:orders', 'list:order_lines', 'list:order_transactions'],
            'wallet_bonus' => ['none'],
        ],
        ExpenseTransactionReportController::class => [
            'getExpenseTransactionList' => ['list:orders', 'list:order_transactions'],
            'generateOrderWiseExpenseTransactionPdf' => ['order.input:id', 'list:orders', 'list:order_transactions'],
            'expenseTransactionExportExcel' => ['list:orders', 'list:order_transactions'],
            'generateExpenseTransactionSummaryPDF' => ['list:orders', 'list:order_transactions'],
        ],
        RefundTransactionController::class => [
            'index' => ['list:refund_transactions'],
            'exportRefundTransaction' => ['list:refund_transactions'],
            'getRefundTransactionPDF' => ['list:refund_transactions'],
        ],
        VendorProductSaleReportController::class => [
            'vendorReport' => ['list:orders', 'list:refund_transactions'],
            'exportVendorReport' => ['list:orders', 'list:refund_transactions'],
        ],
        ProductReportController::class => [
            'all_product' => ['list:order_lines'],
            'allProductExportExcel' => ['list:order_lines'],
        ],
        ProductStockReportController::class => [
            'index' => ['none'],
            'export' => ['none'],
        ],
        ProductWishlistReportController::class => [
            'index' => ['none'],
            'export' => ['none'],
        ],
        InhouseProductSaleController::class => [
            'index' => ['list:order_lines'],
        ],
        AdminTaxReportController::class => [
            'getTaxReport' => ['list:orders', 'list:order_transactions'],
            'getTaxDetails' => ['list:orders', 'list:order_transactions'],
            'adminTaxDetailsExport' => ['list:orders', 'list:order_transactions'],
            'adminTaxReportExport' => ['list:orders', 'list:order_transactions'],
        ],
        VendorTaxReportController::class => [
            'vendorWiseTaxes' => ['list:orders', 'list:order_transactions'],
            'vendorWiseTaxExport' => ['list:orders', 'list:order_transactions'],
            'vendorTax' => ['list:orders', 'list:order_transactions'],
            'vendorTaxExport' => ['list:orders', 'list:order_transactions'],
        ],
        AnalyticsController::class => [
            'index' => ['unavailable'],
            'live' => ['unavailable'],
            'export' => ['unavailable'],
            'campaignQr' => ['unavailable'],
            'storeCampaign' => ['unavailable'],
            'toggleCampaign' => ['unavailable'],
        ],
    ];

    /** @return list<class-string> the controllers whose actions a staff country scope covers here: the order, POS and refund screens, then the ones that read orders */
    public static function controllers(): array
    {
        return [...self::orderScreenControllers(), ...self::readScreenControllers()];
    }

    /** @return list<class-string> the order, POS and refund screens (slice 2) */
    public static function orderScreenControllers(): array
    {
        return array_keys(self::RULES);
    }

    /** @return list<class-string> the screens that read orders without managing them (slice 3) */
    public static function readScreenControllers(): array
    {
        return array_keys(self::READ_RULES);
    }

    /** Whether the panel's money actions are checked against the employee's country (G-14, second criterion). Off as it ships. */
    public static function moneyActionsAreScoped(): bool
    {
        return filter_var(config(self::MONEY_ACTIONS_SWITCH, false), FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<string, list<string>> the rule of every action of a covered controller, by the name of the action */
    public static function rulesOf(string $controller): array
    {
        $controller = ltrim($controller, '\\');
        $rules = self::RULES[$controller] ?? self::READ_RULES[$controller] ?? [];

        // While the money switch is off the rules are the ones above, unchanged, so every guard, inventory and document that reads this
        // registry reads exactly what it read before. With it on the money actions of this controller take their own rule.
        return $rules !== [] && self::moneyActionsAreScoped() ? (self::MONEY_RULES[$controller] ?? []) + $rules : $rules;
    }

    /** @return list<string>|null */
    public static function ruleOf(string $controller, string $method): ?array
    {
        return self::rulesOf(controller: $controller)[$method] ?? null;
    }

    /**
     * What a rule lists, which a request is narrowed for.
     *
     * @param list<string> $rule
     * @return list<string>
     */
    public static function subjectsOf(array $rule): array
    {
        return array_values(array_map(static fn (string $part): string => substr($part, strlen('list:')), array_filter($rule, static fn (string $part): bool => str_starts_with($part, 'list:'))));
    }

    /**
     * Whether everything a request names under a rule is visible to the current request. A part that cannot be checked, because what it asks for is missing or cannot be an
     * id, or because nobody knows the part, is not covered, which is answered like something that does not exist.
     *
     * @param list<string> $rule
     */
    public static function isCovered(Request $request, array $rule, StaffCountryScope $scope): bool
    {
        foreach ($rule as $part) {
            if (!self::partIsCovered(request: $request, part: $part, scope: $scope)) {
                return false;
            }
        }

        return true;
    }

    private static function partIsCovered(Request $request, string $part, StaffCountryScope $scope): bool
    {
        [$kind, $name] = array_pad(explode(':', $part, 2), 2, '');

        return match ($kind) {
            'none', 'list' => true,
            'unavailable' => false,
            'cart' => self::productsAreCovered(productIds: self::productIdsOfCart(), scope: $scope),
            'edit' => self::productsAreCovered(productIds: self::productIdsOfOrderEdit(orderId: $request->input($name)), scope: $scope),
            default => self::namedIdsAreCovered(request: $request, kind: $kind, name: $name, scope: $scope),
        };
    }

    private static function namedIdsAreCovered(Request $request, string $kind, string $name, StaffCountryScope $scope): bool
    {
        [$subject, $source] = array_pad(explode('.', $kind, 2), 2, '');
        $ids = match ($source) {
            'route' => StaffRouteIds::one(value: $request->route($name)),
            'input' => StaffRouteIds::one(value: $request->input($name)),
            'inputs' => StaffRouteIds::many(value: $request->input($name)),
            default => null,
        };

        return $ids !== null && match ($subject) {
            'order' => $scope->coversEveryOrder(orderIds: $ids),
            'line' => $scope->coversEveryOrderLine(lineIds: $ids),
            'refund' => $scope->coversEveryRefund(refundIds: $ids),
            'product' => $scope->coversEveryProduct(productIds: $ids),
            'seller_payout' => self::sellersAreCovered(sellerIds: self::sellersOfVendorWithdraws(withdrawIds: $ids), scope: $scope),
            default => false,
        };
    }

    /** @param list<int>|null $sellerIds */
    private static function sellersAreCovered(?array $sellerIds, StaffCountryScope $scope): bool
    {
        return $sellerIds !== null && $scope->coversEverySeller(sellerIds: $sellerIds);
    }

    /**
     * The sellers whose money these withdrawal requests would move.
     *
     * @param list<int> $withdrawIds
     * @return list<int>|null null when a request is missing, or names no seller -- a delivery man's row reached through a vendor screen --
     *                        which is answered like a request that does not exist
     */
    private static function sellersOfVendorWithdraws(array $withdrawIds): ?array
    {
        if (!Schema::hasTable('withdraw_requests')) {
            return null;
        }

        $sellerIds = DB::table('withdraw_requests')->whereIn('id', $withdrawIds)->pluck('seller_id')->all();
        if (count($sellerIds) !== count($withdrawIds)) {
            return null;
        }

        foreach ($sellerIds as $sellerId) {
            if ($sellerId === null || (int) $sellerId <= 0) {
                return null;
            }
        }

        return array_values(array_unique(array_map('intval', $sellerIds)));
    }

    /** @param list<int>|null $productIds */
    private static function productsAreCovered(?array $productIds, StaffCountryScope $scope): bool
    {
        return $productIds !== null && $scope->coversEveryProduct(productIds: $productIds);
    }

    /**
     * The products of the POS cart in use, which the session keeps beside its discounts and its coupon.
     *
     * @return list<int>|null null when an item names no product
     */
    private static function productIdsOfCart(): ?array
    {
        $cartId = session(SessionKey::CURRENT_USER);
        $cart = is_string($cartId) ? session($cartId) : null;

        return self::productIdsOfItems(items: is_array($cart) ? $cart : [], key: 'id');
    }

    /**
     * The products of the edit of an order that the session holds (the edit starts from the lines of the order, and each product added since is in it).
     *
     * @return list<int>|null null when the order is no order id, or an item names no product
     */
    private static function productIdsOfOrderEdit(mixed $orderId): ?array
    {
        $order = StaffRouteIds::one(value: $orderId);
        if ($order === null) {
            return null;
        }

        $edit = Session::get(app(OrderEditService::class)->getOrderEditSessionKey(orderId: (string) $order[0]));
        $productList = is_array($edit) && is_array($edit['product_list'] ?? null) ? $edit['product_list'] : [];

        return self::productIdsOfItems(items: $productList, key: 'product_id');
    }

    /**
     * @param array<array-key, mixed> $items
     * @return list<int>|null
     */
    private static function productIdsOfItems(array $items, string $key): ?array
    {
        $ids = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = StaffRouteIds::one(value: $item[$key] ?? null);
            if ($id === null) {
                return null;
            }
            $ids[] = $id[0];
        }

        return $ids;
    }
}
