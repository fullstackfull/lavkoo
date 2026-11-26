<?php

namespace Tests\Feature\Storefront;

use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Services\Theme\ThemeScheduleClock;
use App\Services\Theme\ThemeStorefrontSelection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsThemeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * F-08: a schedule set for a market fires on THAT market's clock.
 *
 * The schedule form sends a `datetime-local` value, which carries no zone, and `config/app.php` sets one zone for the whole platform --
 * `Asia/Dhaka`, the upstream vendor's. So `Carbon::parse('2026-12-01T09:00')` meant nine o'clock in Dhaka: an operator of the Emirati
 * storefront who scheduled a seasonal home page for nine in the morning got it at six, and one who scheduled a midnight launch got it at
 * eight the evening before. Nobody is watching when a scheduled publish runs, which is exactly why the hour has to be the one the operator
 * meant.
 *
 * What does NOT change is where the moment is stored. It stays absolute and in the platform's zone, like every other timestamp of this
 * database, so `ThemeVersion::dueToPublish()` still compares against `now()` and the runner needed no change at all -- which is asserted
 * here rather than assumed, by firing the real scope at the real moment.
 */
class ThemeScheduleMarketClockTest extends TestCase
{
    use BuildsThemeWorld;
    use RestoresLanguageFiles;

    /** Dhaka is UTC+6 and the platform's own zone; Dubai is UTC+4 and Riyadh UTC+3. Two hours and three, and none of them moves in April. */
    private const PLATFORM_ZONE = 'Asia/Dhaka';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildThemeWorld();
        $this->switchStorefrontThemesOn();
        $this->openEveryStorefront();
        $this->forgetWhatTheThemeResolverLearnt();
        config(['app.timezone' => self::PLATFORM_ZONE]);
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    public function test_every_configured_market_names_a_zone_this_machine_knows(): void
    {
        self::assertTrue(app(ThemeScheduleClock::class)->everyMarketNamesAZone());
        self::assertSame('Asia/Riyadh', config('gcc.storefronts.countries.SA.timezone'));
        self::assertSame('Asia/Dubai', config('gcc.storefronts.countries.AE.timezone'));
    }

    public function test_while_themes_do_not_belong_to_storefronts_the_clock_is_the_platforms(): void
    {
        config(['gcc.storefronts.themes.enabled' => false]);
        $this->forgetWhatTheThemeResolverLearnt();
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE');

        self::assertSame(self::PLATFORM_ZONE, app(ThemeScheduleClock::class)->timezoneOf($emirati));
        self::assertSame(
            '2037-04-01 09:00:00',
            (string) app(ThemeScheduleClock::class)->momentTyped(theme: $emirati, typed: '2037-04-01T09:00')?->format('Y-m-d H:i:s'),
            'the stored moment is what this platform has always stored',
        );
    }

    public function test_nine_in_the_morning_typed_on_the_emirati_screen_is_nine_in_dubai(): void
    {
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE');

        $stored = app(ThemeScheduleClock::class)->momentTyped(theme: $emirati, typed: '2037-04-01T09:00');

        self::assertSame('Asia/Dubai', app(ThemeScheduleClock::class)->timezoneOf($emirati));
        self::assertSame('2037-04-01 09:00:00', $stored?->copy()->setTimezone('Asia/Dubai')->format('Y-m-d H:i:s'));
        self::assertSame('2037-04-01 11:00:00', $stored?->format('Y-m-d H:i:s'), 'stored in the platform\'s zone, two hours ahead of Dubai');
    }

    public function test_the_same_hour_typed_for_two_markets_is_two_different_moments(): void
    {
        $clock = app(ThemeScheduleClock::class);
        $emirati = $clock->momentTyped(theme: $this->giveTheme(name: 'Emirati', country: 'AE'), typed: '2037-04-01T09:00');
        $saudi = $clock->momentTyped(theme: $this->giveTheme(name: 'Saudi', country: 'SA'), typed: '2037-04-01T09:00');

        self::assertNotNull($emirati);
        self::assertNotNull($saudi);
        self::assertSame(3600, $saudi->getTimestamp() - $emirati->getTimestamp(), 'Riyadh is an hour behind Dubai, so its nine comes an hour later');
    }

    public function test_the_moment_is_shown_back_on_the_market_that_typed_it(): void
    {
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE');
        $clock = app(ThemeScheduleClock::class);
        $stored = $clock->momentTyped(theme: $emirati, typed: '2037-04-01T09:00');

        self::assertSame('09:00', $clock->onTheMarketsClock($emirati, $stored)?->format('H:i'), 'the operator reads back the hour they typed');
        self::assertSame('Dubai', $clock->labelOf($emirati), 'with the zone beside it, so the hour cannot be read as another market\'s');
    }

    public function test_a_midnight_launch_does_not_arrive_the_evening_before(): void
    {
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE');

        $stored = app(ThemeScheduleClock::class)->momentTyped(theme: $emirati, typed: '2037-04-01T00:00');

        self::assertSame('2037-04-01 00:00:00', $stored?->copy()->setTimezone('Asia/Dubai')->format('Y-m-d H:i:s'));
        self::assertSame('2037-04-01', $stored?->copy()->setTimezone('Asia/Dubai')->format('Y-m-d'), 'the day the operator meant');
    }

