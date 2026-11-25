<?php

namespace Tests\Feature\Notifications;

use App\Services\Notification\MailAccount;
use App\Services\Notification\NotificationProfiles;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Monolog\LogRecord;
use RuntimeException;
use Tests\Feature\Storefront\OrderNotificationTestCase;
use Tests\Support\BuildsNotificationProfiles;
use Tests\Support\CapturesLogRecords;

/**
 * I1-01: a market's e-mail leaves through that market's own mail account.
 *
 * `MailConfigServiceProvider::boot()` replaces the mail configuration once, at boot, from the one `mail_config` setting. A queue worker boots
 * once and then sends one market's messages after another, so the account a message left through was never the message's -- it was the
 * worker's. On a platform where each market is a separate company (GCC-13) that means a Kuwaiti order's e-mail left through the Saudi
 * company's host and from a Saudi address: a message signed by a company that did not sell the order, sent from a domain that does not
 * belong to it, which a recipient's mail server is entitled to reject.
 *
 * Every answer here is read AT THE MOMENT OF THE SEND, out of the `MessageSending` event the real mailer fires before it hands the message
 * to its transport: the host that was in force then, and the address on the message as the mailer was given it. An account that was applied
 * and then put back correctly looks identical from outside the send, and which host the message actually went to is the whole question.
 */
class MarketMailAccountTest extends OrderNotificationTestCase
{
    use BuildsNotificationProfiles;
    use CapturesLogRecords;

    private const PLATFORM_HOST = 'smtp.platform.example';

    private const PLATFORM_FROM = 'noreply@platform.example';

    private const PLATFORM_PASSWORD = 'platform-smtp-password';

    private const KUWAITI_HOST = 'smtp.kw.example';

    private const KUWAITI_FROM = 'orders@kw.example';

    private const KUWAITI_PASSWORD = 'kuwait-smtp-password-not-in-any-log';

    private const EMIRATI_ORDER = 9103;

