<?php

namespace Tests\Feature\Storefront;

use App\Services\Theme\StorefrontThemeRenderer;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsThemePreviewWorld;
use Tests\Support\BuildsThemeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * GCC F-07.0 to F-07.3, for the website, through the real route of a custom page (/p/offers) with the storefront middleware and NoIndexThemePreview in place: a preview token that is tampered with,
 * expired or signed by another application is ignored and the published page is drawn; a live token draws the draft it names; every response that carries a token is marked noindex and no-store;
 * a draft is never cached; and the preview an admin starts in the builder (a version in the session) is drawn for an admin who may view themes and for nobody else.
 */
class StorefrontThemePreviewWebTest extends TestCase
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

    private function assertMarkedNoIndexAndNoStore(TestResponse $response): void
    {
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    private function assertNotMarked(TestResponse $response): void
    {
        $response->assertHeaderMissing('X-Robots-Tag');
        $this->assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /** @return array<string, array{0: string}> every way a token fails except the empty value, which carries no token at all */
    public static function tokensThatAreNotLiveButAreThere(): array
    {
        return array_diff_key(self::tokensThatAreNotLive(), ['an empty value' => true]);
    }

    // ---- F-07.0: what the website draws for a token --------------------------------------------------------------------------------------------

    public function test_the_website_draws_the_draft_a_live_token_names(): void
    {
        $this->assertWebsiteServes(height: self::SAUDI_DRAFT, response: $this->offersPage(token: $this->previewTokenOf(version: $this->saudiDraft)));
    }

    #[DataProvider('tokensThatAreNotLive')]
    public function test_the_website_draws_the_published_page_for_a_token_that_is_not_live(string $kind): void
    {
        $this->assertWebsiteServes(height: self::SAUDI_PUBLISHED, response: $this->offersPage(token: $this->tokenThatIsNotLive(kind: $kind)));
    }

    public function test_a_theme_preview_sent_as_a_list_is_ignored_and_the_published_page_is_drawn(): void
    {
        $response = $this->get('/p/offers?theme_preview[]=' . $this->previewTokenOf(version: $this->saudiDraft));

        $this->assertWebsiteServes(height: self::SAUDI_PUBLISHED, response: $response);
    }

    public function test_a_token_needs_no_session_and_no_admin(): void
    {
        $this->assertNull(auth('admin')->user());

        $this->assertWebsiteServes(height: self::SAUDI_DRAFT, response: $this->offersPage(token: $this->previewTokenOf(version: $this->saudiDraft)));
    }

    // ---- F-07.1: a draft the website draws is never cached -------------------------------------------------------------------------------------

    public function test_a_draft_the_website_draws_is_never_written_to_the_cache(): void
    {
        $writes = $this->recordCacheWrites();
        $this->offersPage()->assertOk();
        $this->assertNotSame([], $this->themeKeysAmong(writes: $writes->getArrayCopy()), 'the published page is cached, and the recorder sees it');

        $before = count($writes);
        $this->assertWebsiteServes(height: self::SAUDI_DRAFT, response: $this->offersPage(token: $this->previewTokenOf(version: $this->saudiDraft)));
        $written = array_slice($writes->getArrayCopy(), $before);

        $this->assertSame([], $this->themeKeysAmong(writes: $written));
        foreach ($written as [$key, $value]) {
            $this->assertStringNotContainsString(self::DRAFT_TEXTS[self::SAUDI_DRAFT], (string) json_encode($value), "what was cached under {$key}");
        }
    }

    public function test_a_draft_cannot_reach_a_visitor_by_way_of_the_cache_whether_it_is_asked_for_before_the_page_is_cached_or_after(): void
    {
        $token = $this->previewTokenOf(version: $this->saudiDraft);

        $this->assertWebsiteServes(height: self::SAUDI_DRAFT, response: $this->offersPage(token: $token));
        $this->assertWebsiteServes(height: self::SAUDI_PUBLISHED, response: $this->offersPage());
        $this->assertWebsiteServes(height: self::SAUDI_DRAFT, response: $this->offersPage(token: $token));
        $this->assertWebsiteServes(height: self::SAUDI_PUBLISHED, response: $this->offersPage());
    }

    // ---- F-07.2: a response that carries a token is noindex and no-store -------------------------------------------------------------------------

    public function test_a_response_that_carries_a_live_token_is_marked_noindex(): void
    {
        $response = $this->offersPage(token: $this->previewTokenOf(version: $this->saudiDraft));

        $this->assertWebsiteServes(height: self::SAUDI_DRAFT, response: $response);
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
    }

    public function test_a_response_that_carries_a_live_token_is_marked_no_store_and_private(): void
    {
        $response = $this->offersPage(token: $this->previewTokenOf(version: $this->saudiDraft));

        $this->assertWebsiteServes(height: self::SAUDI_DRAFT, response: $response);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    #[DataProvider('tokensThatAreNotLiveButAreThere')]
    public function test_a_response_that_carries_a_token_that_is_not_live_is_marked_too(string $kind): void
    {
        $response = $this->offersPage(token: $this->tokenThatIsNotLive(kind: $kind));

        $this->assertWebsiteServes(height: self::SAUDI_PUBLISHED, response: $response);
        $this->assertMarkedNoIndexAndNoStore(response: $response);
    }

    public function test_a_response_that_carries_a_token_is_marked_whatever_it_is_the_response_to(): void
    {
        $response = $this->get('/p/no-such-page?theme_preview=' . $this->previewTokenOf(version: $this->saudiDraft));

        $response->assertNotFound();
        $this->assertMarkedNoIndexAndNoStore(response: $response);
    }

    public function test_a_response_that_carries_no_token_is_not_marked(): void
    {
        $this->assertNotMarked(response: $this->offersPage());
        $this->assertNotMarked(response: $this->get('/p/offers?theme_preview='));
        $this->assertNotMarked(response: $this->get('/p/no-such-page'));
    }

    // ---- F-07.3: the preview an admin starts is drawn for an admin who may view themes and for nobody else -------------------------------------

    /** @return array<string, array{0: int|null, 1: list<string>|null, 2: bool}> the role id, the modules and capabilities of the admin, and whether they may view a theme; no role id is a guest */
    public static function adminsAndWhetherTheyMayViewThemes(): array
    {
        return [
            'the master admin' => [1, [], true],
            'an admin granted theme_view' => [2, ['theme_view'], true],
            'an admin of a role that holds the themes module' => [2, ['themes_and_addons'], true],
            'an admin granted theme_edit but not theme_view' => [2, ['theme_edit'], false],
            'an admin granted theme_publish but not theme_view' => [2, ['theme_publish'], false],
            'an admin of a role that holds another module' => [2, ['order_management'], false],
            'an admin of a role that holds nothing' => [2, [], false],
            'an admin who has no role' => [2, null, false],
            'a guest' => [null, null, false],
        ];
    }

    /** @param list<string>|null $modules */
    #[DataProvider('adminsAndWhetherTheyMayViewThemes')]
    public function test_the_preview_in_the_session_is_drawn_for_an_admin_who_may_view_themes_and_for_nobody_else(?int $roleId, ?array $modules, bool $mayView): void
    {
        $this->withSession([StorefrontThemeRenderer::PREVIEW_SESSION_KEY => $this->saudiDraft->id]);
        if ($roleId !== null) {
            $this->actingAsAdminWith(modules: $modules, roleId: $roleId);
        }

        $this->assertWebsiteServes(height: $mayView ? self::SAUDI_DRAFT : self::SAUDI_PUBLISHED, response: $this->offersPage());
    }

    public function test_an_admin_who_may_view_themes_is_drawn_the_published_page_until_a_preview_is_started(): void
    {
        $this->actingAsAdminWith(modules: ['theme_view']);

        $this->assertWebsiteServes(height: self::SAUDI_PUBLISHED, response: $this->offersPage());
    }

    public function test_the_preview_in_the_session_of_a_version_that_no_longer_exists_is_forgotten_and_the_published_page_is_drawn(): void
    {
        $this->withSession([StorefrontThemeRenderer::PREVIEW_SESSION_KEY => $this->saudiDraft->id]);
        $this->actingAsAdminWith(modules: ['theme_view']);
        $this->saudiDraft->delete();

        $this->assertWebsiteServes(height: self::SAUDI_PUBLISHED, response: $this->offersPage());
        $this->assertNull(session(StorefrontThemeRenderer::PREVIEW_SESSION_KEY));
    }

    public function test_a_token_is_not_the_preview_of_the_session_and_does_not_need_the_permission_the_session_needs(): void
    {
        $this->actingAsAdminWith(modules: ['theme_edit']);
        $this->withSession([StorefrontThemeRenderer::PREVIEW_SESSION_KEY => $this->saudiDraft->id]);

        $this->assertWebsiteServes(height: self::SAUDI_PUBLISHED, response: $this->offersPage());
        $this->assertWebsiteServes(height: self::SAUDI_SECOND_DRAFT, response: $this->offersPage(token: $this->previewTokenOf(version: $this->saudiSecondDraft)));
    }
}
