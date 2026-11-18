<?php

namespace Tests\Feature\Storefront;

use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\DeveloperPortal\ApiManifest;
use App\Services\Theme\ComponentCapabilityRegistry;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\ViewerContext;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * GCC F-09.0 and F-09.5: what the customer app is sent of the published theme, and the shape it is sent it in.
 *
 * A client that declares nothing is sent every section the app can draw; one that declares the section types it holds is sent those it can draw and is told, in `compatibility.withheld`, which
 * it was not sent and why. The shapes of /api/v1/theme/home and /api/v1/theme/version are the ones the apps parse (lib/features/dynamic_theme/domain/models/theme_schema.dart of the customer app): a key
 * that is added or goes is a change of the contract and has to be made on purpose, with this test. The developer portal documents the endpoints, and the header that picks the storefront is named there.
 */
class StorefrontThemeAppContractTest extends TestCase
{
    use BuildsThemeWorld;

    /** The section types no app build draws, and why, as the app is told. */
    private const NEVER_SENT_TO_THE_APP = [
        'blog_posts' => 'the_blog_module_exposes_no_public_api',
        'footer_columns' => 'the_app_draws_its_own_navigation_chrome',
        'newsletter' => 'the_app_has_no_newsletter_signup_surface',
        'recently_viewed' => 'backed_by_the_web_visitor_cookie_no_app_equivalent',
        'trending_searches' => 'no_public_endpoint_for_search_terms',
        'vendor_showcase' => 'needs_a_by_id_shop_products_endpoint_the_api_only_serves_by_slug',
    ];

    /** Why a section the app can draw is withheld from a build that did not say it holds it. */
    private const NOT_REPORTED = 'this_build_does_not_report_support_for_this_section';

    /** Why a section no renderer exists for, and nobody decided about, is withheld. */
    private const NO_RENDERER = 'no_app_renderer_exists_for_this_section';

    private const PAGE_KEYS = ['page', 'revision', 'content_stamp', 'schema_version', 'engine_version', 'checksum', 'published_at', 'tokens', 'sections', 'compatibility'];

    /** The page of a storefront with no theme published: the same, without the stamp the app syncs against. */
    private const EMPTY_PAGE_KEYS = ['page', 'revision', 'schema_version', 'engine_version', 'checksum', 'published_at', 'tokens', 'sections', 'compatibility'];

    private const SECTION_KEYS = ['uuid', 'id', 'type', 'variant', 'component_version', 'settings', 'blocks', 'cards', 'source', 'experiment', 'view_all'];
    private const BLOCK_KEYS = ['id', 'type', 'settings'];
    private const TOKEN_KEYS = ['colors', 'typography', 'layout', 'branding'];
    private const COMPATIBILITY_KEYS = ['delivered', 'withheld'];
    private const VERSION_KEYS = ['revision', 'checksum', 'schema_version', 'engine_version', 'published_at'];

    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildThemeWorld();
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();

