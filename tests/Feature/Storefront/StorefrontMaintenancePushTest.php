<?php

namespace Tests\Feature\Storefront;

use App\Events\MaintenanceModeNotificationEvent;
use App\Services\Storefront\StorefrontLifecycle;
use App\Services\Storefront\StorefrontPushTopics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\ApprovesStorefrontTax;
use Tests\Support\BuildsStorefrontDecisionTables;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\TestCase;

/**
 * K-11 and K-V4: a maintenance of ONE market is told to that market's sellers and to nobody else.
 *
 * The platform has had one seller topic since it had one market, and with six markets that is wrong in both directions: a maintenance of
 * Kuwait on the platform-wide topic tells every Saudi seller to stop working, and a seller who works in both is told the same thing whichever
 * market they are looking at. So a market gets a topic of its own and the message goes there.
 *
 * It is sent on the EDGE only -- when maintenance begins and when it ends -- because a seller whose app is showing a maintenance screen has
 * to be let back in, and every other lifecycle change is not news about maintenance. Pausing a market that is already in maintenance into
 * another stop mode ENDS the maintenance even though the market is still stopped, and is told as such.
 *
 * The first test is the one that protects what exists: with storefronts off there is no market topic and nothing is sent, which is the
 * platform as it ships. The platform-wide topic is not replaced and not removed by any of this; it still carries what is true of the whole
 * platform, so an app build older than the market topics is not cut off.
 */
class StorefrontMaintenancePushTest extends TestCase
{
    use ApprovesStorefrontTax;
    use BuildsStorefrontDecisionTables;
    use BuildsStorefrontLegalDocuments;

    private const PERSON = 'The head of the Kuwait market';

    private const REASON = 'Decision of the launch meeting';

