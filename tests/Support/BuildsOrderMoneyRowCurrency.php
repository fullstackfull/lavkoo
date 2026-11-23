<?php

namespace Tests\Support;

use App\Http\Controllers\RestAPI\v3\seller\OrderController as SellerOrderController;
use App\Http\Controllers\RestAPI\v3\seller\POSController;
use App\Http\Controllers\Vendor\Order\OrderEditController;
use App\Models\Order;
use App\Models\Seller;
use App\Models\User;
use App\Services\Storefront\OrderStorefrontSnapshot;
use App\Services\Storefront\PaymentStorefront;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The world of the money rows of an order (D1-06): the order currency world of {@see BuildsOrderCurrencySurfaces}, the two refund tables and the
 * few columns the refund routes read, and the migration that appends a currency to all four.
 *
 * It is a world, not a measurement. Nothing here writes a currency onto a money row, and nothing sets one on an order: every figure a test reads
 * was put there by a real route. The migration is run separately so that a test can place the same order with the columns absent and compare.
 */
trait BuildsOrderMoneyRowCurrency
{
    /** The seller whose till places a sale, and whose id an edited line is saved under. */
    private const TILL_SELLER = 1;

    /** The Kuwaiti product of the storefront catalog, and the second one an edit adds a line for. */
    private const KUWAITI_CATALOG_PRODUCT = 3;

    private const SECOND_KUWAITI_CATALOG_PRODUCT = 7;