        parent::tearDown();
    }

    /**
     * A published theme whose home page is a spacer and then one section of each of the given types, in that order.
     *
     * @param list<string> $types
     */
    private function givePublishedHomeOf(array $types): ThemeVersion
    {
        $theme = $this->giveTheme(name: 'Saudi', active: true);
        $version = $this->givePublishedVersion(theme: $theme, revision: 4, spacerHeight: 41);
        foreach (array_values($types) as $position => $type) {
            ThemeSection::query()->create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => $type, 'sort_order' => $position + 2, 'settings' => []]);
        }

        return $version;
    }

    private function openStorefrontsFor(bool $storefrontsOn): void
    {
        if ($storefrontsOn) {
            $this->switchStorefrontThemesOn();
            $this->openEveryStorefront();
        }
    }

    /** @param array<string, string> $headers */
    private function home(array $headers = []): TestResponse
    {
        return $this->getJson('/api/v1/theme/home', $headers)->assertOk();
    }

    /** @return list<string> */
    private function deliveredTypes(TestResponse $home): array
    {
        return array_column($home->json('sections'), 'type');
    }

    /** @return array<string, string> the types the app was told it was not sent, with why, by type */
    private function withheld(TestResponse $home): array
    {
        $withheld = $home->json('compatibility.withheld');
        ksort($withheld);

        return $withheld;
    }

    /** @param list<string> $expected */
    private function assertKeys(array $expected, mixed $actual, string $of): void
    {
        $this->assertIsArray($actual, $of);
        sort($expected);
        $keys = array_keys($actual);
        sort($keys);

        $this->assertSame($expected, $keys, "the keys of {$of}");
    }

    // ---- F-09.0: what a client is sent, by what it declares -------------------------------------------------------------------------------

    public function test_a_client_that_declares_nothing_is_sent_every_app_safe_section_and_told_which_it_is_not_sent(): void
    {
        $types = array_keys(app(SectionRegistry::class)->types());
        $this->assertGreaterThan(30, count($types), 'the home page is built of every type there is');
        $this->givePublishedHomeOf(types: array_diff($types, ['spacer']));

        $home = $this->home();

        $sent = ['spacer', ...array_values(array_diff($types, ['spacer'], array_keys(self::NEVER_SENT_TO_THE_APP)))];
        $this->assertSame($sent, $this->deliveredTypes($home), 'a section type that is neither sent nor named here is a type the app was never given a decision about: add it to the registry of what the app draws, or to the ones it does not');
        $this->assertSame(count($sent), $home->json('compatibility.delivered'));
        $this->assertSame(self::NEVER_SENT_TO_THE_APP, $this->withheld($home));
    }

    public function test_a_client_that_names_the_section_types_it_can_draw_is_not_sent_the_others_and_is_told_why(): void
    {
        $this->givePublishedHomeOf(types: ['faq', 'custom_html', 'usp_strip', 'recently_viewed', 'newsletter']);

        $home = $this->home(headers: ['X-UI-Components' => 'spacer,faq,newsletter', 'X-UI-Engine' => '1', 'X-UI-Schema' => '1']);

        $this->assertSame(['spacer', 'faq'], $this->deliveredTypes($home));
        $this->assertSame(2, $home->json('compatibility.delivered'));
        $this->assertSame([
            'custom_html' => self::NOT_REPORTED,
            'newsletter' => self::NEVER_SENT_TO_THE_APP['newsletter'],
            'recently_viewed' => self::NEVER_SENT_TO_THE_APP['recently_viewed'],
            'usp_strip' => self::NOT_REPORTED,
        ], $this->withheld($home));
    }

    public function test_a_client_that_names_its_engine_and_no_components_is_sent_every_app_safe_section(): void
    {
        $this->givePublishedHomeOf(types: ['faq', 'custom_html', 'recently_viewed']);

        $home = $this->home(headers: ['X-UI-Engine' => '1']);

        $this->assertSame(['spacer', 'faq', 'custom_html'], $this->deliveredTypes($home));
        $this->assertSame(['recently_viewed' => self::NEVER_SENT_TO_THE_APP['recently_viewed']], $this->withheld($home));
    }

    public function test_a_section_type_nobody_decided_about_is_withheld_from_every_client_whatever_it_declares(): void
    {
        $this->givePublishedHomeOf(types: ['mystery_widget']);

        $declaresNothing = $this->home();
        $declaresIt = $this->home(headers: ['X-UI-Components' => 'spacer,mystery_widget']);

        foreach ([$declaresNothing, $declaresIt] as $home) {
            $this->assertSame(['spacer'], $this->deliveredTypes($home));
            $this->assertSame(['mystery_widget' => self::NO_RENDERER], $this->withheld($home));
        }
    }

    public function test_a_viewer_that_declares_no_components_can_draw_everything_and_one_that_declares_some_can_draw_those_and_no_other(): void
    {
        $declaresNothing = new ViewerContext(platform: ViewerContext::PLATFORM_APP);
        $declaresTwo = new ViewerContext(platform: ViewerContext::PLATFORM_APP, uiEngineVersion: 1, supportedComponents: ['spacer', 'newsletter']);
        $registry = app(ComponentCapabilityRegistry::class);

        $this->assertTrue($declaresNothing->canRender(type: 'anything_at_all'));
        $this->assertTrue($declaresTwo->canRender(type: 'spacer'));
        $this->assertFalse($declaresTwo->canRender(type: 'custom_html'));
        $this->assertFalse($declaresTwo->canRender(type: 'SPACER'), 'a declared type is matched as it is spelt');

        $this->assertTrue($registry->clientHolds(type: 'custom_html', viewer: $declaresNothing));
        $this->assertTrue($registry->clientHolds(type: 'spacer', viewer: $declaresTwo));
        $this->assertFalse($registry->clientHolds(type: 'custom_html', viewer: $declaresTwo), 'a type the client did not declare');
        $this->assertFalse($registry->clientHolds(type: 'newsletter', viewer: $declaresTwo), 'a type the app has no renderer for, whatever the client declares');
        $this->assertFalse($registry->clientHolds(type: 'recently_viewed', viewer: $declaresNothing), 'a type the app has no renderer for, for a client that declares nothing');
    }

    // ---- F-09.5: the shapes the apps parse --------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: bool, 1: array<string, string>}> whether storefronts are on, and the header the app sends: the shape is the same for all of them */
    public static function theAppFromEveryStorefront(): array
    {
        return [
            'storefronts off, no header' => [false, []],
            'storefronts on, the default storefront' => [true, ['X-Storefront' => 'SA']],
            'storefronts on, another storefront' => [true, ['X-Storefront' => 'AE']],
        ];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('theAppFromEveryStorefront')]
    public function test_theme_home_has_the_shape_the_apps_parse(bool $storefrontsOn, array $headers): void
    {
        $this->openStorefrontsFor(storefrontsOn: $storefrontsOn);
        $version = $this->givePublishedHomeOf(types: ['usp_strip']);
        ThemeBlock::query()->create([
            'theme_section_id' => $version->sections()->where('type', 'usp_strip')->firstOrFail()->id, 'type' => 'usp', 'sort_order' => 1, 'settings' => ['title' => 'Free delivery', 'icon' => 'shipping'],
        ]);

        $home = $this->home(headers: $headers);
        $home->assertJsonStructure([
            'page', 'revision', 'content_stamp', 'schema_version', 'engine_version', 'checksum', 'published_at',
            'tokens' => self::TOKEN_KEYS,
            'sections' => ['*' => self::SECTION_KEYS],
            'compatibility' => self::COMPATIBILITY_KEYS,
        ]);
        $body = $home->json();

        $this->assertKeys(expected: self::PAGE_KEYS, actual: $body, of: 'the page');
        $this->assertSame('home', $body['page']);
        $this->assertSame(4, $body['revision']);
        $this->assertIsInt($body['schema_version']);
        $this->assertIsInt($body['engine_version']);
        $this->assertSame('checksum-of-saudi', $body['content_stamp']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $body['checksum']);
        $this->assertMatchesRegularExpression(self::ISO_8601, $body['published_at']);
        $this->assertKeys(expected: self::TOKEN_KEYS, actual: $body['tokens'], of: 'the design tokens');
        foreach (self::TOKEN_KEYS as $group) {
            $this->assertIsArray($body['tokens'][$group], "the {$group} tokens");
        }
        $this->assertKeys(expected: self::COMPATIBILITY_KEYS, actual: $body['compatibility'], of: 'the compatibility block');
        $this->assertSame(2, $body['compatibility']['delivered']);
        $this->assertSame([], $body['compatibility']['withheld'], 'nothing is withheld, and PHP writes an empty map as an empty list');

        $this->assertSame(['spacer', 'usp_strip'], array_column($body['sections'], 'type'));
        foreach ($body['sections'] as $section) {
            $this->assertKeys(expected: self::SECTION_KEYS, actual: $section, of: "the {$section['type']} section");
            $this->assertIsString($section['uuid']);
            $this->assertIsInt($section['id']);
            $this->assertIsInt($section['component_version']);
            $this->assertIsArray($section['settings']);
            $this->assertIsArray($section['blocks']);
            $this->assertIsArray($section['source']);
            $this->assertIsArray($section['view_all']);
        }
        $this->assertSame(41, $body['sections'][0]['settings']['height']);
        $this->assertSame(['kind' => 'inline'], $body['sections'][0]['source']);
        $this->assertSame([], $body['sections'][0]['blocks']);
        $this->assertCount(1, $body['sections'][1]['blocks']);
        $this->assertKeys(expected: self::BLOCK_KEYS, actual: $body['sections'][1]['blocks'][0], of: 'a block');
        $this->assertSame(['usp', 'Free delivery'], [$body['sections'][1]['blocks'][0]['type'], $body['sections'][1]['blocks'][0]['settings']['title']]);
    }

    /** @param array<string, string> $headers */
    #[DataProvider('theAppFromEveryStorefront')]
    public function test_theme_version_has_the_shape_the_apps_parse_and_says_what_theme_home_says(bool $storefrontsOn, array $headers): void
    {
        $this->openStorefrontsFor(storefrontsOn: $storefrontsOn);
        $this->givePublishedHomeOf(types: []);

        $version = $this->getJson('/api/v1/theme/version', $headers)->assertOk();
        $home = $this->home(headers: $headers);

        $version->assertJsonStructure(self::VERSION_KEYS);
        $this->assertKeys(expected: self::VERSION_KEYS, actual: $version->json(), of: 'the revision');
        $this->assertSame(4, $version->json('revision'));
        $this->assertSame('checksum-of-saudi', $version->json('checksum'));
        $this->assertIsInt($version->json('schema_version'));
        $this->assertIsInt($version->json('engine_version'));
        $this->assertMatchesRegularExpression(self::ISO_8601, $version->json('published_at'));
        $this->assertSame(
            [$version->json('revision'), $version->json('checksum'), $version->json('published_at')],
            [$home->json('revision'), $home->json('content_stamp'), $home->json('published_at')],
            'the answers a client compares to learn that nothing changed',
        );
    }

    public function test_theme_home_and_theme_version_say_so_when_no_theme_is_published(): void
    {
        $home = $this->home();
        $version = $this->getJson('/api/v1/theme/version')->assertOk();

        $home->assertJsonStructure(self::EMPTY_PAGE_KEYS);
        $version->assertJsonStructure(self::VERSION_KEYS);
        $this->assertKeys(expected: self::EMPTY_PAGE_KEYS, actual: $home->json(), of: 'the page of a storefront with no theme published');
        $this->assertSame(['home', 0, null, null], [$home->json('page'), $home->json('revision'), $home->json('checksum'), $home->json('published_at')]);
        $this->assertIsInt($home->json('schema_version'));
        $this->assertIsInt($home->json('engine_version'));
        $this->assertSame([[], []], [$home->json('tokens'), $home->json('sections')]);
        $this->assertSame(['delivered' => 0, 'withheld' => []], $home->json('compatibility'));

        $this->assertKeys(expected: self::VERSION_KEYS, actual: $version->json(), of: 'the revision of a storefront with no theme published');
        $this->assertSame([0, null, null], [$version->json('revision'), $version->json('checksum'), $version->json('published_at')]);
        $this->assertIsInt($version->json('schema_version'));
        $this->assertIsInt($version->json('engine_version'));
    }

    /** @return array<string, array{0: string}> */
    public static function theThemeEndpoints(): array
    {
        return ['theme home' => ['/api/v1/theme/home'], 'theme version' => ['/api/v1/theme/version']];
    }

    #[DataProvider('theThemeEndpoints')]
    public function test_the_developer_portal_documents_the_endpoint_and_names_the_header_that_picks_the_storefront(string $path): void
    {
        $endpoint = app(ApiManifest::class)->findByPath(path: $path, method: 'GET');

        $this->assertNotNull($endpoint, "[{$path}] is not in the API manifest");
        $this->assertTrue($endpoint['documented'], "[{$path}] has no ApiDoc");
        $this->assertStringContainsString('X-Storefront', (string) $endpoint['description'], "the documentation of [{$path}] does not name the header that picks the storefront");
    }
}
