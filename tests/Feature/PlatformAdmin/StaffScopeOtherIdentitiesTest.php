<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\Seller;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsPlatformStaffWorld;
use Tests\Support\LoadsPassportKeys;
use Tests\Support\RestoresLanguageFiles;

/**
 * The staff country scopes (GCC-14c) belong to the employees of the admin panel and the platform admin API reads them for the admin who signs in to it. Nobody else is touched by
 * them: the seller API, the customer API and the storefront answer what they always answered, whatever the staff switch and the rows say, and what a seller or a customer holds opens
 * nothing of the platform admin API.
 */
class StaffScopeOtherIdentitiesTest extends PlatformAdminTestCase
{
    use BuildsPlatformStaffWorld;
    use LoadsPassportKeys;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->loadPassportKeys();
        $this->buildStaffCountryTable();
        Schema::create('seller_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            foreach (['withdrawn', 'commission_given', 'total_earning', 'pending_withdraw', 'delivery_charge_earned', 'collected_cash'] as $column) {
                $table->decimal($column, 24, 2)->default(0);
            }
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        $this->removePassportKeys();

        parent::tearDown();
    }

    public function test_the_other_apis_answer_the_same_with_the_staff_scopes_on_or_off_and_whoever_holds_rows(): void
    {
        $password = Str::random(20);
        Seller::forceCreate(['f_name' => 'Owner', 'email' => 'seller@example.test', 'status' => 'approved', 'password' => Hash::make($password)]);
        $this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']);
        $this->employeeWithGrant(grantCountries: ['SA'], staffCountries: ['SA', 'AE']);

        $answers = [];
        foreach ([false, true] as $switchOn) {
            $this->switchStaffScopes(on: $switchOn);
            $this->releaseEveryRateLimit();
            $answers[$switchOn] = [
                $this->normalised($this->getJson('/api/v1/storefronts')),
                $this->normalised($this->getJson('/api/v3/seller/seller-info', $this->bearer(Str::random(50)))),
                $this->normalised($this->postJson('/api/v3/seller/auth/login', ['email' => 'seller@example.test', 'password' => 'not the password'])),
                $this->normalised($this->postJson('/api/v3/seller/auth/login', ['email' => 'seller@example.test', 'password' => $password])),
                $this->normalised($this->getJson('/api/v1/notifications/seen', $this->bearer(Str::random(50)))),
            ];
        }

        $this->assertSame($answers[false], $answers[true]);
        $this->assertSame(401, $answers[true][1]['status']);
        $this->assertSame(200, $answers[true][3]['status']);
    }

    public function test_what_a_seller_or_a_customer_holds_opens_nothing_of_the_platform_api_with_the_staff_scopes_on(): void
    {
        $this->switchStaffScopes(on: true);
        $this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']);
        $password = Str::random(20);
        Seller::forceCreate(['f_name' => 'Owner', 'email' => 'seller@example.test', 'status' => 'approved', 'password' => Hash::make($password)]);
        $sellerToken = (string) $this->postJson('/api/v3/seller/auth/login', ['email' => 'seller@example.test', 'password' => $password])->assertOk()->json('token');

        $this->assertEveryRouteRefuses(headers: $this->bearer($sellerToken), why: 'a seller token');
        $this->assertEveryRouteRefuses(headers: $this->bearer(Str::random(60)), why: 'a token of any other shape');
        $this->assertEveryRouteRefuses(headers: ['Accept' => 'application/json', 'X-Auth-Token' => $sellerToken, 'X-Api-Key' => $sellerToken], why: 'a seller credential in the other headers');
    }

    public function test_an_admin_who_is_also_a_seller_and_holds_staff_rows_still_gets_only_a_seller_token_at_the_seller_login(): void
    {
        $this->switchStaffScopes(on: true);
        $employee = $this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']);
        Seller::forceCreate(['f_name' => 'Same person', 'email' => $employee['email'], 'status' => 'approved', 'password' => Hash::make($employee['password'])]);

        $response = $this->postJson('/api/v3/seller/auth/login', ['email' => $employee['email'], 'password' => $employee['password']]);

        $response->assertOk();
        $this->assertStringStartsNotWith('lkpa_', (string) $response->json('token'));
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
        $this->assertSame(401, $this->getJson(self::PREFIX . '/me', $this->bearer((string) $response->json('token')))->getStatusCode());
    }

    public function test_the_panel_session_of_an_employee_with_a_staff_scope_is_no_credential_of_the_platform_admin_api(): void
    {
        $this->switchStaffScopes(on: true);
        $employee = $this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']);

        $this->actingAs($this->adminModel($employee), 'admin');

        $this->assertEveryRouteRefuses(headers: ['Accept' => 'application/json'], why: 'the session of the panel');
    }

    public function test_the_platform_admin_api_changes_no_row_of_the_other_identities(): void
    {
        $this->switchStaffScopes(on: true);
        $employee = $this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']);
        $token = $this->mintToken($employee);
        Seller::forceCreate(['f_name' => 'Owner', 'email' => 'seller@example.test', 'status' => 'approved', 'password' => Hash::make('x')]);
        $before = [DB::table('sellers')->get()->toJson(), DB::table('seller_staff')->get()->toJson(), DB::table('seller_api_keys')->get()->toJson(), DB::table('admin_country_scopes')->get()->toJson()];

        foreach (['/me', '/markets', '/overview', '/alerts', '/audit-logs', '/sessions'] as $path) {
            $this->getJson(self::PREFIX . $path, $this->bearer($token))->assertOk();
        }
        $this->postJson(self::PREFIX . '/auth/login', ['email' => $employee['email'], 'password' => $employee['password'], 'device_name' => 'Pixel'])->assertOk();

        $this->assertSame($before, [DB::table('sellers')->get()->toJson(), DB::table('seller_staff')->get()->toJson(), DB::table('seller_api_keys')->get()->toJson(), DB::table('admin_country_scopes')->get()->toJson()]);
    }

    /** @return array{status: int, body: mixed} */
    private function normalised(TestResponse $response): array
    {
        $body = json_decode($response->getContent(), true);
        if (is_array($body) && isset($body['token'])) {
            $body['token'] = strlen($body['token']) . ' characters';
        }

        return ['status' => $response->getStatusCode(), 'body' => $body];
    }
}
