<?php

namespace Tests\Feature\Storefront;

use App\Models\ThemeVersion;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\StorefrontThemeResolver;
use App\Services\Theme\ThemeManager;
use App\Services\Theme\ThemePreviewToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsThemePreviewWorld;
use Tests\Support\BuildsThemeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * GCC F-07.4: a preview token and the preview an admin starts in the builder name a version and nothing else, so a draft of one storefront must not be drawn on another's. While themes are kept by
 * storefront, a token or a session preview whose version belongs to a theme of another storefront than the one the request is for is ignored, exactly as a token that is not live is, and the
 * published page of the storefront the request is for is drawn instead. A theme that names no country, or the default one, is the default storefront's. The storefront of a request is the one
 * the theme resolver already uses: a storefront with no theme of its own is drawn the default storefront's published page, but it is still another storefront than the default one, so a draft of
 * the default storefront's theme is not previewed on it either. While themes are not kept by storefront, or before the themes migration has run, nothing changes: every draft is previewed on
 * every storefront, as it always was.
 */
class StorefrontThemePreviewStorefrontTest extends TestCase
{
    use BuildsThemePreviewWorld;
    use BuildsThemeWorld;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->prepareTheWebsite();
        $this->buildThemeWorld();
        $this->switchStorefrontThemesOn();
        $this->openEveryStorefront();
        $this->givePreviewWorld();
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /**
     * The draft a token or a session names, the storefront the request is for, and the page that request is drawn: the draft where it belongs, the published page of the storefront the request
     * is for everywhere else. Kuwait has no theme of its own, so it is drawn the published Saudi page.
     *
     * @return array<string, array{0: string, 1: string|null, 2: int}>
     */
    public static function draftsAndWhereTheyAreDrawn(): array
    {
        return [
            'the Emirati draft on an Emirati request' => ['emirati', 'AE', self::EMIRATI_DRAFT],
            'the Emirati draft on a Saudi request' => ['emirati', 'SA', self::SAUDI_PUBLISHED],
            'the Emirati draft on a request that names no storefront' => ['emirati', null, self::SAUDI_PUBLISHED],
            'the Emirati draft on a Kuwaiti request' => ['emirati', 'KW', self::SAUDI_PUBLISHED],
            'the Saudi draft on a Saudi request' => ['saudi', 'SA', self::SAUDI_DRAFT],
            'the Saudi draft on a request that names no storefront' => ['saudi', null, self::SAUDI_DRAFT],
            'the Saudi draft on an Emirati request' => ['saudi', 'AE', self::EMIRATI_PUBLISHED],
            'the Saudi draft on a Kuwaiti request' => ['saudi', 'KW', self::SAUDI_PUBLISHED],
        ];
    }

    private function draftNamed(string $draft): ThemeVersion
    {
        return $draft === 'emirati' ? $this->emiratiDraft : $this->saudiDraft;
    }

    private function draftHeightNamed(string $draft): int
    {
        return $draft === 'emirati' ? self::EMIRATI_DRAFT : self::SAUDI_DRAFT;
    }

    private function startPreviewInTheSessionOf(ThemeVersion $version): void
    {
        $this->actingAsAdminWith(modules: [], roleId: 1);
        $this->withSession([StorefrontThemeRenderer::PREVIEW_SESSION_KEY => $version->id]);
    }

    /** @return list<string> the text of every statement the callback ran, without the quotes each engine puts around a name */
    private function statementsOf(callable $callback): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $callback();
        $connection->disableQueryLog();

