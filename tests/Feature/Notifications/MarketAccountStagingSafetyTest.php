<?php

namespace Tests\Feature\Notifications;

use App\Services\Notification\NotificationProfiles;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsNotificationProfiles;
use Tests\TestCase;

/**
 * The mandatory safety test for the new credentials table: a restored copy of production must not be able to send as any market.
 *
 * `notification_channel_profiles` holds six companies' sending credentials -- mail hosts and passwords, SMS gateway tokens, a WhatsApp
 * token, a Firebase service account -- and the senders read it BEFORE the shared settings that the staging plan already neutralises. So
 * disarming `mail_config`, `addon_settings`, `zender_settings` and `integration_settings` is no longer enough: a market with an active row
 * of its own would go on sending through its own account, to real customers, from a staging copy.
 *
 * The declaration is in `StagingChannels`, where every other outbound sink of this platform is declared. What this test adds is that the
 * declaration WORKS: the generated statements really disarm a row and really empty the credentials, measured by running them.
 */
class MarketAccountStagingSafetyTest extends TestCase
{
    use BuildsNotificationProfiles;

    private const CANARY = 'CANARY-KUWAIT-MAIL-PASSWORD';

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

    public function test_the_staging_plan_disarms_every_markets_account_and_empties_its_credentials(): void
    {
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::MAIL,
            country: 'KW',
            settings: ['host' => 'smtp.kw.example', 'from_address' => 'orders@kw.example'],
            credentials: ['password' => self::CANARY],
        );
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::PUSH,
            country: 'AE',
            settings: [],
            credentials: ['service_account' => ['project_id' => 'lavkoo-ae', 'private_key' => self::CANARY]],
        );

        $this->applyTheStagingPlan();

        $this->assertSame(0, DB::table(NotificationProfiles::TABLE)->where('active', true)->count(), 'no market can still send');
        $this->assertSame(0, DB::table(NotificationProfiles::TABLE)->whereNotNull('credentials')->count(), 'and no credential is left to send with');
        $this->assertSame(2, DB::table(NotificationProfiles::TABLE)->count(), 'the rows themselves stay, so an operator can see which markets had accounts');
    }

    /** No reader may answer with an account after the plan has run: the resolver requires `active`, which is what the plan clears. */
    public function test_no_market_has_an_account_after_the_plan(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::MAIL,
            country: 'KW',
            settings: ['host' => 'smtp.kw.example'],
            credentials: ['password' => self::CANARY],
        );
        $this->assertNotNull(app(NotificationProfiles::class)->for(channel: NotificationProfiles::MAIL, country: 'KW'), 'armed before the plan');

        $this->applyTheStagingPlan();
        app(NotificationProfiles::class)->flush();

        $this->assertNull(app(NotificationProfiles::class)->for(channel: NotificationProfiles::MAIL, country: 'KW'));
    }

    public function test_the_plan_names_the_table_and_prints_no_credential(): void
    {
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::MAIL,
            country: 'KW',
            settings: ['host' => 'smtp.kw.example'],
            credentials: ['password' => self::CANARY],
        );

        $plan = $this->theStagingPlan();

        $this->assertStringContainsString(NotificationProfiles::TABLE, $plan);
        $this->assertStringNotContainsString(self::CANARY, $plan);
    }

    private function theStagingPlan(): string
    {
        Artisan::call('fulfillment:sanitization-plan', ['--expect-database' => (string) DB::connection()->getDatabaseName()]);

        return Artisan::output();
    }

    /** Runs the plan the way an operator would, and asserts that the statements about this table reported nothing left armed. */
    private function applyTheStagingPlan(): void
    {
        $verified = 0;

        foreach (explode("\n", $this->theStagingPlan()) as $line) {
            $statement = rtrim(trim($line), ';');

            if ($statement === '' || str_starts_with($statement, '--') || !str_contains($statement, NotificationProfiles::TABLE)) {
                continue;
            }

            if (!str_starts_with($statement, 'SELECT')) {
                DB::unprepared($statement);

                continue;
            }

            $row = (array) DB::select($statement)[0];
            $this->assertSame(0, (int) ($row['rows_still_armed'] ?? $row['rows_not_scrubbed'] ?? -1), 'the plan verifies its own work');
            $verified++;
        }

        $this->assertGreaterThan(0, $verified, 'the plan carries a check for this table, not only a statement');
    }
}
