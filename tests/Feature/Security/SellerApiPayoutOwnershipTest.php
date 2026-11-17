<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSellerApiWorld;
use Tests\TestCase;

/**
 * The seller app moves the shop's money through two lists: the payout methods the shop is paid to, and the withdraw requests that ask
 * for a payout. The permission middleware only says that the caller may touch payouts, so every handler must still act only on the
 * caller's own row: another seller's payout method or withdraw request is not found, and neither the row nor any wallet changes.
 */
class SellerApiPayoutOwnershipTest extends TestCase
{
    use BuildsSellerApiWorld;

    private const OWN_METHOD = 10;
    private const OWN_SECOND_METHOD = 11;
    private const OTHER_SELLERS_METHOD = 20;
    private const OTHER_SELLERS_SECOND_METHOD = 21;

    private const OTHER_SELLERS_PENDING_REQUEST = 50;
    private const OWN_PENDING_REQUEST = 51;
    private const OTHER_SELLERS_APPROVED_REQUEST = 52;
    private const OWN_APPROVED_REQUEST = 53;
    private const OWN_DELIVERY_MAN_REQUEST = 54;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerApiWorld();
        $this->createPayoutTables();
        $this->seedPayoutMethods();
        $this->seedWithdrawRequests();
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    // ---- payout methods ----

