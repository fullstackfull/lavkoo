<?php

namespace Tests\Feature\Notifications;

use App\Models\Admin;
use App\Services\Notification\NotificationAccountBoard;
use App\Services\Notification\NotificationAttemptLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Storefront\OrderNotificationTestCase;
use Tests\Support\BuildsStaffScopeWorld;

/**
 * I1-09: every attempt to send one message is stored, a failure is retried within a stated budget, and the final failure reaches that
 * market's admin.
 *
 * None of the three existed. `PushNotificationTrait::sendNotificationToHttp()` caught `Exception` and returned false;
 * `EmailTemplateTrait`'s catch block was EMPTY. So a market whose mail password was wrong, or whose Firebase service account had been
 * revoked, sent nothing and told nobody -- no row, no screen, no counter -- and the platform's one retry mechanism (`$tries` on the mail
 * listeners) could not help, because the exception never left the trait for the queue to see. The only way to find out was for a customer
 * to say the message never arrived.
 *
 * Two properties are asserted as hard as the feature itself:
 *
 *  - **the budget ships at 1**, so the first test is that one attempt is made, one row is written and the caller is handed the same answer
 *    it was handed before -- the platform of today, with a record of it;
 *  - **the log holds no address.** A notification log is the table an operator reads most and the one least likely to be thought of as
 *    personal data, so the recipient is a SHA-256 digest and the provider's error has addresses and long digit runs taken out of it --
 *    because an SMTP or FCM error quotes the address it failed on, which would put back exactly what the digest keeps out.
 */
class NotificationAttemptsAreStoredAndRetriedTest extends OrderNotificationTestCase
{
    use BuildsStaffScopeWorld;

    private const MIGRATION = '2026_12_31_200000_create_notification_send_attempts_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->runTheAttemptLogMigration();
        app(NotificationAttemptLog::class)->forgetTheSchema();
        $this->answerThePushServiceWith(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/lavkoo-test/messages/1'])]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(NotificationAttemptLog::TABLE);
        app(NotificationAttemptLog::class)->forgetTheSchema();

        parent::tearDown();
    }

    // ---- the budget ships at one ------------------------------------------------------------------------------------------------------

    public function test_with_the_budget_at_one_a_push_is_sent_once_and_stored_once(): void
    {
        self::assertSame(1, (int) config('gcc.notifications.attempts'), 'the budget ships at one attempt');

        $this->queueTheOrderPlacedNotification($this->kuwaitiOrderWithARider());

        self::assertCount(1, $this->pushesSent(), 'one attempt, as the platform has always made');
        $attempts = $this->storedAttempts();
        self::assertCount(1, $attempts);
        self::assertSame(NotificationAttemptLog::CHANNEL_PUSH, $attempts[0]->channel);
        self::assertSame(NotificationAttemptLog::STATUS_SENT, $attempts[0]->status);
        self::assertSame(1, (int) $attempts[0]->attempt);
        self::assertSame(1, (int) $attempts[0]->is_final);
        self::assertNull($attempts[0]->provider_error);
    }

    public function test_before_the_migration_has_run_nothing_is_stored_and_nothing_breaks(): void
    {
        Schema::dropIfExists(NotificationAttemptLog::TABLE);
        app(NotificationAttemptLog::class)->forgetTheSchema();

        $this->queueTheOrderPlacedNotification($this->kuwaitiOrderWithARider());

        self::assertCount(1, $this->pushesSent(), 'the push still left the server');
        self::assertFalse(app(NotificationAttemptLog::class)->isRecording());
    }

    // ---- a failure is stored with what the provider said -------------------------------------------------------------------------------

    public function test_a_refused_push_is_stored_as_a_final_failure_with_the_providers_own_answer(): void
    {
        $this->makeTheePushServiceRefuse(body: ['error' => ['message' => 'SERVICE_ACCOUNT_DISABLED']]);

        $this->queueTheOrderPlacedNotification($this->kuwaitiOrderWithARider());

        $attempts = $this->storedAttempts();
        self::assertCount(1, $attempts);
        self::assertSame(NotificationAttemptLog::STATUS_FAILED, $attempts[0]->status);
        self::assertSame(1, (int) $attempts[0]->is_final, 'at a budget of one the first failure is the final one');
        self::assertStringContainsString('HTTP 401', (string) $attempts[0]->provider_error);
        self::assertStringContainsString('SERVICE_ACCOUNT_DISABLED', (string) $attempts[0]->provider_error);
    }

