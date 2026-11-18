<?php

namespace Tests\Feature\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\Support\BuildsSellerMarketWorld;
use Tests\TestCase;

/**
 * K-17 criterion 1 and K-08 criterion 3, the backend half: the seller app can ask who it is (GET api/v3/seller/seller-center/security/me). Login
 * answers a token and nothing else, so until now an app could not tell an owner from a staff member, nor which of its screens a role may open.
 *
 * The answer is about the caller and nobody else: owner or staff, the shop, the role, and the permission keys the caller holds right now (read from
 * the role on every request, not from the token). It asks for no permission, because an app that cannot ask who it is cannot know which screens to
 * draw. No grant of markets exists yet (K-09), so the answer says so plainly: unrestricted, and no list of markets, never a made-up one.
 */
class SellerWhoAmITest extends TestCase
{
    use BuildsSellerMarketWorld;

    private const ME = 'seller-center/security/me';

    private const OWNER_PERMISSIONS = [
        'products.view', 'products.manage', 'orders.view', 'orders.manage', 'inventory.manage', 'promotions.manage', 'finance.view', 'payouts.request',
        'reviews.view', 'shop_settings.manage', 'staff.manage',
    ];

    private const THE_SHAPE = ['principal_type', 'seller_id', 'staff_id', 'role', 'permissions', 'market_scope', 'allowed_markets', 'storefronts_enabled'];

    private const STAFF_TOKEN = 'staff-clerk-token-that-is-longer-than-thirty-characters';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerMarketWorld();
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerMarketWorld();

