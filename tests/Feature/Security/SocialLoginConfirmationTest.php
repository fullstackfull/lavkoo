<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AssertsNoLanguageFileWasWritten;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\TestCase;

/**
 * Linking a social login to an existing customer is only allowed for the email the provider has just returned to this browser's session.
 * Without that session, the confirmation link must not log anyone in, show who owns an email, or change the account.
 *
 * The session entry is the proof, so it is also spent: once the login has completed, or the customer has logged out, the same link and
 * the same form no longer work. The provider callback, the confirmation page and its two actions are driven the way the browser does.
 */
class SocialLoginConfirmationTest extends TestCase
{
    use AssertsNoLanguageFileWasWritten;
    use BuildsFulfillmentSchema;

    private const VICTIM_ID = 5;
    private const VICTIM_EMAIL = 'victim@example.test';
    private const PHONE_ONLY_ID = 6;
    private const PENDING_LOGIN_KEY = 'social_login_new_customer';
    private const MESSAGE_KEYS = [
        'invalid_request',
        'Phone_Number_Already_Exist',
        'Is_It_You',
        'it_looks_like_the_email',
        'you_entered_has_already_been_used_and_has_an_existing_account',
        'It’s_Me',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardAgainstWrittenLanguageFiles();
        $this->renderPagesWithTheStandInStorefrontShell();
        $this->buildCustomerLoginTables();
        DB::table('users')->insert([
            'id' => self::VICTIM_ID,
            'f_name' => 'Layla',
            'l_name' => 'Victim',
            'email' => self::VICTIM_EMAIL,
            'phone' => '0500000005',
            'password' => bcrypt('irrelevant'),
            'is_active' => 1,
            'is_phone_verified' => 1,
            'is_email_verified' => 0,
            'login_medium' => null,
            'social_id' => null,
        ]);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_URI']);

        parent::tearDown();
    }

    private function renderPagesWithTheStandInStorefrontShell(): void
    {
        if (!defined('VIEW_FILE_NAMES')) {
            define('VIEW_FILE_NAMES', include resource_path('themes/default/file_names.php'));
        }

        View::getFinder()->prependLocation(base_path('tests/Support/views/storefront'));
        View::addLocation(resource_path('themes/default'));
        View::share('web_config', ['firebase_otp_verification' => null]);
    }

