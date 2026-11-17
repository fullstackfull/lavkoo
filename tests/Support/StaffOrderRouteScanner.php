<?php

namespace Tests\Support;

use Illuminate\Routing\Route as RoutingRoute;

/**
 * Which routes of the admin panel read orders, order lines, the money records of orders, refunds or the earnings and counters made of them, or name an order (GCC-14c,
 * slice 3): the models, the repositories, the tables, the relations, the services that hold the sums and the wallets, and an order id in a route or a request. The walk and
 * its blind spots are those of StaffRouteScanner.
 */
final class StaffOrderRouteScanner extends StaffRouteScanner
{
    private const MODELS = 'Order|OrderDetail|OrderTransaction|RefundRequest|RefundTransaction|OrderStatusHistory|OrderEditHistory|OrderExpectedDeliveryHistory|OrderDetailsReward|OrderTax|OrderDeliveryVerification';

    private const REPOSITORIES = 'order|orderDetail|orderDetails|orderTransaction|refundRequest|refundTransaction|orderStatusHistory|orderEditHistory|orderExpectedDeliveryHistory|orderDetailsRewards';

    private const INTERFACES = 'Order|OrderDetail|OrderTransaction|RefundRequest|RefundTransaction|OrderStatusHistory|OrderEditHistory|OrderExpectedDeliveryHistory|OrderDetailsRewards';

    /** What in the source of a file or an action means that it reads orders, their lines, their money records, refunds or the earnings made of them. */
    private function markers(): array
    {
        return [
            'model' => '/(?<![A-Za-z])(?:\\\\App\\\\Models\\\\)?(?:' . self::MODELS . ')::/',
            'repository' => '/\b(?:' . self::REPOSITORIES . ')Repo\b|\b(?:' . self::INTERFACES . ')RepositoryInterface\b/',
            'table' => '/(?:table|from|join|leftJoin|rightJoin)\(\s*[\'"](?:orders|order_details|order_transactions|refund_requests|refund_transactions|order_status_histories)[\'"]/',
            'relation' => '/->(?:orders|orderDetails|deliveredOrders|orderDelivered|refundRequest)\b|[\'"](?:orders|orderDetails|orderDelivered|deliveredOrders|refundRequest|orders_count)[\'"]\s*(?:=>|,|\))/',
            'service' => '/\b(?:OrderService|OrderManager|OrderDetailsService|DashboardService|EarningReportsService|TransactionReportService|RefundTransactionService|RefundStatusService)\b/',
            'wallet' => '/\b(?:SellerWallet|AdminWallet|DeliverymanWallet|adminWalletRepo|vendorWalletRepo|AdminWalletRepositoryInterface|VendorWalletRepositoryInterface)\b/',
            'order id in the request' => '/[\'"](?:order_id|orderId|order_ids|orderIds)[\'"]/',
        ];
    }

    /** @return array<string, array{file: string, routes: list<string>, orderRoutes: list<string>, markers: list<string>}> the admin controllers that read orders or name one, by class */
    public function controllers(): array
    {
        return $this->scan(readingKey: 'orderRoutes');
    }

    /** @return list<string> why a route reads orders or names one: empty when it does not */
    public function routeReadsOrders(RoutingRoute $route): array
    {
        return $this->routeReasons(route: $route);
    }

    protected function sourceMarkers(): array
    {
        return $this->markers();
    }

    protected function nameReasons(RoutingRoute $route): array
    {
        $reasons = [];
        foreach ($route->parameterNames() as $parameter) {
            if (preg_match('/^(order|refund)/i', $parameter) === 1) {
                $reasons[] = 'parameter ' . $parameter;
            }
        }
        if (preg_match('#(^|/)(orders?|refund[a-z-]*|order-[a-z-]*|[a-z-]*-orders?|[a-z-]*earning[a-z-]*|[a-z-]*transaction[a-z-]*)(/|$)#i', $route->uri()) === 1) {
            $reasons[] = 'path';
        }

        return $reasons;
    }
}
