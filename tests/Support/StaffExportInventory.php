<?php

namespace Tests\Support;

/**
 * Every admin route that hands the employee a FILE, and what that file holds (GCC-14c, G-10).
 *
 * An export is the one place where a narrowing that is almost right is worth nothing. The rows leave the panel, and nobody opens a
 * downloaded spreadsheet again to ask which markets it held: whatever is in it is in it, in somebody's mailbox, for good. So the rule for
 * this table is narrower than for the two surveys of screens -- a controller is `scoped` only when the file its export route produces holds
 * the employee's rows, and when part of a controller's exports are narrowed and part are not, the entry says exactly which, instead of
 * being called scoped.
 *
 * `scoped`: a slice narrows the rows that go into the file. `not_needed`: the file holds nothing that belongs to a market -- a brand list, a
 * category list, the platform's own API documents -- or the route is not an export at all and was found by the scan because its path says
 * `download` (two settings pages are in that state, and they say so). `out`: the file still holds every market's rows, and the slice,
 * the screen family or the owner decision that will settle it is named.
 */
final class StaffExportInventory
{
    public const SCOPED = 'scoped';

    public const NOT_NEEDED = 'not_needed';

    public const OUT = 'out';

    /** Where an export that is still out of every slice belongs. */
    public const SLICES = [
        'slice 4' => 'the coupon list, its export and the create form: the three gaps the coupon rule table already names',
        'slice 6' => 'integrations and costs: sales channels, fulfilment providers and the operational costs',
        'staff' => 'the employee, role, audit-log and integration-secret screens, which neither survey of screens holds (G-09)',
        'uploads' => 'the files the panel serves by NAME rather than by row (M1-09 stage 4)',
        'owner' => 'figures that cannot be told apart by country, until the owner says what a restricted employee may see (decision 15)',
    ];

