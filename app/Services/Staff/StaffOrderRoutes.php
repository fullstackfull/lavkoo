<?php

namespace App\Services\Staff;

use App\Enums\SessionKey;
use App\Http\Controllers\Admin\Customer\CustomerController;
use App\Http\Controllers\Admin\Deliveryman\DeliveryManController;
use App\Http\Controllers\Admin\Order\DeliverySyriaDispatchController;
use App\Http\Controllers\Admin\Order\OrderController;
use App\Http\Controllers\Admin\Order\OrderEditController;
use App\Http\Controllers\Admin\Order\RefundController;
use App\Http\Controllers\Admin\POS\CartController;
use App\Http\Controllers\Admin\POS\POSController;
use App\Http\Controllers\Admin\POS\POSOrderController;
use App\Http\Controllers\Admin\Vendor\VendorController;
use App\Services\OrderEditService;
use Illuminate\Http\Request;
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
 *   unavailable      it shows what cannot be told apart by country (a wallet, a total of every order): a restricted employee is answered like for something that does not exist
 *   order.route:n    one order id in the path; order.input:n one in the request; order.inputs:n a list in the request, all of which must be visible
 *   line.input:n     one order LINE id in the request: the order it belongs to must be visible
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

    /** @return array<string, list<string>> the rule of every action of a covered controller, by the name of the action */
    public static function rulesOf(string $controller): array
    {
        $controller = ltrim($controller, '\\');

        return self::RULES[$controller] ?? self::READ_RULES[$controller] ?? [];
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
            default => false,
        };
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