    public function test_the_market_of_the_message_is_stored_against_the_attempt(): void
    {
        $this->queueTheOrderPlacedNotification($this->kuwaitiOrderWithARider());

        self::assertSame('KW', $this->storedAttempts()[0]->storefront_country, 'the market the order was sold in, not the worker\'s');
    }

    // ---- the retry ---------------------------------------------------------------------------------------------------------------------

    public function test_a_raised_budget_retries_a_failed_push_and_stores_every_attempt(): void
    {
        config(['gcc.notifications.attempts' => 3]);
        $this->makeTheePushServiceRefuse(body: ['error' => ['message' => 'UNAVAILABLE']], status: 503);

        $this->queueTheOrderPlacedNotification($this->kuwaitiOrderWithARider());

        self::assertCount(3, $this->pushesSent(), 'the budget was spent');
        $attempts = $this->storedAttempts();
        self::assertSame([1, 2, 3], array_map(static fn (object $row): int => (int) $row->attempt, $attempts));
        self::assertSame([0, 0, 1], array_map(static fn (object $row): int => (int) $row->is_final, $attempts), 'only the last is final');
        self::assertSame(
            [NotificationAttemptLog::STATUS_FAILED, NotificationAttemptLog::STATUS_FAILED, NotificationAttemptLog::STATUS_FAILED],
            array_map(static fn (object $row): string => (string) $row->status, $attempts),
        );
    }

    public function test_a_retry_that_succeeds_stops_the_budget_there(): void
    {
        config(['gcc.notifications.attempts' => 3]);
        $this->answerThePushServiceWith(['fcm.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'UNAVAILABLE']], 503)
            ->push(['name' => 'projects/lavkoo-test/messages/1'], 200)]);

        $this->queueTheOrderPlacedNotification($this->kuwaitiOrderWithARider());

        self::assertCount(2, $this->pushesSent());
        $attempts = $this->storedAttempts();
        self::assertCount(2, $attempts);
        self::assertSame(NotificationAttemptLog::STATUS_FAILED, $attempts[0]->status);
        self::assertSame(0, (int) $attempts[0]->is_final, 'a failure a retry fixed is not a final failure, and is not shown as one');
        self::assertSame(NotificationAttemptLog::STATUS_SENT, $attempts[1]->status);
        self::assertSame(1, (int) $attempts[1]->is_final);
    }

    // ---- the log holds no address ------------------------------------------------------------------------------------------------------

    public function test_an_email_attempt_stores_a_digest_of_the_address_and_never_the_address(): void
    {
        $this->seedOrderPlacedEmailTemplate(titleInEnglish: 'Order placed', titleInArabic: 'تم الطلب');

        $this->placeOrderMailFor($this->kuwaitiOrderWithARider());

        $attempts = $this->storedAttempts();
        self::assertCount(1, $attempts);
        self::assertSame(NotificationAttemptLog::CHANNEL_MAIL, $attempts[0]->channel);
        self::assertSame('order-place', $attempts[0]->template_key);
        self::assertSame('customer', $attempts[0]->recipient_type);
        self::assertSame(hash('sha256', 'buyer@example.test'), $attempts[0]->recipient_digest);
        self::assertStringNotContainsString(
            'buyer@example.test',
            json_encode((array) $attempts[0], JSON_THROW_ON_ERROR),
            'no column of the row holds the address',
        );
    }