        parent::tearDown();
    }

    // ---- who is asking ----

    public function test_the_owner_is_told_they_are_the_owner_of_their_shop_and_holds_every_permission(): void
    {
        $answer = $this->sellerApi('GET', self::ME)->assertOk();

        $this->assertSame(self::THE_SHAPE, array_keys($answer->json()));
        $this->assertSame(
            ['owner', self::SELLER, null, null, self::OWNER_PERMISSIONS, 'unrestricted', null, false],
            array_values($answer->json()),
        );
    }

    public function test_the_other_owner_is_told_about_their_own_shop_and_never_the_first(): void
    {
        $answer = $this->sellerApi('GET', self::ME, token: self::OTHER_SELLER_TOKEN)->assertOk();

        $this->assertSame(['owner', self::OTHER_SELLER, null], [$answer->json('principal_type'), $answer->json('seller_id'), $answer->json('staff_id')]);
    }

    public function test_a_staff_member_is_told_who_they_are_which_shop_they_work_in_and_what_their_role_grants(): void
    {
        $roleId = $this->insertRole(self::SELLER, 'Order clerk', ['orders.view', 'products.view', 'unknown.permission', 'orders.view']);
        $staffId = $this->insertStaff(sellerId: self::SELLER, roleId: $roleId, token: self::STAFF_TOKEN);

        $answer = $this->sellerApi('GET', self::ME, token: self::STAFF_TOKEN)->assertOk();

        $this->assertSame(self::THE_SHAPE, array_keys($answer->json()));
        $this->assertSame(
            ['staff', self::SELLER, $staffId, 'Order clerk', ['products.view', 'orders.view'], 'unrestricted', null, false],
            array_values($answer->json()),
            'the keys are the ones the platform knows, once each, in the order of the catalogue, and the shop is the employer',
        );
    }

    public function test_a_staff_member_of_the_other_shop_is_told_about_that_shop(): void
    {
        $this->insertStaff(sellerId: self::OTHER_SELLER, roleId: $this->insertRole(self::OTHER_SELLER, 'Cashier', ['orders.manage']), token: self::STAFF_TOKEN);

        $answer = $this->sellerApi('GET', self::ME, token: self::STAFF_TOKEN)->assertOk();

        $this->assertSame([self::OTHER_SELLER, ['orders.manage']], [$answer->json('seller_id'), $answer->json('permissions')]);
    }

    // ---- no permission is needed to ask ----

    public function test_a_role_that_grants_nothing_can_still_ask_who_it_is_while_every_gated_route_refuses_it(): void
    {
        $this->insertStaff(sellerId: self::SELLER, roleId: $this->insertRole(self::SELLER, 'Nothing', []), token: self::STAFF_TOKEN);

        $answer = $this->sellerApi('GET', self::ME, token: self::STAFF_TOKEN)->assertOk();

        $this->assertSame(['staff', 'Nothing', []], [$answer->json('principal_type'), $answer->json('role'), $answer->json('permissions')]);
        $this->sellerApi('POST', 'orders/list', ['limit' => 10, 'offset' => 1], token: self::STAFF_TOKEN)->assertStatus(403);
    }

    public function test_a_staff_member_with_no_role_can_ask_and_holds_nothing(): void
    {
        $this->insertStaff(sellerId: self::SELLER, roleId: null, token: self::STAFF_TOKEN);

        $answer = $this->sellerApi('GET', self::ME, token: self::STAFF_TOKEN)->assertOk();

        $this->assertSame(['staff', null, []], [$answer->json('principal_type'), $answer->json('role'), $answer->json('permissions')]);
    }

    public function test_a_role_that_was_switched_off_holds_nothing_but_is_still_named(): void
    {
        $this->insertStaff(sellerId: self::SELLER, roleId: $this->insertRole(self::SELLER, 'Retired', ['orders.view'], status: 'inactive'), token: self::STAFF_TOKEN);

        $answer = $this->sellerApi('GET', self::ME, token: self::STAFF_TOKEN)->assertOk();

        $this->assertSame(['Retired', []], [$answer->json('role'), $answer->json('permissions')]);
    }

    public function test_what_a_role_grants_is_read_on_every_request_and_not_from_the_session(): void
    {
        $roleId = $this->insertRole(self::SELLER, 'Order clerk', ['orders.view']);
        $this->insertStaff(sellerId: self::SELLER, roleId: $roleId, token: self::STAFF_TOKEN);
        $this->assertSame(['orders.view'], $this->sellerApi('GET', self::ME, token: self::STAFF_TOKEN)->json('permissions'));

        DB::table('seller_roles')->where('id', $roleId)->update(['permissions' => json_encode(['finance.view', 'orders.view'])]);

        $this->assertSame(['orders.view', 'finance.view'], $this->sellerApi('GET', self::ME, token: self::STAFF_TOKEN)->json('permissions'));
    }

    public function test_the_route_sits_in_the_middleware_chain_of_the_other_routes_of_a_signed_in_seller_and_asks_for_no_permission(): void
    {
        $chainOf = fn (string $uri): array => Route::getRoutes()->match(Request::create("/api/v3/seller/{$uri}", 'GET'))->gatherMiddleware();

        $this->assertSame($chainOf('seller-info'), $chainOf(self::ME), 'the chain of seller-info, which any credential of the shop reaches');
        $this->assertContains('seller_api_auth', $chainOf(self::ME));
        $this->assertSame([], array_filter($chainOf(self::ME), fn ($middleware) => is_string($middleware) && (str_starts_with($middleware, 'seller_can') || $middleware === 'seller_owner')));
    }

    // ---- who may not ask ----

    public function test_without_a_token_the_answer_is_the_refusal_of_every_other_route_of_a_signed_in_seller(): void
    {
        $sibling = $this->getJson('/api/v3/seller/seller-info');
        $answer = $this->getJson('/api/v3/seller/' . self::ME);

        $this->assertSame(401, $answer->getStatusCode());
        $this->assertSame($sibling->getContent(), $answer->getContent());
        $this->assertArrayHasKey('auth-001', $answer->json());
    }

    public function test_a_token_that_is_not_one_is_refused_and_names_nobody(): void
    {
        $this->sellerApi('GET', self::ME, token: 'this-is-not-a-token-of-anybody-but-it-is-long-enough')->assertStatus(401);
        $this->sellerApi('GET', self::ME, token: 'short')->assertStatus(401);
    }

    public function test_a_staff_member_who_was_switched_off_and_a_shop_that_is_not_approved_any_more_are_refused(): void
    {
        $this->insertStaff(sellerId: self::SELLER, roleId: $this->insertRole(self::SELLER, 'Order clerk', ['orders.view']), token: self::STAFF_TOKEN, status: 'inactive');
        $this->sellerApi('GET', self::ME, token: self::STAFF_TOKEN)->assertStatus(401);

        DB::table('sellers')->where('id', self::OTHER_SELLER)->update(['status' => 'pending']);
        $this->insertStaff(sellerId: self::OTHER_SELLER, roleId: null, token: 'staff-of-the-pending-shop-token-longer-than-thirty-characters');
        $this->sellerApi('GET', self::ME, token: 'staff-of-the-pending-shop-token-longer-than-thirty-characters')->assertStatus(401);
        $this->sellerApi('GET', self::ME, token: self::OTHER_SELLER_TOKEN)->assertStatus(401);
    }

    public function test_an_integration_key_is_not_a_person_and_is_refused_like_on_every_route_that_names_no_permission(): void
    {
        $plaintext = 'sk_seller_abc123_' . str_repeat('k', 40);
        DB::table('seller_api_keys')->insert([
            'seller_id' => self::SELLER, 'name' => 'Reports', 'prefix' => 'abc123', 'token_hash' => Hash::make($plaintext), 'scopes' => json_encode(['orders.view']),
            'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
        ]);

        $answer = $this->sellerApi('GET', self::ME, token: $plaintext)->assertStatus(403);

        $this->assertSame('api_key', $answer->json('errors.0.code'));
        $this->sellerApi('GET', 'seller-info', token: $plaintext)->assertStatus(403);
    }

    public function test_the_route_only_reads(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->sellerApi($method, self::ME)->assertStatus(405);
        }
    }

    // ---- what it says about markets ----

    public function test_it_says_that_no_market_is_granted_and_names_none_even_where_the_shop_sells_in_several(): void
    {
        $this->switchStorefrontsOn();
        $this->insertStaff(sellerId: self::SELLER, roleId: $this->insertRole(self::SELLER, 'Order clerk', ['orders.view']), token: self::STAFF_TOKEN);

        foreach ([self::SELLER_TOKEN, self::STAFF_TOKEN] as $token) {
            $answer = $this->sellerApi('GET', self::ME, token: $token)->assertOk();

            $this->assertSame(['unrestricted', null, true], [$answer->json('market_scope'), $answer->json('allowed_markets'), $answer->json('storefronts_enabled')]);
            $this->assertArrayHasKey('allowed_markets', $answer->json(), 'the key is there, so that the app can tell "none granted yet" from "an old server"');
            $this->assertSame([], array_filter($this->leavesOf($answer->json()), fn ($leaf) => is_string($leaf) && preg_match('/^[A-Z]{2}$/', $leaf) === 1), 'no country code anywhere');
        }
    }

    public function test_whether_storefronts_are_on_is_the_platform_switch_and_nothing_else(): void
    {
        $this->assertFalse($this->sellerApi('GET', self::ME)->json('storefronts_enabled'));

        $this->switchStorefrontsOn();
        $this->assertTrue($this->sellerApi('GET', self::ME)->json('storefronts_enabled'));

        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.countries.AE.active' => true]);
        $this->assertFalse($this->sellerApi('GET', self::ME)->json('storefronts_enabled'), 'an active market does not switch storefronts on');
    }

    public function test_it_carries_no_credential_and_no_contact_detail(): void
    {
        $this->insertStaff(sellerId: self::SELLER, roleId: $this->insertRole(self::SELLER, 'Order clerk', ['orders.view']), token: self::STAFF_TOKEN);

        foreach ([self::SELLER_TOKEN, self::STAFF_TOKEN] as $token) {
            $content = (string) $this->sellerApi('GET', self::ME, token: $token)->assertOk()->getContent();

            $this->assertStringNotContainsString($token, $content);
            $this->assertStringNotContainsString('example.test', $content);
            $this->assertStringNotContainsString('password', $content);
        }
    }

    /** @return list<mixed> every scalar of a decoded answer, wherever it sits */
    private function leavesOf(mixed $value): array
    {
        return is_array($value) ? array_merge([], ...array_map(fn ($item) => $this->leavesOf($item), array_values($value))) : [$value];
    }
}
