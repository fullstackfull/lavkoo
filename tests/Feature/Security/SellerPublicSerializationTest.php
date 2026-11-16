<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\RestAPI\v2\seller\SellerController as SellerControllerV2;
use App\Http\Controllers\RestAPI\v3\seller\SellerController as SellerControllerV3;
use App\Models\Seller;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSellerSchema;
use Tests\Support\FillsMissingTables;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A seller is serialized into public responses (a product's `seller`, an order's `seller`), and the model used to carry everything it has:
 * the remember-me token of the web panel, the push tokens of the apps, the bank account the payouts go to and the commission the shop pays.
 * `remember_token` is a credential: Laravel's session guard authenticates a cookie made of a seller id and that token, so an anonymous visitor
 * who read a public product could open the vendor panel as its seller (the independent review of the seller approval fix reproduced it).
 *
 * What a seller's own profile endpoints return is the one place that still shows the bank account and the commission.
 */
class SellerPublicSerializationTest extends TestCase
{
    use BuildsSellerSchema;
    use FillsMissingTables;
    use RestoresLanguageFiles;

    private const REMEMBER_TOKEN = 'REMEMBER-TOKEN-0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOP';
    private const FIREBASE_TOKEN = 'FIREBASE-DEVICE-TOKEN-abcdef0123456789';
    private const IBAN = 'SA0380000000608010167519';
    private const SECRETS = [self::REMEMBER_TOKEN, self::FIREBASE_TOKEN, self::IBAN, 'Al Rajhi Bank', 'Owner Name', 'REMEMBER-WEB', '17.5'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildSellerSchema();
        Schema::table('sellers', function (Blueprint $table) {
            foreach (['bank_name', 'branch', 'account_no', 'holder_name', 'gst', 'app_language', 'country_code'] as $column) {
                $table->string($column)->nullable();
            }
            $table->decimal('sales_commission_percentage', 8, 2)->nullable();
            $table->decimal('minimum_order_amount', 24, 2)->default(0);
            $table->decimal('free_delivery_over_amount', 24, 2)->default(0);
            $table->integer('pos_status')->default(0);
            $table->integer('free_delivery_status')->default(0);
        });
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function sellerWithEverything(): int
    {
        DB::table('sellers')->insert([
            'id' => 7, 'f_name' => 'Owner', 'l_name' => 'Name', 'phone' => '+966500000001', 'email' => 'owner@example.test', 'status' => 'approved',
            'password' => bcrypt('Aa1!aaaaaa'), 'auth_token' => str_repeat('t', 80), 'remember_token' => self::REMEMBER_TOKEN,
            'cm_firebase_token' => self::FIREBASE_TOKEN, 'cm_firebase_token_web' => 'REMEMBER-WEB',
            'bank_name' => 'Al Rajhi Bank', 'branch' => 'Riyadh', 'account_no' => self::IBAN, 'holder_name' => 'Owner Name',
            'gst' => '300000000000003', 'sales_commission_percentage' => 17.5, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('shops')->insert(['seller_id' => 7, 'name' => 'Owner shop', 'created_at' => now(), 'updated_at' => now()]);

        return 7;
    }

    /** @return list<string> the attributes no public response may carry */
    private static function neverSerialized(): array
    {
        return ['password', 'auth_token', 'remember_token', 'cm_firebase_token', 'cm_firebase_token_web', 'bank_name', 'branch', 'account_no', 'holder_name', 'sales_commission_percentage'];
    }

    public function test_a_seller_serialized_anywhere_carries_no_credential_and_no_bank_account_or_commission(): void
    {
        $this->sellerWithEverything();

        $serialized = Seller::findOrFail(7)->toArray();

        foreach (self::neverSerialized() as $attribute) {
            $this->assertArrayNotHasKey($attribute, $serialized, $attribute);
        }
        $this->assertStringNotContainsString(self::REMEMBER_TOKEN, Seller::findOrFail(7)->toJson());
    }

    public function test_the_server_still_reads_every_one_of_them_from_the_model(): void
    {
        $this->sellerWithEverything();
        $seller = Seller::findOrFail(7);

        $this->assertSame(self::REMEMBER_TOKEN, $seller->remember_token);
        $this->assertSame(self::IBAN, $seller->account_no);
        $this->assertSame(self::FIREBASE_TOKEN, $seller->cm_firebase_token);
        $this->assertEquals(17.5, $seller->sales_commission_percentage);
    }

    public function test_what_the_shop_shows_the_public_is_still_in_the_seller(): void
    {
        $this->sellerWithEverything();

        $serialized = Seller::with('shop')->findOrFail(7)->toArray();

        foreach (['id', 'f_name', 'l_name', 'phone', 'email', 'status', 'shop'] as $attribute) {
            $this->assertArrayHasKey($attribute, $serialized, $attribute);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function publicProductEndpoints(): array
    {
        return [
            'a product page' => ['/api/v1/products/details/item-abc123?guest_id=1'],
            'the latest products' => ['/api/v1/products/latest?limit=5&offset=1&guest_id=1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('publicProductEndpoints')]
    public function test_a_public_product_response_carries_none_of_the_sellers_secrets(string $uri): void
    {
        $sellerId = $this->sellerWithEverything();
        DB::table('products')->insert([
            'name' => 'Item', 'slug' => 'item-abc123', 'added_by' => 'seller', 'user_id' => $sellerId, 'status' => 1, 'request_status' => 1,
            'unit_price' => 10, 'current_stock' => 5, 'colors' => '[]', 'images' => '[]', 'choice_options' => '[]', 'variation' => '[]',
            'attributes' => '[]', 'category_ids' => '[]', 'thumbnail' => 'x.png', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withoutExceptionHandling();
        $response = $this->fillMissingTables(fn () => $this->get($uri, ['Accept' => 'application/json']));

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringContainsString('"seller"', $body, 'the response still names the seller');
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $body, $secret);
        }
    }

    // ---- what the seller's own profile still shows -------------------------------------------------------------------------------------

    public function test_the_seller_app_v3_profile_still_shows_the_bank_account_and_the_commission(): void
    {
        $sellerId = $this->sellerWithEverything();
        $request = Request::create('/api/v3/seller/seller-info', 'GET');
        $request->merge(['seller' => Seller::findOrFail($sellerId)]);

        $profile = app(SellerControllerV3::class)->getSellerInfo($request)->getData(true);

        $this->assertSame('Al Rajhi Bank', $profile['bank_name']);
        $this->assertSame(self::IBAN, $profile['account_no']);
        $this->assertSame('Owner Name', $profile['holder_name']);
        $this->assertSame('Riyadh', $profile['branch']);
        $this->assertEquals(17.5, $profile['sales_commission_percentage']);
        foreach (['password', 'auth_token', 'remember_token', 'cm_firebase_token', 'cm_firebase_token_web'] as $credential) {
            $this->assertArrayNotHasKey($credential, $profile, $credential);
        }
    }

    public function test_the_seller_app_v2_profile_still_shows_the_bank_account_and_the_commission(): void
    {
        $sellerId = $this->sellerWithEverything();
        DB::table('sellers')->where('id', $sellerId)->update(['auth_token' => str_repeat('a', 50)]);
        $request = Request::create('/api/v2/seller/seller-info', 'GET');
        $request->headers->set('authorization', 'Bearer ' . str_repeat('a', 50));

        $profile = app(SellerControllerV2::class)->seller_info($request)->getData(true);

        $this->assertSame('Al Rajhi Bank', $profile['bank_name']);
        $this->assertSame(self::IBAN, $profile['account_no']);
        $this->assertEquals(17.5, $profile['sales_commission_percentage']);
        foreach (['password', 'auth_token', 'remember_token', 'cm_firebase_token', 'cm_firebase_token_web'] as $credential) {
            $this->assertArrayNotHasKey($credential, $profile, $credential);
        }
    }

    // ---- the web panel's remember-me cookie ------------------------------------------------------------------------------------------

    public function test_a_remember_me_cookie_issued_before_a_suspension_is_dead_when_the_seller_is_approved_again(): void
    {
        $this->sellerWithEverything();
        $provider = \Illuminate\Support\Facades\Auth::guard('seller')->getProvider();
        $this->assertNotNull($provider->retrieveByToken(7, self::REMEMBER_TOKEN), 'the cookie works while the seller is signed in');

        Seller::findOrFail(7)->update(['status' => 'suspended']);
        Seller::findOrFail(7)->update(['status' => 'approved']);

        $this->assertNull($provider->retrieveByToken(7, self::REMEMBER_TOKEN), 'the session guard looks the cookie up by seller id and this token');
        $this->assertNotSame(self::REMEMBER_TOKEN, Seller::findOrFail(7)->remember_token);
    }

    public function test_a_change_of_status_rotates_the_session_tokens_and_a_change_of_anything_else_does_not(): void
    {
        $this->sellerWithEverything();

        Seller::findOrFail(7)->update(['f_name' => 'Renamed', 'phone' => '+966500000009']);
        $this->assertSame(self::REMEMBER_TOKEN, Seller::findOrFail(7)->remember_token);
        $this->assertSame(str_repeat('t', 80), Seller::findOrFail(7)->auth_token);

        Seller::findOrFail(7)->update(['status' => 'rejected']);
        $this->assertNotSame(self::REMEMBER_TOKEN, Seller::findOrFail(7)->remember_token);
        $this->assertNotSame(str_repeat('t', 80), Seller::findOrFail(7)->auth_token);
    }
}
