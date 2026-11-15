<?php

namespace Tests\Feature\Wallet;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Seller;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The vendor panel closes a withdraw request only on a POST that carries a valid CSRF token.
 *
 * Every request goes through the real route with the real vendor panel middleware and seller guard. CSRF verification is
 * switched on by binding a VerifyCsrfToken subclass that does not skip the check while unit tests run.
 */
class VendorWithdrawClosePostTest extends TestCase
{
    use RestoresLanguageFiles;

    private const OWNER_ID = 1;

    private const OTHER_ID = 2;

    private const METHOD_ID = 1;

    private const PENDING = 0;

    private const CSRF_TOKEN = 'csrf-token-0123456789-0123456789-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        $this->createWithdrawTables();
        $this->setBusinessSetting('currency_model', 'single_currency');
        $this->enforceCsrfVerification();

        $this->insertSeller(self::OWNER_ID);
        $this->insertSeller(self::OTHER_ID);
        $this->insertWallet(self::OWNER_ID, totalEarning: 800, pendingWithdraw: 200);
        $this->insertWallet(self::OTHER_ID, totalEarning: 500, pendingWithdraw: 200);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    public function test_get_to_the_close_route_is_refused_and_moves_nothing(): void
    {
        $this->insertRequest(10, self::OWNER_ID, amount: 200);
        $before = $this->snapshot(10);

        $this->actingAsSeller(self::OWNER_ID);
        $response = $this->from('/vendor/dashboard')->get($this->closeUrl(10));

        $response->assertStatus(405);
        $this->assertSame($before, $this->snapshot(10));
    }

    public function test_post_without_a_csrf_token_is_refused_and_moves_nothing(): void
    {
        $this->insertRequest(10, self::OWNER_ID, amount: 200);
        $before = $this->snapshot(10);

        $this->actingAsSeller(self::OWNER_ID);
        $response = $this->withSession(['_token' => self::CSRF_TOKEN])->from('/vendor/dashboard')->post($this->closeUrl(10));

        $response->assertStatus(419);
        $this->assertSame($before, $this->snapshot(10));
    }

    public function test_post_with_a_valid_csrf_token_closes_the_sellers_own_pending_request(): void
    {
        $this->insertRequest(10, self::OWNER_ID, amount: 200);
        $otherWallet = $this->wallet(self::OTHER_ID);

        $response = $this->postClose(self::OWNER_ID, requestId: 10);

        $response->assertRedirect('/vendor/dashboard');
        $this->assertContains('success', $this->toastTypes());
        $this->assertSame(['total_earning' => 1000.0, 'pending_withdraw' => 0.0], $this->wallet(self::OWNER_ID));
        $this->assertSame($otherWallet, $this->wallet(self::OTHER_ID));
        $this->assertNull($this->request(10));
    }

    public function test_post_for_another_sellers_pending_request_is_refused_and_moves_nothing(): void
    {
        $this->insertRequest(10, self::OTHER_ID, amount: 200);
        $before = $this->snapshot(10);

        $response = $this->postClose(self::OWNER_ID, requestId: 10);

        $response->assertRedirect('/vendor/dashboard');
        $this->assertContains('error', $this->toastTypes());
        $this->assertNotContains('success', $this->toastTypes());
        $this->assertSame($before, $this->snapshot(10));
    }

    private function enforceCsrfVerification(): void
    {
        $this->app->bind(VerifyCsrfToken::class, static fn ($app) => new class($app, $app['encrypter']) extends VerifyCsrfToken {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    private function postClose(int $sellerId, int $requestId): TestResponse
    {
        $this->actingAsSeller($sellerId);

        return $this->withSession(['_token' => self::CSRF_TOKEN])
            ->from('/vendor/dashboard')
            ->post($this->closeUrl($requestId), ['_token' => self::CSRF_TOKEN]);
    }

    private function actingAsSeller(int $sellerId): void
    {
        $this->actingAs(Seller::query()->findOrFail($sellerId), 'seller');
    }

    private function closeUrl(int $requestId): string
    {
        return route('vendor.business-settings.withdraw.close', ['id' => $requestId]);
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

    private function insertSeller(int $id): void
    {
        DB::table('sellers')->insert([
            'id' => $id, 'f_name' => 'Seller ' . $id, 'email' => "seller{$id}@example.test", 'password' => 'unused', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertWallet(int $sellerId, float $totalEarning, float $pendingWithdraw): void
    {
        DB::table('seller_wallets')->insert([
            'seller_id' => $sellerId, 'total_earning' => $totalEarning, 'pending_withdraw' => $pendingWithdraw, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertRequest(int $id, int $sellerId, float $amount): void
    {
        DB::table('withdraw_requests')->insert([
            'id' => $id, 'seller_id' => $sellerId, 'amount' => $amount, 'withdrawal_method_id' => self::METHOD_ID,
            'withdrawal_method_fields' => json_encode(['method_name' => 'Bank']), 'approved' => self::PENDING, 'created_at' => now(), 'updated_at' => now(),
        ]);
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

        Schema::create('sellers', static function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('approved');
            $table->string('auth_token')->nullable();
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
    }
}
