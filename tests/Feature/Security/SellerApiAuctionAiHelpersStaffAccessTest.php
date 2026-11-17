<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Modules\AI\app\Http\Controllers\API\V3\AIAuctionProductController;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSellerApiStaff;
use Tests\TestCase;

/**
 * The ten AI helpers of the auction product form in the seller API (api/v3/seller/auction/product/*) sit behind the token middleware alone, so every
 * staff member who holds a token of their own, with no role, with an empty role or with a role that has nothing to do with products, used them, and a
 * staff member spends the shop's AI quota. The routes of the catalogue declare the permission they need (`seller_can`), and an auction product is a
 * product of the shop: the helpers that generate (all of them but the limit check, which only reads) ask products.manage, and the limit check asks
 * products.view or products.manage. A route that declares a permission also opens to an issued key that carries it; a key that does not is refused.
 *
 * The handlers are stood in for by an answer that counts how often it was reached, so that what is under test is who reaches them. Every request
 * carries the form its handler validates: a refused form answers 403 as well, and a 403 must only ever come from the permission.
 */
class SellerApiAuctionAiHelpersStaffAccessTest extends TestCase
{
    use BuildsSellerApiStaff;

    private const HANDLERS = [
        'titleAutoFill', 'descriptionAutoFill', 'generalSetupAutoFill', 'shippingPolicyAutoFill', 'auctionInfoAutoFill', 'seoSectionAutoFill',
        'generateTitleFromImages', 'generateProductTitleSuggestion', 'setupAutoFill', 'generateLimitCheck',
    ];

    private const GENERATING_HELPERS = [
        'title' => 'title-auto-fill',
        'description' => 'description-auto-fill',
        'general setup' => 'general-setup-auto-fill',
        'shipping policy' => 'shipping-policy-auto-fill',
        'auction info' => 'auction-info-auto-fill',
        'seo section' => 'seo-section-auto-fill',
        'image analysis' => 'analyze-image-auto-fill',
        'title suggestions' => 'generate-title-suggestions',
        'setup' => 'setup-auto-fill',
    ];

    private const LIMIT_CHECK = 'generate-limit-check';

    private const STAFF_WITH_NO_ROLE = 'no role';
    private const STAFF_WITH_AN_EMPTY_ROLE = 'an empty role';
    private const STAFF_WHO_MANAGE_ORDERS = 'orders.manage';
    private const STAFF_WHO_ONLY_VIEW_PRODUCTS = 'products.view';
    private const STAFF_WHO_ONLY_MANAGE_PRODUCTS = 'products.manage';
    private const STAFF_WHO_VIEW_AND_MANAGE_PRODUCTS = 'products.view and products.manage';

    private const KEY_TOKEN_WITH_THE_ORDERS_SCOPE = 'sk_seller_aaaaaa_orders-scope-key-that-is-longer-than-thirty-characters';
    private const KEY_TOKEN_WITH_THE_PRODUCTS_SCOPE = 'sk_seller_bbbbbb_products-scope-key-that-is-longer-than-thirty-characters';

    /** @var array<string, array{id: int, token: string}> */
    private array $staff;

