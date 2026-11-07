<?php

namespace Tests\Feature\Address;

use App\Services\Address\AddressSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/**
 * The three places the website completes an order (cash on delivery, offline payment, wallet) judge the addresses of the session
 * before anything is written: an address that is another customer's, one a guest of another session saved, or an old Saudi address
 * while the structured address is on, sends the shopper back to the address step with the reason, and no order, stock movement or
 * wallet movement happens.
 */
class StorefrontOrderCompletionTest extends StorefrontTestCase
{
    private const WALLET_BALANCE = 500;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createCheckoutPageTables();
        Schema::create('offline_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('method_name')->nullable();
            $table->text('method_fields')->nullable();
            $table->text('method_informations')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::dropIfExists('taxables');
        Schema::dropIfExists('taxes');
        $this->artisan('migrate', ['--path' => 'Modules/TaxModule/database/migrations', '--force' => true])->run();
        Schema::create('referral_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('ref_by')->nullable();
            $table->decimal('ref_by_earning_amount', 16, 2)->default(0);
            $table->decimal('customer_discount_amount', 16, 2)->default(0);
            $table->string('customer_discount_amount_validity')->nullable();
            $table->string('customer_discount_validity_type')->nullable();
            $table->boolean('is_used')->default(false);
            $table->timestamps();
        });
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable();
            $table->boolean('status')->default(true);
            $table->date('start_date')->nullable();
            $table->date('expire_date')->nullable();
            $table->timestamps();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->string('coupon_code')->nullable();
            $table->string('order_group_id')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('wallet_balance', 16, 2)->default(0);
        });
        $this->setBusinessSetting('guest_checkout', 1);
        $this->setAddressMode(AddressSchema::MODE_SOFT);
    }

    private function sessionOf(int $addressId): array
    {
        return ['address_id' => $addressId, 'billing_address_id' => $addressId];
    }

    private function completeCashOnDelivery(int $addressId): TestResponse
    {
        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->withSession($this->sessionOf($addressId))
            ->post(route('checkout-complete'), ['payment_method' => 'cash_on_delivery']);
    }

    private function assertNothingWasPlaced(int $customerId): void
    {
        $this->assertSame(0, DB::table('orders')->count(), 'no order');
        $this->assertSame(1, DB::table('carts')->where('customer_id', $customerId)->where('is_checked', 1)->count(), 'the cart is as it was');
        $this->assertEquals(self::WALLET_BALANCE, DB::table('users')->where('id', $customerId)->value('wallet_balance'), 'the wallet is as it was');
    }

    private function aShopperWithAFullWallet(): int
    {
        $customer = $this->signIn($this->makeCustomer(['wallet_balance' => self::WALLET_BALANCE]));
        $this->putOneProductInTheCart($customer->id);

        return $customer->id;
    }

    public function test_cash_on_delivery_another_customers_address_sends_the_shopper_back_to_the_address_step_and_places_nothing(): void
    {
        $shopperId = $this->aShopperWithAFullWallet();
        $foreign = $this->makeStructuredAddress($this->ids, $this->makeCustomer()->id);

        $this->completeCashOnDelivery(addressId: $foreign->id)
            ->assertOk()
            ->assertExactJson([
                'status' => 0,
                'message' => 'The selected address was not found. Choose one of your saved addresses.',
                'redirect' => route('checkout-details'),
            ]);

        $this->assertNothingWasPlaced($shopperId);
    }

    public function test_cash_on_delivery_an_old_saudi_address_has_to_be_completed_first(): void
    {
        $shopperId = $this->aShopperWithAFullWallet();
        $old = $this->makeLegacyAddress($shopperId);

        $response = $this->completeCashOnDelivery(addressId: $old->id)->assertOk();

        $this->assertSame(0, $response->json('status'));
        $this->assertSame(route('checkout-details'), $response->json('redirect'));
        $this->assertStringContainsString('region', mb_strtolower($response->json('message')));
        $this->assertNothingWasPlaced($shopperId);
    }

    public function test_cash_on_delivery_without_ajax_goes_back_to_the_address_step_with_the_reason(): void
    {
        $shopperId = $this->aShopperWithAFullWallet();
        $foreign = $this->makeStructuredAddress($this->ids, $this->makeCustomer()->id);

        $this->withSession($this->sessionOf($foreign->id))
            ->post(route('checkout-complete'), ['payment_method' => 'cash_on_delivery'])
            ->assertRedirect(route('checkout-details'));

        $this->assertNothingWasPlaced($shopperId);
    }

    public function test_cash_on_delivery_a_guest_cannot_order_to_the_address_of_another_guest(): void
    {
        $this->withSession(['guest_id' => 77]);
        $this->putOneProductInTheCart(77, isGuest: true);
        $othersAddress = $this->makeStructuredAddress($this->ids, 78, isGuest: true);

        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->withSession(['guest_id' => 77] + $this->sessionOf($othersAddress->id))
            ->post(route('checkout-complete'), ['payment_method' => 'cash_on_delivery'])
            ->assertOk()
            ->assertJson(['status' => 0, 'redirect' => route('checkout-details')]);

        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('users')->count(), 'refused before an account is made for the guest');
    }

    public function test_offline_payment_another_customers_address_sends_the_shopper_back_to_the_address_step_and_places_nothing(): void
    {
        $shopperId = $this->aShopperWithAFullWallet();
        $foreign = $this->makeStructuredAddress($this->ids, $this->makeCustomer()->id);

        $this->withSession($this->sessionOf($foreign->id))
            ->post(route('offline-payment-checkout-complete'), ['payment_method' => 'offline_payment'])
            ->assertRedirect(route('checkout-details'));

        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(1, DB::table('carts')->where('customer_id', $shopperId)->count());
    }

    public function test_wallet_an_old_saudi_address_has_to_be_completed_first_and_the_wallet_is_not_touched(): void
    {
        $shopperId = $this->aShopperWithAFullWallet();
        $old = $this->makeLegacyAddress($shopperId);

        $this->withSession($this->sessionOf($old->id))
            ->post(route('checkout-complete-wallet'))
            ->assertRedirect(route('checkout-details'));

        $this->assertNothingWasPlaced($shopperId);
    }
}
