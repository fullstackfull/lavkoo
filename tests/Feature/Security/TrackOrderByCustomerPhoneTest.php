<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Looking an order up by its number and a phone (track-order/result) with nobody signed in: the phone of the customer who placed the order opens
 * it, written any way that is the same number. A piece of that phone, a pattern that matches it, or only its country code opens nothing: the
 * lookup used to find the first customer whose phone contained whatever was typed.
 *
 * The tracking page of the same name in the address suite of the integration branch needs that branch's schema; this is the check that runs
 * on the schema of the tree the server runs.
 */
class TrackOrderByCustomerPhoneTest extends CustomerPasswordResetTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('VIEW_FILE_NAMES')) {
            define('VIEW_FILE_NAMES', include resource_path('themes/default/file_names.php'));
        }
        View::getFinder()->prependLocation(base_path('tests/Support/views/storefront'));
        View::addLocation(resource_path('themes/default'));
        View::share('web_config', ['firebase_otp_verification' => null]);

        Schema::table('orders', function (Blueprint $table) {
            $table->longText('billing_address_data')->nullable();
        });
        Schema::table('order_details', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable();
        });
        Schema::create('guest_users', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address')->nullable();
            $table->string('fcm_token')->nullable();
            $table->timestamps();
        });
        Schema::create('order_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('order_due_amount', 16, 2)->default(0);
            $table->string('order_due_payment_status')->nullable();
            $table->decimal('order_return_amount', 16, 2)->default(0);
            $table->string('order_return_payment_status')->nullable();
            $table->timestamps();
        });
        Schema::create('offline_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('addon_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key_name')->nullable();
            $table->longText('live_values')->nullable();
            $table->longText('test_values')->nullable();
            $table->string('settings_type')->nullable();
            $table->string('mode')->default('live');
            $table->boolean('is_active')->default(true);
            $table->longText('additional_data')->nullable();
            $table->timestamps();
        });
        foreach (['digital_payment', 'cash_on_delivery', 'offline_payment'] as $payment) {
            DB::table('business_settings')->insert(['type' => $payment, 'value' => json_encode(['status' => 0])]);
        }
    }

    private function orderPlacedBy(string $phone, int $orderId): int
    {
        $customerId = $this->customer(phone: $phone, email: "customer{$orderId}@example.test");
        DB::table('orders')->insert([
            'id' => $orderId, 'customer_id' => $customerId, 'is_guest' => 0, 'order_type' => 'default_type', 'order_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_details')->insert(['order_id' => $orderId, 'customer_id' => $customerId, 'created_at' => now(), 'updated_at' => now()]);

        return $customerId;
    }

    private function track(int $orderId, mixed $phone): TestResponse
    {
        return $this->get(route('track-order.result', ['order_id' => $orderId, 'phone_number' => $phone]));
    }

    public function test_the_phone_the_customer_registered_with_opens_the_order_they_placed(): void
    {
        $this->orderPlacedBy(phone: '+966551234567', orderId: 400);

        $this->track(orderId: 400, phone: '+966551234567')->assertOk()->assertSee('TRACKING PAGE OF ORDER 400');
        $this->track(orderId: 400, phone: '551234567')->assertOk()->assertSee('TRACKING PAGE OF ORDER 400');
    }

    public function test_the_same_saudi_number_written_another_way_opens_the_order_of_the_customer(): void
    {
        $this->orderPlacedBy(phone: '+966551234567', orderId: 401);

        $this->track(orderId: 401, phone: '0551234567')->assertOk()->assertSee('TRACKING PAGE OF ORDER 401');
        $this->track(orderId: 401, phone: '00966 55 123 4567')->assertOk()->assertSee('TRACKING PAGE OF ORDER 401');
        $this->track(orderId: 401, phone: '0551234568')->assertRedirect();
    }

    public function test_the_phone_without_the_trunk_zero_the_customer_registered_with_opens_their_order(): void
    {
        $this->orderPlacedBy(phone: '+9630912345678', orderId: 406);

        $this->track(orderId: 406, phone: '+963912345678')->assertOk()->assertSee('TRACKING PAGE OF ORDER 406');
        $this->track(orderId: 406, phone: '+9630912345679')->assertRedirect();
        $this->track(orderId: 406, phone: '+963')->assertRedirect();
    }

    public function test_a_phone_registered_in_digits_of_another_script_is_not_opened_by_its_country_code_alone(): void
    {
        $this->orderPlacedBy(phone: '+971٥٠١٢٣٤٥٦٧', orderId: 407);

        $this->track(orderId: 407, phone: '+971')->assertRedirect();
        $this->track(orderId: 407, phone: '+971501234567')->assertOk()->assertSee('TRACKING PAGE OF ORDER 407');
    }

    public function test_a_phone_registered_in_digits_of_a_script_that_is_not_read_opens_nothing(): void
    {
        $this->orderPlacedBy(phone: '+880১৭১২৩৪৫৬৭৮', orderId: 408);

        $this->track(orderId: 408, phone: '+880')->assertRedirect();
        $this->track(orderId: 408, phone: '+8801712345678')->assertRedirect();
    }

    /** @return array<string, array{string}> */
    public static function piecesAndPatterns(): array
    {
        return [
            'the first digits' => ['+96655'],
            'the last digits' => ['1234567'],
            'a percent sign' => ['%'],
            'a percent sign inside the number' => ['+9665512%'],
            'an underscore' => ['+9665512345_7'],
            'the country code alone' => ['+966'],
            'a single digit' => ['5'],
        ];
    }

    #[DataProvider('piecesAndPatterns')]
    public function test_a_piece_of_the_phone_or_a_pattern_opens_nothing(string $typed): void
    {
        $this->orderPlacedBy(phone: '+966551234567', orderId: 402);

        $this->track(orderId: 402, phone: $typed)->assertRedirect();
    }

    public function test_the_phone_of_another_customer_does_not_open_the_order(): void
    {
        $this->orderPlacedBy(phone: '+966551234567', orderId: 403);
        $this->orderPlacedBy(phone: '+966559876543', orderId: 404);

        $this->track(orderId: 403, phone: '+966559876543')->assertRedirect();
        $this->track(orderId: 404, phone: '+966559876543')->assertOk()->assertSee('TRACKING PAGE OF ORDER 404');
    }

    public function test_a_phone_typed_as_a_list_is_refused_and_does_not_fail(): void
    {
        $this->orderPlacedBy(phone: '+966551234567', orderId: 405);

        $this->track(orderId: 405, phone: ['+966551234567'])->assertRedirect();
        $this->track(orderId: 405, phone: ['%'])->assertRedirect();
    }

    public function test_an_order_that_does_not_exist_opens_nothing(): void
    {
        $this->orderPlacedBy(phone: '+966551234567', orderId: 410);

        $this->track(orderId: 411, phone: '+966551234567')->assertRedirect();
    }
}
