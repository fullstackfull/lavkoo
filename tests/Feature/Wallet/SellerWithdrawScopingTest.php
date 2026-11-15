<?php

namespace Tests\Feature\Wallet;

use App\Models\Seller;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A seller may only update or close their own withdraw request, and only while it is still pending.
 *
 * Every request goes through the real route and its real seller authentication: the v3 bearer-token middleware with its payout permission,
 * the v2 token lookup, and the vendor panel's seller guard. Each refusal asserts that neither the request nor either wallet moved.
 */
class SellerWithdrawScopingTest extends TestCase
{
    use RestoresLanguageFiles;

    private const OWNER_ID = 1;

    private const OTHER_ID = 2;

    private const OWNER_TOKEN = 'owner-token-0123456789-0123456789-0123456789';

    private const OTHER_TOKEN = 'other-token-0123456789-0123456789-0123456789';

    private const METHOD_ID = 1;

    private const PENDING = 0;

    private const APPROVED = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        $this->createWithdrawTables();
        $this->useSingleCurrency();

        $this->insertSeller(self::OWNER_ID, self::OWNER_TOKEN);
        $this->insertSeller(self::OTHER_ID, self::OTHER_TOKEN);
        $this->insertWallet(self::OWNER_ID, totalEarning: 1000, pendingWithdraw: 0);
        $this->insertWallet(self::OTHER_ID, totalEarning: 500, pendingWithdraw: 200);
        DB::table('withdrawal_methods')->insert([
            'id' => self::METHOD_ID, 'method_name' => 'Bank', 'method_fields' => json_encode([['input_name' => 'account']]),
            'is_active' => 1, 'vendor_status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    public function test_v3_update_of_another_sellers_pending_request_is_refused_and_moves_nothing(): void
    {
        $this->insertRequest(10, self::OTHER_ID, amount: 200, approved: self::PENDING);
        $before = $this->snapshot(10);

        $response = $this->v3Update(self::OWNER_TOKEN, requestId: 10, amount: 50);

        $response->assertStatus(403);
        $this->assertSame($before, $this->snapshot(10));
    }

    public function test_v3_close_of_another_sellers_pending_request_is_refused_and_the_request_survives(): void
    {
        $this->insertRequest(10, self::OTHER_ID, amount: 200, approved: self::PENDING);
        $before = $this->snapshot(10);

        $response = $this->v3Close(self::OWNER_TOKEN, requestId: 10);

        $response->assertStatus(403);
        $this->assertSame($before, $this->snapshot(10));
    }

    public function test_v2_close_of_another_sellers_pending_request_is_refused_and_the_request_survives(): void
    {
        $this->insertRequest(10, self::OTHER_ID, amount: 200, approved: self::PENDING);
        $before = $this->snapshot(10);

        $response = $this->v2Close(self::OWNER_TOKEN, requestId: 10, amount: 200);

        $response->assertStatus(400);
        $this->assertSame($before, $this->snapshot(10));
    }

    public function test_vendor_update_of_another_sellers_request_is_refused_and_moves_nothing(): void
    {
        $this->insertRequest(10, self::OTHER_ID, amount: 200, approved: self::PENDING);
        $before = $this->snapshot(10);

        $response = $this->vendorUpdate(self::OWNER_ID, requestId: 10, amount: 50);

        $response->assertRedirect();
        $this->assertContains('error', $this->toastTypes());
        $this->assertNotContains('success', $this->toastTypes());
        $this->assertSame($before, $this->snapshot(10));
    }

    public function test_v3_update_of_the_sellers_own_decided_request_is_refused_and_moves_nothing(): void
    {
        $this->insertRequest(10, self::OWNER_ID, amount: 200, approved: self::APPROVED);
        $before = $this->snapshot(10);

        $response = $this->v3Update(self::OWNER_TOKEN, requestId: 10, amount: 50);

        $response->assertStatus(403);
        $this->assertSame($before, $this->snapshot(10));
    }

    public function test_vendor_update_of_the_sellers_own_decided_request_is_refused_and_moves_nothing(): void
    {
        $this->insertRequest(10, self::OWNER_ID, amount: 200, approved: self::APPROVED);
        $before = $this->snapshot(10);

        $response = $this->vendorUpdate(self::OWNER_ID, requestId: 10, amount: 50);

        $response->assertRedirect();
        $this->assertContains('error', $this->toastTypes());
        $this->assertNotContains('success', $this->toastTypes());
        $this->assertSame($before, $this->snapshot(10));
    }

    public function test_v3_update_of_a_request_approved_after_it_was_read_is_refused_and_moves_nothing(): void
    {
        $this->insertRequest(10, self::OWNER_ID, amount: 200, approved: self::PENDING);
        $this->setWallet(self::OWNER_ID, totalEarning: 800, pendingWithdraw: 200);
        $this->approveRequestRightAfterItIsRead(10);
        $expectedRequest = ['seller_id' => self::OWNER_ID, 'amount' => 200.0, 'approved' => self::APPROVED];
        $expectedWallets = ['owner' => $this->wallet(self::OWNER_ID), 'other' => $this->wallet(self::OTHER_ID)];

        $response = $this->v3Update(self::OWNER_TOKEN, requestId: 10, amount: 50);

        $response->assertStatus(403);
        $this->assertSame(['request' => $expectedRequest] + $expectedWallets, $this->snapshot(10));
    }

    public function test_vendor_update_of_a_request_approved_after_it_was_read_is_refused_and_moves_nothing(): void
    {
        $this->insertRequest(10, self::OWNER_ID, amount: 200, approved: self::PENDING);
        $this->setWallet(self::OWNER_ID, totalEarning: 800, pendingWithdraw: 200);
        $this->approveRequestRightAfterItIsRead(10);
        $expectedRequest = ['seller_id' => self::OWNER_ID, 'amount' => 200.0, 'approved' => self::APPROVED];
        $expectedWallets = ['owner' => $this->wallet(self::OWNER_ID), 'other' => $this->wallet(self::OTHER_ID)];

        $response = $this->vendorUpdate(self::OWNER_ID, requestId: 10, amount: 50);

        $response->assertRedirect();
        $this->assertContains('error', $this->toastTypes());
        $this->assertNotContains('success', $this->toastTypes());
        $this->assertSame(['request' => $expectedRequest] + $expectedWallets, $this->snapshot(10));
    }

    public function test_vendor_update_of_a_request_moved_to_another_seller_after_it_was_read_is_refused_and_moves_nothing(): void
    {
        $this->insertRequest(10, self::OWNER_ID, amount: 200, approved: self::PENDING);
        $this->setWallet(self::OWNER_ID, totalEarning: 800, pendingWithdraw: 200);
        $this->changeRequestRightAfterItIsRead(10, ['seller_id' => self::OTHER_ID]);
        $expectedRequest = ['seller_id' => self::OTHER_ID, 'amount' => 200.0, 'approved' => self::PENDING];
        $expectedWallets = ['owner' => $this->wallet(self::OWNER_ID), 'other' => $this->wallet(self::OTHER_ID)];

        $response = $this->vendorUpdate(self::OWNER_ID, requestId: 10, amount: 50);

        $response->assertRedirect();
        $this->assertContains('error', $this->toastTypes());
        $this->assertNotContains('success', $this->toastTypes());
        $this->assertSame(['request' => $expectedRequest] + $expectedWallets, $this->snapshot(10));
    }

    public function test_v3_update_of_the_sellers_own_pending_request_moves_the_difference_between_the_wallet_columns(): void
    {
        $this->insertRequest(10, self::OWNER_ID, amount: 200, approved: self::PENDING);
        $this->setWallet(self::OWNER_ID, totalEarning: 800, pendingWithdraw: 200);
        $otherWallet = $this->wallet(self::OTHER_ID);

        $response = $this->v3Update(self::OWNER_TOKEN, requestId: 10, amount: 50);

        $response->assertStatus(200);
        $this->assertSame('Withdraw request update successfully!', $response->json());
        $this->assertSame(['total_earning' => 950.0, 'pending_withdraw' => 50.0], $this->wallet(self::OWNER_ID));
        $this->assertSame($otherWallet, $this->wallet(self::OTHER_ID));
        $this->assertSame(['seller_id' => self::OWNER_ID, 'amount' => 50.0, 'approved' => self::PENDING], $this->request(10));
    }

    public function test_vendor_update_of_the_sellers_own_pending_request_moves_the_difference_between_the_wallet_columns(): void
    {
        $this->insertRequest(10, self::OWNER_ID, amount: 200, approved: self::PENDING);
        $this->setWallet(self::OWNER_ID, totalEarning: 800, pendingWithdraw: 200);
        $otherWallet = $this->wallet(self::OTHER_ID);

        $response = $this->vendorUpdate(self::OWNER_ID, requestId: 10, amount: 50);

        $response->assertRedirect();
        $this->assertContains('success', $this->toastTypes());
        $this->assertSame(['total_earning' => 950.0, 'pending_withdraw' => 50.0], $this->wallet(self::OWNER_ID));
        $this->assertSame($otherWallet, $this->wallet(self::OTHER_ID));
        $this->assertSame(['seller_id' => self::OWNER_ID, 'amount' => 50.0, 'approved' => self::PENDING], $this->request(10));
    }

    public function test_v3_close_of_the_sellers_own_pending_request_returns_the_amount_to_the_wallet(): void
    {
        $this->insertRequest(10, self::OWNER_ID, amount: 200, approved: self::PENDING);
        $this->setWallet(self::OWNER_ID, totalEarning: 800, pendingWithdraw: 200);
        $otherWallet = $this->wallet(self::OTHER_ID);

        $response = $this->v3Close(self::OWNER_TOKEN, requestId: 10);

        $response->assertStatus(200);
        $this->assertSame('Withdraw request has been closed successfully!', $response->json());
        $this->assertSame(['total_earning' => 1000.0, 'pending_withdraw' => 0.0], $this->wallet(self::OWNER_ID));
        $this->assertSame($otherWallet, $this->wallet(self::OTHER_ID));
        $this->assertNull($this->request(10));
    }

    public function test_v2_close_of_the_sellers_own_pending_request_returns_the_amount_to_the_wallet(): void
    {
        $this->insertRequest(10, self::OWNER_ID, amount: 200, approved: self::PENDING);
        $this->setWallet(self::OWNER_ID, totalEarning: 800, pendingWithdraw: 200);

        $response = $this->v2Close(self::OWNER_TOKEN, requestId: 10, amount: 200);

        $response->assertStatus(200);
        $this->assertSame(['total_earning' => 1000.0, 'pending_withdraw' => 0.0], $this->wallet(self::OWNER_ID));
        $this->assertNull($this->request(10));
    }

    public function test_v2_close_currently_credits_the_converted_stored_amount_and_debits_the_converted_posted_amount(): void
    {
        $this->useMultiCurrency(defaultRate: 4.0);
        $this->insertRequest(10, self::OWNER_ID, amount: 200, approved: self::PENDING);
        $this->setWallet(self::OWNER_ID, totalEarning: 800, pendingWithdraw: 200);

        $response = $this->v2Close(self::OWNER_TOKEN, requestId: 10, amount: 40);

        $response->assertStatus(200);
        $this->assertSame(
            ['total_earning' => 850.0, 'pending_withdraw' => 190.0],
            $this->wallet(self::OWNER_ID),
            'currently the stored USD amount is converted again on credit (200 / 4 = 50) and pending drops by the posted amount converted (40 / 4 = 10)',
        );
        $this->assertNull($this->request(10));
    }

    private function approveRequestRightAfterItIsRead(int $requestId): void
    {
        $this->changeRequestRightAfterItIsRead($requestId, ['approved' => self::APPROVED]);
    }

    /** @param array<string, int> $changes */
    private function changeRequestRightAfterItIsRead(int $requestId, array $changes): void
    {
        $hasChanged = false;
        DB::listen(static function (QueryExecuted $query) use (&$hasChanged, $requestId, $changes): void {
            if ($hasChanged || !str_starts_with(strtolower($query->sql), 'select') || !str_contains($query->sql, 'withdraw_requests')) {
                return;
            }
            $hasChanged = true;
            DB::table('withdraw_requests')->where('id', $requestId)->update($changes);
        });
    }

    private function v3Update(string $token, int $requestId, float $amount): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v3/seller/balance-withdraw-update', [
            'withdraw_request_id' => $requestId, 'withdraw_method_id' => self::METHOD_ID, 'amount' => $amount, 'account' => 'SA00',
        ]);
    }

    private function v3Close(string $token, int $requestId): TestResponse
    {
        return $this->withToken($token)->deleteJson('/api/v3/seller/close-withdraw-request', ['id' => $requestId]);
    }

    private function v2Close(string $token, int $requestId, float $amount): TestResponse
    {
        return $this->withToken($token)->deleteJson('/api/v2/seller/close-withdraw-request', ['id' => $requestId, 'amount' => $amount]);
    }

    private function vendorUpdate(int $sellerId, int $requestId, float $amount): TestResponse
    {
        $this->actingAs(Seller::query()->findOrFail($sellerId), 'seller');

        return $this->from('/vendor/dashboard')->post(route('vendor.dashboard.withdraw-request-update'), [
            'withdraw_request_id' => $requestId, 'withdraw_method' => self::METHOD_ID, 'amount' => $amount, 'method_info' => ['account' => 'SA00'],
        ]);
    }

    /** @return list<string> */
    private function toastTypes(): array
    {
        return array_column(session('laravel-toaster-magic::messages', []), 'type');
    }

    /** @return array{request: array<string, mixed>|null, owner: array<string, float>, other: array<string, float>} */
    private function snapshot(int $requestId): array
    {
        return ['request' => $this->request($requestId), 'owner' => $this->wallet(self::OWNER_ID), 'other' => $this->wallet(self::OTHER_ID)];
    }

    /** @return array{seller_id: int, amount: float, approved: int}|null */
    private function request(int $id): ?array
    {
        $row = DB::table('withdraw_requests')->where('id', $id)->first();

        return $row === null ? null : ['seller_id' => (int) $row->seller_id, 'amount' => (float) $row->amount, 'approved' => (int) $row->approved];
    }

    /** @return array{total_earning: float, pending_withdraw: float} */
    private function wallet(int $sellerId): array
    {
        $row = DB::table('seller_wallets')->where('seller_id', $sellerId)->first();

        return ['total_earning' => (float) $row->total_earning, 'pending_withdraw' => (float) $row->pending_withdraw];
    }

    private function setWallet(int $sellerId, float $totalEarning, float $pendingWithdraw): void
    {
        DB::table('seller_wallets')->where('seller_id', $sellerId)->update(['total_earning' => $totalEarning, 'pending_withdraw' => $pendingWithdraw]);
    }

    private function insertSeller(int $id, string $token): void
    {
        DB::table('sellers')->insert([
            'id' => $id, 'f_name' => 'Seller ' . $id, 'email' => "seller{$id}@example.test", 'password' => 'unused', 'status' => 'approved',
            'auth_token' => $token, 'bank_name' => 'Bank', 'account_no' => 'SA00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertWallet(int $sellerId, float $totalEarning, float $pendingWithdraw): void
    {
        DB::table('seller_wallets')->insert([
            'seller_id' => $sellerId, 'total_earning' => $totalEarning, 'pending_withdraw' => $pendingWithdraw, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertRequest(int $id, int $sellerId, float $amount, int $approved): void
    {
        DB::table('withdraw_requests')->insert([
            'id' => $id, 'seller_id' => $sellerId, 'amount' => $amount, 'withdrawal_method_id' => self::METHOD_ID,
            'withdrawal_method_fields' => json_encode(['method_name' => 'Bank']), 'approved' => $approved, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function useSingleCurrency(): void
    {
        $this->setBusinessSetting('currency_model', 'single_currency');
    }

    private function useMultiCurrency(float $defaultRate): void
    {
        DB::table('currencies')->insert([
            ['id' => 1, 'code' => 'USD', 'exchange_rate' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'code' => 'SAR', 'exchange_rate' => $defaultRate, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->setBusinessSetting('system_default_currency', '2');
        $this->setBusinessSetting('currency_model', 'multi_currency');
    }

    private function setBusinessSetting(string $type, string $value): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => $type], ['value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        Cache::flush();
    }

    private function createWithdrawTables(): void
    {
        Schema::create('business_settings', static function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('currencies', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code');
            $table->decimal('exchange_rate', 16, 6)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('sellers', static function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('approved');
            $table->string('auth_token')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_no')->nullable();
            $table->string('remember_token')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('seller_wallets', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->decimal('total_earning', 16, 2)->default(0);
            $table->decimal('withdrawn', 16, 2)->default(0);
            $table->decimal('commission_given', 16, 2)->default(0);
            $table->decimal('pending_withdraw', 16, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('withdraw_requests', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->unsignedBigInteger('withdrawal_method_id')->nullable();
            $table->text('withdrawal_method_fields')->nullable();
            $table->string('transaction_note')->nullable();
            $table->unsignedTinyInteger('approved')->default(0);
            $table->string('request_method_type')->nullable();
            $table->timestamps();
        });

        Schema::create('withdrawal_methods', static function (Blueprint $table): void {
            $table->id();
            $table->string('method_name');
            $table->text('method_fields')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('vendor_status')->default(true);
            $table->timestamps();
        });
    }
}