    /** @var list<array{host: ?string, from: ?string}> what was in force each time the mailer was asked to send */
    private array $sends = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildNotificationProfilesTable();
        $this->captureTheEmailsTheMailerRenders();
        config([
            'mail.driver' => self::CAPTURING_MAILER,
            'mail.host' => self::PLATFORM_HOST,
            'mail.from.address' => self::PLATFORM_FROM,
            'mail.username' => 'platform',
            'mail.password' => self::PLATFORM_PASSWORD,
        ]);
        $this->seedOrderPlacedEmailTemplate(titleInEnglish: 'Your order is placed', titleInArabic: 'تم إنشاء طلبك', design: 'order-place');
        $this->watchWhatEachSendLeftThrough();
    }

    protected function tearDown(): void
    {
        $this->dropNotificationProfilesTable();

        parent::tearDown();
    }

    /** The first test of the switch: with it off a market's row is not read and every message leaves through the platform's account. */
    public function test_while_the_switch_is_off_a_markets_own_account_is_not_read(): void
    {
        $this->giveKuwaitItsOwnMailAccount();
        $this->switchStorefrontsOn();

        $this->assertNull(app(NotificationProfiles::class)->for(channel: NotificationProfiles::MAIL, country: 'KW'));
        $this->assertSame(self::PLATFORM_HOST, $this->sendTheOrderMailOf(country: 'KW')['host']);
    }

    public function test_an_email_about_a_kuwaiti_order_leaves_through_the_kuwaiti_hosts_and_from_address(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnMailAccount();
        $this->switchStorefrontsOn();

        $this->assertSame(
            ['host' => self::KUWAITI_HOST, 'from' => self::KUWAITI_FROM],
            $this->sendTheOrderMailOf(country: 'KW'),
        );
    }

    public function test_an_email_about_an_order_of_a_market_with_no_account_of_its_own_leaves_through_the_platforms(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnMailAccount();
        $this->switchStorefrontsOn();

        $this->assertSame(
            ['host' => self::PLATFORM_HOST, 'from' => self::PLATFORM_FROM],
            $this->sendTheOrderMailOf(country: 'AE'),
        );
    }

    /** A market with no account of its own uses the SHARED row where there is one: the fallback this requirement asks for. */
    public function test_a_market_with_no_account_of_its_own_uses_the_shared_account(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnMailAccount();
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::MAIL,
            country: NotificationProfiles::PLATFORM_WIDE,
            settings: ['host' => 'smtp.shared.example', 'from_address' => 'noreply@shared.example'],
            credentials: ['username' => 'shared', 'password' => 'shared-password'],
        );
        $this->switchStorefrontsOn();

        $this->assertSame('smtp.shared.example', $this->sendTheOrderMailOf(country: 'AE')['host']);
        $this->assertSame(self::KUWAITI_HOST, $this->sendTheOrderMailOf(country: 'KW')['host'], "and the market's own still wins over the shared one");
    }

    /**
     * The criterion that names the worker. The request that queues the message is being served from the Emirati market and the ORDER was sold
     * in Kuwait, so an account read from the request, from the session or at boot would be the wrong one.
     */
    public function test_a_queued_listener_uses_the_account_of_the_market_recorded_on_the_order(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnMailAccount();
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::MAIL,
            country: 'AE',
            settings: ['host' => 'smtp.ae.example', 'from_address' => 'orders@ae.example'],
        );
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('AE');

        $this->assertSame(self::KUWAITI_HOST, $this->sendTheOrderMailOf(country: 'KW')['host']);
    }

    /** Two markets' messages sent by one worker, one after the other: neither inherits the other's account. */
    public function test_two_markets_messages_sent_by_one_worker_do_not_inherit_each_others_account(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnMailAccount();
        $this->switchStorefrontsOn();

        $kuwait = $this->sendTheOrderMailOf(country: 'KW');
        $emirates = $this->sendTheOrderMailOf(country: 'AE');

        $this->assertSame(self::KUWAITI_HOST, $kuwait['host']);
        $this->assertSame(self::PLATFORM_HOST, $emirates['host']);
        $this->assertSame(self::PLATFORM_HOST, config('mail.host'), 'and the worker is left as it booted');
        $this->assertSame(self::PLATFORM_PASSWORD, config('mail.password'));
    }

    public function test_the_platforms_account_is_put_back_when_the_send_fails(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnMailAccount();
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('KW');

        try {
            app(MailAccount::class)->forThisMessage(callback: static fn () => throw new RuntimeException('the host refused'));
            $this->fail('the failure was swallowed');
        } catch (RuntimeException) {
            $this->assertSame(self::PLATFORM_HOST, config('mail.host'));
            $this->assertSame(self::PLATFORM_PASSWORD, config('mail.password'));
        }
    }

    /** An account that names a host but no from address keeps the platform's address: a domain is likeliest to accept the address it knows. */
    public function test_an_account_that_names_no_from_address_keeps_the_platforms(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveMarketItsOwnAccount(channel: NotificationProfiles::MAIL, country: 'KW', settings: ['host' => self::KUWAITI_HOST]);
        $this->switchStorefrontsOn();

        $this->assertSame(
            ['host' => self::KUWAITI_HOST, 'from' => self::PLATFORM_FROM],
            $this->sendTheOrderMailOf(country: 'KW'),
        );
    }

    /** An account with no host at all is not an account to send through: the platform's stands, and the message is still sent. */
    public function test_an_account_that_names_no_host_changes_nothing(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveMarketItsOwnAccount(channel: NotificationProfiles::MAIL, country: 'KW', settings: ['from_address' => self::KUWAITI_FROM]);
        $this->switchStorefrontsOn();

        $this->assertSame(
            ['host' => self::PLATFORM_HOST, 'from' => self::PLATFORM_FROM],
            $this->sendTheOrderMailOf(country: 'KW'),
        );
    }

    public function test_an_account_that_is_not_active_is_not_used(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::MAIL,
            country: 'KW',
            settings: ['host' => self::KUWAITI_HOST, 'from_address' => self::KUWAITI_FROM],
            active: false,
        );
        $this->switchStorefrontsOn();

        $this->assertSame(self::PLATFORM_HOST, $this->sendTheOrderMailOf(country: 'KW')['host']);
    }

    /** The market is compared byte for byte, as the column is declared, so a lower-case row is not that market's account. */
    public function test_a_market_written_in_lower_case_is_not_read_as_that_market(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::MAIL,
            country: 'kw',
            settings: ['host' => self::KUWAITI_HOST, 'from_address' => self::KUWAITI_FROM],
        );
        $this->switchStorefrontsOn();

        $this->assertNull(app(NotificationProfiles::class)->for(channel: NotificationProfiles::MAIL, country: 'KW'));
    }

    public function test_a_password_is_stored_encrypted_and_the_row_does_not_hold_it(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnMailAccount();

        $stored = (string) DB::table(NotificationProfiles::TABLE)->where('country', 'KW')->value('credentials');

        $this->assertStringNotContainsString(self::KUWAITI_PASSWORD, $stored, 'the password is not in the row');
        $this->assertSame(self::KUWAITI_PASSWORD, data_get(json_decode(Crypt::decryptString($stored), true), 'password'));
    }

    public function test_what_a_screen_may_show_names_the_secrets_it_holds_and_no_value(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnMailAccount();

        $visible = app(NotificationProfiles::class)->for(channel: NotificationProfiles::MAIL, country: 'KW')->visible();

        $this->assertSame(['username', 'password'], $visible['credentials_present']);
        $this->assertStringNotContainsString(self::KUWAITI_PASSWORD, json_encode($visible, JSON_THROW_ON_ERROR));
        $this->assertSame(['host' => self::KUWAITI_HOST, 'from_address' => self::KUWAITI_FROM], $visible['settings']);
    }

    public function test_a_password_does_not_reach_the_log_when_an_account_cannot_be_read(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnMailAccount();
        $log = $this->captureLog();
        DB::table(NotificationProfiles::TABLE)->where('country', 'KW')->update(['credentials' => 'not-decryptable']);
        app(NotificationProfiles::class)->flush();
        $this->switchStorefrontsOn();

        $this->sendTheOrderMailOf(country: 'KW');

        $written = implode("\n", array_map(
            static fn (LogRecord $record): string => $record->message . json_encode($record->context),
            $log->getRecords(),
        ));
        $this->assertStringNotContainsString(self::KUWAITI_PASSWORD, $written);
        $this->assertStringContainsString('could not be read', $written, 'and the operator is told the account was not readable');
    }

    /** An account whose credentials cannot be read is not a reason to send through another market's: the market's host stands and the message leaves. */
    public function test_an_account_that_cannot_be_read_keeps_its_own_host_and_still_sends(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnMailAccount();
        DB::table(NotificationProfiles::TABLE)->where('country', 'KW')->update(['credentials' => 'not-decryptable']);
        app(NotificationProfiles::class)->flush();
        $this->switchStorefrontsOn();

        $sent = $this->sendTheOrderMailOf(country: 'KW');

        $this->assertSame(self::KUWAITI_HOST, $sent['host'], 'the host is the market\'s, and a host is not a secret');
        $this->assertCount(1, $this->sends, 'and the message is still sent');
    }

    private function giveKuwaitItsOwnMailAccount(): void
    {
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::MAIL,
            country: 'KW',
            settings: ['host' => self::KUWAITI_HOST, 'from_address' => self::KUWAITI_FROM],
            credentials: ['username' => 'kuwait', 'password' => self::KUWAITI_PASSWORD],
            label: 'Kuwait company mail',
        );
    }

    /** Records the mail host in force and the sender on the message, each time the real mailer is about to hand a message to its transport. */
    private function watchWhatEachSendLeftThrough(): void
    {
        Event::listen(MessageSending::class, function (MessageSending $sending): void {
            $from = $sending->message->getFrom();

            $this->sends[] = ['host' => (string) config('mail.host'), 'from' => $from === [] ? null : $from[0]->getAddress()];
        });
    }

    /**
     * Sends the order-placed e-mail of an order really sold in one market, through the real queued listener, and answers what that one send
     * left through.
     *
     * @return array{host: ?string, from: ?string}
     */
    private function sendTheOrderMailOf(string $country): array
    {
        $before = count($this->sends);
        $order = $this->sellOrderIn(
            country: $country,
            orderId: match ($country) {'KW' => self::KW_ORDER, 'AE' => self::EMIRATI_ORDER, default => self::SA_ORDER},
            orderColumns: ['customer_id' => $this->makeCustomer(['app_language' => 'en', 'f_name' => 'Noura', 'l_name' => 'Al Sabah'])->id],
        );

        $this->placeOrderMailFor($order);

        $this->assertCount($before + 1, $this->sends, 'exactly one e-mail left the server');

        return $this->sends[$before];
    }
}
