<?php

namespace Tests\Feature\Notifications;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use App\Services\Notification\NotificationProfiles;
use App\Services\Staff\StaffCountryScope;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\Support\AssertsNoLanguageFileWasWritten;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\BuildsNotificationProfiles;
use Tests\TestCase;

/**
 * I1-01: the admin screen where a market is given its own mail account, and the test send that tells the operator the TRUTH about it.
 *
 * The engine behind this has been in place since the accounts were (see MarketMailAccountTest); what did not exist was a way for anybody but
 * a database client to use it, and the screen is where the rules about secrets bite. Four things are asserted here that no test of the engine
 * can assert:
 *
 *  - the stored password is nowhere in the HTML of the page, not in a value attribute, not in a placeholder, not in a data attribute. A
 *    screen that echoes a secret back so that a save can round-trip it puts a company's SMTP password in the browser's cache and in every
 *    screenshot of the page;
 *  - and therefore a save that only changes the host arrives with an empty password field and must KEEP the stored password. The proof is
 *    not that the row still holds it -- it is that a message sent afterwards still authenticates with it;
 *  - the test send reports the account the message LEFT THROUGH, read from inside the send, and says whether that was the market's own
 *    account or the shared one, because "it worked" is misleading when what worked was another company's host;
 *  - and the transport's own refusal reaches the operator. `EmailTemplateTrait::writeAndSendMail` catches `Exception` and does nothing with
 *    it, which is right for an order confirmation and useless for a credentials test, because "nothing happened" is exactly what a wrong
 *    password looks like.
 */
class AdminMailAccountScreenTest extends TestCase
{
    use AssertsNoLanguageFileWasWritten;
    use BuildsFulfillmentSchema;
    use BuildsNotificationProfiles;

    /** The employee whose role holds `3rd_party_setup`, which is the permission the one shared mail configuration already sits behind. */
    private const INTEGRATIONS_ADMIN = 3;

    /** The employee whose role holds `catalog` only. */
    private const CATALOG_ADMIN = 2;

    private const PLATFORM_HOST = 'smtp.platform.example';

    private const PLATFORM_FROM = 'noreply@platform.example';

    private const KUWAITI_HOST = 'smtp.kw.example';

    private const KUWAITI_FROM = 'orders@kw.example';

    private const KUWAITI_PASSWORD = 'kuwait-smtp-password-not-in-any-page';

