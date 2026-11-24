<?php

namespace Tests\Support;

/**
 * Everything of the admin side that runs away from the request, and what the employee's country scope has to do about each one (G-11,
 * GCC-14c): the queued jobs, the queued listeners and the bulk routes.
 *
 * It exists because a scope that stops at the controller is not a scope. A request is narrowed to the employee's countries; a queue worker
 * is a console process with no request and no employee, so a job started by a restricted employee runs with no scope at all unless it
 * carries one. The question for each entry is therefore not "is it narrowed" but "is there anything to narrow": a job whose payload names
 * one record reads nothing across countries, and one that rebuilds the whole platform was never an employee's decision to scope -- its
 * control is the permission on the route that starts it.
 *
 * Each class is listed with the category, how an employee can reach it (or that none can), and the reason. A job or listener added to
 * app/Jobs or app/Listeners, and a bulk route added to the admin panel, appears in the scan and fails StaffJobInventoryTest until somebody
 * classifies it here -- which is the point of the list.
 *
 * **Blind spot, stated rather than hidden:** reachability was derived by reading which files fire a listener's events and which dispatch a
 * job, so an event fired inside a service that an employee's screen calls reads as "no admin path" at first glance. The two of those found
 * by hand name the service in their reason.
 */
final class StaffJobInventory
{
    /** Its payload names one record. Nothing is read across countries, so the employee's scope has nothing to narrow. */
    public const ONE_RECORD = 'one record';

    /** It reaches every country by design. The control is the permission on the route that starts it, not a country scope. */
    public const PLATFORM_WIDE = 'platform wide';

    /** No admin path starts it: a customer, a seller, the clock or the queue does. */
    public const NOT_STAFF_STARTED = 'not staff started';

    /** A bulk route another inventory already classifies, named in the reason. */
    public const SCOPED_ELSEWHERE = 'scoped elsewhere';

    /** A bulk route an employee can run across countries with nothing narrowing it: the finding this list exists to surface. */
    public const NOT_YET_SCOPED = 'not yet scoped';