    /** @var array<class-string|string, array{class: string, slice: string|null, reason: string}> */
    public const CONTROLLERS = [
        'App\Http\Controllers\Admin\Customer\CustomerController' => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The orders of one customer and the customer list are narrowed through the orders (`list:orders` in StaffOrderRoutes), so both files hold the employee\'s orders only. The SUBSCRIBER export is the exception and is not narrowed: a newsletter subscriber is an e-mail address with no order and no country, so there is nothing to narrow it by -- its rule says `none` and this entry says so rather than implying the whole controller is covered.',
        ],
        'App\Http\Controllers\Admin\Customer\CustomerLoyaltyController' => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The loyalty ledger export reads `loyalty_point_transactions`, whose rows each record the market they belong to (StaffCountryLedgerScope), so the file holds the employee\'s markets.',
        ],
        'App\Http\Controllers\Admin\Customer\CustomerWalletController' => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The wallet ledger export reads `wallet_transactions`, narrowed the same way by the market each row records.',
        ],
        'App\Http\Controllers\Admin\Deliveryman\DeliveryManController' => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The delivery-man list and the order-history export both read orders (`list:orders`), so each file holds the employee\'s orders only.',
        ],
        'App\Http\Controllers\Admin\Deliveryman\DeliverymanWithdrawController' => [
            'class' => self::OUT, 'slice' => 'owner',
            'reason' => 'A delivery man\'s withdrawal names no order and no country: it is money paid to a person for work across whatever markets they delivered in, and splitting it is the owner\'s decision 15 rather than a narrowing anybody can write today.',
        ],
        'App\Http\Controllers\Admin\Employee\CustomRoleController' => [
            'class' => self::OUT, 'slice' => 'staff',
            'reason' => 'The roles of the panel. A role is the platform\'s own authorization and belongs to no market, but the file names every employee who holds each role, which is a staff screen nobody has scoped: G-09\'s third scan.',
        ],
        'App\Http\Controllers\Admin\Employee\EmployeeController' => [
            'class' => self::OUT, 'slice' => 'staff',
            'reason' => 'The employee list export names every employee of the platform, their role and their contact details, to an employee restricted to one market. The same third scan.',
        ],
        'App\Http\Controllers\Admin\ExpenseTransactionReportController' => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The three expense files (the per-order PDF, the spreadsheet and the summary PDF) read the orders and their money records, each narrowed by its rule in StaffOrderRoutes.',
        ],
        'App\Http\Controllers\Admin\Fulfillment\LiveVerificationController' => [
            'class' => self::OUT, 'slice' => 'slice 6',
            'reason' => 'Downloads the log of a live verification run against a courier, which names the orders it tried: a fulfilment-provider screen, slice 6.',
        ],
        'App\Http\Controllers\Admin\OperationalCosts\ProfitabilityController' => [
            'class' => self::SCOPED, 'slice' => 'slice 6',
            'reason' => 'The profit list and its export are settled in slice 6 by a REFUSAL rather than a narrowing (`unavailable` in StaffCostRoutes, both actions): the cost rollup behind them has no market dimension at all, so narrowing the page while its export handed over every market -- the shape G-10 exists to stop -- was refused instead. A restricted employee is answered like for a page that does not exist; an unrestricted one downloads the file as before.',
        ],
        'App\Http\Controllers\Admin\OperationalCosts\ReportController' => [
            'class' => self::OUT, 'slice' => 'slice 6',
            'reason' => 'The operational-cost reports stream every country\'s costs and the orders they are charged against.',
        ],
        'App\Http\Controllers\Admin\OrderReportController' => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The order report\'s spreadsheet and PDF read the orders and their money records of the employee\'s countries.',
        ],
        'App\Http\Controllers\Admin\Order\OrderController' => [
            'class' => self::SCOPED, 'slice' => 'slice 2',
            'reason' => 'The order export per status is narrowed by the scope of the orders, and the digital FILE of a sold line is judged by the order line it belongs to (`line.route:id`): a restricted employee cannot download the file of another market\'s sale.',
        ],
        'App\Http\Controllers\Admin\Order\RefundController' => [
            'class' => self::SCOPED, 'slice' => 'slice 2',
            'reason' => 'The refund export per status reads the refund requests, narrowed through the order line each is about.',
        ],
        'App\Http\Controllers\Admin\ProductReportController' => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The all-product spreadsheet counts the order lines of the employee\'s countries.',
        ],
        'App\Http\Controllers\Admin\ProductStockReportController' => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The stock export lists the products of the employee\'s countries.',
        ],
        'App\Http\Controllers\Admin\ProductWishlistReportController' => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The wishlist export lists the products of the employee\'s countries.',
        ],
        'App\Http\Controllers\Admin\Product\BrandController' => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'A brand is global by construction -- one brand, every market -- so its export holds nothing that belongs to a market.',
        ],
        'App\Http\Controllers\Admin\Product\CategoryController' => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'The category tree is shared by every market (GCC-14a), so its export has no country to narrow by.',
        ],
        'App\Http\Controllers\Admin\Product\SubCategoryController' => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'The same shared tree, one level down: a sub-category belongs to every market that sells under its parent.',
        ],
        'App\Http\Controllers\Admin\Product\SubSubCategoryController' => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'The same shared tree, two levels down, and for the same reason: no market owns a branch of it.',
        ],
        'App\Http\Controllers\Admin\Product\ProductController' => [
            'class' => self::SCOPED, 'slice' => 'slice 1',
            'reason' => 'The product spreadsheet and the restock export read the narrowed product list, and the two digital-FILE downloads are judged by the product (`route:id`) and by the variation\'s product (`variation:id`) -- so the file of another market\'s product is answered like one that does not exist.',
        ],
        'App\Http\Controllers\Admin\Product\ReviewController' => [
            'class' => self::SCOPED, 'slice' => 'slice 5',
            'reason' => 'The reviews export is narrowed through the product each review is about (StaffCountryProductRowScope on Review), and the product filter of the export is judged by the product it names.',
        ],
        'App\Http\Controllers\Admin\Promotion\CouponController' => [
            'class' => self::OUT, 'slice' => 'slice 4',
            'reason' => 'A coupon HAS a country (GCC-05) and every action that names one is scoped, but the coupon list, its export and the create form are not: coupons have no query scope of their own. The export is one of the three gaps the coupon rule table names as `list_not_narrowed`, so the file still holds every market\'s coupons.',
        ],
        'App\Http\Controllers\Admin\ReportController' => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The admin-earning spreadsheet, its duration PDF and the vendor-earning spreadsheet read the orders, their money records and the refund money records of the employee\'s countries. The vendor-earning SCREEN is `unavailable` to a restricted employee because its figure cannot be split by country; its export reads the narrowed rows.',
        ],
        'App\Http\Controllers\Admin\Report\RefundTransactionController' => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The refund-transaction export and its summary PDF read the money records of refunds about a line of the employee\'s countries.',
        ],
        'App\Http\Controllers\Admin\Settings\FileManagerController' => [
            'class' => self::OUT, 'slice' => 'uploads',
            'reason' => 'Serves a file of the storage by its NAME, with no row behind it to carry a market: the one export on this list that a country scope cannot reach even in principle. It belongs with the upload surface (M1-09 stage 4), where what may be served at all is the question.',
        ],
        'App\Http\Controllers\Admin\Settings\SiteMapController' => [
            'class' => self::SCOPED, 'slice' => 'slice 4',
            'reason' => 'Settled by a REFUSAL in slice 4: the bare site-map file lists the default storefront\'s product addresses and the rest is one file per live market, so downloading it hands over another market\'s addresses. Every action of the screen answers a restricted employee like something that does not exist (`unavailable`).',
        ],
        'App\Http\Controllers\Admin\Settings\ThemeManagementController' => [
            'class' => self::OUT, 'slice' => 'slice 6',
            'reason' => 'Exports a theme version, and a theme belongs to a storefront (`themes.storefront_country`, F-01). Slice 4 scoped the theme BUILDER through that column; this screen is not among its five controllers, so the export is still every market\'s.',
        ],
        'App\Http\Controllers\Admin\Settings\VendorRegistrationSettingController' => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'Not an export: `download-app` is the settings page that holds the links to the seller app, and the POST saves them. The scan found it because its path says `download`, which is exactly the kind of false positive the reason column exists to record.',
        ],
        'App\Http\Controllers\Admin\Telemetry\AnalyticsController' => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'Refused rather than narrowed: the analytics rollup has no market dimension at all, so every figure in it is a platform total. Its export is `unavailable` to a restricted employee.',
        ],
        'App\Http\Controllers\Admin\Telemetry\DeveloperPortalController' => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'The OpenAPI and Postman documents of the platform\'s own API. They describe routes, not rows, and name no market.',
        ],
        'App\Http\Controllers\Admin\TransactionReportController' => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The three transaction files read the orders, their lines and their money records of the employee\'s countries.',
        ],
        'App\Http\Controllers\Admin\VendorProductSaleReportController' => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The vendor sales export reads the orders and the refund money records of the employee\'s countries.',
        ],
        'App\Http\Controllers\Admin\Vendor\VendorController' => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The orders of one vendor and the vendor list are narrowed through the orders. The WITHDRAW export is not, and the rule says so (`none`): a vendor\'s withdrawal carries no country, the same open question as the delivery man\'s, so this entry names which of its three files is still every market\'s.',
        ],
        'Modules\Blog\app\Http\Controllers\Admin\BlogDownloadAppController' => [
            'class' => self::NOT_NEEDED, 'slice' => null,
            'reason' => 'Not an export either: the blog\'s app-download block, a settings page found by the same path reading.',
        ],
        'Modules\TaxModule\app\Http\Controllers\Admin\Reports\AdminTaxReportController' => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The two tax files sum the taxes of the orders of the employee\'s countries.',
        ],
        'Modules\TaxModule\app\Http\Controllers\Admin\Reports\VendorTaxReportController' => [
            'class' => self::SCOPED, 'slice' => 'slice 3',
            'reason' => 'The two vendor tax files sum the orders and taxes of the employee\'s countries.',
        ],
        'Modules\TaxModule\app\Http\Controllers\TaxVatController' => [
            'class' => self::OUT, 'slice' => 'owner',
            'reason' => 'Exports the VAT rates of the platform. A tax rate has no market of its own yet, and which rate belongs to which market is unit H\'s open Q-11 rather than a narrowing.',
        ],
    ];

    /** @return array<string, int> how many controllers there are of each status */
    public static function countsByStatus(): array
    {
        $counts = [self::SCOPED => 0, self::NOT_NEEDED => 0, self::OUT => 0];
        foreach (self::CONTROLLERS as $entry) {
            $counts[$entry['class']]++;
        }

        return $counts;
    }
}
