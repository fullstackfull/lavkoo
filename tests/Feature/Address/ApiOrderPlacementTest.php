<?php

namespace Tests\Feature\Address;

use App\Enums\Address\AddressChannel;
use App\Models\AddressCity;
use App\Models\ShippingAddress;
use App\Models\User;
use App\Services\Address\AddressSchema;
use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The mobile app places orders with a saved address by its id. Every id is the customer's own, or the order is refused before an
 * account is created, a wallet charged or a payment started; the order keeps a copy of the address as it was that day.
 */
class ApiOrderPlacementTest extends ApiAddressTestCase
{
    private const COD = '/api/v1/customer/order/place';

    private const OFFLINE = '/api/v1/customer/order/place-by-offline-payment';

    private const WALLET = '/api/v1/customer/order/place-by-wallet';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
    }

    private function stock(): int
    {
        return (int) DB::table('products')->where('id', 1)->value('current_stock');
    }

    private function ordersCount(): int
    {
        return DB::table('orders')->count();
    }

    /** @return array<string, mixed> */
    private function storedCopy(int $orderId, string $column = 'shipping_address_data'): array
    {
        return json_decode((string) DB::table('orders')->where('id', $orderId)->value($column), true);
    }

    private function assertRefusedBeforeAnythingWasCreated($response, string $expectedMessage): void
    {
        $response->assertStatus(403)->assertJsonPath('message', $expectedMessage);
        $this->assertSame($expectedMessage, $response->json('errors.0.message'));
        $this->assertSame('address', $response->json('errors.0.code'));
        $this->assertSame(0, $this->ordersCount());
        $this->assertSame(10, $this->stock(), 'no stock was taken');
    }

    public function test_a_structured_address_is_copied_into_the_order_with_the_names_of_that_day_and_the_copy_never_moves(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $address = $this->makeStructuredAddress($this->ids, $customer->id, overrides: ['zip' => '12211']);
        $this->putOneProductInTheCart($customer->id);

        $response = $this->asCustomer($customer)->getJson(self::COD . '?address_id=' . $address->id . '&billing_address_id=' . $address->id);

        $response->assertOk()->assertJsonPath('order_ids.0', 100001)->assertJsonPath('new_user', false);
        $this->assertSame(9, $this->stock());
        $order = DB::table('orders')->where('id', 100001)->first();
        $this->assertSame([$address->id, $address->id], [(int) $order->shipping_address, (int) $order->billing_address]);

        $copy = $this->storedCopy(100001);
        $this->assertSame($this->storedCopy(100001, 'billing_address_data'), $copy);
        $this->assertSame(['unified', 'الرياض', 'Riyadh', 'منطقة الرياض', 'RAGI2929', 'Al Olaya', '12211'], [
            $copy['address_schema'], $copy['city_name_ar'], $copy['city_name_en'], $copy['region_name_ar'], $copy['national_address'], $copy['district'], $copy['zip'],
        ]);

        $address->update(['address' => 'Another street', 'zip' => '99999']);
        AddressCity::whereKey($this->ids['riyadh'])->update(['name_ar' => 'اسم جديد', 'name_en' => 'New name']);
        $address->delete();

        $this->assertSame($copy, $this->storedCopy(100001));
    }

    /** @return array<string, array{0: string, 1: bool}> the mode, and whether the address is a structured one */
    public static function modesAndAddresses(): array
    {
        return [
            'off, an old address' => [AddressSchema::MODE_OFF, false],
            'soft, a structured address' => [AddressSchema::MODE_SOFT, true],
            'strict, a structured address' => [AddressSchema::MODE_STRICT, true],
        ];
    }

    #[DataProvider('modesAndAddresses')]
    public function test_the_money_of_an_order_is_what_the_cart_says_whatever_the_address_and_the_mode(string $mode, bool $structuredAddress): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('discount_type')->nullable();
            $table->decimal('discount', 16, 2)->default(0);
            $table->decimal('min_purchase', 16, 2)->default(0);
            $table->decimal('max_discount', 16, 2)->default(0);
            $table->integer('limit')->default(0);
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('customer_id')->default(0);
            $table->string('coupon_bearer')->nullable();
        });
        Schema::create('order_details_rewards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_details_id');
            $table->string('reward_type')->nullable();
            $table->json('reward_details')->nullable();
            $table->decimal('reward_amount', 18, 12)->nullable()->default(0);
            $table->tinyInteger('reward_delivered')->default(0);
            $table->timestamps();
        });
        $this->setAddressMode($mode);
        $customer = $this->makeCustomer();
        $address = $structuredAddress ? $this->makeStructuredAddress($this->ids, $customer->id) : $this->makeLegacyAddress($customer->id);
        $this->putOneProductInTheCart($customer->id);
        DB::table('carts')->where('customer_id', $customer->id)->update(['quantity' => 2, 'price' => 100]);
        DB::table('business_settings')->insert(['type' => 'shipping_method', 'value' => 'inhouse_shipping']);
        DB::table('shipping_types')->insert(['seller_id' => 0, 'shipping_type' => 'order_wise']);
        DB::table('cart_shippings')->insert(['cart_group_id' => 'group-' . $customer->id . 'c', 'shipping_method_id' => 1, 'shipping_cost' => 25]);
        Cache::flush();
        $this->useTaxIncludedPrices();
        DB::table('coupons')->insert([
            'code' => 'SAVE15', 'coupon_type' => 'discount_on_purchase', 'discount_type' => 'amount', 'discount' => 15, 'min_purchase' => 0, 'max_discount' => 0,
            'limit' => 5, 'seller_id' => 0, 'customer_id' => 0, 'coupon_bearer' => 'inhouse', 'status' => 1, 'start_date' => '2020-01-01', 'expire_date' => '2099-12-31',
        ]);

        $this->asCustomer($customer)->getJson(self::COD . '?address_id=' . $address->id . '&coupon_code=SAVE15')->assertOk();

        $order = DB::table('orders')->where('id', 100001)->first();
        $this->assertEquals(
            ['order_amount' => 210, 'init_order_amount' => 210, 'shipping_cost' => 25, 'discount_amount' => 15, 'total_tax_amount' => 0],
            ['order_amount' => $order->order_amount, 'init_order_amount' => $order->init_order_amount, 'shipping_cost' => $order->shipping_cost, 'discount_amount' => $order->discount_amount, 'total_tax_amount' => $order->total_tax_amount],
            'two packs at 100, the 25 of shipping, the coupon of 15',
        );
        $this->assertSame(8, $this->stock(), 'two packs left the shelf');
    }

    public function test_while_the_mode_is_off_the_order_keeps_the_copy_the_checkout_has_always_kept(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id, overrides: ['zip' => '']);
        $this->putOneProductInTheCart($customer->id);
        $asTheCheckoutLoadsIt = (string) ShippingAddress::find($address->id);

        $this->asCustomer($customer)->getJson(self::COD . '?address_id=' . $address->id)->assertOk();

        $order = DB::table('orders')->where('id', 100001)->first();
        $this->assertSame($asTheCheckoutLoadsIt, $order->shipping_address_data);
        $this->assertSame($asTheCheckoutLoadsIt, $order->billing_address_data, 'billing falls back to the delivery address as before');
        $this->assertSame('', $this->storedCopy(100001)['zip']);
        $this->assertArrayNotHasKey('city_name_ar', $this->storedCopy(100001));
    }

    public function test_in_soft_mode_the_app_may_still_order_with_an_old_address_and_the_copy_says_it_is_old(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id, overrides: ['zip' => '12211']);
        $this->putOneProductInTheCart($customer->id);

        $this->asCustomer($customer)->getJson(self::COD . '?address_id=' . $address->id)->assertOk();

        $copy = $this->storedCopy(100001);
        $this->assertSame(['legacy', null, '12211', 'الرياض'], [$copy['address_schema'], $copy['city_id'], $copy['zip'], $copy['city']]);
    }

    public function test_an_order_can_still_be_placed_without_any_address(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $customer = $this->makeCustomer();
        $this->putOneProductInTheCart($customer->id);

        $this->asCustomer($customer)->getJson(self::COD)->assertOk();

        $order = DB::table('orders')->where('id', 100001)->first();
        $this->assertNull($order->shipping_address_data);
        $this->assertSame(0, (int) $order->shipping_address);
    }

    public function test_a_customer_cannot_order_with_the_address_of_another_customer_as_delivery_or_billing_address(): void
    {
        $customer = $this->makeCustomer();
        $stranger = $this->makeCustomer();
        $mine = $this->makeLegacyAddress($customer->id);
        $theirs = $this->makeLegacyAddress($stranger->id, overrides: ['address' => 'A stranger street', 'phone' => '0501111111']);
        $this->putOneProductInTheCart($customer->id);

        foreach ([['address_id' => $theirs->id], ['address_id' => $mine->id, 'billing_address_id' => $theirs->id], ['address_id' => 99999], ['address_id' => 'abc']] as $query) {
            $this->assertRefusedBeforeAnythingWasCreated($this->asCustomer($customer)->getJson(self::COD . '?' . http_build_query($query)), translate('address_not_found'));
        }
    }

    public function test_a_guest_cannot_order_with_the_address_of_another_guest_or_of_a_customer_with_the_same_number(): void
    {
        $this->putOneProductInTheCart(callerId: 7001, isGuest: true);
        $otherGuest = $this->makeLegacyAddress(7002, isGuest: true);
        $customerWithTheSameNumber = $this->makeLegacyAddress(7001);

        foreach ([$otherGuest->id, $customerWithTheSameNumber->id] as $addressId) {
            $this->assertRefusedBeforeAnythingWasCreated($this->getJson(self::COD . '?guest_id=7001&address_id=' . $addressId), translate('address_not_found'));
        }
    }

    public function test_a_guest_who_asks_for_an_account_with_an_address_that_is_not_theirs_gets_no_account(): void
    {
        $this->putOneProductInTheCart(callerId: 7001, isGuest: true);
        $theirs = $this->makeLegacyAddress(7002, isGuest: true, overrides: ['email' => 'victim@example.test', 'phone' => '0502222222']);

        $response = $this->getJson(self::COD . '?guest_id=7001&is_check_create_account=1&password=secret12&address_id=' . $theirs->id);

        $this->assertRefusedBeforeAnythingWasCreated($response, translate('address_not_found'));
        $this->assertSame(0, User::count(), 'no account was created from a stranger\'s details');
    }

    public function test_a_guest_who_registers_while_ordering_orders_with_the_address_made_as_a_guest(): void
    {
        $this->putOneProductInTheCart(callerId: 7001, isGuest: true);
        $address = $this->makeLegacyAddress(7001, isGuest: true, overrides: ['email' => 'new.customer@example.test', 'phone' => '0503333333']);

        $response = $this->getJson(self::COD . '?guest_id=7001&is_check_create_account=1&password=secret12&address_id=' . $address->id);

        $response->assertOk()->assertJsonPath('new_user', true);
        $newUser = User::where('email', 'new.customer@example.test')->firstOrFail();
        $order = DB::table('orders')->where('id', 100001)->first();
        $this->assertSame([$newUser->id, 0], [(int) $order->customer_id, (int) $order->is_guest]);
        $this->assertSame('King Fahd Rd 12', $this->storedCopy(100001)['address']);
    }

    public function test_a_customer_who_signed_in_after_adding_an_address_as_a_guest_can_use_it_when_the_request_names_that_guest(): void
    {
        $customer = $this->makeCustomer();
        $guestAddress = $this->makeLegacyAddress(7001, isGuest: true);
        $this->putOneProductInTheCart($customer->id);

        $this->assertRefusedBeforeAnythingWasCreated($this->asCustomer($customer)->getJson(self::COD . '?address_id=' . $guestAddress->id), translate('address_not_found'));
        $this->assertRefusedBeforeAnythingWasCreated($this->asCustomer($customer)->getJson(self::COD . '?guest_id=7002&address_id=' . $guestAddress->id), translate('address_not_found'));

        $this->asCustomer($customer)->getJson(self::COD . '?guest_id=7001&address_id=' . $guestAddress->id)->assertOk();
        $this->assertSame(9, $this->stock());
    }

    public function test_strict_mode_stops_an_order_with_an_old_saudi_address_and_says_what_to_do(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $customer = $this->makeCustomer();
        $old = $this->makeLegacyAddress($customer->id);
        $this->putOneProductInTheCart($customer->id);

        $this->assertRefusedBeforeAnythingWasCreated($this->asCustomer($customer)->getJson(self::COD . '?address_id=' . $old->id), translate('address_complete_required'));
    }

    public function test_an_address_whose_city_was_switched_off_stops_a_strict_order(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $customer = $this->makeCustomer();
        $address = $this->makeStructuredAddress($this->ids, $customer->id);
        $this->putOneProductInTheCart($customer->id);
        AddressCity::whereKey($this->ids['riyadh'])->update(['is_active' => false]);
        AddressSchema::flush();

        $this->assertRefusedBeforeAnythingWasCreated($this->asCustomer($customer)->getJson(self::COD . '?address_id=' . $address->id), translate('address_city_invalid'));
    }

    public function test_the_offline_payment_order_is_checked_the_same_way(): void
    {
        $customer = $this->makeCustomer();
        $mine = $this->makeLegacyAddress($customer->id);
        $theirs = $this->makeLegacyAddress($this->makeCustomer()->id);
        $this->putOneProductInTheCart($customer->id);

        $this->assertRefusedBeforeAnythingWasCreated($this->asCustomer($customer)->postJson(self::OFFLINE, ['address_id' => $theirs->id]), translate('address_not_found'));
        $this->assertRefusedBeforeAnythingWasCreated($this->asCustomer($customer)->postJson(self::OFFLINE, ['address_id' => $mine->id, 'billing_address_id' => $theirs->id]), translate('address_not_found'));

        $this->asCustomer($customer)->postJson(self::OFFLINE, ['address_id' => $mine->id])->assertOk()->assertJsonPath('order_ids.0', 100001);
        $this->assertSame($mine->id, (int) $this->storedCopy(100001)['id']);
    }

    public function test_the_wallet_order_is_checked_before_a_single_riyal_is_taken(): void
    {
        $customer = $this->makeCustomer(['wallet_balance' => 500]);
        $theirs = $this->makeLegacyAddress($this->makeCustomer()->id);
        $this->putOneProductInTheCart($customer->id);

        $this->assertRefusedBeforeAnythingWasCreated($this->asCustomer($customer)->getJson(self::WALLET . '?address_id=' . $theirs->id), translate('address_not_found'));
        $this->assertEquals(500, $customer->fresh()->wallet_balance);
    }

    public function test_the_old_error_shape_of_these_endpoints_is_kept(): void
    {
        $customer = $this->makeCustomer();
        $theirs = $this->makeLegacyAddress($this->makeCustomer()->id);
        $this->putOneProductInTheCart($customer->id);

        $json = $this->asCustomer($customer)->getJson(self::COD . '?address_id=' . $theirs->id)->assertStatus(403)->json();

        $this->assertArrayHasKey('message', $json);
        $this->assertIsString($json['message']);
    }

    /** The order writer called the way every controller calls it, for a signed-in customer. */
    private function generateOrderFor(User $customer, array $data): array
    {
        request()->merge(['user' => $customer]);

        return OrderManager::generateOrder(data: $data + [
            'is_guest' => 0, 'customer_id' => $customer->id, 'order_status' => 'pending', 'payment_method' => 'cash_on_delivery',
            'payment_status' => 'unpaid', 'transaction_ref' => '', 'requestObj' => request(),
        ]);
    }

    public function test_the_order_writer_itself_refuses_an_address_that_is_not_the_customers_even_when_a_caller_did_not_check(): void
    {
        $customer = $this->makeCustomer();
        $theirs = $this->makeLegacyAddress($this->makeCustomer()->id);
        $this->putOneProductInTheCart($customer->id);

        try {
            $this->generateOrderFor($customer, ['address_id' => $theirs->id, 'address_channel' => AddressChannel::Api]);
            $this->fail('The order writer should have refused the address.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('address', $exception->errors());
        }

        $this->assertSame(0, $this->ordersCount());
        $this->assertSame(10, $this->stock());
    }

    public function test_the_order_writer_treats_a_caller_that_names_no_channel_as_the_website(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $old = $this->makeLegacyAddress($customer->id);
        $this->putOneProductInTheCart($customer->id);

        try {
            $this->generateOrderFor($customer, ['address_id' => $old->id]);
            $this->fail('The website must complete an old Saudi address first.');
        } catch (ValidationException) {
            $this->assertSame(0, $this->ordersCount());
        }

        $unsavedStructured = $this->makeStructuredAddress($this->ids, 0, overrides: ['address' => 'Typed at checkout']);
        $orderIds = $this->generateOrderFor($customer, ['address_id' => $unsavedStructured->id]);

        $this->assertCount(1, $orderIds);
        $this->assertSame('Typed at checkout', $this->storedCopy($orderIds[0])['address']);
    }

    public function test_an_order_that_is_already_paid_keeps_the_customers_address_for_review_but_never_a_strangers(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $customer = $this->makeCustomer();
        $old = $this->makeLegacyAddress($customer->id, overrides: ['zip' => '12211']);
        $theirs = $this->makeLegacyAddress($this->makeCustomer()->id, overrides: ['address' => 'A stranger street']);
        $this->putOneProductInTheCart($customer->id);

        $oldOrder = $this->generateOrderFor($customer, ['address_id' => $old->id, 'address_channel' => AddressChannel::Api, 'enforce_address_rules' => false, 'payment_status' => 'paid', 'order_status' => 'confirmed']);
        $this->assertCount(1, $oldOrder, 'the money is taken: an old Saudi address does not stop the order');
        $this->assertSame(['legacy', '12211'], [$this->storedCopy($oldOrder[0])['address_schema'], $this->storedCopy($oldOrder[0])['zip']]);

        $this->putOneProductInTheCart($customer->id);
        $strangerOrder = $this->generateOrderFor($customer, ['address_id' => $theirs->id, 'billing_address_id' => $theirs->id, 'address_channel' => AddressChannel::Api, 'enforce_address_rules' => false, 'payment_status' => 'paid', 'order_status' => 'confirmed']);

        $row = DB::table('orders')->where('id', $strangerOrder[0])->first();
        $this->assertNull($row->shipping_address_data, 'nothing of the stranger lands in the order');
        $this->assertNull($row->billing_address_data);
        $this->assertSame([0, 0], [(int) $row->shipping_address, (int) $row->billing_address]);
    }
}
