<?php

namespace Tests\Feature\Storefront;

use App\Services\Pos\PosSaleGate;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontLifecycle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A till follows the market of the products it rings up, and respects that market's state (C-16, I2-09).
 *
 * The web checkout has asked `StorefrontSellingGate` since GCC-11: a storefront that is paused, or that has not met what it needs to open,
 * takes no new order. The three tills asked nothing -- the admin panel's, the vendor panel's and the seller app's -- so a market could be
 * stopped for every customer on the website and go on selling across the counter all afternoon. That is not a pause, and it is the owner's
 * own decision that it should not be one (I2-09).
 *
 * The market of a till sale is the market of its PRODUCTS and not of the request. That is I2-09's wording and also the only readable
 * answer: a panel is served from no storefront, so asking the request's storefront would read the DEFAULT market for every till in every
 * country -- which is the error this platform has made before, in four places that told a carrier where a parcel was going.
 *
 * The first test is the dormancy, and it is the one that matters: with storefronts off, every till rings up exactly what it rings up today.
 */
class PosSaleGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('type')->nullable();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }

        // Pausing a market is a recorded decision and `recordOrFail` refuses to pause one it cannot record, so the trail is built here.
        if (!Schema::hasTable('audit_logs')) {
            (require database_path('migrations/2026_12_29_100000_create_audit_logs_and_name_the_market_each_line_concerns.php'))->up();
            \App\Services\AuditLogger::forget();
        }

        Schema::dropIfExists('products');
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->char(StorefrontCatalog::COLUMN, 2)->nullable();
            $table->timestamps();
        });
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Saudi', StorefrontCatalog::COLUMN => 'SA'],
            ['id' => 2, 'name' => 'Kuwaiti', StorefrontCatalog::COLUMN => 'KW'],
            ['id' => 3, 'name' => 'Unmarked', StorefrontCatalog::COLUMN => null],
        ]);

        config([
            'gcc.storefronts.default_country' => 'SA',
            // Set here and never in `config/gcc.php`: which markets are active is the owner's G0 decision and never a test's.
            'gcc.storefronts.countries' => [
                'SA' => ['path' => 'sa', 'currency' => 'SAR', 'active' => true],
                'KW' => ['path' => 'kw', 'currency' => 'KWD', 'active' => true],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('products');

        parent::tearDown();
    }

    private function gate(): PosSaleGate
    {
        return app(PosSaleGate::class);
    }

    private function stop(string $market): void
    {
        app(StorefrontLifecycle::class)->pause(
            country: $market,
            mode: StorefrontLifecycle::BROWSE_ONLY,
            by: 'The head of the operations team',
            reason: 'Decision of the operations meeting',
        );
        $this->beforeApplicationDestroyed(static fn () => DB::table('business_settings')->where('type', StorefrontLifecycle::settingName(country: $market))->delete());
    }

    public function test_with_storefronts_off_every_till_rings_up_exactly_what_it_rings_up_today(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $this->stop('SA');

        $this->assertTrue($this->gate()->maySell([1, 2, 3]), 'a platform with one market has no market state to respect');
        $this->assertNull($this->gate()->refusalFor([1]));
    }

    public function test_a_till_may_not_ring_up_a_product_of_a_market_that_has_been_stopped(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->stop('SA');

        $this->assertSame('SA', $this->gate()->refusalFor([1]), 'the refusal names the market, which is what an operator needs');
        $this->assertFalse($this->gate()->maySell([1]));
    }

    /**
     * The twin: the same till, the same product, with nothing paused.
     *
     * The DEFAULT storefront is the pair to make this on, because it is active without a record and meets what it needs; a second market
     * answers "may not sell" for a different reason entirely -- it has not been opened yet -- and a refusal test against one of those
     * would pass whether or not a pause does anything.
     */
    public function test_the_same_till_sells_while_the_market_is_selling(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $this->assertNull($this->gate()->refusalFor([1, 3]));
    }

    /** A cart of two markets is judged on both, and one market that may not sell refuses the whole sale. */
    public function test_a_cart_of_two_markets_is_refused_when_either_of_them_may_not_sell(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $this->assertSame(['SA', 'KW'], $this->gate()->marketsOf([1, 2]));
        $this->assertSame('KW', $this->gate()->refusalFor([1, 2]), 'Kuwait has not been opened for sale, so its line stops the sale');
    }

    /** A product that names no market is the default storefront's, which is every product of a single-market platform. */
    public function test_an_unmarked_product_belongs_to_the_default_storefront(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $this->assertSame(['SA'], $this->gate()->marketsOf([3]));

        $this->stop('SA');

        $this->assertSame('SA', $this->gate()->refusalFor([3]));
    }

    /** A market nobody configured has no currency to ask the gate about, so a till is not stopped over a configuration gap. */
    public function test_a_market_the_configuration_does_not_name_does_not_stop_a_till(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        DB::table('products')->where('id', 1)->update([StorefrontCatalog::COLUMN => 'QA']);

        $this->assertSame(['QA'], $this->gate()->marketsOf([1]));
        $this->assertNull($this->gate()->refusalFor([1]));
    }

    /** Before the market column exists there is one market, and it is the default one. */
    public function test_before_the_market_column_exists_every_product_is_the_default_markets(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        Schema::table('products', static fn (Blueprint $table) => $table->dropColumn(StorefrontCatalog::COLUMN));

        $this->assertSame(['SA'], $this->gate()->marketsOf([1, 2]));
    }

    /**
     * Every till asks the gate, which is the half a test of the gate alone cannot prove.
     *
     * The three place-order actions are read from source rather than driven: the two panel tills need the admin shell and the
     * installation database to render at all, and this is the realistic regression -- a call taken out, not a gate that answers wrongly.
     * The check is per action and names the file, so a till added later is not quietly left out.
     */
    public function test_each_of_the_three_tills_asks_the_gate_before_it_writes_a_sale(): void
    {
        $tills = [
            'app/Http/Controllers/Admin/POS/POSOrderController.php',
            'app/Http/Controllers/Vendor/POS/POSOrderController.php',
            'app/Http/Controllers/RestAPI/v3/seller/POSController.php',
        ];

        foreach ($tills as $till) {
            $source = (string) file_get_contents(base_path($till));

            $this->assertStringContainsString('PosSaleGate', $source, "{$till} rings up a sale without asking which market it is in");
            $this->assertStringContainsString('refusalFor(', $source, "{$till} names the gate but never asks it");

            $asked = strpos($source, 'PosSaleGate::class)->refusalFor');
            $written = strpos($source, 'generateOrder') ?: strpos($source, 'writeOrderRetryingOnDuplicateId');

            $this->assertIsInt($asked, "{$till} does not ask the gate");
            $this->assertTrue($written === false || $asked < $written, "{$till} asks the gate after it has already written the sale");
        }
    }
}