    /**
     * What the refund and delivery routes read besides the order: the two refund tables, the status trail, the line's own refund and stock
     * flags, and the wallets a shop has before it takes its first order.
     */
    protected function createRefundWorld(): void
    {
        $this->seedThePlatformWallets();

        if (!Schema::hasTable('refund_requests')) {
            Schema::create('refund_requests', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('order_details_id');
                $table->unsignedBigInteger('customer_id');
                $table->string('status');
                $table->tinyInteger('approved_count')->default(0);
                $table->tinyInteger('denied_count')->default(0);
                $table->decimal('amount', 21, 12)->default(0);
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('order_id');
                $table->longText('refund_reason');
                $table->text('images')->nullable();
                $table->longText('approved_note')->nullable();
                $table->longText('rejected_note')->nullable();
                $table->longText('payment_info')->nullable();
                $table->string('change_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('refund_transactions')) {
            Schema::create('refund_transactions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->string('payment_for')->nullable();
                $table->unsignedBigInteger('payer_id')->nullable();
                $table->unsignedBigInteger('payment_receiver_id')->nullable();
                $table->string('paid_by')->nullable();
                $table->string('paid_to')->nullable();
                $table->string('payment_method')->nullable();
                $table->string('payment_status')->nullable();
                $table->decimal('amount', 21, 12)->default(0);
                $table->string('transaction_type')->nullable();
                $table->unsignedBigInteger('order_details_id')->nullable();
                $table->unsignedBigInteger('refund_id')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('refund_statuses')) {
            Schema::create('refund_statuses', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('refund_request_id')->nullable();
                $table->string('change_by')->nullable();
                $table->unsignedBigInteger('change_by_id')->nullable();
                $table->string('status')->nullable();
                $table->longText('message')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('order_details_rewards')) {
            Schema::create('order_details_rewards', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('order_details_id');
                $table->string('reward_type')->nullable();
                $table->json('reward_details')->nullable();
                $table->decimal('reward_amount', 18, 12)->nullable()->default(0);
                $table->tinyInteger('reward_delivered')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasColumn('order_details', 'refund_request')) {
            Schema::table('order_details', function (Blueprint $table): void {
                $table->integer('refund_request')->default(0);
                $table->timestamp('refund_started_at')->nullable();
                // 1, as the real column defaults: a line of a placed order has already taken its stock, and
                // the delivery route's conditional flip is written to find it that way. Defaulting it to 0
                // would send that route down the branch that takes the stock a second time.
                $table->boolean('is_stock_decreased')->default(true);
                // Written by the order edit, which saves a line of its own on an order that is already placed.
                $table->string('digital_file_after_sell')->nullable();
            });
        }

        if (DB::table('sellers')->where('id', self::TILL_SELLER)->doesntExist()) {
            DB::table('sellers')->insert(['id' => self::TILL_SELLER, 'status' => 'approved']);
        }

        // A seller has a shop before they take their first order, and the writer that records a handed-over
        // cash sale reads it to know which shop the money belongs to.
        DB::table('shops')->updateOrInsert(['seller_id' => self::TILL_SELLER], ['author_type' => 'vendor', 'name' => 'The till shop']);
    }

    /**
     * Puts one catalog product in the till seller's own hands, which is what the seller app's point of sale requires of anything it sells
     * (`Product::ofSeller` binds both `added_by` and `user_id`). The product's country is untouched, so the money the till sells in is still
     * the money of that product's storefront.
     */
    private function giveTheTillItsOwnStock(int $productId): void
    {
        DB::table('products')->where('id', $productId)->update(['added_by' => 'seller', 'user_id' => self::TILL_SELLER]);
    }

    /** The wallets of the shop and of its one in-house seller, which exist in a running shop before any order is placed. */
    private function seedThePlatformWallets(): void
    {
        if (!Schema::hasTable('seller_wallets')) {
            Schema::create('seller_wallets', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('seller_id')->nullable();
                $table->decimal('withdrawn', 16, 2)->default(0);
                $table->decimal('commission_given', 16, 2)->default(0);
                $table->decimal('total_earning', 16, 2)->default(0);
                $table->decimal('pending_withdraw', 16, 2)->default(0);
                $table->decimal('delivery_charge_earned', 16, 2)->default(0);
                $table->decimal('collected_cash', 16, 2)->default(0);
                $table->decimal('total_tax_collected', 16, 2)->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasColumn('referral_customers', 'delivered_notify')) {
            Schema::table('referral_customers', fn (Blueprint $table) => $table->boolean('delivered_notify')->default(false));
        }

        DB::table('admin_wallets')->updateOrInsert(['admin_id' => 1], ['inhouse_earning' => 1000, 'commission_earned' => 0, 'pending_amount' => 0]);
        DB::table('seller_wallets')->updateOrInsert(['seller_id' => 1], ['total_earning' => 1000]);
    }

    /** Adds the currency columns the way the migration adds them, which is the only way they are ever added. */
    protected function runOrderMoneyRowCurrencyMigration(): void
    {
        (require database_path('migrations/2026_11_27_100000_add_order_currency_to_order_money_rows.php'))->up();
    }

    /** The database as it is before the migration: the two columns are dropped from every money-row table that has them. */
    protected function dropOrderMoneyRowCurrencyColumns(): void
    {
        foreach (OrderStorefrontSnapshot::MONEY_ROW_TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'currency_code')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn(['currency_code', 'currency_rate']));
            }
        }
    }

    /**
     * Marks an order delivered the way an administrator does, from the panel's own route. A cash-on-delivery order has no payment transaction
     * until it is delivered: the checkout skips the transaction for cash and offline orders, and the row is written when the money is in.
     */
    protected function deliverTheOrder(object $order): void
    {
        $this->openTheAdminPanel();

        $this->postJson(route('admin.orders.status'), ['id' => $order->id, 'order_status' => 'delivered'])->assertOk();
    }

    /** Files a refund on an order's line the way the customer's app files one, and returns the row the route saved. */
    protected function fileRefundOn(object $order, User $customer): object
    {
        $line = DB::table('order_details')->where('order_id', $order->id)->orderBy('id')->first();

        $this->asCustomer($customer)
            ->postJson('/api/v1/customer/order/refund-store', [
                'order_details_id' => $line->id,
                // Required by the route's own rules and then ignored by it: the amount it records is the
                // one it works out from the line. Whatever is passed here cannot decide the currency.
                'amount' => $line->price,
                'refund_reason' => 'Damaged on arrival',
            ])
            ->assertOk();
        $this->flushHeaders();

        return DB::table('refund_requests')->latest('id')->first();
    }

    /** Files a refund from the website the way a signed-in customer files one, and returns the row that route saved. */
    protected function fileRefundOnTheWebsite(object $order, User $customer): object
    {
        $line = DB::table('order_details')->where('order_id', $order->id)->orderBy('id')->first();

        // Signed in as the database holds the customer, not as the checkout left them in memory: the website's customer guard reads is_active,
        // which the row carries by default and a freshly created model does not.
        $this->asTheCustomerOnTheWebsite($customer->fresh())
            ->post(route('refund-store'), ['order_details_id' => $line->id, 'amount' => $line->price, 'refund_reason' => 'Damaged on arrival'])
            ->assertRedirect(route('account-order-details', ['id' => $order->id]));

        return DB::table('refund_requests')->latest('id')->first();
    }

    /**
     * Every amount an order stored, the order's own and those of its money rows, as a list of "table.column: value" strings in a fixed order.
     *
     * It names no currency column, so two orders placed the same way can be compared across the migration: anything that differs is a stored
     * amount that moved.
     *
     * @return list<string>
     */
    protected function storedAmountsOf(int|string $orderId): array
    {
        $amounts = [
            'orders' => ['order_amount', 'init_order_amount', 'paid_amount', 'total_tax_amount', 'discount_amount', 'shipping_cost', 'extra_discount', 'admin_commission'],
            'order_details' => ['qty', 'price', 'discount', 'tax'],
            'order_transactions' => ['order_amount', 'seller_amount', 'admin_commission', 'delivery_charge', 'tax'],
            'refund_requests' => ['amount'],
            'refund_transactions' => ['amount'],
        ];
        $stored = [];

        foreach ($amounts as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $key = $table === 'orders' ? 'id' : 'order_id';

            foreach (DB::table($table)->where($key, $orderId)->orderBy('id')->get() as $index => $row) {
                foreach ($columns as $column) {
                    $stored[] = $table . '[' . $index . '].' . $column . ': ' . (float) $row->{$column};
                }
            }
        }

        return $stored;
    }

    /**
     * Puts the columns back on a database a test has taken them off, and makes the snapshot service look at the schema again.
     *
     * The service remembers that a column is there for the life of the process, because in production a column is never taken away. A test that
     * compares the same checkout on either side of the migration does take it away, so it has to say so.
     */
    protected function restoreOrderMoneyRowCurrencyColumns(): void
    {
        $this->runOrderMoneyRowCurrencyMigration();
        app()->forgetInstance(OrderStorefrontSnapshot::class);
    }

    /**
     * A whole life of an order, in the order a shop lives it and all of it through real routes: the customer places it, the panel marks it
     * delivered, the customer asks for their money back and the panel pays it. Returns the order row as the checkout saved it.
     */
    protected function liveThroughAnOrder(string $country, int $productId): object
    {
        $order = $this->placeOrderFrom(country: $country, productId: $productId);
        $this->deliverTheOrder($order);
        $this->approveTheRefund($this->fileRefundOn($order, $order->customer));

        return $order;
    }

    /**
     * A sale at the seller's own till, placed through the point-of-sale method of the seller app's API with a request signed in as the seller the
     * way that API's middleware signs one in. The till is the one order writer that saves its lines before the order row exists, and the one that
     * takes two of its amounts from what the seller typed rather than from the cart.
     *
     * @param array<string, mixed> $overrides
     */
    protected function sellAtTheTill(int $productId, array $overrides = []): object
    {
        $line = ['id' => $productId, 'variant' => null, 'quantity' => 1, 'price' => 30, 'discounted_price' => 30, 'discount' => 0, 'applied_tax_amount' => 0, 'coupon_discount' => 0, 'extra_discount' => 0, 'variation' => []];
        $input = $overrides + [
            'customer_id' => 0,
            'cart' => [],
            'modified_cart' => [$line],
            'extra_discount' => 0,
            'extra_discount_type' => null,
            'coupon_discount_amount' => 10,
            'coupon_code' => null,
            'payment_method' => 'card',
            'paid_amount' => 30,
            'fulfillment' => 'instant',
            'total_tax_amount' => 0,
        ];

        // A seller's till prices and sells only that seller's own products, so a sale at the till is a sale of stock the till owns. The catalog
        // this world builds is the platform's in-house one, which the till rightly refuses, so the line's product is put in the till's own
        // hands here rather than weakening the rule that refuses it.
        $this->giveTheTillItsOwnStock(productId: $productId);

        $request = Request::create('/api/v3/seller/pos/place-order', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($input));
        $request['seller'] = (new Seller())->forceFill(['id' => self::TILL_SELLER]);

        $response = app(POSController::class)->place_order($request);
        $answer = json_decode((string) $response->getContent(), true);

        // The till answers a refusal with 200 and a reason in the body, so a status check alone reads a refusal as a sale and then finds no
        // order. Both are read here, and the reason is quoted, so a refused sale names itself instead of arriving as a null order.
        if ($response->getStatusCode() !== 200 || !is_array($answer) || !array_key_exists('order_id', $answer)) {
            throw new RuntimeException('The till refused the sale: ' . $response->getContent());
        }

        return DB::table('orders')->where('id', $answer['order_id'])->sole();
    }

    /**
     * An order edited the way a panel edits one: the vendor panel's own edit controller, which is where the trait that writes the lines is used.
     * The lines given are the lines the order is left with, so a product that was not on the order is a line the edit creates.
     *
     * @param list<array<string, mixed>> $lines
     */
    protected function editTheLinesOf(object $order, array $lines): void
    {
        app(OrderEditController::class)->addAndUpdateProductsInOrderDetails(
            order: Order::with('details')->findOrFail($order->id),
            editedOrder: $lines,
            taxSummary: [],
        );
    }

    /**
     * A sale paid by card, placed where production places one: the gateway's success hook, which runs in a request of the gateway's own with no
     * storefront of its own and builds the order from what the payment recorded. A card sale, unlike a cash one, writes its payment transaction
     * inside the checkout rather than at delivery.
     */
    protected function payByCardFrom(string $country, int $productId): object
    {
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom($customer, $country, $productId);
        $this->flushHeaders();

        $additionalData = ['customer_id' => $customer->id, 'is_guest' => 0, 'payment_mode' => 'web', 'order_note' => null, 'coupon_code' => null, 'address_id' => null, 'billing_address_id' => null, PaymentStorefront::KEY => $country];

        digital_payment_success([
            'is_paid' => 1,
            'payment_method' => 'card',
            'transaction_id' => 'tr-' . $productId,
            'additional_data' => json_encode($additionalData),
        ]);

        return DB::table('orders')->latest('id')->first();
    }

    /**
     * A seller delivering their own cash sale from the seller app, which is one of the three callers of the branch that writes an
     * order_transactions row when a cash-on-delivery order is handed over (the panel's own delivery writes it from the order repository
     * instead, so the two are different writers of the same table).
     */
    protected function deliverAsTheSeller(object $order): void
    {
        $request = Request::create('/api/v3/seller/orders/order-detail-status', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['id' => $order->id, 'order_status' => 'delivered']));
        $request['seller'] = (new Seller())->forceFill(['id' => self::TILL_SELLER]);

        $response = app(SellerOrderController::class)->order_detail_status($request);

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('The seller app refused the delivery: ' . $response->getContent());
        }
    }

    /** A second product of the Kuwaiti catalog, so that an edit of a Kuwaiti order has a line it can add. */
    protected function addASecondKuwaitiProduct(): void
    {
        $existing = (array) DB::table('products')->where('id', self::KUWAITI_CATALOG_PRODUCT)->sole();

        DB::table('products')->insert(['id' => self::SECOND_KUWAITI_CATALOG_PRODUCT, 'slug' => 'paracetamol-kw-2', 'code' => 'SKU-KW-2'] + $existing);
    }

    /** One line of an order edit, as the edit screens hand them over. */
    protected function editedLine(int $productId, string $variant = ''): array
    {
        return ['product_id' => $productId, 'variant' => $variant, 'qty' => 1, 'price' => 30, 'discount' => 0, 'seller_id' => self::TILL_SELLER, 'product_type' => 'physical', 'tax_model' => 'exclude'];
    }

    /** Approves a refund the way an administrator approves one, from the panel's own route. */
    protected function approveTheRefund(object $refund): void
    {
        $this->openTheAdminPanel();

        $this->postJson(route('admin.refund-section.refund.refund-status-update'), [
            'id' => $refund->id,
            'refund_status' => 'refunded',
            'payment_method' => 'manual',
            'payment_info' => 'paid by hand',
            'approved_note' => 'Approved by the returns desk',
        ])->assertOk();
    }
}