    /** @var array<string, array{class: string, started: string, reason: string}> */
    public const QUEUED = [
        'App\Jobs\DeliverSellerWebhook' => [
            'class' => self::NOT_STAFF_STARTED, 'started' => 'SellerWebhookDispatcher, the seller API and the retry command',
            'reason' => 'Delivers one seller\'s webhook to the URL that seller configured. It belongs to the shop, not to a country of the platform, and no admin screen dispatches it.',
        ],
        'App\Jobs\Fulfillment\CancelProviderFulfillment' => [
            'class' => self::NOT_STAFF_STARTED, 'started' => 'FulfillmentQueue',
            'reason' => 'Dispatched by the fulfilment queue for one shipment. It already carries the storefront of the request that queued it (A1-14, CarriesItsStorefront).',
        ],
        'App\Jobs\Fulfillment\DispatchProviderFulfillment' => [
            'class' => self::NOT_STAFF_STARTED, 'started' => 'FulfillmentQueue',
            'reason' => 'As CancelProviderFulfillment: one shipment, queued by the fulfilment machinery, carrying its storefront.',
        ],
        'App\Jobs\Fulfillment\ProcessProviderEvent' => [
            'class' => self::NOT_STAFF_STARTED, 'started' => 'FulfillmentQueue',
            'reason' => 'One webhook event of one provider, queued when the webhook arrives. No employee starts it.',
        ],
        'App\Jobs\Fulfillment\ReconcileProviderFulfillment' => [
            'class' => self::NOT_STAFF_STARTED, 'started' => 'FulfillmentQueue',
            'reason' => 'One shipment reconciled against its provider, queued by the recovery pass.',
        ],
        'App\Jobs\Fulfillment\RouteOrderFulfillment' => [
            'class' => self::NOT_STAFF_STARTED, 'started' => 'FulfillmentQueue',
            'reason' => 'Splits one order into its provider shipments, queued when the order reaches a dispatchable status.',
        ],
        'App\Jobs\RebuildProductSearchIndex' => [
            'class' => self::PLATFORM_WIDE, 'started' => 'Admin\Telemetry\MonitoringController',
            'reason' => 'Rebuilds the search index of every product of every country. A restricted employee who can reach the monitoring screen rebuilds the whole platform\'s index, which is a platform action and not a country one: narrowing it to their countries would leave the index half built. Its control is the permission on that screen.',
        ],
        'App\Jobs\RunSellerBulkJob' => [
            'class' => self::NOT_STAFF_STARTED, 'started' => 'SellerBulkJobService, from the seller panel',
            'reason' => 'A seller\'s own bulk operation. It is the pattern G-11 names for a staff job -- only the job id travels, and the runner re-reads the principal and the permission when it starts -- but no employee dispatches it.',
        ],
        'App\Jobs\SalesChannels\DrainStockEvents' => [
            'class' => self::NOT_STAFF_STARTED, 'started' => 'StockEventNudge',
            'reason' => 'Drains the stock outbox for the marketplace channels. Queued by the outbox itself when rows appear.',
        ],
        'App\Jobs\SendEmailJob' => [
            'class' => self::NOT_STAFF_STARTED, 'started' => 'the queued listeners, through QueuedMailDelivery',
            'reason' => 'Sends one message to one address. Nothing dispatches it directly; the listeners below do, and the language and sender it renders with are the A1-17 question, not a scope question.',
        ],
        'App\Listeners\AddFundToWalletListener' => [
            'class' => self::ONE_RECORD, 'started' => 'Admin\Customer\CustomerWalletController',
            'reason' => 'Tells one customer their wallet was topped up. The customer is named in the event; the employee\'s right to top them up was decided on the request, by the scope of the wallet screen.',
        ],
        'App\Listeners\CustomerRegistrationListener' => [
            'class' => self::ONE_RECORD, 'started' => 'Admin\Customer\CustomerController',
            'reason' => 'Welcomes the one customer the event names, created on that request.',
        ],
        'App\Listeners\CustomerStatusUpdateListener' => [
            'class' => self::ONE_RECORD, 'started' => 'Admin\Customer\CustomerController',
            'reason' => 'Tells the one customer the event names that their account was blocked or unblocked.',
        ],
        'App\Listeners\DeliverymanPasswordResetListener' => [
            'class' => self::NOT_STAFF_STARTED, 'started' => 'the delivery-man login API',
            'reason' => 'Sends the reset code one delivery man asked for, on their own account. No admin screen fires it.',
        ],
        'App\Listeners\DigitalProductDownloadListener' => [
            'class' => self::ONE_RECORD, 'started' => 'Admin\POS\POSOrderController',
            'reason' => 'Sends the download link of one digital line of one order, the order the request names.',
        ],
        'App\Listeners\DigitalProductOtpVerificationListener' => [
            'class' => self::NOT_STAFF_STARTED, 'started' => 'the customer order API and the website',
            'reason' => 'Sends the one-time code a customer needs to download the digital line they bought. Started by that customer, on their own order.',
        ],
        'App\Listeners\EmailVerificationListener' => [
            'class' => self::NOT_STAFF_STARTED, 'started' => 'the customer sign-up paths',
            'reason' => 'Sends the verification message for the address a customer signed up with. Started by that customer, on their own account.',
        ],
        'App\Listeners\MaintenanceModeNotificationListener' => [
            'class' => self::PLATFORM_WIDE, 'started' => 'BusinessSettingService, which the admin settings screens call',
            'reason' => 'Announces maintenance mode, which is one setting for the whole platform and reaches every country. An employee reaches it through a service rather than by firing the event, which is the blind spot this list states; the decision it carries is a platform one either way.',
        ],
        'App\Listeners\OrderEditDuePaymentListener' => [
            'class' => self::NOT_STAFF_STARTED, 'started' => 'OrderManager',
            'reason' => 'Tells the one customer whose edited order still owes money what it owes. Fired by OrderManager, not by a screen.',
        ],
        'App\Listeners\OrderEditListener' => [
            'class' => self::NOT_STAFF_STARTED, 'started' => 'OrderEditManager',
            'reason' => 'Tells the one customer whose order was edited that it changed. Fired by the edit manager the panels share.',
        ],
        'App\Listeners\OrderEditReturnPaymentListener' => [
            'class' => self::ONE_RECORD, 'started' => 'Admin\Order\OrderController',
            'reason' => 'Tells one customer about the amount returned on the one order the request names.',
        ],
        'App\Listeners\OrderPlacedListener' => [
            'class' => self::NOT_STAFF_STARTED, 'started' => 'OrderManager, at checkout',
            'reason' => 'Confirms the one order the event names to the customer who placed it, fired at checkout.',
        ],
        'App\Listeners\PasswordResetListener' => [
            'class' => self::NOT_STAFF_STARTED, 'started' => 'the customer password-reset paths',
            'reason' => 'Sends the reset link for the account a customer asked about. Started by that customer, on their own account.',
        ],
        'App\Listeners\RequestProductRestockListener' => [
            'class' => self::NOT_STAFF_STARTED, 'started' => 'the cart, on the website and in the API',
            'reason' => 'Records that one customer wants to hear when one product is back in stock. Started by that customer, from the cart.',
        ],
        'App\Listeners\RestockProductNotificationListener' => [
            'class' => self::NOT_STAFF_STARTED, 'started' => 'ProductService, when stock returns',
            'reason' => 'Tells the customers who asked about one product that it is back. The list it reads is the requests for that one product, not a country\'s customers.',
        ],
        'App\Listeners\VendorRegistrationListener' => [
            'class' => self::ONE_RECORD, 'started' => 'Admin\Vendor\VendorController',
            'reason' => 'Welcomes the one seller the event names, created on that request.',
        ],
    ];

