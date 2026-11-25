<?php

namespace Tests\Feature\Storefront;

use App\Models\CategoryShippingCost;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontCategoryShippingCosts;
use App\Services\Storefront\StorefrontCurrency;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\TestCase;

/**
 * H1-14 for the per-category shipping costs: a cost typed for one market is charged by that market and by no other.
 *
 * The same decision as for the methods, and the same rule reading it (`StorefrontMarketRows`), so these tests are the methods' tests asked of the other
 * table -- deliberately, because the two tables must not come to disagree. The first one protects what exists: with storefronts off, every cost is read
 * exactly as before.
 *
 * One difference, and it is about money: a category cost row has no `status` column, so there is no such thing as one that is switched off, and every row
 * of a market counts as that market's own.
 */
class CategoryShippingCostStorefrontTest extends TestCase
{
    use BuildsStorefrontMoneyWorld;

    private const SAUDI = 'SA';

    private const KUWAIT = 'KW';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCategoryCosts();
        app(StorefrontCategoryShippingCosts::class)->forget();
    }

    protected function tearDown(): void
    {
        $this->dropStorefrontMoneyWorld();
        Schema::dropIfExists('category_shipping_costs');
        parent::tearDown();
    }

    public function test_with_storefronts_off_every_cost_is_read_exactly_as_before(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $this->costsOf([['SA cost', self::SAUDI], ['KW cost', self::KUWAIT], ['Legacy', null]]);

        $this->assertSame(3, $this->readableCosts(), 'the switch is off, so nothing is narrowed');
        $this->assertFalse(app(StorefrontCategoryShippingCosts::class)->applies());
    }

    public function test_the_default_storefront_reads_its_own_costs_and_the_ones_that_name_no_market(): void
    {
        $this->storefrontsOn(serving: self::SAUDI);
        $this->costsOf([['SA cost', self::SAUDI], ['KW cost', self::KUWAIT], ['Legacy', null]]);

        $this->assertSame(2, $this->readableCosts(), 'its own and the legacy one, which was typed for it');
    }

    public function test_another_market_reads_only_the_costs_that_name_it(): void
    {
        $this->storefrontsOn(serving: self::KUWAIT);
        $this->costsOf([['SA cost', self::SAUDI], ['KW cost', self::KUWAIT], ['Legacy', null]]);

        $this->assertSame(1, $this->readableCosts(), 'the Saudi cost and the legacy one are not inherited');
    }

    public function test_a_market_with_no_cost_of_its_own_reads_none(): void
    {
        $this->storefrontsOn(serving: 'BH');
        $this->costsOf([['SA cost', self::SAUDI], ['Legacy', null]]);

        $this->assertSame(0, $this->readableCosts(), 'none is the right answer; another market\'s cost is not a fallback');
    }

    public function test_a_market_that_owns_a_category_cost_may_be_priced_by_it_while_its_own_currency_is_in_force(): void
    {
        $this->moneyWorld();
        $this->costsOf([['KW cost', self::KUWAIT]]);

        $type = $this->inStorefront(self::KUWAIT, function (): string {
            app(StorefrontCategoryShippingCosts::class)->forget();

            return (string) app(StorefrontCurrency::class)->shippingTypeInForce('category_wise');
        });

        $this->assertSame('category_wise', $type, 'the cost was typed for this market, so it is already in this market\'s money');
    }

    public function test_a_market_with_no_cost_of_its_own_is_still_priced_from_the_product(): void
    {
        $this->moneyWorld();
        $this->costsOf([['SA cost', self::SAUDI], ['Legacy', null]]);

        $type = $this->inStorefront(self::KUWAIT, function (): string {
            app(StorefrontCategoryShippingCosts::class)->forget();

            return (string) app(StorefrontCurrency::class)->shippingTypeInForce('category_wise');
        });

        $this->assertSame('product_wise', $type, 'the unmarked cost is the default storefront\'s, in the platform\'s money');
    }

    /** Each type asks its own table: owning a category cost says nothing about whether a method may be charged. */
    public function test_owning_a_cost_does_not_make_a_method_chargeable(): void
    {
        $this->moneyWorld();
        $this->costsOf([['KW cost', self::KUWAIT]]);

        $type = $this->inStorefront(self::KUWAIT, function (): string {
            app(StorefrontCategoryShippingCosts::class)->forget();

            return (string) app(StorefrontCurrency::class)->shippingTypeInForce('order_wise');
        });

        $this->assertSame('product_wise', $type, 'this market owns a category cost and no method, so order_wise charges nothing of its own');
    }

    public function test_the_migration_adds_the_column_runs_twice_and_refuses_to_roll_back_once_a_cost_carries_a_market(): void
    {
        $migration = require database_path('migrations/2026_12_22_100100_add_storefront_country_to_category_shipping_costs.php');
        Schema::table('category_shipping_costs', fn (Blueprint $table) => $table->dropColumn(StorefrontCategoryShippingCosts::COLUMN));

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('category_shipping_costs', StorefrontCategoryShippingCosts::COLUMN));

        $this->costsOf([['KW cost', self::KUWAIT]]);

        try {
            $migration->down();
            $this->fail('The rollback must be refused while a cost carries a market.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('Rolling this migration back would forget', $refusal->getMessage());
        }
    }

    /** How many costs the storefront being served can read of the three in the table. */
    private function readableCosts(): int
    {
        return CategoryShippingCost::query()->forStorefront()->count();
    }

    /** @param list<array{0: string, 1: ?string}> $costs */
    private function costsOf(array $costs): void
    {
        foreach ($costs as $index => [$title, $market]) {
            DB::table('category_shipping_costs')->insert([
                'seller_id' => 0,
                'category_id' => $index + 1,
                'cost' => 15,
                'multiply_qty' => 0,
                StorefrontCategoryShippingCosts::COLUMN => $market,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function moneyWorld(): void
    {
        $this->buildStorefrontMoneyWorld();
        $this->buildCategoryCosts();
        app(StorefrontCategoryShippingCosts::class)->forget();
    }

    private function storefrontsOn(string $serving): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.default_country' => self::SAUDI]);
        app()->instance(StorefrontContext::class, new StorefrontContext(country: $serving, currency: 'SAR', storefrontsEnabled: true));
        app(StorefrontCategoryShippingCosts::class)->forget();
    }

    private function buildCategoryCosts(): void
    {
        Schema::dropIfExists('category_shipping_costs');
        Schema::create('category_shipping_costs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedInteger('category_id')->nullable();
            $table->float('cost')->nullable();
            $table->boolean('multiply_qty')->nullable();
            $table->char(StorefrontCategoryShippingCosts::COLUMN, 2)->nullable();
            $table->timestamps();
        });
    }
}