    private int $helperCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerApiWorld();
        $this->createSellerApiStaffTables();
        $this->staff = [
            self::STAFF_WITH_NO_ROLE => $this->insertSellerApiStaffMember(name: 'norole', permissions: null),
            self::STAFF_WITH_AN_EMPTY_ROLE => $this->insertSellerApiStaffMember(name: 'emptyrole', permissions: []),
            self::STAFF_WHO_MANAGE_ORDERS => $this->insertSellerApiStaffMember(name: 'orders', permissions: ['orders.manage']),
            self::STAFF_WHO_ONLY_VIEW_PRODUCTS => $this->insertSellerApiStaffMember(name: 'productsview', permissions: ['products.view']),
            self::STAFF_WHO_ONLY_MANAGE_PRODUCTS => $this->insertSellerApiStaffMember(name: 'productsmanage', permissions: ['products.manage']),
            self::STAFF_WHO_VIEW_AND_MANAGE_PRODUCTS => $this->insertSellerApiStaffMember(name: 'productsboth', permissions: ['products.view', 'products.manage']),
        ];
        $this->createApiKeyTable();
        $this->insertApiKey(token: self::KEY_TOKEN_WITH_THE_ORDERS_SCOPE, prefix: 'aaaaaa', scopes: ['orders.view']);
        $this->insertApiKey(token: self::KEY_TOKEN_WITH_THE_PRODUCTS_SCOPE, prefix: 'bbbbbb', scopes: ['products.manage']);
        $this->standInForTheHandlers();
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, string}> a helper that generates, and a staff member whose role does not grant products.manage */
    public static function generatingHelpersAndStaffWhoMayNotManageProducts(): iterable
    {
        $withoutTheManagePermission = [
            self::STAFF_WITH_NO_ROLE, self::STAFF_WITH_AN_EMPTY_ROLE, self::STAFF_WHO_MANAGE_ORDERS, self::STAFF_WHO_ONLY_VIEW_PRODUCTS,
        ];

        foreach (self::GENERATING_HELPERS as $helper => $segment) {
            foreach ($withoutTheManagePermission as $staff) {
                yield "the {$helper} helper, staff with {$staff}" => ['POST', $segment, $staff];
            }
        }
    }

    /** @return iterable<string, array{string}> a staff member whose role grants neither products.view nor products.manage */
    public static function staffWhoMayNotSeeProducts(): iterable
    {
        foreach ([self::STAFF_WITH_NO_ROLE, self::STAFF_WITH_AN_EMPTY_ROLE, self::STAFF_WHO_MANAGE_ORDERS] as $staff) {
            yield "staff with {$staff}" => [$staff];
        }
    }

    /** @return iterable<string, array{string, string, string}> a helper, and a staff member whose role grants what the helper asks */
    public static function helpersAndStaffWhoMayUseThem(): iterable
    {
        foreach (self::GENERATING_HELPERS as $helper => $segment) {
            yield "the {$helper} helper, staff who manage products" => ['POST', $segment, self::STAFF_WHO_ONLY_MANAGE_PRODUCTS];
            yield "the {$helper} helper, staff who view and manage products" => ['POST', $segment, self::STAFF_WHO_VIEW_AND_MANAGE_PRODUCTS];
        }
        foreach ([self::STAFF_WHO_ONLY_VIEW_PRODUCTS, self::STAFF_WHO_ONLY_MANAGE_PRODUCTS, self::STAFF_WHO_VIEW_AND_MANAGE_PRODUCTS] as $staff) {
            yield "the limit check, staff with {$staff}" => ['GET', self::LIMIT_CHECK, $staff];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function everyHelper(): iterable
    {
        foreach (self::GENERATING_HELPERS as $helper => $segment) {
            yield "the {$helper} helper" => ['POST', $segment];
        }
        yield 'the limit check' => ['GET', self::LIMIT_CHECK];
    }

    #[DataProvider('generatingHelpersAndStaffWhoMayNotManageProducts')]
    public function test_a_staff_member_whose_role_does_not_grant_products_manage_is_refused_by_a_helper_that_generates(string $method, string $segment, string $staff): void
    {
        $this->requestTheHelper(token: $this->staff[$staff]['token'], method: $method, segment: $segment)
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'permission');

        $this->assertSame(0, $this->helperCalls);
    }

    #[DataProvider('staffWhoMayNotSeeProducts')]
    public function test_a_staff_member_whose_role_grants_neither_products_permission_is_refused_by_the_limit_check(string $staff): void
    {
        $this->requestTheHelper(token: $this->staff[$staff]['token'], method: 'GET', segment: self::LIMIT_CHECK)
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'permission');

        $this->assertSame(0, $this->helperCalls);
    }

    #[DataProvider('helpersAndStaffWhoMayUseThem')]
    public function test_a_staff_member_whose_role_grants_what_the_helper_asks_still_reaches_it(string $method, string $segment, string $staff): void
    {
        $this->requestTheHelper(token: $this->staff[$staff]['token'], method: $method, segment: $segment)->assertOk()->assertJson(['handled' => true]);

        $this->assertSame(1, $this->helperCalls);
    }

    #[DataProvider('everyHelper')]
    public function test_the_owner_still_reaches_every_helper(string $method, string $segment): void
    {
        $this->requestTheHelper(token: self::SELLER_TOKENS[self::SELLER], method: $method, segment: $segment)->assertOk()->assertJson(['handled' => true]);

        $this->assertSame(1, $this->helperCalls);
    }

    #[DataProvider('everyHelper')]
    public function test_a_request_without_a_token_is_refused_by_every_helper(string $method, string $segment): void
    {
        $this->requestTheHelper(token: null, method: $method, segment: $segment)->assertUnauthorized();

        $this->assertSame(0, $this->helperCalls);
    }

    #[DataProvider('everyHelper')]
    public function test_an_issued_key_without_the_scope_of_the_helper_is_refused(string $method, string $segment): void
    {
        $this->requestTheHelper(token: self::KEY_TOKEN_WITH_THE_ORDERS_SCOPE, method: $method, segment: $segment)->assertForbidden();

        $this->assertSame(0, $this->helperCalls);
    }

    public function test_an_issued_key_with_the_scope_to_manage_products_reaches_the_helpers_as_it_reaches_the_rest_of_the_catalogue(): void
    {
        $this->requestTheHelper(token: self::KEY_TOKEN_WITH_THE_PRODUCTS_SCOPE, method: 'POST', segment: 'title-auto-fill')->assertOk()->assertJson(['handled' => true]);

        $this->assertSame(1, $this->helperCalls);
    }

    private function requestTheHelper(?string $token, string $method, string $segment): TestResponse
    {
        return $this->call(
            method: $method,
            uri: '/api/v3/seller/auction/product/' . $segment,
            parameters: $this->formFor($segment),
            server: ['HTTP_ACCEPT' => 'application/json'] + ($token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]),
        );
    }

    /** @return array<string, mixed> what the helper's form request accepts, so that a request that is let through reaches its handler */
    private function formFor(string $segment): array
    {
        return match ($segment) {
            'analyze-image-auto-fill' => ['image' => UploadedFile::fake()->image('photo.png')],
            'generate-title-suggestions' => ['keywords' => ['aspirin']],
            'title-auto-fill', 'description-auto-fill' => ['name' => 'Aspirin', 'langCode' => 'en'],
            self::LIMIT_CHECK => [],
            default => ['name' => 'Aspirin', 'description' => 'Pain relief'],
        };
    }

    private function standInForTheHandlers(): void
    {
        $this->partialMock(AIAuctionProductController::class, function (MockInterface $mock): void {
            $mock->shouldReceive(...self::HANDLERS)->andReturnUsing(function (): JsonResponse {
                $this->helperCalls++;

                return new JsonResponse(['handled' => true]);
            });
        });
    }

    private function createApiKeyTable(): void
    {
        $this->createSellerApiTable('seller_api_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('created_by_staff_id')->nullable();
            $table->string('name');
            $table->string('prefix');
            $table->string('token_hash');
            $table->text('scopes')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->string('last_used_ip')->nullable();
            $table->timestamps();
        });
    }

    /** @param list<string> $scopes */
    private function insertApiKey(string $token, string $prefix, array $scopes): void
    {
        DB::table('seller_api_keys')->insert([
            'seller_id' => self::SELLER, 'name' => 'Key ' . $prefix, 'prefix' => $prefix, 'token_hash' => Hash::make($token), 'scopes' => json_encode($scopes),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
