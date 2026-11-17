<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\Admin;
use App\Models\Seller;
use App\Models\SellerRole;
use App\Models\SellerStaff;
use App\Services\Marketplace\SellerApiKeyService;
use App\Services\Marketplace\SellerPermissionService;
use App\Services\Marketplace\SellerPrincipal;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LoadsPassportKeys;

/**
 * Every route of the API against every kind of caller that must not get in. The routes are read from the route table, so a route added later
 * is walked by every case without anybody remembering to list it. A refusal is always the same 401, and an unauthenticated request writes
 * nothing: not a session, not a line of the trail, not even the time a session was last used.
 */
class AuthorizationMatrixTest extends PlatformAdminTestCase
{
    use LoadsPassportKeys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadPassportKeys();
    }

    protected function tearDown(): void
    {
        $this->removePassportKeys();

        parent::tearDown();
    }

    public function test_a_valid_token_is_accepted_by_every_route_that_only_reads(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);
        $reads = array_filter($this->platformRoutes(), static fn ($route): bool => in_array('GET', $route->methods(), true));
        $this->assertNotEmpty($reads);

        foreach ($reads as $route) {
            [$method, $uri] = $this->requestTo($route);

            $this->json($method, $uri, [], $this->bearer($token))->assertOk();
        }
    }

    public function test_the_only_routes_that_need_no_token_are_the_two_steps_of_the_sign_in(): void
    {
        $withoutAuthentication = [];
        foreach ($this->platformRoutes() as $route) {
            if (!in_array('platform_admin_auth', $route->gatherMiddleware(), true)) {
                $withoutAuthentication[] = $route->getName();
            }
        }

        $this->assertSame(['platform_admin.auth.login', 'platform_admin.auth.mfa'], $withoutAuthentication);
    }

    public function test_no_header(): void
    {
        $this->assertEveryRouteRefuses(headers: [], why: 'no Authorization header');
    }

    /** @return array<string, array{0: string}> */
    public static function malformedHeaders(): array
    {
        return [
            'a scheme and no token' => ['Bearer'],
            'a scheme and a space' => ['Bearer '],
            'a short token' => ['Bearer abc'],
            'basic authentication' => ['Basic ' . 'dXNlcjpwYXNz'],
            'two tokens' => ['Bearer lkpa_one lkpa_two'],
            'no space after the scheme' => ['Bearerlkpa_'],
            'a scheme that is not bearer' => ['Token lkpa_'],
            'the prefix and nothing else' => ['Bearer lkpa_'],
            'only the scheme in lower case' => ['bearer'],
        ];
    }

    #[DataProvider('malformedHeaders')]
    public function test_a_malformed_header(string $header): void
    {
        $this->assertEveryRouteRefuses(headers: ['Authorization' => $header], why: $header);
    }

    public function test_a_token_of_the_wrong_length_or_alphabet(): void
    {
        foreach (['lkpa_' . Str::random(63), 'lkpa_' . Str::random(65), 'lkpa_' . str_repeat('!', 64), 'lkpa_' . str_repeat('é', 64), 'LKPA_' . Str::random(64), ' lkpa_' . Str::random(64)] as $token) {
            $this->assertEveryRouteRefuses(headers: ['Authorization' => 'Bearer ' . $token], why: 'a token that is not the shape of ours');
        }
    }

    public function test_a_random_string_of_the_right_length_and_a_random_token_of_the_right_shape(): void
    {
        $this->platformAdmin();

        $this->assertEveryRouteRefuses(headers: $this->bearer(Str::random(64)), why: 'a random 64 character string');
        $this->assertEveryRouteRefuses(headers: $this->bearer('lkpa_' . Str::random(64)), why: 'a random token of the right shape');
    }

    public function test_a_valid_token_under_any_scheme_but_bearer_is_not_a_credential(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);

        foreach (['Token ' . $token, 'Basic ' . $token, 'Bot ' . $token, 'Bearer: ' . $token, 'Bearer  ' . $token, 'BearerX ' . $token, $token, ' Bearer ' . $token, 'Token: ' . $token, 'Bearer:' . $token, 'Bearer_' . $token, 'Xearer ' . $token, 'BearerX' . $token] as $header) {
            $this->assertEveryRouteRefuses(headers: ['Authorization' => $header], why: 'the header ' . substr($header, 0, 12));
        }
        $this->json('GET', self::PREFIX . '/me', [], ['Authorization' => 'bearer ' . $token])->assertOk();
        $this->json('GET', self::PREFIX . '/me', [], ['Authorization' => 'BEARER ' . $token])->assertOk();
    }

    public function test_a_token_that_cannot_be_ours_is_refused_without_a_read_of_the_token_table(): void
    {
        foreach (['x', Str::random(64), 'lkpa_' . Str::random(63), 'lkpa_' . str_repeat('é', 64)] as $candidate) {
            $statements = $this->statementsRunBy(fn () => $this->json('GET', self::PREFIX . '/me', [], $this->bearer($candidate))->assertStatus(401));

            $reads = array_filter($this->withoutSchemaChecks($statements), static fn (string $sql): bool => str_contains($sql, 'platform_admin_tokens'));

            $this->assertSame([], array_values($reads), 'a token of the wrong shape costs no read of the sessions');
        }
    }

    public function test_the_hash_of_a_token_is_not_a_token(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);

        $this->assertEveryRouteRefuses(headers: $this->bearer(hash('sha256', $token)), why: 'the stored hash');
        $this->assertEveryRouteRefuses(headers: $this->bearer(substr($token, 5)), why: 'the token without its prefix');
    }

    public function test_a_seller_owner_token(): void
    {
        $seller = Seller::forceCreate(['f_name' => 'Owner', 'email' => 'seller@example.test', 'status' => 'approved', 'auth_token' => Str::random(50)]);
        $this->assertNotNull(Seller::approved()->where('auth_token', $seller->auth_token)->first(), 'the credential is a good one for the seller API');

        $this->assertEveryRouteRefuses(headers: $this->bearer($seller->auth_token), why: 'a seller owner token');
    }

    public function test_a_seller_staff_token(): void
    {
        $seller = Seller::forceCreate(['f_name' => 'Owner', 'email' => 'seller@example.test', 'status' => 'approved']);
        $role = SellerRole::create(['seller_id' => $seller->id, 'name' => 'Everything', 'permissions' => ['orders.view'], 'status' => 'active']);
        $staff = SellerStaff::create(['seller_id' => $seller->id, 'seller_role_id' => $role->id, 'name' => 'Clerk', 'email' => 'clerk@example.test', 'password' => Hash::make(Str::random(12)), 'auth_token' => Str::random(50), 'status' => 'active']);
        $this->assertNotNull(app(SellerPermissionService::class)->principalFor($staff->auth_token), 'the credential is a good one for the seller API');

        $this->assertEveryRouteRefuses(headers: $this->bearer($staff->auth_token), why: 'a seller staff token');
    }

    public function test_a_seller_api_key(): void
    {
        $seller = Seller::forceCreate(['f_name' => 'Owner', 'email' => 'seller@example.test', 'status' => 'approved']);
        $issued = app(SellerApiKeyService::class)->issue(SellerPrincipal::owner($seller), 'Reports', ['orders.view']);
        $this->assertNotNull(app(SellerApiKeyService::class)->resolve($issued['plaintext']), 'the credential is a good one for the seller API');

        $this->assertEveryRouteRefuses(headers: $this->bearer($issued['plaintext']), why: 'a seller API key');
    }

    public function test_a_customer_passport_token_even_with_the_api_guard_signed_in(): void
    {
        $customer = (new User())->forceFill(['id' => 5]);
        $this->actingAs($customer, 'api');
        $jwt = rtrim(strtr(base64_encode('{"typ":"JWT","alg":"RS256"}'), '+/', '-_'), '=') . '.' . rtrim(strtr(base64_encode('{"aud":"1","sub":"5"}'), '+/', '-_'), '=') . '.' . Str::random(342);
        $this->assertAuthenticated('api');

        $this->assertEveryRouteRefuses(headers: $this->bearer($jwt), why: 'a customer passport token');
        $this->assertEveryRouteRefuses(headers: [], why: 'the api guard signed in and no header');
    }

    public function test_an_admin_web_session_without_a_bearer_token(): void
    {
        $admin = $this->platformAdmin();
        $this->actingAs($this->adminModel($admin), 'admin');
        $this->assertAuthenticated('admin');

        $this->assertEveryRouteRefuses(headers: [], why: 'the admin web session of an admin who holds a grant');
    }

    public function test_an_admin_web_session_next_to_a_bearer_token_of_someone_else(): void
    {
        $webAdmin = $this->platformAdmin();
        $this->actingAs($this->adminModel($webAdmin), 'admin');

        $this->assertEveryRouteRefuses(headers: $this->bearer('lkpa_' . Str::random(64)), why: 'a web session does not vouch for a token');
    }

    public function test_an_expired_token(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin, ['expires_at' => now()->subSecond()]);
        $this->assertTokenOfTheSameAdminWorks($admin);

        $this->assertEveryRouteRefuses(headers: $this->bearer($token), why: 'an expired token');
    }

    public function test_a_token_that_expires_exactly_now_is_expired(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin, ['expires_at' => now()]);

        $this->assertEveryRouteRefuses(headers: $this->bearer($token), why: 'a token whose last moment is this one');
    }

    public function test_a_revoked_token(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin, ['revoked_at' => now()->subMinute()]);
        $this->assertTokenOfTheSameAdminWorks($admin);

        $this->assertEveryRouteRefuses(headers: $this->bearer($token), why: 'a revoked token');
    }

    public function test_the_token_of_a_deactivated_admin(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);
        DB::table('admins')->where('id', $admin['id'])->update(['status' => 0]);

        $this->assertEveryRouteRefuses(headers: $this->bearer($token), why: 'a deactivated admin');
    }

    public function test_the_first_admin_gets_no_exemption_when_deactivated(): void
    {
        DB::table('admins')->delete();
        $admin = $this->platformAdmin(adminOverrides: ['id' => 1]);
        $token = $this->mintToken($admin);
        DB::table('admins')->where('id', 1)->update(['status' => 0]);

        $this->assertEveryRouteRefuses(headers: $this->bearer($token), why: 'admin id 1, deactivated');
    }

    /** @return array<string, array{0: mixed}> */
    public static function statusesThatAreNotOne(): array
    {
        return ['zero' => [0], 'two' => [2], 'minus one' => [-1]];
    }

    #[DataProvider('statusesThatAreNotOne')]
    public function test_only_a_status_of_exactly_one_is_active(mixed $status): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);
        DB::table('admins')->where('id', $admin['id'])->update(['status' => $status]);

        $this->assertEveryRouteRefuses(headers: $this->bearer($token), why: 'an admin whose status is not 1');
    }

    public function test_a_token_after_the_password_of_the_admin_changed(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);
        $this->json('GET', self::PREFIX . '/me', [], $this->bearer($token))->assertOk();

        DB::table('admins')->where('id', $admin['id'])->update(['password' => Hash::make(Str::random(20))]);

        $this->assertEveryRouteRefuses(headers: $this->bearer($token), why: 'a password that changed after the token was issued');
    }

    public function test_the_same_password_hashed_again_is_a_change_too(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);

        DB::table('admins')->where('id', $admin['id'])->update(['password' => Hash::make($admin['password'])]);

        $this->assertEveryRouteRefuses(headers: $this->bearer($token), why: 'a rehashed password');
    }

    public function test_a_token_after_the_grant_was_revoked(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);
        DB::table('platform_admin_grants')->where('id', $admin['grant_id'])->update(['revoked_at' => now()]);

        $this->assertEveryRouteRefuses(headers: $this->bearer($token), why: 'a revoked grant');
    }

    public function test_a_grant_of_a_level_that_does_not_exist_opens_nothing(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);
        DB::table('platform_admin_grants')->where('id', $admin['grant_id'])->update(['level' => 0]);

        $this->assertEveryRouteRefuses(headers: $this->bearer($token), why: 'level 0');
    }

    public function test_the_revoked_grant_of_one_admin_does_not_touch_another_admin(): void
    {
        $revoked = $this->platformAdmin(grantOverrides: ['revoked_at' => now()]);
        $revokedToken = $this->mintToken($revoked);
        $other = $this->platformAdmin();
        $otherToken = $this->mintToken($other);

        $this->assertEveryRouteRefuses(headers: $this->bearer($revokedToken), why: 'the admin whose grant is revoked');
        $this->json('GET', self::PREFIX . '/me', [], $this->bearer($otherToken))->assertOk()->assertJsonPath('data.admin_id', $other['id']);
    }

    public function test_a_token_that_points_at_the_grant_of_a_different_admin(): void
    {
        $revoked = $this->platformAdmin(grantOverrides: ['revoked_at' => now()]);
        $active = $this->platformAdmin();
        $other = $this->platformAdmin();

        $borrowing = $this->platformAdmin(grantOverrides: ['admin_fingerprint' => $this->adminFingerprint(adminId: $active['id'])]);

        $pointsAtRevoked = $this->mintToken($active, ['grant_id' => $revoked['grant_id']]);
        $pointsAtAnActiveOne = $this->mintToken($active, ['grant_id' => $other['grant_id']]);
        $pointsAtOneThatBorrowsTheFingerprint = $this->mintToken($active, ['grant_id' => $borrowing['grant_id']]);

        $this->assertEveryRouteRefuses(headers: $this->bearer($pointsAtRevoked), why: "a token of one admin on another's revoked grant");
        $this->assertEveryRouteRefuses(headers: $this->bearer($pointsAtAnActiveOne), why: "a token of one admin on another's active grant");
        $this->assertEveryRouteRefuses(headers: $this->bearer($pointsAtOneThatBorrowsTheFingerprint), why: "a token of one admin on the grant of another that carries the first one's fingerprint");
    }

    public function test_a_token_of_an_admin_that_was_deleted(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);
        DB::table('platform_admin_grants')->where('id', $admin['grant_id'])->delete();
        DB::table('admins')->where('id', $admin['id'])->delete();

        $this->assertEveryRouteRefuses(headers: $this->bearer($token), why: 'a deleted admin');
    }

    public function test_a_token_whose_admin_row_is_gone_is_refused_even_when_nothing_removed_its_grant(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);
        $this->deleteTheAdminRowAndNothingElse($admin);

        $this->assertEveryRouteRefuses(headers: $this->bearer($token), why: 'an admin row that is gone while its grant and its session are not');
    }

    public function test_a_row_that_comes_to_carry_the_id_of_a_deleted_admin_inherits_neither_its_grant_nor_its_sessions(): void
    {
        $admin = $this->platformAdmin();
        $oldSession = $this->mintToken($admin);
        $this->deleteTheAdminRowAndNothingElse($admin);
        $replacement = $this->makeAdmin(['id' => $admin['id'], 'created_at' => now()->addMinute()]);
        $newSessionOnTheOldGrant = $this->mintToken(['id' => $replacement['id'], 'grant_id' => $admin['grant_id']]);

        $this->assertEveryRouteRefuses(headers: $this->bearer($oldSession), why: 'the session of the admin that was deleted');
        $this->assertEveryRouteRefuses(headers: $this->bearer($newSessionOnTheOldGrant), why: 'a session of the replacement row on the grant of the deleted admin');
        $this->postJson(self::PREFIX . '/auth/login', ['email' => $replacement['email'], 'password' => $replacement['password'], 'device_name' => 'Pixel'])
            ->assertStatus(401)
            ->assertExactJson($this->uniformRefusal());
    }

    public function test_a_valid_token_in_the_query_string_or_the_body_is_not_a_credential(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);

        foreach ($this->platformRoutes() as $route) {
            [$method, $uri] = $this->requestTo($route);

            $this->json($method, $uri . '?token=' . $token . '&access_token=' . $token . '&api_token=' . $token, ['token' => $token, 'access_token' => $token], ['Accept' => 'application/json'])
                ->assertStatus(401)->assertExactJson($this->uniformRefusal());
        }
    }

    public function test_a_valid_token_from_a_cookie_is_not_a_credential(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);

        $this->withUnencryptedCookie('token', $token)->withUnencryptedCookie('platform_admin_token', $token)->json('GET', self::PREFIX . '/me')->assertStatus(401);
    }

    public function test_the_admin_model_is_never_used_to_sign_in_through_the_session_guard(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);

        $this->json('GET', self::PREFIX . '/me', [], $this->bearer($token))->assertOk();

        $this->assertGuest('admin');
        $this->assertGuest('web');
        $this->assertNull(Admin::query()->find($admin['id'])->remember_token, 'no remember token is written');
    }

    /** What a database without the foreign key does when an admin is deleted: the row goes and its grant and sessions stay behind. */
    private function deleteTheAdminRowAndNothingElse(array $admin): void
    {
        Schema::withoutForeignKeyConstraints(fn () => DB::table('admins')->where('id', $admin['id'])->delete());
    }

    private function assertTokenOfTheSameAdminWorks(array $admin): void
    {
        $this->json('GET', self::PREFIX . '/me', [], $this->bearer($this->mintToken($admin)))->assertOk();
    }
}
