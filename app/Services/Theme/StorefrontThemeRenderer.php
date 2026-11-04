<?php

namespace App\Services\Theme;

use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves what the storefront should render for a page from the ACTIVE theme's PUBLISHED version
 * (Phase 1.1 → storefront).
 *
 * This is the compatibility shim that makes the whole theme system safe to ship: it only returns
 * sections when an active theme has a published version with visible sections for that page.
 * Otherwise it returns null, and the caller keeps rendering the existing hardcoded blades exactly
 * as today. Nothing about the current storefront changes until a merchant publishes a theme.
 *
 * Responsive settings are resolved per breakpoint (`key`, `key_tablet`, `key_mobile`) so a section
 * can differ across devices without the storefront knowing the naming convention.
 */
class StorefrontThemeRenderer
{
    public const CACHE_KEY_PREFIX = 'storefront_theme_page_';
    public const GLOBAL_SETTINGS_CACHE_KEY = 'storefront_theme_global_settings';
    private const CACHE_TTL = 600; // seconds

    public function __construct(
        private readonly SectionRegistry $registry,
        // Defaulted (PHP 8.1 new-in-initializers) so every existing construction — tests
        // included — keeps working; the container still injects a shared instance in normal boot.
        private readonly SectionVisibility $visibility = new SectionVisibility(),
    ) {
    }

    /**
     * Sections to render for a page, or NULL when the storefront should fall back to its existing
     * templates (no active theme / nothing published / no visible sections).
     *
     * @return array<int, array{type:string, settings:array, blocks:array}>|null
     */
    /**
     * Session key holding the theme version an admin is previewing.
     *
     * Preview is session-scoped and admin-gated rather than taking a version id from the URL: a
     * ?preview_version=N would be guessable, shareable and crawlable, which would expose an
     * unpublished design to customers and to search engines.
     */
    public const PREVIEW_SESSION_KEY = 'theme_preview_version_id';

    /**
     * Query parameter carrying a SIGNED preview token.
     *
     * This is the one way a preview may travel in a URL, and it answers each of the objections
     * above rather than ignoring them: the token is an HMAC over a version id and an expiry, so it
     * cannot be guessed or edited into another version, it stops working on its own, and
     * {@see \App\Http\Middleware\NoIndexThemePreview} marks any response carrying one noindex.
     *
     * It exists because the session cannot leave the browser the admin is signed in to, and the
     * question a merchant actually has — does this look right on a phone — can only be answered on
     * the phone.
     */
    public const PREVIEW_TOKEN_KEY = 'theme_preview';

