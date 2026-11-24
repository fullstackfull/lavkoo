<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\LegacyModeSafety;
use App\Services\Storefront\StorefrontLifecycle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\ExpectationFailedException;
use Throwable;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * A1-02, as the owner settled it on 2026-11-24: the master storefront switch off means legacy mode, a single market is closed through its own
 * settings and its lifecycle, and once a second market is live the switch is **not** a kill switch.
 *
 * That leaves one state the platform must not be operated in -- the switch off while another market already holds rows, where nothing
 * narrows the catalogue and those rows are served as the default market's own. The code does not correct it, because correcting it would
 * contradict the Saudi compatibility the switch exists to protect. So what is tested here is the check that keeps an operator out of it: the
 * actions that would OPEN a market refuse, the actions that CLOSE one never do, and `gcc:storefront:safety` reports it and exits 1.
 */
class LegacyModeSafetyTest extends TestCase
{
    use BuildsStorefrontCatalog;

    /** What the connection was before a test pointed it somewhere it cannot open. */
    private mixed $databaseBefore = null;

    private mixed $portBefore = null;

    private const PERSON = 'The head of the Kuwait market';

    private const REASON = 'Decision of the operations meeting';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCatalogTables();
        $this->runStorefrontCountryMigration();
        $this->buildOrdersWithTheirMarket();
        $this->buildBusinessSettings();
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.countries.KW.active' => true]);
    }

    private function buildOrdersWithTheirMarket(): void
    {
        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->char('storefront_country', 2)->nullable();
            $table->timestamps();
        });
        $this->beforeApplicationDestroyed(static fn () => Schema::dropIfExists('orders'));
    }

    /** The lifecycle keeps its records here, and it reads a market as a draft while the table is absent. */
    private function buildBusinessSettings(): void
    {
        if (!Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('type')->nullable();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
            $this->beforeApplicationDestroyed(static fn () => Schema::dropIfExists('business_settings'));
        }

        DB::table('business_settings')->where('type', 'like', 'storefront_lifecycle_%')->delete();
        clearWebConfigCacheKeys();
    }

    private function orderSoldIn(?string $country): void
    {
        DB::table('orders')->insert(['storefront_country' => $country, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function safety(): LegacyModeSafety
    {
        return app(LegacyModeSafety::class);
    }

    private function safetyCommand(): int
    {
        return Artisan::call('gcc:storefront:safety');
    }

    public function test_an_installation_that_serves_one_market_is_safe(): void
    {
        $this->insertCatalogProduct(id: 1, name: 'Saudi serum');
        $this->orderSoldIn('SA');
        $this->orderSoldIn(null);

        $this->assertTrue($this->safety()->isLegacyMode());
        $this->assertSame([], $this->safety()->rowsOfOtherMarkets(), 'the default market and the rows that name none are not another market');
        $this->assertNull($this->safety()->refusalOfAnActionThatOpensAMarket());
        $this->assertSame(0, $this->safetyCommand());
    }

    public function test_the_switch_off_while_another_market_holds_products_is_the_state_to_stay_out_of(): void
    {
        $this->insertCatalogProduct(id: 1, name: 'Saudi serum');
        $this->insertCatalogProduct(id: 2, name: 'Kuwaiti serum', country: 'KW');

        $refusal = $this->safety()->refusalOfAnActionThatOpensAMarket();

        $this->assertSame(['KW' => ['products' => 1]], $this->safety()->rowsOfOtherMarkets());
        $this->assertNotNull($refusal);
        $this->assertStringContainsString('KW holds 1 products', $refusal);
        $this->assertStringContainsString('GCC_STOREFRONTS_ENABLED', $refusal, 'the refusal says the way out');
        $this->assertStringContainsString('gcc:storefront:state <CC> pause', $refusal);
        $this->assertSame(1, $this->safetyCommand(), 'a deploy can gate on it');
    }

    /** An order a market sold counts as much as a product it owns: a market that sold is a market that is live. */
    public function test_an_order_of_another_market_is_enough_on_its_own(): void
    {
        $this->orderSoldIn('KW');

        $this->assertSame(['KW' => ['orders' => 1]], $this->safety()->rowsOfOtherMarkets());
        $this->assertNotNull($this->safety()->refusalOfAnActionThatOpensAMarket());
    }

    public function test_what_each_market_holds_is_counted_per_table(): void
    {
        $this->insertCatalogProduct(id: 2, name: 'Kuwaiti serum', country: 'KW');
        $this->insertCatalogProduct(id: 3, name: 'Another Kuwaiti serum', country: 'KW');
        $this->insertCatalogProduct(id: 4, name: 'Emirati serum', country: 'AE');
        $this->orderSoldIn('KW');

        $this->assertSame(
            ['AE' => ['products' => 1], 'KW' => ['products' => 2, 'orders' => 1]],
            $this->safety()->rowsOfOtherMarkets(),
        );
    }

    /** With the switch on, every market is served as itself: holding rows is the normal operating state, not a warning. */
    public function test_the_switch_on_is_always_safe(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->insertCatalogProduct(id: 2, name: 'Kuwaiti serum', country: 'KW');

        $this->assertFalse($this->safety()->isLegacyMode());
        $this->assertNull($this->safety()->refusalOfAnActionThatOpensAMarket());
        $this->assertSame(0, $this->safetyCommand());
    }

    /** Before the migrations, and on a database that cannot answer, there is nothing to read and nothing is claimed. */
    /**
     * A table or a column that is not there is a FACT about this installation: no market holds a row of it, which is true, and the answer is
     * an all-clear.
     */
    public function test_an_installation_that_has_not_migrated_is_not_told_it_is_unsafe(): void
    {
        $this->insertCatalogProduct(id: 2, name: 'Kuwaiti serum', country: 'KW');
        $this->removeStorefrontCountryColumn();
        Schema::dropIfExists('orders');

        $this->assertSame([], $this->safety()->rowsOfOtherMarkets());
        $this->assertSame([], $this->safety()->tablesThatCouldNotBeRead(), 'a table that is not there is not a table that could not be read');
        $this->assertNull($this->safety()->refusalOfAnActionThatOpensAMarket());
        $this->assertSame(0, $this->safetyCommand());
    }

    /**
     * A database that cannot answer is NOT an all-clear, and it is not the same as a table that is not there.
     *
     * The platform cannot tell whether it is in the state it must not be operated in, so an action that would open a market is refused, the
     * command exits 1, and the refusal says which table and why -- a different instruction from the one for rows actually found.
     */
    public function test_a_table_that_cannot_be_read_is_not_an_all_clear(): void
    {
        $this->makeTheDatabaseUnreachable();

        $couldNotBeRead = $this->safety()->tablesThatCouldNotBeRead();

        $this->assertSame(['orders', 'products'], array_keys($couldNotBeRead), 'both tables are reported, and neither as empty');
        $this->assertNotSame('', $couldNotBeRead['products'], 'with what went wrong');
        $this->assertSame([], $this->safety()->rowsOfOtherMarkets(), 'nothing was found, because nothing could be read');

        $refusal = $this->safety()->refusalOfAnActionThatOpensAMarket();

        $this->assertNotNull($refusal, 'silence is not a yes');
        $this->assertStringContainsString('cannot be read right now', $refusal);
        $this->assertStringContainsString('products (', $refusal, 'and names the table it could not read');
        $this->assertStringNotContainsString('KW holds', $refusal, 'it does not claim to know what any market holds');
        $this->assertSame(1, $this->safetyCommand(), 'a deploy gating on it stops');

        $this->restoreTheDatabase();
    }

    /**
     * And nothing opens a market while the database is unreachable -- by a different road, which is worth knowing.
     *
     * The lifecycle reads its own record before anything else, so it fails loudly on the database rather than reaching the safety check's
     * refusal. Both ends are therefore covered: the check answers "unknown" to whoever asks it (above), and an action that would open a
     * market cannot go through at all. What must never happen is that it succeeds, and that is what this asserts.
     */
    public function test_nothing_opens_a_market_while_the_database_is_unreachable(): void
    {
        $this->makeTheDatabaseUnreachable();

        try {
            app(StorefrontLifecycle::class)->markReady(country: 'KW', by: self::PERSON, reason: self::REASON);
            $this->fail('A market was opened while the database could not be read.');
        } catch (Throwable $refused) {
            $this->assertNotInstanceOf(ExpectationFailedException::class, $refused, 'it was refused, rather than going through');
        } finally {
            $this->restoreTheDatabase();
        }
    }

    /**
     * With the switch on there is nothing to be unsure about: every market is served as itself whatever any table holds, so an unreadable
     * table is said out loud and is not a reason for a deploy to stop.
     */
    public function test_a_table_that_cannot_be_read_is_not_a_refusal_while_the_switch_is_on(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->makeTheDatabaseUnreachable();

        $this->assertNull($this->safety()->refusalOfAnActionThatOpensAMarket());
        $this->assertSame(0, $this->safetyCommand());
        $this->assertStringContainsString('could not be read', Artisan::output());

        $this->restoreTheDatabase();
    }

    /**
     * A database that cannot answer, on whichever engine the suite runs on: the connection is pointed somewhere it cannot open and purged,
     * which is the shape of a database that is down, a credential that was revoked or a host that is unreachable. Every table is then
     * unreadable, which is the case this is about -- the platform cannot tell whether it is in the state it must not be operated in.
     */
    private function makeTheDatabaseUnreachable(): void
    {
        $this->insertCatalogProduct(id: 2, name: 'Kuwaiti serum', country: 'KW');

        $connection = (string) config('database.default');
        $this->databaseBefore = config("database.connections.{$connection}.database");
        $this->portBefore = config("database.connections.{$connection}.port");

        config(config("database.connections.{$connection}.driver") === 'sqlite'
            ? ["database.connections.{$connection}.database" => '/a-directory-that-is-not-there/nothing.sqlite']
            : ["database.connections.{$connection}.port" => 1]);

        DB::purge($connection);
    }

    /** Puts the connection back, so what the test world built can still be taken down when the test ends. */
    private function restoreTheDatabase(): void
    {
        $connection = (string) config('database.default');

        config([
            "database.connections.{$connection}.database" => $this->databaseBefore,
            "database.connections.{$connection}.port" => $this->portBefore,
        ]);

        DB::purge($connection);
    }

    /**
     * The two actions that open a market refuse, each from the state it is reachable in: a draft is made ready, and a market already marked
     * ready is activated. The transition itself is still checked first, so a nonsensical move is still reported as one.
     */
    public function test_an_action_that_would_open_a_market_is_refused_in_that_state(): void
    {
        $this->insertCatalogProduct(id: 2, name: 'Kuwaiti serum', country: 'KW');

        $this->assertRefusedBySafety(fn () => app(StorefrontLifecycle::class)->markReady(country: 'KW', by: self::PERSON, reason: self::REASON));

        $this->recordFor('KW', StorefrontLifecycle::READY);

        $this->assertRefusedBySafety(fn () => app(StorefrontLifecycle::class)->activate(country: 'KW', by: self::PERSON, reference: 'The minutes', reason: self::REASON));
    }

    private function assertRefusedBySafety(callable $openingTheMarket): void
    {
        try {
            $openingTheMarket();
            $this->fail('An action that opens a market was not refused.');
        } catch (InvalidArgumentException $refused) {
            $this->assertStringContainsString('The storefronts switch is off while KW holds', $refused->getMessage());
        }
    }

    /** The lifecycle record of a state a test needs to start from, written as an approved change leaves it. */
    private function recordFor(string $country, string $state): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => StorefrontLifecycle::settingName($country)],
            ['value' => json_encode([
                'state' => $state,
                'mode' => null,
                'reason' => self::REASON,
                'message' => null,
                'until' => null,
                'changed_by' => self::PERSON,
                'reference' => 'The minutes',
                'changed_at' => now()->toIso8601String(),
            ]), 'created_at' => now(), 'updated_at' => now()],
        );

        clearWebConfigCacheKeys();
    }

    /**
     * And the way out is never blocked: pausing and resuming do not ask the question at all, so a market can always be closed and the market
     * that was closed can always be reopened. A pause of a draft is refused for being a draft, which is the answer it has always given.
     */
    public function test_the_actions_that_close_a_market_are_not_refused_by_the_check(): void
    {
        $this->insertCatalogProduct(id: 2, name: 'Kuwaiti serum', country: 'KW');

        foreach ([
            fn () => app(StorefrontLifecycle::class)->pause(country: 'KW', mode: StorefrontLifecycle::BROWSE_ONLY, by: self::PERSON, reason: self::REASON),
            fn () => app(StorefrontLifecycle::class)->resume(country: 'KW', by: self::PERSON, reason: self::REASON),
        ] as $closingOrReopening) {
            try {
                $closingOrReopening();
            } catch (InvalidArgumentException $refused) {
                $this->assertStringNotContainsString('The storefronts switch is off', $refused->getMessage());
                $this->assertStringContainsString('draft', $refused->getMessage(), 'refused for the state it is in, as it always was');
            }
        }
    }

    /** Reading where a storefront stands is not a change, so it answers in that state as in any other. */
    public function test_reading_the_state_of_a_storefront_is_never_refused(): void
    {
        $this->insertCatalogProduct(id: 2, name: 'Kuwaiti serum', country: 'KW');

        $this->assertSame(StorefrontLifecycle::DRAFT, app(StorefrontLifecycle::class)->statusOf('KW')['state']);
        $this->assertFalse(app(StorefrontLifecycle::class)->isOpenForSale('KW'));
    }
}