    public function test_an_address_or_a_number_the_provider_quoted_back_is_taken_out_of_the_stored_error(): void
    {
        app(NotificationAttemptLog::class)->record(
            channel: NotificationAttemptLog::CHANNEL_MAIL,
            templateKey: 'order-place',
            recipientType: 'customer',
            sentTo: 'buyer@example.test',
            status: NotificationAttemptLog::STATUS_FAILED,
            error: 'SMTP 550: mailbox buyer@example.test unknown; notify +966 50 123 4567',
        );

        $stored = (string) $this->storedAttempts()[0]->provider_error;

        self::assertStringNotContainsString('buyer@example.test', $stored);
        self::assertStringNotContainsString('966', $stored);
        self::assertStringContainsString('SMTP 550', $stored, 'what the provider SAID is kept: that is the point of storing it');
        self::assertStringContainsString('[address]', $stored);
        self::assertStringContainsString('[number]', $stored);
    }

    // ---- the final failure is visible to that market's admin ---------------------------------------------------------------------------

    public function test_the_final_failure_is_counted_and_listed_for_that_markets_admin(): void
    {
        $this->buildStaffAdminTables();
        $this->makeTheePushServiceRefuse(body: ['error' => ['message' => 'SERVICE_ACCOUNT_DISABLED']]);
        $this->queueTheOrderPlacedNotification($this->kuwaitiOrderWithARider());
        $this->signInAsStaff(1);

        $board = app(NotificationAccountBoard::class);

        self::assertSame(1, $board->row(country: 'KW')['channels'][NotificationAttemptLog::CHANNEL_PUSH]['undelivered']);
        self::assertTrue($board->row(country: 'KW')['recording_attempts']);
        $listed = $board->undeliveredMessages();
        self::assertCount(1, $listed);
        self::assertSame('KW', $listed[0]['storefront_country']);
    }

    public function test_an_employee_of_another_market_is_not_shown_it(): void
    {
        $this->buildStaffAdminTables();
        $this->runStaffScopeMigration();
        $this->makeTheePushServiceRefuse(body: ['error' => ['message' => 'SERVICE_ACCOUNT_DISABLED']]);
        $this->queueTheOrderPlacedNotification($this->kuwaitiOrderWithARider());

        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['AE']);
        $this->signInAsStaff(2);

        self::assertSame([], app(NotificationAccountBoard::class)->undeliveredMessages(), 'another company\'s failed messages are not theirs to read');

        // The unrestricted twin, because a refusal test alone cannot tell a guard from an empty table.
        $this->giveCountries(adminId: 2, countries: ['KW']);
        \App\Services\Staff\StaffCountryScope::forgetTheSchema();
        $this->signInAsStaff(2);

        self::assertCount(1, app(NotificationAccountBoard::class)->undeliveredMessages());
        $this->rollBackStaffScopeMigration();
    }

    // ---- helpers -----------------------------------------------------------------------------------------------------------------------

    /** An order really sold in Kuwait, with a rider holding a device token: the shape that makes the platform send one push. */
    private function kuwaitiOrderWithARider(): \App\Models\Order
    {
        $this->switchStorefrontsOn();

        return $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'delivery_man_id' => $this->deliveryMan(id: 1, token: 'kw-rider', language: 'sa')->id,
            'deliveryman_charge' => self::CHARGE_STORED,
        ]);
    }

    /** @return list<object> every stored attempt, oldest first */
    private function storedAttempts(): array
    {
        return DB::table(NotificationAttemptLog::TABLE)->orderBy('id')->get()->all();
    }

    private function makeTheePushServiceRefuse(array $body, int $status = 401): void
    {
        $this->answerThePushServiceWith(['fcm.googleapis.com/*' => Http::response($body, $status)]);
    }

    /**
     * Replaces the HTTP client wholesale before faking, because `Http::fake()` MERGES its stubs and the FIRST match wins.
     *
     * The base case registers a 200 for `fcm.googleapis.com/*`, so a second `Http::fake()` asking for a 401 is never reached and the
     * refusal reads as a success -- which is exactly how this test first passed for the wrong reason.
     */
    private function answerThePushServiceWith(array $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory($this->app['events']));
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'minted-access-token'])] + $stubs);
    }

    private function runTheAttemptLogMigration(): void
    {
        Schema::dropIfExists(NotificationAttemptLog::TABLE);
        (require database_path('migrations/' . self::MIGRATION))->up();
    }
}
