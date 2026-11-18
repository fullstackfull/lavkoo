<?php

namespace Tests\Support;

use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\GuestMiddleware;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\ThemePreviewToken;
use ArrayObject;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;

/**
 * What the preview tests (GCC F-07) tell apart, on top of BuildsThemeWorld: a Saudi theme and an Emirati one, both active and published, and a draft of each that only that theme has (and a second
 * Saudi draft, for a token whose id is tampered with; a test that needs a Kuwaiti theme makes it, and a draft of it, itself). Every page is told by the height of its one spacer, so the page a response carries names whose it is and whether it is a draft; a draft also
 * carries a text that appears nowhere else.
 *
 * The two pages that carry the spacer are the home page, which the app asks for, and the custom page `offers`, which both themes have and the website serves at /p/offers.
 */
trait BuildsThemePreviewWorld
{
    private const SAUDI_PUBLISHED = 41;

    private const EMIRATI_PUBLISHED = 42;

    private const SAUDI_DRAFT = 4141;

    private const SAUDI_SECOND_DRAFT = 4343;

    private const EMIRATI_DRAFT = 4242;

    private const KUWAITI_DRAFT = 4444;

    /** What each spacer height is, in the words a failed assertion reads best in. */
    private const PAGES = [
        self::SAUDI_PUBLISHED => 'the published Saudi page',
        self::EMIRATI_PUBLISHED => 'the published Emirati page',
        self::SAUDI_DRAFT => 'the Saudi draft',
        self::SAUDI_SECOND_DRAFT => 'the second Saudi draft',
        self::EMIRATI_DRAFT => 'the Emirati draft',
        self::KUWAITI_DRAFT => 'the Kuwaiti draft',
    ];

    /** The text each draft carries beside its spacer. */
    private const DRAFT_TEXTS = [
        self::SAUDI_DRAFT => 'text-only-the-first-saudi-draft-has',
        self::SAUDI_SECOND_DRAFT => 'text-only-the-second-saudi-draft-has',
        self::EMIRATI_DRAFT => 'text-only-the-emirati-draft-has',
        self::KUWAITI_DRAFT => 'text-only-the-kuwaiti-draft-has',
    ];

    protected Theme $saudiTheme;

    protected Theme $emiratiTheme;

    protected ThemeVersion $saudiDraft;

    protected ThemeVersion $saudiSecondDraft;

    protected ThemeVersion $emiratiDraft;

    protected function givePreviewWorld(): void
    {
        $this->saudiTheme = $this->giveStorefrontTheme(name: 'Saudi', country: null, revision: 3, spacerHeight: self::SAUDI_PUBLISHED, page: 'offers', active: true);
        $this->emiratiTheme = $this->giveStorefrontTheme(name: 'Emirati', country: 'AE', revision: 7, spacerHeight: self::EMIRATI_PUBLISHED, page: 'offers', active: true);
        $this->giveSpacer(version: $this->saudiTheme->publishedVersion, page: 'offers', height: self::SAUDI_PUBLISHED);
        $this->giveSpacer(version: $this->emiratiTheme->publishedVersion, page: 'offers', height: self::EMIRATI_PUBLISHED);

        $this->saudiDraft = $this->giveDraft(theme: $this->saudiTheme, height: self::SAUDI_DRAFT);
        $this->saudiSecondDraft = $this->giveDraft(theme: $this->saudiTheme, height: self::SAUDI_SECOND_DRAFT);
        $this->emiratiDraft = $this->giveDraft(theme: $this->emiratiTheme, height: self::EMIRATI_DRAFT);
    }

    protected function giveSpacer(ThemeVersion $version, string $page, int $height, int $sortOrder = 1): void
    {
        ThemeSection::query()->create(['theme_version_id' => $version->id, 'page' => $page, 'type' => 'spacer', 'sort_order' => $sortOrder, 'settings' => ['height' => $height]]);
    }

