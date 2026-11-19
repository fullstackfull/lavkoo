<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use App\Models\Seller;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\TestCase;

/**
 * The name of whoever writes to the shop reaches the inbox page as text.
 *
 * The inbox of the admin panel and the inbox of the vendor panel write the name of the open conversation into the page as
 * markup, and the name is whatever its owner typed when registering: a customer registers with a first name that is only
 * required to be there. A name that carried markup was run in the browser of the employee or the seller who opened the
 * conversation, with everything their session may do.
 *
 * The answer the page asks for is driven here over HTTP as the admin and as a seller. That the pages write the field as markup
 * was seen in a browser with the shop's own two scripts; no browser runs in this suite, so what is pinned here is the half the
 * server owns: the field holds no markup, whatever the name.
 */
class InboxNameIsTextTest extends TestCase
{
    use BuildsFulfillmentSchema;

    private const MASTER = 1;

    private const SELLER = 5;

    private const CUSTOMER = 17;

    private const DELIVERY_MAN = 23;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCoreSchema();
        $this->buildTheInboxTables();
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class,
            MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class, MaintenanceModeMiddleware::class,
        ]);
        Http::preventStrayRequests();
    }

    /** @return array<string, array{string, string}> a first and a last name */
    public static function aNameThatIsNotOnlyLetters(): array
    {
        return [
            'an element that runs a script when it fails to load' => ['<img src=x onerror=alert(document.domain)>', 'Alharbi'],
            'a script element' => ['Noura', '<script>alert(1)</script>'],
            'an ampersand and quotes, which are a real name' => ['Noura & "Sons"', "O'Neil"],
        ];
    }

    #[DataProvider('aNameThatIsNotOnlyLetters')]
    public function test_the_admin_inbox_is_given_the_name_of_a_customer_as_text(string $first, string $last): void
    {
        $this->aCustomerNamed(first: $first, last: $last);
        $this->aMessageToTheShopFrom(column: 'user_id', id: self::CUSTOMER, to: ['admin_id' => 0]);

        $name = $this->actingAs(Admin::findOrFail(self::MASTER), 'admin')->getJson('/admin/messages/message?user_id=' . self::CUSTOMER)->assertOk()->json('userData.name');

        $this->assertIsTextOf(written: $name, typed: "{$first} {$last}");
    }

    #[DataProvider('aNameThatIsNotOnlyLetters')]
    public function test_the_admin_inbox_is_given_the_name_of_a_delivery_man_as_text(string $first, string $last): void
    {
        $this->aDeliveryManNamed(first: $first, last: $last);
        $this->aMessageToTheShopFrom(column: 'delivery_man_id', id: self::DELIVERY_MAN, to: ['admin_id' => 0]);

        $name = $this->actingAs(Admin::findOrFail(self::MASTER), 'admin')->getJson('/admin/messages/message?delivery_man_id=' . self::DELIVERY_MAN)->assertOk()->json('userData.name');

        $this->assertIsTextOf(written: $name, typed: "{$first} {$last}");
    }

    #[DataProvider('aNameThatIsNotOnlyLetters')]
    public function test_the_vendor_inbox_is_given_the_name_of_a_customer_as_text(string $first, string $last): void
    {
        $this->aCustomerNamed(first: $first, last: $last);
        $this->aMessageToTheShopFrom(column: 'user_id', id: self::CUSTOMER, to: ['seller_id' => self::SELLER]);

        $name = $this->actingAs(Seller::findOrFail(self::SELLER), 'seller')->getJson('/vendor/messages/message?user_id=' . self::CUSTOMER)->assertOk()->json('userData.name');

        $this->assertIsTextOf(written: $name, typed: "{$first} {$last}");
    }

    /** No markup reaches the page, and nothing of the name is lost: read back as text it is what was typed. */
    private function assertIsTextOf(mixed $written, string $typed): void
    {
        $this->assertIsString($written);
        $this->assertStringNotContainsString('<', $written);
        $this->assertStringNotContainsString('>', $written);
        $this->assertSame($typed, html_entity_decode($written, ENT_QUOTES | ENT_HTML5));
    }

    private function aCustomerNamed(string $first, string $last): void
    {
        DB::table('users')->insert(['id' => self::CUSTOMER, 'f_name' => $first, 'l_name' => $last, 'phone' => '500000017', 'country_code' => '+966', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function aDeliveryManNamed(string $first, string $last): void
    {
        DB::table('delivery_men')->insert(['id' => self::DELIVERY_MAN, 'f_name' => $first, 'l_name' => $last, 'phone' => '500000023', 'country_code' => '+966', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, int> $to */
    private function aMessageToTheShopFrom(string $column, int $id, array $to): void
    {
        DB::table('chattings')->insert([$column => $id, 'message' => 'where is my order?', 'attachment' => json_encode([]), 'status' => 1, 'created_at' => now(), 'updated_at' => now()] + $to);
    }

    private function buildTheInboxTables(): void
    {
        foreach (['users', 'delivery_men'] as $people) {
            Schema::dropIfExists($people);
            Schema::create($people, function (Blueprint $table): void {
                $table->id();
                $table->text('f_name')->nullable();
                $table->text('l_name')->nullable();
                $table->string('phone')->nullable();
                $table->string('country_code')->nullable();
                $table->string('image')->nullable();
                $table->timestamps();
            });
        }

        Schema::dropIfExists('sellers');
        Schema::create('sellers', function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->default('approved');
            $table->softDeletes();
            $table->timestamps();
        });
        DB::table('sellers')->insert(['id' => self::SELLER, 'f_name' => 'A', 'l_name' => 'Seller', 'email' => 'seller@example.test', 'status' => 'approved']);

        Schema::dropIfExists('chattings');
        Schema::create('chattings', function (Blueprint $table): void {
            $table->id();
            foreach (['user_id', 'seller_id', 'admin_id', 'delivery_man_id', 'shop_id'] as $party) {
                $table->unsignedBigInteger($party)->nullable();
            }
            $table->text('message')->nullable();
            $table->text('attachment')->nullable();
            foreach (['sent_by_customer', 'sent_by_seller', 'sent_by_admin', 'sent_by_delivery_man', 'seen_by_customer', 'seen_by_seller', 'seen_by_admin', 'seen_by_delivery_man', 'seen_notification'] as $flag) {
                $table->boolean($flag)->nullable();
            }
            $table->boolean('status')->default(true);
            $table->string('notification_receiver')->nullable();
            $table->timestamps();
        });
    }
}
