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
use App\Models\AdminRole;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsAuditLogTable;
use Tests\Support\BuildsThemeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * GCC F-02: the audit lines of a scheduled publish name the storefront. An admin sets, changes or unsets the moment a draft goes live, and theme:publish-due publishes the draft whose moment
 * has come, cancels the one that no longer passes the check a manual publish passes, and records the one whose publish failed. Every one of those lines says which storefront the theme is for,
 * a due publish of one storefront never touches the theme or the schedule of another, and while themes are not kept by storefront the lines carry no storefront. Whichever of two storefronts'
 * themes was created first, each line names the storefront of its own version's theme: the themes of two storefronts are created in both orders wherever a line is about one of them.
 * The two lines of the admin's schedule and unset are written whether or not themes are kept by storefront: only their storefront is not.
 */
class StorefrontThemeScheduledPublishTest extends TestCase
{
    use BuildsAuditLogTable;
    use BuildsThemeWorld;
    use RestoresLanguageFiles;

    /** What a section that waits for the merchant's choice makes the reasons of a blocked publish say: its label, then why. */
    private const BLOCKING_REASON = 'category_showcase: choose_a_category_for_this_section_to_have_something_to_show';

    /** The admin who sets and unsets schedules, recorded as the actor of the lines. */
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

    /** @return array<string, Theme> the theme of each of two storefronts by its country, the first created before the second: what the id of a theme is says nothing about its storefront */
    private function giveThemesOfTwoStorefronts(string $first, string $second): array
    {
        return [
            $first => $this->giveTheme(name: "Theme of {$first}", country: $first),
            $second => $this->giveTheme(name: "Theme of {$second}", country: $second),
        ];
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

    /** Makes the read of any theme fail from now on, the way a database that went away would. */
    private function failTheReadOfEveryTheme(): void
    {
        Theme::retrieved(static function (): void {
            throw new RuntimeException('the database went away');
        });
    }

    private function assertTheReadOfAThemeFailureWasTheOnlyOneReported(): void
    {
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'the database went away');
        Exceptions::assertReportedCount(count: 1);
    }

    private function runDuePublishes(int $exitCode = 0): void
    {
        $this->artisan('theme:publish-due')->assertExitCode($exitCode);
    }

    /**
     * The moment as the datetime-local field of the schedule form sends it: the wall clock of the market whose theme it is (F-08).
     *
     * The field carries no zone, and since F-08 the server reads it on that market's own clock rather than on the platform's single
     * `Asia/Dhaka`. So a test that wants to schedule the ABSOLUTE moment `$moment` has to type that moment as the operator of that market
     * would see it on their own clock -- and then everything these tests already assert about the stored moment and the audit line holds
     * unchanged. Typing the platform's wall clock instead silently shifted every one of them by the market's offset, and turned "an hour
     * ago" in Dhaka into an hour from now in Dubai.
     */
    private function asFormMoment(Carbon $moment, ?string $storefront = 'SA'): string
    {
        $zone = ($storefront === null ? null : config("gcc.storefronts.countries.{$storefront}.timezone")) ?? config('app.timezone');

        return $moment->copy()->setTimezone((string) $zone)->format('Y-m-d\TH:i');
    }

    private function masterAdmin(): Admin
    {
        return (new Admin())->forceFill(['id' => self::ADMIN_ID, 'name' => 'Theme publisher', 'admin_role_id' => 1]);
    }

    /** An admin whose role holds the theme module, so that it may edit themes, and was never granted theme_publish. */
    private function adminWhoMayNotPublish(): Admin
    {
        $role = (new AdminRole())->forceFill(['module_access' => json_encode(['themes_and_addons'])]);

        return (new Admin())->forceFill(['id' => self::ADMIN_ID, 'name' => 'Theme editor', 'admin_role_id' => 2])->setRelation('role', $role);
    }

