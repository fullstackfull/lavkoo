<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsAuditLogTable;
use Tests\Support\BuildsThemeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * GCC F-02: the audit lines of a scheduled publish name the storefront. An admin sets, changes or cancels the moment a draft goes live, and theme:publish-due publishes the draft whose moment
 * has come, cancels the one that no longer passes the check a manual publish passes, and records the one whose publish failed. Every one of those lines says which storefront the theme is for,
 * a due publish of one storefront never touches the theme or the schedule of another, and while themes are not kept by storefront the lines are what they always were: no storefront in any of them.
 */
class StorefrontThemeScheduledPublishTest extends TestCase
{
    use BuildsAuditLogTable;
    use BuildsThemeWorld;
    use RestoresLanguageFiles;

    /** What a section that waits for the merchant's choice makes the reasons of a blocked publish say: its label, then why. */
    private const BLOCKING_REASON = 'category_showcase: choose_a_category_for_this_section_to_have_something_to_show';

    /** The admin who sets and cancels schedules, recorded as the actor of the lines. */
    private const ADMIN_ID = 7;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        session(['local' => 'en']);
        $this->buildThemeWorld();
        $this->buildAuditLogTable();
        $this->switchStorefrontThemesOn();
        $this->openEveryStorefront();
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();
        Schema::dropIfExists('audit_logs');
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function due(int $minutesAgo = 5): Carbon
    {
        return now()->subMinutes($minutesAgo);
    }

    private function notYetDue(): Carbon
    {
        return now()->addHours(3);
    }

    private function draftOf(Theme $theme, ?Carbon $publishAt = null): ThemeVersion
    {
        return ThemeVersion::query()->create(['theme_id' => $theme->id, 'label' => "{$theme->name} draft", 'status' => ThemeVersion::STATUS_DRAFT, 'publish_at' => $publishAt]);
    }

    /** A section the merchant left unfinished: it renders nothing until a category is chosen, so the check a publish passes stops the version. */
    private function giveBlockingSection(ThemeVersion $draft): void
    {
        ThemeSection::query()->create(['theme_version_id' => $draft->id, 'page' => 'home', 'type' => 'category_showcase', 'sort_order' => 1, 'settings' => []]);
    }

    /** Makes the publish of one draft throw inside its transaction, the way a database error would. */
    private function failThePublishOf(ThemeVersion $draft, string $message): void
    {
        ThemeVersion::saving(static function (ThemeVersion $saved) use ($draft, $message): void {
            if ((int) $saved->getKey() === (int) $draft->getKey() && $saved->status === ThemeVersion::STATUS_PUBLISHED) {
                throw new RuntimeException($message);
            }
        });
    }

    private function runDuePublishes(int $exitCode = 0): void
    {
        $this->artisan('theme:publish-due')->assertExitCode($exitCode);
    }

    /** The moment as the datetime-local field of the schedule form sends it. */
    private function asFormMoment(Carbon $moment): string
    {
        return $moment->format('Y-m-d\TH:i');
    }

