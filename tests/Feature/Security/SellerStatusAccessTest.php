<?php

namespace Tests\Feature\Security;

use App\Events\VendorRegistrationEvent;
use App\Http\Controllers\Admin\Vendor\VendorController;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Seller;
use App\Services\VendorService;
use App\Utils\Helpers;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSellerSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A seller's status decides whether anything they hold still works, and a change of status ends what they were holding.
 *
 * The v2 seller API looked a seller up by the token alone, so a seller who was rejected or set back to pending after signing in kept full
 * access (balance-withdraw included) until someone happened to press suspend, the one transition that used to rotate the token. The web
 * sign-in gate looked up (email OR phone) but authenticated by email, so another seller's phone that spelled a pending seller's e-mail
 * address made the gate read the wrong row.
 */
class SellerStatusAccessTest extends TestCase
{
    use BuildsSellerSchema;
    use RestoresLanguageFiles;

    private const PASSWORD = 'Aa1!aaaaaa';

    private const EMAIL = 'owner@example.test';

    private const TOKEN = 'a-seller-session-token-longer-than-thirty-characters';

    private const NOT_APPROVED = ['rejected', 'pending', 'suspended'];

    private int $sellerCount = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildSellerSchema();
        $this->withoutMiddleware([
            ThrottleRequests::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);
        Event::fake([VendorRegistrationEvent::class]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function statusesThatAreNotApproved(): array
    {
        return array_combine(self::NOT_APPROVED, array_map(static fn (string $status): array => [$status], self::NOT_APPROVED));
    }

    private function seller(array $attributes = []): int
    {
        $this->sellerCount++;

        return DB::table('sellers')->insertGetId(array_merge([
            'f_name' => 'Seller',
            'l_name' => (string) $this->sellerCount,
            'phone' => '05000000' . str_pad((string) $this->sellerCount, 2, '0', STR_PAD_LEFT),
            'email' => "seller{$this->sellerCount}@example.test",
            'password' => bcrypt(self::PASSWORD),
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function column(int $sellerId, string $column): mixed
    {
        return DB::table('sellers')->where('id', $sellerId)->value($column);
    }

    private function v2Login(): string
    {
        return $this->postJson('/api/v2/seller/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])->assertOk()->json('token');
    }

    private function useTokenOnV2(string $token): int
    {
        return $this->putJson('/api/v2/seller/cm-firebase-token', ['cm_firebase_token' => 'a-device'], ['Authorization' => 'Bearer ' . $token])->getStatusCode();
    }

    private function adminSetsStatus(int $sellerId, string $status): void
    {
        app(VendorController::class)->updateStatus(new Request(['id' => $sellerId, 'status' => $status]));
    }

    private function signInOnTheWeb(string $email, string $password = self::PASSWORD): TestResponse
    {
        return $this->post('/vendor/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function assertToastedError(TestResponse $response, string $translationKey): void
    {
        $response->assertSessionHas('laravel-toaster-magic::messages', static fn (array $messages): bool => $messages[0]['type'] === 'error' && $messages[0]['message'] === translate($translationKey) . '!');
    }

    #[DataProvider('statusesThatAreNotApproved')]
    public function test_a_v2_token_is_refused_once_the_seller_is_not_approved(string $status): void
    {
        $sellerId = $this->seller(['status' => $status, 'auth_token' => self::TOKEN]);

        $this->assertSame(401, $this->useTokenOnV2(self::TOKEN));

        $this->assertNull($this->column($sellerId, 'cm_firebase_token'));
    }

    public function test_a_v2_token_works_for_an_approved_seller(): void
    {
        $sellerId = $this->seller(['auth_token' => self::TOKEN]);

        $this->assertSame(200, $this->useTokenOnV2(self::TOKEN));

        $this->assertSame('a-device', $this->column($sellerId, 'cm_firebase_token'));
    }

    public function test_the_token_helpers_resolve_an_approved_seller_and_nobody_else(): void
    {
        $approved = $this->seller(['auth_token' => self::TOKEN . '-approved']);
        foreach (self::NOT_APPROVED as $status) {
            $this->seller(['status' => $status, 'auth_token' => self::TOKEN . '-' . $status]);
        }
        $requestFor = static fn (string $suffix): Request => Request::create('/', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN . '-' . $suffix]);

        $this->assertSame($approved, Helpers::getSellerByToken($requestFor('approved'))?->id);
        $this->assertSame(1, Helpers::get_seller_by_token($requestFor('approved'))['success']);
        foreach (self::NOT_APPROVED as $status) {
            $this->assertNull(Helpers::getSellerByToken($requestFor($status)), "a {$status} seller must not resolve from their token");
            $this->assertSame(0, Helpers::get_seller_by_token($requestFor($status))['success'], "a {$status} seller must not resolve from their token");
        }
    }

    #[DataProvider('statusesThatAreNotApproved')]
    public function test_a_seller_who_leaves_approved_gets_a_new_token(string $status): void
    {
        $sellerId = $this->seller(['auth_token' => self::TOKEN]);

        Seller::query()->findOrFail($sellerId)->update(['status' => $status]);

        $this->assertSame($status, $this->column($sellerId, 'status'));
        $this->assertNotSame(self::TOKEN, $this->column($sellerId, 'auth_token'));
        $this->assertNotEmpty($this->column($sellerId, 'auth_token'));
    }

    public function test_a_seller_keeps_the_token_when_the_status_does_not_change(): void
    {
        $sellerId = $this->seller(['auth_token' => self::TOKEN]);

        Seller::query()->findOrFail($sellerId)->update(['f_name' => 'Renamed', 'status' => 'approved']);

        $this->assertSame('Renamed', $this->column($sellerId, 'f_name'));
        $this->assertSame(self::TOKEN, $this->column($sellerId, 'auth_token'));
    }

    #[DataProvider('statusesThatAreNotApproved')]
    public function test_an_admin_changing_the_status_ends_the_seller_session(string $status): void
    {
        $sellerId = $this->seller(['auth_token' => self::TOKEN]);

        $this->adminSetsStatus($sellerId, $status);

        $this->assertSame($status, $this->column($sellerId, 'status'));
        $this->assertNotSame(self::TOKEN, $this->column($sellerId, 'auth_token'));
        $this->assertSame(401, $this->useTokenOnV2(self::TOKEN));
    }

    public function test_the_bulk_status_change_ends_the_seller_sessions_too(): void
    {
        $sellerId = $this->seller(['auth_token' => self::TOKEN]);

        app(VendorController::class)->bulkUpdateStatus(new Request(['ids' => [$sellerId], 'status' => 'rejected']));

        $this->assertSame('rejected', $this->column($sellerId, 'status'));
        $this->assertNotSame(self::TOKEN, $this->column($sellerId, 'auth_token'));
    }

    public function test_deleting_an_approved_seller_suspends_them_and_ends_their_session(): void
    {
        $sellerId = $this->seller(['auth_token' => self::TOKEN]);

        app(VendorController::class)->destroy($sellerId);

        $deleted = Seller::withTrashed()->findOrFail($sellerId);
        $this->assertSame('suspended', $deleted->status);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertNotSame(self::TOKEN, DB::table('sellers')->where('id', $sellerId)->value('auth_token'));
    }

    public function test_a_seller_who_was_rejected_and_approved_again_signs_in_again_and_works(): void
    {
        $sellerId = $this->seller(['email' => self::EMAIL]);
        $firstToken = $this->v2Login();
        $this->assertSame(200, $this->useTokenOnV2($firstToken));

        $this->adminSetsStatus($sellerId, 'rejected');
        $this->assertSame(401, $this->useTokenOnV2($firstToken), 'a rejected seller keeps nothing of the session');

        $this->adminSetsStatus($sellerId, 'approved');
        $this->assertSame(401, $this->useTokenOnV2($firstToken), 'approving again does not bring the old session back');
        $secondToken = $this->v2Login();
        $this->assertNotSame($firstToken, $secondToken);
        $this->assertSame(200, $this->useTokenOnV2($secondToken));
    }

    public function test_a_pending_seller_cannot_sign_in_on_the_web_through_another_sellers_phone(): void
    {
        $this->seller(['status' => 'pending', 'phone' => self::EMAIL, 'password' => bcrypt('Another1!password')]);
        $this->seller(['status' => 'pending', 'email' => self::EMAIL]);

        $response = $this->signInOnTheWeb(self::EMAIL);

        $this->assertGuest('seller');
        $this->assertToastedError($response, 'Not_approve_yet');
    }

    public function test_an_approved_seller_signs_in_on_the_web_even_when_another_sellers_phone_looks_like_their_email(): void
    {
        $this->seller(['status' => 'pending', 'phone' => self::EMAIL, 'password' => bcrypt('Another1!password')]);
        $approvedId = $this->seller(['email' => self::EMAIL]);

        $this->signInOnTheWeb(self::EMAIL)->assertRedirect(route('vendor.dashboard.index'));

        $this->assertAuthenticatedAs(Seller::query()->findOrFail($approvedId), 'seller');
    }

    public function test_an_approved_seller_signs_in_on_the_web(): void
    {
        $sellerId = $this->seller(['email' => self::EMAIL]);

        $this->signInOnTheWeb(self::EMAIL)->assertRedirect(route('vendor.dashboard.index'));

        $this->assertAuthenticatedAs(Seller::query()->findOrFail($sellerId), 'seller');
    }

    #[DataProvider('statusesThatAreNotApproved')]
    public function test_a_seller_who_is_not_approved_does_not_sign_in_on_the_web_with_the_right_password(string $status): void
    {
        $this->seller(['status' => $status, 'email' => self::EMAIL]);

        $response = $this->signInOnTheWeb(self::EMAIL)->assertRedirect();

        $this->assertGuest('seller');
        $this->assertToastedError($response, 'Not_approve_yet');
    }

    public function test_an_approved_seller_with_the_wrong_password_does_not_sign_in_on_the_web(): void
    {
        $this->seller(['email' => self::EMAIL]);

        $response = $this->signInOnTheWeb(self::EMAIL, 'Wrong1!password')->assertRedirect();

        $this->assertGuest('seller');
        $this->assertToastedError($response, 'credentials_doesnt_match');
    }

    #[DataProvider('statusesThatAreNotApproved')]
    public function test_the_vendor_login_service_authenticates_only_an_approved_seller(string $status): void
    {
        $this->seller(['status' => $status, 'email' => self::EMAIL]);

        $signedIn = app(VendorService::class)->isLoginSuccessful(email: self::EMAIL, password: self::PASSWORD, rememberToken: false);

        $this->assertFalse($signedIn);
        $this->assertGuest('seller');
    }

    public function test_the_vendor_login_service_authenticates_an_approved_seller(): void
    {
        $sellerId = $this->seller(['email' => self::EMAIL]);

        $signedIn = app(VendorService::class)->isLoginSuccessful(email: self::EMAIL, password: self::PASSWORD, rememberToken: false);

        $this->assertTrue($signedIn);
        $this->assertAuthenticatedAs(Seller::query()->findOrFail($sellerId), 'seller');
    }
}
