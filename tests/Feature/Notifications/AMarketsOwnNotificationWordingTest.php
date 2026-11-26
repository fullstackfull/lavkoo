<?php

namespace Tests\Feature\Notifications;

use App\Events\OrderStatusEvent;
use App\Models\NotificationMessage;
use App\Models\Order;
use App\Models\Translation;
use App\Services\Notification\MarketNotificationText;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Storefront\OrderNotificationTestCase;

/**
 * I1-03's one MISSING criterion: a KW order's push text comes from the KW override, else the recipient-language text, else the global
 * message.
 *
 * **What was wrong.** `notification_messages` holds one row per `(user_type, key)` for the whole installation, with its translations by
 * locale beside it, and nothing on it names a market. So every market told its customers the same thing in the same words -- and the
 * wording a Kuwaiti customer is told an order is on its way in is not a translation question. The brand, the delivery promise and the
 * legal phrasing of that sentence differ per country, and no number of locale rows gives a market its own words.
 *
 * **The order the requirement states, and the LANGUAGE outranks the market at the second step.** A market that has written an override and
 * not translated it into this recipient's language does not send them another language's words: the global row's translation does. The
 * market's own untranslated words come next, before the platform's, which is a refinement of "then the global text" and is asserted here
 * rather than left to be inferred from the code.
 *
 * **What is asserted is the text the DEVICE would have been sent**, read off the FCM request at the HTTP boundary through the real
 * `OrderStatusEvent` and the real listener -- not the resolver's return value. A resolver that answers correctly and a sender that does not
 * ask it look identical from inside the resolver.
 *
 * **Saudi Arabia cannot be moved by this mechanism**, and that is a property and not a side effect: the global row already IS the default
 * market's text, so the default market is read as the global row even when somebody writes an SA override. Asserted, because it is the one
 * thing an operator might do by mistake.
 *
 * Engine: SQLite (PHP 8.3.6) and MariaDB 10.11.14 (`phpunit.mysql.xml`, database `pharmacy_testing_id`).
 */
class AMarketsOwnNotificationWordingTest extends OrderNotificationTestCase
{
    private const MIGRATION = '2027_01_10_100000_add_storefront_to_notification_messages_table.php';

    /** The global row this fixture already holds, from the base test case: 'Order {orderId} is pending', customer, with no Arabic. */
    private const GLOBAL_KEY = 'order_pending_message';

    private const KUWAITI_WORDING = 'Order {orderId} is on its way from our Kuwait pharmacy';

    private const KUWAITI_WORDING_IN_ARABIC = 'طلبك {orderId} في الطريق من صيدليتنا في الكويت';

    private const GLOBAL_WORDING_IN_ARABIC = 'طلبك {orderId} قيد الانتظار';

