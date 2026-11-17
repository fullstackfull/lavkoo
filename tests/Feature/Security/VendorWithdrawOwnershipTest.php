<?php

namespace Tests\Feature\Security;

use App\Exports\VendorWithdrawRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The vendor panel's withdraw handlers (the dashboard's update and the close action) read a withdraw request by the id in the request. A request is the seller's own, and open to change,
 * only when it carries the seller's id, belongs to no delivery man (a driver's request carries the seller's id too, but it is paid out of the
 * driver's wallet) and is still pending. Without that, a seller took over another seller's pending request (its amount and its payout fields
 * became the caller's, and the caller's wallet was credited with it), reopened a request that was already paid, and rewrote a driver's.
 *
 * The wallet of seller 1 holds 500 earned and 100 pending, the wallet of seller 2 holds 1000 pending.
 */
class VendorWithdrawOwnershipTest extends VendorPanelTestCase
{
    private const OTHER_SELLERS_PENDING_REQUEST = 50;
    private const OWN_PENDING_REQUEST = 51;
    private const OTHER_SELLERS_PAID_REQUEST = 52;
    private const OWN_PAID_REQUEST = 53;
    private const OWN_DELIVERY_MANS_REQUEST = 54;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('withdrawal_methods', static function (Blueprint $table): void {
            $table->id();
            $table->string('method_name')->nullable();
            $table->text('method_fields')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('vendor_status')->default(true);
            $table->boolean('customer_status')->default(true);
            $table->timestamps();
        });
        Schema::create('seller_wallets', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->decimal('total_earning', 16, 2)->default(0);
            $table->decimal('pending_withdraw', 16, 2)->default(0);
            $table->decimal('withdrawn', 16, 2)->default(0);
            $table->decimal('commission_given', 16, 2)->default(0);
            $table->decimal('delivery_charge_earned', 16, 2)->default(0);
            $table->decimal('collected_cash', 16, 2)->default(0);
            $table->decimal('total_tax_collected', 16, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('withdraw_requests', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->tinyInteger('approved')->default(0);
            $table->text('transaction_note')->nullable();
            $table->unsignedBigInteger('withdrawal_method_id')->nullable();
            $table->text('withdrawal_method_fields')->nullable();
            $table->string('request_method_type')->nullable();
            $table->timestamps();
        });

        DB::table('withdrawal_methods')->insert([
            'id' => 1, 'method_name' => 'Bank', 'method_fields' => json_encode([['input_name' => 'iban', 'input_type' => 'text', 'placeholder' => 'IBAN', 'is_required' => 1]]),
        ]);
        DB::table('seller_wallets')->insert([
            ['seller_id' => self::SELLER, 'total_earning' => 500, 'pending_withdraw' => 100],
            ['seller_id' => self::OTHER_SELLER, 'total_earning' => 0, 'pending_withdraw' => 1000],
        ]);
        $this->insertWithdrawRequest(id: self::OTHER_SELLERS_PENDING_REQUEST, sellerId: self::OTHER_SELLER, amount: 1000, approved: 0, iban: 'SA-VICTIM-IBAN');
        $this->insertWithdrawRequest(id: self::OWN_PENDING_REQUEST, sellerId: self::SELLER, amount: 100, approved: 0, iban: 'SA-OWNER-IBAN');
        $this->insertWithdrawRequest(id: self::OTHER_SELLERS_PAID_REQUEST, sellerId: self::OTHER_SELLER, amount: 700, approved: 1, iban: 'SA-VICTIM-IBAN');
        $this->insertWithdrawRequest(id: self::OWN_PAID_REQUEST, sellerId: self::SELLER, amount: 80, approved: 1, iban: 'SA-OWNER-IBAN');
        $this->insertWithdrawRequest(id: self::OWN_DELIVERY_MANS_REQUEST, sellerId: self::SELLER, amount: 30, approved: 0, iban: 'SA-DRIVER-IBAN', deliveryManId: 9);
    }

    /** @return array<string, array{int}> the requests the seller may not rewrite or close: not theirs, not pending, a driver's, or no request at all */
    public static function requestsThatAreNotTheSellersToChange(): array
    {
        return [
            'another seller pending request' => [self::OTHER_SELLERS_PENDING_REQUEST],
            'another seller paid request' => [self::OTHER_SELLERS_PAID_REQUEST],
            'own paid request' => [self::OWN_PAID_REQUEST],
            'own delivery man request' => [self::OWN_DELIVERY_MANS_REQUEST],
            'a request that does not exist' => [999],
        ];
    }

    #[DataProvider('requestsThatAreNotTheSellersToChange')]
    public function test_a_withdraw_request_that_is_not_the_sellers_pending_own_is_not_updated_and_the_wallet_is_not_credited(int $requestId): void
    {
        $before = $this->requestsAndWallets();

        $this->signInAs(self::SELLER)->post('/vendor/dashboard/withdraw-request-update', $this->updateForm(requestId: $requestId))->assertRedirect();

        $this->assertSame(['error'], $this->toastTypes());
        $this->assertSame($before, $this->requestsAndWallets());
    }

    public function test_the_sellers_own_pending_request_is_still_updated_and_the_wallet_follows(): void
    {
        $this->signInAs(self::SELLER)->post('/vendor/dashboard/withdraw-request-update', $this->updateForm(requestId: self::OWN_PENDING_REQUEST))->assertRedirect();

        $this->assertSame(['success'], $this->toastTypes());
        $request = DB::table('withdraw_requests')->where('id', self::OWN_PENDING_REQUEST)->first();
        $this->assertSame([self::SELLER, 50.0, 0], [(int)$request->seller_id, (float)$request->amount, (int)$request->approved]);
        $this->assertSame('SA-NEW-IBAN', json_decode($request->withdrawal_method_fields, true)['iban']);
        $this->assertSame([550.0, 50.0], $this->walletOf(self::SELLER), 'the 100 held for the request went back and the new 50 was taken');
    }

