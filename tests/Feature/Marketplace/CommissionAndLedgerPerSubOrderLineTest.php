<?php

namespace Tests\Feature\Marketplace;

use App\Models\CommissionRule;
use App\Models\OrderItemCommission;
use App\Models\User;
use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\CommissionEngine;
use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsAddressSchema;
use Tests\Support\BuildsApiAddressSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Checkout freezes the commission of every line, and puts the seller's side of it on their ledger, one line at a time.
 *
 * A basket of two vendors and the in-house shop becomes three sub-orders. Every line of every sub-order gets its own frozen
 * commission snapshot, and every SELLER line gets two ledger entries — the sale as a credit and the marketplace's cut as a debit —
 * which mature together. The in-house line is the control: it is snapshotted and it reaches no ledger, because the marketplace does
 * not charge itself. Every line in the basket carries a different amount, so a writer that kept one figure per ORDER rather than per
 * line cannot satisfy these assertions.
 *
 * ## What this test builds, and what that means for what it proves
 *
 * `order_item_commissions`, `vendor_ledger_entries`, `commission_rules` and `category_governance` are created by NO migration in
 * this repository, so they are absent from a test database unless a test builds them — which is why this writer has never been
 * covered: `OrderManager::recordOrderItemCommission` is wrapped in a `Schema::hasTable()` guard and silently does nothing when the
 * table is missing. The four tables below are therefore INFERRED from the models and the code that reads and writes them, exactly as
 * `tests/Support/BuildsAddressSchema` and the other schema builders of this project infer the store tables the installation dump
 * owns. `orders`, `products`, `categories` and `sellers` stand in exactly the same place — no migration here creates them either —
 * so "no migration in this repository" says nothing about whether a table exists on an installation. Which tables an installation
 * has is a property of its SQL dump, and that is not knowable from this checkout.
 *
 * So what this proves is that the WRITER is correct. It covers a money path that had no test at all: this writer runs on the
 * checkout path of every order and freezes the figure every settlement and seller statement later reads back.
 */
class CommissionAndLedgerPerSubOrderLineTest extends TestCase
{
    use BuildsAddressSchema;
    use BuildsApiAddressSchema;
    use RestoresLanguageFiles;

    /** The global `refund_day_limit`: how long after checkout a seller's earning may not be paid out. */
    private const GLOBAL_RETURN_WINDOW_DAYS = 7;

    /** One category sets its own, shorter window, so two lines of the SAME seller mature on different days. */
    private const SHORT_RETURN_WINDOW_DAYS = 3;

    private const GLOBAL_RATE = 10.0;

    private const SELLER_B_RATE = 15.0;

    /**
     * `sellers.sales_commission_percentage`, which is what the engine falls back to with no rule and what the legacy aggregate
     * `orders.admin_commission` is still derived from. Deliberately unlike either rule rate: a snapshot that had copied the legacy
     * figure instead of asking the engine would carry 5% of the line and fail every amount assertion below.
     */
    private const LEGACY_RATE = 5.0;

    private const SELLER_A = 11;

    private const SELLER_B = 22;