    private const REFERENCE = 'Minutes of the launch meeting, 2026-11-17';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontLegalDocumentsTable();
        $this->buildTheTablesOfAStorefrontDecision(settingPrefix: 'storefront_lifecycle_');
        DB::table('audit_logs')->delete();
        DB::table('business_settings')->where('type', 'like', 'storefront_lifecycle_%')->delete();
        clearWebConfigCacheKeys();

        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true]);
        Event::fake([MaintenanceModeNotificationEvent::class]);
    }

    private function lifecycle(): StorefrontLifecycle
    {
        return app(StorefrontLifecycle::class);
    }

    /** A Kuwaiti storefront that is selling, which is where every test here starts. The prerequisites are the lifecycle test's own. */
    private function activeKuwait(): void
    {
        $this->publishRequiredLegalDocuments(country: 'KW', activated: false);
        $this->approveNoTaxFor('KW');
        $this->lifecycle()->markReady(country: 'KW', by: self::PERSON, reason: self::REASON);
        $this->lifecycle()->activate(country: 'KW', by: self::PERSON, reference: self::REFERENCE, reason: self::REASON);
    }

    /** @return list<array{topic: string, title: string}> what was told, in order */
    private function whatWasTold(): array
    {
        $told = [];

        foreach (Event::dispatched(MaintenanceModeNotificationEvent::class) as $dispatch) {
            $data = $dispatch[0]->data;
            $told[] = ['topic' => (string) $data['topic'], 'title' => (string) $data['title']];
        }

        return $told;
    }

    public function test_with_storefronts_off_nothing_is_sent_at_all(): void
    {
        $this->activeKuwait();
        config(['gcc.storefronts.enabled' => false]);

        $this->lifecycle()->pause(country: 'KW', mode: StorefrontLifecycle::MAINTENANCE, by: self::PERSON, reason: self::REASON);

        Event::assertNotDispatched(MaintenanceModeNotificationEvent::class);
        $this->assertNull(StorefrontPushTopics::sellerMaintenance(country: 'KW'), 'there is no market topic to send to');
    }

    public function test_pausing_a_market_in_maintenance_tells_that_markets_sellers(): void
    {
        $this->activeKuwait();

        $this->lifecycle()->pause(country: 'KW', mode: StorefrontLifecycle::MAINTENANCE, by: self::PERSON, reason: self::REASON);

        $told = $this->whatWasTold();

        $this->assertCount(1, $told);
        $this->assertSame('maintenance_mode_start_vendor_KW', $told[0]['topic'], 'that market, and no other');
        $this->assertSame(translate('Maintenance_Mode_start'), $told[0]['title']);
    }

    public function test_resuming_tells_them_the_maintenance_has_ended(): void
    {
        $this->activeKuwait();
        $this->lifecycle()->pause(country: 'KW', mode: StorefrontLifecycle::MAINTENANCE, by: self::PERSON, reason: self::REASON);
        $this->lifecycle()->resume(country: 'KW', by: self::PERSON, reason: self::REASON);

        $told = $this->whatWasTold();

        $this->assertCount(2, $told);
        $this->assertSame(translate('Maintenance_Mode_End'), $told[1]['title']);
        $this->assertSame('maintenance_mode_start_vendor_KW', $told[1]['topic']);
    }

    /**
     * A market already in maintenance, stopped another way, has its maintenance ENDED.
     *
     * The market is still stopped, so this is not "everything is fine again" -- but a seller whose app is showing a maintenance screen has to
     * be let back in to work, and the app learns that from this message and from nothing else.
     */
    public function test_moving_from_maintenance_to_another_stop_mode_ends_the_maintenance(): void
    {
        $this->activeKuwait();
        $this->lifecycle()->pause(country: 'KW', mode: StorefrontLifecycle::MAINTENANCE, by: self::PERSON, reason: self::REASON);
        $this->lifecycle()->pause(country: 'KW', mode: StorefrontLifecycle::BROWSE_ONLY, by: self::PERSON, reason: self::REASON);

        $told = $this->whatWasTold();

        $this->assertCount(2, $told);
        $this->assertSame(translate('Maintenance_Mode_End'), $told[1]['title']);
    }

    /** A stop that is not a maintenance tells the sellers nothing: it is not news about maintenance. */
    public function test_a_stop_that_is_not_a_maintenance_tells_the_sellers_nothing(): void
    {
        $this->activeKuwait();

        $this->lifecycle()->pause(country: 'KW', mode: StorefrontLifecycle::BROWSE_ONLY, by: self::PERSON, reason: self::REASON);

        Event::assertNotDispatched(MaintenanceModeNotificationEvent::class);
    }

    /** Nor does becoming ready, or being activated: only the maintenance edge is told. */
    public function test_the_other_lifecycle_changes_tell_the_sellers_nothing(): void
    {
        $this->activeKuwait();

        Event::assertNotDispatched(MaintenanceModeNotificationEvent::class);
    }

    /** Pausing in maintenance twice tells them once: the second pause is not a new edge. */
    public function test_a_second_maintenance_pause_is_not_a_second_message(): void
    {
        $this->activeKuwait();
        $this->lifecycle()->pause(country: 'KW', mode: StorefrontLifecycle::MAINTENANCE, by: self::PERSON, reason: self::REASON);
        $this->lifecycle()->pause(country: 'KW', mode: StorefrontLifecycle::MAINTENANCE, by: self::PERSON, reason: 'The same again');

        $this->assertCount(1, $this->whatWasTold());
    }

    /**
     * The config answer names WHICH markets are paused (K-V4).
     *
     * Both apps have only ever been told the platform's one maintenance switch, with no country in it, so a seller whose market was stopped
     * and a seller whose market was selling were told the same thing -- and a seller who works in both could not tell which of them stopped.
     * The per-market block is beside that one, keyed by country because `/api/v1/config` deliberately resolves no storefront of its own.
     */
    public function test_the_config_answer_names_which_markets_are_paused(): void
    {
        $this->activeKuwait();
        $this->lifecycle()->pause(country: 'KW', mode: StorefrontLifecycle::MAINTENANCE, by: self::PERSON, reason: self::REASON, message: 'Back on Sunday');

        $paused = $this->lifecycle()->apiPause();

        $this->assertSame(['KW'], array_keys($paused['storefront_pause']), 'the market that is stopped, and no other');
        $this->assertSame(
            ['country' => 'KW', 'state' => StorefrontLifecycle::PAUSED, 'mode' => StorefrontLifecycle::MAINTENANCE, 'message' => 'Back on Sunday', 'until' => null],
            $paused['storefront_pause']['KW'],
        );
    }

    /** The operator's own record of why a market was stopped, and who stopped it, is not sent to an app. */
    public function test_the_config_answer_carries_no_reason_no_person_and_no_reference(): void
    {
        $this->activeKuwait();
        $this->lifecycle()->pause(country: 'KW', mode: StorefrontLifecycle::MAINTENANCE, by: self::PERSON, reason: self::REASON);

        $sent = $this->lifecycle()->apiPause()['storefront_pause']['KW'];

        $this->assertSame(['country', 'state', 'mode', 'message', 'until'], array_keys($sent));
        $this->assertStringNotContainsString(self::PERSON, (string) json_encode($sent));
        $this->assertStringNotContainsString(self::REASON, (string) json_encode($sent));
    }

    public function test_a_market_that_is_selling_is_not_in_the_map_and_a_quiet_platform_sends_no_key(): void
    {
        $this->activeKuwait();

        $this->assertSame([], $this->lifecycle()->apiPause(), 'nothing is paused, so there is no key to read');

        $this->lifecycle()->pause(country: 'KW', mode: StorefrontLifecycle::BROWSE_ONLY, by: self::PERSON, reason: self::REASON);

        $this->assertSame(['KW'], array_keys($this->lifecycle()->apiPause()['storefront_pause']), 'a stop that is not a maintenance is still a stop');
    }

    public function test_with_storefronts_off_the_config_answer_carries_no_pause_block_at_all(): void
    {
        $this->activeKuwait();
        $this->lifecycle()->pause(country: 'KW', mode: StorefrontLifecycle::MAINTENANCE, by: self::PERSON, reason: self::REASON);
        config(['gcc.storefronts.enabled' => false]);

        $this->assertSame([], $this->lifecycle()->apiPause());
    }

    /** The topic name is built in one place, because the app subscribes to it and a mismatch is silent. */
    public function test_the_topic_of_a_market_is_the_platform_topic_with_the_market_appended(): void
    {
        $this->assertSame('maintenance_mode_start_vendor_KW', StorefrontPushTopics::sellerMaintenance(country: 'KW'));
        $this->assertSame('maintenance_mode_start_vendor_KW', StorefrontPushTopics::sellerMaintenance(country: ' kw '), 'in one case, trimmed');
        $this->assertSame('maintenance_mode_start_user_app_KW', StorefrontPushTopics::customerMaintenance(country: 'KW'));
        $this->assertNull(StorefrontPushTopics::sellerMaintenance(country: 'not-a-market'), 'a typo gets no topic rather than a topic named after it');
        $this->assertNull(StorefrontPushTopics::sellerMaintenance(country: null));
    }
}
