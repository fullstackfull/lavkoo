<?php

namespace Tests\Feature\Wallet;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Pins how the seller app's balance-withdraw endpoints (v3 and the legacy v2) decide whether a request fits the wallet, and what they write.
 *
 * The store sells in SAR at 3.75 per USD and keeps the wallet in USD, so 375 SAR asked for is 100 USD of earnings. The guard converts the
 * amount with Convert::usd (default currency read through the cached settings) and the debit with BackEndHelper::currency_to_usd (default
 * currency read straight from the settings row); the tests named "currently" pin what happens when those two reads disagree.
 */
class WithdrawBoundaryCharacterizationTest extends TestCase
{
    use RestoresLanguageFiles;

    private const SELLER_ID = 7;
    private const METHOD_ID = 3;
    private const USD_ID = 1;
    private const SAR_ID = 2;
    private const TOKEN = 'withdraw-boundary-test-token-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildWalletSchema();
        $this->seedStore();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function buildWalletSchema(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol', 20)->nullable();
            $table->string('code', 20)->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('status')->default('approved');
            $table->string('auth_token')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_no')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('seller_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->decimal('total_earning', 40, 20)->default(0);
            $table->decimal('withdrawn', 40, 20)->default(0);
            $table->decimal('commission_given', 40, 20)->default(0);
            $table->decimal('pending_withdraw', 40, 20)->default(0);
            $table->decimal('delivery_charge_earned', 40, 20)->default(0);
            $table->decimal('collected_cash', 40, 20)->default(0);
            $table->decimal('total_tax_collected', 40, 20)->default(0);
            $table->timestamps();
        });

        Schema::create('withdrawal_methods', function (Blueprint $table) {
            $table->id();
            $table->string('method_name');
            $table->longText('method_fields')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('vendor_status')->default(true);
            $table->boolean('customer_status')->default(true);
            $table->timestamps();
        });

        Schema::create('withdraw_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->decimal('amount', 40, 20)->default(0);
            $table->unsignedBigInteger('withdrawal_method_id')->nullable();
            $table->longText('withdrawal_method_fields')->nullable();
            $table->longText('transaction_note')->nullable();
            $table->boolean('approved')->default(false);
            $table->timestamps();
        });
    }

    private function seedStore(): void
    {
        DB::table('currencies')->insert([
            ['id' => self::USD_ID, 'name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'exchange_rate' => 1],
            ['id' => self::SAR_ID, 'name' => 'Saudi Riyal', 'code' => 'SAR', 'symbol' => 'SAR', 'exchange_rate' => 3.75],
        ]);
        DB::table('business_settings')->insert([
            ['type' => 'currency_model', 'value' => json_encode('multi_currency')],
            ['type' => 'system_default_currency', 'value' => json_encode(self::SAR_ID)],
        ]);
        DB::table('sellers')->insert([
            'id' => self::SELLER_ID, 'f_name' => 'Huda', 'status' => 'approved', 'auth_token' => self::TOKEN,
            'bank_name' => 'Test Bank', 'account_no' => 'SA0000000000000000000000',
        ]);
        DB::table('seller_wallets')->insert(['seller_id' => self::SELLER_ID, 'total_earning' => 100, 'pending_withdraw' => 20]);
        DB::table('withdrawal_methods')->insert([
            'id' => self::METHOD_ID, 'method_name' => 'Bank transfer',
            'method_fields' => json_encode([['input_type' => 'string', 'input_name' => 'iban', 'placeholder' => 'IBAN', 'is_required' => 1]]),
        ]);
        Cache::flush();
    }

    private function withdrawV3(float|int $amount): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . self::TOKEN, 'lang' => 'en'])
            ->postJson('/api/v3/seller/balance-withdraw', ['withdraw_method_id' => self::METHOD_ID, 'amount' => $amount, 'iban' => 'SA0380000000608010167519']);
    }

    /** @return array{0: float, 1: float} total_earning and pending_withdraw as stored */
    private function wallet(): array
    {
        $wallet = DB::table('seller_wallets')->where('seller_id', self::SELLER_ID)->sole();

        return [(float) $wallet->total_earning, (float) $wallet->pending_withdraw];
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame([100.0, 20.0], $this->wallet());
        $this->assertSame(0, DB::table('withdraw_requests')->count());
    }

    public function test_v3_accepts_a_request_that_converts_to_exactly_the_total_earning_and_moves_it_to_pending(): void
    {
        $response = $this->withdrawV3(375);

        $response->assertStatus(200);
        $this->assertSame('Withdraw request sent successfully!', $response->json());
        $this->assertSame([0.0, 120.0], $this->wallet());
        $row = DB::table('withdraw_requests')->sole();
        $this->assertSame([self::SELLER_ID, 100.0, self::METHOD_ID, 0, null], [(int) $row->seller_id, (float) $row->amount, (int) $row->withdrawal_method_id, (int) $row->approved, $row->transaction_note]);
        $this->assertSame(['method_name' => 'Bank transfer', 'iban' => 'SA0380000000608010167519'], json_decode($row->withdrawal_method_fields, true));
    }

    public function test_v3_moves_a_partial_amount_to_pending_withdraw_and_records_the_same_amount_on_the_request(): void
    {
        $this->withdrawV3(150)->assertStatus(200);

        $this->assertSame([60.0, 60.0], $this->wallet());
        $this->assertSame(40.0, (float) DB::table('withdraw_requests')->value('amount'));
    }

    public function test_v3_refuses_a_request_above_the_total_earning_and_writes_nothing(): void
    {
        $response = $this->withdrawV3(375.01);

        $response->assertStatus(403);
        $this->assertSame(['message' => 'Invalid withdraw request'], $response->json());
        $this->assertNothingWritten();
    }

    public function test_v3_refuses_a_zero_or_negative_amount_and_writes_nothing(): void
    {
        foreach ([0, -375] as $amount) {
            $response = $this->withdrawV3($amount);

            $response->assertStatus(403);
            $this->assertSame(['message' => 'Invalid withdraw request'], $response->json(), (string) $amount);
        }
        $this->assertNothingWritten();
    }

    public function test_v3_currently_debits_through_a_different_currency_lookup_than_the_guard_and_drives_total_earning_negative(): void
    {
        $this->assertEquals(self::SAR_ID, getWebConfig(name: 'system_default_currency'));
        DB::table('business_settings')->where('type', 'system_default_currency')->update(['value' => json_encode(self::USD_ID)]);

        $response = $this->withdrawV3(375);

        $response->assertStatus(200);
        $this->assertSame([-275.0, 395.0], $this->wallet());
        $this->assertSame(100.0, (float) DB::table('withdraw_requests')->value('amount'), 'the request row records the guard conversion, not the debit');
    }
}
