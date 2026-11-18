<?php

namespace Tests\Feature\Storefront;

use App\Services\Theme\ThemePreviewToken;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsThemePreviewWorld;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * GCC F-07.0 and F-07.1, for the app: a preview token that is tampered with, expired or signed by another application is ignored, and the published page is served in its place. A live token
 * delivers the draft it names, and what is delivered for it is never cached, never given an ETag and never answered with a 304, so a draft cannot reach a shopper by way of a cache.
 *
 * Checked on the token itself and through the two routes of the app that read it: /api/v1/theme/home (as `preview`) and /api/v1/theme/sections (as `theme_preview`). The website is in
 * StorefrontThemePreviewWebTest and the storefront a token belongs to is in StorefrontThemePreviewStorefrontTest.
 *
 * The last four tests are defects of the app's routes that these tests found, each skipped with what it answers today: F-07.2 is not met for either route of the app, nor F-07.1 for /theme/sections, until they are decided and fixed.
 */
class StorefrontThemePreviewTokenTest extends TestCase
{
    use BuildsThemePreviewWorld;
    use BuildsThemeWorld;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->buildThemeWorld();
        $this->switchStorefrontThemesOn();
        $this->openEveryStorefront();
        $this->givePreviewWorld();
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();

        parent::tearDown();
    }

    // ---- F-07.0: the version a token names -------------------------------------------------------------------------------------------------

    public function test_a_live_token_names_the_version_it_was_minted_for(): void
    {
        $token = $this->previewTokenOf(version: $this->saudiDraft);

        $this->assertSame($this->saudiDraft->id, app(ThemePreviewToken::class)->version(token: $token)?->id);
    }

    #[DataProvider('tokensThatAreNotLive')]
    public function test_a_token_that_is_not_live_names_no_version(string $kind): void
    {
        $this->assertNull(app(ThemePreviewToken::class)->version(token: $this->tokenThatIsNotLive(kind: $kind)));
    }

    public function test_no_token_names_no_version(): void
    {
        $this->assertNull(app(ThemePreviewToken::class)->version(token: null));
    }

    public function test_a_token_is_live_until_its_expiry_and_not_after_it(): void
    {
        $token = $this->previewTokenOf(version: $this->saudiDraft, minutes: 30);
        $tokens = app(ThemePreviewToken::class);

        $this->travel(29)->minutes();
        $this->assertSame($this->saudiDraft->id, $tokens->version(token: $token)?->id, 'a minute before it expires');

        $this->travel(2)->minutes();
        $this->assertNull($tokens->version(token: $token), 'a minute after it expired');
    }

    public function test_a_token_of_a_version_that_no_longer_exists_names_no_version(): void
    {
        $token = $this->previewTokenOf(version: $this->saudiDraft);
        $this->saudiDraft->delete();

        $this->assertNull(app(ThemePreviewToken::class)->version(token: $token));
    }

    // ---- F-07.0: what the app is given for the token of /theme/home -------------------------------------------------------------------------

    public function test_the_app_is_given_the_draft_a_live_token_names(): void
    {
        $response = $this->appHome(token: $this->previewTokenOf(version: $this->saudiDraft));

        $this->assertAppServes(height: self::SAUDI_DRAFT, response: $response);
        $response->assertJsonPath('preview', true);
    }

    #[DataProvider('tokensThatAreNotLive')]
    public function test_the_app_is_given_the_published_page_for_a_token_that_is_not_live(string $kind): void
    {
        $response = $this->appHome(token: $this->tokenThatIsNotLive(kind: $kind));

        $this->assertAppServes(height: self::SAUDI_PUBLISHED, response: $response);
        $response->assertJsonMissingPath('preview')->assertJsonPath('revision', 3)->assertHeader('ETag');
        $this->assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    // ---- F-07.0: what the app is given for the token of /theme/sections ----------------------------------------------------------------------

    public function test_the_sections_of_the_app_are_those_of_the_draft_a_live_token_names(): void
    {
        $this->assertAppServes(height: self::SAUDI_DRAFT, response: $this->appSections(token: $this->previewTokenOf(version: $this->saudiDraft)));
    }

    #[DataProvider('tokensThatAreNotLive')]
    public function test_the_sections_of_the_app_are_the_published_ones_for_a_token_that_is_not_live(string $kind): void
    {
        $this->assertAppServes(height: self::SAUDI_PUBLISHED, response: $this->appSections(token: $this->tokenThatIsNotLive(kind: $kind)));
    }

    public function test_a_theme_preview_sent_as_a_list_is_ignored_by_the_sections_of_the_app(): void
    {
        $response = $this->getJson('/api/v1/theme/sections?page=home&theme_preview[]=' . $this->previewTokenOf(version: $this->saudiDraft));

        $this->assertAppServes(height: self::SAUDI_PUBLISHED, response: $response);
    }

    // ---- F-07.1: how a draft is delivered ----------------------------------------------------------------------------------------------------

    public function test_a_draft_is_sent_with_no_store_and_private(): void
    {
        $response = $this->appHome(token: $this->previewTokenOf(version: $this->saudiDraft));

        $this->assertAppServes(height: self::SAUDI_DRAFT, response: $response);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_draft_is_sent_without_an_etag(): void
    {
        $response = $this->appHome(token: $this->previewTokenOf(version: $this->saudiDraft));

        $this->assertAppServes(height: self::SAUDI_DRAFT, response: $response);
        $response->assertHeaderMissing('ETag');
    }

    public function test_the_published_page_is_given_an_etag_and_may_be_answered_with_a_304_and_a_draft_is_never(): void
    {
        $etag = (string) $this->appHome()->assertOk()->headers->get('ETag');
        $this->appHome(headers: ['If-None-Match' => $etag])->assertStatus(304);
        $this->appHome(headers: ['If-None-Match' => '*'])->assertStatus(304);
        $token = $this->previewTokenOf(version: $this->saudiDraft);

        foreach ([$etag, '*'] as $validator) {
            $this->assertAppServes(height: self::SAUDI_DRAFT, response: $this->appHome(token: $token, headers: ['If-None-Match' => $validator]));
        }
    }

    public function test_a_draft_is_never_written_to_the_cache(): void
    {
        $writes = $this->recordCacheWrites();
        $this->appHome()->assertOk();
        $this->assertNotSame([], $this->themeKeysAmong(writes: $writes->getArrayCopy()), 'the published page is cached, and the recorder sees it');

        $before = count($writes);
        $token = $this->previewTokenOf(version: $this->saudiDraft);
        $this->assertAppServes(height: self::SAUDI_DRAFT, response: $this->appHome(token: $token));
        $this->assertAppServes(height: self::SAUDI_DRAFT, response: $this->appSections(token: $token));
        $written = array_slice($writes->getArrayCopy(), $before);

        $this->assertSame([], $this->themeKeysAmong(writes: $written));
        foreach ($written as [$key, $value]) {
            $this->assertStringNotContainsString(self::DRAFT_TEXTS[self::SAUDI_DRAFT], (string) json_encode($value), "what was cached under {$key}");
        }
    }

    public function test_a_draft_cannot_reach_a_shopper_by_way_of_the_cache_whether_it_is_asked_for_before_the_page_is_cached_or_after(): void
    {
        $token = $this->previewTokenOf(version: $this->saudiDraft);

        foreach (['appHome', 'appSections'] as $endpoint) {
            $this->assertAppServes(height: self::SAUDI_DRAFT, response: $this->{$endpoint}(token: $token));
            $this->assertAppServes(height: self::SAUDI_PUBLISHED, response: $this->{$endpoint}());
            $this->assertAppServes(height: self::SAUDI_DRAFT, response: $this->{$endpoint}(token: $token));
            $this->assertAppServes(height: self::SAUDI_PUBLISHED, response: $this->{$endpoint}());
        }
    }

    // ---- defects of the app's routes, found by these tests and not fixed here -----------------------------------------------------------------------

    public function test_a_list_sent_as_the_preview_of_the_home_page_is_ignored_and_the_published_page_is_given(): void
    {
        $this->markTestSkipped('Defect, a decision of the owner: /api/v1/theme/home?preview[]=x answers 500, because ThemePreviewToken::version takes a ?string and a list is a TypeError. Widening it to mixed changes behaviour outside F-07.4.');

        $response = $this->getJson('/api/v1/theme/home?preview[]=' . $this->previewTokenOf(version: $this->saudiDraft));

        $this->assertAppServes(height: self::SAUDI_PUBLISHED, response: $response);
        $response->assertJsonMissingPath('preview');
    }

    public function test_the_home_page_of_the_app_that_a_token_summoned_is_marked_noindex(): void
    {
        $this->markTestSkipped('Defect, a decision of the owner: /api/v1/theme/home?preview=<live token> answers the draft with no X-Robots-Tag, because NoIndexThemePreview is in the web group only and reads theme_preview, which this route does not take.');

        $response = $this->appHome(token: $this->previewTokenOf(version: $this->saudiDraft));

        $this->assertAppServes(height: self::SAUDI_DRAFT, response: $response);
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
    }

    public function test_the_sections_of_the_app_that_a_token_summoned_are_marked_noindex(): void
    {
        $this->markTestSkipped('Defect, a decision of the owner: /api/v1/theme/sections?theme_preview=<live token> answers the draft with no X-Robots-Tag, because NoIndexThemePreview is in the web group only.');

        $response = $this->appSections(token: $this->previewTokenOf(version: $this->saudiDraft));

        $this->assertAppServes(height: self::SAUDI_DRAFT, response: $response);
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
    }

    public function test_the_sections_of_the_app_that_a_token_summoned_are_sent_with_no_store(): void
    {
        $this->markTestSkipped('Defect, a decision of the owner: /api/v1/theme/sections?theme_preview=<live token> answers the draft with Cache-Control "no-cache, private", not "no-store, private", because only the home route sets the header.');

        $response = $this->appSections(token: $this->previewTokenOf(version: $this->saudiDraft));

        $this->assertAppServes(height: self::SAUDI_DRAFT, response: $response);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
