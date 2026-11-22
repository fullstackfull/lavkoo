<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\Admin\Settings\ThemeBuilderController;
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
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\PublishValidator;
use App\Services\Theme\ThemeCompatibilityReport;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsThemeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * GCC F-09.2: the merchant is told, before publishing a draft, which of its sections the customer app cannot draw. The compatibility report names each such section type with how many there are and
 * why, the publish check warns about each of the sections without stopping the publish (the website still draws them), and the theme list shows both next to the draft's publish button. A draft of sections the
 * app can draw says nothing of the app, and a section the merchant has hidden is not one the app is missing.
 */
class StorefrontThemeAppCompatibilityTest extends TestCase
{
    use BuildsThemeWorld;
    use RestoresLanguageFiles;

    /** What the publish check tells the merchant of a section the app cannot draw: what the section will not do, and where it will still show. */
    private const APP_WARNING = 'it_will_show_on_the_website_and_not_in_the_mobile_app';

    private const WHY_RECENTLY_VIEWED = 'backed_by_the_web_visitor_cookie_no_app_equivalent';
    private const WHY_NEWSLETTER = 'the_app_has_no_newsletter_signup_surface';

    /** The admin who publishes, recorded as the actor of what the panel does. */
    private const ADMIN_ID = 7;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        session(['local' => 'en']);
        $this->buildThemeWorld();
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /**
     * A draft of the active theme whose home page is one visible section of each of the given types and one hidden section of each of the hidden types, in that order.
     *
     * @param list<string> $types
     * @param list<string> $hidden
     */
    private function giveDraftOf(array $types, array $hidden = []): ThemeVersion
    {
        $theme = $this->giveTheme(name: 'Saudi', active: true);
        $draft = ThemeVersion::query()->create(['theme_id' => $theme->id, 'label' => 'A draft', 'status' => ThemeVersion::STATUS_DRAFT]);
        foreach (array_values([...$types, ...$hidden]) as $position => $type) {
            ThemeSection::query()->create([
                'theme_version_id' => $draft->id, 'page' => 'home', 'type' => $type, 'sort_order' => $position + 1, 'is_visible' => !in_array($type, $hidden, true), 'settings' => [],
            ]);
        }

        return $draft;
    }

    /** What the publish check warns of the app, one finding for each section. @return list<array<string, mixed>> */
    private function warningsAboutTheApp(ThemeVersion $draft): array
    {
        return array_values(array_filter(
            app(PublishValidator::class)->inspect($draft)['warnings'],
            static fn (array $finding): bool => $finding['fix_key'] === self::APP_WARNING,
        ));
    }

