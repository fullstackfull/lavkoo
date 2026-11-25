<?php

namespace Tests\Support;

use App\Services\Notification\NotificationProfiles;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The `notification_channel_profiles` table, built by the REAL migration, and a writer for one account.
 *
 * The migration rather than a hand-written `Schema::create`, so the `country` column is exactly what production gets -- `char(2)` with
 * `ascii_bin` on a real engine -- and a test that asserts a market is compared byte for byte is asserting it against the column the server
 * will have. A hand-built `char(2)` takes the engine's own case-insensitive collation, which is how a `kw` that matched `KW` on MariaDB and
 * not on SQLite got as far as CI.
 *
 * The credentials are encrypted here the same way the application encrypts them, so no test is told a secret in a shape the readers do not
 * read.
 */
trait BuildsNotificationProfiles
{
    private const PROFILE_MIGRATION = 'database/migrations/2026_12_27_100000_create_notification_channel_profiles_table.php';

    protected function buildNotificationProfilesTable(): void
    {
        (require base_path(self::PROFILE_MIGRATION))->up();
        app(NotificationProfiles::class)->flush();
    }

    /**
     * The table is created by this trait and by no other class, and the suite runs no migrations, so absent is the state every other class
     * sees. Dropped rather than rolled back because the migration's own `down()` refuses to discard configured accounts, which is right on a
     * server and unhelpful here.
     */
    protected function dropNotificationProfilesTable(): void
    {
        Schema::dropIfExists(NotificationProfiles::TABLE);
        app(NotificationProfiles::class)->flush();
    }

    /** Turns per-market accounts on. The table still has to hold a row for anything to change. */
    protected function switchNotificationProfilesOn(): void
    {
        config(['gcc.notifications.profiles' => true]);
        app(NotificationProfiles::class)->flush();
    }

    /**
     * One account, as an operator would have saved it.
     *
     * @param string $country the market, or '' for the shared account every installation has
     * @param array<string, mixed> $settings values a screen may show
     * @param array<string, mixed> $credentials values no screen, response or log may show
     */
    protected function giveMarketItsOwnAccount(string $channel, string $country, array $settings, array $credentials = [], bool $active = true, ?string $label = null): void
    {
        DB::table(NotificationProfiles::TABLE)->updateOrInsert(
            ['channel' => $channel, 'country' => $country],
            [
                'label' => $label,
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
                'credentials' => $credentials === [] ? null : Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR)),
                'active' => $active,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        app(NotificationProfiles::class)->flush();
    }
}
