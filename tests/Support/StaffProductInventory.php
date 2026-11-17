<?php

namespace Tests\Support;

use App\Http\Controllers\Admin\Fulfillment\M5aznProductsController;
use App\Http\Controllers\Admin\Fulfillment\TorodMatchingController;
use App\Http\Controllers\Admin\InhouseProductSaleController;
use App\Http\Controllers\Admin\Marketplace\BatchController;
use App\Http\Controllers\Admin\Marketplace\BrandRegistryController;
use App\Http\Controllers\Admin\Marketplace\CommissionRuleController;
use App\Http\Controllers\Admin\Marketplace\InventoryAdjustmentController;
use App\Http\Controllers\Admin\Marketplace\ProductModerationController;
use App\Http\Controllers\Admin\Marketplace\PurchaseOrderController;
use App\Http\Controllers\Admin\Marketplace\ReturnLogisticsController;
use App\Http\Controllers\Admin\Marketplace\WarehouseController;
use App\Http\Controllers\Admin\OperationalCosts\ProductCostController;
use App\Http\Controllers\Admin\OperationalCosts\ReturnController as OperationalCostsReturnController;
use App\Http\Controllers\Admin\Order\OrderController;
use App\Http\Controllers\Admin\Order\OrderEditController;
use App\Http\Controllers\Admin\POS\CartController as PosCartController;
use App\Http\Controllers\Admin\POS\POSController;
use App\Http\Controllers\Admin\POS\POSOrderController;
use App\Http\Controllers\Admin\Product\BrandController;
use App\Http\Controllers\Admin\Product\CategoryController;
use App\Http\Controllers\Admin\Product\ProductController;
use App\Http\Controllers\Admin\Product\ReviewController;
use App\Http\Controllers\Admin\ProductReportController;
use App\Http\Controllers\Admin\ProductStockReportController;
use App\Http\Controllers\Admin\ProductWishlistReportController;
use App\Http\Controllers\Admin\Promotion\BannerController;
use App\Http\Controllers\Admin\Promotion\ClearanceSaleController;
use App\Http\Controllers\Admin\Promotion\DealOfTheDayController;
use App\Http\Controllers\Admin\Promotion\FlashDealController;
use App\Http\Controllers\Admin\Promotion\MostDemandedController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SalesChannels\ChannelAccountOperationsController;
use App\Http\Controllers\Admin\SalesChannels\ChannelInventoryController;
use App\Http\Controllers\Admin\SalesChannels\ChannelListingController;
use App\Http\Controllers\Admin\SalesChannels\ChannelOrderController;
use App\Http\Controllers\Admin\SalesChannels\ChannelProductController;
use App\Http\Controllers\Admin\SalesChannels\ChannelRoutingController;
use App\Http\Controllers\Admin\Settings\BusinessSettingsController;
use App\Http\Controllers\Admin\Settings\InhouseShopController;
use App\Http\Controllers\Admin\Settings\SeoHealthController;
use App\Http\Controllers\Admin\Settings\SeoTranslationController;
use App\Http\Controllers\Admin\Settings\SiteMapController;
use App\Http\Controllers\Admin\Settings\ThemeBuilderController;
use App\Http\Controllers\Admin\TransactionReportController;
use App\Http\Controllers\Admin\Vendor\VendorController;
use App\Http\Controllers\Admin\VendorProductSaleReportController;
use App\Http\Controllers\Admin\DashboardController;
use Modules\AI\app\Http\Controllers\Admin\AIProductController;
use Modules\AI\app\Http\Controllers\API\V3\AIAuctionProductController;

/**
 * Every controller of the admin panel that reads products or names one, and what a staff country scope does about it (GCC-14c). The table is exact, in the way of the
 * lookup table of the country-scoped codes: a controller the scan finds that is not here fails, an entry whose controller no longer reads products fails, and so
 * does an entry without a reason or with a slice nobody named. The same table is in docs/gcc/GCC-14C-STAFF-COUNTRY-SCOPES.md, and a test keeps the two in step.
 *
 * `scoped`: a slice narrows it, slice 1 when it names none (App\Services\Staff\StaffProductRoutes holds the rules of the product screens) and slice 2 for the order, POS and
 * refund screens (App\Services\Staff\StaffOrderRoutes). `not_needed`: it must keep seeing every country, or it has no product rows. `out`: it still shows every country's
 * products to a restricted employee; the slice that takes it is named.
 */
