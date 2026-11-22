<?php

namespace Tests\Feature\Security;

use App\Events\OrderStatusEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsPanelOrderEditTables;

/**
 * Three order handlers of the vendor panel took an order, a driver or an order line from the request without the ownership their seller API twins
 * were given. The order was read by its id alone, so a seller handed another shop's order to a driver (which pointed that driver's app at it), and the
 * in-house shop's orders, which carry seller_id 1 as well, to any driver; any driver id was accepted, another shop's and the in-house one; a refused
 * foreign order answered 403 when it was delivered and 200 when it was not, which told a seller whether another shop's order was delivered. The amount
 * and date update scoped on seller_id alone, so it edited the in-house shop's order, and it announced the change to the order's driver even when it had
 * refused it. The after-sale file upload scoped the order line on seller_id alone, so it replaced the file customers of the in-house shop download.
 *
 * An order is the seller's own when it carries the seller's id and seller_is 'seller'. A driver is assignable to it when he belongs to the pool the
 * order page offers: the shop's own drivers when the shop ships the order (sellerwise_shipping), the in-house drivers (seller_id 0) when the store does.
 */
class VendorOrderHandlerOwnershipTest extends VendorPanelTestCase
{
    use BuildsPanelOrderEditTables;

    private const OWN_ORDER = 100001;
    private const OTHER_SELLERS_ORDER = 100002;
    private const IN_HOUSE_ORDER = 100003;
    private const OTHER_SELLERS_DELIVERED_ORDER = 100004;
    private const OWN_ORDER_SHIPPED_IN_HOUSE = 100005;
    private const OWN_DELIVERED_ORDER = 100006;

    private const OWN_DRIVER = 5;
    private const OTHER_SELLERS_DRIVER = 6;
    private const IN_HOUSE_DRIVER = 7;

    private const OWN_LINE = 1;
    private const OTHER_SELLERS_LINE = 2;
    private const IN_HOUSE_LINE = 3;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([OrderStatusEvent::class]);

