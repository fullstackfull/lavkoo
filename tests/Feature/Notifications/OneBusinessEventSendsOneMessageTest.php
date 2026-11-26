<?php

namespace Tests\Feature\Notifications;

use App\Events\OrderStatusEvent;
use App\Models\Order;
use App\Services\Notification\MessageIdentity;
use App\Services\Notification\NotificationAttemptLog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Storefront\OrderNotificationTestCase;

/**
 * I1-09's last criterion: a replay of the same business event gives one push and one e-mail per recipient.
 *
 * **What this platform did, and what pinned it.** Nothing in a notification said which message it was. `OrderStatusNotificationWaitsOnTheQueueTest`
 * fired one status change twice and got two identical pushes, and asserted the absence of any key in the payload; the abandoned-cart
 * reminder had a ledger of its own and no other channel had anything. So a queue worker running a job twice, a webhook arriving twice or
 * an operator pressing the button again told the customer twice, and no reader downstream could tell that from two real status changes.
 *
 * **What closes it.** The message's identity ({@see MessageIdentity}) is CLAIMED in `notification_send_attempts` before the send, through
 * the unique key `(idempotency_key, attempt)` that `2027_01_09_100000_add_idempotency_key_to_notification_send_attempts` adds. The claim
 * is an INSERT and not a read, because two workers pass a read together and both send -- the engine is the arbiter, and the test below
 * that holds the claim by hand is the proof that the gate reads the table rather than its own memory.
 *
 * **It ships OFF, and the first test is that off nothing changed.** `gcc.notifications.idempotency` is false and the migration is
 * additive, so there are three independent ways for this to be dormant -- the switch, the table, the column -- and two of them are
 * asserted here.
 *
 * **The cost of turning it on, characterised and NOT hidden.** A notification's identity is (order, recipient, which message) and
 * deliberately not the rendered text: `textVariableDataFormat()` renders `{time}` as `now()->diffForHumans()`, so hashing the body would
 * make every replay a new message and switch the mechanism off without saying so. The price is that an order put BACK into a status it
 * has already been notified about notifies nobody a second time, which is pinned below as a measured consequence rather than left for an
 * operator to discover.
 *
 * Engine: both. Run on SQLite and on MariaDB 10.11.14 (`phpunit.mysql.xml`, database `pharmacy_testing_id`) -- and the duplicate-key
 * behaviour the whole mechanism rests on is asserted against whichever engine is running, because a unique key is the one thing SQLite
 * and MariaDB are not free to answer differently and both must be shown to refuse.
 */
class OneBusinessEventSendsOneMessageTest extends OrderNotificationTestCase
{
    private const ATTEMPT_LOG_MIGRATION = '2026_12_31_200000_create_notification_send_attempts_table.php';

    private const IDEMPOTENCY_MIGRATION = '2027_01_09_100000_add_idempotency_key_to_notification_send_attempts.php';

    /** A second status of the same order, so "a different message about one order" is a real status key and not an invented one. */
    private const A_SECOND_STATUS = 'order_refunded_message';

    private const RIDER = 7705;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTheAttemptLog();
        $this->answerThePushServiceWith(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/lavkoo-test/messages/1'])]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(NotificationAttemptLog::TABLE);
        app(NotificationAttemptLog::class)->forgetTheSchema();

        parent::tearDown();
    }

    // ---- the switch ships off ----------------------------------------------------------------------------------------------------------

    public function test_the_switch_ships_off_and_off_a_replay_is_two_pushes_exactly_as_the_platform_sends_them_today(): void
    {
        self::assertFalse((bool) config('gcc.notifications.idempotency'), 'the switch ships off');

        $order = $this->aKuwaitiOrderOfTheHouse();

        $this->tellTheCustomer($order, status: 'pending');
        $this->tellTheCustomer($order, status: 'pending');

        self::assertCount(2, $this->pushesSent(), 'the platform of today: nothing suppresses the second send of one status change');
        self::assertCount(2, $this->storedAttempts(), 'and both are stored, as they were before');
        self::assertSame([null, null], array_map(
            static fn (object $row): ?string => $row->idempotency_key,
            $this->storedAttempts(),
        ), 'no message is given an identity while the switch is off');
    }

