<?php

namespace Tests\Feature\Storefront;

use App\Models\Order;
use App\Services\Storefront\OrderStorefrontSnapshot;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * GCC-05: an order records what it was sold in, from the lines it was saved with. An order of a storefront with its own currency is stored in
 * that currency (rate one); an order of the default storefront is only known to be in a currency when the platform has a single one. A record
 * is written once, and an order that exists today stays empty.
 */
class OrderStorefrontSnapshotTest extends TestCase
{
    use BuildsStorefrontCatalog;

    private const ORDER = 9001;

    private const SNAPSHOT_COLUMNS = ['storefront_country', 'currency_code', 'currency_rate'];

    /** The column that says an amount is stored in the currency the order names. The other two share their names with columns of other tables. */
    private const RATE_COLUMN = 'currency_rate';

    /** Where a writer of a column of the orders table could live: the application, its modules, its migrations, its routes and its configuration. */
    private const APPLICATION_DIRECTORIES = ['app', 'Modules', 'database', 'routes', 'config'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['order_details', 'orders', 'products', 'currencies', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->buildTables();
        $this->runOrderSnapshotMigration();
        $this->seedCurrencies();
        $this->useCurrencyModel('multi_currency');

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.QA.active' => true,
            'gcc.storefronts.countries.BH.active' => true,
            'gcc.storefronts.countries.OM.active' => true,
        ]);
        StorefrontCatalog::flush();
    }

    protected function tearDown(): void
    {
        foreach (['order_details', 'orders', 'products', 'currencies', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    private function buildTables(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->decimal('unit_price', 16, 3)->default(0);
        });
        $this->runStorefrontCountryMigration();
        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->decimal('order_amount', 16, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
        });
    }

    private function runOrderSnapshotMigration(): void
    {
        $migration = require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php');
        $migration->up();
    }

    private function seedCurrencies(): void
    {
        foreach (['USD' => 1.0, 'SAR' => 3.75, 'AED' => 3.6725, 'KWD' => 0.307, 'QAR' => 3.64, 'BHD' => 0.376, 'OMR' => 0.385] as $code => $rate) {
            DB::table('currencies')->insert(['name' => $code, 'symbol' => $code, 'code' => $code, 'exchange_rate' => $rate, 'status' => 1]);
        }
    }

    private function useCurrencyModel(string $model): void
    {
        DB::table('business_settings')->whereIn('type', ['currency_model', 'system_default_currency'])->delete();
        DB::table('business_settings')->insert([
            ['type' => 'currency_model', 'value' => $model],
            ['type' => 'system_default_currency', 'value' => (string) DB::table('currencies')->where('code', 'SAR')->value('id')],
        ]);
        Cache::flush();
    }

    /** An order of the given product countries, one line for each. */
    private function orderOf(string ...$countries): int
    {
        DB::table('orders')->insert(['id' => self::ORDER, 'order_amount' => 10]);

        foreach ($countries as $index => $country) {
            $productId = 100 + $index;
            DB::table('products')->insert(['id' => $productId, 'name' => "Product {$country}", 'unit_price' => 10, 'storefront_country' => $country]);
            DB::table('order_details')->insert(['order_id' => self::ORDER, 'product_id' => $productId]);
        }

        return self::ORDER;
    }

    private function record(int $orderId): void
    {
        app(OrderStorefrontSnapshot::class)->record(orderId: $orderId);
    }

    /** @return array{storefront_country: ?string, currency_code: ?string, currency_rate: ?string} */
    private function snapshotOf(int $orderId): array
    {
        $row = DB::table('orders')->where('id', $orderId)->first(['storefront_country', 'currency_code', 'currency_rate']);

        return [
            'storefront_country' => $row->storefront_country,
            'currency_code' => $row->currency_code,
            'currency_rate' => $row->currency_rate === null ? null : rtrim(rtrim((string) $row->currency_rate, '0'), '.'),
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function storefrontsWithTheirOwnCurrency(): array
    {
        return [
            'United Arab Emirates' => ['AE', 'AED'],
            'Kuwait' => ['KW', 'KWD'],
            'Qatar' => ['QA', 'QAR'],
            'Bahrain' => ['BH', 'BHD'],
            'Oman' => ['OM', 'OMR'],
        ];
    }

    #[DataProvider('storefrontsWithTheirOwnCurrency')]
    public function test_an_order_of_a_storefront_with_its_own_currency_records_it_at_rate_one(string $country, string $code): void
    {
        $this->record($this->orderOf($country));

        $this->assertSame(['storefront_country' => $country, 'currency_code' => $code, 'currency_rate' => '1'], $this->snapshotOf(self::ORDER));
    }

    public function test_an_order_of_the_default_storefront_on_a_single_currency_platform_records_that_currency(): void
    {
        $this->useCurrencyModel('single_currency');

        $this->record($this->orderOf('SA'));

        $this->assertSame(['storefront_country' => 'SA', 'currency_code' => 'SAR', 'currency_rate' => '1'], $this->snapshotOf(self::ORDER));
    }

    public function test_an_order_of_the_default_storefront_on_a_multi_currency_platform_records_only_its_storefront(): void
    {
        $this->record($this->orderOf('SA'));

        $this->assertSame(['storefront_country' => 'SA', 'currency_code' => null, 'currency_rate' => null], $this->snapshotOf(self::ORDER));
    }

    public function test_nothing_is_recorded_while_storefront_currencies_are_off(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);

        $this->record($this->orderOf('KW'));

        $this->assertSame(['storefront_country' => null, 'currency_code' => null, 'currency_rate' => null], $this->snapshotOf(self::ORDER));
    }

    public function test_nothing_is_recorded_while_storefronts_are_off(): void
    {
        config(['gcc.storefronts.enabled' => false]);

        $this->record($this->orderOf('KW'));

        $this->assertSame(['storefront_country' => null, 'currency_code' => null, 'currency_rate' => null], $this->snapshotOf(self::ORDER));
    }

    public function test_an_order_whose_lines_belong_to_two_countries_records_nothing(): void
    {
        $this->record($this->orderOf('SA', 'KW'));

        $this->assertSame(['storefront_country' => null, 'currency_code' => null, 'currency_rate' => null], $this->snapshotOf(self::ORDER));
    }

    public function test_an_order_without_lines_records_nothing(): void
    {
        DB::table('orders')->insert(['id' => self::ORDER, 'order_amount' => 10]);

        $this->record(self::ORDER);

        $this->assertSame(['storefront_country' => null, 'currency_code' => null, 'currency_rate' => null], $this->snapshotOf(self::ORDER));
    }

    public function test_a_record_is_written_once_and_a_later_change_of_the_settings_never_rewrites_it(): void
    {
        $this->record($this->orderOf('KW'));
        config(['gcc.storefronts.countries.KW.currency' => 'AED']);
        DB::table('currencies')->where('code', 'KWD')->update(['status' => 0]);
        Cache::forget('active_currency_list');

        $this->record(self::ORDER);

        $this->assertSame(['storefront_country' => 'KW', 'currency_code' => 'KWD', 'currency_rate' => '1'], $this->snapshotOf(self::ORDER));
    }

    public function test_a_storefront_without_its_currency_records_its_country_and_no_currency(): void
    {
        DB::table('currencies')->where('code', 'KWD')->delete();
        Cache::forget('active_currency_list');

        $this->record($this->orderOf('KW'));

        $this->assertSame(['storefront_country' => 'KW', 'currency_code' => null, 'currency_rate' => null], $this->snapshotOf(self::ORDER));
    }

    public function test_a_request_served_from_another_storefront_records_the_country_of_the_lines_and_never_a_currency_it_cannot_vouch_for(): void
    {
        $orderId = $this->orderOf('AE');

        StorefrontResolver::fromConfig()->context(explicitChoice: 'KW')->serve(fn () => $this->record($orderId));

        $this->assertSame(['storefront_country' => 'AE', 'currency_code' => null, 'currency_rate' => null], $this->snapshotOf($orderId));
    }

    public function test_the_storefront_of_the_request_and_the_storefront_of_the_lines_agree_on_a_checkout(): void
    {
        $orderId = $this->orderOf('KW');

        StorefrontResolver::fromConfig()->context(explicitChoice: 'KW')->serve(fn () => $this->record($orderId));

        $this->assertSame(['storefront_country' => 'KW', 'currency_code' => 'KWD', 'currency_rate' => '1'], $this->snapshotOf($orderId));
    }

    public function test_an_order_saved_before_the_migration_ran_is_left_alone_and_nothing_fails(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['storefront_country', 'currency_code', 'currency_rate']);
        });
        $orderId = $this->orderOf('KW');

        $this->record($orderId);

        $this->assertFalse(Schema::hasColumn('orders', 'storefront_country'));
        $this->assertSame(10.0, (float) DB::table('orders')->where('id', $orderId)->value('order_amount'));
    }

    public function test_the_migration_is_idempotent_and_adds_nullable_columns_only(): void
    {
        $this->runOrderSnapshotMigration();

        $this->assertTrue(Schema::hasColumns('orders', ['storefront_country', 'currency_code', 'currency_rate']));
        DB::table('orders')->insert(['id' => 1, 'order_amount' => 5]);
        $this->assertNull(DB::table('orders')->where('id', 1)->value('storefront_country'));
    }

    public function test_the_migration_refuses_to_roll_back_while_an_order_carries_a_record_and_rolls_back_when_none_does(): void
    {
        $migration = require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php');
        $this->record($this->orderOf('KW'));

        try {
            $migration->down();
            $this->fail('The rollback must be refused while an order carries a record.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('Rolling this migration back would forget', $refusal->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('orders', 'storefront_country'));

        DB::table('orders')->update(['storefront_country' => null, 'currency_code' => null, 'currency_rate' => null]);
        $migration->down();

        $this->assertFalse(Schema::hasColumn('orders', 'storefront_country'));
        $this->assertFalse(Schema::hasColumn('orders', 'currency_code'));
        $this->assertFalse(Schema::hasColumn('orders', 'currency_rate'));
    }

    /**
     * What makes the rate of an order one or nothing, and never another number: this service is the only WRITER there is. Every PHP file of the
     * application that so much as names the rate is listed below with what it does with it, and only one of them writes it; none of the three
     * columns may be filled from a request. So a second writer, in a panel, a console command, an importer or a module, has to name the column
     * here or open the model up to it. Either way this test says so, and what a record means, an amount stored as it is in the currency the
     * order names, stops resting on a sentence.
     *
     * The list grew from two to eight as the refund (D1-10) and order-currency (D1-06) lanes landed, and every one of the six it grew by READS
     * the rate the sale recorded -- which is the whole point of recording it -- or declares a rate column of its own on another table. They are
     * named one by one rather than allowed as a pattern, so a new name still fails this test and still has to be accounted for.
 *
 * Deliberately NOT a read-versus-write scan of the text: this service writes the column as `self::RATE => ...`, through a constant, so a
 * one-file reader cannot tell its write from a mention, and a detector with that blind spot would pass a real second writer that used a
 * constant too. The accounted-for list carries the guarantee instead, and it carries it whole: nothing can name this column without
 * being written down here.
     */
    public function test_this_service_is_the_only_place_in_the_application_that_names_the_rate_column(): void
    {
        $naming = [];

        foreach ($this->applicationSources() as $path) {
            if (str_contains((string) file_get_contents(base_path($path)), self::RATE_COLUMN)) {
                $naming[] = $path;
            }
        }

        sort($naming);

        $this->assertSame([
            // Reads the order's rate to decide whether the row's stored amount can be shown in the order's own currency; writes nothing.
            'app/Models/RefundTransaction.php',
            // A SECOND WRITER, and the only one admitted on real orders: the backfill of orders placed before this column existed. It writes
            // the rate ONLY where the country and the currency are both still null, so it never overwrites a recorded rate and running it
            // twice stamps the same orders once; it REFUSES outright under `multi_currency` rather than stamping a currency an invoice could
            // later be reprinted from; `plan()` writes nothing and is what the command runs by default; and it touches no column of money,
            // proving that by comparing the sum of order_amount before and after. Those four properties are why a backfill does not make the
            // record stop meaning what it says -- it fills rows that said nothing, and says so where it cannot know.
            'app/Services/Checkout/OrderCurrencyBackfill.php',
            // READS it to decide whether a stored amount may be counted as money of the recorded currency: an amount is priced only while the
            // rate is one, and any other rate is counted UNPRICED rather than re-scaled by a conversion rule nobody has approved.
            'app/Services/PlatformAdmin/PlatformMarketFigures.php',
            // Names it in the PUBLISHED rule beside the figure (`priced_when: currency_rate = 1`), so a reader of the number knows what makes
            // it safe to read. A string in an answer, not a query.
            'app/Services/PlatformAdmin/PlatformOverviewService.php',
            // The one WRITER of orders.currency_rate on a NEW order, and the subject of this class.
            'app/Services/Storefront/OrderStorefrontSnapshot.php',
            // D1-10: copies the order's record onto a REFUND row when that row is made. Reads orders.currency_rate; writes the refund table's own column, not this one.
            'app/Services/Storefront/RefundFromOriginalPayment.php',
            'app/Services/Storefront/RefundOrderMoney.php',
            // D1-06: reads the rate recorded on the order to answer in the order's own money, and refuses any rate but one rather than re-scaling by a guess.
            'app/Services/Storefront/StorefrontCurrency.php',
            // The migration that adds this column.
            'database/migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php',
            // D1-06 and D1-10: declare a currency_rate of their own on the four money-row tables and the two refund tables. Neither names orders.
            'database/migrations/2026_11_27_100000_add_order_currency_to_order_money_rows.php',
            'database/migrations/2026_12_03_100000_add_order_money_to_refund_tables.php',
            // A WRITER of INVENTED rows only: the six-market staging seeder sets the rate to one on the orders it makes up, and refuses to run
            // outside staging, testing and local as the first statement of run(). No order of a real shop passes through it.
            'database/seeders/GccStagingSeeder.php',
        ], $naming, 'A file names the order rate that is not accounted for. If it WRITES orders.currency_rate the record stops meaning what it says; if it reads it, add it here with what it reads it for.');
        $this->assertSame([], array_intersect(self::SNAPSHOT_COLUMNS, (new Order())->getFillable()), 'none of the three may be filled from a request');
    }

    /** @return list<string> every PHP file of the application, as a path from the root of the repository */
    private function applicationSources(): array
    {
        $paths = [];

        foreach (self::APPLICATION_DIRECTORIES as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $paths[] = $directory . '/' . str_replace(base_path($directory) . '/', '', $file->getPathname());
                }
            }
        }

        return $paths;
    }
}
