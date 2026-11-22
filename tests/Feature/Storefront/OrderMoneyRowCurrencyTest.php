<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\OrderStorefrontSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsOrderCurrencySurfaces;
use Tests\Support\BuildsOrderMoneyRowCurrency;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;

/**
 * What the money rows of an order are counted in (D1-06): every order line, every payment transaction of the order and both refund rows record
 * the currency of their own amount, copied from the order's own record and never asked of the settings of the day they are read.
 *
 * Every figure here is read back out of the database after a real route put it there: a cash-on-delivery checkout of the customer API, the
 * customer app's refund route, the panel's refund approval and the panel's delivery. Nothing in this test writes a currency anywhere, so a
 * writer that records nothing shows as a NULL and a writer that copies the wrong thing shows as the wrong code.
 *
 * A Kuwaiti order is the measurement because its amounts are held in dinars while the platform's own default is the riyal: a row that took its
 * currency from the settings instead of the order would say SAR over an amount of thirty dinars.
 */
class OrderMoneyRowCurrencyTest extends ApiAddressTestCase
{
    use BuildsOrderCurrencySurfaces;
    use BuildsOrderMoneyRowCurrency;
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;

    private const KUWAITI_PRODUCT = 3;

    private const SECOND_KUWAITI_PRODUCT = 7;

    private const SAUDI_PRODUCT = 1;

    /** Thirty dinars, charged, stored as thirty. The platform's own default currency, which no money row of this order may name. */
    private const SOLD_IN = 'KWD';