    /**
     * The schedule, classified as one thing rather than entry by entry, because one sentence is true of all of it: a scheduled run has no
     * employee and no request, so there is no scope to carry and none to leak. `schedule:run` is a console process; what it starts runs for
     * the platform, every country at once, which is what a settlement sweep or a stock drain is for.
     *
     * What has to stay true for that sentence to hold is asserted rather than asserted-of: the schedule binds no staff scope, and its one
     * non-command entry is a named heartbeat that writes a single setting. A closure added to the schedule could close over anything,
     * including a scope, so a second one fails the test and has to be read by a person.
     */
    public const SCHEDULE_ENTRIES = 45;

    public const SCHEDULE_CLOSURE = 'call: scheduler-heartbeat';

    /** @var array<string, array{class: string, reason: string}> "POST uri" => its classification */
    public const BULK_ROUTES = [
        'POST admin/coupon/bulk-status' => [
            'class' => self::NOT_YET_SCOPED,
            'reason' => 'Switches any list of coupon ids on or off. Coupons carry a storefront (GCC-05, Coupon::storefront_country), so a restricted employee can change another country\'s coupon; nothing narrows the ids and no inventory claims it is narrowed.',
        ],
        'POST admin/customer/bulk-status' => [
            'class' => self::NOT_YET_SCOPED,
            'reason' => 'Blocks or unblocks any list of customer ids. StaffOrderRoutes rates it `none` for the ORDER dimension, which is true and is not the same statement: the customer dimension of slice 2 does not narrow these ids.',
        ],
        'POST admin/marketplace/exchange-rates/bulk-update' => [
            'class' => self::NOT_YET_SCOPED,
            'reason' => 'Writes the marketplace exchange rates. Arguably platform-wide rather than country-owned, which is exactly why it is listed: nobody has decided, and the rates are money.',
        ],
        'POST admin/marketplace/product-moderation/bulk' => [
            'class' => self::SCOPED_ELSEWHERE,
            'reason' => 'StaffProductInventory classifies ProductModerationController as SCOPED: an id route like the product screens, singly or in bulk. StaffProductIdRoutesTest refuses a list holding one foreign or unknown id.',
        ],
        'POST admin/orders/bulk-status' => [
            'class' => self::SCOPED_ELSEWHERE,
            'reason' => 'StaffOrderRoutes rates OrderController::bulkUpdateStatus `order.inputs:ids`, so the list is refused whole when one id is outside the employee\'s countries (StaffOrderRoutesTest).',
        ],
        'POST admin/products/bulk-import' => [
            'class' => self::SCOPED_ELSEWHERE,
            'reason' => 'StaffBulkImportTest: an employee holding one country imports every row into it, and one holding several or none is refused before the sheet is read.',
        ],
        'POST admin/products/bulk-product-status' => [
            'class' => self::SCOPED_ELSEWHERE,
            'reason' => 'StaffProductActionsTest::test_a_bulk_change_is_all_or_nothing: one foreign, unknown or junk id refuses the whole list and touches nothing.',
        ],
        'POST admin/vendors/bulk-status' => [
            'class' => self::NOT_YET_SCOPED,
            'reason' => 'Blocks or unblocks any list of seller ids. A seller is not country-owned today, which may be the answer -- but it is G-12\'s question (a company dimension on staff scopes), and until it is answered this route narrows nothing.',
        ],
    ];
}
