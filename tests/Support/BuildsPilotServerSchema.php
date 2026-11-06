<?php

namespace Tests\Support;

use App\Services\Fulfillment\FulfillmentSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the owner found on the pilot server (MariaDB 11.4.2, Laravel 12.62.0) when the compatibility command was first run there, put
 * on top of the inferred core schema of BuildsFulfillmentSchema:
 *
 *  - the social media table is `social_medias`;
 *  - `currencies.exchange_rate` is varchar(191) (the platform's own migration ex_rate_update.php made it text), every rate a numeric string;
 *  - the store is a single-currency store whose default currency is id 8, code "KSA" (the riyal under the country's name), rate "1",
 *    while FULFILLMENT_CURRENCY is "SAR";
 *  - `order_details` has no rows, `delivery_status` is varchar(15) and `variation` is varchar(255); it carries the platform's own indexes on
 *    delivery_status and the stock flag the platform's status writer flips.
 *
 * SQLite does not enforce a declared length, so the length consequences are proved on MySQL (CI runs the fulfillment suites on MySQL 8).
 */
trait BuildsPilotServerSchema
{
    protected function usePilotServerSchema(): void
    {
        foreach (['login_setups', 'addon_settings', 'social_medias', 'sellers', 'brands', 'banners', 'flash_deals', 'business_pages', 'robots_meta_contents', 'analytic_scripts', 'stock_clearance_setups', 'stock_clearance_products'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, static fn (Blueprint $blueprint) => $blueprint->id());
        }

        Schema::drop('currencies');
        Schema::create('currencies', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('symbol');
            $table->string('code');
            $table->string('exchange_rate');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        DB::table('currencies')->insert([
            ['id' => 1, 'name' => 'US Dollar', 'symbol' => '$', 'code' => 'USD', 'exchange_rate' => '1', 'status' => 1],
            ['id' => 8, 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'code' => 'KSA', 'exchange_rate' => '1', 'status' => 1],
        ]);

        DB::table('business_settings')->truncate();
        DB::table('business_settings')->insert([
            ['type' => 'currency_model', 'value' => json_encode('single_currency')],
            ['type' => 'system_default_currency', 'value' => json_encode(8)],
            ['type' => 'decimal_point_settings', 'value' => json_encode(2)],
        ]);

        Schema::drop('order_details');
        Schema::create('order_details', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->longText('product_details')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 40, 20)->default(0);
            $table->decimal('discount', 40, 20)->default(0);
            $table->decimal('tax', 40, 20)->default(0);
            $table->string('tax_model')->nullable();
            $table->string('variant')->nullable();
            $table->string('variation', 255)->nullable();
            $table->string('delivery_status', 15)->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->tinyInteger('is_stock_decreased')->default(1);
            $table->timestamps();

            $table->index(['order_id', 'delivery_status'], 'idx_order_details_order_id_delivery_status');
            $table->index(['product_id', 'delivery_status'], 'idx_order_details_product_id_delivery_status');
            $table->index(['seller_id', 'delivery_status'], 'idx_order_details_seller_id_delivery_status');
            $table->index('delivery_status', 'idx_order_details_delivery_status');
        });

        config(['fulfillment.currency' => 'SAR']);
        FulfillmentSchema::flush();
        Cache::flush();
    }

    /**
     * Point the pilot store at another currency set-up, keeping every rate as the text the platform stores.
     *
     * @param array<int, array{0: string, 1: string}> $rows currency id => [code, text rate]
     */
    protected function useStoreCurrencies(string $model, int $defaultCurrencyId, array $rows): void
    {
        DB::table('currencies')->truncate();
        foreach ($rows as $id => [$code, $rate]) {
            DB::table('currencies')->insert(['id' => $id, 'name' => $code, 'symbol' => $code, 'code' => $code, 'exchange_rate' => $rate, 'status' => 1]);
        }
        DB::table('business_settings')->where('type', 'currency_model')->update(['value' => json_encode($model)]);
        DB::table('business_settings')->where('type', 'system_default_currency')->update(['value' => json_encode($defaultCurrencyId)]);
        Cache::flush();
    }

    /** The mode the platform's own connection runs in (config/database.php: 'strict' => false): a value that does not fit is cut short without an error. */
    protected const LENIENT_SQL_MODE = 'NO_ENGINE_SUBSTITUTION';

    protected const STRICT_SQL_MODE = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

    /** Runs the work with this session in the given SQL mode (MySQL and MariaDB), and puts the mode back afterwards. */
    protected function inSqlMode(string $mode, callable $work): void
    {
        $previous = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        DB::statement(sprintf('SET SESSION sql_mode = %s', DB::getPdo()->quote($mode)));

        try {
            $work();
        } finally {
            DB::statement(sprintf('SET SESSION sql_mode = %s', DB::getPdo()->quote($previous)));
        }
    }

    /** True on MySQL and MariaDB: the engines that enforce a column's declared length. */
    protected function engineEnforcesColumnLengths(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** The two platform migrations of this hotfix, run the way `php artisan migrate --path=…` runs them. */
    protected function runCompatibilityMigrations(): void
    {
        foreach ([
            '2026_11_06_100000_widen_order_details_delivery_status_for_fulfillment.php',
            '2026_11_06_100100_widen_order_details_variation_for_fulfillment.php',
        ] as $file) {
            (require database_path('migrations/' . $file))->up();
        }
    }
}