    /** @param array<string, mixed> $input */
    private function postedSchedule(array $input, ?Admin $admin = null): TestResponse
    {
        $this->withoutMiddleware([
            AdminMiddleware::class, ActivationCheckMiddleware::class, ModulePermissionMiddleware::class, MaintenanceModeMiddleware::class, Localization::class,
            DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        $this->actingAs($admin ?? $this->masterAdmin(), 'admin');

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

    /** @return array<string, array{0: string, 1: string}> the storefront whose theme is created first and the one whose theme is created second */
    public static function creationOrdersOfTwoStorefronts(): array
    {
        return ['Emirati theme created first' => ['AE', 'SA'], 'Saudi theme created first' => ['SA', 'AE']];
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> the creation orders, and whether the Emirati version is due before the Saudi one or after it */
    public static function creationAndDueOrdersOfTwoStorefronts(): array
    {
        return [
            'Emirati theme created first, Emirati version due first' => ['AE', 'SA', true],
            'Emirati theme created first, Saudi version due first' => ['AE', 'SA', false],
            'Saudi theme created first, Emirati version due first' => ['SA', 'AE', true],
            'Saudi theme created first, Saudi version due first' => ['SA', 'AE', false],
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

    /** @return array<string, array{0: string, 1: string, 2: bool}> the storefront whose publish is due, the storefront whose publish is not, and whether the theme of the first is created before the theme of the other */
    public static function dueAndLaterStorefronts(): array
    {
        return [
            'Emirati due, Saudi later, Emirati theme created first' => ['AE', 'SA', true],
            'Emirati due, Saudi later, Saudi theme created first' => ['AE', 'SA', false],
            'Saudi due, Emirati later, Saudi theme created first' => ['SA', 'AE', true],
            'Saudi due, Emirati later, Emirati theme created first' => ['SA', 'AE', false],
        ];
    }

    #[DataProvider('dueAndLaterStorefronts')]
    public function test_a_due_publish_of_one_storefront_leaves_the_theme_and_the_schedule_of_the_other_as_they_were(string $due, string $later, bool $dueThemeCreatedFirst): void
    {
        $themes = $dueThemeCreatedFirst
            ? $this->giveThemesOfTwoStorefronts(first: $due, second: $later)
            : $this->giveThemesOfTwoStorefronts(first: $later, second: $due);
        $previous = $this->givePublishedVersion(theme: $themes[$due], revision: 3, spacerHeight: 250);
        $dueDraft = $this->draftOf(theme: $themes[$due], publishAt: $this->due());
        $published = $this->givePublishedVersion(theme: $themes[$later], revision: 7, spacerHeight: 100);
        $laterDraft = $this->draftOf(theme: $themes[$later], publishAt: $this->notYetDue());
        $otherStorefrontsRows = $this->rowsOf($published, $laterDraft);

        $this->runDuePublishes();

        $this->assertSame([ThemeVersion::STATUS_PUBLISHED, 4, null], [$dueDraft->fresh()->status, $dueDraft->fresh()->revision, $dueDraft->fresh()->publish_at]);
        $this->assertSame(ThemeVersion::STATUS_ARCHIVED, $previous->fresh()->status);
        $this->assertEquals($otherStorefrontsRows, $this->rowsOf($published, $laterDraft));
        $this->assertSame(['theme.published'], $this->recordedActions());
        $this->assertEquals(['theme_id' => $themes[$due]->id, 'storefront' => $due], $this->auditContext(action: 'theme.published', version: $dueDraft));
    }

    #[DataProvider('creationOrdersOfTwoStorefronts')]
    public function test_two_due_publishes_of_two_storefronts_each_go_live_and_each_line_names_its_own_storefront(string $first, string $second): void
    {
        $themes = $this->giveThemesOfTwoStorefronts(first: $first, second: $second);
        $emiratiDraft = $this->draftOf(theme: $themes['AE'], publishAt: $this->due(minutesAgo: 10));
        $saudiDraft = $this->draftOf(theme: $themes['SA'], publishAt: $this->due(minutesAgo: 5));

        $this->runDuePublishes();

        $this->assertSame([ThemeVersion::STATUS_PUBLISHED, ThemeVersion::STATUS_PUBLISHED], [$emiratiDraft->fresh()->status, $saudiDraft->fresh()->status]);
        $this->assertEquals(['theme_id' => $themes['AE']->id, 'storefront' => 'AE'], $this->auditContext(action: 'theme.published', version: $emiratiDraft));
        $this->assertEquals(['theme_id' => $themes['SA']->id, 'storefront' => 'SA'], $this->auditContext(action: 'theme.published', version: $saudiDraft));
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
        $this->assertSame(['system', 0], $line['actor'], 'the command is the actor, which is what tells its line from the admin\'s unset');
        $this->assertSame([ThemeVersion::STATUS_DRAFT, null], [$draft->fresh()->status, $draft->fresh()->publish_at]);
        $this->assertSame(ThemeVersion::STATUS_PUBLISHED, $previous->fresh()->status);
        $this->assertSame(['theme.scheduled_publish_cancelled'], $this->recordedActions());
    }

    #[DataProvider('creationAndDueOrdersOfTwoStorefronts')]
    public function test_a_blocked_publish_of_one_storefront_does_not_stop_the_publish_of_another_and_each_line_names_its_own_storefront(string $first, string $second, bool $blockedDueFirst): void
    {
        $themes = $this->giveThemesOfTwoStorefronts(first: $first, second: $second);
        $this->givePublishedVersion(theme: $themes['SA'], revision: 7, spacerHeight: 100);
        $blocked = $this->draftOf(theme: $themes['AE'], publishAt: $this->due(minutesAgo: $blockedDueFirst ? 10 : 5));
        $this->giveBlockingSection(draft: $blocked);
        $fine = $this->draftOf(theme: $themes['SA'], publishAt: $this->due(minutesAgo: $blockedDueFirst ? 5 : 10));

        $this->runDuePublishes();

        $cancelled = $this->auditLine(action: 'theme.scheduled_publish_cancelled', version: $blocked);
        $this->assertEquals(['theme_id' => $themes['AE']->id, 'storefront' => 'AE'], $cancelled['context']);
        $this->assertSame(['reasons' => [self::BLOCKING_REASON]], $cancelled['after']);
        $this->assertSame(['system', 0], $cancelled['actor']);
        $this->assertEquals(['theme_id' => $themes['SA']->id, 'storefront' => 'SA'], $this->auditContext(action: 'theme.published', version: $fine));
        $this->assertSame([ThemeVersion::STATUS_DRAFT, ThemeVersion::STATUS_PUBLISHED], [$blocked->fresh()->status, $fine->fresh()->status]);
        $this->assertSame(
            $blockedDueFirst ? ['theme.scheduled_publish_cancelled', 'theme.published'] : ['theme.published', 'theme.scheduled_publish_cancelled'],
            $this->recordedActions(),
        );
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

        $line = $this->auditLine(action: 'theme.scheduled_publish_failed', version: $draft);
        $this->assertEquals(['error' => 'the database refused the publish', 'storefront' => $storefront], $line['context']);
        $this->assertSame(['system', 0], $line['actor']);
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

    #[DataProvider('creationOrdersOfTwoStorefronts')]
    public function test_a_failed_publish_of_one_storefront_leaves_the_schedule_of_another_for_the_next_run_and_each_line_names_its_own_storefront(string $first, string $second): void
    {
        $themes = $this->giveThemesOfTwoStorefronts(first: $first, second: $second);
        $failing = $this->draftOf(theme: $themes['AE'], publishAt: $this->due(minutesAgo: 10));
        $waiting = $this->draftOf(theme: $themes['SA'], publishAt: $this->due(minutesAgo: 5));
        $this->failThePublishOf(draft: $failing, message: 'the database refused the publish');
        $waitingRows = $this->rowsOf($waiting);

        $this->runDuePublishes(exitCode: 1);

        $failed = $this->auditLine(action: 'theme.scheduled_publish_failed', version: $failing);
        $this->assertEquals(['error' => 'the database refused the publish', 'storefront' => 'AE'], $failed['context']);
        $this->assertSame(['system', 0], $failed['actor']);
        $this->assertSame([ThemeVersion::STATUS_DRAFT, null], [$failing->fresh()->status, $failing->fresh()->publish_at], 'the publish was rolled back, and its schedule is not retried');
        $this->assertEquals($waitingRows, $this->rowsOf($waiting), 'a run stops at the failure, so the other storefront waits for the next run with its schedule as it was');
        $this->assertSame(['theme.scheduled_publish_failed'], $this->recordedActions());

        $this->runDuePublishes();

        $this->assertSame(ThemeVersion::STATUS_PUBLISHED, $waiting->fresh()->status);
        $this->assertEquals(['theme_id' => $themes['SA']->id, 'storefront' => 'SA'], $this->auditContext(action: 'theme.published', version: $waiting));
        $this->assertSame(['theme.scheduled_publish_failed', 'theme.published'], $this->recordedActions());
    }

    #[DataProvider('creationOrdersOfTwoStorefronts')]
    public function test_a_failed_publish_after_the_publish_of_another_storefront_is_recorded_with_its_own_storefront_and_leaves_the_other_published(string $first, string $second): void
    {
        $themes = $this->giveThemesOfTwoStorefronts(first: $first, second: $second);
        $fine = $this->draftOf(theme: $themes['SA'], publishAt: $this->due(minutesAgo: 10));
        $failing = $this->draftOf(theme: $themes['AE'], publishAt: $this->due(minutesAgo: 5));
        $this->failThePublishOf(draft: $failing, message: 'the database refused the publish');

        $this->runDuePublishes(exitCode: 1);

        $this->assertSame([ThemeVersion::STATUS_PUBLISHED, ThemeVersion::STATUS_DRAFT], [$fine->fresh()->status, $failing->fresh()->status]);
        $this->assertEquals(['theme_id' => $themes['SA']->id, 'storefront' => 'SA'], $this->auditContext(action: 'theme.published', version: $fine));
        $this->assertEquals(['error' => 'the database refused the publish', 'storefront' => 'AE'], $this->auditContext(action: 'theme.scheduled_publish_failed', version: $failing));
        $this->assertSame(['theme.published', 'theme.scheduled_publish_failed'], $this->recordedActions());
    }

    // ---- a version whose theme is gone, and a dry run ----------------------------------------------------------------------------------------

    public function test_a_due_publish_of_a_version_whose_theme_no_longer_exists_is_still_recorded_without_a_storefront_and_nothing_but_the_failed_publish_is_reported(): void
    {
        $theme = $this->giveTheme(name: 'Gone', country: 'AE');
        $blocked = $this->draftOf(theme: $theme, publishAt: $this->due(minutesAgo: 10));
        $this->giveBlockingSection(draft: $blocked);
        $failing = $this->draftOf(theme: $theme, publishAt: $this->due(minutesAgo: 5));
        $this->failThePublishOf(draft: $failing, message: 'the database refused the publish');
        $theme->delete();
        Exceptions::fake();

        $this->runDuePublishes(exitCode: 1);

        $this->assertEquals(['theme_id' => $theme->id], $this->auditContext(action: 'theme.scheduled_publish_cancelled', version: $blocked));
        $this->assertEquals(['error' => 'the database refused the publish'], $this->auditContext(action: 'theme.scheduled_publish_failed', version: $failing));
        Exceptions::assertReportedCount(count: 1);
    }

    public function test_a_blocked_publish_is_still_cancelled_and_recorded_when_the_storefront_of_its_theme_cannot_be_read(): void
    {
        $theme = $this->giveTheme(name: 'Emirati', country: 'AE');
        $blocked = $this->draftOf(theme: $theme, publishAt: $this->due());
        $this->giveBlockingSection(draft: $blocked);
        $this->failTheReadOfEveryTheme();
        Exceptions::fake();

        $this->runDuePublishes();

        $line = $this->auditLine(action: 'theme.scheduled_publish_cancelled', version: $blocked);
        $this->assertEquals(['theme_id' => $theme->id], $line['context']);
        $this->assertSame(['reasons' => [self::BLOCKING_REASON]], $line['after']);
        $this->assertSame([ThemeVersion::STATUS_DRAFT, null], [$blocked->fresh()->status, $blocked->fresh()->publish_at]);
        $this->assertTheReadOfAThemeFailureWasTheOnlyOneReported();
    }

    public function test_a_failed_publish_is_still_recorded_and_reported_when_the_storefront_of_its_theme_cannot_be_read(): void
    {
        $failing = $this->draftOf(theme: $this->giveTheme(name: 'Emirati', country: 'AE'), publishAt: $this->due());
        $this->failThePublishOf(draft: $failing, message: 'the database refused the publish');
        $this->failTheReadOfEveryTheme();
        Exceptions::fake();

        $this->artisan('theme:publish-due')->expectsOutputToContain('failed to publish: the database refused the publish')->assertExitCode(1);

        $this->assertEquals(['error' => 'the database refused the publish'], $this->auditContext(action: 'theme.scheduled_publish_failed', version: $failing));
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'the database refused the publish');
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'the database went away');
        Exceptions::assertReportedCount(count: 2);
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

        $this->postedSchedule(input: ['version_id' => $draft->id, 'publish_at' => $this->asFormMoment(moment: $moment, storefront: $storefront)])->assertRedirect(route('admin.theme.index'));

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

        $this->postedSchedule(input: ['version_id' => $draft->id, 'publish_at' => $this->asFormMoment(moment: $later, storefront: 'AE')])->assertRedirect(route('admin.theme.index'));

        $line = $this->auditLine(action: 'theme.scheduled_publish_set', version: $draft);
        $this->assertSame(['publish_at' => $earlier->toIso8601String()], $line['before']);
        $this->assertSame(['publish_at' => $later->toIso8601String()], $line['after']);
        $this->assertSame('AE', $line['context']['storefront']);
    }

    #[DataProvider('storefrontsOfThemes')]
    public function test_unsetting_a_schedule_records_the_moment_it_was_the_admin_and_the_storefront_under_a_name_of_its_own(?string $country, string $storefront): void
    {
        $theme = $this->giveTheme(name: 'Scheduled', country: $country);
        $moment = now()->addDay()->startOfMinute();
        $draft = $this->draftOf(theme: $theme, publishAt: $moment);

        $this->postedSchedule(input: ['version_id' => $draft->id, 'cancel' => 1])->assertRedirect(route('admin.theme.index'));

        $line = $this->auditLine(action: 'theme.scheduled_publish_unset', version: $draft);
        $this->assertEquals(['theme_id' => $theme->id, 'storefront' => $storefront], $line['context']);
        $this->assertSame(['publish_at' => $moment->toIso8601String()], $line['before']);
        $this->assertSame(['publish_at' => null], $line['after']);
        $this->assertSame(['admin', self::ADMIN_ID], $line['actor']);
        $this->assertNull($draft->fresh()->publish_at);
        $this->assertSame(['theme.scheduled_publish_unset'], $this->recordedActions());
    }

    public function test_unsetting_when_nothing_is_scheduled_records_nothing(): void
    {
        $draft = $this->draftOf(theme: $this->giveTheme(name: 'Emirati', country: 'AE'));

        $this->postedSchedule(input: ['version_id' => $draft->id, 'cancel' => 1])->assertRedirect(route('admin.theme.index'));

        $this->assertSame([], $this->recordedActions());
    }

    public function test_a_schedule_that_the_action_refuses_for_the_draft_or_the_moment_records_nothing_and_stores_nothing(): void
    {
        $theme = $this->giveTheme(name: 'Emirati', country: 'AE');
        $published = $this->givePublishedVersion(theme: $theme, revision: 3, spacerHeight: 250);
        $draft = $this->draftOf(theme: $theme);
        $blocked = $this->draftOf(theme: $theme);
        $this->giveBlockingSection(draft: $blocked);
        $tomorrow = $this->asFormMoment(moment: now()->addDay(), storefront: 'AE');
        $before = $this->rowsOf($published, $draft, $blocked);

        $refused = [
            'a moment in the past' => ['version_id' => $draft->id, 'publish_at' => $this->asFormMoment(moment: now()->subHour(), storefront: 'AE')],
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

    /** A schedule and an unset posted as $admin, which change no row and write no line. */
    private function assertAScheduleAndAnUnsetAreRefusedTo(?Admin $admin): void
    {
        $theme = $this->giveTheme(name: 'Emirati', country: 'AE');
        $scheduled = $this->draftOf(theme: $theme, publishAt: now()->addDay()->startOfMinute());
        $unscheduled = $this->draftOf(theme: $theme);
        $before = $this->rowsOf($scheduled, $unscheduled);

        $this->postedSchedule(input: ['version_id' => $unscheduled->id, 'publish_at' => $this->asFormMoment(moment: now()->addDays(2), storefront: 'AE')], admin: $admin)->assertRedirect();
        $this->postedSchedule(input: ['version_id' => $scheduled->id, 'cancel' => 1], admin: $admin)->assertRedirect();

        $this->assertEquals($before, $this->rowsOf($scheduled, $unscheduled));
        $this->assertSame([], $this->recordedActions());
    }

    public function test_a_schedule_and_an_unset_by_an_admin_who_may_not_publish_record_nothing_and_store_nothing(): void
    {
        $this->assertAScheduleAndAnUnsetAreRefusedTo(admin: $this->adminWhoMayNotPublish());
    }

    public function test_a_schedule_and_an_unset_on_the_demo_record_nothing_and_store_nothing(): void
    {
        config(['app.mode' => 'demo']);

        $this->assertAScheduleAndAnUnsetAreRefusedTo(admin: null);
    }

    #[DataProvider('creationOrdersOfTwoStorefronts')]
    public function test_the_lines_of_a_schedule_set_and_unset_by_hand_name_the_storefront_of_the_draft_whichever_theme_was_created_first(string $first, string $second): void
    {
        $themes = $this->giveThemesOfTwoStorefronts(first: $first, second: $second);
        $emiratiDraft = $this->draftOf(theme: $themes['AE']);
        $saudiDraft = $this->draftOf(theme: $themes['SA']);
        $moment = now()->addDay()->startOfMinute();

        $this->postedSchedule(input: ['version_id' => $emiratiDraft->id, 'publish_at' => $this->asFormMoment(moment: $moment, storefront: 'AE')]);
        $this->postedSchedule(input: ['version_id' => $saudiDraft->id, 'publish_at' => $this->asFormMoment(moment: $moment, storefront: 'SA')]);
        $this->postedSchedule(input: ['version_id' => $emiratiDraft->id, 'cancel' => 1]);

        $this->assertNull($emiratiDraft->fresh()->publish_at);
        $this->assertTrue($saudiDraft->fresh()->publish_at->equalTo($moment), 'the schedule of the other storefront is as it was');

        $this->postedSchedule(input: ['version_id' => $saudiDraft->id, 'cancel' => 1]);

        $this->assertEquals(['theme_id' => $themes['AE']->id, 'storefront' => 'AE'], $this->auditContext(action: 'theme.scheduled_publish_set', version: $emiratiDraft));
        $this->assertEquals(['theme_id' => $themes['SA']->id, 'storefront' => 'SA'], $this->auditContext(action: 'theme.scheduled_publish_set', version: $saudiDraft));
        $this->assertEquals(['theme_id' => $themes['AE']->id, 'storefront' => 'AE'], $this->auditContext(action: 'theme.scheduled_publish_unset', version: $emiratiDraft));
        $this->assertEquals(['theme_id' => $themes['SA']->id, 'storefront' => 'SA'], $this->auditContext(action: 'theme.scheduled_publish_unset', version: $saudiDraft));
        $this->assertSame(
            ['theme.scheduled_publish_set', 'theme.scheduled_publish_set', 'theme.scheduled_publish_unset', 'theme.scheduled_publish_unset'],
            $this->recordedActions(),
        );
    }

    public function test_a_schedule_is_stored_and_recorded_when_the_storefront_of_its_theme_cannot_be_read(): void
    {
        $theme = $this->giveTheme(name: 'Emirati', country: 'AE');
        $draft = $this->draftOf(theme: $theme);
        $moment = now()->addDay()->startOfMinute();
        $this->failTheReadOfEveryTheme();
        Exceptions::fake();

        // `storefront: null` because the theme read FAILS here, so the server has no market whose clock to read the typed value on and
        // falls back to the platform's -- which is the clock this screen used for every market before F-08.
        $this->postedSchedule(input: ['version_id' => $draft->id, 'publish_at' => $this->asFormMoment(moment: $moment, storefront: null)])->assertRedirect(route('admin.theme.index'));

        $this->assertTrue($draft->fresh()->publish_at->equalTo($moment));
        $this->assertEquals(['theme_id' => $theme->id], $this->auditContext(action: 'theme.scheduled_publish_set', version: $draft));
        $this->assertTheReadOfAThemeFailureWasTheOnlyOneReported();
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
        $this->assertEquals(['theme_id' => $theme->id], $this->auditContext(action: 'theme.scheduled_publish_unset', version: $byHand));
        $this->assertEquals(['theme_id' => $theme->id], $this->auditContext(action: 'theme.published', version: $published));
        $this->assertEquals(['theme_id' => $theme->id], $this->auditContext(action: 'theme.scheduled_publish_cancelled', version: $blocked));
        $this->assertSame(['reasons' => [self::BLOCKING_REASON]], $this->auditLine(action: 'theme.scheduled_publish_cancelled', version: $blocked)['after']);
        $this->assertEquals(['error' => 'the database refused the publish'], $this->auditContext(action: 'theme.scheduled_publish_failed', version: $failing));
        $this->assertSame(
            ['theme.scheduled_publish_set', 'theme.scheduled_publish_unset', 'theme.published', 'theme.scheduled_publish_cancelled', 'theme.scheduled_publish_failed'],
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