        return array_map(static fn (string $statement): string => str_replace(['"', '`'], '', $statement), array_column($connection->getQueryLog(), 'query'));
    }

    // ---- a token, for the app and for the website ---------------------------------------------------------------------------------------------

    #[DataProvider('draftsAndWhereTheyAreDrawn')]
    public function test_the_home_page_of_the_app_is_a_draft_only_on_the_storefront_of_its_theme(string $draft, ?string $storefront, int $drawn): void
    {
        $response = $this->appHome(token: $this->previewTokenOf(version: $this->draftNamed(draft: $draft)), storefront: $storefront);

        $this->assertAppServes(height: $drawn, response: $response);
        if ($this->isDraft(height: $drawn)) {
            $response->assertJsonPath('preview', true);
        } else {
            $response->assertJsonMissingPath('preview')->assertHeader('ETag');
        }
    }

    #[DataProvider('draftsAndWhereTheyAreDrawn')]
    public function test_the_sections_of_the_app_are_those_of_a_draft_only_on_the_storefront_of_its_theme(string $draft, ?string $storefront, int $drawn): void
    {
        $this->assertAppServes(height: $drawn, response: $this->appSections(token: $this->previewTokenOf(version: $this->draftNamed(draft: $draft)), storefront: $storefront));
    }

    #[DataProvider('draftsAndWhereTheyAreDrawn')]
    public function test_the_website_draws_a_draft_only_on_the_storefront_of_its_theme(string $draft, ?string $storefront, int $drawn): void
    {
        $this->assertWebsiteServes(height: $drawn, response: $this->offersPage(token: $this->previewTokenOf(version: $this->draftNamed(draft: $draft)), storefront: $storefront));
    }

    // ---- the preview of the session ------------------------------------------------------------------------------------------------------------

    #[DataProvider('draftsAndWhereTheyAreDrawn')]
    public function test_the_website_draws_the_preview_of_the_session_only_on_the_storefront_of_its_theme(string $draft, ?string $storefront, int $drawn): void
    {
        $this->startPreviewInTheSessionOf(version: $this->draftNamed(draft: $draft));

        $this->assertWebsiteServes(height: $drawn, response: $this->offersPage(storefront: $storefront));
    }

    public function test_the_preview_of_the_session_of_another_storefront_is_ignored_for_that_request_and_kept_for_its_own(): void
    {
        $this->startPreviewInTheSessionOf(version: $this->emiratiDraft);

        $this->assertWebsiteServes(height: self::SAUDI_PUBLISHED, response: $this->offersPage(storefront: 'SA'));
        $this->assertSame($this->emiratiDraft->id, session(StorefrontThemeRenderer::PREVIEW_SESSION_KEY));
        $this->assertWebsiteServes(height: self::EMIRATI_DRAFT, response: $this->offersPage(storefront: 'AE'));
    }

    public function test_a_token_of_the_storefront_is_drawn_when_the_preview_of_the_session_is_the_draft_of_another(): void
    {
        $this->startPreviewInTheSessionOf(version: $this->emiratiDraft);

        $this->assertWebsiteServes(height: self::SAUDI_DRAFT, response: $this->offersPage(token: $this->previewTokenOf(version: $this->saudiDraft), storefront: 'SA'));
    }

    // ---- which storefront a theme belongs to ---------------------------------------------------------------------------------------------------

    public function test_a_version_may_be_previewed_only_on_the_storefront_of_its_theme(): void
    {
        $resolver = app(StorefrontThemeResolver::class);

        foreach (['SA' => [true, false], 'AE' => [false, true], 'KW' => [false, false]] as $storefront => [$saudi, $emirati]) {
            $this->serveRequestFromStorefront(country: $storefront);

            $this->assertSame($saudi, $resolver->mayPreview(version: $this->saudiDraft), "the Saudi draft on {$storefront}");
            $this->assertSame($saudi, $resolver->mayPreview(version: $this->saudiTheme->publishedVersion), "the published Saudi version on {$storefront}");
            $this->assertSame($emirati, $resolver->mayPreview(version: $this->emiratiDraft), "the Emirati draft on {$storefront}");
        }
    }

    public function test_a_theme_that_names_the_default_country_is_the_default_storefronts_like_one_that_names_none(): void
    {
        $explicit = $this->giveDraft(theme: $this->giveTheme(name: 'Explicit', country: 'SA'), height: self::SAUDI_SECOND_DRAFT);
        $token = $this->previewTokenOf(version: $explicit);

        $this->assertAppServes(height: self::SAUDI_SECOND_DRAFT, response: $this->appHome(token: $token, storefront: 'SA'));
        $this->assertAppServes(height: self::SAUDI_SECOND_DRAFT, response: $this->appHome(token: $token));
        $this->assertAppServes(height: self::EMIRATI_PUBLISHED, response: $this->appHome(token: $token, storefront: 'AE'));
    }

    public function test_a_draft_of_a_theme_that_is_not_active_is_previewed_on_its_own_storefront_alone(): void
    {
        $kuwaiti = $this->giveDraft(theme: $this->giveTheme(name: 'Kuwaiti', country: 'KW'), height: self::KUWAITI_DRAFT);
        $token = $this->previewTokenOf(version: $kuwaiti);

        $this->assertAppServes(height: self::KUWAITI_DRAFT, response: $this->appHome(token: $token, storefront: 'KW'));
        $this->assertWebsiteServes(height: self::KUWAITI_DRAFT, response: $this->offersPage(token: $token, storefront: 'KW'));
        $this->assertAppServes(height: self::SAUDI_PUBLISHED, response: $this->appHome(token: $token, storefront: 'SA'));
        $this->assertAppServes(height: self::EMIRATI_PUBLISHED, response: $this->appHome(token: $token, storefront: 'AE'));
        $this->assertWebsiteServes(height: self::SAUDI_PUBLISHED, response: $this->offersPage(token: $token));
    }

    public function test_a_theme_that_names_no_country_belongs_to_whichever_storefront_is_the_default(): void
    {
        config(['gcc.storefronts.default_country' => 'AE']);
        $resolver = app(StorefrontThemeResolver::class);

        $this->serveRequestFromStorefront(country: 'AE');
        $this->assertTrue($resolver->mayPreview(version: $this->saudiDraft), 'the theme that names no country is the Emirati storefront\'s now');

        $this->serveRequestFromStorefront(country: 'SA');
        $this->assertFalse($resolver->mayPreview(version: $this->saudiDraft));
    }

    public function test_a_draft_of_a_theme_that_no_longer_exists_is_ignored_and_the_published_page_of_the_storefront_is_given_instead(): void
    {
        $token = $this->previewTokenOf(version: $this->saudiDraft);
        $this->saudiTheme->delete();

        $response = $this->appHome(token: $token);

        $response->assertOk()->assertJsonMissingPath('preview')->assertJson(['sections' => []]);
    }

    public function test_the_colours_of_the_page_head_follow_the_same_rule(): void
    {
        $this->saudiDraft->update(['settings' => ['colors' => ['primary' => '#005a5a']]]);
        $this->emiratiDraft->update(['settings' => ['colors' => ['primary' => '#ae0000']]]);
        $renderer = app(StorefrontThemeRenderer::class);
        $manager = app(ThemeManager::class);
        $primaryColourOn = function (string $storefront, ThemeVersion $draft) use ($renderer, $manager): string {
            $this->serveRequestFromStorefront(country: $storefront);
            $this->app->instance('request', Request::create('/', 'GET', ['theme_preview' => $this->previewTokenOf(version: $draft)]));

            return $renderer->publishedGlobalSettings(manager: $manager)['colors']['primary'];
        };

        $this->assertSame('#ae0000', $primaryColourOn(storefront: 'AE', draft: $this->emiratiDraft));
        $this->assertSame($manager->defaultSettings()['colors']['primary'], $primaryColourOn(storefront: 'SA', draft: $this->emiratiDraft));
        $this->assertSame('#005a5a', $primaryColourOn(storefront: 'SA', draft: $this->saudiDraft));
        $this->assertSame($manager->defaultSettings()['colors']['primary'], $primaryColourOn(storefront: 'AE', draft: $this->saudiDraft));
    }

    // ---- nothing changes while themes are not kept by storefront -------------------------------------------------------------------------------

    #[DataProvider('draftsAndWhereTheyAreDrawn')]
    public function test_while_themes_are_not_kept_by_storefront_a_draft_is_previewed_on_every_storefront_for_the_app_as_it_always_was(string $draft, ?string $storefront): void
    {
        config(['gcc.storefronts.themes.enabled' => false]);

        $response = $this->appHome(token: $this->previewTokenOf(version: $this->draftNamed(draft: $draft)), storefront: $storefront);

        $this->assertAppServes(height: $this->draftHeightNamed(draft: $draft), response: $response);
        $response->assertJsonPath('preview', true);
    }

    #[DataProvider('draftsAndWhereTheyAreDrawn')]
    public function test_while_themes_are_not_kept_by_storefront_a_draft_is_previewed_on_every_storefront_for_the_website_as_it_always_was(string $draft, ?string $storefront): void
    {
        config(['gcc.storefronts.themes.enabled' => false]);

        $this->assertWebsiteServes(height: $this->draftHeightNamed(draft: $draft), response: $this->offersPage(token: $this->previewTokenOf(version: $this->draftNamed(draft: $draft)), storefront: $storefront));
    }

    #[DataProvider('draftsAndWhereTheyAreDrawn')]
    public function test_while_themes_are_not_kept_by_storefront_the_preview_of_the_session_is_drawn_on_every_storefront_as_it_always_was(string $draft, ?string $storefront): void
    {
        config(['gcc.storefronts.themes.enabled' => false]);
        $this->startPreviewInTheSessionOf(version: $this->draftNamed(draft: $draft));

        $this->assertWebsiteServes(height: $this->draftHeightNamed(draft: $draft), response: $this->offersPage(storefront: $storefront));
    }

    public function test_before_the_themes_migration_has_run_a_draft_is_previewed_on_every_storefront_as_it_always_was(): void
    {
        $this->buildThemeWorld(withCountryColumn: false);
        $this->givePreviewWorld();

        $this->assertAppServes(height: self::EMIRATI_DRAFT, response: $this->appHome(token: $this->previewTokenOf(version: $this->emiratiDraft), storefront: 'SA'));
        $this->assertWebsiteServes(height: self::EMIRATI_DRAFT, response: $this->offersPage(token: $this->previewTokenOf(version: $this->emiratiDraft), storefront: 'AE'));
    }

    public function test_while_themes_are_not_kept_by_storefront_a_draft_of_a_theme_that_no_longer_exists_is_previewed_as_it_always_was(): void
    {
        config(['gcc.storefronts.themes.enabled' => false]);
        $token = $this->previewTokenOf(version: $this->saudiDraft);
        $this->saudiTheme->delete();

        $this->assertAppServes(height: self::SAUDI_DRAFT, response: $this->appHome(token: $token));
    }

    public function test_while_themes_are_not_kept_by_storefront_the_storefront_is_not_read_to_decide_which_draft_to_preview(): void
    {
        config(['gcc.storefronts.themes.enabled' => false, 'gcc.storefronts.default_country' => 'XX']);
        $renderer = app(StorefrontThemeRenderer::class);
        $this->startPreviewInTheSessionOf(version: $this->emiratiDraft);

        $this->assertSame($this->saudiDraft->id, app(ThemePreviewToken::class)->version(token: $this->previewTokenOf(version: $this->saudiDraft))?->id, 'a configuration that cannot be resolved would throw');
        $this->assertSame(self::EMIRATI_DRAFT, (int) $renderer->sectionsFor(page: 'offers')[0]['settings']['height'], 'the session preview is drawn');
    }

    public function test_while_themes_are_not_kept_by_storefront_deciding_which_draft_to_preview_asks_the_database_nothing_and_a_token_costs_the_one_statement_it_always_cost(): void
    {
        config(['gcc.storefronts.themes.enabled' => false]);
        $token = $this->previewTokenOf(version: $this->emiratiDraft);

        $statements = $this->statementsOf(callback: fn () => app(ThemePreviewToken::class)->version(token: $token));
        $this->assertSame(['select * from theme_versions where theme_versions.id = ? limit 1'], $statements);

        $statements = $this->statementsOf(callback: fn () => app(StorefrontThemeResolver::class)->mayPreview(version: $this->emiratiDraft));
        $this->assertSame([], $statements);
    }

    public function test_while_themes_are_not_kept_by_storefront_a_token_that_is_not_live_still_names_no_version(): void
    {
        config(['gcc.storefronts.themes.enabled' => false]);

        foreach (array_keys(self::tokensThatAreNotLive()) as $kind) {
            $this->assertNull(app(ThemePreviewToken::class)->version(token: $this->tokenThatIsNotLive(kind: $kind)), $kind);
        }
    }

    public function test_while_storefronts_are_off_a_request_a_payment_callback_bound_to_another_country_is_still_the_default_storefronts(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $this->app->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->contextOfRecordedCountry(countryCode: 'AE'));
        $resolver = app(StorefrontThemeResolver::class);

        $this->assertSame('AE', StorefrontContext::current()->country, 'the callback of an Emirati order is bound to the Emirati storefront');
        $this->assertTrue($resolver->mayPreview(version: $this->saudiDraft));
        $this->assertFalse($resolver->mayPreview(version: $this->emiratiDraft));
    }

    public function test_while_storefronts_are_off_every_request_is_the_default_storefronts_and_only_its_drafts_are_previewed(): void
    {
        config(['gcc.storefronts.enabled' => false]);

        foreach ([null, 'SA', 'AE'] as $storefront) {
            $this->assertAppServes(height: self::SAUDI_DRAFT, response: $this->appHome(token: $this->previewTokenOf(version: $this->saudiDraft), storefront: $storefront));
            $this->assertAppServes(height: self::SAUDI_PUBLISHED, response: $this->appHome(token: $this->previewTokenOf(version: $this->emiratiDraft), storefront: $storefront));
            $this->assertWebsiteServes(height: self::SAUDI_PUBLISHED, response: $this->offersPage(token: $this->previewTokenOf(version: $this->emiratiDraft), storefront: $storefront));
        }
    }
}