    protected function setUp(): void
    {
        parent::setUp();

        $this->runTheMarketWordingMigration();
        $this->answerThePushServiceWith(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/lavkoo-test/messages/1'])]);
    }

    // ---- the switch ships off ----------------------------------------------------------------------------------------------------------

    public function test_the_switch_ships_off_and_off_an_override_is_not_read_at_all(): void
    {
        self::assertFalse((bool) config('gcc.notifications.market_templates'), 'the switch ships off');

        $order = $this->aKuwaitiOrder();
        $this->giveTheMarketItsOwnWording(country: 'KW', message: self::KUWAITI_WORDING);

        $this->tellTheCustomer($order);

        self::assertSame(
            'Order ' . self::KW_ORDER . ' is pending',
            $this->theBodySent(),
            'the platform of today: the one global row, whatever a market has written',
        );
    }

    /**
     * With the switch off this costs ONE config read and asks the database nothing, which is why the pinned query counts of the push
     * senders do not move ({@see OrderStatusNotificationWaitsOnTheQueueTest}, whose figures are measurements and not expectations).
     * `isEnabled()` reads the config before it asks the schema, so the short circuit is what keeps a `hasColumn` off every push.
     */
    public function test_off_the_resolver_asks_the_database_nothing(): void
    {
        $statements = [];
        \Illuminate\Support\Facades\DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        self::assertNull(app(MarketNotificationText::class)->of(key: self::GLOBAL_KEY, userType: 'customer', language: 'en', country: 'KW'));
        self::assertSame([], $statements, 'not even the schema is asked while the switch is off');
    }

    public function test_before_the_migration_has_run_the_switch_changes_nothing(): void
    {
        $this->readEachMarketsOwnWording();
        $order = $this->aKuwaitiOrder();
        $this->giveTheMarketItsOwnWording(country: 'KW', message: self::KUWAITI_WORDING);
        $this->takeTheMarketColumnAway();

        $this->tellTheCustomer($order);

        self::assertSame('Order ' . self::KW_ORDER . ' is pending', $this->theBodySent());
    }

    // ---- the market's own words --------------------------------------------------------------------------------------------------------

    public function test_a_kuwaiti_orders_push_comes_from_the_kuwaiti_override(): void
    {
        $this->readEachMarketsOwnWording();
        $order = $this->aKuwaitiOrder();
        $this->giveTheMarketItsOwnWording(country: 'KW', message: self::KUWAITI_WORDING);

        $this->tellTheCustomer($order);

        self::assertSame('Order ' . self::KW_ORDER . ' is on its way from our Kuwait pharmacy', $this->theBodySent());
    }

    public function test_the_kuwaiti_override_is_read_in_the_language_the_recipient_reads(): void
    {
        $this->readEachMarketsOwnWording();
        $order = $this->aKuwaitiOrderWhoseCustomerReadsArabic();
        $kuwaiti = $this->giveTheMarketItsOwnWording(country: 'KW', message: self::KUWAITI_WORDING);
        $this->translate(row: $kuwaiti, locale: 'sa', value: self::KUWAITI_WORDING_IN_ARABIC);

        $this->tellTheCustomer($order);

        self::assertSame(str_replace('{orderId}', (string) self::KW_ORDER, self::KUWAITI_WORDING_IN_ARABIC), $this->theBodySent());
    }

    public function test_an_untranslated_override_does_not_send_another_language_so_the_global_translation_is_used(): void
    {
        $this->readEachMarketsOwnWording();
        $order = $this->aKuwaitiOrderWhoseCustomerReadsArabic();
        $this->giveTheMarketItsOwnWording(country: 'KW', message: self::KUWAITI_WORDING);
        $this->translate(row: $this->theGlobalRow(), locale: 'sa', value: self::GLOBAL_WORDING_IN_ARABIC);

        $this->tellTheCustomer($order);

        self::assertSame(
            str_replace('{orderId}', (string) self::KW_ORDER, self::GLOBAL_WORDING_IN_ARABIC),
            $this->theBodySent(),
            'the language outranks the market at the second step: an Arabic reader is not sent the market\'s English',
        );
    }

    public function test_with_no_language_matching_anywhere_the_markets_own_words_beat_the_platforms(): void
    {
        $this->readEachMarketsOwnWording();
        $order = $this->aKuwaitiOrderWhoseCustomerReadsArabic();
        $this->giveTheMarketItsOwnWording(country: 'KW', message: self::KUWAITI_WORDING);

        $this->tellTheCustomer($order);

        self::assertSame(
            'Order ' . self::KW_ORDER . ' is on its way from our Kuwait pharmacy',
            $this->theBodySent(),
            'once no language matches at all, the market\'s own words are better than the platform\'s',
        );
    }

    public function test_a_market_with_no_override_reads_the_global_row(): void
    {
        $this->readEachMarketsOwnWording();
        $order = $this->aKuwaitiOrder();
        $this->giveTheMarketItsOwnWording(country: 'AE', message: 'Order {orderId} is on its way from Dubai');

        $this->tellTheCustomer($order);

        self::assertSame('Order ' . self::KW_ORDER . ' is pending', $this->theBodySent(), 'another market\'s words are not this market\'s');
    }

    // ---- Saudi Arabia cannot be moved by this -----------------------------------------------------------------------------------------

    public function test_the_default_market_is_read_as_the_global_row_even_when_an_sa_override_is_written(): void
    {
        $this->readEachMarketsOwnWording();
        $order = $this->aSaudiOrder();
        $this->giveTheMarketItsOwnWording(country: 'SA', message: 'Order {orderId} is on its way, written as an SA override');

        $this->tellTheCustomer($order);

        self::assertSame(
            'Order ' . self::SA_ORDER . ' is pending',
            $this->theBodySent(),
            'the global row IS the default market\'s text, so an SA override is not a second one',
        );
    }

    // ---- a market may switch a message off for itself -----------------------------------------------------------------------------------

    public function test_a_market_that_switches_a_message_off_for_itself_sends_nothing(): void
    {
        $this->readEachMarketsOwnWording();
        $order = $this->aKuwaitiOrder();
        $this->giveTheMarketItsOwnWording(country: 'KW', message: self::KUWAITI_WORDING, status: 0);

        $this->tellTheCustomer($order);

        self::assertCount(0, $this->pushesSent(), 'the override is the whole of that market\'s decision about that message');
    }

    public function test_a_market_whose_message_is_on_still_sends_while_the_global_row_is_off(): void
    {
        $this->readEachMarketsOwnWording();
        $order = $this->aKuwaitiOrder();
        $this->theGlobalRow()->forceFill(['status' => 0])->save();
        $this->giveTheMarketItsOwnWording(country: 'KW', message: self::KUWAITI_WORDING);

        $this->tellTheCustomer($order);

        self::assertSame(
            'Order ' . self::KW_ORDER . ' is on its way from our Kuwait pharmacy',
            $this->theBodySent(),
            'a market that has written its own message has made its own decision about sending it',
        );
    }

    public function test_a_market_with_no_override_still_follows_the_global_switch(): void
    {
        $this->readEachMarketsOwnWording();
        $order = $this->aKuwaitiOrder();
        $this->theGlobalRow()->forceFill(['status' => 0])->save();

        $this->tellTheCustomer($order);

        self::assertCount(0, $this->pushesSent(), 'nothing about this mechanism changes what a market without an override does');
    }

    // ---- the resolver's own rules ------------------------------------------------------------------------------------------------------

    public function test_the_lowest_id_decides_when_an_installation_already_holds_two_rows_for_one_market(): void
    {
        $this->readEachMarketsOwnWording();
        $this->serveFrom(country: 'KW');
        $this->giveTheMarketItsOwnWording(country: 'KW', message: 'the first row written');
        $this->giveTheMarketItsOwnWording(country: 'KW', message: 'the second row written');

        self::assertSame(
            'the first row written',
            app(MarketNotificationText::class)->of(key: self::GLOBAL_KEY, userType: 'customer', language: 'en'),
            'there is no unique key on this table and the migration deliberately adds none, so the read is deterministic instead',
        );
    }

    public function test_nothing_is_answered_while_storefronts_are_off(): void
    {
        $this->readEachMarketsOwnWording();
        $this->giveTheMarketItsOwnWording(country: 'KW', message: self::KUWAITI_WORDING);
        config(['gcc.storefronts.enabled' => false]);

        self::assertNull(
            app(MarketNotificationText::class)->of(key: self::GLOBAL_KEY, userType: 'customer', language: 'en', country: 'KW'),
            'a platform with one storefront has one set of words',
        );
    }

    // ---- the world ---------------------------------------------------------------------------------------------------------------------

    private function aKuwaitiOrder(): Order
    {
        $this->switchStorefrontsOn();

        return $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->aCustomerWhoReads(language: 'en')->id,
            'seller_is' => 'admin',
            'order_status' => 'pending',
        ]);
    }

    private function aKuwaitiOrderWhoseCustomerReadsArabic(): Order
    {
        $this->switchStorefrontsOn();

        return $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'seller_is' => 'admin',
            'order_status' => 'pending',
        ]);
    }

    private function aSaudiOrder(): Order
    {
        $this->switchStorefrontsOn();

        return $this->sellOrderIn(country: 'SA', orderId: self::SA_ORDER, orderColumns: [
            'customer_id' => $this->aCustomerWhoReads(language: 'en')->id,
            'seller_is' => 'admin',
            'order_status' => 'pending',
        ]);
    }

    private function aCustomerWhoReads(string $language): \App\Models\User
    {
        $customer = $this->customerWhoReadsArabic();
        $customer->forceFill(['app_language' => $language])->save();

        return $customer;
    }

    private function tellTheCustomer(Order $order): void
    {
        event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));
    }

    /** The body of the one push that left the server, read off the FCM request rather than out of the resolver. */
    private function theBodySent(): string
    {
        $pushes = $this->pushesSent();
        self::assertCount(1, $pushes, 'exactly one push left the server');

        return (string) $pushes[0]['message']['data']['body'];
    }

    private function giveTheMarketItsOwnWording(string $country, string $message, int $status = 1): NotificationMessage
    {
        $row = NotificationMessage::create([
            'user_type' => 'customer',
            'key' => self::GLOBAL_KEY,
            'message' => $message,
            'status' => $status,
        ]);

        // The column is not on the model's fillable list, because nothing in the application writes it yet: the admin screen per country
        // is the rest of I1-03. Written the way a migration-aware writer would.
        \Illuminate\Support\Facades\DB::table('notification_messages')
            ->where('id', $row->id)
            ->update([MarketNotificationText::COLUMN => $country]);

        return $row->refresh();
    }

    private function theGlobalRow(): NotificationMessage
    {
        return NotificationMessage::query()
            ->where('key', self::GLOBAL_KEY)
            ->where('user_type', 'customer')
            ->whereNull(MarketNotificationText::COLUMN)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function translate(NotificationMessage $row, string $locale, string $value): void
    {
        Translation::create([
            'translationable_type' => NotificationMessage::class,
            'translationable_id' => $row->id,
            'locale' => $locale,
            'key' => 'message',
            'value' => $value,
        ]);
    }

    private function readEachMarketsOwnWording(): void
    {
        config(['gcc.notifications.market_templates' => true]);
        app(MarketNotificationText::class)->forgetTheSchema();
    }

    private function serveFrom(string $country): void
    {
        $this->switchStorefrontsOn();
        $this->app->instance(
            \App\Services\Storefront\StorefrontContext::class,
            \App\Services\Storefront\StorefrontResolver::fromConfig()->context(explicitChoice: $country),
        );
    }

    private function runTheMarketWordingMigration(): void
    {
        (require database_path('migrations/' . self::MIGRATION))->up();
        app(MarketNotificationText::class)->forgetTheSchema();

        self::assertTrue(Schema::hasColumn('notification_messages', MarketNotificationText::COLUMN), 'the migration under test ran');
    }

    private function takeTheMarketColumnAway(): void
    {
        Schema::table('notification_messages', static function (Blueprint $table): void {
            $table->dropIndex('notif_messages_market_type_key_idx');
            $table->dropColumn(MarketNotificationText::COLUMN);
        });
        app(MarketNotificationText::class)->forgetTheSchema();
    }

    private function answerThePushServiceWith(array $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory($this->app['events']));
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'minted-access-token'])] + $stubs);
    }
}
