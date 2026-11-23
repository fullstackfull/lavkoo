<?php

namespace Tests\Feature\Wallet;

use App\Models\Seller;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Pins how the balance-withdraw paths (v3, the legacy v2 and the vendor panel) decide whether a request fits the wallet, and what they write.
 *
 * The store sells in SAR at 3.75 per USD and keeps the wallet in USD, so 375 SAR asked for is 100 USD of earnings.
 *
 * The guard, the amount stored on the request row and the wallet debit used to be converted separately: the guard through Convert::usd
 * (default currency as the settings cache has it) and the debit through BackEndHelper::currency_to_usd (default currency read straight from
 * the settings row), with the vendor panel on a third helper again. While the cached and stored defaults disagreed, a request passed the
 * guard at one rate and was debited at another, taking total_earning below zero. All three now take the one amount
 * WithdrawRequestService::getWithdrawAmountInWalletCurrency returns, and refuse when it cannot be converted.
 */
class WithdrawBoundaryCharacterizationTest extends TestCase
{
    use RestoresLanguageFiles;

    private const SELLER_ID = 7;
    private const METHOD_ID = 3;
    private const USD_ID = 1;
    private const SAR_ID = 2;
    private const TOKEN = 'withdraw-boundary-test-token-0123456789abcdef';
    private const STAFF_TOKEN = 'withdraw-boundary-staff-token-0123456789abcdef';
    /**
     * A rate of zero cannot divide and a negative one would turn a withdrawal into a credit, so neither may
     * reach the conversion. Refusing the negative rate is also what keeps the debit's own sign out of reach:
     * with the amount required to be above zero and the rate required to be above zero, the one converted
     * amount can never be negative.
     */
    private const UNUSABLE_RATES = [0, -3.75];
    /** 100 SAR is 26.666666666667 USD at 3.75, so the row and the debit cannot agree by rounding to the same 2 decimal places. */
    private const AMOUNT_THAT_DOES_NOT_DIVIDE = 100;
    private const TABLES = ['business_settings', 'currencies', 'sellers', 'seller_wallets', 'withdrawal_methods', 'withdraw_requests', 'seller_roles', 'seller_staff'];

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
        // Dropped before they are built, the way the repository's other hand-built schemas do it. On
        // in-memory SQLite each test gets a new database and the tables cannot already be there; on a
        // real engine they survive the previous test, and creating them again fails.
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
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
            $table->string('request_method_type')->nullable();
            $table->timestamps();
        });

        Schema::create('seller_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('name');
            $table->longText('permissions')->nullable();
            $table->string('status')->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('seller_staff', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('seller_role_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('last_login_at')->nullable();
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

    private function withdrawV3(mixed $amount): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . self::TOKEN, 'lang' => 'en'])
            ->postJson('/api/v3/seller/balance-withdraw', ['withdraw_method_id' => self::METHOD_ID, 'amount' => $amount, 'iban' => 'SA0380000000608010167519']);
    }

    private function withdrawV2(mixed $amount): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . self::TOKEN, 'lang' => 'en'])
            ->postJson('/api/v2/seller/balance-withdraw', ['amount' => $amount]);
    }

    /**
     * The state the admin's General Settings save leaves behind: business_settings holds the new default
     * currency, and the business-settings cache still holds the old one, because that writer forgets only
     * session keys (BusinessSettingsController::updateSystemCurrency).
     */
    private function changeTheDefaultCurrencyBehindTheSettingsCache(): void
    {
        $this->assertEquals(self::SAR_ID, getWebConfig(name: 'system_default_currency'), 'the cache must be warm on SAR first');
        DB::table('business_settings')->where('type', 'system_default_currency')->update(['value' => json_encode(self::USD_ID)]);
        $this->assertEquals(self::SAR_ID, getWebConfig(name: 'system_default_currency'), 'the cached default must still be stale');
    }

    private function removeTheUsdCurrencyRow(): void
    {
        DB::table('currencies')->where('id', self::USD_ID)->delete();
    }

    private function setTheDefaultCurrencyRateTo(float $exchangeRate): void
    {
        DB::table('currencies')->where('id', self::SAR_ID)->update(['exchange_rate' => $exchangeRate]);
        Cache::flush();
    }

    private function closeWithdrawV3(int $withdrawRequestId): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . self::TOKEN, 'lang' => 'en'])
            ->deleteJson('/api/v3/seller/close-withdraw-request', ['id' => $withdrawRequestId]);
    }

    /**
     * The one number the criterion is about: the amount on the request row and the amount the wallet moved by.
     *
     * Asserted against the wallet the request started from, with no rounding anywhere, so an amount that does
     * not divide evenly separates them. Rounding the stored row to the 2 decimal places money is quoted in,
     * while the wallet keeps the unrounded figure, leaves the seller's own close path crediting back more than
     * was taken -- and every assertion in this file that uses a figure converting to an exact 2 decimal places
     * stays green while it happens.
     */
    private function assertTheStoredAmountIsExactlyWhatTheWalletMoved(float $earningBeforeTheRequest, float $pendingBeforeTheRequest): void
    {
        [$totalEarning, $pendingWithdraw] = $this->wallet();
        $storedAmount = (float) DB::table('withdraw_requests')->sole()->amount;

        $this->assertSame($earningBeforeTheRequest - $storedAmount, $totalEarning, 'total_earning fell by exactly the amount the request records');
        $this->assertSame($pendingBeforeTheRequest + $storedAmount, $pendingWithdraw, 'pending_withdraw rose by exactly the amount the request records');
    }

    private function createStaffWithPayoutPermission(): string
    {
        DB::table('seller_roles')->insert([
            'id' => 1, 'seller_id' => self::SELLER_ID, 'name' => 'Finance',
            'permissions' => json_encode(['payouts.request']), 'status' => 'active',
        ]);
        DB::table('seller_staff')->insert([
            'id' => 1, 'seller_id' => self::SELLER_ID, 'seller_role_id' => 1, 'name' => 'Nawaf',
            'email' => 'nawaf@example.test', 'password' => bcrypt('irrelevant'),
            'auth_token' => self::STAFF_TOKEN, 'status' => 'active',
        ]);

        return self::STAFF_TOKEN;
    }

    private function withdrawV3AsStaff(float|int $amount): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->createStaffWithPayoutPermission(), 'lang' => 'en'])
            ->postJson('/api/v3/seller/balance-withdraw', ['withdraw_method_id' => self::METHOD_ID, 'amount' => $amount, 'iban' => 'SA0380000000608010167519']);
    }

    private function updateWithdrawV3(int $withdrawRequestId, float|int $amount): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . self::TOKEN, 'lang' => 'en'])
            ->postJson('/api/v3/seller/balance-withdraw-update', [
                'withdraw_request_id' => $withdrawRequestId,
                'withdraw_method_id' => self::METHOD_ID,
                'amount' => $amount,
                'iban' => 'SA0380000000608010167519',
            ]);
    }

    private function updateWithdrawOnTheVendorPanel(int $withdrawRequestId, float|int $amount): TestResponse
    {
        return $this->actingAs(Seller::find(self::SELLER_ID), 'seller')
            ->post(route('vendor.dashboard.withdraw-request-update'), [
                'withdraw_request_id' => $withdrawRequestId,
                'withdraw_method' => self::METHOD_ID,
                'amount' => $amount,
                'method_info' => ['iban' => 'SA0380000000608010167519'],
            ]);
    }

    private function withdrawOnTheVendorPanel(mixed $amount): TestResponse
    {
        return $this->actingAs(Seller::find(self::SELLER_ID), 'seller')
            ->post(route('vendor.dashboard.withdraw-request'), [
                'withdraw_method' => self::METHOD_ID,
                'amount' => $amount,
                'method_info' => ['iban' => 'SA0380000000608010167519'],
            ]);
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

    public function test_v3_debits_exactly_what_the_guard_approved_when_the_cached_and_stored_default_currency_disagree(): void
    {
        $this->changeTheDefaultCurrencyBehindTheSettingsCache();

        $response = $this->withdrawV3(375);

        $response->assertStatus(200);
        $this->assertSame([0.0, 120.0], $this->wallet());
        [$totalEarning] = $this->wallet();
        $this->assertGreaterThanOrEqual(0, $totalEarning, 'the debit must never take total_earning below zero');
        $this->assertSame(100.0, (float) DB::table('withdraw_requests')->value('amount'), 'the request row records the amount that was debited');
    }

    public function test_v3_refuses_a_withdrawal_when_the_store_has_no_usd_row_to_convert_through(): void
    {
        $this->removeTheUsdCurrencyRow();

        $response = $this->withdrawV3(375);

        $response->assertStatus(403);
        $this->assertSame(['message' => 'Invalid withdraw request'], $response->json());
        $this->assertNothingWritten();
    }

    /**
     * A negative USD rate turns a withdrawal into a credit: 375 of a 3.75 default currency converts to -100,
     * which any balance clears, and the debit then raises total_earning instead of lowering it. Refusing it is
     * also what puts the debit's own sign out of reach -- with the requested amount above zero and both rates
     * above zero, the one converted amount can never be negative.
     */
    public function test_v3_refuses_a_withdrawal_when_the_usd_rate_is_negative(): void
    {
        DB::table('currencies')->where('id', self::USD_ID)->update(['exchange_rate' => -1]);
        Cache::flush();

        $this->withdrawV3(375)->assertStatus(403);

        $this->assertNothingWritten();
    }

    public function test_v3_staff_with_the_payout_permission_debits_the_employer_wallet_by_the_amount_the_guard_approved(): void
    {
        $this->changeTheDefaultCurrencyBehindTheSettingsCache();

        $response = $this->withdrawV3AsStaff(375);

        $response->assertStatus(200);
        $this->assertSame([0.0, 120.0], $this->wallet());
        $row = DB::table('withdraw_requests')->sole();
        $this->assertSame([self::SELLER_ID, 100.0], [(int) $row->seller_id, (float) $row->amount], 'the request belongs to the employer shop, at the debited amount');
    }

    public function test_v3_staff_is_refused_when_the_store_has_no_usd_row_to_convert_through(): void
    {
        $this->removeTheUsdCurrencyRow();

        $this->withdrawV3AsStaff(375)->assertStatus(403);

        $this->assertNothingWritten();
    }

    public function test_v2_accepts_a_request_that_converts_to_exactly_the_total_earning_and_moves_it_to_pending(): void
    {
        $response = $this->withdrawV2(375);

        $response->assertStatus(200);
        $this->assertSame('Withdraw request sent successfully!', $response->json());
        $this->assertSame([0.0, 120.0], $this->wallet());
        $row = DB::table('withdraw_requests')->sole();
        $this->assertSame([self::SELLER_ID, 100.0, null, null, 0], [(int) $row->seller_id, (float) $row->amount, $row->withdrawal_method_id, $row->withdrawal_method_fields, (int) $row->approved]);
    }

    public function test_v2_refuses_a_request_above_the_total_earning_and_writes_nothing(): void
    {
        $response = $this->withdrawV2(375.01);

        $response->assertStatus(400);
        $this->assertSame(['message' => 'Invalid withdraw request'], $response->json());
        $this->assertNothingWritten();
    }

    public function test_v2_refuses_a_zero_or_negative_amount_and_writes_nothing(): void
    {
        foreach ([0, -375] as $amount) {
            $response = $this->withdrawV2($amount);

            $response->assertStatus(400);
            $this->assertSame(['message' => 'Invalid withdraw request'], $response->json(), (string) $amount);
        }
        $this->assertNothingWritten();
    }

    public function test_v2_debits_exactly_what_the_guard_approved_when_the_cached_and_stored_default_currency_disagree(): void
    {
        $this->changeTheDefaultCurrencyBehindTheSettingsCache();

        $response = $this->withdrawV2(375);

        $response->assertStatus(200);
        $this->assertSame([0.0, 120.0], $this->wallet());
        [$totalEarning] = $this->wallet();
        $this->assertGreaterThanOrEqual(0, $totalEarning, 'the debit must never take total_earning below zero');
        $this->assertSame(100.0, (float) DB::table('withdraw_requests')->value('amount'), 'the request row records the amount that was debited');
    }

    public function test_v2_refuses_a_withdrawal_when_the_store_has_no_usd_row_to_convert_through(): void
    {
        $this->removeTheUsdCurrencyRow();

        $response = $this->withdrawV2(375);

        $response->assertStatus(400);
        $this->assertSame(['message' => 'Invalid withdraw request'], $response->json());
        $this->assertNothingWritten();
    }

    public function test_the_vendor_panel_stores_and_debits_the_one_converted_amount(): void
    {
        $this->withdrawOnTheVendorPanel(375)->assertRedirect();

        $this->assertSame([0.0, 120.0], $this->wallet());
        $this->assertSame(100.0, (float) DB::table('withdraw_requests')->value('amount'));
    }

    public function test_the_vendor_panel_refuses_a_withdrawal_when_the_store_has_no_usd_row_to_convert_through(): void
    {
        $this->removeTheUsdCurrencyRow();

        $this->withdrawOnTheVendorPanel(375)->assertRedirect();

        $this->assertNothingWritten();
    }

    public function test_v3_update_debits_exactly_what_the_guard_approved_when_the_cached_and_stored_default_currency_disagree(): void
    {
        $this->withdrawV3(150)->assertStatus(200);
        $this->assertSame([60.0, 60.0], $this->wallet());
        $withdrawRequestId = (int) DB::table('withdraw_requests')->value('id');
        $this->changeTheDefaultCurrencyBehindTheSettingsCache();

        $this->updateWithdrawV3($withdrawRequestId, 375)->assertStatus(200);

        $this->assertSame([0.0, 120.0], $this->wallet());
        [$totalEarning] = $this->wallet();
        $this->assertGreaterThanOrEqual(0, $totalEarning, 'the debit must never take total_earning below zero');
        $this->assertSame(100.0, (float) DB::table('withdraw_requests')->value('amount'), 'the request row records the amount that was debited');
    }

    public function test_v3_update_is_refused_when_the_store_has_no_usd_row_to_convert_through(): void
    {
        $this->withdrawV3(150)->assertStatus(200);
        $withdrawRequestId = (int) DB::table('withdraw_requests')->value('id');
        $this->removeTheUsdCurrencyRow();

        $this->updateWithdrawV3($withdrawRequestId, 375)->assertStatus(403);

        $this->assertSame([60.0, 60.0], $this->wallet(), 'a refused update leaves the wallet as the create left it');
        $this->assertSame(40.0, (float) DB::table('withdraw_requests')->value('amount'));
    }

    public function test_the_vendor_panel_update_stores_and_debits_the_one_converted_amount(): void
    {
        $this->withdrawOnTheVendorPanel(150)->assertRedirect();
        $this->assertSame([60.0, 60.0], $this->wallet());
        $withdrawRequestId = (int) DB::table('withdraw_requests')->value('id');

        $this->updateWithdrawOnTheVendorPanel($withdrawRequestId, 375)->assertRedirect();

        $this->assertSame([0.0, 120.0], $this->wallet());
        $this->assertSame(100.0, (float) DB::table('withdraw_requests')->value('amount'));
    }

    /**
     * The amount stored on the request and the amount taken off the wallet have to be the same number.
     *
     * With a USD rate of 1 every conversion in the repository agrees, so the figures alone cannot tell them
     * apart. A USD rate of 2 can: the vendor panel used to convert through currencyConverter, whose USD
     * lookup asks for the lowercase code the USD constant holds while the rows are written 'USD', so it fell
     * back to a rate of 1 on any engine that compares strings case-sensitively and converted at a rate the
     * store does not hold.
     */
    public function test_the_vendor_panel_stores_the_same_amount_it_debits_when_the_usd_rate_is_not_one(): void
    {
        DB::table('currencies')->where('id', self::USD_ID)->update(['exchange_rate' => 2]);
        Cache::flush();

        $this->withdrawOnTheVendorPanel(150)->assertRedirect();

        [$totalEarning, $pendingWithdraw] = $this->wallet();
        $storedAmount = (float) DB::table('withdraw_requests')->value('amount');
        $this->assertSame(80.0, $storedAmount, '150 of a 3.75 default currency is 80 at a USD rate of 2');
        $this->assertSame(100.0 - $storedAmount, $totalEarning, 'the wallet is debited the amount the request records');
        $this->assertSame(20.0 + $storedAmount, $pendingWithdraw, 'the pending total rises by the amount the request records');
    }

    public function test_v3_stores_the_exact_amount_it_debits_when_the_conversion_does_not_divide_evenly(): void
    {
        $this->withdrawV3(self::AMOUNT_THAT_DOES_NOT_DIVIDE)->assertStatus(200);

        $this->assertTheStoredAmountIsExactlyWhatTheWalletMoved(earningBeforeTheRequest: 100.0, pendingBeforeTheRequest: 20.0);
    }

    public function test_v2_stores_the_exact_amount_it_debits_when_the_conversion_does_not_divide_evenly(): void
    {
        $this->withdrawV2(self::AMOUNT_THAT_DOES_NOT_DIVIDE)->assertStatus(200);

        $this->assertTheStoredAmountIsExactlyWhatTheWalletMoved(earningBeforeTheRequest: 100.0, pendingBeforeTheRequest: 20.0);
    }

    public function test_the_vendor_panel_stores_the_exact_amount_it_debits_when_the_conversion_does_not_divide_evenly(): void
    {
        $this->withdrawOnTheVendorPanel(self::AMOUNT_THAT_DOES_NOT_DIVIDE)->assertRedirect();

        $this->assertTheStoredAmountIsExactlyWhatTheWalletMoved(earningBeforeTheRequest: 100.0, pendingBeforeTheRequest: 20.0);
    }

    /**
     * The update credits the request it replaces back before it debits the new amount, so the wallet it writes
     * from is the one the first request started from.
     */
    public function test_v3_update_stores_the_exact_amount_it_debits_when_the_conversion_does_not_divide_evenly(): void
    {
        $this->withdrawV3(150)->assertStatus(200);
        $withdrawRequestId = (int) DB::table('withdraw_requests')->value('id');

        $this->updateWithdrawV3($withdrawRequestId, self::AMOUNT_THAT_DOES_NOT_DIVIDE)->assertStatus(200);

        $this->assertTheStoredAmountIsExactlyWhatTheWalletMoved(earningBeforeTheRequest: 100.0, pendingBeforeTheRequest: 20.0);
    }

    public function test_the_vendor_panel_update_stores_the_exact_amount_it_debits_when_the_conversion_does_not_divide_evenly(): void
    {
        $this->withdrawOnTheVendorPanel(150)->assertRedirect();
        $withdrawRequestId = (int) DB::table('withdraw_requests')->value('id');

        $this->updateWithdrawOnTheVendorPanel($withdrawRequestId, self::AMOUNT_THAT_DOES_NOT_DIVIDE)->assertRedirect();

        $this->assertTheStoredAmountIsExactlyWhatTheWalletMoved(earningBeforeTheRequest: 100.0, pendingBeforeTheRequest: 20.0);
    }

    /**
     * Closing a request credits back the amount the row records, so the row and the debit disagreeing by any
     * amount at all leaves the wallet richer or poorer every time a seller opens a request and closes it again.
     */
    public function test_opening_and_closing_a_withdrawal_that_does_not_divide_evenly_leaves_the_wallet_exactly_as_it_was(): void
    {
        $this->withdrawV3(self::AMOUNT_THAT_DOES_NOT_DIVIDE)->assertStatus(200);
        $withdrawRequestId = (int) DB::table('withdraw_requests')->value('id');

        $this->closeWithdrawV3($withdrawRequestId)->assertStatus(200);

        $this->assertNothingWritten();
    }

    public function test_v3_refuses_a_withdrawal_when_the_default_currency_has_no_usable_rate(): void
    {
        foreach (self::UNUSABLE_RATES as $exchangeRate) {
            $this->setTheDefaultCurrencyRateTo($exchangeRate);

            $response = $this->withdrawV3(375);

            $response->assertStatus(403);
            $this->assertSame(['message' => 'Invalid withdraw request'], $response->json(), (string) $exchangeRate);
        }
        $this->assertNothingWritten();
    }

    public function test_v2_refuses_a_withdrawal_when_the_default_currency_has_no_usable_rate(): void
    {
        foreach (self::UNUSABLE_RATES as $exchangeRate) {
            $this->setTheDefaultCurrencyRateTo($exchangeRate);

            $response = $this->withdrawV2(375);

            $response->assertStatus(400);
            $this->assertSame(['message' => 'Invalid withdraw request'], $response->json(), (string) $exchangeRate);
        }
        $this->assertNothingWritten();
    }

    public function test_the_vendor_panel_refuses_a_withdrawal_when_the_default_currency_has_no_usable_rate(): void
    {
        foreach (self::UNUSABLE_RATES as $exchangeRate) {
            $this->setTheDefaultCurrencyRateTo($exchangeRate);

            $this->withdrawOnTheVendorPanel(375)->assertRedirect();
        }
        $this->assertNothingWritten();
    }

    /**
     * Neither seller API validates the amount, so whatever the app sends reaches the conversion. An array or a
     * word has to get the same refusal as an amount that is too large, not an unhandled type error.
     */
    public function test_v3_refuses_an_amount_that_is_not_a_number_and_writes_nothing(): void
    {
        foreach ([['375'], 'abc', true] as $amount) {
            $response = $this->withdrawV3($amount);

            $response->assertStatus(403);
            $this->assertSame(['message' => 'Invalid withdraw request'], $response->json(), json_encode($amount));
        }
        $this->assertNothingWritten();
    }

    public function test_v2_refuses_an_amount_that_is_not_a_number_and_writes_nothing(): void
    {
        foreach ([['375'], 'abc', true] as $amount) {
            $response = $this->withdrawV2($amount);

            $response->assertStatus(400);
            $this->assertSame(['message' => 'Invalid withdraw request'], $response->json(), json_encode($amount));
        }
        $this->assertNothingWritten();
    }

    public function test_the_vendor_panel_refuses_an_amount_that_is_not_a_number_and_writes_nothing(): void
    {
        foreach ([['375'], 'abc'] as $amount) {
            $this->withdrawOnTheVendorPanel($amount)->assertSessionHasErrors('amount');
        }
        $this->assertNothingWritten();
    }

    public function test_the_vendor_panel_update_is_refused_when_the_store_has_no_usd_row_to_convert_through(): void
    {
        $this->withdrawOnTheVendorPanel(150)->assertRedirect();
        $withdrawRequestId = (int) DB::table('withdraw_requests')->value('id');
        $this->removeTheUsdCurrencyRow();

        $this->updateWithdrawOnTheVendorPanel($withdrawRequestId, 375)->assertRedirect();

        $this->assertSame([60.0, 60.0], $this->wallet(), 'a refused update leaves the wallet as the create left it');
        $this->assertSame(40.0, (float) DB::table('withdraw_requests')->value('amount'));
    }
}
