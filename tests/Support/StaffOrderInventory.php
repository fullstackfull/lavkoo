<?php

namespace Tests\Support;

use App\Http\Controllers\Admin\Customer\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\Deliveryman\DeliveryManController;
use App\Http\Controllers\Admin\ExpenseTransactionReportController;
use App\Http\Controllers\Admin\Fulfillment\ProviderFulfillmentController;
use App\Http\Controllers\Admin\Marketplace\FulfillmentController;
use App\Http\Controllers\Admin\Marketplace\PurchaseOrderController;
use App\Http\Controllers\Admin\Marketplace\ReturnLogisticsController;
use App\Http\Controllers\Admin\MyFatoorah\SettingsController as MyFatoorahSettingsController;
use App\Http\Controllers\Admin\OperationalCosts\ProfitabilityController;
use App\Http\Controllers\Admin\Order\DeliverySyriaDispatchController;
use App\Http\Controllers\Admin\Order\OrderController;
use App\Http\Controllers\Admin\Order\OrderEditController;
use App\Http\Controllers\Admin\Order\RefundController;
use App\Http\Controllers\Admin\OrderReportController;
use App\Http\Controllers\Admin\POS\POSController;
use App\Http\Controllers\Admin\POS\POSOrderController;
use App\Http\Controllers\Admin\Product\ProductController;
use App\Http\Controllers\Admin\Product\ReviewController;
use App\Http\Controllers\Admin\ProductReportController;
use App\Http\Controllers\Admin\Report\RefundTransactionController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SalesChannels\ChannelOrderController;
use App\Http\Controllers\Admin\Settings\BusinessSettingsController;
use App\Http\Controllers\Admin\Settings\InhouseShopController;
use App\Http\Controllers\Admin\Settings\OrderSettingsController;
use App\Http\Controllers\Admin\Telemetry\AnalyticsController;
use App\Http\Controllers\Admin\TransactionReportController;
use App\Http\Controllers\Admin\V2AdvancedSearchController;
use App\Http\Controllers\Admin\Vendor\VendorController;
use App\Http\Controllers\Admin\VendorProductSaleReportController;
use Modules\TaxModule\app\Http\Controllers\Admin\Reports\AdminTaxReportController;
use Modules\TaxModule\app\Http\Controllers\Admin\Reports\VendorTaxReportController;

/**
 * Every controller of the admin panel that reads orders, their lines, their money records, refunds or the earnings and counters made of them, or names an order, and what a staff
 * country scope does about it (GCC-14c, slice 3). The table is exact, in the way of StaffProductInventory: a controller the scan finds that is not here fails, an entry whose
 * controller no longer reads orders fails, and so does an entry without a reason or with a slice nobody named. The same table is in docs/gcc/GCC-14C-STAFF-COUNTRY-SCOPES.md,
 * and a test keeps the two in step.
 *
 * `scoped`: a slice narrows it (App\Services\Staff\StaffOrderRoutes holds the rules): slice 2 for the order, POS and refund screens, slice 3 for the screens that read orders.
 * `not_needed`: it must keep seeing every order, or it holds no order row. `out`: it still shows every country's orders to a restricted employee; the slice that takes it is
 * named, or `owner` when what it shows cannot be told apart by country and the owner has to decide what an employee may see. `by` names what narrows a controller whose
 * rules are NOT in StaffOrderRoutes: the header search, which narrows what it hands back rather than its query, and the promotion screens, whose own registry carries the
 * order subject for the one page of slice 4 that counts orders.
 */
final class StaffOrderInventory
{
    public const SCOPED = 'scoped';

    public const NOT_NEEDED = 'not_needed';

    public const OUT = 'out';