    private const PLATFORM_DEFAULT = 'SAR';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildOrderCurrencySurfaceWorld();
        $this->createRefundWorld();
        $this->runOrderMoneyRowCurrencyMigration();
    }

    public function test_the_line_of_a_kuwaiti_order_records_the_dinars_it_was_sold_in(): void
    {
        $order = $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);

        $line = DB::table('order_details')->where('order_id', $order->id)->sole();

        $this->assertSame(self::SOLD_IN, $line->currency_code, 'the saved line names the currency its price is counted in');
        $this->assertSame(1.0, (float) $line->currency_rate, 'at the rate that turns its stored price into that currency');
        $this->assertSame(30.0, (float) $line->price, 'and the price is the thirty dinars that were charged, unchanged');
    }

    public function test_the_payment_transaction_of_a_delivered_kuwaiti_order_records_dinars(): void
    {
        $order = $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);

        $this->deliverTheOrder($order);

        $transaction = DB::table('order_transactions')->where('order_id', $order->id)->sole();

        $this->assertSame(self::SOLD_IN, $transaction->currency_code, 'the saved transaction names the currency its amounts are counted in');
        $this->assertSame(1.0, (float) $transaction->currency_rate);
        $this->assertSame(30.0, (float) $transaction->order_amount, 'and the amount is the dinars the order was for');
    }

    public function test_a_refund_filed_on_a_kuwaiti_order_records_dinars(): void
    {
        $order = $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);

        $refund = $this->fileRefundOn($order, $order->customer);

        $this->assertSame(self::SOLD_IN, $refund->currency_code, 'the refund the customer filed names the currency of the money it asks back');
        $this->assertSame(1.0, (float) $refund->currency_rate);
        $this->assertSame(30.0, (float) $refund->amount);
    }

    public function test_the_refund_transaction_of_a_refunded_kuwaiti_order_records_dinars(): void
    {
        $order = $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);
        $refund = $this->fileRefundOn($order, $order->customer);

        $this->approveTheRefund($refund);

        $transaction = DB::table('refund_transactions')->where('order_id', $order->id)->sole();

        $this->assertSame(self::SOLD_IN, $transaction->currency_code, 'the money the panel paid back is recorded in the currency it was taken in');
        $this->assertSame(1.0, (float) $transaction->currency_rate);
        $this->assertSame(30.0, (float) $transaction->amount);
    }

    /** The website's own refund form is a second writer of the same table, and it records the same thing. */
    public function test_a_refund_filed_from_the_website_records_dinars(): void
    {
        $order = $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);

        $refund = $this->fileRefundOnTheWebsite($order, $order->customer);

        $this->assertSame(self::SOLD_IN, $refund->currency_code, 'the refund the website filed names the currency of the money it asks back');
        $this->assertSame(1.0, (float) $refund->currency_rate);
    }

    /**
     * The order of operations, measured instead of read. The lines of an order are saved before the order can be stamped from the one country
     * its products belong to, so a line that asked for the order's currency at the moment it was inserted would find the order still empty and
     * record a NULL. No money row of a stamped order may hold one.
     */
    public function test_no_money_row_of_a_stamped_order_is_left_without_a_currency(): void
    {
        $order = $this->liveThroughAnOrder(country: 'KW', productId: self::KUWAITI_PRODUCT);

        $this->assertSame(self::SOLD_IN, DB::table('orders')->where('id', $order->id)->value('currency_code'), 'the order itself is stamped');

        foreach (OrderStorefrontSnapshot::MONEY_ROW_TABLES as $table) {
            $rows = DB::table($table)->where('order_id', $order->id)->get();

            $this->assertNotEmpty($rows, $table . ' has no money row of this order, so this test proves nothing about it');
            $this->assertSame(
                [],
                $rows->whereNull('currency_code')->pluck('id')->all(),
                $table . ' left a money row of a stamped order without a currency',
            );
            $this->assertSame(
                [self::SOLD_IN],
                $rows->pluck('currency_code')->unique()->values()->all(),
                $table . ' did not take the currency from the order it belongs to',
            );
        }
    }

    /** The one unit stated: no money row may name the platform's own default currency over an amount that is held in dinars. */
    public function test_no_money_row_of_a_kuwaiti_order_names_the_platform_default_currency(): void
    {
        $order = $this->liveThroughAnOrder(country: 'KW', productId: self::KUWAITI_PRODUCT);

        foreach (OrderStorefrontSnapshot::MONEY_ROW_TABLES as $table) {
            $this->assertSame(
                [],
                DB::table($table)->where('order_id', $order->id)->where('currency_code', self::PLATFORM_DEFAULT)->pluck('id')->all(),
                $table . ' labelled an amount of dinars with the currency of the platform settings',
            );
        }
    }

    /**
     * The till is the one writer whose lines exist before the order row does, so the ORDER it stamps them from is one it has just stamped itself.
     * Stamping in the other order leaves every line of a till sale without a currency, which nothing else here would notice.
     */
    public function test_the_lines_of_a_till_sale_record_the_currency_the_till_sold_in(): void
    {
        $order = $this->sellAtTheTill(productId: self::KUWAITI_PRODUCT);

        $this->assertSame(self::SOLD_IN, $order->currency_code, 'the till stamps the order from the one country its lines belong to');

        $line = DB::table('order_details')->where('order_id', $order->id)->sole();

        $this->assertSame(self::SOLD_IN, $line->currency_code, 'and the line takes that currency afterwards, not before the order exists');
        $this->assertSame(1.0, (float) $line->currency_rate);
    }

    /**
     * Every amount of a till sale in ONE unit. The till takes its lines from the cart and stores them as they were typed, but what was paid and
     * what the coupon took off came through a converter into the platform's base unit, so a ten-dinar coupon was stored as 2.67 on an order
     * stamped "every amount here is in dinars at rate one" — and a reader of that record printed 2.666KD, a figure nobody was charged.
     */
    public function test_a_till_sale_stores_every_amount_in_the_currency_the_order_records(): void
    {
        $order = $this->sellAtTheTill(productId: self::KUWAITI_PRODUCT);

        $this->assertSame(self::SOLD_IN, $order->currency_code);
        $this->assertSame(30.0, (float) $order->order_amount, 'the cart total, as the lines hold it');
        $this->assertSame(30.0, (float) DB::table('order_details')->where('order_id', $order->id)->value('price'), 'the line, as the till typed it');
        $this->assertSame(10.0, (float) $order->discount_amount, 'the ten dinars the coupon took off, not the 2.67 a conversion to the platform base makes of them');
        $this->assertSame(30.0, (float) $order->paid_amount, 'and the thirty dinars that were handed over, not 8');
    }

    /**
     * DORMANCY of the same sale. A till of the default storefront records no order currency, so nothing about what it stores may move: its two
     * typed amounts go on being converted exactly as they were.
     */
    public function test_a_till_sale_of_the_default_storefront_stores_what_it_always_stored(): void
    {
        $order = $this->sellAtTheTill(productId: self::SAUDI_PRODUCT);

        $this->assertNull($order->currency_code, 'the default storefront of a converting platform records no currency');
        // To two decimals, which is as many as a money column of this schema holds: what is measured is that the
        // two typed amounts are still divided by the platform's rate, not that a rounding is reproduced exactly.
        $this->assertSame(round(10 / 3.75, 2), round((float) $order->discount_amount, 2), 'the coupon is converted to the platform base, as it always was');
        $this->assertSame(round(30 / 3.75, 2), round((float) $order->paid_amount, 2), 'and so is what was paid');
        $this->assertNotSame(10.0, (float) $order->discount_amount, 'a till of the default storefront stores no amount as it was typed');
    }

    /**
     * A non-cash sale writes its payment transaction inside the checkout rather than at delivery, which is a second writer of the same table and
     * a second place the order's currency has to reach.
     */
    public function test_the_payment_transaction_a_non_cash_checkout_writes_records_dinars(): void
    {
        $order = $this->payByCardFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);

        $transaction = DB::table('order_transactions')->where('order_id', $order->id)->sole();

        $this->assertSame(self::SOLD_IN, $transaction->currency_code, 'the transaction the checkout itself wrote names the order\'s currency');
        $this->assertSame(1.0, (float) $transaction->currency_rate);
    }

    /**
     * The panel's delivery and the seller app's delivery write the same table from two different places. The seller app's is the branch a
     * courier's app uses too, and it is the one that runs when a cash sale is handed over away from the panel.
     */
    public function test_the_payment_transaction_a_seller_writes_on_delivering_a_cash_sale_records_dinars(): void
    {
        $order = $this->sellAtTheTill(productId: self::KUWAITI_PRODUCT, overrides: ['customer_id' => $this->makeCustomer()->id, 'fulfillment' => 'delivery', 'payment_method' => 'cash', 'paid_amount' => 0]);

        $this->deliverAsTheSeller($order);

        $transaction = DB::table('order_transactions')->where('order_id', $order->id)->sole();

        $this->assertSame(self::SOLD_IN, $transaction->currency_code, 'the row the seller app wrote names the order\'s own currency');
        $this->assertSame(1.0, (float) $transaction->currency_rate);
    }

    /**
     * An edit adds a line to an order that is already stamped, and the writer it adds it with records no currency of its own: an edited Kuwaiti
     * order ended with some lines naming the dinar and some naming nothing, in the very column this records what an amount is counted in.
     */
    public function test_a_line_an_edit_adds_to_a_stamped_order_records_that_order_s_currency(): void
    {
        $order = $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);
        $this->addASecondKuwaitiProduct();

        $this->editTheLinesOf($order, [$this->editedLine(self::KUWAITI_PRODUCT), $this->editedLine(self::SECOND_KUWAITI_PRODUCT)]);

        $lines = DB::table('order_details')->where('order_id', $order->id)->get();

        $this->assertCount(2, $lines, 'the edit kept one line and added one');
        $this->assertSame([], $lines->whereNull('currency_code')->pluck('product_id')->all(), 'the edit left a line of a stamped order without a currency');
        $this->assertSame([self::SOLD_IN], $lines->pluck('currency_code')->unique()->values()->all());
    }

    /**
     * What an amount is counted in is written once. A row that already carries a currency is never rewritten, so a second call — a retried
     * transaction, a second edit, a backfill — records the same thing once and cannot relabel an amount that is already labelled.
     */
    public function test_a_money_row_that_already_carries_a_currency_is_never_relabelled(): void
    {
        $order = $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);
        $this->addASecondKuwaitiProduct();
        $this->editTheLinesOf($order, [$this->editedLine(self::KUWAITI_PRODUCT), $this->editedLine(self::SECOND_KUWAITI_PRODUCT)]);

        $labelled = DB::table('order_details')->where('order_id', $order->id)->orderBy('id')->value('id');
        DB::table('order_details')->where('id', $labelled)->update(['currency_code' => 'AED', 'currency_rate' => 1]);
        DB::table('order_details')->where('id', '!=', $labelled)->where('order_id', $order->id)->update(['currency_code' => null, 'currency_rate' => null]);

        app(OrderStorefrontSnapshot::class)->stampMoneyRows(table: 'order_details', orderId: $order->id);

        $this->assertSame('AED', DB::table('order_details')->where('id', $labelled)->value('currency_code'), 'a row that already said what its amount is counted in was overwritten');
        $this->assertSame(
            [self::SOLD_IN],
            DB::table('order_details')->where('order_id', $order->id)->where('id', '!=', $labelled)->pluck('currency_code')->unique()->values()->all(),
            'and the row that said nothing took the order\'s currency',
        );
    }

    /**
     * DORMANCY, for an order that records no currency. The default storefront on a platform that converts from a base currency cannot say what
     * an order's figures are in, so the order records nothing — and its money rows must record nothing either rather than guess.
     */
    public function test_an_order_of_the_default_storefront_leaves_its_money_rows_alone(): void
    {
        $order = $this->liveThroughAnOrder(country: 'SA', productId: self::SAUDI_PRODUCT);

        $this->assertNull(DB::table('orders')->where('id', $order->id)->value('currency_code'), 'the order itself records no currency');

        foreach (OrderStorefrontSnapshot::MONEY_ROW_TABLES as $table) {
            $rows = DB::table($table)->where('order_id', $order->id)->get();

            $this->assertNotEmpty($rows, $table . ' has no money row of this order, so this test proves nothing about it');
            $this->assertSame([], $rows->whereNotNull('currency_code')->pluck('id')->all(), $table . ' invented a currency for an order that has none');
            $this->assertSame([], $rows->whereNotNull('currency_rate')->pluck('id')->all(), $table . ' invented a rate for an order that has none');
        }
    }

    /**
     * DORMANCY, before the migration has run. A shop whose money rows have no currency columns places, refunds, pays back and delivers an order
     * exactly as it did: every route answers as it answered, and every amount is stored as it was stored.
     */
    public function test_a_shop_whose_money_rows_have_no_currency_columns_is_unchanged(): void
    {
        $this->dropOrderMoneyRowCurrencyColumns();

        $before = $this->storedAmountsOf($this->liveThroughAnOrder(country: 'KW', productId: self::KUWAITI_PRODUCT)->id);

        foreach (OrderStorefrontSnapshot::MONEY_ROW_TABLES as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'currency_code'), $table . ' still has the column this test runs without');
        }

        $this->restoreOrderMoneyRowCurrencyColumns();

        $after = $this->storedAmountsOf($this->liveThroughAnOrder(country: 'KW', productId: self::KUWAITI_PRODUCT)->id);

        $this->assertNotEmpty($before, 'the order placed before the migration stored amounts to compare');
        $this->assertSame($before, $after, 'an amount an order stores changed when the currency columns appeared');
    }

    /**
     * What a sold amount is counted in cannot be worked out again from the amount, so the rollback refuses while any money row carries a
     * currency — and rolls back cleanly once none does, which is the state a shop that never switched a storefront currency on is in.
     */
    public function test_the_rollback_is_refused_while_a_money_row_carries_a_currency_and_runs_when_none_does(): void
    {
        $migration = require database_path('migrations/2026_11_27_100000_add_order_currency_to_order_money_rows.php');
        $this->liveThroughAnOrder(country: 'KW', productId: self::KUWAITI_PRODUCT);

        try {
            $migration->down();
            $this->fail('The rollback must be refused while a money row carries a currency.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('Rolling this migration back would forget', $refusal->getMessage());
        }

        foreach (OrderStorefrontSnapshot::MONEY_ROW_TABLES as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'currency_code'), $table . ' lost its column to a rollback that was refused');
        }

        foreach (OrderStorefrontSnapshot::MONEY_ROW_TABLES as $table) {
            DB::table($table)->update(['currency_code' => null, 'currency_rate' => null]);
        }
        $migration->down();

        foreach (OrderStorefrontSnapshot::MONEY_ROW_TABLES as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'currency_code'), $table . ' kept currency_code through a rollback');
            $this->assertFalse(Schema::hasColumn($table, 'currency_rate'), $table . ' kept currency_rate through a rollback');
        }
    }
}