    public function test_with_the_switch_on_but_the_column_not_there_a_replay_is_still_two_pushes(): void
    {
        $this->claimEveryMessagesIdentity();
        $this->takeTheIdempotencyColumnAway();

        $order = $this->aKuwaitiOrderOfTheHouse();

        $this->tellTheCustomer($order, status: 'pending');
        $this->tellTheCustomer($order, status: 'pending');

        self::assertCount(2, $this->pushesSent(), 'an installation that has not run the migration is the platform of today');
        self::assertCount(2, $this->storedAttempts(), 'and its attempts are stored as they always were');
    }

    // ---- one business event, one message -----------------------------------------------------------------------------------------------

    public function test_a_replay_of_one_status_change_is_one_push_and_one_row(): void
    {
        $this->claimEveryMessagesIdentity();
        $order = $this->aKuwaitiOrderOfTheHouse();

        $this->tellTheCustomer($order, status: 'pending');
        $this->tellTheCustomer($order, status: 'pending');

        self::assertCount(1, $this->pushesSent(), 'the replay sent nothing');

        $attempts = $this->storedAttempts();
        self::assertCount(1, $attempts, 'and wrote nothing: the claim it could not take is the record of the first send');
        self::assertSame(NotificationAttemptLog::STATUS_SENT, $attempts[0]->status);
        self::assertSame(1, (int) $attempts[0]->attempt);
        self::assertNotNull($attempts[0]->idempotency_key, 'the row carries the identity the claim was taken on');
        self::assertSame('KW', $attempts[0]->storefront_country, 'the market of the order, as it was before');
    }

    public function test_a_different_status_of_the_same_order_is_a_different_message_and_is_sent(): void
    {
        $this->claimEveryMessagesIdentity();
        $order = $this->aKuwaitiOrderOfTheHouse();

        $this->tellTheCustomer($order, status: 'pending');
        $this->tellTheCustomer($order, status: self::A_SECOND_STATUS);

        self::assertCount(2, $this->pushesSent(), 'two real status changes are two messages');
        $keys = array_map(static fn (object $row): string => (string) $row->idempotency_key, $this->storedAttempts());
        self::assertCount(2, array_unique($keys), 'and two identities: which change it is about is part of the key');
    }

