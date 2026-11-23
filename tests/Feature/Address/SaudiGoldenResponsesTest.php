<?php

namespace Tests\Feature\Address;

use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1-17 and M2-01 of the master plan: the checkout of the Saudi store answers, and writes, exactly what it did before any GCC work. Each case places an order through the
 * endpoint the app calls, in the same world, and compares the JSON answer and the rows the order left (the order, its lines, the stock and the wallet) with a golden file
 * recorded from `main` with every GCC switch off. A branch that changes one byte of it, while its switches are off, fails here.
 *
 * To record again (only for a change the owner approved): RECORD_SAUDI_GOLDEN=1 vendor/bin/phpunit tests/Feature/Address/SaudiGoldenResponsesTest.php
 */
class SaudiGoldenResponsesTest extends ApiAddressTestCase
{
    private const COD = '/api/v1/customer/order/place';

    private const OFFLINE = '/api/v1/customer/order/place-by-offline-payment';

    private const WALLET = '/api/v1/customer/order/place-by-wallet';

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('DB_STRICT_TESTS') === '1' && !method_exists(OrderManager::class, 'countCouponUses')) {
            $this->markTestSkipped('The coupon count of the checkout is still a bare GROUP BY that a strict SQL server refuses, so the checkout of main answers 500 there (PR 89 fixes it).');
        }

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        $this->openTheWallet();
    }

    /** What the order list reads of a product, a review and a colour, which the order pipeline world leaves out. */
    private function giveTheTablesTheOrderListFormats(): void
    {
        if (!Schema::hasColumn('reviews', 'order_id')) {
            Schema::table('reviews', fn (Blueprint $table) => $table->unsignedBigInteger('order_id')->nullable());
        }
        if (!Schema::hasTable('colors')) {
            Schema::create('colors', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->string('code')->nullable();
            });
        }

        foreach (['colors', 'attributes', 'choice_options', 'category_ids', 'images', 'variation'] as $column) {
            if (!Schema::hasColumn('products', $column)) {
                Schema::table('products', fn (Blueprint $table) => $table->text($column)->nullable());
            }
        }
    }

    /** A store whose wallet is on: the setting the wallet writer asks for and the ledger it writes to. */
    private function openTheWallet(): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'wallet_status'], ['value' => '1']);
        clearWebConfigCacheKeys();

        if (!Schema::hasTable('wallet_transactions')) {
            Schema::create('wallet_transactions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('transaction_id');
                $table->decimal('credit', 24, 3)->default(0);
                $table->decimal('debit', 24, 3)->default(0);
                $table->decimal('admin_bonus', 24, 3)->default(0);
                $table->decimal('balance', 24, 3)->default(0);
                $table->string('transaction_type')->nullable();
                $table->string('reference')->nullable();
                $table->string('payment_method')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * The values that change from run to run (times, random references) are named, never compared, and a decimal or a count is the number it is whichever way the engine
     * hands it back (SQLite gives 100 and 1, MySQL "100.00" and "1").
     */
    private function normalized(mixed $value, string $key = ''): mixed
    {
        if (is_array($value)) {
            ksort($value);
            $normalized = [];

            foreach ($value as $name => $item) {
                $normalized[$name] = $this->normalized($item, (string) $name);
            }

            return $normalized;
        }

        if (in_array($key, ['transaction_id', 'order_group_id', 'verification_code'], true)) {
            return '<random>';
        }

        if (is_string($value) && str_ends_with($key, '_count') && ctype_digit($value)) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+\.\d+$/', $value)) {
            $value = (float) $value;
        }

        if (is_float($value)) {
            $rounded = round($value, 6);

            return $rounded === floor($rounded) ? (int) $rounded : $rounded;
        }

        if (is_string($value) && (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $value) || in_array($key, ['created_at', 'updated_at'], true))) {
            return '<time>';
        }

        return $value;
    }

    /** @param array<string, mixed> $actual */
    private function assertMatchesGolden(string $name, array $actual): void
    {
        $path = dirname(__DIR__, 2) . '/Fixtures/SaudiGolden/' . $name . '.json';
        $encoded = json_encode($this->normalized($actual), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (getenv('RECORD_SAUDI_GOLDEN') === '1') {
            file_put_contents($path, $encoded . "\n");
            $this->markTestSkipped("recorded {$name}");
        }

        $this->assertFileExists($path, 'no golden file: record it from main');
        $this->assertSame(rtrim((string) file_get_contents($path)), $encoded, "the Saudi answer of {$name} changed");
    }

    /** @return array<string, mixed> what the order left behind */
    private function rowsLeftBy(int $customerId): array
    {
        return [
            'orders' => DB::table('orders')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'order_details' => DB::table('order_details')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'stock_of_product_1' => (int) DB::table('products')->where('id', 1)->value('current_stock'),
            'wallet_balance' => (float) DB::table('users')->where('id', $customerId)->value('wallet_balance'),
            'carts_left' => DB::table('carts')->where('customer_id', $customerId)->count(),
            'wallet_ledger' => Schema::hasTable('wallet_transactions') ? DB::table('wallet_transactions')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all() : [],
        ];
    }

    public function test_the_cash_on_delivery_order_of_one_hundred_riyals(): void
    {
        $customer = $this->makeCustomer(['email' => 'golden@example.test']);
        $this->putOneProductInTheCart($customer->id);

        $response = $this->asCustomer($customer)->getJson(self::COD)->assertOk();

        $this->assertMatchesGolden('cash_on_delivery', ['status' => $response->status(), 'body' => $response->json(), 'rows' => $this->rowsLeftBy($customer->id)]);
    }

    public function test_the_order_list_the_app_reads_after_a_cash_on_delivery_order(): void
    {
        $this->giveTheTablesTheOrderListFormats();
        $customer = $this->makeCustomer(['email' => 'golden@example.test']);
        $this->putOneProductInTheCart($customer->id);
        DB::table('products')->update(['colors' => '[]', 'attributes' => '[]', 'choice_options' => '[]', 'category_ids' => '[]', 'images' => '[]', 'variation' => '[]']);
        $this->asCustomer($customer)->getJson(self::COD)->assertOk();

        $response = $this->asCustomer($customer)->getJson('/api/v1/customer/order/list?limit=10&offset=1');

        $this->assertMatchesGolden('order_list', ['status' => $response->status(), 'body' => $response->json()]);
    }

    public function test_the_wallet_order_of_one_hundred_riyals(): void
    {
        $customer = $this->makeCustomer(['email' => 'golden@example.test', 'wallet_balance' => 500]);
        $this->putOneProductInTheCart($customer->id);

        $response = $this->asCustomer($customer)->getJson(self::WALLET);

        $this->assertMatchesGolden('wallet', ['status' => $response->status(), 'body' => $response->json(), 'rows' => $this->rowsLeftBy($customer->id)]);
    }

    public function test_the_wallet_order_that_the_balance_cannot_pay(): void
    {
        $customer = $this->makeCustomer(['email' => 'golden@example.test', 'wallet_balance' => 30]);
        $this->putOneProductInTheCart($customer->id);

        $response = $this->asCustomer($customer)->getJson(self::WALLET);

        $this->assertMatchesGolden('wallet_insufficient', ['status' => $response->status(), 'body' => $response->json(), 'rows' => $this->rowsLeftBy($customer->id)]);
    }

    public function test_the_offline_payment_order_of_one_hundred_riyals(): void
    {
        $customer = $this->makeCustomer(['email' => 'golden@example.test']);
        $this->putOneProductInTheCart($customer->id);

        $response = $this->asCustomer($customer)->postJson(self::OFFLINE, ['payment_note' => 'transfer', 'method_informations' => []]);

        $this->assertMatchesGolden('offline_payment', ['status' => $response->status(), 'body' => $response->json(), 'rows' => $this->rowsLeftBy($customer->id)]);
    }
}