    #[DataProvider('requestsThatAreNotTheSellersToChange')]
    public function test_a_withdraw_request_that_is_not_the_sellers_pending_own_is_not_closed_and_the_wallet_is_not_credited(int $requestId): void
    {
        $before = $this->requestsAndWallets();

        $this->signInAs(self::SELLER)->get('/vendor/business-settings/withdraw/close/' . $requestId)->assertRedirect();

        $this->assertSame(['error'], $this->toastTypes());
        $this->assertSame($before, $this->requestsAndWallets());
    }

    public function test_the_sellers_own_pending_request_is_still_closed_and_its_amount_goes_back_to_the_earning(): void
    {
        $this->signInAs(self::SELLER)->get('/vendor/business-settings/withdraw/close/' . self::OWN_PENDING_REQUEST)->assertRedirect();

        $this->assertSame(['success'], $this->toastTypes());
        $this->assertNull(DB::table('withdraw_requests')->where('id', self::OWN_PENDING_REQUEST)->first());
        $this->assertSame([600.0, 0.0], $this->walletOf(self::SELLER));
    }

    // ---- the lists ----

    /** @return array<string, array{string, list<int>}> the status the list is asked for and the requests that are the seller's own, whoever they were asked by */
    public static function statusesAndTheSellersOwnRequestsInThem(): array
    {
        return [
            'pending' => ['pending', [self::OWN_PENDING_REQUEST]],
            'approved' => ['approved', [self::OWN_PAID_REQUEST]],
            'denied' => ['denied', []],
            'all' => ['all', [self::OWN_PENDING_REQUEST, self::OWN_PAID_REQUEST]],
        ];
    }

    /** @param list<int> $expected */
    #[DataProvider('statusesAndTheSellersOwnRequestsInThem')]
    public function test_the_status_filtered_list_shows_the_sellers_own_requests_and_not_a_delivery_mans_or_another_sellers(string $status, array $expected): void
    {
        $this->useTheDefaultCurrency();

        $response = $this->signInAs(self::SELLER)->postJson('/vendor/business-settings/withdraw/index', ['status' => $status])->assertOk();

        preg_match_all('#withdraw/close/(\d+)#', (string)$response->json('view'), $matches);
        $this->assertEqualsCanonicalizing($expected, array_map('intval', $matches[1]));
        $this->assertSame(count($expected), $response->json('count'));
    }

    /** @param list<int> $expected */
    #[DataProvider('statusesAndTheSellersOwnRequestsInThem')]
    public function test_the_export_lists_the_sellers_own_requests_and_not_a_delivery_mans_or_another_sellers(string $status, array $expected): void
    {
        Excel::fake();

        $this->signInAs(self::SELLER)->get('/vendor/business-settings/withdraw/export?status=' . $status)->assertOk();

        Excel::assertDownloaded('Vendor-Withdraw-Request.xlsx', function (VendorWithdrawRequest $export) use ($expected): bool {
            $this->assertEqualsCanonicalizing($expected, $export->view()->getData()['data']['withdraw_request']->pluck('id')->map(static fn ($id): int => (int)$id)->all());

            return true;
        });
    }

    private function useTheDefaultCurrency(): void
    {
        DB::table('currencies')->insert(['id' => 1, 'name' => 'US Dollar', 'symbol' => '$', 'code' => 'USD', 'exchange_rate' => 1]);
        DB::table('business_settings')->insert([['type' => 'system_default_currency', 'value' => '1'], ['type' => 'currency_model', 'value' => 'single_currency']]);
        Cache::flush();
    }

    /** @return array<string, array<string, mixed>> */
    private function requestsAndWallets(): array
    {
        return [
            'requests' => DB::table('withdraw_requests')->orderBy('id')->get()->map(static fn (object $row): array => Arr::except((array)$row, ['created_at', 'updated_at']))->all(),
            'wallets' => DB::table('seller_wallets')->orderBy('seller_id')->get()->map(static fn (object $row): array => Arr::except((array)$row, ['created_at', 'updated_at']))->all(),
        ];
    }

    /** @return list<float> the earned and the pending amounts of a seller's wallet */
    private function walletOf(int $sellerId): array
    {
        $wallet = DB::table('seller_wallets')->where('seller_id', $sellerId)->first();

        return [(float)$wallet->total_earning, (float)$wallet->pending_withdraw];
    }

    /** @return array<string, mixed> what the dashboard's withdraw form posts when the seller changes a request to 50 and a new IBAN */
    private function updateForm(int $requestId): array
    {
        return ['withdraw_request_id' => $requestId, 'withdraw_method' => 1, 'amount' => 50, 'method_info' => ['iban' => 'SA-NEW-IBAN']];
    }

    private function insertWithdrawRequest(int $id, int $sellerId, float $amount, int $approved, string $iban, ?int $deliveryManId = null): void
    {
        DB::table('withdraw_requests')->insert([
            'id' => $id, 'seller_id' => $sellerId, 'delivery_man_id' => $deliveryManId, 'amount' => $amount, 'approved' => $approved, 'withdrawal_method_id' => 1,
            'withdrawal_method_fields' => json_encode(['method_name' => 'Bank', 'iban' => $iban]), 'request_method_type' => 'system',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
