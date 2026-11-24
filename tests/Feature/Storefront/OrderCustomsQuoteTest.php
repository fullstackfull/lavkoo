<?php

namespace Tests\Feature\Storefront;

use App\Services\Shipping\CustomsDuty;
use App\Services\Shipping\CustomsDutyQuote;
use App\Services\Shipping\OrderCustomsQuote;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * What an order keeps about customs duty (H1-09), and the choice this class exists to hold: duty is charged on the value of the GOODS.
 *
 * `orders.order_amount` carries the shipping and the tax as well, and neither is dutiable -- duty is charged on the thing crossing the
 * border, not on what it cost to get it there, and certainly not on another country's VAT. So the order's LINES are read and the discount
 * comes off. The order built here has a shipping cost and a tax on purpose, so that the two figures differ and a test can tell them apart;
 * the checkout's own quoter reads the same formula over the basket, which is what makes what the customer was shown what the order keeps.
 *
 * Written once and never rewritten: a tariff changes, a rule is written or withdrawn, and an order recomputed a year later would show a
 * figure nobody ever charged.
 */
class OrderCustomsQuoteTest extends TestCase
{
    private const ORDER = 501;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildOrderWorld();
        (require database_path('migrations/2026_12_20_100400_add_customs_quote_to_orders_table.php'))->up();
        OrderCustomsQuote::forget();
        config([
            'gcc.shipping.duty.mode' => CustomsDuty::MODE_DECLARE,
            'gcc.shipping.duty.routes' => ['SA:KW' => ['percentage' => 10, 'payer' => CustomsDutyQuote::BUYER_ON_DELIVERY]],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['order_details', 'orders'] as $table) {
            Schema::dropIfExists($table);
        }
        OrderCustomsQuote::forget();

        parent::tearDown();
    }

    private function buildOrderWorld(): void
    {
        Schema::dropIfExists('order_details');
        Schema::dropIfExists('orders');

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->decimal('order_amount', 24, 3)->default(0);
            $table->text('shipping_address_data')->nullable();
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('price', 24, 3)->default(0);
            $table->decimal('discount', 24, 3)->default(0);
            $table->integer('qty')->default(1);
            $table->timestamps();
        });
    }

    /**
     * An order of 300 in goods that cost 350 to buy once the shipping and the tax are on it. The duty is owed on the 300.
     *
     * @param list<array{price: float, discount: float, qty: int}> $lines
     */
    private function anOrderOf(array $lines, float $orderAmount, string $destination = 'KW'): void
    {
        DB::table('orders')->insert([
            'id' => self::ORDER,
            'order_amount' => $orderAmount,
            'shipping_address_data' => json_encode(['country' => $destination]),
        ]);

        foreach ($lines as $line) {
            DB::table('order_details')->insert(['order_id' => self::ORDER] + $line);
        }
    }

    /** The choice, and the test that can tell it from the other one: the goods are 300, the order came to 350, the duty is 30. */
    public function test_the_duty_is_quoted_on_the_goods_and_not_on_the_order_total(): void
    {
        $this->anOrderOf([['price' => 100.0, 'discount' => 0.0, 'qty' => 3]], orderAmount: 350.0);

        $quote = OrderCustomsQuote::record(self::ORDER);

        $this->assertNotNull($quote);
        $this->assertSame(300.0, $quote->basis, 'the goods, not the 350 the order came to');
        $this->assertSame(30.0, $quote->amount);
        $this->assertSame(CustomsDutyQuote::BUYER_ON_DELIVERY, $quote->payer);
    }

    /** What was actually paid for the goods is their value, so a discount comes off before the duty is worked out. */
    public function test_the_discount_comes_off_before_the_duty(): void
    {
        $this->anOrderOf([['price' => 100.0, 'discount' => 25.0, 'qty' => 2]], orderAmount: 200.0);

        $this->assertSame(150.0, OrderCustomsQuote::record(self::ORDER)->basis);
    }

    public function test_the_quote_is_read_back_as_it_was_written(): void
    {
        $this->anOrderOf([['price' => 100.0, 'discount' => 0.0, 'qty' => 3]], orderAmount: 350.0);
        OrderCustomsQuote::record(self::ORDER);

        $kept = OrderCustomsQuote::of(self::ORDER);

        $this->assertSame(CustomsDutyQuote::PAYABLE, $kept->kind);
        $this->assertSame(30.0, $kept->amount);
        $this->assertSame(300.0, $kept->basis);
        $this->assertSame('SA:KW', $kept->route);
    }

    /** A tariff changes; what the customer was told does not. */
    public function test_an_order_that_carries_a_quote_keeps_it(): void
    {
        $this->anOrderOf([['price' => 100.0, 'discount' => 0.0, 'qty' => 3]], orderAmount: 350.0);
        OrderCustomsQuote::record(self::ORDER);

        config(['gcc.shipping.duty.routes' => ['SA:KW' => ['percentage' => 50, 'payer' => CustomsDutyQuote::SELLER]]]);
        $this->assertNull(OrderCustomsQuote::record(self::ORDER), 'nothing is written a second time');

        $this->assertSame(30.0, OrderCustomsQuote::of(self::ORDER)->amount, 'the figure the customer was told');
    }

    /** A domestic order owes nothing, and the record says the question was asked rather than leaving it unanswered. */
    public function test_a_domestic_order_records_that_nothing_is_owed(): void
    {
        $this->anOrderOf([['price' => 100.0, 'discount' => 0.0, 'qty' => 1]], orderAmount: 120.0, destination: 'SA');

        $this->assertSame(CustomsDutyQuote::NONE, OrderCustomsQuote::record(self::ORDER)->kind);
    }

    /** The dormancy: with duty off nothing is written at all, which is how the platform ships. */
    public function test_with_duty_off_nothing_is_written(): void
    {
        config(['gcc.shipping.duty.mode' => CustomsDuty::MODE_OFF]);
        $this->anOrderOf([['price' => 100.0, 'discount' => 0.0, 'qty' => 1]], orderAmount: 120.0);

        $this->assertNull(OrderCustomsQuote::record(self::ORDER));
        $this->assertNull(OrderCustomsQuote::of(self::ORDER));
    }

    /** And before the column exists there is nowhere to write it, so an un-migrated shop is untouched. */
    public function test_without_the_column_nothing_is_written(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('customs_quote'));
        OrderCustomsQuote::forget();
        $this->anOrderOf([['price' => 100.0, 'discount' => 0.0, 'qty' => 1]], orderAmount: 120.0);

        $this->assertNull(OrderCustomsQuote::record(self::ORDER));
    }

    /** A route nobody has a rule for is recorded as not quoted, which is the record saying so rather than saying nothing is owed. */
    public function test_an_unquotable_route_is_recorded_as_not_quoted(): void
    {
        config(['gcc.shipping.duty.routes' => []]);
        $this->anOrderOf([['price' => 100.0, 'discount' => 0.0, 'qty' => 1]], orderAmount: 120.0);

        $quote = OrderCustomsQuote::record(self::ORDER);

        $this->assertSame(CustomsDutyQuote::NOT_QUOTED, $quote->kind);
        $this->assertSame(0.0, $quote->amount, 'and no amount is claimed');
    }
}