    public function test_the_runner_still_fires_on_the_stored_moment_and_fires_each_market_on_its_own(): void
    {
        $clock = app(ThemeScheduleClock::class);
        $emiratiDraft = $this->draftOf($this->giveTheme(name: 'Emirati', country: 'AE'));
        $saudiDraft = $this->draftOf($this->giveTheme(name: 'Saudi', country: 'SA'));

        // Eight in the morning, for both markets, on the same day: in Dubai that is 10:00 on the platform's clock and in Riyadh 11:00.
        $emiratiDraft->forceFill(['publish_at' => $clock->momentTyped(theme: $emiratiDraft->theme, typed: '2037-04-01T08:00')])->save();
        $saudiDraft->forceFill(['publish_at' => $clock->momentTyped(theme: $saudiDraft->theme, typed: '2037-04-01T08:00')])->save();

        Carbon::setTestNow(Carbon::parse('2037-04-01 10:30:00', self::PLATFORM_ZONE));
        $due = ThemeVersion::dueToPublish()->pluck('id')->all();
        Carbon::setTestNow();

        self::assertSame([$emiratiDraft->id], $due, 'the Emirati eight o\'clock has come and the Saudi one has not');
    }

    public function test_a_market_naming_no_zone_or_an_unknown_one_falls_back_to_the_platforms(): void
    {
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE');

        config(['gcc.storefronts.countries.AE.timezone' => null]);
        self::assertSame(self::PLATFORM_ZONE, app(ThemeScheduleClock::class)->timezoneOf($emirati));

        config(['gcc.storefronts.countries.AE.timezone' => 'Mars/Olympus']);
        self::assertSame(self::PLATFORM_ZONE, app(ThemeScheduleClock::class)->timezoneOf($emirati), 'a schedule nobody can read is worse than one in the wrong zone');
        self::assertFalse(app(ThemeScheduleClock::class)->everyMarketNamesAZone());
    }

    public function test_a_moment_that_has_already_passed_is_still_refused(): void
    {
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE');
        $clock = app(ThemeScheduleClock::class);

        self::assertNull($clock->momentTyped(theme: $emirati, typed: '2001-01-01T09:00'));
        self::assertNull($clock->momentTyped(theme: $emirati, typed: ''));
        self::assertNull($clock->momentTyped(theme: $emirati, typed: 'not a date at all'));
        self::assertSame(ThemeScheduleClock::NOT_IN_THE_FUTURE, $clock->whyItCannotBeScheduled(theme: $emirati, typed: '2001-01-01T09:00'));
    }

    /**
     * A DEFECT found while writing the test above, and it is the inverse of the one F-08 was about.
     *
     * `theme_versions.publish_at` is a MySQL `TIMESTAMP`, whose range ends in 2038, and this platform ships no migration for the theme
     * tables at all, so the column cannot be widened from here. A moment beyond that is stored as `0000-00-00`, and
     * `ThemeVersion::dueToPublish()` compares `publish_at <= now()` -- so a schedule set far enough ahead published IMMEDIATELY. It was
     * found by a first draft of the test above that used the year 2099 and watched BOTH markets' drafts come due at once.
     */
    public function test_a_moment_the_column_cannot_hold_is_refused_rather_than_published_at_once(): void
    {
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE');
        $clock = app(ThemeScheduleClock::class);

        self::assertNull($clock->momentTyped(theme: $emirati, typed: '2099-04-01T09:00'));
        self::assertSame(ThemeScheduleClock::TOO_LATE_FOR_THE_COLUMN, $clock->whyItCannotBeScheduled(theme: $emirati, typed: '2099-04-01T09:00'));
        self::assertNotNull($clock->momentTyped(theme: $emirati, typed: '2037-04-01T09:00'), 'a date the column does hold is still scheduled');
    }

    /**
     * What the refusal above PREVENTS, written out so the reason cannot be mistaken for tidiness: the column really does store zero, and
     * the runner's own scope really does read zero as due.
     *
     * It is the half of that test that can only be asked of a real engine, and it is its own test for that reason. It used to be the tail
     * of the test above, which made the whole thing red on SQLite -- a `TIMESTAMP` out of range is a MySQL and MariaDB behaviour, and
     * SQLite keeps dates as text and stores `2099-04-01 09:00:00` happily, so there the assertion read the moment back unchanged. Both
     * SQLite jobs of CI run 678 carried that failure and the handover did not account for it. Splitting it rather than skipping the lot
     * keeps the REFUSAL -- which is application code and engine-independent -- asserted on every engine, which is the part a regression
     * would break.
     */
    public function test_the_column_really_does_store_zero_and_the_runner_reads_zero_as_due(): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('A TIMESTAMP beyond 2038 is truncated by MySQL and MariaDB only: SQLite keeps dates as text and stores the moment unchanged.');
        }

        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE');
        $draft = $this->draftOf($emirati);

        DB::table('theme_versions')->where('id', $draft->id)->update(['publish_at' => '2099-04-01 09:00:00']);

        self::assertSame('0000-00-00 00:00:00', DB::table('theme_versions')->where('id', $draft->id)->value('publish_at'));
        self::assertContains($draft->id, ThemeVersion::dueToPublish()->pluck('id')->all(), 'zero reads as already due');
    }

    private function draftOf(Theme $theme): ThemeVersion
    {
        return ThemeVersion::query()->create([
            'theme_id' => $theme->id,
            'label' => $theme->name . ' draft',
            'status' => ThemeVersion::STATUS_DRAFT,
        ]);
    }
}