    private function asThePublishingAdmin(): void
    {
        $this->withoutMiddleware([
            AdminMiddleware::class, ActivationCheckMiddleware::class, ModulePermissionMiddleware::class, MaintenanceModeMiddleware::class, Localization::class,
            DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        $this->actingAs((new Admin())->forceFill(['id' => self::ADMIN_ID, 'name' => 'Theme publisher', 'admin_role_id' => 1]), 'admin');
    }

    private function themeList(): TestResponse
    {
        $this->asThePublishingAdmin();

        return $this->get(route('admin.theme.index'))->assertOk();
    }

    private function xpath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $response->getContent());
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    private function textOf(DOMNode $node): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $node->textContent));
    }

    // ---- the report: what the app cannot draw of a draft -----------------------------------------------------------------------------------

    public function test_the_report_names_each_section_type_the_app_cannot_draw_with_how_many_there_are_and_why(): void
    {
        $draft = $this->giveDraftOf(types: ['spacer', 'custom_html', 'recently_viewed', 'recently_viewed', 'newsletter'], hidden: ['blog_posts']);

        $report = app(ThemeCompatibilityReport::class)->for($draft);

        $this->assertSame(5, $report['sections']);
        $this->assertSame(2, $report['app_supported']);
        $withheld = array_column($report['withheld'], null, 'type');
        ksort($withheld);
        $this->assertSame([
            'newsletter' => ['type' => 'newsletter', 'label' => 'newsletter', 'count' => 1, 'reason' => self::WHY_NEWSLETTER],
            'recently_viewed' => ['type' => 'recently_viewed', 'label' => 'recently_viewed', 'count' => 2, 'reason' => self::WHY_RECENTLY_VIEWED],
        ], $withheld, 'the hidden blog section is not one the app is missing');
    }

    public function test_a_draft_of_sections_the_app_can_draw_reports_none(): void
    {
        $draft = $this->giveDraftOf(types: ['spacer', 'custom_html', 'faq']);

        $report = app(ThemeCompatibilityReport::class)->for($draft);

        $this->assertSame([3, 3, []], [$report['sections'], $report['app_supported'], $report['withheld']]);
    }

    // ---- the publish check: warned, and not stopped ---------------------------------------------------------------------------------------

    public function test_the_publish_check_warns_about_each_section_the_app_cannot_draw_and_does_not_stop_the_publish(): void
    {
        $draft = $this->giveDraftOf(types: ['spacer', 'custom_html', 'recently_viewed', 'recently_viewed', 'newsletter'], hidden: ['blog_posts']);

        $warnings = $this->warningsAboutTheApp(draft: $draft);

        $this->assertSame(['recently_viewed', 'recently_viewed', 'newsletter'], array_column($warnings, 'type'), 'one warning for each section, none for the hidden one');
        $this->assertSame([self::WHY_RECENTLY_VIEWED, self::WHY_RECENTLY_VIEWED, self::WHY_NEWSLETTER], array_column($warnings, 'reason_key'));
        $this->assertSame([PublishValidator::WARNING], array_values(array_unique(array_column($warnings, 'severity'))));
        $this->assertSame(['home'], array_values(array_unique(array_column($warnings, 'page'))));
        $this->assertSame([], app(PublishValidator::class)->inspect($draft)['blocking']);
        $this->assertTrue(app(PublishValidator::class)->passes($draft));
    }

    public function test_a_draft_of_sections_the_app_can_draw_has_no_warning_about_the_app(): void
    {
        $draft = $this->giveDraftOf(types: ['spacer', 'custom_html', 'faq']);

        $this->assertSame([], $this->warningsAboutTheApp(draft: $draft));
        $this->assertTrue(app(PublishValidator::class)->passes($draft));
    }

    // ---- the theme list: what the merchant sees before publishing ---------------------------------------------------------------------------

    public function test_the_theme_list_shows_the_merchant_before_publishing_which_sections_the_app_cannot_show(): void
    {
        $draft = $this->giveDraftOf(types: ['spacer', 'custom_html', 'recently_viewed', 'recently_viewed', 'newsletter'], hidden: ['blog_posts']);

        $list = $this->themeList();
        $xpath = $this->xpath(response: $list);

        $report = $list->viewData('compatibility')[$draft->theme_id];
        $counts = array_column($report['withheld'], 'count', 'type');
        ksort($counts);
        $this->assertSame(['newsletter' => 1, 'recently_viewed' => 2], $counts);
        $badges = array_filter(
            iterator_to_array($xpath->query('//span[@title]')),
            static fn (DOMElement $span): bool => str_starts_with($span->getAttribute('title'), translate('sections_the_app_cannot_show')),
        );
        $this->assertCount(1, $badges);
        $badge = reset($badges);
        $title = $badge->getAttribute('title');
        $this->assertStringStartsWith(translate('sections_the_app_cannot_show') . ': ', $title);
        $listed = explode(', ', substr($title, strlen(translate('sections_the_app_cannot_show') . ': ')));
        $expected = [translate('recently_viewed') . ' ×2', translate('newsletter')];
        sort($listed);
        sort($expected);
        $this->assertSame($expected, $listed, 'every type the app cannot draw, with how many there are of it');
        $this->assertSame(translate('app') . ' 2/5', $this->textOf(node: $badge));

        $publish = $xpath->query('//form[contains(@action, "version/publish")]')->item(0);
        $this->assertSame("return confirm('" . translate('publish_this_draft') . '? 2 ' . translate('section_types_will_not_appear_in_the_mobile_app') . "')", $publish->getAttribute('onsubmit'));

        $lines = array_map(fn (DOMNode $item): string => $this->textOf(node: $item), iterator_to_array($xpath->query('//li')));
        $counted = array_count_values($lines);
        $this->assertSame(2, $counted[translate('recently_viewed') . ' — ' . translate(self::WHY_RECENTLY_VIEWED) . '.'] ?? 0, 'one line for each of the two sections');
        $this->assertSame(1, $counted[translate('newsletter') . ' — ' . translate(self::WHY_NEWSLETTER) . '.'] ?? 0);
    }

    public function test_the_theme_list_says_the_app_draws_every_section_of_a_draft_it_can_draw_them_all_of(): void
    {
        $this->giveDraftOf(types: ['spacer', 'custom_html', 'faq']);

        $list = $this->themeList();
        $xpath = $this->xpath(response: $list);

        $badges = array_filter(
            iterator_to_array($xpath->query('//span[@title]')),
            static fn (DOMElement $span): bool => $span->getAttribute('title') === translate('every_section_of_this_draft_renders_in_the_mobile_app'),
        );
        $this->assertCount(1, $badges);
        $this->assertSame(translate('app') . ' 3/3', $this->textOf(node: reset($badges)));
        $publish = $xpath->query('//form[contains(@action, "version/publish")]')->item(0);
        $this->assertSame("return confirm('" . translate('publish_this_draft') . "?')", $publish->getAttribute('onsubmit'));
        $this->assertStringNotContainsString(translate('sections_the_app_cannot_show'), $list->getContent());
    }

    public function test_the_builder_is_handed_the_same_report_and_the_same_check_for_the_draft_it_opens(): void
    {
        $draft = $this->giveDraftOf(types: ['spacer', 'recently_viewed', 'newsletter']);
        $this->asThePublishingAdmin();

        $handedToTheBuilder = app(ThemeBuilderController::class)->index(Request::create('/', 'GET', ['version' => $draft->id]))->getData();

        $this->assertSame([3, 1], [$handedToTheBuilder['compatibility']['sections'], $handedToTheBuilder['compatibility']['app_supported']]);
        $withheld = array_column($handedToTheBuilder['compatibility']['withheld'], 'reason', 'type');
        ksort($withheld);
        $this->assertSame(['newsletter' => self::WHY_NEWSLETTER, 'recently_viewed' => self::WHY_RECENTLY_VIEWED], $withheld);
        $told = array_filter($handedToTheBuilder['publishCheck']['warnings'], static fn (array $finding): bool => $finding['fix_key'] === self::APP_WARNING);
        $this->assertSame(['recently_viewed', 'newsletter'], array_column($told, 'type'));
        $this->assertSame([], $handedToTheBuilder['publishCheck']['blocking']);
    }

    public function test_publishing_goes_ahead_and_the_merchant_is_told_what_the_app_will_not_show(): void
    {
        $draft = $this->giveDraftOf(types: ['spacer', 'recently_viewed', 'newsletter']);
        $this->asThePublishingAdmin();

        $this->post(route('admin.theme.version.publish'), ['version_id' => $draft->id])->assertRedirect(route('admin.theme.index'));

        $this->assertSame(ThemeVersion::STATUS_PUBLISHED, $draft->fresh()->status);
        $findings = session('theme_publish_findings');
        $this->assertSame([], $findings['blocking']);
        $told = array_filter($findings['warnings'], static fn (array $finding): bool => $finding['fix_key'] === self::APP_WARNING);
        $this->assertSame(['recently_viewed', 'newsletter'], array_column($told, 'type'));
    }
}
