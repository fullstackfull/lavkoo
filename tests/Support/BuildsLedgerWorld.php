<?php

namespace Tests\Support;

use App\Services\Storefront\LedgerAttribution;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The tables the wallet and loyalty writers read and write, with the columns the writers use (built from the migrations that define them), an order table that carries the
 * record of GCC-05 and GCC-13, the refund and payment tables a movement can come from, and the ledger migration of E-05 run or not. Everything starts switched off, as in
 * production: no storefront, no storefront currency, no legal entity.
 *
 * On a real engine the tables outlive a test, so each test drops what it built when it is done. Changing them is refused on a database that is not a test database.
 */
trait BuildsLedgerWorld
{
    use BuildsLedgerTables;
    use BuildsLegalEntityWorld;

    private const LEDGER_NOW = '2026-12-05 10:00:00';

    /** @var list<string> */
    private array $ledgerWorldTables = ['loyalty_point_transactions', 'wallet_transactions', 'add_fund_bonus_categories', 'refund_requests', 'payment_requests', 'legal_entities', 'orders', 'currencies', 'business_settings', 'users'];

    protected function buildLedgerWorld(bool $attributed = true): void
    {
        $this->guardLedgerDatabase();
        $this->dropLedgerWorld();
        $this->createLedgerTables();

        (require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php'))->up();
        $this->runLegalEntityMigrations();

        if ($attributed) {
            $this->ledgerMigration()->up();
        }

        $this->seedLedgerSettings();
        Carbon::setTestNow(self::LEDGER_NOW);
        StorefrontCatalog::flush();
        app(LedgerAttribution::class)->flush();
    }

    protected function dropLedgerWorld(): void
    {
        foreach ($this->ledgerWorldTables as $table) {
            Schema::dropIfExists($table);
        }
        Carbon::setTestNow();
        StorefrontCatalog::flush();
    }

    private function guardLedgerDatabase(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite' && !str_contains(strtolower((string) $connection->getDatabaseName()), 'test')) {
            throw new RuntimeException('Refusing to rebuild the ledger tables of a database that is not a test database.');
        }
    }

    private function createLedgerTables(): void
    {
        $this->createWalletAndLoyaltyLedgers();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('email')->nullable();
            $table->decimal('wallet_balance', 18, 12)->default(0);
            $table->decimal('loyalty_point', 18, 4)->nullable()->default(0);
            $table->timestamps();
        });
        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('add_fund_bonus_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('bonus_type', 50);
            $table->float('bonus_amount', 14, 2)->default(0);
            $table->float('min_add_money_amount', 14, 2)->default(0);
            $table->float('max_bonus_amount', 14, 2)->default(0);
            $table->dateTime('start_date_time')->nullable();
            $table->dateTime('end_date_time')->nullable();
            $table->boolean('is_active')->default(0);
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('order_group_id')->default('def-order-group');
            $table->decimal('order_amount', 16, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('refund_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->decimal('amount', 16, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('payment_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('payer_id')->nullable();
            $table->decimal('payment_amount', 24, 2)->default(0);
            $table->string('currency_code', 10)->default('SAR');
            $table->string('payment_method')->nullable();
            $table->longText('additional_data')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->timestamps();
        });
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol', 20)->nullable();
            $table->string('code', 20)->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    private function seedLedgerSettings(): void
    {
        foreach (['wallet_status' => '1', 'loyalty_point_status' => '1', 'loyalty_point_exchange_rate' => '100', 'loyalty_point_item_purchase_point' => '10'] as $type => $value) {
            DB::table('business_settings')->insert(['type' => $type, 'value' => $value]);
        }
        DB::table('business_settings')->insert([
            ['type' => 'currency_model', 'value' => json_encode('single_currency')],
            ['type' => 'system_default_currency', 'value' => json_encode(1)],
        ]);

        foreach ([1 => ['SAR', 'Saudi Riyal'], 2 => ['AED', 'UAE Dirham'], 3 => ['KWD', 'Kuwaiti Dinar'], 4 => ['QAR', 'Qatari Riyal'], 5 => ['BHD', 'Bahraini Dinar'], 6 => ['OMR', 'Omani Rial']] as $id => [$code, $name]) {
            DB::table('currencies')->insert(['id' => $id, 'name' => $name, 'code' => $code, 'symbol' => $code, 'exchange_rate' => 1, 'status' => 1]);
        }

        Cache::flush();
    }

    protected function ledgerCustomer(int $id = 1, float $wallet = 150.0, float $points = 1000.0): int
    {
        DB::table('users')->insert(['id' => $id, 'f_name' => 'Customer', 'email' => "customer{$id}@example.test", 'wallet_balance' => $wallet, 'loyalty_point' => $points, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** An order of the given market, as the sale of GCC-05 and GCC-13 records it; a null leaves that part of the record empty. */
    protected function ledgerOrder(int $id, ?string $country = null, ?string $currency = null, ?int $entityId = null, string $group = 'def-order-group'): int
    {
        DB::table('orders')->insert(['id' => $id, 'order_group_id' => $group, 'storefront_country' => $country, 'currency_code' => $currency, 'currency_rate' => $currency === null ? null : 1, 'legal_entity_id' => $entityId, 'order_amount' => 10]);

        return $id;
    }

    protected function ledgerRefund(int $id, int $orderId): int
    {
        DB::table('refund_requests')->insert(['id' => $id, 'order_id' => $orderId, 'amount' => 5]);

        return $id;
    }

    /** @param array<string, mixed> $additionalData */
    protected function ledgerPayment(string $id, array $additionalData = [], string $currency = 'SAR'): string
    {
        DB::table('payment_requests')->insert(['id' => $id, 'payer_id' => '1', 'currency_code' => $currency, 'additional_data' => json_encode($additionalData), 'is_paid' => true]);

        return $id;
    }

    /** Storefronts on (only the given countries, besides Saudi Arabia, may serve), the storefront currency and the legal entities as asked. */
    protected function switchMarketsOn(bool $currency = false, bool $entities = false): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => $currency,
            'gcc.storefronts.legal_entities.enabled' => $entities,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.QA.active' => true,
            'gcc.storefronts.countries.BH.active' => true,
            'gcc.storefronts.countries.OM.active' => true,
        ]);
        StorefrontCatalog::flush();
        app(LedgerAttribution::class)->flush();
    }

    /** Runs the callback as a request of one storefront, the way the storefront middleware binds it. */
    protected function servedFrom(string $country, callable $callback): mixed
    {
        return StorefrontResolver::fromConfig()->contextOfRecordedCountry($country)->serve(callback: $callback);
    }
}
