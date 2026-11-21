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