    /** @param array<string, mixed> $input */
    private function postedSchedule(array $input): TestResponse
    {
        $this->withoutMiddleware([
            AdminMiddleware::class, ActivationCheckMiddleware::class, ModulePermissionMiddleware::class, MaintenanceModeMiddleware::class, Localization::class,
            DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        $this->actingAs((new Admin())->forceFill(['id' => self::ADMIN_ID, 'name' => 'Theme publisher', 'admin_role_id' => 1]), 'admin');

        return $this->post(route('admin.theme.version.schedule'), $input);
    }

    /** @return array{context: array<string, mixed>, before: array<string, mixed>, after: array<string, mixed>, actor: array{0: ?string, 1: int}} the audit line of an action about a version */
    private function auditLine(string $action, ThemeVersion $version): array
    {
        $line = DB::table('audit_logs')->where('action', $action)->where('subject_id', (string) $version->id)->orderByDesc('id')->first();
        $this->assertNotNull($line, "No audit line [{$action}] about version {$version->id}.");

        return [
            'context' => (array) json_decode((string) $line->context, true),
            'before' => (array) json_decode((string) $line->before, true),
            'after' => (array) json_decode((string) $line->after, true),
            'actor' => [$line->actor_type, (int) $line->actor_id],
        ];
    }

    /** @return array<string, mixed> */
    private function auditContext(string $action, ThemeVersion $version): array
    {
        return $this->auditLine(action: $action, version: $version)['context'];
    }

    /** @return list<string> every action the audit trail holds, oldest first */
    private function recordedActions(): array
    {
        return DB::table('audit_logs')->orderBy('id')->pluck('action')->all();
    }

    /** @return list<array<string, mixed>> the rows of the versions as the database holds them */
    private function rowsOf(ThemeVersion ...$versions): array
    {
        return DB::table('theme_versions')->whereIn('id', array_map(static fn (ThemeVersion $version): int => $version->id, $versions))->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    /** @return array<string, array{0: ?string, 1: string}> the country a theme names, and the storefront the audit lines about it name */
    public static function storefrontsOfThemes(): array
    {
        return [
            'Emirati' => ['AE', 'AE'],
            'Kuwaiti' => ['KW', 'KW'],
            'default storefront, naming no country' => [null, 'SA'],
            'default storefront, naming its country' => ['SA', 'SA'],
        ];
    }

    // ---- a due publish ---------------------------------------------------------------------------------------------------------------------

    #[DataProvider('storefrontsOfThemes')]
    public function test_a_due_publish_goes_live_and_its_audit_line_names_the_storefront(?string $country, string $storefront): void
    {
        $theme = $this->giveTheme(name: 'Scheduled', country: $country);
        $draft = $this->draftOf(theme: $theme, publishAt: $this->due());

        $this->runDuePublishes();

        $this->assertSame([ThemeVersion::STATUS_PUBLISHED, null], [$draft->fresh()->status, $draft->fresh()->publish_at]);
        $this->assertEquals(['theme_id' => $theme->id, 'storefront' => $storefront], $this->auditContext(action: 'theme.published', version: $draft));
        $this->assertSame(['theme.published'], $this->recordedActions());
    }

    /** @return array<string, array{0: string, 1: string}> the storefront whose publish is due, and the storefront whose publish is not */
    public static function dueAndLaterStorefronts(): array
    {
        return ['Emirati due, Saudi later' => ['AE', 'SA'], 'Saudi due, Emirati later' => ['SA', 'AE']];
    }

    #[DataProvider('dueAndLaterStorefronts')]
    public function test_a_due_publish_of_one_storefront_leaves_the_theme_and_the_schedule_of_the_other_as_they_were(string $due, string $later): void
    {
        $dueTheme = $this->giveTheme(name: "Theme of {$due}", country: $due);
        $previous = $this->givePublishedVersion(theme: $dueTheme, revision: 3, spacerHeight: 250);
        $dueDraft = $this->draftOf(theme: $dueTheme, publishAt: $this->due());
        $laterTheme = $this->giveTheme(name: "Theme of {$later}", country: $later);
        $published = $this->givePublishedVersion(theme: $laterTheme, revision: 7, spacerHeight: 100);
        $laterDraft = $this->draftOf(theme: $laterTheme, publishAt: $this->notYetDue());
        $otherStorefrontsRows = $this->rowsOf($published, $laterDraft);

        $this->runDuePublishes();

        $this->assertSame([ThemeVersion::STATUS_PUBLISHED, 4, null], [$dueDraft->fresh()->status, $dueDraft->fresh()->revision, $dueDraft->fresh()->publish_at]);
        $this->assertSame(ThemeVersion::STATUS_ARCHIVED, $previous->fresh()->status);
        $this->assertEquals($otherStorefrontsRows, $this->rowsOf($published, $laterDraft));
        $this->assertSame(['theme.published'], $this->recordedActions());
        $this->assertSame($due, $this->auditContext(action: 'theme.published', version: $dueDraft)['storefront']);
    }

    public function test_two_due_publishes_of_two_storefronts_each_go_live_and_each_line_names_its_own_storefront(): void
    {
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE');
        $saudi = $this->giveTheme(name: 'Saudi');
        $emiratiDraft = $this->draftOf(theme: $emirati, publishAt: $this->due(minutesAgo: 10));
        $saudiDraft = $this->draftOf(theme: $saudi, publishAt: $this->due(minutesAgo: 5));

        $this->runDuePublishes();

        $this->assertSame([ThemeVersion::STATUS_PUBLISHED, ThemeVersion::STATUS_PUBLISHED], [$emiratiDraft->fresh()->status, $saudiDraft->fresh()->status]);
        $this->assertEquals(['theme_id' => $emirati->id, 'storefront' => 'AE'], $this->auditContext(action: 'theme.published', version: $emiratiDraft));
        $this->assertEquals(['theme_id' => $saudi->id, 'storefront' => 'SA'], $this->auditContext(action: 'theme.published', version: $saudiDraft));
        $this->assertSame(['theme.published', 'theme.published'], $this->recordedActions());
    }

    // ---- a publish that is not yet due -------------------------------------------------------------------------------------------------------

    public function test_a_publish_that_is_not_yet_due_is_left_alone_and_nothing_is_recorded(): void
    {
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE');
        $saudi = $this->giveTheme(name: 'Saudi');
        $versions = [
            $this->givePublishedVersion(theme: $emirati, revision: 3, spacerHeight: 250),
            $this->draftOf(theme: $emirati, publishAt: $this->notYetDue()),
            $this->draftOf(theme: $emirati),
            $this->givePublishedVersion(theme: $saudi, revision: 7, spacerHeight: 100),
            $this->draftOf(theme: $saudi, publishAt: $this->notYetDue()),
        ];
        $before = $this->rowsOf(...$versions);

        $this->runDuePublishes();

        $this->assertEquals($before, $this->rowsOf(...$versions));
        $this->assertSame([], $this->recordedActions());
    }

    // ---- a due publish that is blocked, and so cancelled ---------------------------------------------------------------------------------------

    #[DataProvider('storefrontsOfThemes')]
    public function test_a_due_publish_that_is_blocked_is_cancelled_and_its_audit_line_names_the_storefront_and_keeps_its_reasons(?string $country, string $storefront): void
    {
        $theme = $this->giveTheme(name: 'Scheduled', country: $country);
        $previous = $this->givePublishedVersion(theme: $theme, revision: 3, spacerHeight: 100);
        $draft = $this->draftOf(theme: $theme, publishAt: $this->due());
        $this->giveBlockingSection(draft: $draft);

        $this->runDuePublishes();

        $line = $this->auditLine(action: 'theme.scheduled_publish_cancelled', version: $draft);
        $this->assertEquals(['theme_id' => $theme->id, 'storefront' => $storefront], $line['context']);
        $this->assertSame(['reasons' => [self::BLOCKING_REASON]], $line['after']);
        $this->assertSame([ThemeVersion::STATUS_DRAFT, null], [$draft->fresh()->status, $draft->fresh()->publish_at]);
        $this->assertSame(ThemeVersion::STATUS_PUBLISHED, $previous->fresh()->status);
        $this->assertSame(['theme.scheduled_publish_cancelled'], $this->recordedActions());
    }

    public function test_a_blocked_publish_of_one_storefront_does_not_stop_the_publish_of_another(): void
    {
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE');
        $saudi = $this->giveTheme(name: 'Saudi');
        $blocked = $this->draftOf(theme: $emirati, publishAt: $this->due(minutesAgo: 10));
        $this->giveBlockingSection(draft: $blocked);
        $fine = $this->draftOf(theme: $saudi, publishAt: $this->due(minutesAgo: 5));

        $this->runDuePublishes();

        $this->assertSame([ThemeVersion::STATUS_DRAFT, ThemeVersion::STATUS_PUBLISHED], [$blocked->fresh()->status, $fine->fresh()->status]);
        $this->assertSame('AE', $this->auditContext(action: 'theme.scheduled_publish_cancelled', version: $blocked)['storefront']);
        $this->assertSame('SA', $this->auditContext(action: 'theme.published', version: $fine)['storefront']);
        $this->assertSame(['theme.scheduled_publish_cancelled', 'theme.published'], $this->recordedActions());
    }

    // ---- a due publish that fails ------------------------------------------------------------------------------------------------------------

    #[DataProvider('storefrontsOfThemes')]
    public function test_a_due_publish_that_fails_is_recorded_with_its_error_and_the_storefront(?string $country, string $storefront): void
    {
        $theme = $this->giveTheme(name: 'Scheduled', country: $country);
        $previous = $this->givePublishedVersion(theme: $theme, revision: 3, spacerHeight: 100);
        $draft = $this->draftOf(theme: $theme, publishAt: $this->due());
        $this->failThePublishOf(draft: $draft, message: 'the database refused the publish');

        $this->runDuePublishes(exitCode: 1);

        $this->assertEquals(['error' => 'the database refused the publish', 'storefront' => $storefront], $this->auditContext(action: 'theme.scheduled_publish_failed', version: $draft));
        $this->assertSame([ThemeVersion::STATUS_DRAFT, null], [$draft->fresh()->status, $draft->fresh()->publish_at], 'the publish was rolled back, and its schedule is not retried');
        $this->assertSame(ThemeVersion::STATUS_PUBLISHED, $previous->fresh()->status);
        $this->assertSame(['theme.scheduled_publish_failed'], $this->recordedActions());
    }

    public function test_the_error_of_a_failed_publish_is_kept_up_to_three_hundred_characters(): void
    {
        $message = str_repeat('a database error ', 25);
        $draft = $this->draftOf(theme: $this->giveTheme(name: 'Emirati', country: 'AE'), publishAt: $this->due());
        $this->failThePublishOf(draft: $draft, message: $message);

        $this->runDuePublishes(exitCode: 1);

        $context = $this->auditContext(action: 'theme.scheduled_publish_failed', version: $draft);
        $this->assertSame(substr($message, 0, 300), $context['error']);
        $this->assertSame('AE', $context['storefront']);
    }

    public function test_a_failed_publish_of_one_storefront_does_not_cost_another_storefront_its_publish(): void
    {
        $emiratiDraft = $this->draftOf(theme: $this->giveTheme(name: 'Emirati', country: 'AE'), publishAt: $this->due(minutesAgo: 10));
        $saudiDraft = $this->draftOf(theme: $this->giveTheme(name: 'Saudi'), publishAt: $this->due(minutesAgo: 5));
        $this->failThePublishOf(draft: $emiratiDraft, message: 'the database refused the publish');

        $this->runDuePublishes(exitCode: 1);
        $this->runDuePublishes();

        $this->assertSame([ThemeVersion::STATUS_DRAFT, ThemeVersion::STATUS_PUBLISHED], [$emiratiDraft->fresh()->status, $saudiDraft->fresh()->status]);
        $this->assertSame('AE', $this->auditContext(action: 'theme.scheduled_publish_failed', version: $emiratiDraft)['storefront']);
        $this->assertSame('SA', $this->auditContext(action: 'theme.published', version: $saudiDraft)['storefront']);
        $this->assertEqualsCanonicalizing(['theme.scheduled_publish_failed', 'theme.published'], $this->recordedActions());
    }

    // ---- a version whose theme is gone, and a dry run ----------------------------------------------------------------------------------------

    public function test_a_due_publish_of_a_version_whose_theme_no_longer_exists_is_still_recorded_without_a_storefront(): void
    {
        $theme = $this->giveTheme(name: 'Gone', country: 'AE');
        $blocked = $this->draftOf(theme: $theme, publishAt: $this->due(minutesAgo: 10));
        $this->giveBlockingSection(draft: $blocked);
        $failing = $this->draftOf(theme: $theme, publishAt: $this->due(minutesAgo: 5));
        $this->failThePublishOf(draft: $failing, message: 'the database refused the publish');
        $theme->delete();

        $this->runDuePublishes(exitCode: 1);

        $this->assertEquals(['theme_id' => $theme->id], $this->auditContext(action: 'theme.scheduled_publish_cancelled', version: $blocked));
        $this->assertEquals(['error' => 'the database refused the publish'], $this->auditContext(action: 'theme.scheduled_publish_failed', version: $failing));
    }

    public function test_a_dry_run_changes_nothing_and_records_nothing(): void
    {
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE');
        $fine = $this->draftOf(theme: $emirati, publishAt: $this->due(minutesAgo: 10));
        $blocked = $this->draftOf(theme: $emirati, publishAt: $this->due(minutesAgo: 5));
        $this->giveBlockingSection(draft: $blocked);
        $before = $this->rowsOf($fine, $blocked);

        $this->artisan('theme:publish-due', ['--dry-run' => true])->assertExitCode(0);

        $this->assertEquals($before, $this->rowsOf($fine, $blocked));
        $this->assertSame([], $this->recordedActions());
    }

    // ---- a schedule set, changed or cancelled by hand ----------------------------------------------------------------------------------------

    #[DataProvider('storefrontsOfThemes')]
    public function test_scheduling_a_draft_records_the_moment_the_admin_and_the_storefront(?string $country, string $storefront): void
    {
        $theme = $this->giveTheme(name: 'Scheduled', country: $country);
        $draft = $this->draftOf(theme: $theme);
        $moment = now()->addDay()->startOfMinute();

        $this->postedSchedule(input: ['version_id' => $draft->id, 'publish_at' => $this->asFormMoment(moment: $moment)])->assertRedirect(route('admin.theme.index'));

        $line = $this->auditLine(action: 'theme.scheduled_publish_set', version: $draft);
        $this->assertEquals(['theme_id' => $theme->id, 'storefront' => $storefront], $line['context']);
        $this->assertSame(['publish_at' => null], $line['before']);
        $this->assertSame(['publish_at' => $moment->toIso8601String()], $line['after']);
        $this->assertSame(['admin', self::ADMIN_ID], $line['actor']);
        $this->assertTrue($draft->fresh()->publish_at->equalTo($moment));
        $this->assertSame(['theme.scheduled_publish_set'], $this->recordedActions());
    }

    public function test_changing_a_schedule_records_the_moment_it_was_and_the_moment_it_is(): void
    {
        $theme = $this->giveTheme(name: 'Emirati', country: 'AE');
        $earlier = now()->addDays(2)->startOfMinute();
        $later = now()->addDays(3)->startOfMinute();
        $draft = $this->draftOf(theme: $theme, publishAt: $earlier);

        $this->postedSchedule(input: ['version_id' => $draft->id, 'publish_at' => $this->asFormMoment(moment: $later)])->assertRedirect(route('admin.theme.index'));

        $line = $this->auditLine(action: 'theme.scheduled_publish_set', version: $draft);
        $this->assertSame(['publish_at' => $earlier->toIso8601String()], $line['before']);
        $this->assertSame(['publish_at' => $later->toIso8601String()], $line['after']);
        $this->assertSame('AE', $line['context']['storefront']);
    }

    #[DataProvider('storefrontsOfThemes')]
    public function test_cancelling_a_schedule_records_the_moment_it_was_the_admin_and_the_storefront(?string $country, string $storefront): void
    {
        $theme = $this->giveTheme(name: 'Scheduled', country: $country);
        $moment = now()->addDay()->startOfMinute();
        $draft = $this->draftOf(theme: $theme, publishAt: $moment);

        $this->postedSchedule(input: ['version_id' => $draft->id, 'cancel' => 1])->assertRedirect(route('admin.theme.index'));

        $line = $this->auditLine(action: 'theme.scheduled_publish_cancelled', version: $draft);
        $this->assertEquals(['theme_id' => $theme->id, 'storefront' => $storefront], $line['context']);
        $this->assertSame(['publish_at' => $moment->toIso8601String()], $line['before']);
        $this->assertSame(['publish_at' => null], $line['after']);
        $this->assertSame(['admin', self::ADMIN_ID], $line['actor']);
        $this->assertNull($draft->fresh()->publish_at);
        $this->assertSame(['theme.scheduled_publish_cancelled'], $this->recordedActions());
    }

    public function test_cancelling_when_nothing_is_scheduled_records_nothing(): void
    {
        $draft = $this->draftOf(theme: $this->giveTheme(name: 'Emirati', country: 'AE'));

        $this->postedSchedule(input: ['version_id' => $draft->id, 'cancel' => 1])->assertRedirect(route('admin.theme.index'));

        $this->assertSame([], $this->recordedActions());
    }

    public function test_a_schedule_that_is_refused_records_nothing_and_stores_nothing(): void
    {
        $theme = $this->giveTheme(name: 'Emirati', country: 'AE');
        $published = $this->givePublishedVersion(theme: $theme, revision: 3, spacerHeight: 250);
        $draft = $this->draftOf(theme: $theme);
        $blocked = $this->draftOf(theme: $theme);
        $this->giveBlockingSection(draft: $blocked);
        $tomorrow = $this->asFormMoment(moment: now()->addDay());
        $before = $this->rowsOf($published, $draft, $blocked);

        $refused = [
            'a moment in the past' => ['version_id' => $draft->id, 'publish_at' => $this->asFormMoment(moment: now()->subHour())],
            'something that is not a moment' => ['version_id' => $draft->id, 'publish_at' => 'not a moment'],
            'no moment' => ['version_id' => $draft->id],
            'a published version' => ['version_id' => $published->id, 'publish_at' => $tomorrow],
            'a draft that is blocked' => ['version_id' => $blocked->id, 'publish_at' => $tomorrow],
            'a version that does not exist' => ['version_id' => $blocked->id + 1000, 'publish_at' => $tomorrow],
        ];

        foreach ($refused as $why => $input) {
            $this->postedSchedule(input: $input)->assertRedirect();
            $this->assertSame([], $this->recordedActions(), "A line was recorded for {$why}.");
            $this->assertEquals($before, $this->rowsOf($published, $draft, $blocked), "A schedule was stored for {$why}.");
        }
    }

    public function test_the_lines_of_one_schedule_name_the_same_storefront_from_the_moment_it_is_set_to_the_moment_it_goes_live(): void
    {
        $draft = $this->draftOf(theme: $this->giveTheme(name: 'Emirati', country: 'AE'));
        $this->postedSchedule(input: ['version_id' => $draft->id, 'publish_at' => $this->asFormMoment(moment: now()->addDay())]);

        $this->travel(2)->days();
        $this->runDuePublishes();

        $this->assertSame(ThemeVersion::STATUS_PUBLISHED, $draft->fresh()->status);
        $this->assertSame('AE', $this->auditContext(action: 'theme.scheduled_publish_set', version: $draft)['storefront']);
        $this->assertSame('AE', $this->auditContext(action: 'theme.published', version: $draft)['storefront']);
        $this->assertSame(['theme.scheduled_publish_set', 'theme.published'], $this->recordedActions());
    }

    // ---- while themes are not kept by storefront ---------------------------------------------------------------------------------------------

    /** Every audit line this class tests, written for a theme that names a storefront wherever the database can say so: none of them may name it. */
    private function assertEveryLineIsWhatItWasBeforeStorefrontThemes(): void
    {
        $theme = $this->giveTheme(name: 'Only', country: 'AE');
        $byHand = $this->draftOf(theme: $theme);
        $this->postedSchedule(input: ['version_id' => $byHand->id, 'publish_at' => $this->asFormMoment(moment: now()->addDay())]);
        $this->postedSchedule(input: ['version_id' => $byHand->id, 'cancel' => 1]);
        $published = $this->draftOf(theme: $theme, publishAt: $this->due(minutesAgo: 30));
        $blocked = $this->draftOf(theme: $theme, publishAt: $this->due(minutesAgo: 20));
        $this->giveBlockingSection(draft: $blocked);
        $failing = $this->draftOf(theme: $theme, publishAt: $this->due(minutesAgo: 10));
        $this->failThePublishOf(draft: $failing, message: 'the database refused the publish');

        $this->runDuePublishes(exitCode: 1);

        $this->assertEquals(['theme_id' => $theme->id], $this->auditContext(action: 'theme.scheduled_publish_set', version: $byHand));
        $this->assertEquals(['theme_id' => $theme->id], $this->auditContext(action: 'theme.scheduled_publish_cancelled', version: $byHand));
        $this->assertEquals(['theme_id' => $theme->id], $this->auditContext(action: 'theme.published', version: $published));
        $this->assertEquals(['theme_id' => $theme->id], $this->auditContext(action: 'theme.scheduled_publish_cancelled', version: $blocked));
        $this->assertSame(['reasons' => [self::BLOCKING_REASON]], $this->auditLine(action: 'theme.scheduled_publish_cancelled', version: $blocked)['after']);
        $this->assertEquals(['error' => 'the database refused the publish'], $this->auditContext(action: 'theme.scheduled_publish_failed', version: $failing));
        $this->assertSame(
            ['theme.scheduled_publish_set', 'theme.scheduled_publish_cancelled', 'theme.published', 'theme.scheduled_publish_cancelled', 'theme.scheduled_publish_failed'],
            $this->recordedActions(),
        );
    }

    public function test_while_the_switch_is_off_no_audit_line_of_a_scheduled_publish_names_a_storefront(): void
    {
        config(['gcc.storefronts.themes.enabled' => false]);

        $this->assertEveryLineIsWhatItWasBeforeStorefrontThemes();
    }

    public function test_before_the_themes_migration_no_audit_line_of_a_scheduled_publish_names_a_storefront(): void
    {
        $this->buildThemeWorld(withCountryColumn: false);

        $this->assertEveryLineIsWhatItWasBeforeStorefrontThemes();
    }
}