    /** The slices that follow slice 3, in the order proposed, and the decisions that wait for the owner. */
    public const SLICES = [
        'slice 3' => 'the rest of the order reads, the shared layout, the dashboard and the reports',
        'slice 4' => 'promotions and storefront content: deals, banners, themes, the SEO pages and the site map',
        'slice 5' => 'vendors, reviews and the marketplace operations',
        'slice 6' => 'integrations and costs: sales channels, fulfilment providers and the operational costs',
        'owner' => 'figures that cannot be told apart by country, until the owner says what an employee may see',
    ];

    /** @var array<class-string, array{class: string, slice: string|null, reason: string}> */
    public const CONTROLLERS = [
        OrderController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 2',
            'reason' => 'Every order screen: the lists per status with their filters and counters, the exports, the details with the neighbours of an order, and every action on an order. Every action has a rule in StaffOrderRoutes.',
        ],
        OrderEditController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 2',
            'reason' => 'The order edit: the order is looked up by id, the product picker is narrowed by the product scope and a product of another country is refused. Every action has a rule in StaffOrderRoutes.',
        ],
        DeliverySyriaDispatchController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 2',
            'reason' => 'Dispatches an order to the Delivery Syria courier by its id: an order route like the order screens. Every action has a rule in StaffOrderRoutes.',
        ],
        RefundController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 2',
            'reason' => 'The refund requests: the lists, their exports, the details and the decision follow the order line each request is about. Every action has a rule in StaffOrderRoutes.',
        ],
        POSController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 2',
            'reason' => 'The POS grid lists the products of the employee\'s countries and the filter of the POS orders reads the orders of the scope. Every action has a rule in StaffOrderRoutes.',
        ],
        POSOrderController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 2',
            'reason' => 'Places a POS order only when every product of the cart is the employee\'s, and reads a POS order by id. Every action has a rule in StaffOrderRoutes.',
        ],
        CustomerController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The list of customers counts the orders of each, the screen of one customer lists their orders and counts their refund requests, and the export holds their orders: all in the employee\'s countries. A customer belongs to no country, so the list of customers is not narrowed. Every action has a rule in StaffOrderRoutes.',
        ],
        DeliveryManController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The orders, the earnings per order and the status history of a delivery man, and the status history of an order by its number, in the employee\'s countries. The wallet overview and the ratings of a delivery man are unavailable to a restricted employee. Every action has a rule in StaffOrderRoutes.',
        ],
        VendorController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The orders of a vendor and their export, an order read by its number, the money records of a vendor and the order counts of the list of vendors, in the employee\'s countries. The request is bound for the whole controller, so the product tab of a vendor lists the employee\'s countries too. Every action has a rule in StaffOrderRoutes.',
        ],
        DashboardController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The counters, earnings and graphs, the top lists and the real-time poller of the dashboard read the orders, lines, money records and refunds of the employee\'s countries. The wallets (the admin wallet, the top vendors by earning, the auction wallet) cannot be told apart by country and are not shown to a restricted employee. Every action has a rule in StaffOrderRoutes.',
        ],
        OrderReportController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The order report, its totals and its charts sum the orders of the employee\'s countries. Every action has a rule in StaffOrderRoutes.',
        ],
        ReportController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The earning reports sum the orders, their money records and refunds of the employee\'s countries, and its export by vendor does the same. The vendor earning report is made of the wallets of the vendors and is unavailable to a restricted employee. Every action has a rule in StaffOrderRoutes.',
        ],
        TransactionReportController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The order transaction report lists and sums the money records of the orders of the employee\'s countries. The wallet bonus tab lists the bonuses of customers\' wallets, which carry no order and no country, and is not narrowed. Every action has a rule in StaffOrderRoutes.',
        ],
        ExpenseTransactionReportController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The expense transaction report lists and sums the discounts and delivery expenses of the orders of the employee\'s countries. Every action has a rule in StaffOrderRoutes.',
        ],
        RefundTransactionController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The refund transaction report lists the money records of the refunds about a line of the employee\'s countries. Every action has a rule in StaffOrderRoutes.',
        ],
        VendorProductSaleReportController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The vendor sales report sums the orders, lines and refund money records of the employee\'s countries. Every action has a rule in StaffOrderRoutes.',
        ],
        ProductReportController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The all-products report sums the sales of the order lines of the employee\'s countries, for the products of their countries. Every action has a rule in StaffOrderRoutes.',
        ],
        AnalyticsController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The analytics screens count visits, orders and revenue from rollups that carry no country of the shop, so they cannot be told apart by country: every action is unavailable to a restricted employee until the owner decides what they may see. Every action has a rule in StaffOrderRoutes.',
        ],
        AdminTaxReportController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The tax report and its details and exports sum the taxes of the money records of the orders of the employee\'s countries. Every action has a rule in StaffOrderRoutes.',
        ],
        VendorTaxReportController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The vendor tax reports and their exports sum the orders and taxes of the employee\'s countries; the raw sum over the orders table is narrowed by the same rule. Every action has a rule in StaffOrderRoutes.',
        ],
        ProductController::class => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'Counts the order lines of a product the employee works with: the lines of their own products, narrowed by the product scope of slice 1. It lists no order.',
        ],
        BusinessSettingsController::class => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'The refund setup page is a setting of the shop. It reads no order.',
        ],
        OrderSettingsController::class => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'The order settings page is a setting of the shop. It reads no order.',
        ],
        PurchaseOrderController::class => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'Purchase orders to suppliers are not customer orders: they have no order, no order line and no country of their own.',
        ],
        InhouseShopController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 4', 'by' => 'the promotion screens',
            'reason' => 'The in-house shop page counts the in-house orders. Narrowed in slice 4 by the `list:orders` part of its rule in StaffPromotionRoutes, which is the slice that took the page: an order and a product are two subjects, and one middleware carries both.',
        ],
        FulfillmentController::class => [
            'class' => self::OUT, 'slice' => 'slice 5',
            'reason' => 'The marketplace fulfilments are shipments of orders of every country, picked by order number.',
        ],
        ReturnLogisticsController::class => [
            'class' => self::OUT, 'slice' => 'slice 5',
            'reason' => 'A marketplace return names an order of any country.',
        ],
        ReviewController::class => [
            'class' => self::OUT, 'slice' => 'slice 5',
            'reason' => 'The reviews list reads the order behind a review, of any country.',
        ],
        ProviderFulfillmentController::class => [
            'class' => self::OUT, 'slice' => 'slice 6',
            'reason' => 'The provider fulfilments are shipments of orders of every country.',
        ],
        ChannelOrderController::class => [
            'class' => self::OUT, 'slice' => 'slice 6',
            'reason' => 'A channel order is mapped to an order of any country.',
        ],
        ProfitabilityController::class => [
            'class' => self::OUT, 'slice' => 'slice 6',
            'reason' => 'The profitability of an order of any country, with its costs.',
        ],
        MyFatoorahSettingsController::class => [
            'class' => self::OUT, 'slice' => 'slice 6',
            'reason' => 'Lists the payment attempts and refunds of the gateway, which belong to orders of every country, and acts on an attempt by its id.',
        ],
    ];

    /**
     * Controllers that reach orders or their money only through a service or a trait the scan does not read, found by hand. They are not checked against the scan, only against
     * the route table: a controller that is gone from it fails the guard.
     *
     * @var array<class-string, array{slice: string, reason: string, class?: string}>
     */
    public const READ_THROUGH_SERVICES = [
        'App\Http\Controllers\Admin\V2AdvancedSearchController' => ['class' => self::SCOPED, 'slice' => 'slice 3', 'by' => 'the search', 'reason' => 'The header search of the new layout finds an order or a refund request by its number only when it is the employee\'s, and a product only when it is one of their countries\'. It narrows what it hands back, with no middleware: it fills a cache every employee shares (App\Services\Staff\StaffSearchHits).'],
        'App\Http\Controllers\Admin\AdvancedSearchController' => ['class' => self::SCOPED, 'slice' => 'slice 3', 'by' => 'the search', 'reason' => 'The header search of the classic layout is the same search as the new one (StaffSearchHits): an order or a refund request is found only when it is the employee\'s, with no middleware.'],
        'App\Http\Controllers\Admin\InhouseProductSaleController' => ['class' => self::SCOPED, 'slice' => 'slice 3', 'reason' => 'The in-house sales report counts the sales of the order lines of the employee\'s countries through the product repository, for their products. Every action has a rule in StaffOrderRoutes.'],
        'App\Http\Controllers\Admin\Customer\CustomerWalletController' => ['class' => self::SCOPED, 'slice' => 'slice 3', 'reason' => 'The wallet report and a customer\'s wallet movements. The reason they were the owner\'s to decide -- "they carry no order and no country" -- stopped being true when wallet_transactions gained storefront_country: each row records its own market, so a restricted employee is shown the movements of their markets through StaffCountryLedgerScope. The bonus setup is the platform\'s own configuration and lists nothing. Every action has a rule in StaffOrderRoutes.'],
        'App\Http\Controllers\Admin\Customer\CustomerLoyaltyController' => ['class' => self::SCOPED, 'slice' => 'slice 3', 'reason' => 'A customer\'s loyalty movements, narrowed the same way through loyalty_point_transactions.storefront_country. Every action has a rule in StaffOrderRoutes.'],
        'App\Http\Controllers\Admin\Marketplace\SellerScorecardController' => ['slice' => 'slice 5', 'reason' => 'The scorecard of a seller is made of its orders of every country, through SellerScorecardService.'],
        'App\Http\Controllers\Admin\Marketplace\SettlementController' => ['slice' => 'slice 5', 'reason' => 'The settlements of the sellers are made of their orders of every country, through the vendor ledger.'],
        'App\Http\Controllers\Admin\OperationalCosts\OverviewController' => ['slice' => 'slice 6', 'reason' => 'The overview of the operational costs aggregates the profit of the orders of every country.'],
        'App\Http\Controllers\Admin\SalesChannels\ChannelAccountOperationsController' => ['slice' => 'slice 6', 'reason' => 'Synchronises the orders of a sales channel account, which map to orders of any country.'],
    ];

    /**
     * Controllers a staff scope covers for orders that read no order themselves, so that the survey above does not hold them: the POS cart names a product, not an order.
     *
     * @var array<class-string, array{slice: string, reason: string}>
     */
    public const SCOPED_WITHOUT_ORDER_READS = [
        'App\Http\Controllers\Admin\POS\CartController' => ['slice' => 'slice 2', 'reason' => 'The POS cart takes, prices and counts a product by id and reads no order (StaffOrderRoutes).'],
        'App\Http\Controllers\Admin\ProductStockReportController' => ['slice' => 'slice 3', 'reason' => 'The stock report and its export list the products of the employee\'s countries and read no order (StaffOrderRoutes).'],
        'App\Http\Controllers\Admin\ProductWishlistReportController' => ['slice' => 'slice 3', 'reason' => 'The wishlist report and its export list the products of the employee\'s countries and read no order (StaffOrderRoutes).'],
    ];

    /** @return array<string, int> how many controllers there are of each status: scoped in slice 2, 3 and 4, not needed, out */
    public static function countsByStatus(): array
    {
        $counts = ['slice 2' => 0, 'slice 3' => 0, 'slice 4' => 0, self::NOT_NEEDED => 0, self::OUT => 0];
        foreach (self::CONTROLLERS as $entry) {
            $counts[self::statusKeyOf($entry)]++;
        }

        return $counts;
    }

    /** @param array{class: string, slice: string|null} $entry */
    public static function statusKeyOf(array $entry): string
    {
        return $entry['class'] === self::SCOPED ? (string) $entry['slice'] : $entry['class'];
    }
}