    public function sectionsFor(string $page): ?array
    {
        if (!$this->tablesReady()) {
            return null;
        }

        // Draft preview: only for an authenticated admin, and never cached, so a preview can never
        // leak into what customers are served.
        $previewVersionId = $this->activePreviewVersionId();
        if ($previewVersionId !== null) {
            try {
                return $this->runnable($this->buildSections($previewVersionId, $page), $page);
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            // Wrapped in an array so the common "nothing published" result is cached
            // too: Cache::remember treats a bare null as a miss, and this runs several
            // times per storefront render (once per page area, plus the banner
            // hand-off), each miss costing a publishedVersion() lookup.
            $cached = Cache::remember(self::CACHE_KEY_PREFIX . $page, self::CACHE_TTL, function () use ($page) {
                $version = $this->publishedVersion();

                return ['sections' => $version ? $this->buildSections($version->id, $page) : null];
            });

            // Scheduling and audience targeting are applied to the CACHED structure, never inside
            // the cache: a campaign that opens at 09:00 would otherwise open up to a TTL late, and
            // a guest and a signed-in customer would share whichever of them warmed the entry.
            // What is cached is the shape of the page; what varies per request is who may see it.
            return $this->runnable($cached['sections'] ?? null, $page);
        } catch (\Throwable) {
            // A theme problem must never take the storefront down.
            return null;
        }
    }

    /**
     * The sections that actually run right now, for the visitor making this request.
     *
     * @param  array<int, array<string, mixed>>|null  $sections
     * @return array<int, array<string, mixed>>|null
     */
    private function runnable(?array $sections, string $page = 'home'): ?array
    {
        if ($sections === null) {
            return null;
        }

        $viewer = new ViewerContext(
            platform: ViewerContext::PLATFORM_WEB,
            // One response serves every width on the web, so breakpoint hiding stays a CSS
            // concern; only rules that cannot be expressed in CSS are decided here.
            device: ViewerContext::DEVICE_DESKTOP,
            // Guarded because this also runs from the console and from early-boot paths where no
            // session guard is resolvable. A viewer we cannot identify is a guest, which is the
            // narrower audience and therefore the safe assumption.
            authenticated: $this->customerIsSignedIn(),
            locale: app()->getLocale(),
            segments: $this->viewerSegments(),
        );

        $runnable = array_values(array_filter(
            $sections,
            fn (array $section) => $this->visibility->passes($section, $viewer),
        ));

        // Experiment assignments for this viewer (Phase 3.5) — empty means control everywhere,
        // which is also the answer to every failure (§48).
        try {
            $assignments = app(\App\Services\Commerce\ExperimentResolver::class)
                ->assignmentsFor($page, $this->webExperimentSubject());
        } catch (\Throwable) {
            $assignments = [];
        }

        // The experiment patch lands FIRST, then the language folds — a variant can carry its
        // own per-locale text, and no override key can ride a patch past the collapse. Done here,
        // once per request, so no partial ever meets a `title_ar` key.
        $experiments = app(\App\Services\Commerce\ExperimentResolver::class);
        $runnable = array_map(function (array $section) use ($viewer, $assignments, $experiments) {
            [$section['settings'], $section['experiment']] = $experiments
                ->patch($section['uuid'] ?? null, $section['settings'] ?? [], $assignments);

            $section['settings'] = LocalisedSettings::collapse(
                $section['settings'],
                $viewer->locale,
                $this->registry->localeOverrideKeys((string) ($section['type'] ?? '')),
            );
            $section['blocks'] = array_map(function (array $block) use ($viewer) {
                $block['settings'] = LocalisedSettings::collapse(
                    $block['settings'] ?? [],
                    $viewer->locale,
                    $this->registry->blockLocaleOverrideKeys((string) ($block['type'] ?? '')),
                );
                return $block;
            }, $section['blocks'] ?? []);

            return $section;
        }, $runnable);

        // Campaign overlay (§33): live campaign overrides dress the page per request, on top of
        // the cached base — so a campaign opens and closes on time, and the base page is never
        // written. Any failure here serves the base page unchanged (§37).
        try {
            $campaigns = app(\App\Services\Commerce\CampaignResolver::class);
            $overrides = $campaigns->overridesFor($page);

            if ($overrides !== []) {
                $registry = $this->registry;
                $runnable = $campaigns->splice(
                    $runnable,
                    $overrides,
                    fn (array $section, int $campaignId) => !isset($registry->types()[$section['type'] ?? ''])
                        ? null
                        : [
                        'id'         => null,
                        'uuid'       => 'campaign-' . $campaignId . '-' . $section['type'],
                        'type'       => $section['type'],
                        'is_visible' => true,
                        'starts_at'  => null, 'ends_at' => null,
                        'platforms'  => null, 'audience' => null,
                        'settings'   => LocalisedSettings::collapse(
                            $registry->normalizeSettings($section['type'], $section['settings'] ?? []),
                            $viewer->locale,
                            $registry->localeOverrideKeys($section['type']),
                        ),
                        'blocks'     => array_map(fn (array $block) => [
                            'id'       => null,
                            'type'     => $block['type'],
                            'settings' => LocalisedSettings::collapse(
                                $registry->normalizeBlockSettings($block['type'], $block['settings'] ?? []),
                                $viewer->locale,
                                $registry->blockLocaleOverrideKeys($block['type']),
                            ),
                        ], $section['blocks'] ?? []),
                    ],
                    fn (array $section) => $section['type'] ?? null,
                );
            }
        } catch (\Throwable) {
            // The base page is the answer whenever the overlay cannot be.
        }

        // An empty result means the same thing as no theme: keep the storefront's own templates,
        // rather than publishing a page whose every section happens to be out of schedule.
        return $runnable === [] ? null : $runnable;
    }

    private function customerIsSignedIn(): bool
    {
        // Both guards, because this renderer serves the storefront (session guard) AND the
        // legacy /theme/sections API (token guard): checking only the session left signed-in
        // app users looking like guests on that endpoint, hiding customer-only sections.
        try {
            return auth('customer')->check() || auth('api')->check();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The identity experiments bucket this web viewer by — the same rule ViewerContext applies
     * for the API: customer id first, the analytics visitor cookie otherwise, control when
     * neither resolves.
     */
    private function webExperimentSubject(): ?string
    {
        try {
            $customerId = auth('customer')->id() ?: auth('api')->id();
            if ($customerId) {
                return 'c' . $customerId;
            }

            $visitorId = app(\App\Services\Analytics\VisitorContext::class)->visitorId(request());

            return $visitorId !== null ? 'v' . $visitorId : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The signed-in customer's segments, or none — resolution failing costs the personalised
     * sections, never the page (§44). Both guards, for the same reason as customerIsSignedIn().
     *
     * @return array<int, string>
     */
    private function viewerSegments(): array
    {
        try {
            $customerId = auth('customer')->id() ?: auth('api')->id();

            return $customerId
                ? app(\App\Services\Commerce\SegmentResolver::class)->segmentsFor((int) $customerId)
                : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Whether the published theme already renders a dashboard banner type as one of its own
     * sections on the given page. The storefront's built-in slots for those banners stand down
     * when it does, so a merchant who places the banners themselves in the builder — to control
     * where in the page order they sit — does not get them twice.
     */
    public function pageSectionsRenderBannerType(string $page, string $bannerType): bool
    {
        foreach ($this->sectionsFor($page) ?? [] as $section) {
            // A section has two independent hide switches: the structure panel's
            // is_visible column (already filtered out upstream) and the settings
            // panel's `visible` toggle. Standing the built-in slot down for a section
            // the merchant has hidden would make the banners disappear entirely.
            if (($section['settings']['visible'] ?? true) === false) {
                continue;
            }

            if (($section['type'] ?? null) === 'store_banner'
                && ($section['settings']['banner_type'] ?? null) === $bannerType) {
                return true;
            }
        }

        return false;
    }

    /** Effective global settings (branding/colors/typography/layout) of the published version. */
    public function globalSettings(ThemeManager $manager): array
    {
        if (!$this->tablesReady()) {
            return $manager->defaultSettings();
        }

        try {
            return $manager->resolveSettings($this->publishedVersion());
        } catch (\Throwable) {
            return $manager->defaultSettings();
        }
    }

    /**
     * Resolved global settings ONLY when an active theme has a published version; null otherwise.
     *
     * This is what the storefront `<head>` consults to override its design tokens: returning null when
     * nothing is published preserves the contract that the storefront is unchanged until a merchant
     * publishes a theme (globalSettings() can't be used for this because it returns defaults either way).
     */
    public function publishedGlobalSettings(ThemeManager $manager): ?array
    {
        if (!$this->tablesReady()) {
            return null;
        }

        try {
            // Draft preview: for an authenticated admin previewing a draft, resolve THAT version's
            // settings (never cached) so the visual builder's live preview shows draft colours/typography
            // together with its draft sections. Guests and normal requests are unaffected.
            $previewVersionId = $this->activePreviewVersionId();
            if ($previewVersionId !== null) {
                $version = ThemeVersion::find($previewVersionId);
                return $version ? $manager->resolveSettings($version) : null;
            }

            // Cached like sectionsFor(): this runs in the storefront <head> on EVERY request, so an
            // uncached publishedVersion() lookup would hit the DB per page even with nothing published.
            // Wrapped in an array so the common "no published theme" (null) result is cached too —
            // Cache::remember treats a bare null as a miss and would re-query every request.
            $cached = Cache::remember(self::GLOBAL_SETTINGS_CACHE_KEY, self::CACHE_TTL, function () use ($manager) {
                $version = $this->publishedVersion();

                return ['settings' => $version ? $manager->resolveSettings($version) : null];
            });

            return $cached['settings'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve a setting for a breakpoint, falling back to the base value.
     * $breakpoint: desktop | tablet | mobile
     */
    public function responsiveValue(array $settings, string $key, string $breakpoint = 'desktop'): mixed
    {
        if ($breakpoint !== 'desktop') {
            $scoped = $key . '_' . $breakpoint;
            if (array_key_exists($scoped, $settings) && $settings[$scoped] !== null && $settings[$scoped] !== '') {
                return $settings[$scoped];
            }
        }
        return $settings[$key] ?? null;
    }

    /** Drop the cached structure for every page — call after publishing. */
    public function flush(array $pages = ['home', 'header', 'footer']): void
    {
        // Custom pages are cached under the same prefix by their own slugs; a publish that only
        // flushed the three built-ins left every composed page stale for a full TTL.
        try {
            if (Schema::hasTable('experience_pages')) {
                $pages = array_unique([...$pages, ...\App\Models\ExperiencePage::query()->pluck('slug')->all()]);
            }
        } catch (\Throwable) {
            // The built-ins still flush.
        }

        foreach ($pages as $page) {
            Cache::forget(self::CACHE_KEY_PREFIX . $page);
        }
        Cache::forget(self::GLOBAL_SETTINGS_CACHE_KEY);
    }

    /** Shared section builder used by both the published path and draft preview. */
    private function buildSections(int $versionId, string $page): ?array
    {
        $sections = ThemeSection::with('blocks')
            ->where('theme_version_id', $versionId)
            ->where('page', $page)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->get();

        if ($sections->isEmpty()) {
            return null; // nothing configured -> keep the existing storefront
        }

        return $sections->map(fn (ThemeSection $section) => [
            // The id travels to the storefront as a data attribute so the visual builder can map a
            // click in its preview iframe back to the section in its structure panel.
            'id'       => $section->id,
            'uuid'     => $section->uuid,
            'type'     => $section->type,
            // Delivery rules travel with the section so the post-cache filter can apply them
            // without a second query on a page a customer is waiting for.
            'is_visible' => true,
            'starts_at'  => $section->starts_at,
            'ends_at'    => $section->ends_at,
            'platforms'  => $section->platforms,
            'audience'   => $section->audience,
            // Without this key the channel rule was inert on this path too.
            'channels'   => $section->channels ?? null,
            'settings' => $this->registry->normalizeSettings($section->type, $section->settings ?? []),
            'blocks'   => $section->blocks
                ->where('is_visible', true)
                ->map(fn ($block) => [
                    'id'       => $block->id,
                    'type'     => $block->type,
                    // Normalized like sections: a block's settings reach the storefront coerced to
                    // their declared types, so a stale or hand-imported value cannot reach a view raw.
                    'settings' => $this->registry->normalizeBlockSettings($block->type, $block->settings ?? []),
                ])
                ->values()->all(),
        ])->all();
    }

    /**
     * The version being previewed: an admin's own session, or a signed token on the URL.
     *
     * Checked in that order because the session is the cheaper answer and the one an admin
     * browsing their own storefront already has.
     */
    private function activePreviewVersionId(): ?int
    {
        try {
            // Being signed in as an admin is not the same as being allowed to see an unpublished
            // version. The link endpoint already asks this question; asking it here instead of in
            // each controller makes it the answer for every way the key can be set — the builder
            // opening, the explicit Preview button, and whatever sets it next.
            $sessionVersionId = app(ThemePermissionService::class)->canView()
                ? (int) session(self::PREVIEW_SESSION_KEY)
                : 0;
            if ($sessionVersionId > 0) {
                // The id is taken on faith nowhere else: a merchant who discards the draft in
                // another tab leaves this key pointing at nothing, and an unchecked id turns the
                // builder frame into an empty page rather than the shop. Forgetting it here is what
                // ends the preview the moment there is nothing left to preview.
                if (ThemeVersion::query()->whereKey($sessionVersionId)->exists()) {
                    return $sessionVersionId;
                }

                session()->forget(self::PREVIEW_SESSION_KEY);
            }

            $token = request()?->query(self::PREVIEW_TOKEN_KEY);

            return app(ThemePreviewToken::class)->version(is_string($token) ? $token : null)?->id;
        } catch (\Throwable) {
            return null;
        }
    }

    private function publishedVersion(): ?ThemeVersion
    {
        $theme = Theme::query()->where('is_active', true)->first();
        if (!$theme) {
            return null;
        }
        return ThemeVersion::query()
            ->where('theme_id', $theme->id)
            ->where('status', ThemeVersion::STATUS_PUBLISHED)
            ->first();
    }

    private function tablesReady(): bool
    {
        return Schema::hasTable('themes')
            && Schema::hasTable('theme_versions')
            && Schema::hasTable('theme_sections');
    }
}