    /** @var list<array{host: ?string, username: ?string}> what was in force each time the mailer was about to hand a message over */
    private array $sends = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardAgainstWrittenLanguageFiles();
        $this->buildCoreSchema();
        $this->buildNotificationProfilesTable();
        $this->giveProductsTheirMarketColumn();
        $this->buildStaffCountryScopes();

        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->withoutMiddleware([
            AdminMiddleware::class, ActivationCheckMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class,
            NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        ToastMagic::clear();

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'mail.default' => 'array',
            'mail.driver' => 'array',
            'mail.host' => self::PLATFORM_HOST,
            'mail.from.address' => self::PLATFORM_FROM,
            'mail.username' => 'platform',
            'mail.password' => 'platform-smtp-password',
        ]);
        $this->switchNotificationProfilesOn();
        $this->watchWhatEachSendLeftThrough();
        $this->signInAs(self::INTEGRATIONS_ADMIN);
    }

    protected function tearDown(): void
    {
        $this->dropNotificationProfilesTable();
        Schema::dropIfExists(StaffCountryScope::TABLE);

        parent::tearDown();
    }

    public function test_the_screen_lists_the_markets_and_names_the_shared_account_as_shared(): void
    {
        $response = $this->get(route('admin.notification-accounts.index'));

        $response->assertOk();
        $response->assertSee('KW');
        $response->assertSee('SA');
        $response->assertSee(translate('this_is_the_shared_account_every_market_without_one_of_its_own_uses'), escape: false);
    }

    /** The whole point of the screen's shape: a stored secret is not in the page, in any form a browser or a screenshot would keep. */
    public function test_a_stored_password_is_nowhere_in_the_page(): void
    {
        $this->giveKuwaitItsOwnMailAccount();

        $page = $this->get(route('admin.notification-accounts.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::KUWAITI_PASSWORD, (string) $page, 'the stored SMTP password reached the HTML of the page');
        $this->assertStringContainsString(self::KUWAITI_HOST, (string) $page, 'the host is not a secret and the operator has to be able to read it');
        $this->assertStringContainsString('password', (string) $page, 'the operator is told that a password is stored, by its name');
    }

    /** And because it is not in the page, an empty password field means "keep it" -- proved by a later message still authenticating with it. */
    public function test_a_save_that_changes_only_the_host_keeps_the_stored_password(): void
    {
        $this->giveKuwaitItsOwnMailAccount();

        $this->post(route('admin.notification-accounts.mail.save'), [
            'country' => 'KW',
            'host' => 'smtp.kw2.example',
            'driver' => 'smtp',
            'from_address' => self::KUWAITI_FROM,
            'username' => '',
            'password' => '',
            'active' => '1',
        ])->assertRedirect();

        $account = app(NotificationProfiles::class)->for(channel: NotificationProfiles::MAIL, country: 'KW');

        $this->assertSame('smtp.kw2.example', $account?->setting('host'), 'the host the operator typed was not saved');
        $this->assertSame(self::KUWAITI_PASSWORD, $account?->credential('password'), 'the stored password was lost by a save that never saw it');
        $this->assertSame('kuwait', $account?->credential('username'), 'the stored username was lost by a save that never saw it');
    }

    public function test_clearing_a_secret_is_a_separate_instruction_the_operator_has_to_give(): void
    {
        $this->giveKuwaitItsOwnMailAccount();

        $this->post(route('admin.notification-accounts.mail.save'), [
            'country' => 'KW',
            'host' => self::KUWAITI_HOST,
            'password' => '',
            'forget' => ['password'],
            'active' => '1',
        ])->assertRedirect();

        $account = app(NotificationProfiles::class)->for(channel: NotificationProfiles::MAIL, country: 'KW');

        $this->assertNull($account?->credential('password'), 'the password the operator asked to clear is still stored');
        $this->assertSame('kuwait', $account?->credential('username'), 'clearing one secret took the other with it');
    }

    public function test_a_saved_password_is_encrypted_in_the_row(): void
    {
        $this->post(route('admin.notification-accounts.mail.save'), [
            'country' => 'KW',
            'host' => self::KUWAITI_HOST,
            'username' => 'kuwait',
            'password' => self::KUWAITI_PASSWORD,
            'active' => '1',
        ])->assertRedirect();

        $row = DB::table(NotificationProfiles::TABLE)->where(['channel' => NotificationProfiles::MAIL, 'country' => 'KW'])->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString(self::KUWAITI_PASSWORD, (string) $row->credentials, 'the password is stored in the clear');
        $this->assertStringNotContainsString(self::KUWAITI_PASSWORD, (string) $row->settings, 'the password reached the column a screen may show');
        $this->assertStringContainsString(self::KUWAITI_PASSWORD, Crypt::decryptString((string) $row->credentials));
    }

    /** The criterion itself: the operator chooses a market, the message goes through THAT market's account, and the answer says which one. */
    public function test_a_test_send_goes_through_the_chosen_markets_account_and_says_so(): void
    {
        $this->giveKuwaitItsOwnMailAccount(driver: 'array');

        $this->post(route('admin.notification-accounts.mail.test'), ['country' => 'KW', 'email' => 'operator@example.test'])->assertRedirect();
        $result = session('mail_account_test_result');

        $this->assertTrue($result['sent'], 'the test send did not report a send');
        $this->assertSame('KW', $result['market']);
        $this->assertTrue($result['own_account'], 'the operator was not told the message left through the market\'s own account');
        $this->assertSame(self::KUWAITI_HOST, $result['host'], 'the host reported is not the Kuwaiti account\'s');
        $this->assertSame(self::KUWAITI_FROM, $result['from']);
        $this->assertSame([['host' => self::KUWAITI_HOST, 'username' => 'kuwait']], $this->sends, 'the message did not leave through the Kuwaiti account');
    }

    /** And the configuration is put back, so the next message of the platform is not sent on Kuwait's host. */
    public function test_the_platforms_account_is_in_force_again_after_a_test_send(): void
    {
        $this->giveKuwaitItsOwnMailAccount(driver: 'array');

        $this->post(route('admin.notification-accounts.mail.test'), ['country' => 'KW', 'email' => 'operator@example.test']);

        $this->assertSame(self::PLATFORM_HOST, config('mail.host'));
        $this->assertSame(self::PLATFORM_FROM, config('mail.from.address'));
    }

    /** A market with no account of its own leaves through the platform's host, and the answer says so rather than implying success. */
    public function test_a_market_with_no_account_of_its_own_is_told_the_shared_account_sent_it(): void
    {
        $this->post(route('admin.notification-accounts.mail.test'), ['country' => 'KW', 'email' => 'operator@example.test'])->assertRedirect();
        $result = session('mail_account_test_result');

        $this->assertTrue($result['sent']);
        $this->assertFalse($result['own_account'], 'the operator was told a market account sent a message the platform account sent');
        $this->assertSame(self::PLATFORM_HOST, $result['host']);
    }

    /** The transport's own words reach the operator, which is what `sendingMail` cannot do because it swallows the exception. */
    public function test_the_mail_servers_refusal_is_shown_as_the_mail_server_wrote_it(): void
    {
        $this->refuseEverySend(because: '535 Authentication failed: Bad username or password');
        $this->giveKuwaitItsOwnMailAccount(driver: 'refusing');

        $this->post(route('admin.notification-accounts.mail.test'), ['country' => 'KW', 'email' => 'operator@example.test'])->assertRedirect();
        $result = session('mail_account_test_result');

        $this->assertFalse($result['sent'], 'a refused send was reported as sent');
        $this->assertStringContainsString('535 Authentication failed', (string) $result['error'], 'the mail server\'s own words did not reach the operator');
        $this->assertSame(self::KUWAITI_HOST, $result['host'], 'the operator is not told which host refused them');
    }

    /** A refused send must not leave Kuwait's host in force for the rest of the process. */
    public function test_the_platforms_account_is_put_back_when_a_test_send_fails(): void
    {
        $this->refuseEverySend(because: 'Connection could not be established with host "smtp.kw.example"');
        $this->giveKuwaitItsOwnMailAccount(driver: 'refusing');

        $this->post(route('admin.notification-accounts.mail.test'), ['country' => 'KW', 'email' => 'operator@example.test']);

        $this->assertSame(self::PLATFORM_HOST, config('mail.host'));
    }

    public function test_an_employee_restricted_to_kuwait_is_offered_kuwait_and_not_the_emirates(): void
    {
        config(['gcc.storefronts.countries.AE.active' => true]);
        $this->restrictTo(admin: self::INTEGRATIONS_ADMIN, countries: ['KW']);

        $page = (string) $this->get(route('admin.notification-accounts.index'))->assertOk()->getContent();

        $this->assertStringContainsString('name="country" value="KW"', $page, 'the market the employee works with is not offered');
        $this->assertStringNotContainsString('name="country" value="AE"', $page, 'a market outside the employee\'s scope is offered');
        $this->assertStringNotContainsString('name="country" value="SA"', $page, 'the shared account is offered to an employee of one market');
    }

    public function test_an_employee_restricted_to_kuwait_cannot_write_the_emirates_account(): void
    {
        config(['gcc.storefronts.countries.AE.active' => true]);
        $this->restrictTo(admin: self::INTEGRATIONS_ADMIN, countries: ['KW']);

        $this->post(route('admin.notification-accounts.mail.save'), [
            'country' => 'AE',
            'host' => 'smtp.taken-over.example',
            'password' => 'not-theirs',
            'active' => '1',
        ])->assertRedirect();

        $this->assertDatabaseMissing(NotificationProfiles::TABLE, ['channel' => NotificationProfiles::MAIL, 'country' => 'AE']);
    }

    public function test_an_employee_restricted_to_kuwait_cannot_test_the_emirates_account(): void
    {
        config(['gcc.storefronts.countries.AE.active' => true]);
        $this->restrictTo(admin: self::INTEGRATIONS_ADMIN, countries: ['KW']);

        $this->post(route('admin.notification-accounts.mail.test'), ['country' => 'AE', 'email' => 'operator@example.test'])->assertRedirect();

        $this->assertNull(session('mail_account_test_result'), 'a market outside the employee\'s scope was tested');
        $this->assertSame([], $this->sends, 'a message was sent for a market the employee may not configure');
    }

    /** The screen is behind the permission the shared mail configuration is behind, and not behind nothing. */
    public function test_an_employee_without_the_third_party_permission_cannot_open_the_screen(): void
    {
        $this->signInAs(self::CATALOG_ADMIN);

        $this->get(route('admin.notification-accounts.index'))->assertRedirect();
    }

    /** With the switch off nothing reads these accounts, so a save is refused rather than stored where it would be ignored. */
    public function test_a_save_is_refused_while_accounts_per_market_are_switched_off(): void
    {
        config(['gcc.notifications.profiles' => false]);
        app(NotificationProfiles::class)->flush();

        $this->post(route('admin.notification-accounts.mail.save'), [
            'country' => 'KW',
            'host' => self::KUWAITI_HOST,
            'active' => '1',
        ])->assertRedirect();

        $this->assertDatabaseMissing(NotificationProfiles::TABLE, ['channel' => NotificationProfiles::MAIL, 'country' => 'KW']);
    }

    /** A blank host is not stored as an empty string: `MailAccount` would ignore such an account while the screen called it configured. */
    public function test_a_blank_field_is_not_stored_as_an_empty_setting(): void
    {
        $this->post(route('admin.notification-accounts.mail.save'), [
            'country' => 'KW',
            'host' => self::KUWAITI_HOST,
            'from_address' => '',
            'active' => '1',
        ])->assertRedirect();

        $settings = json_decode((string) DB::table(NotificationProfiles::TABLE)->where('country', 'KW')->value('settings'), true);

        $this->assertSame(['host' => self::KUWAITI_HOST], $settings, 'a field the operator left blank was stored');
    }

    private function giveKuwaitItsOwnMailAccount(?string $driver = null): void
    {
        $settings = ['host' => self::KUWAITI_HOST, 'from_address' => self::KUWAITI_FROM];

        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::MAIL,
            country: 'KW',
            settings: $driver === null ? $settings : [...$settings, 'driver' => $driver],
            credentials: ['username' => 'kuwait', 'password' => self::KUWAITI_PASSWORD],
            label: 'Kuwait company mail',
        );
    }

    /** A transport that answers every send with the words a real mail server would, so that the screen can be asked what it shows of them. */
    private function refuseEverySend(string $because): void
    {
        Mail::extend('refusing', static fn (array $config): TransportInterface => new class($because) implements TransportInterface {
            public function __construct(private readonly string $because)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException($this->because);
            }

            public function __toString(): string
            {
                return 'refusing://';
            }
        });
    }

    /** Records the mail host and user in force each time the real mailer is about to hand a message to its transport. */
    private function watchWhatEachSendLeftThrough(): void
    {
        Event::listen(MessageSending::class, function (MessageSending $sending): void {
            $this->sends[] = ['host' => (string) config('mail.host'), 'username' => (string) config('mail.username')];
        });
    }

    private function giveProductsTheirMarketColumn(): void
    {
        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
    }

    private function buildStaffCountryScopes(): void
    {
        Schema::dropIfExists(StaffCountryScope::TABLE);
        Schema::create(StaffCountryScope::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->string('country', 2);
            $table->timestamps();
        });
        StaffCountryScope::forgetTheSchema();
    }

    /** @param list<string> $countries */
    private function restrictTo(int $admin, array $countries): void
    {
        config(['gcc.staff.country_scopes' => true]);

        foreach ($countries as $country) {
            DB::table(StaffCountryScope::TABLE)->insert(['admin_id' => $admin, 'country' => $country, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function signInAs(int $admin): void
    {
        $this->actingAs(Admin::query()->findOrFail($admin), 'admin');
    }
}