    public function test_each_recipient_of_one_status_change_is_told_once_and_a_replay_tells_none_of_them_again(): void
    {
        $this->claimEveryMessagesIdentity();
        $order = $this->aKuwaitiOrderOfAShopWithARider();

        foreach ([1, 2] as $ignored) {
            event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));
            event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: $order));
            event(new OrderStatusEvent(key: 'new_order_message', type: 'seller', order: $order));
        }

        self::assertSame(
            ['customer-device', 'rider-device', 'seller-device'],
            array_map(static fn (mixed $push): string => (string) $push['message']['token'], $this->pushesSent()),
            'one push per recipient and not one push per event: the recipient is part of the identity',
        );
    }

    public function test_a_push_that_names_no_order_is_a_replay_of_nothing_and_is_always_sent(): void
    {
        $this->claimEveryMessagesIdentity();
        $this->aKuwaitiOrderOfTheHouse();

        $broadcast = new class {
            use \App\Traits\PushNotificationTrait;

            public function announce(): void
            {
                $this->sendPushNotificationToTopic(['title' => 'Eid sale', 'description' => '20% off', 'type' => 'general'], topic: 'kw');
            }
        };

        $broadcast->announce();
        $broadcast->announce();

        self::assertCount(2, $this->pushesSent(), 'a marketing broadcast names no business event, so nothing suppresses it');
        self::assertSame([null, null], array_map(
            static fn (object $row): ?string => $row->idempotency_key,
            $this->storedAttempts(),
        ), 'and it is given no identity at all, rather than one nothing can tell apart');
    }

    // ---- the claim is the engine's, not this process's ---------------------------------------------------------------------------------

    public function test_a_send_is_dropped_although_the_claim_is_held_by_another_process(): void
    {
        $this->claimEveryMessagesIdentity();
        $order = $this->aKuwaitiOrderOfTheHouse();

        // What a second worker would have left behind: the claim row, written before its send, with no answer from the provider yet.
        // Nothing in this process knows about it, so a gate reading its own memory would send; a gate reading the table does not.
        $this->holdTheClaimOfTheCustomerPush($order, status: 'pending', asStatus: NotificationAttemptLog::STATUS_PENDING);

        $this->tellTheCustomer($order, status: 'pending');

        self::assertCount(0, $this->pushesSent(), 'the message another worker is already sending is not sent again');
        self::assertCount(1, $this->storedAttempts(), 'and the other worker\'s claim row is left exactly as it was');
    }

    /**
     * The race itself, reproduced rather than reasoned about: the competing worker's claim lands AFTER this process has read the table
     * and BEFORE its own insert, which is the one window in which two sends could both be allowed.
     *
     * `DB::listen` fires once a query has run, so inserting the competing row from inside it puts it exactly in that window. This is the
     * only test here that exercises the refused insert and the re-read behind it; every other replay in this file is caught by the read,
     * which is why the unique key needs its own proof below as well.
     */
    public function test_the_worker_that_loses_the_race_for_a_claim_drops_its_send(): void
    {
        $this->claimEveryMessagesIdentity();
        $order = $this->aKuwaitiOrderOfTheHouse();

        $raced = false;
        DB::listen(function (QueryExecuted $query) use ($order, &$raced): void {
            if (!$raced && str_contains($query->sql, NotificationAttemptLog::IDEMPOTENCY_COLUMN)) {
                $raced = true;
                $this->holdTheClaimOfTheCustomerPush($order, status: 'pending', asStatus: NotificationAttemptLog::STATUS_PENDING);
            }
        });

        $this->tellTheCustomer($order, status: 'pending');

        self::assertTrue($raced, 'the window was reached, or this test proves nothing');
        self::assertCount(0, $this->pushesSent(), 'the loser reads again, finds the winner holding the claim and sends nothing');
        self::assertCount(1, $this->storedAttempts(), 'and leaves one claim row, not two');
    }

    /**
     * What the unique key itself does, and what it does NOT.
     *
     * Removing it leaves every other replay test in this file GREEN, because a sequential replay is caught by the read that precedes the
     * insert. What it catches is the test above: two processes that pass the read together. Measured both ways rather than asserted --
     * with `unique` swapped for a plain `index`, this test is the only one that reddens.
     */
    public function test_the_engine_refuses_a_second_claim_of_one_identity(): void
    {
        $this->claimEveryMessagesIdentity();

        $row = [
            'channel' => NotificationAttemptLog::CHANNEL_PUSH,
            'template_key' => 'order',
            'status' => NotificationAttemptLog::STATUS_PENDING,
            'attempt' => 1,
            'is_final' => false,
            'idempotency_key' => str_repeat('a', 64),
            'created_at' => now(),
        ];

        DB::table(NotificationAttemptLog::TABLE)->insert($row);

        // The whole mechanism rests on this, so it is asserted on whichever engine is running rather than reasoned about: two workers
        // claiming one identity are two inserts, and the key is what makes exactly one of them win.
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table(NotificationAttemptLog::TABLE)->insert($row);
    }

    public function test_two_null_keys_do_not_collide_so_every_message_with_no_subject_is_still_stored(): void
    {
        $this->claimEveryMessagesIdentity();

        foreach ([1, 2] as $ignored) {
            app(NotificationAttemptLog::class)->record(
                channel: NotificationAttemptLog::CHANNEL_MAIL,
                templateKey: 'forgot-password',
                recipientType: 'customer',
                sentTo: 'buyer@example.test',
                status: NotificationAttemptLog::STATUS_SENT,
            );
        }

        self::assertCount(2, $this->storedAttempts(), 'a unique key over NULLs refuses nothing on any engine this platform runs on');
    }

    // ---- a message that was never delivered is not lost ---------------------------------------------------------------------------------

    public function test_a_replay_sends_again_when_every_earlier_attempt_failed_and_continues_the_attempt_series(): void
    {
        $this->claimEveryMessagesIdentity();
        $order = $this->aKuwaitiOrderOfTheHouse();

        $this->makeThePushServiceRefuse(body: ['error' => ['message' => 'UNAVAILABLE']], status: 503);
        $this->tellTheCustomer($order, status: 'pending');

        $failed = $this->storedAttempts();
        self::assertCount(1, $failed);
        self::assertSame(NotificationAttemptLog::STATUS_FAILED, $failed[0]->status);
        self::assertSame(1, (int) $failed[0]->is_final, 'at a budget of one the first failure is the final one');

        $this->answerThePushServiceWith(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/lavkoo-test/messages/2'])]);
        $this->tellTheCustomer($order, status: 'pending');

        $attempts = $this->storedAttempts();
        self::assertCount(2, $attempts, 'a message whose only attempt failed was never delivered, and the replay is its second chance');
        self::assertSame(2, (int) $attempts[1]->attempt, 'the series continues rather than starting again, which the key would refuse');
        self::assertSame(NotificationAttemptLog::STATUS_SENT, $attempts[1]->status);
        self::assertSame($attempts[0]->idempotency_key, $attempts[1]->idempotency_key, 'both attempts are of one message');
    }

    public function test_a_delivered_message_is_not_sent_again_by_a_replay_that_follows_a_failure(): void
    {
        $this->claimEveryMessagesIdentity();
        $order = $this->aKuwaitiOrderOfTheHouse();

        $this->makeThePushServiceRefuse(body: ['error' => ['message' => 'UNAVAILABLE']], status: 503);
        $this->tellTheCustomer($order, status: 'pending');

        $this->answerThePushServiceWith(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/lavkoo-test/messages/2'])]);
        $this->tellTheCustomer($order, status: 'pending');
        $this->tellTheCustomer($order, status: 'pending');

        self::assertCount(1, $this->pushesSent(), 'the delivered attempt closes the message: only the failure reopened it');
        self::assertCount(2, $this->storedAttempts());
    }

    public function test_a_raised_budget_stores_every_attempt_of_one_claimed_message_under_its_own_identity(): void
    {
        $this->claimEveryMessagesIdentity();
        config()->set('gcc.notifications.attempts', 3);
        $order = $this->aKuwaitiOrderOfTheHouse();
        $this->makeThePushServiceRefuse(body: ['error' => ['message' => 'UNAVAILABLE']], status: 503);

        $this->tellTheCustomer($order, status: 'pending');

        $attempts = $this->storedAttempts();
        self::assertCount(3, $attempts, 'the budget was spent and every attempt is stored, as it is without a key');
        self::assertSame([1, 2, 3], array_map(static fn (object $row): int => (int) $row->attempt, $attempts));
        self::assertSame([0, 0, 1], array_map(static fn (object $row): int => (int) $row->is_final, $attempts), 'only the last is final');
        self::assertCount(1, array_unique(array_map(static fn (object $row): string => (string) $row->idempotency_key, $attempts)));
    }

    // ---- what turning it on costs, measured --------------------------------------------------------------------------------------------

    /**
     * The price of the identity being (order, recipient, which message) rather than the rendered text, written down as an assertion so
     * that an operator raising the switch is choosing something that is known.
     *
     * An admin who sets an order back to `pending` after it has been `order_refunded_message` tells the customer nothing: as far as the
     * key is concerned that message has already been sent. Hashing the body instead would have sent it -- and would also have sent every
     * true replay, because the default text of several of these messages renders `{time}` as `now()->diffForHumans()`.
     */
    public function test_an_order_returned_to_a_status_it_was_already_notified_about_sends_nothing_a_second_time(): void
    {
        $this->claimEveryMessagesIdentity();
        $order = $this->aKuwaitiOrderOfTheHouse();

        $this->tellTheCustomer($order, status: 'pending');
        $this->tellTheCustomer($order, status: self::A_SECOND_STATUS);
        $this->tellTheCustomer($order, status: 'pending');

        self::assertCount(2, $this->pushesSent(), 'at most once per status: the second pending is indistinguishable from a replay');
    }

    // ---- the e-mail channel ------------------------------------------------------------------------------------------------------------

    public function test_a_replay_of_the_order_placed_email_sends_one_message(): void
    {
        $this->claimEveryMessagesIdentity();
        $this->seedOrderPlacedEmailTemplate(titleInEnglish: 'Order placed', titleInArabic: 'تم الطلب');
        Mail::fake();
        $order = $this->aKuwaitiOrderOfTheHouse();

        $this->placeOrderMailFor($order);
        $this->placeOrderMailFor($order);

        Mail::assertSentCount(1);
        self::assertCount(1, $this->storedAttempts());
    }

    public function test_two_password_reset_emails_are_two_messages_because_neither_is_about_an_order(): void
    {
        $this->claimEveryMessagesIdentity();
        $this->seedEmailTemplate(templateName: 'forgot-password', userType: 'customer', titleInEnglish: 'Reset', titleInArabic: 'إعادة');
        Mail::fake();

        foreach ([1, 2] as $ignored) {
            app(ResetMailSender::class)->send();
        }

        Mail::assertSentCount(2, 'a customer who asks for a second code is asking for a second message, not replaying the first');
    }

    // ---- the world ---------------------------------------------------------------------------------------------------------------------

    private function aKuwaitiOrderOfTheHouse(): Order
    {
        $this->switchStorefrontsOn();

        return $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'seller_is' => 'admin',
            'order_status' => 'pending',
        ]);
    }

    private function aKuwaitiOrderOfAShopWithARider(): Order
    {
        $this->switchStorefrontsOn();
        $rider = $this->deliveryMan(id: self::RIDER, token: 'rider-device', language: 'sa');

        return $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'seller_id' => $this->sellerWhoReads('sa')->id,
            'seller_is' => 'seller',
            'delivery_man_id' => $rider->id,
            'deliveryman_charge' => self::CHARGE_STORED,
            'order_status' => 'pending',
        ]);
    }

    private function tellTheCustomer(Order $order, string $status): void
    {
        event(new OrderStatusEvent(key: $status, type: 'customer', order: $order));
    }

    /** The claim row another worker would have left: the identity this platform derives for that customer push. */
    private function holdTheClaimOfTheCustomerPush(Order $order, string $status, string $asStatus): void
    {
        $key = MessageIdentity::of(
            channel: NotificationAttemptLog::CHANNEL_PUSH,
            templateKey: 'order',
            recipientType: 'device',
            sentTo: (string) $order->customer?->cm_firebase_token,
            about: MessageIdentity::subjectOf([
                'order_id' => (string) $order->id,
                'message_key' => $status,
                'type' => 'order',
            ]),
        );

        self::assertNotNull($key, 'the fixture must name a subject, or this test proves nothing');

        DB::table(NotificationAttemptLog::TABLE)->insert([
            'channel' => NotificationAttemptLog::CHANNEL_PUSH,
            'template_key' => 'order',
            'recipient_type' => 'device',
            'status' => $asStatus,
            'attempt' => 1,
            'is_final' => false,
            'idempotency_key' => $key,
            'created_at' => now(),
        ]);
    }

    private function claimEveryMessagesIdentity(): void
    {
        config()->set('gcc.notifications.idempotency', true);
        app(NotificationAttemptLog::class)->forgetTheSchema();
    }

    /** @return list<object> every stored attempt, oldest first */
    private function storedAttempts(): array
    {
        return DB::table(NotificationAttemptLog::TABLE)->orderBy('id')->get()->all();
    }

    private function makeThePushServiceRefuse(array $body, int $status = 401): void
    {
        $this->answerThePushServiceWith(['fcm.googleapis.com/*' => Http::response($body, $status)]);
    }

    /**
     * Replaces the HTTP client wholesale before faking, because `Http::fake()` MERGES its stubs and the FIRST match wins -- the same trap
     * `NotificationAttemptsAreStoredAndRetriedTest` records, where a 401 asked for after a 200 was never reached.
     */
    private function answerThePushServiceWith(array $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory($this->app['events']));
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'minted-access-token'])] + $stubs);
    }

    private function buildTheAttemptLog(): void
    {
        Schema::dropIfExists(NotificationAttemptLog::TABLE);
        (require database_path('migrations/' . self::ATTEMPT_LOG_MIGRATION))->up();
        (require database_path('migrations/' . self::IDEMPOTENCY_MIGRATION))->up();
        app(NotificationAttemptLog::class)->forgetTheSchema();

        self::assertTrue(
            Schema::hasColumn(NotificationAttemptLog::TABLE, NotificationAttemptLog::IDEMPOTENCY_COLUMN),
            'the migration under test ran',
        );
    }

    /** The installation that has the attempt log and has not run the migration that gives a message an identity. */
    private function takeTheIdempotencyColumnAway(): void
    {
        (require database_path('migrations/' . self::IDEMPOTENCY_MIGRATION))->down();
        app(NotificationAttemptLog::class)->forgetTheSchema();

        self::assertFalse(Schema::hasColumn(NotificationAttemptLog::TABLE, NotificationAttemptLog::IDEMPOTENCY_COLUMN));
    }
}

/** One of the platform's e-mails that is about no order at all, sent the way every listener sends one. */
final class ResetMailSender
{
    use \App\Traits\EmailTemplateTrait;

    public function send(): void
    {
        $this->sendingMail(sendMailTo: 'buyer@example.test', userType: 'customer', templateName: 'forgot-password', data: [
            'subject' => 'reset', 'title' => 'reset', 'userName' => 'Noura', 'userType' => 'customer',
            'templateName' => 'forgot-password',
        ]);
    }
}
