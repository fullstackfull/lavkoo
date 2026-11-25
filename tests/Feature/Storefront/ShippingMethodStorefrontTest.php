<?php

namespace Tests\Feature\Storefront;

use App\Models\ShippingMethod;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontCurrency;
use App\Services\Storefront\StorefrontShippingMethods;
use App\Utils\Helpers;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\TestCase;

/**
 * H1-14: a storefront lists and charges only its own shipping methods, and inherits none.
 *
 * The decision this pins is that local shipping rules belong to a storefront and its selling company. A method made for the Saudi store at a Saudi price
 * in Saudi riyals must not quietly become Kuwait's delivery option, which is what "inherited" would mean and what the platform's own decisions forbid.
 *
 * The first test is the one that protects what exists: with storefronts off, every listing is the listing it has always been -- the same methods in the
 * same order, by the same query -- even on a database where some methods name a market and some do not.
 */
class ShippingMethodStorefrontTest extends TestCase
{
    use BuildsStorefrontMoneyWorld;

    private const SAUDI = 'SA';

    private const KUWAIT = 'KW';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShippingMethods();
        app(StorefrontShippingMethods::class)->forget();
    }

    protected function tearDown(): void
    {
        $this->dropStorefrontMoneyWorld();
        Schema::dropIfExists('shipping_methods');
        parent::tearDown();
    }

    public function test_with_storefronts_off_every_method_is_offered_exactly_as_before(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $this->methodsOf(['Saudi standard' => self::SAUDI, 'Kuwaiti standard' => self::KUWAIT, 'Legacy' => null]);

        $this->assertSame(
            ['Saudi standard', 'Kuwaiti standard', 'Legacy'],
            $this->offered(),
            'the switch is off, so nothing is narrowed and the listing is the one this platform has always had',
        );
        $this->assertFalse(app(StorefrontShippingMethods::class)->applies());
        $this->assertNull(app(StorefrontShippingMethods::class)->stampFor());
    }

    public function test_the_default_storefront_is_offered_its_own_methods_and_the_ones_that_name_no_market(): void
    {
        $this->storefrontsOn(serving: self::SAUDI);
        $this->methodsOf(['Saudi standard' => self::SAUDI, 'Kuwaiti standard' => self::KUWAIT, 'Legacy' => null]);

        $this->assertSame(
            ['Saudi standard', 'Legacy'],
            $this->offered(),
            'a method that names no market is the default storefront\'s, because every method of this platform was made for it',
        );
    }

    public function test_another_market_is_offered_only_the_methods_that_name_it(): void
    {
        $this->storefrontsOn(serving: self::KUWAIT);
        $this->methodsOf(['Saudi standard' => self::SAUDI, 'Kuwaiti standard' => self::KUWAIT, 'Legacy' => null]);

        $this->assertSame(
            ['Kuwaiti standard'],
            $this->offered(),
            'the Saudi method and the legacy one are not inherited: that is the decision this test exists for',
        );
    }

    public function test_a_market_with_no_method_of_its_own_is_offered_none_rather_than_another_markets(): void
    {
        $this->storefrontsOn(serving: 'BH');
        $this->methodsOf(['Saudi standard' => self::SAUDI, 'Kuwaiti standard' => self::KUWAIT, 'Legacy' => null]);

        $this->assertSame([], $this->offered(), 'none is the right answer; the Saudi methods are not a fallback');
    }

    public function test_a_sellers_methods_are_narrowed_the_same_way_as_the_platforms(): void
    {
        $this->storefrontsOn(serving: self::KUWAIT);
        $this->methodOf(title: 'Seller Saudi', market: self::SAUDI, creatorType: 'seller', creatorId: 7);
        $this->methodOf(title: 'Seller Kuwait', market: self::KUWAIT, creatorType: 'seller', creatorId: 7);

        $this->assertSame(
            ['Seller Kuwait'],
            Helpers::getShippingMethods(7, 'seller')->pluck('title')->all(),
        );
    }

    public function test_a_method_made_in_a_storefront_is_stamped_with_it(): void
    {
        $this->storefrontsOn(serving: self::KUWAIT);

        $this->assertSame(self::KUWAIT, app(StorefrontShippingMethods::class)->stampFor());
        $this->assertSame(self::SAUDI, app(StorefrontShippingMethods::class)->stampFor(self::SAUDI), 'a market may be named outright');
    }

    /**
     * The market is compared BYTE FOR BYTE, as the column is declared (ascii_bin) and as PHP compares it.
     *
     * So nothing may store a code in another case, and the stamp is what makes sure of it. A read that quietly matched
     * `kw` against `KW` would be relying on a collation, and the day the column's collation changed the methods of a
     * market would disappear from it.
     */
    public function test_the_market_is_compared_byte_for_byte_and_the_stamp_is_what_keeps_it_upper_case(): void
    {
        $this->storefrontsOn(serving: self::KUWAIT);
        $this->methodsOf(['Written in lower case' => 'kw', 'Kuwaiti standard' => self::KUWAIT]);

        $this->assertSame(['Kuwaiti standard'], $this->offered(), 'only the row written the way the stamp writes it');
        $this->assertSame(self::KUWAIT, app(StorefrontShippingMethods::class)->stampFor('kw'), 'so the stamp never writes lower case');
        $this->assertSame(self::KUWAIT, app(StorefrontShippingMethods::class)->stampFor(' kw '), 'nor keeps the spaces somebody typed');
    }

    public function test_a_database_without_the_column_narrows_nothing(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        Schema::table('shipping_methods', fn (Blueprint $table) => $table->dropColumn(StorefrontShippingMethods::COLUMN));
        app(StorefrontShippingMethods::class)->forget();
        DB::table('shipping_methods')->insert([
            ['title' => 'Legacy', 'cost' => 15, 'status' => 1, 'creator_type' => 'admin', 'creator_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertFalse(app(StorefrontShippingMethods::class)->applies());
        $this->assertSame(['Legacy'], $this->offered(), 'before the migration there is no market to read, so nothing is narrowed');
    }

    public function test_the_migration_adds_the_column_and_refuses_to_roll_back_once_a_method_carries_a_market(): void
    {
        $migration = require database_path('migrations/2026_12_22_100000_add_storefront_country_to_shipping_methods.php');
        Schema::table('shipping_methods', fn (Blueprint $table) => $table->dropColumn(StorefrontShippingMethods::COLUMN));

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('shipping_methods', StorefrontShippingMethods::COLUMN), 'and running it twice is not a failure');

        $this->methodsOf(['Kuwaiti standard' => self::KUWAIT]);

        try {
            $migration->down();
            $this->fail('The rollback must be refused while a method carries a market.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('Rolling this migration back would forget', $refusal->getMessage());
        }
    }

    public function test_the_rollback_is_allowed_while_no_method_carries_a_market(): void
    {
        $migration = require database_path('migrations/2026_12_22_100000_add_storefront_country_to_shipping_methods.php');
        $this->methodsOf(['Legacy' => null]);

        $migration->down();

        $this->assertFalse(Schema::hasColumn('shipping_methods', StorefrontShippingMethods::COLUMN));
    }

    /**
     * H1-14's charging half: a market that owns a method may charge it, and one that does not prices shipping as it always did.
     *
     * `shippingTypeInForce` forced `product_wise` on every storefront with its own currency, because the platform's shipping tables are in the platform's
     * money. A market's OWN method is not: its cost was typed for that market. Without this exception a market could own a method and never charge it.
     *
     * These run inside `inStorefront()`, the real money world, so the storefront's currency is genuinely in force. Asserting this with only the config
     * switch on proves nothing: `currencyInForce()` answers null without a storefront and a currency row, the method returns the stored type by its first
     * line, and the test passes without the exception existing at all. The first version of these tests did exactly that.
     */
    public function test_a_market_that_owns_a_method_charges_it_while_its_own_currency_is_in_force(): void
    {
        $this->moneyWorld();
        $this->methodsOf(['Kuwaiti standard' => self::KUWAIT]);

        $answered = $this->inStorefront(self::KUWAIT, function (): array {
            app(StorefrontShippingMethods::class)->forget();

            return [
                'owns' => app(StorefrontShippingMethods::class)->marketHasOwnMethods(),
                'type' => app(StorefrontCurrency::class)->shippingTypeInForce('order_wise'),
            ];
        });

        $this->assertTrue($answered['owns']);
        $this->assertSame('order_wise', $answered['type'], 'the method belongs to this market, so order_wise prices it in this market\'s money');
    }

    public function test_a_market_with_no_method_of_its_own_still_prices_shipping_from_the_product(): void
    {
        $this->moneyWorld();
        $this->methodsOf(['Saudi standard' => self::SAUDI, 'Legacy' => null]);

        $answered = $this->inStorefront(self::KUWAIT, function (): array {
            app(StorefrontShippingMethods::class)->forget();

            return [
                'owns' => app(StorefrontShippingMethods::class)->marketHasOwnMethods(),
                'type' => app(StorefrontCurrency::class)->shippingTypeInForce('order_wise'),
            ];
        });

        $this->assertFalse($answered['owns'], 'the unmarked method is the default storefront\'s, typed in the platform\'s money');
        $this->assertSame('product_wise', $answered['type']);
    }

    /**
     * Each type asks its OWN table. Owning a method says nothing about whether the per-category costs may be charged, and this world has none of those,
     * so `category_wise` is priced from the product -- and `product_wise` already means that.
     */
    public function test_owning_a_method_does_not_make_a_category_cost_chargeable(): void
    {
        $this->moneyWorld();
        $this->methodsOf(['Kuwaiti standard' => self::KUWAIT]);

        $types = $this->inStorefront(self::KUWAIT, function (): array {
            app(StorefrontShippingMethods::class)->forget();
            $currency = app(StorefrontCurrency::class);

            return [$currency->shippingTypeInForce('category_wise'), $currency->shippingTypeInForce('product_wise')];
        });

        $this->assertSame(['product_wise', 'product_wise'], $types);
    }

    public function test_a_switched_off_method_is_not_a_method_this_market_owns(): void
    {
        $this->moneyWorld();
        DB::table('shipping_methods')->insert([
            'title' => 'Kuwaiti, switched off', 'cost' => 15, 'status' => 0, 'creator_type' => 'admin',
            'creator_id' => null, StorefrontShippingMethods::COLUMN => self::KUWAIT, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $type = $this->inStorefront(self::KUWAIT, function (): string {
            app(StorefrontShippingMethods::class)->forget();

            return (string) app(StorefrontCurrency::class)->shippingTypeInForce('order_wise');
        });

        $this->assertSame('product_wise', $type, 'a method nobody offers is not a method this market can charge');
    }

    public function test_the_default_storefront_prices_shipping_exactly_as_it_always_did(): void
    {
        $this->moneyWorld();
        $this->methodsOf(['Legacy' => null]);

        $types = $this->inStorefront(self::SAUDI, function (): array {
            app(StorefrontShippingMethods::class)->forget();
            $currency = app(StorefrontCurrency::class);

            return array_map(fn (?string $stored): ?string => $currency->shippingTypeInForce($stored), ['order_wise', 'category_wise', 'product_wise', null]);
        });

        $this->assertSame(
            ['order_wise', 'category_wise', 'product_wise', null],
            $types,
            'the default storefront keeps the platform\'s money, so the stored type stands whatever it is',
        );
    }

    /** @return list<string> */
    private function offered(): array
    {
        return Helpers::getShippingMethods(null, 'admin')->pluck('title')->all();
    }

    /** @param array<string, ?string> $methods title to the market it belongs to */
    private function methodsOf(array $methods): void
    {
        foreach ($methods as $title => $market) {
            $this->methodOf(title: $title, market: $market);
        }
    }

    private function methodOf(string $title, ?string $market, string $creatorType = 'admin', ?int $creatorId = null): void
    {
        DB::table('shipping_methods')->insert([
            'title' => $title,
            'cost' => 15,
            'duration' => '3 days',
            'status' => 1,
            'creator_type' => $creatorType,
            'creator_id' => $creatorId,
            StorefrontShippingMethods::COLUMN => $market,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storefrontsOn(string $serving): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.default_country' => self::SAUDI]);
        app()->instance(StorefrontContext::class, new StorefrontContext(country: $serving, currency: 'SAR', storefrontsEnabled: true));
        app(StorefrontShippingMethods::class)->forget();
    }

    /** The storefronts with currencies of their own, so that `currencyInForce()` answers one. */
    private function moneyWorld(): void
    {
        $this->buildStorefrontMoneyWorld();
        $this->buildShippingMethods();
        app(StorefrontShippingMethods::class)->forget();
    }

    /** `shipping_methods` comes from the installation dump, so a test that reads it builds it. */
    private function buildShippingMethods(): void
    {
        Schema::dropIfExists('shipping_methods');
        Schema::create('shipping_methods', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->string('creator_type', 24)->nullable();
            $table->string('title')->nullable();
            $table->decimal('cost', 24, 2)->default(0);
            $table->string('duration')->nullable();
            $table->boolean('status')->default(true);
            $table->char(StorefrontShippingMethods::COLUMN, 2)->nullable();
            $table->timestamps();
        });
    }
}
