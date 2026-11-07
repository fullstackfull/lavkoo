<?php

namespace Tests\Feature\SalesChannels;

use App\Models\InventoryPool;
use App\Models\InventorySourcePolicy;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\Allocation\PoolSettings;
use App\Services\SalesChannels\Inventory\Allocation\StockShareValidator;
use App\Services\SalesChannels\Inventory\PoolKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * The application's own connection is not strict (a value that does not fit is cut or clamped, not refused), so the ranges of a share live in StockShareValidator and nowhere
 * else. Run with DB_STRICT_TESTS=1 on MySQL or MariaDB to see what a strict server says to the same values.
 */
class StockShareStrictModeTest extends TestCase
{
    use BuildsSalesChannelSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
    }

    private function strictRun(): bool
    {
        return getenv('DB_STRICT_TESTS') === '1' && in_array(config('database.default'), ['mysql', 'mariadb'], true);
    }

    public function test_the_largest_values_the_validator_accepts_are_stored_exactly_on_every_table(): void
    {
        $validator = new StockShareValidator();
        $share = $validator->share(['stock_share_mode' => 'fixed', 'stock_share_value' => 1000000, 'stock_priority' => 1000]);
        $percent = $validator->share(['stock_share_mode' => 'percent', 'stock_share_value' => 100]);
        $priority = $validator->share(['stock_share_mode' => 'priority', 'stock_priority' => 0]);

        $listing = SalesChannelProductMapping::create(['account_id' => 1, 'listing_key' => 'L-1'] + $share);
        $policy = InventorySourcePolicy::create(['scope' => 'account', 'account_id' => 1] + $percent);
        $other = SalesChannelProductMapping::create(['account_id' => 1, 'listing_key' => 'L-2'] + $priority);
        $pool = app(PoolSettings::class)->save(new PoolKey(1, 5), ['storefront_reserve_mode' => 'fixed', 'storefront_reserve_value' => 1000000, 'safety_buffer' => 1000000], adminId: 1);

        $this->assertSame(['fixed', 1000000, 1000], [$listing->fresh()->stock_share_mode, $listing->fresh()->stock_share_value, $listing->fresh()->stock_priority]);
        $this->assertSame(['percent', 100, null], [$policy->fresh()->stock_share_mode, $policy->fresh()->stock_share_value, $policy->fresh()->stock_priority]);
        $this->assertSame(['priority', null, 0], [$other->fresh()->stock_share_mode, $other->fresh()->stock_share_value, $other->fresh()->stock_priority], 'the longest mode name fits its column');
        $this->assertSame(['fixed', 1000000, 1000000], [$pool->fresh()->storefront_reserve_mode, $pool->fresh()->storefront_reserve_value, $pool->fresh()->safety_buffer]);
    }

    public function test_a_percentage_of_two_hundred_and_fifty_is_something_no_database_stops(): void
    {
        $listing = SalesChannelProductMapping::create(['account_id' => 1, 'listing_key' => 'L-1', 'stock_share_mode' => 'percent', 'stock_share_value' => 250]);

        $this->assertSame(250, $listing->fresh()->stock_share_value, 'there is no range check in the table: the validator is the only thing between a typing mistake and a division of the stock');
    }

    public function test_a_strict_server_refuses_a_negative_share_and_a_mode_that_does_not_fit_where_a_lax_one_would_alter_it(): void
    {
        if (!$this->strictRun()) {
            $this->markTestSkipped('Only in the strict-mode run (DB_STRICT_TESTS=1 on MySQL/MariaDB).');
        }

        foreach ([['stock_share_value' => -1], ['stock_share_mode' => 'far-too-long-a-mode'], ['stock_priority' => 70000]] as $values) {
            try {
                DB::table('sales_channel_product_mappings')->insert(['account_id' => 1, 'listing_key' => 'S-' . md5(json_encode($values))] + $values);
                $this->fail('A strict server stored ' . json_encode($values));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, InventoryPool::count());
    }
}
