<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Utils\CartManager;
use App\Utils\Helpers;
use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsApiAddressSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Who a request is, as Helpers::getCustomerInformation() answers it, may only come from a session or a validated token:
 * never from an input field the caller fills in.
 */
class TrustedRequestIdentityTest extends TestCase
{
    use BuildsApiAddressSchema;
    use RestoresLanguageFiles;

    private const GUARDED = '/test-probe/identity/guarded';
    private const OPEN = '/test-probe/identity/open';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();

        Http::preventStrayRequests();
        $this->createApiStoreTables();
        $this->useThrowawayPassportKeys();

        $answer = static fn (Request $request) => response()->json(['identity' => self::describe(Helpers::getCustomerInformation($request))]);
        Route::post(self::GUARDED, $answer)->middleware('apiGuestCheck');
        Route::post(self::OPEN, $answer);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private static function describe(mixed $identity): string
    {
        if (is_string($identity)) {
            return $identity;
        }

        return $identity instanceof User ? 'customer:' . $identity->id : 'untrusted:' . json_encode($identity);
    }

    /** Signs the request in the way the mobile app does, a bearer token that resolves to this customer, and nothing else: the default guard stays the web one, as it is for a real API call. */
    private function withTokenOf(User $customer): static
    {
        $this->actingAs($customer, 'api');
        auth()->shouldUse('web');

        return $this->withHeader('Authorization', 'Bearer test-token');
    }

    public function test_a_stranger_who_posts_a_customer_id_as_user_is_nobody(): void
    {
        $owner = $this->makeCustomer(['wallet_balance' => 500]);

        $request = Request::create('/any', 'POST', ['user' => $owner->id]);
        $this->assertSame('offline', self::describe(Helpers::getCustomerInformation($request)), 'A posted user id must not identify a request.');

        $this->postJson(self::OPEN, ['user' => $owner->id])->assertOk()->assertJsonPath('identity', 'offline');
    }

    public function test_a_stranger_who_posts_a_whole_customer_as_user_is_nobody(): void
    {
        $owner = $this->makeCustomer(['wallet_balance' => 500]);
        $forged = ['id' => $owner->id, 'f_name' => $owner->f_name, 'email' => $owner->email, 'wallet_balance' => 500];

        $request = Request::create('/any', 'POST', ['user' => $forged]);
        $this->assertSame('offline', self::describe(Helpers::getCustomerInformation($request)), 'A posted user array must not identify a request.');

        $this->postJson(self::OPEN, ['user' => $forged])->assertOk()->assertJsonPath('identity', 'offline');
    }

    public function test_a_guest_who_also_posts_a_customer_as_user_is_still_a_guest(): void
    {
        $owner = $this->makeCustomer();

        $this->postJson(self::GUARDED, ['guest_id' => 7, 'user' => $owner->id])->assertOk()->assertJsonPath('identity', 'offline');
        $this->postJson(self::GUARDED, ['guest_id' => 7, 'user' => ['id' => $owner->id]])->assertOk()->assertJsonPath('identity', 'offline');
    }

    public function test_a_caller_with_a_valid_token_is_that_customer(): void
    {
        $customer = $this->makeCustomer();

        $this->withTokenOf($customer)->postJson(self::GUARDED)->assertOk()->assertJsonPath('identity', 'customer:' . $customer->id);
    }

    public function test_a_request_with_only_a_guest_id_is_nobody(): void
    {
        $this->postJson(self::GUARDED, ['guest_id' => 7])->assertOk()->assertJsonPath('identity', 'offline');
    }

    public function test_a_request_without_a_token_or_a_guest_id_is_refused_by_the_guest_check(): void
    {
        $this->postJson(self::GUARDED)->assertStatus(401);
    }

    public function test_a_customer_signed_in_on_the_website_is_that_customer(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($customer, 'customer');

        $this->postJson(self::OPEN)->assertOk()->assertJsonPath('identity', 'customer:' . $customer->id);
        $this->assertSame($customer->id, Helpers::getCustomerInformation(Request::create('/any', 'POST'))->id);
    }

    public function test_the_token_wins_over_a_posted_user(): void
    {
        $customer = $this->makeCustomer();
        $owner = $this->makeCustomer();

        $this->withTokenOf($customer)->postJson(self::GUARDED, ['user' => $owner->id])->assertOk()->assertJsonPath('identity', 'customer:' . $customer->id);
        $this->withTokenOf($customer)->postJson(self::GUARDED, ['user' => ['id' => $owner->id]])->assertOk()->assertJsonPath('identity', 'customer:' . $customer->id);
    }

    /** The body a stranger sends to a public web route: it names another customer exactly as the app's payment fields do. */
    private function strangerBodyNaming(User $owner): void
    {
        $this->app->instance('request', Request::create('/checkout', 'POST', [
            'payment_request_from' => 'app', 'customer_id' => $owner->id, 'is_guest' => 0,
        ]));
    }

    public function test_a_stranger_body_does_not_choose_the_web_checkout_customer(): void
    {
        $owner = $this->makeCustomer();
        $this->strangerBodyNaming($owner);

        $customer = OrderManager::getCustomerInfoForOrder(data: ['is_guest' => 0])['customer'];

        $this->assertFalse($customer instanceof User && $customer->id === $owner->id, 'The checkout resolved to the customer named in the caller-supplied body.');
        $this->assertSame('offline', self::describe($customer));
    }

    public function test_a_checkout_with_only_a_guest_id_stays_a_guest(): void
    {
        $this->app->instance('request', Request::create('/checkout', 'POST', ['guest_id' => 7]));

        $customerInfo = OrderManager::getCustomerInfoForOrder(data: ['is_guest' => 1, 'guest_id' => 7]);

        $this->assertSame('offline', self::describe($customerInfo['customer']));
        $this->assertSame(1, (int) $customerInfo['is_guest']);
        $this->assertSame(7, (int) $customerInfo['customer_id']);
    }

    public function test_a_stranger_body_on_the_cart_listing_leaves_the_owners_cart_alone(): void
    {
        if (!Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('type')->nullable();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }
        $this->createOrderPipelineTables();
        $owner = $this->makeCustomer();
        $this->putOneProductInTheCart($owner->id);
        DB::table('carts')->where('customer_id', $owner->id)->update(['price' => 40]);
        $ownerCartBefore = (array) DB::table('carts')->where('customer_id', $owner->id)->first();

        $this->strangerBodyNaming($owner);
        $listedRows = CartManager::getCartListQuery();

        $this->assertSame($ownerCartBefore, (array) DB::table('carts')->where('customer_id', $owner->id)->first(), 'The owner\'s cart row was changed by a caller who only named the owner.');
        $this->assertCount(0, $listedRows, 'The stranger was shown the owner\'s cart.');
    }
}