final class StaffProductInventory
{
    public const SCOPED = 'scoped';

    public const NOT_NEEDED = 'not_needed';

    public const OUT = 'out';

    /** The slices that follow slice 2 (the order, POS and refund screens), in the order proposed; slice 3 is the one that is being taken. */
    public const SLICES = [
        'slice 3' => 'reports, the dashboard counters and the counters of the shared layout',
        'slice 4' => 'promotions and storefront content: deals, banners, themes, the SEO pages and the site map',
        'slice 5' => 'vendors, reviews and the marketplace operations',
        'slice 6' => 'integrations and costs: sales channels, fulfilment providers and the operational costs',
    ];

    /** @var array<class-string, array{class: string, slice: string|null, reason: string}> */
    public const CONTROLLERS = [
        ProductController::class => [
            'class' => self::SCOPED, 'slice' => null,
            'reason' => 'The product screens: lists, counters, pickers, exports, the add and edit forms, and every action on a product. Every action has a rule in StaffProductRoutes.',
        ],
        ProductModerationController::class => [
            'class' => self::SCOPED, 'slice' => null,
            'reason' => 'Approve, reject or suspend a seller product by its id, singly or in bulk, and read its history: an id route like the product screens.',
        ],
        BrandController::class => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'Reads the products of a brand only to refuse deleting a brand that is in use. The answer must count every country\'s products, or a brand a product of another country uses could be deleted.',
        ],
        CategoryController::class => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'As the brands: the products of a category are read only to refuse deleting or moving a category that is in use, and that count must include every country.',
        ],
        BusinessSettingsController::class => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'The product settings page is a setting of the shop (brands, digital products, approval). It reads no product row.',
        ],
        AIProductController::class => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'Writes a title, a description or the SEO text of a product from what is typed in the form. It reads no product row.',
        ],
        AIAuctionProductController::class => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'As the product text generation, for the auction add-on, whose products are not in the products table.',
        ],
        ChannelAccountOperationsController::class => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'The only route that mentions a product is the Noon product group of a channel account, a setting of the account. It reads no product row.',
        ],
        SeoTranslationController::class => [
            'class' => self::OUT, 'slice' => 'slice 4',
            'reason' => 'Lists the products whose SEO text can be translated: every country\'s.',
        ],
        FlashDealController::class => [
            'class' => self::OUT, 'slice' => 'slice 4',
            'reason' => 'The deal pages pick products and list the ones in a deal: every country\'s. A promotion has no country yet (GCC-17).',
        ],
        DealOfTheDayController::class => [
            'class' => self::OUT, 'slice' => 'slice 4',
            'reason' => 'Picks the product of the day among every country\'s products.',
        ],
        ClearanceSaleController::class => [
            'class' => self::OUT, 'slice' => 'slice 4',
            'reason' => 'Picks, lists and removes clearance products of every country.',
        ],
        MostDemandedController::class => [
            'class' => self::OUT, 'slice' => 'slice 4',
            'reason' => 'Picks the products of the most demanded section among every country\'s.',
        ],
        BannerController::class => [
            'class' => self::OUT, 'slice' => 'slice 4',
            'reason' => 'A banner can point at a product, picked among every country\'s.',
        ],
        InhouseShopController::class => [
            'class' => self::OUT, 'slice' => 'slice 4',
            'reason' => 'The in-house shop page lists the in-house products of every country.',
        ],
        ThemeBuilderController::class => [
            'class' => self::OUT, 'slice' => 'slice 4',
            'reason' => 'The pickers of the theme builder list and name products of every country.',
        ],
        SeoHealthController::class => [
            'class' => self::OUT, 'slice' => 'slice 4',
            'reason' => 'Audits the SEO of the products a storefront shows, of every country.',
        ],
        SiteMapController::class => [
            'class' => self::OUT, 'slice' => 'slice 4',
            'reason' => 'The site map lists the active products of every country.',
        ],
        OrderController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 2',
            'reason' => 'Every order screen: the lists per status with their filters and counters, the exports, the details with the neighbours of an order, and every action on an order (status, payment, address, courier, invoice, bulk change). Every action has a rule in StaffOrderRoutes.',
        ],
        OrderEditController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 2',
            'reason' => 'The order edit: the product picker is narrowed by the product scope, the order is looked up by id and a product of another country is refused. Every action has a rule in StaffOrderRoutes.',
        ],
        POSController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 2',
            'reason' => 'The POS grid and search offer the products of the employee\'s countries (the product scope), and a product of another country is refused by id. Every action has a rule in StaffOrderRoutes.',
        ],
        PosCartController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 2',
            'reason' => 'The POS cart takes, prices and counts a product by id: a product of another country is refused. Every action has a rule in StaffOrderRoutes.',
        ],
        POSOrderController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 2',
            'reason' => 'Places a POS order only when every product of the cart is the employee\'s, and reads a POS order by id. Every action has a rule in StaffOrderRoutes.',
        ],
        DashboardController::class => [
            'class' => self::OUT, 'slice' => 'slice 3',
            'reason' => 'Counters of products, stock and restock requests across every country.',
        ],
        ReportController::class => [
            'class' => self::OUT, 'slice' => 'slice 3',
            'reason' => 'The earning and product counts of the reports are not split by country.',
        ],
        ProductReportController::class => [
            'class' => self::OUT, 'slice' => 'slice 3',
            'reason' => 'The all-products report and its export list every country\'s products.',
        ],
        ProductStockReportController::class => [
            'class' => self::OUT, 'slice' => 'slice 3',
            'reason' => 'The stock report and its export list every country\'s products.',
        ],
        ProductWishlistReportController::class => [
            'class' => self::OUT, 'slice' => 'slice 3',
            'reason' => 'The wishlist report and its export list every country\'s products.',
        ],
        InhouseProductSaleController::class => [
            'class' => self::OUT, 'slice' => 'slice 3',
            'reason' => 'The in-house sales report lists every country\'s products.',
        ],
        TransactionReportController::class => [
            'class' => self::OUT, 'slice' => 'slice 3',
            'reason' => 'The transaction report counts the products of every country.',
        ],
        VendorProductSaleReportController::class => [
            'class' => self::OUT, 'slice' => 'slice 3',
            'reason' => 'The vendor sales report lists the products of every country.',
        ],
        ReviewController::class => [
            'class' => self::OUT, 'slice' => 'slice 5',
            'reason' => 'The reviews list and its product filter show the reviews of every country\'s products.',
        ],
        VendorController::class => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The request is bound for the whole controller (it lists orders), so a vendor\'s product tab lists the products of the employee\'s countries only. Every action has a rule in StaffOrderRoutes.',
        ],
        BatchController::class => [
            'class' => self::OUT, 'slice' => 'slice 5',
            'reason' => 'Batches of a marketplace product, picked by id from any country.',
        ],
        BrandRegistryController::class => [
            'class' => self::OUT, 'slice' => 'slice 5',
            'reason' => 'Counts the products of each brand across every country.',
        ],
        CommissionRuleController::class => [
            'class' => self::OUT, 'slice' => 'slice 5',
            'reason' => 'A commission rule can name a product of any country.',
        ],
        InventoryAdjustmentController::class => [
            'class' => self::OUT, 'slice' => 'slice 5',
            'reason' => 'Adjusts the stock of a product by id, of any country.',
        ],
        PurchaseOrderController::class => [
            'class' => self::OUT, 'slice' => 'slice 5',
            'reason' => 'A purchase order line names a product of any country.',
        ],
        ReturnLogisticsController::class => [
            'class' => self::OUT, 'slice' => 'slice 5',
            'reason' => 'A return names a product of any country.',
        ],
        WarehouseController::class => [
            'class' => self::OUT, 'slice' => 'slice 5',
            'reason' => 'Places and transfers the stock of a product by id, of any country.',
        ],
        M5aznProductsController::class => [
            'class' => self::OUT, 'slice' => 'slice 6',
            'reason' => 'Imports provider items as products in the default country, and lists the ones already mapped.',
        ],
        TorodMatchingController::class => [
            'class' => self::OUT, 'slice' => 'slice 6',
            'reason' => 'Matches provider items to the products of the default storefront\'s country when codes are per country; otherwise every country\'s.',
        ],
        ChannelProductController::class => [
            'class' => self::OUT, 'slice' => 'slice 6',
            'reason' => 'Links a channel listing to a product picked among every country\'s.',
        ],
        ChannelListingController::class => [
            'class' => self::OUT, 'slice' => 'slice 6',
            'reason' => 'Drafts and publishes the listing of a product by id, of any country.',
        ],
        ChannelInventoryController::class => [
            'class' => self::OUT, 'slice' => 'slice 6',
            'reason' => 'Lists the stock a channel holds for the products of every country.',
        ],
        ChannelOrderController::class => [
            'class' => self::OUT, 'slice' => 'slice 6',
            'reason' => 'A channel order\'s lines are matched to products of any country.',
        ],
        ChannelRoutingController::class => [
            'class' => self::OUT, 'slice' => 'slice 6',
            'reason' => 'A routing rule can name a product of any country.',
        ],
        ProductCostController::class => [
            'class' => self::OUT, 'slice' => 'slice 6',
            'reason' => 'The cost layer picker offers the products of every country.',
        ],
        OperationalCostsReturnController::class => [
            'class' => self::OUT, 'slice' => 'slice 6',
            'reason' => 'The returns of the operational costs name products of any country.',
        ],
    ];

    /**
     * Controllers that reach products only through a service the scan does not read, found by hand. They are not checked against the scan, only against the route table:
     * a controller that is gone from it fails the guard. One that a slice has scoped says so with `class`.
     *
     * @var array<class-string, array{slice: string, reason: string, class?: string}>
     */
    public const READ_THROUGH_SERVICES = [
        'App\Http\Controllers\Admin\AdvancedSearchController' => ['slice' => 'slice 3', 'reason' => 'The header search of the classic layout finds products of every country through AdvancedSearchService.'],
        'App\Http\Controllers\Admin\V2AdvancedSearchController' => ['slice' => 'slice 3', 'reason' => 'The header search of the new layout finds products of every country through the same service.'],
        'App\Http\Controllers\Admin\Commerce\CollectionController' => ['slice' => 'slice 4', 'reason' => 'The preview of a collection resolves its products through CollectionResolver.'],
        'App\Http\Controllers\Admin\Order\RefundController' => ['class' => self::SCOPED, 'slice' => 'slice 2', 'reason' => 'A refund request follows the country of the product of the order line it is about. The refund lists, their exports, the details and the decision on a request go through the scope of the refund requests and the rules of StaffOrderRoutes.'],
        'App\Http\Controllers\Admin\Telemetry\AnalyticsController' => ['slice' => 'slice 3', 'reason' => 'The analytics export by product counts the products of every country.'],
    ];

    /**
     * Controllers a staff scope covers that read no product, so that the survey above does not hold them: an order screen names an order, not a product.
     *
     * @var array<class-string, array{slice: string, reason: string}>
     */
    public const SCOPED_WITHOUT_PRODUCTS = [
        'App\Http\Controllers\Admin\Order\DeliverySyriaDispatchController' => ['slice' => 'slice 2', 'reason' => 'Dispatches an order to the Delivery Syria courier by its id: an order route like the order screens (StaffOrderRoutes). It reads no product.'],
        'App\Http\Controllers\Admin\Customer\CustomerController' => ['slice' => 'slice 3', 'reason' => 'Lists the orders and refund requests of a customer and counts the orders of each customer (StaffOrderRoutes). It reads no product.'],
        'App\Http\Controllers\Admin\Deliveryman\DeliveryManController' => ['slice' => 'slice 3', 'reason' => 'Lists the orders, the earnings and the status history of a delivery man (StaffOrderRoutes). It reads no product.'],
    ];

    /** @return array<string, int> how many controllers there are of each status: scoped in slice 1, 2 and 3, not needed, out */
    public static function countsByStatus(): array
    {
        $counts = ['slice 1' => 0, 'slice 2' => 0, 'slice 3' => 0, self::NOT_NEEDED => 0, self::OUT => 0];
        foreach (self::CONTROLLERS as $entry) {
            $counts[self::statusKeyOf($entry)]++;
        }

        return $counts;
    }

    /** @param array{class: string, slice: string|null} $entry */
    public static function statusKeyOf(array $entry): string
    {
        return $entry['class'] === self::SCOPED ? ($entry['slice'] === null ? 'slice 1' : $entry['slice']) : $entry['class'];
    }
}