    /** Starts from an empty database, which the helper refuses unless it is unmistakably a throwaway test database. */
    private function buildCustomerLoginTables(): void
    {
        $this->startFromAnEmptyTestDatabase();

        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_phone_verified')->default(false);
            $table->boolean('is_email_verified')->default(false);
            $table->string('login_medium')->nullable();
            $table->string('social_id')->nullable();
            $table->string('temporary_token')->nullable();
            $table->string('referral_code')->nullable();
            $table->string('app_language')->nullable();
            $table->string('cm_firebase_token_web')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('business_settings', function ($table) {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('login_setups', function ($table) {
            $table->id();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('phone_or_email_verifications', function ($table) {
            $table->id();
            $table->string('phone_or_email')->nullable();
            $table->string('token')->nullable();
            $table->unsignedTinyInteger('otp_hit_count')->default(0);
            $table->boolean('is_temp_blocked')->default(false);
            $table->timestamp('temp_block_time')->nullable();
            $table->timestamps();
        });
        Schema::create('product_compares', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('product_id');
        });
        Schema::create('wishlists', function ($table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('product_id');
        });
        Schema::create('products', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('added_by')->nullable();
            $table->string('product_type')->nullable();
            $table->boolean('status')->default(true);
            $table->boolean('request_status')->default(true);
        });
        Schema::create('sellers', function ($table) {
            $table->id();
            $table->string('status')->nullable();
            $table->softDeletes();
        });
        Schema::create('brands', function ($table) {
            $table->id();
            $table->boolean('status')->default(true);
        });
        Schema::create('restock_products', function ($table) {
            $table->id();
        });
        Schema::create('restock_product_customers', function ($table) {
            $table->id();
            $table->unsignedBigInteger('restock_product_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
        });
        Schema::create('storages', function ($table) {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
    }

    private function insertPhoneOnlyCustomer(): void
    {
        DB::table('users')->insert([
            'id' => self::PHONE_ONLY_ID,
            'f_name' => 'Phone',
            'l_name' => 'Only',
            'email' => null,
            'phone' => '0500000006',
            'password' => bcrypt('irrelevant'),
            'is_active' => 1,
            'is_phone_verified' => 1,
            'is_email_verified' => 0,
        ]);
    }

    private function identityFor(string $email): string
    {
        return base64_encode($email);
    }

    private function pendingSocialLogin(?string $email): array
    {
        return [
            self::PENDING_LOGIN_KEY => [
                'name' => 'Layla Victim',
                'f_name' => 'Layla',
                'l_name' => 'Victim',
                'email' => $email,
                'phone' => '',
                'login_medium' => 'google',
                'social_id' => 'google-uid-1',
                'is_email_verified' => 1,
            ],
        ];
    }

    private function confirmationUrl(string $email, array $query = []): string
    {
        return route('customer.auth.social-login-confirmation', array_merge(['identity' => $this->identityFor($email)], $query));
    }

    private function updateUrl(): string
    {
        return route('customer.auth.social-login-confirmation.update');
    }

    private function customerRow(int $id): array
    {
        return (array) DB::table('users')->where('id', $id)->first();
    }

    /** @return list<string> */
    private function toastedMessages(): array
    {
        return array_column(session('toastr::messages') ?? [], 'message');
    }

    private function fakeProviderReturning(string $service, array $profile): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn((new SocialiteUser())->setRaw($profile)->map($profile));
        Socialite::shouldReceive('driver')->with($service)->andReturn($driver);
    }

    private function returnFromProvider(string $service, array $profile): TestResponse
    {
        $this->fakeProviderReturning(service: $service, profile: $profile);
        $_SERVER['REQUEST_URI'] = "/customer/auth/login/{$service}/callback?code=authorization-code";

        return $this->get(route('customer.auth.service-callback', ['service' => $service, 'code' => 'authorization-code']));
    }

    private function returnFromGoogle(string $email, string $name = 'Layla Victim'): TestResponse
    {
        return $this->returnFromProvider(service: 'google', profile: ['id' => 'google-uid-1', 'name' => $name, 'email' => $email]);
    }

    private function identityOfTheRedirect(TestResponse $callback): string
    {
        parse_str((string) parse_url((string) $callback->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query['identity'];
    }

    /** The link the "Yes, it's me" button of the confirmation page opens. */
    private function approvalUrlOf(TestResponse $callback): string
    {
        return route('customer.auth.social-login-confirmation', ['identity' => $this->identityOfTheRedirect($callback), 'status' => 'approve']);
    }

    private function isRunningOnMySql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function test_a_visitor_with_no_social_login_cannot_log_in_by_approving_an_email(): void
    {
        $response = $this->get($this->confirmationUrl(self::VICTIM_EMAIL, ['status' => 'approve']));

        $response->assertRedirect(route('customer.auth.login'));
        $this->assertGuest('customer');
    }

    public function test_an_approval_with_no_social_login_leaves_the_account_unchanged(): void
    {
        $accountBefore = $this->customerRow(id: self::VICTIM_ID);

        $this->get($this->confirmationUrl(self::VICTIM_EMAIL, ['status' => 'approve']));

        $this->assertSame($accountBefore, $this->customerRow(id: self::VICTIM_ID));
    }

    public function test_a_social_login_for_another_email_cannot_approve_this_account(): void
    {
        $accountBefore = $this->customerRow(id: self::VICTIM_ID);

        $response = $this->withSession($this->pendingSocialLogin('someone.else@example.test'))
            ->get($this->confirmationUrl(self::VICTIM_EMAIL, ['status' => 'approve']));

        $response->assertRedirect(route('customer.auth.login'));
        $this->assertGuest('customer');
        $this->assertSame($accountBefore, $this->customerRow(id: self::VICTIM_ID));
    }

    public function test_the_confirmation_page_does_not_show_who_owns_an_email_to_a_social_login_for_another_email(): void
    {
        $response = $this->withSession($this->pendingSocialLogin('attacker@example.test'))
            ->get($this->confirmationUrl(self::VICTIM_EMAIL));

        $response->assertRedirect(route('customer.auth.login'));
    }

    public function test_the_confirmation_page_asks_the_matching_social_login_whether_it_is_the_owner_of_the_account(): void
    {
        $this->withSession($this->pendingSocialLogin(self::VICTIM_EMAIL))
            ->get($this->confirmationUrl(self::VICTIM_EMAIL))
            ->assertOk()
            ->assertSee('Layla Victim');
    }

    public function test_the_provider_email_returned_to_this_session_can_be_approved(): void
    {
        $response = $this->withSession($this->pendingSocialLogin(self::VICTIM_EMAIL))
            ->get($this->confirmationUrl(self::VICTIM_EMAIL, ['status' => 'approve']));

        $response->assertRedirect();
        $this->assertAuthenticatedAs(User::query()->findOrFail(self::VICTIM_ID), 'customer');
        $account = User::query()->findOrFail(self::VICTIM_ID);
        $this->assertSame('google', $account->login_medium);
        $this->assertSame('google-uid-1', $account->social_id);
    }

    public function test_the_approval_matches_the_provider_email_without_regard_to_letter_case(): void
    {
        $this->withSession($this->pendingSocialLogin('Victim@Example.test'))
            ->get($this->confirmationUrl(self::VICTIM_EMAIL, ['status' => 'approve']));

        $this->assertAuthenticatedAs(User::query()->findOrFail(self::VICTIM_ID), 'customer');
    }

    public function test_on_mysql_an_approval_finds_the_account_when_the_provider_writes_the_email_in_another_letter_case(): void
    {
        if (!$this->isRunningOnMySql()) {
            $this->markTestSkipped('MySQL only: its collation compares emails without regard to letter case, SQLite compares them exactly.');
        }

        $callback = $this->returnFromGoogle(email: 'Victim@Example.test');
        $this->get($this->approvalUrlOf($callback));

        $this->assertAuthenticatedAs(User::query()->findOrFail(self::VICTIM_ID), 'customer');
    }

    public function test_an_update_with_no_social_login_does_not_remove_the_email_of_an_account(): void
    {
        $accountBefore = $this->customerRow(id: self::VICTIM_ID);

        $this->post($this->updateUrl(), [
            'identity' => $this->identityFor(self::VICTIM_EMAIL),
            'phone' => '0500000099',
            'name' => 'Attacker',
        ]);

        $this->assertSame($accountBefore, $this->customerRow(id: self::VICTIM_ID));
    }

    public function test_an_update_with_a_social_login_for_another_email_changes_nothing(): void
    {
        $accountBefore = $this->customerRow(id: self::VICTIM_ID);

        $this->withSession($this->pendingSocialLogin('attacker@example.test'))
            ->post($this->updateUrl(), [
                'identity' => $this->identityFor(self::VICTIM_EMAIL),
                'phone' => '0500000099',
                'name' => 'Attacker',
            ])
            ->assertRedirect();

        $this->assertGuest('customer');
        $this->assertSame($accountBefore, $this->customerRow(id: self::VICTIM_ID));
        $this->assertSame(1, User::query()->count());
        $this->assertSame([translate('invalid_request')], $this->toastedMessages());
    }

    public function test_an_update_with_no_social_login_does_not_say_whether_a_phone_is_registered(): void
    {
        $this->post($this->updateUrl(), [
            'identity' => $this->identityFor('nobody@example.test'),
            'phone' => '0500000005',
            'name' => 'Attacker',
        ]);

        $this->assertSame([translate('invalid_request')], $this->toastedMessages());
    }

    public function test_the_provider_email_returned_to_this_session_can_finish_the_update(): void
    {
        DB::table('users')->where('id', self::VICTIM_ID)->update(['phone' => null]);

        $this->withSession($this->pendingSocialLogin(self::VICTIM_EMAIL))
            ->post($this->updateUrl(), [
                'identity' => $this->identityFor(self::VICTIM_EMAIL),
                'phone' => '0500000077',
                'name' => 'Layla New',
            ]);

        $this->assertDatabaseHas('users', ['email' => self::VICTIM_EMAIL, 'phone' => '0500000077', 'login_medium' => 'google']);
    }

    #[DataProvider('identitiesThatCarryNoEmail')]
    public function test_a_social_login_without_an_email_cannot_take_over_a_customer_without_one(string $identity): void
    {
        $this->insertPhoneOnlyCustomer();
        $accountBefore = $this->customerRow(id: self::PHONE_ONLY_ID);

        $this->withSession($this->pendingSocialLogin(email: null))
            ->post($this->updateUrl(), ['identity' => $identity, 'phone' => '0500000321', 'name' => 'Mallory']);

        $this->assertGuest('customer');
        $this->assertSame($accountBefore, $this->customerRow(id: self::PHONE_ONLY_ID));
    }

    /** @return array<string, array{string}> */
    public static function identitiesThatCarryNoEmail(): array
    {
        return [
            'an empty identity' => [''],
            'an identity that is not base64' => ['not base64!'],
        ];
    }

    public function test_a_social_login_without_an_email_cannot_be_approved_for_a_customer_without_one(): void
    {
        $this->insertPhoneOnlyCustomer();
        $accountBefore = $this->customerRow(id: self::PHONE_ONLY_ID);

        $response = $this->withSession($this->pendingSocialLogin(email: null))
            ->get(route('customer.auth.social-login-confirmation', ['identity' => '', 'status' => 'approve']));

        $response->assertRedirect(route('customer.auth.login'));
        $this->assertGuest('customer');
        $this->assertSame($accountBefore, $this->customerRow(id: self::PHONE_ONLY_ID));
    }

    public function test_a_provider_that_shares_an_email_sends_the_browser_to_a_confirmation_that_finishes_the_sign_up(): void
    {
        $callback = $this->returnFromGoogle(email: 'new.person@example.test', name: 'New Person');

        $callback->assertRedirectToRoute('customer.auth.social-login-confirmation', [
            'identity' => $this->identityFor('new.person@example.test'),
            'fullName' => base64_encode('New Person'),
        ]);
        $this->assertSame('new.person@example.test', session(self::PENDING_LOGIN_KEY)['email']);

        $this->get((string) $callback->headers->get('Location'))
            ->assertOk()
            ->assertSee($this->updateUrl(), escape: false)
            ->assertDontSee('Layla');

        $this->post($this->updateUrl(), [
            'identity' => $this->identityOfTheRedirect($callback),
            'phone' => '0555000111',
            'name' => 'New Person',
        ]);

        $this->assertAuthenticated('customer');
        $this->assertDatabaseHas('users', ['email' => 'new.person@example.test', 'phone' => '0555000111', 'login_medium' => 'google', 'social_id' => 'google-uid-1']);
    }

    public function test_an_existing_customer_can_link_the_provider_through_the_callback_and_the_approval(): void
    {
        $callback = $this->returnFromGoogle(email: self::VICTIM_EMAIL);

        $this->get((string) $callback->headers->get('Location'))
            ->assertOk()
            ->assertSee('Layla Victim');
        $this->get($this->approvalUrlOf($callback))->assertRedirect();

        $this->assertAuthenticatedAs(User::query()->findOrFail(self::VICTIM_ID), 'customer');
        $this->assertDatabaseHas('users', ['id' => self::VICTIM_ID, 'email' => self::VICTIM_EMAIL, 'login_medium' => 'google', 'social_id' => 'google-uid-1']);
    }

    public function test_an_existing_customer_who_says_it_is_not_them_gets_a_new_account_for_the_provider_email(): void
    {
        $callback = $this->returnFromGoogle(email: self::VICTIM_EMAIL, name: 'Somebody Else');

        $this->post($this->updateUrl(), [
            'identity' => $this->identityOfTheRedirect($callback),
            'phone' => '0500000088',
            'name' => 'Somebody Else',
        ]);

        $this->assertAuthenticated('customer');
        $this->assertNull(User::query()->findOrFail(self::VICTIM_ID)->email);
        $this->assertDatabaseHas('users', ['email' => self::VICTIM_EMAIL, 'phone' => '0500000088', 'login_medium' => 'google', 'social_id' => 'google-uid-1']);
        $this->assertSame(2, User::query()->count());
    }

    public function test_on_mysql_the_schema_is_not_rebuilt_outside_the_testing_environment(): void
    {
        if (!$this->isRunningOnMySql()) {
            $this->markTestSkipped('MySQL only: the in-memory SQLite database starts empty and is never dropped.');
        }

        $this->app['env'] = 'production';

        try {
            $this->buildCustomerLoginTables();
            $this->fail('the customer tables were dropped and rebuilt although the environment is not testing');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('Refusing to drop every table', $refusal->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => self::VICTIM_ID, 'email' => self::VICTIM_EMAIL]);
    }

    public function test_every_locale_defines_the_messages_of_the_social_login_page_and_its_refusals(): void
    {
        $locales = array_map('basename', glob(base_path('resources/lang/*'), GLOB_ONLYDIR));
        $this->assertNotSame([], $locales);

        foreach ($locales as $locale) {
            $messages = array_merge(
                include base_path("resources/lang/{$locale}/messages.php"),
                include base_path("resources/lang/{$locale}/new-messages.php"),
            );

            foreach (self::MESSAGE_KEYS as $key) {
                $this->assertArrayHasKey(key: $key, array: $messages, message: "the {$locale} locale does not define {$key}");
            }
        }
    }
}