    /**
     * The basket, line by line: five lines over three sub-orders, each line its own product and its own net amount.
     *
     * @var list<array{group: string, seller_id: int, seller_is: string, product_id: int, category_id: int, price: float, qty: int}>
     */
    private const BASKET = [
        ['group' => 'g-vendor-a', 'seller_id' => self::SELLER_A, 'seller_is' => 'seller', 'product_id' => 101, 'category_id' => 1, 'price' => 120.0, 'qty' => 1],
        ['group' => 'g-vendor-a', 'seller_id' => self::SELLER_A, 'seller_is' => 'seller', 'product_id' => 102, 'category_id' => 1, 'price' => 90.0, 'qty' => 2],
        ['group' => 'g-vendor-b', 'seller_id' => self::SELLER_B, 'seller_is' => 'seller', 'product_id' => 201, 'category_id' => 1, 'price' => 250.0, 'qty' => 1],
        ['group' => 'g-vendor-b', 'seller_id' => self::SELLER_B, 'seller_is' => 'seller', 'product_id' => 202, 'category_id' => 2, 'price' => 70.0, 'qty' => 3],
        ['group' => 'g-inhouse', 'seller_id' => 1, 'seller_is' => 'admin', 'product_id' => 301, 'category_id' => 1, 'price' => 60.0, 'qty' => 1],
    ];

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));

        $this->buildAddressSchema();
        $this->createApiStoreTables();
        $this->createOrderPipelineTables();
        $this->createMarketplaceAccountingTables();

        $this->customer = $this->makeCustomer();
        $this->sellBasketTo($this->customer);

        // After the fixture, not before: building it flushes the cache this set-up lives in.
        $this->useTaxIncludedPrices();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // The criterion
    // ---------------------------------------------------------------------------------------------

    public function test_every_line_of_every_sub_order_gets_its_own_commission_snapshot(): void
    {
        $orderIds = $this->checkout();

        $this->assertCount(3, $orderIds, 'two vendors and the in-house shop are three sub-orders');
        $this->assertSame(count(self::BASKET), DB::table('order_details')->count(), 'one order_details row per basket line');
        $this->assertSame(
            count(self::BASKET),
            OrderItemCommission::count(),
            'one commission snapshot per LINE: a writer keyed by order would have written three',
        );

        foreach (self::BASKET as $line) {
            $detail = $this->detailOf($line);
            $snapshot = OrderItemCommission::where('order_details_id', $detail->id)->first();

            $this->assertNotNull($snapshot, "line {$line['product_id']} has no snapshot of its own");
            $this->assertSame(
                [
                    'order_id' => (int) $detail->order_id,
                    'product_id' => $line['product_id'],
                    // Whoever the cart line says owns it, in-house lines included: `seller_is` is what separates them.
                    'seller_id' => $line['seller_id'],
                    'seller_is' => $line['seller_is'],
                ],
                [
                    'order_id' => (int) $snapshot->order_id,
                    'product_id' => (int) $snapshot->product_id,
                    'seller_id' => (int) $snapshot->seller_id,
                    'seller_is' => $snapshot->seller_is,
                ],
                "the snapshot of line {$line['product_id']} names that line's own order, product and seller",
            );
            $this->assertEqualsWithDelta($this->netOf($line), $snapshot->commissionable_amount, 0.0001, "line {$line['product_id']}: its own net amount");
            $this->assertEqualsWithDelta($this->expectedCommissionOf($line), $snapshot->commission_amount, 0.0001, "line {$line['product_id']}: its own commission");
            $this->assertEqualsWithDelta(
                $this->netOf($line) - $this->expectedCommissionOf($line),
                $snapshot->seller_net_amount,
                0.0001,
                "line {$line['product_id']}: what the seller keeps",
            );
        }
    }

    public function test_the_snapshot_of_a_line_carries_the_rule_that_priced_it(): void
    {
        $this->checkout();

        foreach (self::BASKET as $line) {
            $snapshot = OrderItemCommission::where('order_details_id', $this->detailOf($line)->id)->firstOrFail();
            $rule = $this->winningRuleFor($line);

            $this->assertSame(
                [$rule?->id, $rule?->scope_type, $rule === null ? 0.0 : $rule->percentage],
                [$snapshot->commission_rule_id === null ? null : (int) $snapshot->commission_rule_id, $snapshot->rule_scope_type, $snapshot->percentage],
                "line {$line['product_id']}: the rule the engine resolved for THIS line is the one recorded",
            );
        }
    }

    public function test_each_seller_line_puts_an_earning_and_its_commission_charge_on_that_sellers_ledger(): void
    {
        $this->checkout();

        foreach ($this->sellerLines() as $line) {
            $detail = $this->detailOf($line);
            $entries = VendorLedgerEntry::where(['reference_type' => 'order_details', 'reference_id' => $detail->id])->get();

            $this->assertCount(2, $entries, "line {$line['product_id']}: an earning and a commission charge, not one netted figure");

            $earning = $entries->firstWhere('entry_type', VendorLedgerEntry::TYPE_ORDER_EARNING);
            $charge = $entries->firstWhere('entry_type', VendorLedgerEntry::TYPE_COMMISSION_CHARGE);

            $this->assertNotNull($earning, "line {$line['product_id']}: no earning entry");
            $this->assertNotNull($charge, "line {$line['product_id']}: no commission charge entry");

            $this->assertSame($line['seller_id'], (int) $earning->seller_id, "line {$line['product_id']}: the earning is on ITS seller's ledger");
            $this->assertSame($line['seller_id'], (int) $charge->seller_id, "line {$line['product_id']}: the charge is on ITS seller's ledger");

            $this->assertEqualsWithDelta($this->netOf($line), $earning->credit, 0.0001, "line {$line['product_id']}: the earning credits that line's net amount");
            $this->assertEqualsWithDelta(0.0, $earning->debit, 0.0001, 'an earning is a credit, never a debit');
            $this->assertEqualsWithDelta($this->expectedCommissionOf($line), $charge->debit, 0.0001, "line {$line['product_id']}: the charge debits that line's commission");
            $this->assertEqualsWithDelta(0.0, $charge->credit, 0.0001, 'a commission charge is a debit, never a credit');

            $this->assertSame(
                [VendorLedgerEntry::STATUS_PENDING, VendorLedgerEntry::STATUS_PENDING],
                [$earning->status, $charge->status],
                "line {$line['product_id']}: money inside the return window is not money the seller can be paid",
            );
        }
    }

    public function test_the_two_entries_of_a_line_mature_on_the_same_day_which_is_that_lines_own_return_window(): void
    {
        $this->checkout();

        foreach ($this->sellerLines() as $line) {
            $entries = VendorLedgerEntry::where(['reference_type' => 'order_details', 'reference_id' => $this->detailOf($line)->id])->get();
            $earning = $entries->firstWhere('entry_type', VendorLedgerEntry::TYPE_ORDER_EARNING);
            $charge = $entries->firstWhere('entry_type', VendorLedgerEntry::TYPE_COMMISSION_CHARGE);

            $this->assertNotNull($earning->available_at, "line {$line['product_id']}: an earning with no available_at could never leave pending");
            $this->assertNotNull($charge->available_at);
            $this->assertTrue(
                $earning->available_at->equalTo($charge->available_at),
                "line {$line['product_id']}: the earning and its commission must mature together, or settlement pays the gross",
            );
            $this->assertSame(
                Carbon::now()->addDays($this->returnWindowOf($line))->toDateTimeString(),
                $earning->available_at->toDateTimeString(),
                "line {$line['product_id']}: the window of THIS line's category, counted from checkout",
            );
        }

        $windows = collect($this->sellerLines())
            ->map(fn (array $line) => $this->earningOf($line)->available_at->toDateTimeString())
            ->unique();

        $this->assertGreaterThan(1, $windows->count(), 'the window is resolved per line, so lines of one seller can mature on different days');
    }

    public function test_the_in_house_line_is_snapshotted_and_reaches_no_ledger(): void
    {
        $this->checkout();

        $inHouse = $this->inHouseLine();
        $snapshot = OrderItemCommission::where('order_details_id', $this->detailOf($inHouse)->id)->first();

        $this->assertNotNull($snapshot, 'an in-house line is still snapshotted: a line without one cannot be reconciled');
        $this->assertSame('admin', $snapshot->seller_is);
        $this->assertEqualsWithDelta($this->netOf($inHouse), $snapshot->commissionable_amount, 0.0001);
        $this->assertEqualsWithDelta(0.0, $snapshot->commission_amount, 0.0001, 'the marketplace does not charge itself');
        $this->assertEqualsWithDelta($this->netOf($inHouse), $snapshot->seller_net_amount, 0.0001);

        $this->assertSame(
            0,
            VendorLedgerEntry::where(['reference_type' => 'order_details', 'reference_id' => $this->detailOf($inHouse)->id])->count(),
            'the in-house shop has no vendor ledger',
        );
        $this->assertSame(
            2 * count($this->sellerLines()),
            VendorLedgerEntry::count(),
            'two entries per SELLER line and nothing else: entries follow the seller lines, not every line',
        );
    }

    public function test_a_sellers_ledger_holds_only_their_own_lines_and_its_balance_is_their_own_net(): void
    {
        $this->checkout();

        foreach ([self::SELLER_A, self::SELLER_B] as $sellerId) {
            $own = collect($this->sellerLines())->where('seller_id', $sellerId);
            $entries = VendorLedgerEntry::forSeller($sellerId)->get();

            $this->assertSame($own->count() * 2, $entries->count(), "seller {$sellerId}: two entries for each of their own lines");
            $this->assertSame(
                $own->map(fn (array $line) => (int) $this->detailOf($line)->id)->sort()->values()->all(),
                $entries->pluck('reference_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all(),
                "seller {$sellerId}: their ledger references their own order lines and no others",
            );
            $this->assertEqualsWithDelta(
                $own->sum(fn (array $line) => $this->netOf($line) - $this->expectedCommissionOf($line)),
                $entries->sum(fn (VendorLedgerEntry $entry) => $entry->signed_amount),
                0.0001,
                "seller {$sellerId}: the credits less the debits come to what they are owed on their own lines",
            );
        }
    }

    public function test_the_lines_of_the_basket_each_carry_a_different_amount(): void
    {
        $nets = collect(self::BASKET)->map(fn (array $line) => $this->netOf($line));
        $commissions = collect($this->sellerLines())->map(fn (array $line) => $this->expectedCommissionOf($line));

        $this->assertSame($nets->count(), $nets->unique()->count(), 'the fixture is only a per-line test while every line differs');
        $this->assertSame($commissions->count(), $commissions->unique()->count());
        $this->assertGreaterThan(0, $commissions->min(), 'every seller line is charged something, or its charge entry would not be written at all');
    }

    // ---------------------------------------------------------------------------------------------
    // Driving the production path
    // ---------------------------------------------------------------------------------------------

    /**
     * Checkout, the way every controller runs it: `OrderManager::generateOrder`, which splits the cart per vendor, opens one
     * transaction and calls the real `addOrderDetailsData` for each sub-order.
     *
     * @return list<int> the sub-order ids
     */
    private function checkout(): array
    {
        // The resolver, not the merged input. Identity comes from the guard, the request's own resolver or the session and
        // never from request input, because a request-supplied `user` was an identity hole (9db1506b, "request identity comes
        // only from the guard, the request's resolver or the session"). Without the resolver the reader answers 'offline', the
        // cart lookup goes looking for a guest's rows, finds none, and generateOrder writes no order at all -- which is what
        // "two vendors and the in-house shop are three sub-orders: actual size 0" was. tests/Feature/Wallet/WalletCheckoutTest
        // drives the same writer and was given the same line.
        request()->merge(['user' => $this->customer]);
        request()->setUserResolver(fn () => $this->customer);

        return OrderManager::generateOrder(data: [
            'is_guest' => 0,
            'customer_id' => $this->customer->id,
            'order_status' => 'pending',
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'unpaid',
            'transaction_ref' => '',
            'requestObj' => request(),
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // What each line is worth, derived the way the rule says rather than written down
    // ---------------------------------------------------------------------------------------------

    /** @param array{price: float, qty: int} $line */
    private function netOf(array $line): float
    {
        return round($line['price'] * $line['qty'], 4);
    }

    /**
     * The commission the winning rule charges on this line, derived from the rule row itself. Nothing here is a number typed in by
     * hand: change a rate in the fixture and the expectation follows it.
     */
    private function expectedCommissionOf(array $line): float
    {
        $rule = $this->winningRuleFor($line);

        return $rule === null ? 0.0 : round($this->netOf($line) * $rule->percentage / 100, 4);
    }

    /** Which rule the engine must resolve for this line: the vendor rule where one is scoped to its seller, else the global one. */
    private function winningRuleFor(array $line): ?CommissionRule
    {
        if ($line['seller_is'] !== 'seller') {
            return null;
        }

        return CommissionRule::where(['scope_type' => CommissionEngine::SCOPE_VENDOR, 'scope_id' => $line['seller_id']])->first()
            ?? CommissionRule::where('scope_type', CommissionEngine::SCOPE_GLOBAL)->firstOrFail();
    }

    private function returnWindowOf(array $line): int
    {
        return $line['category_id'] === 2 ? self::SHORT_RETURN_WINDOW_DAYS : self::GLOBAL_RETURN_WINDOW_DAYS;
    }

    // ---------------------------------------------------------------------------------------------
    // Reading the rows back, each bound to the line it belongs to
    // ---------------------------------------------------------------------------------------------

    /** The `order_details` row of one basket line, found by its own product and its own sub-order's seller. */
    private function detailOf(array $line): object
    {
        return DB::table('order_details')
            ->join('orders', 'orders.id', '=', 'order_details.order_id')
            ->where('order_details.product_id', $line['product_id'])
            ->where('orders.seller_is', $line['seller_is'])
            ->when($line['seller_is'] === 'seller', fn ($query) => $query->where('orders.seller_id', $line['seller_id']))
            ->select('order_details.id', 'order_details.order_id', 'order_details.product_id')
            ->firstOrFail();
    }

    private function earningOf(array $line): VendorLedgerEntry
    {
        return VendorLedgerEntry::where([
            'reference_type' => 'order_details',
            'reference_id' => $this->detailOf($line)->id,
            'entry_type' => VendorLedgerEntry::TYPE_ORDER_EARNING,
        ])->firstOrFail();
    }

    /** @return list<array<string, mixed>> */
    private function sellerLines(): array
    {
        return array_values(array_filter(self::BASKET, static fn (array $line) => $line['seller_is'] === 'seller'));
    }

    /** @return array<string, mixed> */
    private function inHouseLine(): array
    {
        return array_values(array_filter(self::BASKET, static fn (array $line) => $line['seller_is'] !== 'seller'))[0];
    }

    // ---------------------------------------------------------------------------------------------
    // The fixture
    // ---------------------------------------------------------------------------------------------

    /** The two vendors, their shops, the catalogue and the cart: the basket as the checkout finds it. */
    private function sellBasketTo(User $customer): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable();
        });
        Schema::table('sellers', function (Blueprint $table) {
            $table->decimal('sales_commission_percentage', 16, 2)->nullable();
        });
        $this->createTable('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->unsignedBigInteger('parent_id')->default(0);
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Pain relief', 'slug' => 'pain-relief'],
            ['id' => 2, 'name' => 'Refrigerated', 'slug' => 'refrigerated'],
        ]);

        foreach ([self::SELLER_A => 'Vendor A', self::SELLER_B => 'Vendor B'] as $sellerId => $name) {
            DB::table('sellers')->insert([
                'id' => $sellerId, 'f_name' => $name, 'status' => 'approved',
                'sales_commission_percentage' => self::LEGACY_RATE, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('shops')->insert(['seller_id' => $sellerId, 'author_type' => 'vendor', 'name' => $name . ' shop', 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach (self::BASKET as $line) {
            DB::table('products')->insert([
                'id' => $line['product_id'], 'name' => 'Product ' . $line['product_id'], 'slug' => 'product-' . $line['product_id'],
                'added_by' => $line['seller_is'] === 'seller' ? 'seller' : 'admin', 'user_id' => $line['seller_id'],
                'category_id' => $line['category_id'], 'unit_price' => $line['price'], 'current_stock' => 100,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('carts')->insert([
                'customer_id' => $customer->id, 'is_guest' => 0, 'cart_group_id' => $line['group'], 'product_id' => $line['product_id'],
                'quantity' => $line['qty'], 'price' => $line['price'], 'seller_id' => $line['seller_id'], 'seller_is' => $line['seller_is'],
                'is_checked' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('business_settings')->insert([
            ['type' => 'sales_commission', 'value' => json_encode(self::LEGACY_RATE)],
            ['type' => 'refund_day_limit', 'value' => json_encode(self::GLOBAL_RETURN_WINDOW_DAYS)],
            ['type' => 'shipping_method', 'value' => json_encode('inhouse_shipping')],
        ]);
        DB::table('category_governance')->insert([
            'category_id' => 2, 'return_window_days' => self::SHORT_RETURN_WINDOW_DAYS, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->writeCommissionRules();
        Cache::flush();
    }

    /**
     * The rules the engine reads, written with the Eloquent call and the normalised payload of the platform's own writer,
     * `Admin\Marketplace\CommissionRuleController::store()`. The admin HTTP route is not driven here: it would add admin
     * authentication and the marketplace module gate to a test about checkout, and the rows it produces are these.
     *
     * The priorities are the engine's own documented defaults, so the vendor rule outranks the global one for seller B by priority
     * rather than by the accident of being created second.
     */
    private function writeCommissionRules(): void
    {
        CommissionRule::create([
            'label' => 'Platform rate', 'scope_type' => CommissionEngine::SCOPE_GLOBAL, 'scope_id' => null,
            'rate_type' => CommissionEngine::RATE_PERCENTAGE, 'percentage' => self::GLOBAL_RATE, 'fixed_amount' => 0,
            'min_amount' => null, 'max_amount' => null, 'priority' => CommissionEngine::DEFAULT_PRIORITY[CommissionEngine::SCOPE_GLOBAL],
            'is_active' => 1, 'starts_at' => null, 'ends_at' => null, 'created_by' => null,
        ]);

        CommissionRule::create([
            'label' => 'Vendor B rate', 'scope_type' => CommissionEngine::SCOPE_VENDOR, 'scope_id' => self::SELLER_B,
            'rate_type' => CommissionEngine::RATE_PERCENTAGE, 'percentage' => self::SELLER_B_RATE, 'fixed_amount' => 0,
            'min_amount' => null, 'max_amount' => null, 'priority' => CommissionEngine::DEFAULT_PRIORITY[CommissionEngine::SCOPE_VENDOR],
            'is_active' => 1, 'starts_at' => null, 'ends_at' => null, 'created_by' => null,
        ]);
    }

    /**
     * The four tables of the commission and ledger subsystem, inferred from the models and the code that reads them. See the class
     * docblock for why a test has to build them and what that limits this test to proving.
     */
    private function createMarketplaceAccountingTables(): void
    {
        $this->createTable('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type')->default('global');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('rate_type')->default('percentage');
            $table->decimal('percentage', 8, 4)->default(0);
            $table->decimal('fixed_amount', 16, 4)->default(0);
            $table->decimal('min_amount', 16, 4)->nullable();
            $table->decimal('max_amount', 16, 4)->nullable();
            $table->integer('priority')->default(10);
            $table->boolean('is_active')->default(true);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('label')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        $this->createTable('order_item_commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_details_id')->unique();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->default('admin');
            $table->unsignedBigInteger('commission_rule_id')->nullable();
            $table->string('rule_scope_type')->nullable();
            $table->string('rule_label')->nullable();
            $table->string('rate_type')->default('percentage');
            $table->decimal('percentage', 8, 4)->default(0);
            $table->decimal('fixed_amount', 16, 4)->default(0);
            $table->decimal('commissionable_amount', 16, 4)->default(0);
            $table->decimal('commission_amount', 16, 4)->default(0);
            $table->decimal('seller_net_amount', 16, 4)->default(0);
            $table->decimal('reversed_amount', 16, 4)->default(0);
            $table->string('currency', 10)->nullable();
            $table->timestamps();
        });

        $this->createTable('vendor_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('seller_is')->default('seller');
            $table->string('entry_type');
            $table->decimal('debit', 16, 4)->default(0);
            $table->decimal('credit', 16, 4)->default(0);
            $table->decimal('balance_after', 16, 4)->default(0);
            $table->string('status')->default('pending');
            $table->dateTime('available_at')->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('settlement_id')->nullable();
            $table->timestamps();
        });

        $this->createTable('category_governance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->unique();
            $table->integer('return_window_days')->nullable();
            $table->json('required_attributes')->nullable();
            $table->string('tax_class')->nullable();
            $table->boolean('requires_moderation')->default(false);
            $table->boolean('shipping_restricted')->default(false);
            $table->string('restriction_note')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    private function createTable(string $name, callable $definition): void
    {
        if (!Schema::hasTable($name)) {
            Schema::create($name, $definition);
        }
    }
}
