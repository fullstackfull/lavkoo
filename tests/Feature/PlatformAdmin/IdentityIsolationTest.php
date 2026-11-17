<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\PlatformAdminGrant;
use App\Models\Seller;
use App\Models\SellerRole;
use App\Models\SellerStaff;
use App\Services\Marketplace\SellerApiKeyService;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\PlatformAdmin\PlatformAdminMfaChallenges;
use App\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LoadsPassportKeys;

/**
 * Every route of the platform API against the credential of every other identity, put in every place a credential can be put: the answer is the one 401, and
 * nothing is written. The routes are read from the route table. And the platform's own tables have no key, no credential column and no token shape in
 * common with those of sellers, their staff, customers and delivery men.
 */
class IdentityIsolationTest extends PlatformAdminTestCase
{
    use LoadsPassportKeys;

    private const PLATFORM_TABLES = [
        'platform_admin_grants', 'platform_admin_tokens', 'platform_admin_audit_logs', 'platform_admin_audit_tail', 'platform_admin_audit_checkpoints',
        'platform_admin_mfa_factors', 'platform_admin_mfa_challenges',
    ];

    /** What a credential of somebody else is called in the tables that hold it. */
    private const CREDENTIAL_COLUMNS = ['auth_token', 'remember_token', 'password', 'api_key', 'access_token', 'refresh_token', 'cm_firebase_token'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadPassportKeys();
        Schema::create('delivery_men', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('auth_token')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        $this->removePassportKeys();

        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function identities(): array
    {
        return [
            'a seller owner token' => ['seller owner'],
            'a seller staff token' => ['seller staff'],
            'a seller API key' => ['seller api key'],
            'a customer token' => ['customer'],
            'a delivery man token' => ['delivery man'],
            'a live challenge of the second step' => ['challenge'],
        ];
    }

    #[DataProvider('identities')]
    public function test_every_route_of_the_platform_api_refuses_the_credential_of_every_other_identity_wherever_it_is_put(string $identity): void
    {
        $credential = $this->credentialOf(identity: $identity);

        $this->assertEveryRouteRefuses(headers: $this->bearer($credential), why: "{$identity} as a bearer token");
        $this->assertEveryRouteRefuses(headers: ['Authorization' => $credential, 'Accept' => 'application/json'], why: "{$identity} as the whole Authorization header");
        $this->assertEveryRouteRefuses(headers: ['X-Auth-Token' => $credential, 'X-Api-Key' => $credential, 'Accept' => 'application/json'], why: "{$identity} in the headers other APIs read");
        $this->assertEveryRouteRefuses(headers: ['Accept' => 'application/json'], why: "{$identity} in the query string", query: ['token' => $credential, 'auth_token' => $credential, 'access_token' => $credential, 'api_key' => $credential]);

        $this->withUnencryptedCookies(['token' => $credential, 'auth_token' => $credential, 'api_key' => $credential]);
        $this->assertEveryRouteRefuses(headers: ['Accept' => 'application/json'], why: "{$identity} in a cookie");
    }

    public function test_the_session_of_a_seller_in_the_vendor_panel_and_of_an_admin_who_holds_a_grant_in_the_admin_panel_are_no_credential_here(): void
    {
        $seller = Seller::forceCreate(['f_name' => 'Owner', 'email' => 'seller@example.test', 'status' => 'approved']);
        $admin = $this->platformAdmin();

        $this->actingAs($seller, 'seller');
        $this->assertAuthenticated('seller');
        $this->assertEveryRouteRefuses(headers: ['Accept' => 'application/json'], why: 'the vendor panel session of a seller');
        $this->assertEveryRouteRefuses(headers: $this->bearer('lkpa_' . Str::random(64)), why: 'the vendor panel session of a seller beside a token that was never issued');

        $this->actingAs($this->adminModel($admin), 'admin');
        $this->assertAuthenticated('admin');
        $this->assertEveryRouteRefuses(headers: ['Accept' => 'application/json'], why: 'the admin panel session of an admin who holds a grant');
        $this->assertEveryRouteRefuses(headers: $this->bearer('lkpa_' . Str::random(64)), why: 'the admin panel session of an admin who holds a grant beside a token that was never issued');
    }

    public function test_the_walk_is_driven_by_the_route_table_and_covers_every_route_of_the_platform_api_and_only_those(): void
    {
        $inTheTable = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/platform-admin/v1'))->map(fn ($route): string => $route->methods()[0] . ' ' . $route->uri())->sort()->values()->all();
        $walked = collect($this->platformRoutes())->map(fn ($route): string => $route->methods()[0] . ' ' . $route->uri())->sort()->values()->all();

        $this->assertGreaterThanOrEqual(11, count($walked));
        $this->assertSame($inTheTable, $walked);
    }

    public function test_no_foreign_key_joins_a_platform_table_to_the_table_of_another_identity(): void
    {
        $allowedTargets = [...self::PLATFORM_TABLES, 'admins'];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table) as $key) {
                if (in_array($table, self::PLATFORM_TABLES, true)) {
                    $this->assertContains($key['foreign_table'], $allowedTargets, "{$table} refers to {$key['foreign_table']}");
                } else {
                    $this->assertNotContains($key['foreign_table'], self::PLATFORM_TABLES, "{$table} refers to a table of the platform API");
                }
            }
        }
    }

    public function test_no_migration_outside_the_platform_apis_own_mentions_one_of_its_tables(): void
    {
        $outside = array_filter(File::files(database_path('migrations')), static fn ($file): bool => !str_contains($file->getFilename(), 'platform_admin'));

        $this->assertNotEmpty($outside);
        foreach ($outside as $file) {
            $this->assertStringNotContainsString('platform_admin_', (string) file_get_contents($file->getPathname()), $file->getFilename());
        }
    }

    public function test_the_tables_of_the_platform_hold_none_of_the_credential_columns_of_sellers_staff_customers_and_delivery_men(): void
    {
        foreach (self::PLATFORM_TABLES as $table) {
            $this->assertSame([], array_values(array_intersect(self::CREDENTIAL_COLUMNS, Schema::getColumnListing($table))), $table);
        }
        foreach (['sellers', 'seller_staff', 'delivery_men'] as $other) {
            $this->assertContains('auth_token', Schema::getColumnListing($other), "the control: {$other} does hold a credential column, so the check above can see one");
        }
    }

    public function test_the_credentials_of_the_platform_have_a_shape_none_of_the_others_has(): void
    {
        $platform = ['lkpa_' . Str::random(64), 'lkpc_' . Str::random(48)];
        foreach (['seller owner', 'seller staff', 'seller api key', 'customer', 'delivery man'] as $identity) {
            $credential = $this->credentialOf(identity: $identity);

            foreach (['lkpa_', 'lkpc_'] as $prefix) {
                $this->assertStringStartsNotWith($prefix, $credential, $identity);
            }
        }
        $this->assertSame(69, strlen($platform[0]));
        $this->assertSame(53, strlen($platform[1]));
    }

    private function credentialOf(string $identity): string
    {
        $seller = Seller::forceCreate(['f_name' => 'Owner', 'email' => 'seller@example.test', 'status' => 'approved', 'auth_token' => Str::random(50)]);

        return match ($identity) {
            'seller owner' => $seller->auth_token,
            'seller staff' => $this->staffTokenOf(seller: $seller),
            'seller api key' => app(SellerApiKeyService::class)->issue(SellerPrincipal::owner($seller), 'Reports', ['orders.view'])['plaintext'],
            'customer' => $this->customerToken(),
            'delivery man' => $this->deliveryManToken(),
            'challenge' => $this->challengeOfAnAdmin(),
        };
    }

    private function staffTokenOf(Seller $seller): string
    {
        $role = SellerRole::create(['seller_id' => $seller->id, 'name' => 'Everything', 'permissions' => ['orders.view'], 'status' => 'active']);

        return SellerStaff::create(['seller_id' => $seller->id, 'seller_role_id' => $role->id, 'name' => 'Clerk', 'email' => 'clerk@example.test', 'password' => Hash::make(Str::random(12)), 'auth_token' => Str::random(50), 'status' => 'active'])->auth_token;
    }

    private function customerToken(): string
    {
        $this->actingAs((new User())->forceFill(['id' => 5]), 'api');

        return rtrim(strtr(base64_encode('{"typ":"JWT","alg":"RS256"}'), '+/', '-_'), '=') . '.' . rtrim(strtr(base64_encode('{"aud":"1","sub":"5"}'), '+/', '-_'), '=') . '.' . Str::random(342);
    }

    private function deliveryManToken(): string
    {
        $token = Str::random(50);
        DB::table('delivery_men')->insert(['f_name' => 'Courier', 'phone' => '+96550000002', 'password' => Hash::make(Str::random(12)), 'auth_token' => $token]);

        return $token;
    }

    private function challengeOfAnAdmin(): string
    {
        $admin = $this->platformAdmin();

        return app(PlatformAdminMfaChallenges::class)->issue(admin: $this->adminModel($admin), grant: PlatformAdminGrant::query()->findOrFail($admin['grant_id']), deviceName: 'Pixel')->plain;
    }
}