    /** A draft with the spacer of its height and its own text, on the home page and on the custom page. */
    protected function giveDraft(Theme $theme, int $height): ThemeVersion
    {
        $draft = ThemeVersion::query()->create(['theme_id' => $theme->id, 'label' => self::PAGES[$height], 'status' => ThemeVersion::STATUS_DRAFT]);

        foreach (['home', 'offers'] as $page) {
            $this->giveSpacer(version: $draft, page: $page, height: $height);
            ThemeSection::query()->create(['theme_version_id' => $draft->id, 'page' => $page, 'type' => 'custom_html', 'sort_order' => 2, 'settings' => ['content' => self::DRAFT_TEXTS[$height]]]);
        }

        return $draft;
    }

    /**
     * The website's own page /p/offers, drawn inside a stand-in storefront shell (the real one needs the installation database) and without the middleware that reads that database. The two that
     * matter to a preview stay: ResolveStorefront, which binds the storefront, and NoIndexThemePreview, which marks the response.
     */
    protected function prepareTheWebsite(): void
    {
        session(['local' => 'en']);
        View::getFinder()->prependLocation(base_path('tests/Support/views/storefront'));
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
            GuestMiddleware::class, MaintenanceModeMiddleware::class,
        ]);
    }

    protected function previewTokenOf(ThemeVersion $version, int $minutes = ThemePreviewToken::DEFAULT_MINUTES): string
    {
        return app(ThemePreviewToken::class)->mint(version: $version, minutes: $minutes);
    }

    /** @return array<string, array{0: string}> the ways a token fails to be a live, untampered one of the version it names, by the name each case is reported under */
    public static function tokensThatAreNotLive(): array
    {
        $kinds = [
            'a tampered id', 'a moved expiry', 'an expired token', 'an expiry moved on an expired token', 'a token of another application key', 'a token without a signature',
            'a string that is not a token', 'a token with a part missing', 'a token with a part too many', 'a token with letters in the id', 'an empty value',
        ];

        return array_combine($kinds, array_map(static fn (string $kind): array => [$kind], $kinds));
    }

    /** The token of the kind, made from the first Saudi draft: a tampered id names the second one, which the Saudi storefront may show, so nothing but the signature stops it. */
    protected function tokenThatIsNotLive(string $kind): string
    {
        $token = $this->previewTokenOf(version: $this->saudiDraft);
        [$id, $expires, $signature] = explode('.', $token);

        return match ($kind) {
            'a tampered id' => "{$this->saudiSecondDraft->id}.{$expires}.{$signature}",
            'a moved expiry' => "{$id}." . ((int) $expires + 86400) . ".{$signature}",
            'an expired token' => $this->tokenMintedHoursAgo(version: $this->saudiDraft, hours: 2),
            'an expiry moved on an expired token' => $this->withTheExpiryMovedForward(token: $this->tokenMintedHoursAgo(version: $this->saudiDraft, hours: 2)),
            'a token of another application key' => $this->tokenOfAnotherApplicationKey(version: $this->saudiDraft),
            'a token without a signature' => "{$id}.{$expires}.",
            'a string that is not a token' => 'not-a-token',
            'a token with a part missing' => "{$id}.{$expires}",
            'a token with a part too many' => "{$token}.extra",
            'a token with letters in the id' => "{$id}x.{$expires}.{$signature}",
            'an empty value' => '',
        };
    }

    private function tokenMintedHoursAgo(ThemeVersion $version, int $hours): string
    {
        $this->travelTo(now()->subHours($hours));
        $token = $this->previewTokenOf(version: $version);
        $this->travelBack();

        return $token;
    }

    private function withTheExpiryMovedForward(string $token): string
    {
        [$id, $expires, $signature] = explode('.', $token);

        return "{$id}." . ((int) $expires + 86400 * 7) . ".{$signature}";
    }

    private function tokenOfAnotherApplicationKey(ThemeVersion $version): string
    {
        $applicationKey = config('app.key');
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        $token = $this->previewTokenOf(version: $version);
        config(['app.key' => $applicationKey]);

        return $token;
    }

    protected function isDraft(int $height): bool
    {
        return isset(self::DRAFT_TEXTS[$height]);
    }

    /** Whose page, or whose draft, a spacer of this height is: what a response carries is told by the heights of its spacers. */
    protected function pageOfHeight(int $height): string
    {
        return self::PAGES[$height] ?? "a page of a spacer of {$height}";
    }

    /** @param array<string, string|null> $parameters */
    private function queryOf(array $parameters): string
    {
        $named = array_filter($parameters, static fn (?string $value): bool => $value !== null);

        return $named === [] ? '' : '?' . http_build_query($named);
    }

    /** @return array<string, string> */
    private function storefrontHeader(?string $storefront): array
    {
        return $storefront === null ? [] : ['X-Storefront' => $storefront];
    }

    /** What the app is given for its home page: a draft when it sends a live token as `preview`. */
    protected function appHome(?string $token = null, ?string $storefront = null, array $headers = []): TestResponse
    {
        return $this->getJson('/api/v1/theme/home' . $this->queryOf(parameters: ['preview' => $token]), $this->storefrontHeader(storefront: $storefront) + $headers);
    }

    /** What the app is given by the sections endpoint, which reads the token the website reads: as `theme_preview`. */
    protected function appSections(?string $token = null, ?string $storefront = null): TestResponse
    {
        return $this->getJson('/api/v1/theme/sections' . $this->queryOf(parameters: ['page' => 'home', 'theme_preview' => $token]), $this->storefrontHeader(storefront: $storefront));
    }

    /** The custom page both themes have, drawn by the website. */
    protected function offersPage(?string $token = null, ?string $storefront = null): TestResponse
    {
        return $this->get('/p/offers' . $this->queryOf(parameters: ['theme_preview' => $token]), $this->storefrontHeader(storefront: $storefront));
    }

    /** The page the app was given has the spacer of this height, and carries the text of that draft and of no other. */
    protected function assertAppServes(int $height, TestResponse $response): void
    {
        $response->assertOk();
        $this->assertSame($this->pageOfHeight(height: $height), $this->pageOfHeight(height: (int) $response->json('sections.0.settings.height')));
        $this->assertTextOfNoDraftBut(height: $height, response: $response);
    }

    /** The page the website drew has the one spacer of this height, and carries the text of that draft and of no other. */
    protected function assertWebsiteServes(int $height, TestResponse $response): void
    {
        $response->assertOk();
        preg_match_all('/--tb-h:(\d+)px/', (string) $response->getContent(), $drawn);
        $this->assertSame([$this->pageOfHeight(height: $height)], array_map(fn (string $drawnHeight): string => $this->pageOfHeight(height: (int) $drawnHeight), $drawn[1]));
        $this->assertTextOfNoDraftBut(height: $height, response: $response);
    }

    private function assertTextOfNoDraftBut(int $height, TestResponse $response): void
    {
        foreach (self::DRAFT_TEXTS as $draftHeight => $text) {
            if ($draftHeight === $height) {
                $this->assertStringContainsString($text, (string) $response->getContent());
            } else {
                $this->assertStringNotContainsString($text, (string) $response->getContent());
            }
        }
    }

    /** The cache keys, and what was written under them, from now on: nothing is forgotten when the test says what it saw. */
    protected function recordCacheWrites(): ArrayObject
    {
        $writes = new ArrayObject();
        Event::listen(KeyWritten::class, static function (KeyWritten $event) use ($writes): void {
            $writes->append([$event->key, $event->value]);
        });

        return $writes;
    }

    /**
     * @param  list<array{0: string, 1: mixed}>  $writes
     * @return list<string> the keys among them that a theme page, a theme's settings or a theme's delivery is cached under
     */
    protected function themeKeysAmong(array $writes): array
    {
        return array_values(array_filter(array_column($writes, 0), static fn (string $key): bool => str_contains($key, 'theme')));
    }

    /** @param list<string>|null $modules the modules and capabilities of the role, or null for an admin who has no role at all */
    protected function actingAsAdminWith(?array $modules, int $roleId = 2): void
    {
        $admin = (new Admin())->forceFill(['id' => 7, 'name' => 'Theme viewer', 'admin_role_id' => $roleId]);
        $admin->setRelation('role', $modules === null ? null : (new AdminRole())->forceFill(['module_access' => json_encode($modules)]));

        $this->actingAs($admin, 'admin');
    }
}
