<?php

namespace Tests\Feature\Security;

use App\Models\Seller;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\Marketplace\SellerTeamService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\Support\BuildsAuditLogTable;
use Tests\Support\BuildsSellerMarketWorld;
use Tests\TestCase;

/**
 * K-12's last criterion: signing out of the seller app ends the session ON THE SERVER.
 *
 * Until now it could not. `POST api/v3/seller/auth/logout` did not exist -- there was no address in the whole of seller v3 at which a
 * credential could be given back -- so the app's sign-out cleared its own copy of the token and the token itself stayed live on the row,
 * with no expiry and nothing to end it but the next login replacing it. A phone that was sold, lent or lost kept a working credential for
 * the shop: its orders, its till, its payouts and its staff.
 *
 * What is asserted here is the property from OUTSIDE, which is the only form of it worth having: the token this request arrived with does
 * not work on an authenticated endpoint afterwards. A test that only read the column back would pass on a sign-out that cleared the wrong
 * row.
 *
 * It is a seller-app criterion with two halves. This is the server half. The app half -- the app calling this address at sign-out -- is
 * not here and the criterion is not whole without it.
 */
class SellerSignOutEndsTheSessionTest extends TestCase
{
    use BuildsAuditLogTable;
    use BuildsSellerMarketWorld;

    private const LOGOUT = 'auth/logout';

    /** An authenticated read of this API, used to ask whether a token still works. */
    private const WHO_AM_I = 'seller-center/security/me';

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

    // ---- the criterion itself ----

    public function test_the_owners_sign_out_ends_the_session_their_token_was_in(): void
    {
        $this->sellerApi('GET', self::WHO_AM_I)->assertOk();

        $this->sellerApi('POST', self::LOGOUT)->assertOk()->assertExactJson(['signed_out' => true]);

        $this->sellerApi('GET', self::WHO_AM_I)->assertStatus(401);
        $this->assertNull(DB::table('sellers')->where('id', self::SELLER)->value('auth_token'), 'the credential is gone from the row, not merely from the phone');
    }

    public function test_a_staff_members_sign_out_ends_their_own_session(): void
    {
        $this->insertStaff(sellerId: self::SELLER, roleId: $this->insertRole(sellerId: self::SELLER, name: 'Order clerk', permissions: ['orders.view']), token: self::STAFF_TOKEN);
        $this->sellerApi('GET', self::WHO_AM_I, token: self::STAFF_TOKEN)->assertOk();

        $this->sellerApi('POST', self::LOGOUT, token: self::STAFF_TOKEN)->assertOk();

        $this->sellerApi('GET', self::WHO_AM_I, token: self::STAFF_TOKEN)->assertStatus(401);
        $this->assertNull(DB::table('seller_staff')->where('auth_token', self::STAFF_TOKEN)->value('id'), 'the staff row no longer carries that token');
    }

    public function test_signing_out_again_with_the_same_token_is_refused_rather_than_answered_twice(): void
    {
        $this->sellerApi('POST', self::LOGOUT)->assertOk();

        // A replay is a dead credential being presented, and the honest answer to that is 401. Answering 200 a second time would tell an
        // app holding a stolen token that it is still somebody.
        $this->sellerApi('POST', self::LOGOUT)->assertStatus(401);
    }

    public function test_a_token_nobody_holds_and_a_request_with_no_token_at_all_are_refused_and_change_nothing(): void
    {
        $before = $this->credentialsOfEverybody();

        $this->sellerApi('POST', self::LOGOUT, token: 'a-token-that-was-never-issued-to-anybody-at-all')->assertStatus(401);
        $this->withHeaders(['Accept' => 'application/json'])->json('POST', '/api/v3/seller/' . self::LOGOUT)->assertStatus(401);

        $this->assertSame($before, $this->credentialsOfEverybody());
    }

    // ---- whose session ends, and whose does not ----

    public function test_the_owners_sign_out_leaves_their_staff_signed_in_and_the_other_direction_too(): void
    {
        $this->insertStaff(sellerId: self::SELLER, roleId: $this->insertRole(sellerId: self::SELLER, name: 'Order clerk', permissions: ['orders.view']), token: self::STAFF_TOKEN);

        $this->sellerApi('POST', self::LOGOUT)->assertOk();

        $this->sellerApi('GET', self::WHO_AM_I, token: self::STAFF_TOKEN)->assertOk('a shop does not go dark because its owner closed the app on one phone');

        DB::table('sellers')->where('id', self::SELLER)->update(['auth_token' => self::SELLER_TOKEN]);
        $this->sellerApi('POST', self::LOGOUT, token: self::STAFF_TOKEN)->assertOk();

        $this->sellerApi('GET', self::WHO_AM_I)->assertOk('an employee signing out is not the owner being signed out');
    }

    public function test_no_other_shop_is_signed_out(): void
    {
        $this->sellerApi('POST', self::LOGOUT)->assertOk();

        $this->sellerApi('GET', self::WHO_AM_I, token: self::OTHER_SELLER_TOKEN)->assertOk();
        $this->assertSame(self::OTHER_SELLER_TOKEN, DB::table('sellers')->where('id', self::OTHER_SELLER)->value('auth_token'));
    }

