<?php

namespace Tests\Feature\Storefront;

use App\Events\RefundEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsStorefrontRefundWorld;

/**
 * D1-10: a customer's refund request, made through the API the app calls, carries the money and the company of the order it refunds, copied from the order's own record.
 * The amount is what it always was.
 */
class StorefrontRefundRecordTest extends ApiAddressTestCase
{
    use BuildsStorefrontRefundWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTheSixStorefrontsWorld();
        (require database_path('migrations/2026_11_27_100100_add_legal_entity_to_orders_table.php'))->up();
        Event::fake([RefundEvent::class]);

        $this->buildTheRefundTables();
    }

    protected function tearDown(): void
    {
        $this->dropTheRefundTables();

        parent::tearDown();
    }

    public function test_a_refund_request_of_a_kuwaiti_order_carries_its_country_currency_rate_and_company(): void
    {
        [$customer, $orderId, $lineId] = $this->placeFrom('KW', 3);
        DB::table('orders')->where('id', $orderId)->update(['legal_entity_id' => 5]);

        $this->askForRefund($customer, 'KW', $lineId);

        $refund = DB::table('refund_requests')->first();
        $this->assertSame(['KW', 'KWD', 1.0, 5], [$refund->storefront_country, $refund->currency_code, (float) $refund->currency_rate, (int) $refund->legal_entity_id]);
        $this->assertEquals(30, $refund->amount, 'the amount is the order line\'s, as it always was');
        $this->assertSame($orderId, (int) $refund->order_id);
    }

    public function test_each_storefront_refund_names_its_own_currency(): void
    {
        foreach ([['AE', 2, 'AED'], ['QA', 4, 'QAR'], ['BH', 5, 'BHD'], ['OM', 6, 'OMR']] as [$country, $productId, $currency]) {
            [$customer, , $lineId] = $this->placeFrom($country, $productId);
            $this->askForRefund($customer, $country, $lineId);
        }

        $this->assertSame(
            ['AE' => 'AED', 'QA' => 'QAR', 'BH' => 'BHD', 'OM' => 'OMR'],
            DB::table('refund_requests')->orderBy('id')->pluck('currency_code', 'storefront_country')->all(),
        );
    }

    public function test_a_refund_request_of_an_order_that_recorded_nothing_is_made_as_it_always_was(): void
    {
        [$customer, $orderId, $lineId] = $this->placeFrom('SA', 1);
        DB::table('orders')->where('id', $orderId)->update(['storefront_country' => null, 'currency_code' => null, 'currency_rate' => null, 'legal_entity_id' => null]);

        $this->askForRefund($customer, 'SA', $lineId);

        $refund = DB::table('refund_requests')->first();
        $this->assertSame([null, null, null, null], [$refund->storefront_country, $refund->currency_code, $refund->currency_rate, $refund->legal_entity_id]);
        $this->assertEquals(100, $refund->amount);
    }

    public function test_a_refund_request_before_the_migration_is_made_without_the_columns(): void
    {
        (require database_path('migrations/2026_12_03_100000_add_order_money_to_refund_tables.php'))->down();
        [$customer, , $lineId] = $this->placeFrom('KW', 3);

        $this->askForRefund($customer, 'KW', $lineId);

        $this->assertSame(1, DB::table('refund_requests')->count());
        $this->assertFalse(Schema::hasColumn('refund_requests', 'currency_code'));
    }
}