    public function test_a_payout_method_of_another_seller_cannot_be_overwritten(): void
    {
        $response = $this->sellerApi('POST', 'payment-information/update', [
            'id' => self::OTHER_SELLERS_METHOD,
            'withdraw_method_id' => 1,
            'method_name' => 'Attacker bank',
            'method_info' => ['iban' => 'SA-ATTACKER-IBAN'],
            'is_active' => 1,
        ]);

        $this->assertSame(['user_id' => 2, 'method_name' => 'Other seller bank', 'iban' => 'SA-OTHER-SELLER-IBAN'], $this->methodOwnerNameAndIban(self::OTHER_SELLERS_METHOD));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_a_payout_method_of_another_seller_cannot_be_switched_off(): void
    {
        $response = $this->sellerApi('POST', 'payment-information/status', ['id' => self::OTHER_SELLERS_METHOD, 'status' => 0]);

        $this->assertSame(1, $this->methodFlag(self::OTHER_SELLERS_METHOD, 'is_active'));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_a_payout_method_of_another_seller_cannot_be_made_the_default_and_the_callers_own_default_is_kept(): void
    {
        $response = $this->sellerApi('POST', 'payment-information/default', ['id' => self::OTHER_SELLERS_SECOND_METHOD]);

        $this->assertSame(0, $this->methodFlag(self::OTHER_SELLERS_SECOND_METHOD, 'is_default'));
        $this->assertSame(1, $this->methodFlag(self::OWN_METHOD, 'is_default'));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_the_seller_can_still_update_their_own_payout_method(): void
    {
        $response = $this->sellerApi('POST', 'payment-information/update', [
            'id' => self::OWN_METHOD,
            'withdraw_method_id' => 1,
            'method_name' => 'Own bank renamed',
            'method_info' => ['iban' => 'SA-OWN-NEW-IBAN'],
            'is_active' => 1,
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->json('status'));
        $this->assertSame(['user_id' => 1, 'method_name' => 'Own bank renamed', 'iban' => 'SA-OWN-NEW-IBAN'], $this->methodOwnerNameAndIban(self::OWN_METHOD));
    }

    public function test_the_seller_can_still_switch_their_own_payout_method_off_and_on(): void
    {
        $this->assertSame(200, $this->sellerApi('POST', 'payment-information/status', ['id' => self::OWN_SECOND_METHOD, 'status' => 0])->getStatusCode());
        $this->assertSame(0, $this->methodFlag(self::OWN_SECOND_METHOD, 'is_active'));

        $this->assertSame(200, $this->sellerApi('POST', 'payment-information/status', ['id' => self::OWN_SECOND_METHOD, 'status' => 1])->getStatusCode());
        $this->assertSame(1, $this->methodFlag(self::OWN_SECOND_METHOD, 'is_active'));
    }

    public function test_the_seller_can_still_make_another_of_their_own_methods_the_default(): void
    {
        $response = $this->sellerApi('POST', 'payment-information/default', ['id' => self::OWN_SECOND_METHOD]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $this->methodFlag(self::OWN_SECOND_METHOD, 'is_default'));
        $this->assertSame(0, $this->methodFlag(self::OWN_METHOD, 'is_default'));
        $this->assertSame(1, $this->methodFlag(self::OTHER_SELLERS_METHOD, 'is_default'));
    }

    // ---- withdraw requests: closing ----

    public function test_closing_a_withdraw_request_of_another_seller_is_refused_and_credits_nobody(): void
    {
        $response = $this->sellerApi('DELETE', 'close-withdraw-request', ['id' => self::OTHER_SELLERS_PENDING_REQUEST]);

        $this->assertTrue(DB::table('withdraw_requests')->where('id', self::OTHER_SELLERS_PENDING_REQUEST)->exists());
        $this->assertSame([500.0, 100.0], $this->wallet(self::SELLER));
        $this->assertSame([0.0, 1000.0], $this->wallet(self::OTHER_SELLER));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_the_seller_can_still_close_their_own_pending_withdraw_request(): void
    {
        $response = $this->sellerApi('DELETE', 'close-withdraw-request', ['id' => self::OWN_PENDING_REQUEST]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse(DB::table('withdraw_requests')->where('id', self::OWN_PENDING_REQUEST)->exists());
        $this->assertSame([600.0, 0.0], $this->wallet(self::SELLER));
    }

    public function test_an_approved_withdraw_request_of_the_seller_still_cannot_be_closed(): void
    {
        $response = $this->sellerApi('DELETE', 'close-withdraw-request', ['id' => self::OWN_APPROVED_REQUEST]);

        $this->assertTrue(DB::table('withdraw_requests')->where('id', self::OWN_APPROVED_REQUEST)->exists());
        $this->assertSame([500.0, 100.0], $this->wallet(self::SELLER));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_a_delivery_mans_withdraw_request_is_not_the_sellers_to_close_and_does_not_credit_the_sellers_wallet(): void
    {
        $response = $this->sellerApi('DELETE', 'close-withdraw-request', ['id' => self::OWN_DELIVERY_MAN_REQUEST]);

        $this->assertTrue(DB::table('withdraw_requests')->where('id', self::OWN_DELIVERY_MAN_REQUEST)->exists());
        $this->assertSame([500.0, 100.0], $this->wallet(self::SELLER));
        $this->assertSame(403, $response->getStatusCode());
    }

    // ---- withdraw requests: closing, API v2 ----

    public function test_v2_closing_a_withdraw_request_of_another_seller_is_refused_and_credits_nobody(): void
    {
        $response = $this->sellerApiV2('DELETE', 'close-withdraw-request', ['id' => self::OTHER_SELLERS_PENDING_REQUEST, 'amount' => 0]);

        $this->assertTrue(DB::table('withdraw_requests')->where('id', self::OTHER_SELLERS_PENDING_REQUEST)->exists());
        $this->assertSame([500.0, 100.0], $this->wallet(self::SELLER));
        $this->assertSame([0.0, 1000.0], $this->wallet(self::OTHER_SELLER));
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_v2_the_seller_can_still_close_their_own_pending_withdraw_request(): void
    {
        $response = $this->sellerApiV2('DELETE', 'close-withdraw-request', ['id' => self::OWN_PENDING_REQUEST, 'amount' => 100]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse(DB::table('withdraw_requests')->where('id', self::OWN_PENDING_REQUEST)->exists());
        $this->assertSame([600.0, 0.0], $this->wallet(self::SELLER));
        $this->assertTrue(DB::table('withdraw_requests')->where('id', self::OTHER_SELLERS_PENDING_REQUEST)->exists());
    }

    public function test_v2_closing_a_withdraw_request_gives_back_the_stored_amount_whatever_amount_the_app_sends(): void
    {
        $this->sellerApiV2('DELETE', 'close-withdraw-request', ['id' => self::OWN_PENDING_REQUEST, 'amount' => 5]);

        $this->assertSame([600.0, 0.0], $this->wallet(self::SELLER));
    }

    public function test_v2_an_amount_larger_than_the_pending_withdraw_does_not_push_it_below_zero(): void
    {
        $this->sellerApiV2('DELETE', 'close-withdraw-request', ['id' => self::OWN_PENDING_REQUEST, 'amount' => 100000]);

        $this->assertSame([600.0, 0.0], $this->wallet(self::SELLER));
    }

    public function test_v2_an_approved_withdraw_request_of_the_seller_still_cannot_be_closed(): void
    {
        $response = $this->sellerApiV2('DELETE', 'close-withdraw-request', ['id' => self::OWN_APPROVED_REQUEST, 'amount' => 80]);

        $this->assertTrue(DB::table('withdraw_requests')->where('id', self::OWN_APPROVED_REQUEST)->exists());
        $this->assertSame([500.0, 100.0], $this->wallet(self::SELLER));
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_v2_a_delivery_mans_withdraw_request_is_not_the_sellers_to_close_and_does_not_credit_the_sellers_wallet(): void
    {
        $response = $this->sellerApiV2('DELETE', 'close-withdraw-request', ['id' => self::OWN_DELIVERY_MAN_REQUEST, 'amount' => 30]);

        $this->assertTrue(DB::table('withdraw_requests')->where('id', self::OWN_DELIVERY_MAN_REQUEST)->exists());
        $this->assertSame([500.0, 100.0], $this->wallet(self::SELLER));
        $this->assertSame(400, $response->getStatusCode());
    }

    // ---- withdraw requests: editing ----

    public function test_a_pending_withdraw_request_of_another_seller_cannot_be_taken_over(): void
    {
        $response = $this->sellerApi('POST', 'balance-withdraw-update', ['withdraw_request_id' => self::OTHER_SELLERS_PENDING_REQUEST, 'withdraw_method_id' => 1, 'amount' => 1000]);

        $this->assertSame([2, 1000.0, 0], $this->requestOwnerAmountAndStatus(self::OTHER_SELLERS_PENDING_REQUEST));
        $this->assertSame([500.0, 100.0], $this->wallet(self::SELLER));
        $this->assertSame([0.0, 1000.0], $this->wallet(self::OTHER_SELLER));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_an_approved_withdraw_request_of_another_seller_cannot_be_reopened(): void
    {
        $response = $this->sellerApi('POST', 'balance-withdraw-update', ['withdraw_request_id' => self::OTHER_SELLERS_APPROVED_REQUEST, 'withdraw_method_id' => 1, 'amount' => 700]);

        $this->assertSame([2, 700.0, 1], $this->requestOwnerAmountAndStatus(self::OTHER_SELLERS_APPROVED_REQUEST));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_an_approved_withdraw_request_of_the_seller_cannot_be_reopened_to_mint_the_amount_back_into_the_wallet(): void
    {
        $response = $this->sellerApi('POST', 'balance-withdraw-update', ['withdraw_request_id' => self::OWN_APPROVED_REQUEST, 'withdraw_method_id' => 1, 'amount' => 1]);

        $this->assertSame([1, 80.0, 1], $this->requestOwnerAmountAndStatus(self::OWN_APPROVED_REQUEST));
        $this->assertSame([500.0, 100.0], $this->wallet(self::SELLER));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_a_delivery_mans_withdraw_request_cannot_be_rewritten_through_the_sellers_own_payout_form(): void
    {
        $response = $this->sellerApi('POST', 'balance-withdraw-update', ['withdraw_request_id' => self::OWN_DELIVERY_MAN_REQUEST, 'withdraw_method_id' => 1, 'amount' => 10]);

        $this->assertSame(9, (int) DB::table('withdraw_requests')->where('id', self::OWN_DELIVERY_MAN_REQUEST)->value('delivery_man_id'));
        $this->assertSame([500.0, 100.0], $this->wallet(self::SELLER));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_the_seller_can_still_edit_their_own_pending_withdraw_request(): void
    {
        $response = $this->sellerApi('POST', 'balance-withdraw-update', ['withdraw_request_id' => self::OWN_PENDING_REQUEST, 'withdraw_method_id' => 1, 'amount' => 150]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([1, 150.0, 0], $this->requestOwnerAmountAndStatus(self::OWN_PENDING_REQUEST));
        $this->assertSame([450.0, 150.0], $this->wallet(self::SELLER));
    }

    // ---- helpers ----

    /** @return array{user_id: int, method_name: string, iban: string} */
    private function methodOwnerNameAndIban(int $methodId): array
    {
        $method = DB::table('vendor_withdraw_method_infos')->where('id', $methodId)->first();

        return ['user_id' => (int) $method->user_id, 'method_name' => $method->method_name, 'iban' => json_decode($method->method_info, true)['iban']];
    }

    private function methodFlag(int $methodId, string $flag): int
    {
        return (int) DB::table('vendor_withdraw_method_infos')->where('id', $methodId)->value($flag);
    }

    /** @return array{0: int, 1: float, 2: int} */
    private function requestOwnerAmountAndStatus(int $requestId): array
    {
        $request = DB::table('withdraw_requests')->where('id', $requestId)->first();

        return [(int) $request->seller_id, (float) $request->amount, (int) $request->approved];
    }

    /** @return array{0: float, 1: float} total earning and pending withdraw */
    private function wallet(int $sellerId): array
    {
        $wallet = DB::table('seller_wallets')->where('seller_id', $sellerId)->first();

        return [(float) $wallet->total_earning, (float) $wallet->pending_withdraw];
    }

    private function createPayoutTables(): void
    {
        $this->createSellerApiTable('withdrawal_methods', function (Blueprint $table) {
            $table->string('method_name')->nullable();
            $table->text('method_fields')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('vendor_status')->default(true);
            $table->timestamps();
        });
        $this->createSellerApiTable('vendor_withdraw_method_infos', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('withdraw_method_id');
            $table->string('method_name');
            $table->text('method_info')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        $this->createSellerApiTable('seller_wallets', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id');
            $table->decimal('total_earning', 16, 2)->default(0);
            $table->decimal('pending_withdraw', 16, 2)->default(0);
            $table->decimal('withdrawn', 16, 2)->default(0);
            $table->timestamps();
        });
        $this->createSellerApiTable('withdraw_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->tinyInteger('approved')->default(0);
            $table->text('transaction_note')->nullable();
            $table->unsignedBigInteger('withdrawal_method_id')->nullable();
            $table->text('withdrawal_method_fields')->nullable();
            $table->timestamps();
        });
    }

    private function seedPayoutMethods(): void
    {
        DB::table('withdrawal_methods')->insert(['id' => 1, 'method_name' => 'Bank', 'method_fields' => json_encode([['input_name' => 'iban', 'is_required' => 1]])]);

        $method = fn (int $id, int $userId, string $name, string $iban, bool $default) => DB::table('vendor_withdraw_method_infos')->insert([
            'id' => $id, 'user_id' => $userId, 'withdraw_method_id' => 1, 'method_name' => $name,
            'method_info' => json_encode(['iban' => $iban]), 'is_active' => 1, 'is_default' => $default,
        ]);
        $method(self::OWN_METHOD, self::SELLER, 'Own bank', 'SA-OWN-IBAN', true);
        $method(self::OWN_SECOND_METHOD, self::SELLER, 'Own second bank', 'SA-OWN-SECOND-IBAN', false);
        $method(self::OTHER_SELLERS_METHOD, self::OTHER_SELLER, 'Other seller bank', 'SA-OTHER-SELLER-IBAN', true);
        $method(self::OTHER_SELLERS_SECOND_METHOD, self::OTHER_SELLER, 'Other seller second bank', 'SA-OTHER-SELLER-SECOND-IBAN', false);
    }

    private function seedWithdrawRequests(): void
    {
        DB::table('seller_wallets')->insert([
            ['seller_id' => self::SELLER, 'total_earning' => 500, 'pending_withdraw' => 100],
            ['seller_id' => self::OTHER_SELLER, 'total_earning' => 0, 'pending_withdraw' => 1000],
        ]);
        $request = fn (int $id, int $sellerId, float $amount, int $approved, ?int $deliveryManId = null) => DB::table('withdraw_requests')->insert([
            'id' => $id, 'seller_id' => $sellerId, 'delivery_man_id' => $deliveryManId, 'amount' => $amount, 'approved' => $approved,
        ]);
        $request(self::OTHER_SELLERS_PENDING_REQUEST, self::OTHER_SELLER, 1000, 0);
        $request(self::OWN_PENDING_REQUEST, self::SELLER, 100, 0);
        $request(self::OTHER_SELLERS_APPROVED_REQUEST, self::OTHER_SELLER, 700, 1);
        $request(self::OWN_APPROVED_REQUEST, self::SELLER, 80, 1);
        $request(self::OWN_DELIVERY_MAN_REQUEST, self::SELLER, 30, 0, deliveryManId: 9);
    }
}
