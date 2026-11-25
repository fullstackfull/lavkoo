<?php

namespace Tests\Feature\Notifications;

use App\Events\OrderStatusEvent;
use App\Services\Notification\NotificationProfiles;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Storefront\OrderNotificationTestCase;
use Tests\Support\BuildsNotificationProfiles;

/**
 * I1-02: a push about a market's order is sent with that market's own Firebase credentials.
 *
 * `sendNotificationToHttp` read the one `push_notification_key` setting, so every market's pushes went out on one service account: one quota,
 * one audit trail, one set of credentials to rotate for six companies.
 *
 * The project id is in the FCM URL itself, so what is asserted here is the address the request actually went to, and the access token is
 * minted from the credentials the request used -- the token cache is keyed on `client_email`, so a second account gets a token of its own
 * without anything else changing.
 *
 * **What a second credential does NOT buy.** An FCM device token belongs to the Firebase PROJECT of the app build that registered it, so a
 * market's own credentials reach that market's devices only where that market's app build uses that market's project. Until the owner answers
 * that (A1-17, Q-18, Q-32), a per-market credential for the same project separates quota and audit, not devices. That is why no test here
 * claims a device was reached: they claim which account the request was made with.
 */
class MarketPushAccountTest extends OrderNotificationTestCase
{
    use BuildsNotificationProfiles;

    private const KUWAITI_PROJECT = 'lavkoo-kw';

    private const KUWAITI_CLIENT = 'push@lavkoo-kw.example';

    /** The shared project, as OrderNotificationTestCase configures the platform. */
    private const PLATFORM_PROJECT = 'lavkoo-test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildNotificationProfilesTable();
    }

    protected function tearDown(): void
    {
        $this->dropNotificationProfilesTable();

        parent::tearDown();
    }

    /** The first test of the switch: with it off, a market's credentials are not read and every push goes out on the shared project. */
    public function test_while_the_switch_is_off_every_push_goes_out_on_the_shared_project(): void
    {
        $this->giveKuwaitItsOwnPushAccount();
        $this->switchStorefrontsOn();

        $this->assertSame(self::PLATFORM_PROJECT, $this->projectThePushWentTo(country: 'KW'));
    }

    public function test_a_push_about_a_kuwaiti_order_is_sent_with_the_kuwaiti_credentials(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnPushAccount();
        $this->switchStorefrontsOn();

        $this->assertSame(self::KUWAITI_PROJECT, $this->projectThePushWentTo(country: 'KW'));
    }

    public function test_a_push_about_an_order_of_a_market_with_no_account_of_its_own_goes_out_on_the_shared_project(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnPushAccount();
        $this->switchStorefrontsOn();

        $this->assertSame(self::PLATFORM_PROJECT, $this->projectThePushWentTo(country: 'AE'));
    }

    /**
     * The worker criterion again, for the push channel: the request is being served from the Emirati market and the ORDER was sold in Kuwait,
     * so credentials read from the request would be the wrong ones.
     */
    public function test_a_queued_push_uses_the_credentials_of_the_market_recorded_on_the_order(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnPushAccount();
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('AE');

        $this->assertSame(self::KUWAITI_PROJECT, $this->projectThePushWentTo(country: 'KW'));
    }

    /**
     * **Push falls back where SMS and WhatsApp deliberately do not, and the reason is the device token.**
     *
     * A market whose account names no service account is sent on the platform's project, because a device token only works with the project
     * it was registered in: refusing would silence that market's pushes without any safety gained, since the account that would be safer
     * does not exist. An SMS gateway or a WhatsApp inbox is the opposite case -- the shared one would really send, from another company's
     * sender number -- which is why those refuse instead.
     */
    public function test_a_market_whose_account_names_no_service_account_is_sent_on_the_platforms_project(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveMarketItsOwnAccount(channel: NotificationProfiles::PUSH, country: 'KW', settings: ['note' => 'not configured yet']);
        $this->switchStorefrontsOn();

        $this->assertSame(self::PLATFORM_PROJECT, $this->projectThePushWentTo(country: 'KW'));
    }

    /** Each account mints its own access token, because the token cache is keyed on the client it was minted for. */
    public function test_each_account_mints_a_token_of_its_own(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnPushAccount();
        $this->switchStorefrontsOn();

        $this->projectThePushWentTo(country: 'KW');
        $this->projectThePushWentTo(country: 'AE');

        $this->assertCount(2, Http::recorded(static fn ($request): bool => str_starts_with($request->url(), 'https://oauth2.googleapis.com/')));
    }

    private function giveKuwaitItsOwnPushAccount(): void
    {
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::PUSH,
            country: 'KW',
            settings: [],
            credentials: ['service_account' => [
                'project_id' => self::KUWAITI_PROJECT,
                'client_email' => self::KUWAITI_CLIENT,
                'private_key' => config('passport.private_key'),
            ]],
            label: 'Kuwait Firebase',
        );
    }

    /**
     * Sends the pending-order push about an order really sold in one market, and answers which Firebase project the request went to -- which
     * is in the URL, so it is the account the request was actually made with and not a reading of the configuration.
     */
    private function projectThePushWentTo(string $country): ?string
    {
        $before = count($this->pushesSent());
        $order = $this->sellOrderIn(
            country: $country,
            orderId: $country === 'KW' ? self::KW_ORDER : self::SA_ORDER,
            orderColumns: ['customer_id' => $this->customerWhoReadsArabic()->id],
        );

        event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));

        $pushes = $this->pushesSent();

        if (count($pushes) === $before) {
            return null;
        }

        preg_match('#/v1/projects/([^/]+)/messages:send#', $pushes[$before]->url(), $found);

        return $found[1] ?? null;
    }
}