        Schema::table('orders', static function (Blueprint $table): void {
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->string('delivery_type')->nullable();
            $table->string('delivery_service_name')->nullable();
            $table->string('third_party_delivery_tracking_id')->nullable();
            $table->dateTime('deliveryman_assigned_at')->nullable();
            $table->decimal('deliveryman_charge', 16, 2)->default(0);
            $table->string('expected_delivery_date')->nullable();
            $table->string('shipping_responsibility')->nullable();
        });
        Schema::table('order_details', static function (Blueprint $table): void {
            $table->string('digital_file_after_sell')->nullable();
        });
        Schema::create('delivery_men', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->default(0);
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('country_code')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('image')->nullable();
            $table->string('fcm_token')->nullable();
            $table->boolean('is_active')->default(1);
            $table->string('app_language')->nullable();
            $table->timestamps();
        });
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('image')->nullable();
            $table->string('cm_firebase_token')->nullable();
            $table->timestamps();
        });
        Schema::create('order_expected_delivery_histories', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_type')->nullable();
            $table->string('expected_delivery_date')->nullable();
            $table->text('cause')->nullable();
            $table->timestamps();
        });

        DB::table('users')->insert(['id' => 7, 'f_name' => 'Cust', 'l_name' => 'Omer', 'email' => 'customer@example.test', 'phone' => '0500000000']);
        DB::table('currencies')->insert(['id' => 1, 'name' => 'US Dollar', 'symbol' => '$', 'code' => 'USD', 'exchange_rate' => 1]);
        DB::table('business_settings')->insert([['type' => 'system_default_currency', 'value' => '1'], ['type' => 'currency_model', 'value' => 'single_currency']]);
        DB::table('delivery_men')->insert([
            ['id' => self::OWN_DRIVER, 'seller_id' => self::SELLER, 'f_name' => 'Own', 'l_name' => 'Driver', 'phone' => '0500000005'],
            ['id' => self::OTHER_SELLERS_DRIVER, 'seller_id' => self::OTHER_SELLER, 'f_name' => 'Rival', 'l_name' => 'Driver', 'phone' => '0500000006'],
            ['id' => self::IN_HOUSE_DRIVER, 'seller_id' => 0, 'f_name' => 'Inhouse', 'l_name' => 'Driver', 'phone' => '0500000007'],
        ]);
        $orders = [
            [self::OWN_ORDER, 'seller', self::SELLER, 'confirmed', 'sellerwise_shipping', null],
            [self::OTHER_SELLERS_ORDER, 'seller', self::OTHER_SELLER, 'confirmed', 'sellerwise_shipping', self::OTHER_SELLERS_DRIVER],
            [self::IN_HOUSE_ORDER, 'admin', self::SELLER, 'confirmed', 'inhouse_shipping', self::IN_HOUSE_DRIVER],
            [self::OTHER_SELLERS_DELIVERED_ORDER, 'seller', self::OTHER_SELLER, 'delivered', 'sellerwise_shipping', null],
            [self::OWN_ORDER_SHIPPED_IN_HOUSE, 'seller', self::SELLER, 'confirmed', 'inhouse_shipping', null],
            [self::OWN_DELIVERED_ORDER, 'seller', self::SELLER, 'delivered', 'sellerwise_shipping', null],
        ];
        foreach ($orders as [$id, $sellerIs, $sellerId, $status, $shipping, $driverId]) {
            DB::table('orders')->insert([
                'id' => $id, 'customer_id' => 7, 'seller_is' => $sellerIs, 'seller_id' => $sellerId, 'order_status' => $status, 'shipping_responsibility' => $shipping,
                'delivery_man_id' => $driverId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('order_details')->insert([
            ['id' => self::OWN_LINE, 'order_id' => self::OWN_ORDER, 'seller_id' => self::SELLER, 'product_id' => self::OWN_PRODUCT, 'digital_file_after_sell' => 'original-own.pdf'],
            ['id' => self::OTHER_SELLERS_LINE, 'order_id' => self::OTHER_SELLERS_ORDER, 'seller_id' => self::OTHER_SELLER, 'product_id' => self::OTHER_SELLERS_PRODUCT, 'digital_file_after_sell' => 'original-rival.pdf'],
            ['id' => self::IN_HOUSE_LINE, 'order_id' => self::IN_HOUSE_ORDER, 'seller_id' => self::SELLER, 'product_id' => self::IN_HOUSE_PRODUCT, 'digital_file_after_sell' => 'original-inhouse.pdf'],
        ]);
        foreach (['own', 'rival', 'inhouse'] as $owner) {
            Storage::disk('public')->put("product/digital-product/original-{$owner}.pdf", strtoupper($owner));
        }
    }

    // ---- handing an order to a delivery man ----

    /** @return array<string, array{int, int}> an order that is not the seller's, with a driver of each kind */
    public static function ordersThatAreNotTheSellersAndDrivers(): array
    {
        return [
            'another seller order, the seller own driver' => [self::OTHER_SELLERS_ORDER, self::OWN_DRIVER],
            'another seller order, his own driver' => [self::OTHER_SELLERS_ORDER, self::OTHER_SELLERS_DRIVER],
            'another seller delivered order' => [self::OTHER_SELLERS_DELIVERED_ORDER, self::OWN_DRIVER],
            'the in-house order sharing the seller id, the seller own driver' => [self::IN_HOUSE_ORDER, self::OWN_DRIVER],
            'the in-house order sharing the seller id, an in-house driver' => [self::IN_HOUSE_ORDER, self::IN_HOUSE_DRIVER],
            'an order that does not exist' => [999999, self::OWN_DRIVER],
        ];
    }

    /** @return array<string, array{int, int}> the seller's own order with a driver outside the pool its page offers */
    public static function driversOutsideThePoolOfAnOwnOrder(): array
    {
        return [
            'a shop-shipped order, another seller driver' => [self::OWN_ORDER, self::OTHER_SELLERS_DRIVER],
            'a shop-shipped order, an in-house driver' => [self::OWN_ORDER, self::IN_HOUSE_DRIVER],
            'an in-house-shipped order, the seller own driver' => [self::OWN_ORDER_SHIPPED_IN_HOUSE, self::OWN_DRIVER],
            'an in-house-shipped order, another seller driver' => [self::OWN_ORDER_SHIPPED_IN_HOUSE, self::OTHER_SELLERS_DRIVER],
            'a driver that does not exist' => [self::OWN_ORDER, 999],
        ];
    }

    #[DataProvider('ordersThatAreNotTheSellersAndDrivers')]
    public function test_an_order_that_is_not_the_sellers_is_not_handed_to_a_driver_and_no_driver_is_told(int $orderId, int $driverId): void
    {
        $before = $this->orders();

        $this->signInAs(self::SELLER)->getJson("/vendor/orders/add-delivery-man/{$orderId}/{$driverId}")->assertNotFound()->assertJson(['status' => false]);

        $this->assertSame($before, $this->orders());
        Event::assertNotDispatched(OrderStatusEvent::class);
    }

    #[DataProvider('driversOutsideThePoolOfAnOwnOrder')]
    public function test_the_sellers_own_order_is_not_handed_to_a_driver_outside_the_pool_its_page_offers(int $orderId, int $driverId): void
    {
        $before = $this->orders();

        $this->signInAs(self::SELLER)->getJson("/vendor/orders/add-delivery-man/{$orderId}/{$driverId}")->assertNotFound()->assertJson(['status' => false]);

        $this->assertSame($before, $this->orders());
        Event::assertNotDispatched(OrderStatusEvent::class);
    }

    public function test_the_sellers_own_order_is_still_handed_to_one_of_the_shops_own_drivers_and_the_driver_is_told(): void
    {
        $this->signInAs(self::SELLER)->getJson('/vendor/orders/add-delivery-man/' . self::OWN_ORDER . '/' . self::OWN_DRIVER)->assertOk()->assertJson(['status' => true]);

        $order = DB::table('orders')->where('id', self::OWN_ORDER)->first();
        $this->assertSame([self::OWN_DRIVER, 'self_delivery'], [(int)$order->delivery_man_id, $order->delivery_type]);
        Event::assertDispatched(OrderStatusEvent::class, static fn (OrderStatusEvent $event): bool => $event->key === 'new_order_assigned_message' && $event->type === 'delivery_man');
    }

    public function test_an_own_order_the_store_ships_is_still_handed_to_an_in_house_driver(): void
    {
        $this->signInAs(self::SELLER)->getJson('/vendor/orders/add-delivery-man/' . self::OWN_ORDER_SHIPPED_IN_HOUSE . '/' . self::IN_HOUSE_DRIVER)->assertOk()->assertJson(['status' => true]);

        $this->assertSame(self::IN_HOUSE_DRIVER, (int)DB::table('orders')->where('id', self::OWN_ORDER_SHIPPED_IN_HOUSE)->value('delivery_man_id'));
    }

    public function test_a_delivered_own_order_still_refuses_a_driver(): void
    {
        $this->signInAs(self::SELLER)->getJson('/vendor/orders/add-delivery-man/' . self::OWN_DELIVERED_ORDER . '/' . self::OWN_DRIVER)->assertForbidden()->assertJson(['status' => false]);

        $this->assertNull(DB::table('orders')->where('id', self::OWN_DELIVERED_ORDER)->value('delivery_man_id'));
    }

    // ---- the drivers the order page offers ----

    /** @return array<string, array{int, string}> the order, and the drivers its page offers for it: the pool the assignment above accepts */
    public static function ordersAndTheDriversTheirPagesOffer(): array
    {
        return [
            'an order the shop ships' => [self::OWN_ORDER, 'Own'],
            'an order the store ships' => [self::OWN_ORDER_SHIPPED_IN_HOUSE, 'Inhouse'],
        ];
    }

    #[DataProvider('ordersAndTheDriversTheirPagesOffer')]
    public function test_the_order_page_offers_the_drivers_of_the_pool_the_assignment_accepts(int $orderId, string $drivers): void
    {
        $this->createTheTablesTheOrderPageReads();

        $response = $this->signInAs(self::SELLER)->get('/vendor/orders/details/' . $orderId);

        $response->assertOk();
        $this->assertMatchesRegularExpression('/^drivers:' . $drivers . '$/m', (string)$response->getContent());
    }

    // ---- the amount and the date of an order ----

    /** @return array<string, array{int, array<string, mixed>}> an order that is not the seller's and each of the two fields the page edits */
    public static function ordersThatAreNotTheSellersAndTheirFields(): array
    {
        $charge = ['field_name' => 'deliveryman_charge', 'field_val' => 99];
        $date = ['field_name' => 'expected_delivery_date', 'field_val' => '2030-01-01', 'cause' => 'late'];

        return [
            'the charge of another seller order' => [self::OTHER_SELLERS_ORDER, $charge],
            'the date of another seller order' => [self::OTHER_SELLERS_ORDER, $date],
            'the charge of another seller delivered order' => [self::OTHER_SELLERS_DELIVERED_ORDER, $charge],
            'the charge of the in-house order sharing the seller id' => [self::IN_HOUSE_ORDER, $charge],
            'the date of the in-house order sharing the seller id' => [self::IN_HOUSE_ORDER, $date],
        ];
    }

    /** @param array<string, mixed> $field */
    #[DataProvider('ordersThatAreNotTheSellersAndTheirFields')]
    public function test_an_order_that_is_not_the_sellers_keeps_its_amount_and_date_and_its_driver_is_not_told(int $orderId, array $field): void
    {
        $before = [$this->orders(), DB::table('order_expected_delivery_histories')->count()];

        $this->signInAs(self::SELLER)->postJson('/vendor/orders/amount-date-update', ['order_id' => $orderId] + $field)->assertForbidden()->assertJson(['status' => false]);

        $this->assertSame($before, [$this->orders(), DB::table('order_expected_delivery_histories')->count()]);
        Event::assertNotDispatched(OrderStatusEvent::class);
    }

    public function test_the_sellers_own_order_still_takes_its_amount_and_date_and_its_driver_is_told(): void
    {
        $this->signInAs(self::SELLER);

        $this->postJson('/vendor/orders/amount-date-update', ['order_id' => self::OWN_ORDER, 'field_name' => 'deliveryman_charge', 'field_val' => 99])->assertOk()->assertJson(['status' => true]);
        $this->postJson('/vendor/orders/amount-date-update', ['order_id' => self::OWN_ORDER, 'field_name' => 'expected_delivery_date', 'field_val' => '2030-01-01', 'cause' => 'late'])->assertOk()->assertJson(['status' => true]);

        $order = DB::table('orders')->where('id', self::OWN_ORDER)->first();
        $this->assertSame([99.0, '2030-01-01'], [(float)$order->deliveryman_charge, $order->expected_delivery_date]);
        $this->assertSame(1, DB::table('order_expected_delivery_histories')->where('order_id', self::OWN_ORDER)->count());
        Event::assertDispatched(OrderStatusEvent::class, static fn (OrderStatusEvent $event): bool => $event->key === 'delivery_man_charge');
        Event::assertDispatched(OrderStatusEvent::class, static fn (OrderStatusEvent $event): bool => $event->key === 'expected_delivery_date');
    }

    // ---- the file an order line delivers after the sale ----

    /** @return array<string, array{int, string}> the order lines that are not the seller's, and the file each delivers */
    public static function linesThatAreNotTheSellers(): array
    {
        return [
            'a line of another seller' => [self::OTHER_SELLERS_LINE, 'original-rival.pdf'],
            'a line of the in-house order sharing the seller id' => [self::IN_HOUSE_LINE, 'original-inhouse.pdf'],
            'a line that does not exist' => [999, ''],
        ];
    }

    #[DataProvider('linesThatAreNotTheSellers')]
    public function test_the_file_of_an_order_line_that_is_not_the_sellers_is_not_replaced(int $lineId, string $file): void
    {
        $this->signInAs(self::SELLER)->post('/vendor/orders/digital-file-upload-after-sell', $this->uploadForm(lineId: $lineId))->assertRedirect();

        $this->assertSame(['error'], $this->toastTypes());
        $this->assertSame($file, (string)DB::table('order_details')->where('id', $lineId)->value('digital_file_after_sell'));
        $this->assertSame([], array_filter(Storage::disk('public')->allFiles('product/digital-product'), static fn (string $path): bool => !str_contains($path, 'original-')), 'no file was stored');
    }

    public function test_the_file_of_the_sellers_own_line_is_still_replaced(): void
    {
        $this->signInAs(self::SELLER)->post('/vendor/orders/digital-file-upload-after-sell', $this->uploadForm(lineId: self::OWN_LINE))->assertRedirect();

        $this->assertSame(['success'], $this->toastTypes());
        $stored = (string)DB::table('order_details')->where('id', self::OWN_LINE)->value('digital_file_after_sell');
        $this->assertNotSame('original-own.pdf', $stored);
        $this->assertSame('REPLACEMENT', Storage::disk('public')->get('product/digital-product/' . $stored));
    }

    /** @return array<string, mixed> */
    private function uploadForm(int $lineId): array
    {
        return ['order_id' => $lineId, 'digital_file_after_sell' => UploadedFile::fake()->createWithContent('replacement.pdf', 'REPLACEMENT')];
    }

    /** The order page, stood in for by tests/Support/vendor-panel-views, prices the order's edit session: it needs what the edit needs, and a line that carries its product. */
    private function createTheTablesTheOrderPageReads(): void
    {
        $this->createTheTablesTheOrderEditUses();
        Cache::put('tax_system_type_vendor_with_vat', [
            'SystemTaxVat' => ['is_active' => 0, 'tax_ids' => [], 'additionalData' => []], 'SystemTaxVatType' => 'order_wise', 'is_included' => 1,
            'productWiseTax' => false, 'categoryWiseTax' => false, 'taxVats' => collect([]),
        ], 3600);
        DB::table('order_details')->where('id', self::OWN_LINE)->update(['qty' => 2, 'price' => 100, 'product_details' => json_encode([
            'id' => self::OWN_PRODUCT, 'name' => 'Own product', 'product_type' => 'physical', 'variation' => '[]', 'choice_options' => '[]', 'minimum_order_qty' => 1,
            'current_stock' => 5, 'unit_price' => 100, 'discount' => 0, 'discount_type' => 'flat',
        ])]);
    }

    /** @return list<array<string, mixed>> */
    private function orders(): array
    {
        return DB::table('orders')->orderBy('id')->get()->map(static fn (object $row): array => Arr::except((array)$row, ['created_at', 'updated_at']))->all();
    }
}
