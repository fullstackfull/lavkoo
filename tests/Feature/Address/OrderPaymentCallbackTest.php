<?php

namespace Tests\Feature\Address;

use App\Models\User;
use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\DB;

/**
 * A payment gateway reports back after the money has been taken. The order is created from the ids stored when the payment started:
 * it is never refused over its address, and it never keeps an address that is not the payer's.
 */
class OrderPaymentCallbackTest extends ApiAddressTestCase
{
    private ?string $debugLog = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();

        // The callback appends a line to a debug file under storage/logs; the run must not leave one behind.
        $path = storage_path('logs/order_generate_test.txt');
        $this->debugLog = is_file($path) ? (string) file_get_contents($path) : null;
    }

    protected function tearDown(): void
    {
        $path = storage_path('logs/order_generate_test.txt');
        $this->debugLog === null ? @unlink($path) : file_put_contents($path, $this->debugLog);

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $stored what the app or the website stored when the payment started
     * @return list<int|string> the ids of the orders the callback created
     */
    private function gatewayReportsBack(User $payer, array $stored): array
    {
        request()->replace([]);
        digital_payment_success([
            'is_paid' => 1, 'payment_method' => 'ssl_commerz', 'transaction_id' => 'TXN-1',
            'additional_data' => json_encode($stored + [
                'customer_id' => $payer->id, 'is_guest' => 0, 'is_guest_in_order' => 0, 'order_note' => null, 'coupon_code' => null,
                'payment_mode' => 'web', 'payment_request_from' => 'web', 'new_customer_id' => 0,
            ]),
        ]);

        return DB::table('orders')->orderBy('id')->pluck('id')->all();
    }

    public function test_a_paid_order_is_created_with_the_payers_structured_address_and_keeps_a_frozen_copy(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $payer = $this->makeCustomer();
        $address = $this->makeStructuredAddress($this->ids, $payer->id);
        $this->putOneProductInTheCart($payer->id);

        $orders = $this->gatewayReportsBack($payer, ['address_id' => $address->id, 'billing_address_id' => $address->id]);

        $this->assertCount(1, $orders);
        $row = DB::table('orders')->first();
        $copy = json_decode($row->shipping_address_data, true);
        $this->assertSame([$address->id, 'unified', 'Riyadh', 'RAGI2929'], [(int) $row->shipping_address, $copy['address_schema'], $copy['city_name_en'], $copy['national_address']]);
        $this->assertSame('paid', $row->payment_status);
    }

    public function test_a_paid_order_is_never_refused_over_an_old_saudi_address_even_in_strict_mode(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $payer = $this->makeCustomer();
        $old = $this->makeLegacyAddress($payer->id, overrides: ['zip' => '12211']);
        $this->putOneProductInTheCart($payer->id);

        $orders = $this->gatewayReportsBack($payer, ['address_id' => $old->id, 'billing_address_id' => $old->id]);

        $this->assertCount(1, $orders, 'the customer paid, so the order exists and waits for the address to be completed');
        $copy = json_decode((string) DB::table('orders')->value('shipping_address_data'), true);
        $this->assertSame(['legacy', '12211', 'الرياض'], [$copy['address_schema'], $copy['zip'], $copy['city']]);
    }

    public function test_a_paid_order_keeps_no_address_that_was_stored_for_someone_else(): void
    {
        $payer = $this->makeCustomer();
        $stranger = $this->makeLegacyAddress($this->makeCustomer()->id, overrides: ['address' => 'A stranger street', 'phone' => '0501111111']);
        $this->putOneProductInTheCart($payer->id);

        $orders = $this->gatewayReportsBack($payer, ['address_id' => $stranger->id, 'billing_address_id' => $stranger->id]);

        $this->assertCount(1, $orders, 'the payer still gets their order');
        $row = DB::table('orders')->first();
        $this->assertNull($row->shipping_address_data);
        $this->assertNull($row->billing_address_data);
        $this->assertSame(0, (int) $row->shipping_address);
        $this->assertStringNotContainsString('stranger', (string) json_encode($row));
    }

    public function test_an_address_typed_at_checkout_and_never_saved_is_kept_by_a_website_payment_but_not_by_an_app_payment(): void
    {
        $websitePayer = $this->makeCustomer();
        $appPayer = $this->makeCustomer();
        $unsaved = $this->makeLegacyAddress(0, overrides: ['address' => 'Typed at checkout']);
        $this->putOneProductInTheCart($websitePayer->id);
        $this->putOneProductInTheCart($appPayer->id);

        $this->gatewayReportsBack($websitePayer, ['address_id' => $unsaved->id]);
        $this->gatewayReportsBack($appPayer, ['address_id' => $unsaved->id, 'payment_mode' => 'app', 'payment_request_from' => 'app']);

        $copies = DB::table('orders')->orderBy('id')->pluck('shipping_address_data', 'customer_id')->all();
        $this->assertCount(2, $copies, 'both payers get their order');
        $this->assertSame('Typed at checkout', json_decode($copies[$websitePayer->id], true)['address']);
        $this->assertNull($copies[$appPayer->id], 'the app never saves an address by typing it at checkout, so such a stored id is not the payer\'s');
    }

    public function test_an_order_stored_before_the_upgrade_with_no_address_at_all_is_still_created(): void
    {
        $payer = $this->makeCustomer();
        $this->putOneProductInTheCart($payer->id);

        $this->assertCount(1, $this->gatewayReportsBack($payer, []));
        $this->assertNull(DB::table('orders')->value('shipping_address_data'));
    }
}