    public function test_two_staff_members_of_one_shop_sign_out_one_at_a_time(): void
    {
        $roleId = $this->insertRole(sellerId: self::SELLER, name: 'Order clerk', permissions: ['orders.view']);
        $this->insertStaff(sellerId: self::SELLER, roleId: $roleId, token: self::STAFF_TOKEN);
        $other = 'second-clerk-token-that-is-longer-than-thirty-characters';
        $this->insertStaff(sellerId: self::SELLER, roleId: $roleId, token: $other);

        $this->sellerApi('POST', self::LOGOUT, token: self::STAFF_TOKEN)->assertOk();

        $this->sellerApi('GET', self::WHO_AM_I, token: $other)->assertOk();
    }

    // ---- what a key may not do ----

    public function test_an_issued_key_cannot_sign_the_shop_out_and_still_works_afterwards(): void
    {
        $plaintext = 'sk_seller_abc123_' . str_repeat('k', 40);
        DB::table('seller_api_keys')->insert([
            'seller_id' => self::SELLER, 'name' => 'Reports', 'prefix' => 'abc123', 'token_hash' => Hash::make($plaintext), 'scopes' => json_encode(['orders.view']),
            'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
        ]);

        // A key is revoked in the key console by the shop that issued it. Were a key able to post here it could end the owner's session --
        // locking the only person who can revoke it out of the app it is being revoked from.
        $answer = $this->sellerApi('POST', self::LOGOUT, token: $plaintext)->assertStatus(403);

        $this->assertSame('api_key', $answer->json('errors.0.code'));
        $this->assertSame(self::SELLER_TOKEN, DB::table('sellers')->where('id', self::SELLER)->value('auth_token'));
        $this->assertNull(DB::table('seller_api_keys')->where('prefix', 'abc123')->value('revoked_at'), 'the key itself is untouched: refusing is not revoking');
    }

    public function test_the_service_refuses_a_key_principal_with_a_reason_rather_than_guessing_whose_session_to_end(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An issued key has no session to end: revoke the key instead.');

        app(SellerTeamService::class)->signOut(principal: SellerPrincipal::integration(
            seller: Seller::findOrFail(self::SELLER),
            apiKey: new \App\Models\SellerApiKey(['seller_id' => self::SELLER, 'name' => 'Reports', 'prefix' => 'abc123']),
            scopes: ['orders.view'],
        ));
    }

    // ---- what else it touches, and what it says ----

    public function test_nothing_about_the_account_changes_but_the_credential(): void
    {
        $before = (array) DB::table('sellers')->where('id', self::SELLER)->first();

        $this->sellerApi('POST', self::LOGOUT)->assertOk();

        $after = (array) DB::table('sellers')->where('id', self::SELLER)->first();
        $moved = array_keys(array_diff_assoc(
            array_map(static fn ($value) => (string) $value, $before),
            array_map(static fn ($value) => (string) $value, $after),
        ));

        // Signing out is not deactivating, and not a password change: the status, the name and every other column stay as they were.
        $this->assertSame(['auth_token'], array_values(array_diff($moved, ['updated_at'])));
        $this->assertSame('approved', $after['status']);
    }

    public function test_the_answer_carries_no_credential(): void
    {
        $body = $this->sellerApi('POST', self::LOGOUT)->assertOk()->content();

        $this->assertStringNotContainsString(self::SELLER_TOKEN, $body);
        $this->assertSame('{"signed_out":true}', $body, 'a machine-readable yes, in no language: an app signs out whatever the server says');
    }

    public function test_the_sign_out_is_recorded_in_the_shops_own_trail(): void
    {
        $this->buildAuditLogTable();

        $this->sellerApi('POST', self::LOGOUT)->assertOk();

        $line = DB::table('audit_logs')->where('action', 'seller.signed_out')->first();
        $this->assertNotNull($line, 'a credential being given back is a security event the shop can read afterwards');
        $this->assertSame([(string) self::SELLER, 'seller'], [(string) $line->subject_id, (string) $line->subject_type]);
        $this->assertStringNotContainsString(self::SELLER_TOKEN, json_encode($line), 'the trail names the session, never its token');
    }

    // ---- where the route sits ----

    public function test_the_route_is_authenticated_and_carries_neither_the_login_throttle_nor_the_market_scope(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($candidate) => $candidate->uri() === 'api/v3/seller/auth/logout' && in_array('POST', $candidate->methods(), true),
        );

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();
        $this->assertContains('seller_api_auth', $middleware, 'a credential has to be presented before it can be given back');
        // The login throttle counts failures against the address, so a seller trying to leave would be refused because somebody else was
        // guessing passwords; the market scope decides what a seller may SEE, and seeing nothing must never trap a seller in a session.
        $this->assertNotContains('throttle:auth', $middleware);
        $this->assertNotContains('seller_market', $middleware);
    }

    public function test_only_a_post_reaches_it(): void
    {
        foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->sellerApi($method, self::LOGOUT)->assertStatus(405);
        }

        $this->sellerApi('GET', self::WHO_AM_I)->assertOk('none of those ended the session');
    }

    /** @return array<string, mixed> */
    private function credentialsOfEverybody(): array
    {
        return [
            'sellers' => DB::table('sellers')->orderBy('id')->pluck('auth_token', 'id')->all(),
            'staff' => DB::table('seller_staff')->orderBy('id')->pluck('auth_token', 'id')->all(),
        ];
    }
}
