<?php

namespace App\Services\Theme;

use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The published theme, assembled for one client.
 *
 * The storefront renderer answers "what does THIS server draw"; this answers "what may THAT client
 * be sent" — and the difference is the whole compatibility story. Between the database and the
 * phone sit four filters, applied in this order because each is cheaper than the next:
 *
 *   1. published    only the active theme's published version, never a draft
 *   2. visible      the two hide switches, the schedule, and the platform/audience rules
 *   3. capability   the intersection of what the app ships and what this build reports
 *   4. shape        settings resolved for the client's breakpoint, images absolutized,
 *                   links turned into typed actions
 *
 * What is dropped at step 3 is not silently dropped: the payload names the types it withheld, so
 * an old build's thin home page is explainable from the response itself rather than from guesswork
 * about which version the shopper is running.
 *
 * Every result is versioned by a `revision` that only moves on publish and a `checksum` that only
 * moves when the delivered bytes change — which is what lets a client ask "anything new?" for the
 * price of a header instead of a download.
 */
class ThemeDelivery
{
    private const CACHE_PREFIX = 'theme_delivery_';
    private const CACHE_TTL = 600;

    public function __construct(
        private readonly SectionRegistry $registry,
        private readonly SectionDataResolver $resolver,
        private readonly SectionVisibility $visibility,
        private readonly ComponentCapabilityRegistry $capabilities,
        private readonly ActionResolver $actions,
        private readonly ThemeSourceMap $sources,
        private readonly SectionDestination $destinations,
        private readonly ThemeManager $manager,
    ) {
    }

    /**
     * Settings keys whose value is an image path a client with no origin must be able to fetch.
     * Mirrors ThemeSectionController's list, which this service now backs.
     */
    private const IMAGE_KEYS = ['image', 'image_2', 'image_3', 'image_mobile', 'background_image', 'logo', 'icon_image', 'after'];

    /**
     * The cheap question: what is live, and is it what you already hold.
     *
     * Costs one indexed row and is cached, because every cold start and every resume of every
     * installed app asks it. `revision` 0 means nothing has ever been published — a client that
     * sees it renders its built-in home and stops asking for the session.
     *
     * @return array{revision: int, checksum: ?string, schema_version: int, engine_version: int, published_at: ?string}
     */
    public function revision(): array
    {
        $empty = [
            'revision' => 0,
            'checksum' => null,
            'schema_version' => ComponentCapabilityRegistry::SCHEMA_VERSION,
            'engine_version' => ComponentCapabilityRegistry::CURRENT_ENGINE_VERSION,
            'published_at' => null,
        ];

        if (!$this->tablesReady()) {
            return $empty;
        }

        try {
            return Cache::remember(self::CACHE_PREFIX . 'revision', self::CACHE_TTL, function () use ($empty) {
                $version = $this->publishedVersion();
                if ($version === null) {
                    return $empty;
                }

                // Every live campaign on every page: a campaign on a custom page must move this
                // checksum too, or apps holding that page never learn it changed.
                $checksum = $this->syncStamp($version);

                return [
                    'revision' => (int) ($version->revision ?: 1),
                    // A live campaign folds into the checksum: the app's "did anything change"
                    // question must answer YES when an overlay opened or closed, and the stored
                    // revision knows nothing about overlays.
                    'checksum' => $checksum,
                    'schema_version' => ComponentCapabilityRegistry::SCHEMA_VERSION,
                    'engine_version' => ComponentCapabilityRegistry::CURRENT_ENGINE_VERSION,
                    'published_at' => $version->published_at?->toIso8601String(),
                ];
            });
        } catch (\Throwable) {
            // A theme lookup that fails must read as "nothing published", never as an error the
            // client has to handle: the app's own fallback home is a better answer than a crash.
            return $empty;
        }
    }

    /**
     * The full page, shaped for this client.
     *
     * @return array{
     *     page: string, revision: int, schema_version: int, engine_version: int,
     *     checksum: string, published_at: ?string, tokens: array, sections: array,
     *     compatibility: array
     * }
     */
    public function payload(string $page, ViewerContext $viewer): array
    {
        if (!$this->tablesReady()) {
            return $this->emptyPayload($page);
        }

        try {
            // Experiment assignments are read ONCE and feed both the cache key and the build:
            // two separate reads could disagree across a status flip and cache one variant's
            // content under another's key for the whole TTL.
            $assignments = $this->assignments($page, $viewer);

            return Cache::remember(
                self::CACHE_PREFIX . $page . '_' . $this->fingerprint($viewer)
                    . $this->campaignKeyPart($page) . $this->experimentKeyPart($assignments),
                self::CACHE_TTL,
                fn () => $this->build($page, $viewer, null, $assignments),
            );
        } catch (\Throwable) {
            return $this->emptyPayload($page);
        }
    }

    /**
     * One unpublished version, delivered as if it were live.
     *
     * Not cached, and deliberately not sharing a code path with anything that is: a draft changes
     * on every save, and a preview that could be served from a cache — or could poison one a real
     * shopper reads — is worse than no preview. `preview` travels in the payload so the client can
     * say so on screen; a build that ignores the key simply renders the draft, which is what was
     * asked for.
     *
     * @return array<string, mixed>
     */
    public function previewPayload(ThemeVersion $version, string $page, ViewerContext $viewer): array
    {
        try {
            return ['preview' => true] + $this->build($page, $viewer, $version);
        } catch (\Throwable) {
            return ['preview' => true] + $this->emptyPayload($page);
        }
    }

    /** Drop every cached delivery — called on publish, alongside the storefront's own flush. */
    public function flush(): void
    {
        Cache::forget(self::CACHE_PREFIX . 'revision');

        // Per-client payloads are keyed by a capability fingerprint nobody enumerates, so they
        // cannot be forgotten one by one. They carry the revision in their key instead: a publish
        // moves the revision, which strands every old entry to expire on its own TTL.
        Cache::forget(self::CACHE_PREFIX . 'home_index');
    }

    /**
     * Stamp a version with the revision and checksum a client syncs against.
     *
     * Called at publish time inside the publishing transaction, so a version becomes live and
     * becomes addressable in the same commit — a client can never observe a published version that
     * has no revision.
     */
    public function stampVersion(ThemeVersion $version): ThemeVersion
    {
        // Code can reach a publish before the revision migration has run (a deploy is not atomic
        // with its migrations, and the theme tests build their schemas by hand). Publishing still
        // works then — the version simply is not addressable until the columns exist.
        if (!Schema::hasColumn($version->getTable(), 'revision')) {
            return $version;
        }

        $highest = (int) ThemeVersion::query()
            ->where('theme_id', $version->theme_id)
            // Locked: two concurrent publishes reading the same max would mint the same revision,
            // and the app's sync protocol depends on revisions never repeating.
            ->lockForUpdate()
            ->max('revision');

        $version->revision = $highest + 1;
        $version->checksum = $this->checksumFor($version);
        $version->save();

        return $version;
    }

    // ---------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function build(string $page, ViewerContext $viewer, ?ThemeVersion $version = null, ?array $assignments = null): array
    {
        $version ??= $this->publishedVersion();
        if ($version === null) {
            return $this->emptyPayload($page);
        }

        $rows = ThemeSection::with('blocks')
            ->where('theme_version_id', $version->id)
            ->where('page', $page)
            ->orderBy('sort_order')
            ->get();

        $sections = [];
        $withheld = [];

        // Experiment assignments for this viewer (Phase 3.5): a settings patch per targeted
        // section uuid, resolved by payload() so the cache key and this content agree; the
        // preview path passes none and resolves here. Empty on any failure — control is the
        // answer to every problem (§48).
        $assignments ??= $this->assignments($page, $viewer);

        foreach ($rows as $row) {
            $section = [
                'is_visible' => (bool) $row->is_visible,
                'settings'   => $row->settings ?? [],
                'starts_at'  => $row->starts_at,
                'ends_at'    => $row->ends_at,
                'platforms'  => $row->platforms,
                'audience'   => $row->audience,
                // Without this key the channel rule was inert on this path: a section restricted
                // to the web was still delivered to every app.
                'channels'   => $row->channels ?? null,
            ];

            if (!$this->visibility->passes($section, $viewer)) {
                continue;
            }

            // A type this client cannot draw is recorded and skipped. Recorded, because "the app
            // shows 9 of my 11 sections" needs an answer, and the response is the only place a
            // support conversation can get one.
            if ($viewer->platform !== ViewerContext::PLATFORM_WEB
                && !$this->capabilities->clientHolds($row->type, $viewer)) {
                $withheld[$row->type] ??= $this->capabilities->exclusionReason($row->type)
                    ?? 'this_build_does_not_report_support_for_this_section';
                continue;
            }

            $sections[] = $this->present($row, $viewer, $assignments);
        }

        // Campaign overlay (§33): live overrides join the delivered list through the same
        // present() pipeline as stored sections — same normalisation, same action typing, same
        // capability withholding. Any failure serves the base payload unchanged (§37).
        try {
            $campaigns = app(\App\Services\Commerce\CampaignResolver::class);
            $overrides = $campaigns->overridesFor($page);

            if ($overrides !== []) {
                $sections = $campaigns->splice(
                    $sections,
                    $overrides,
                    function (array $definition, int $campaignId) use ($viewer, &$withheld) {
                        // A type this SERVER does not render is a corrupted row, whatever wrote
                        // it; and a type this CLIENT cannot draw is withheld exactly as a stored
                        // section of that type would be — named in the compatibility report, so
                        // "the campaign shows on the web and not on my phone" has an answer.
                        if (!isset($this->registry->types()[$definition['type'] ?? ''])) {
                            return null;
                        }
                        if ($viewer->platform !== ViewerContext::PLATFORM_WEB
                            && !$this->capabilities->clientHolds($definition['type'], $viewer)) {
                            $withheld[$definition['type']] ??= $this->capabilities->exclusionReason($definition['type'])
                                ?? 'this_build_does_not_report_support_for_this_section';
                            return null;
                        }

                        $row = new ThemeSection();
                        $row->forceFill([
                            'uuid'     => 'campaign-' . $campaignId . '-' . $definition['type'],
                            'type'     => $definition['type'],
                            'settings' => $definition['settings'] ?? [],
                            'is_visible' => true,
                        ]);
                        $row->setRelation('blocks', collect(array_map(function (array $block) {
                            $model = new \App\Models\ThemeBlock();
                            $model->forceFill([
                                'type' => $block['type'], 'settings' => $block['settings'] ?? [],
                                'is_visible' => true, 'sort_order' => 1,
                            ]);

                            return $model;
                        }, $definition['blocks'] ?? [])));

                        return $this->present($row, $viewer);
                    },
                    fn (array $section) => $section['type'] ?? null,
                );
            }
        } catch (\Throwable) {
            // The base payload is the answer whenever the overlay cannot be.
        }

        $payload = [
            'page'           => $page,
            'revision'       => (int) ($version->revision ?: 1),
            // The SAME value /theme/version serves as its checksum. The per-client checksum below
            // is an ETag over delivered bytes and can never equal the version endpoint's stored
            // hash — comparing the two made every resume look like a change. This is the sync
            // validator; the checksum stays the cache validator.
            'content_stamp'  => $this->syncStamp($version),
            'schema_version' => ComponentCapabilityRegistry::SCHEMA_VERSION,
            'engine_version' => ComponentCapabilityRegistry::CURRENT_ENGINE_VERSION,
            'published_at'   => $version->published_at?->toIso8601String(),
            'tokens'         => $this->tokens($version),
            'sections'       => $sections,
            'compatibility'  => [
                'delivered' => count($sections),
                'withheld'  => $withheld,
            ],
        ];

        // The ETag is computed over what is actually being delivered, not over the stored version:
        // two clients with different capabilities hold genuinely different pages and must not
        // share a validator, or the weaker one would be told it is up to date with the other's.
        $payload['checksum'] = substr(hash('sha256', json_encode($payload)), 0, 32);

        return $payload;
    }

    /**
     * One section as a client renders it.
     *
     * @return array<string, mixed>
     */
    private function present(ThemeSection $row, ViewerContext $viewer, array $assignments = []): array
    {
        // The experiment patch lands FIRST, then the language folds (Phase 3.5): a variant can
        // carry its own per-locale text and have it localise like anything else, and no override
        // key can ride a patch into a payload — after the collapse, `title` IS the title for the
        // `lang` header this client sent, whatever wrote it.
        [$settings, $experiment] = app(\App\Services\Commerce\ExperimentResolver::class)
            ->patch($row->uuid, $this->registry->normalizeSettings($row->type, $row->settings ?? []), $assignments);

        $settings = LocalisedSettings::collapse($settings, $viewer->locale, $this->registry->localeOverrideKeys($row->type));

        $blocks = $row->blocks
            ->where('is_visible', true)
            ->map(fn ($block) => [
                'id'       => $block->id,
                'type'     => $block->type,
                'settings' => $this->shape(
                    LocalisedSettings::collapse(
                        $this->registry->normalizeBlockSettings($block->type, $block->settings ?? []),
                        $viewer->locale,
                        $this->registry->blockLocaleOverrideKeys($block->type),
                    ),
                    $viewer,
                ),
            ])
            ->values()->all();
$blocks = $row->blocks
            ->where('is_visible', true)
            ->map(fn ($block) => [
                'id'       => $block->id,
                'type'     => $block->type,
                'settings' => $this->shape(
                    LocalisedSettings::collapse(
                        $this->registry->normalizeBlockSettings($block->type, $block->settings ?? []),
                        $viewer->locale,
                        $this->registry->blockLocaleOverrideKeys($block->type),
                    ),
                    $viewer,
                ),
            ])
            ->values()->all();

        // أضف هذه القطعة فقط أسفل الكود السابق مباشرة:
        if ($row->type === 'stats_bar') {
            $stats = $this->resolver->storeStats();
            foreach ($blocks as &$block) {
                $source = $block['settings']['source'] ?? null;
                if ($source && isset($stats[$source])) {
                    $block['settings']['value'] = (string) $stats[$source];
                }
            }
            unset($block);
        }
        $bannerBacked = array_intersect(
            array_column($blocks, 'type'),
            SectionRegistry::BANNER_BACKED_BLOCK_TYPES,
        ) !== [];

        // store_banner has no child blocks: on the web its banners are resolved LIVE from
        // Promotion -> Banners at render time. Without this, the app received the section with no
        // cards at all and hid it — which is how the merchant's MAIN BANNER could be on the web
        // and absent from every phone. Resolved through the same resolver the web uses, so the
        // two can never disagree about which banners show or in what order.
        $storeCards = null;
        if ($row->type === 'store_banner') {
            $storeCards = array_map(
                fn (array $card) => $this->actions->annotate($this->absolutize($card)),
                $this->resolver->dashboardBanners(
                    (string) ($settings['banner_type'] ?? 'Main Banner'),
                    max(1, (int) ($settings['limit'] ?? 6)),
                ),
            );
        }

        // banner_strip carries no child blocks — its one banner lives in the section settings.
        // The app's banner renderer draws CARDS, so the settings become the card here; without
        // this the section arrived with cards: null and the app hid it while the web showed it.
        $stripCards = null;
        if ($row->type === 'banner_strip' && trim((string) ($settings['image'] ?? '')) !== '') {
            $stripCards = [$this->actions->annotate($this->absolutize(array_filter([
                'type'        => 'banner',
                'image'       => $settings['image'],
                'eyebrow'     => $settings['eyebrow'] ?? null,
                'title'       => $settings['title'] ?? null,
                'subtitle'    => $settings['subtitle'] ?? null,
                'link'        => $settings['link'] ?? null,
                'button_text' => $settings['button_text'] ?? null,
                // The merchant's strip text color travels with the card — the app reads a
                // card's text_color, and without it every strip fell back to white.
                'text_color'  => $settings['text_color'] ?? null,
            ], static fn ($value) => $value !== null && $value !== '')))];
        }

        return [
            // The uuid is the identity a client keeps across publishes; the id is what the
            // builder's preview maps a click back to. Both travel, because they answer different
            // questions and a client that guesses wrong gets subtly broken state.
            'uuid'     => $row->uuid,
            'id'       => $row->id,
            'type'     => $row->type,
            // The layout the merchant chose, lifted out of the settings bag it is stored in.
            // Clients branch on it, and reading it from one key means they no longer have to know
            // that most types call it `style` and one calls it `layout`.
            'variant'  => $this->variantOf($row->type, $settings),
            // The contract generation this section is delivered under. A client that negotiated a
            // lower version for this type never receives it — but one that simply ignores the key
            // behaves exactly as it does today, which is what every installed build does.
            'component_version' => $this->registry->types()[$row->type]['version'] ?? 1,
            'settings' => $this->shape($settings, $viewer),
            'blocks'   => $blocks,
            'cards'    => $storeCards ?? $stripCards ?? ($bannerBacked
                ? array_map(
                    fn (array $card) => $this->actions->annotate($this->absolutize($card)),
                    $this->resolver->blockCards($row->blocks->where('is_visible', true)->map(fn ($b) => [
                        'type' => $b->type,
                        'settings' => LocalisedSettings::collapse(
                            $this->registry->normalizeBlockSettings($b->type, $b->settings ?? []),
                            $viewer->locale,
                            $this->registry->blockLocaleOverrideKeys($b->type),
                        ),
                    ])->values()->all()),
                )
                : null),
            'source'   => $this->sources->for($row->type, $settings, $blocks),
            // Which experiment variant this section is, when it is one. Optional and additive:
            // clients that ignore it render the variant they were given, which is the point.
            'experiment' => $experiment,
            // Where the heading's "view all" leads, decided from what the section shows rather
            // than from its type alone — so a rail scoped to one category opens that category on
            // the phone, exactly as it does on the web. `none` when the section leads nowhere.
            'view_all' => $this->destinations->actionFor($row->type, $settings),
        ];
    }

    /**
     * The display variant a section is stored with, whichever key its type uses.
     *
     * Null for a type with one look — the client then has nothing to branch on, which is correct.
     */
    private function variantOf(string $type, array $settings): ?string
    {
        $key = $this->registry->variantKeyFor($type);

        if ($key === null) {
            return null;
        }

        $variant = $settings[$key] ?? null;

        return is_string($variant) && $variant !== '' ? $variant : null;
    }

    /**
     * Settings resolved for this client: the right breakpoint, whole image URLs, typed actions.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function shape(array $settings, ViewerContext $viewer): array
    {
        return $this->actions->annotate($this->absolutize($this->forBreakpoint($settings, $viewer->device)));
    }

    /**
     * Per-breakpoint overrides collapsed onto their base keys.
     *
     * The builder stores `columns`, `columns_tablet`, `columns_mobile`; the web picks at render
     * time because one page serves every width. A native client has exactly one width, so the
     * winning value is resolved here and the siblings are left in place for a client that wants
     * to re-resolve on rotation.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function forBreakpoint(array $settings, string $device): array
    {
        if ($device === ViewerContext::DEVICE_DESKTOP) {
            return $settings;
        }

        $suffix = '_' . $device;

        foreach ($settings as $key => $value) {
            if (str_ends_with($key, $suffix) && $value !== null && $value !== '') {
                $settings[substr($key, 0, -strlen($suffix))] = $value;
            }
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function absolutize(array $values): array
    {
        foreach (self::IMAGE_KEYS as $key) {
            $value = $values[$key] ?? null;

            if (is_string($value) && str_starts_with($value, '/')) {
                $values[$key] = url($value);
            }
        }

        // A frames list is a list of images: the sections endpoint absolutizes it, and the two
        // surfaces must hand the phone the same URLs.
        if (isset($values['images']) && is_array($values['images'])) {
            $values['images'] = array_map(
                static fn ($image) => is_string($image) && str_starts_with($image, '/') ? url($image) : $image,
                $values['images'],
            );
        }

        return $values;
    }

    /**
     * The design tokens both renderers consume — the same resolved settings the storefront's
     * <head> writes as CSS custom properties, handed to the app as data.
     *
     * @return array<string, mixed>
     */
    private function tokens(ThemeVersion $version): array
    {
        $settings = $this->manager->resolveSettings($version);

        return [
            'colors'     => $settings['colors'] ?? [],
            'typography' => $settings['typography'] ?? [],
            'layout'     => $settings['layout'] ?? [],
            'branding'   => $this->absolutize(array_map(
                fn ($value) => is_string($value) && $value !== '' ? $value : null,
                $settings['branding'] ?? [],
            )),
        ];
    }

    /**
     * A stable identity for everything that changes what this client is served.
     *
     * The revision is in the key so a publish strands every stale entry at once without having to
     * enumerate capability sets that nobody records. The capability list is sorted before hashing,
     * because two clients that report the same components in a different order hold the same page.
     */
    /**
     * This viewer's experiment assignments, or none — control answers every failure (§48).
     *
     * @return array<string, array<string, mixed>>
     */
    private function assignments(string $page, ViewerContext $viewer): array
    {
        try {
            return app(\App\Services\Commerce\ExperimentResolver::class)
                ->assignmentsFor($page, $viewer->experimentSubject);
        } catch (\Throwable) {
            return [];
        }
    }

    /** The cache-key fragment the already-resolved assignments contribute. */
    private function experimentKeyPart(array $assignments): string
    {
        if ($assignments === []) {
            return '';
        }

        ksort($assignments);

        return '_x' . substr(hash('crc32b', json_encode(array_map(
            fn (array $assignment) => $assignment['variant'],
            $assignments,
        ))), 0, 8);
    }

    /** The cache-key fragment the live campaign set contributes; empty when none is live. */
    private function campaignKeyPart(string $page): string
    {
        $stamp = $this->campaignStamp($page);

        return $stamp === null ? '' : '_c' . $stamp;
    }

    /**
     * The one sync validator both endpoints speak: the stored structural checksum, with every
     * live campaign folded in. /theme/version serves it as `checksum`; /theme/home echoes it as
     * `content_stamp` — the pair being equal is what lets the app's cheap "did anything change"
     * check actually answer NO.
     */
    private function syncStamp(ThemeVersion $version): ?string
    {
        $stamp = $this->campaignStamp(null);

        if ($version->checksum === null) {
            return $stamp;
        }

        return $stamp === null ? $version->checksum : $version->checksum . '-' . $stamp;
    }

    private function campaignStamp(?string $page = 'home'): ?string
    {
        try {
            return app(\App\Services\Commerce\CampaignResolver::class)->stamp($page);
        } catch (\Throwable) {
            return null;
        }
    }

    private function fingerprint(ViewerContext $viewer): string
    {
        $components = $viewer->supportedComponents;
        sort($components);

        return substr(hash('sha256', implode('|', [
            // Checksum, not the bare number: revision 5 of theme A and revision 5 of theme B are
            // different pages, and an activation must never serve the old theme from cache.
            (string) $this->revision()['checksum'] . '#' . $this->revision()['revision'],
            $viewer->platform,
            $viewer->device,
            $viewer->audience(),
            // The matched segment SET joins the key (§64–65): what varies the response must vary
            // the cache. Bounded by the segments actually defined, not by customers — two repeat
            // buyers share one entry, and no customer data is ever IN a shared value.
            implode(',', $viewer->segments),
            // Text is folded to the request's language before caching, so the language is part of
            // what makes two deliveries the same delivery. Without it, the first shopper's locale
            // would warm the cache for everybody's.
            (string) $viewer->locale,
            $viewer->uiEngineVersion,
            $viewer->uiSchemaVersion,
            implode(',', $components),
        ])), 0, 24);
    }

    /**
     * The checksum stored on a version: a hash of its structure, so republishing identical content
     * does not cost every installed app a download.
     */
    private function checksumFor(ThemeVersion $version): string
    {
        $structure = ThemeSection::with('blocks')
            ->where('theme_version_id', $version->id)
            ->orderBy('page')->orderBy('sort_order')
            ->get()
            ->map(fn (ThemeSection $section) => [
                $section->uuid, $section->page, $section->type, $section->sort_order,
                $section->is_visible, $section->settings,
                $section->starts_at?->toIso8601String(), $section->ends_at?->toIso8601String(),
                $section->platforms, $section->audience,
                $section->blocks->map(fn ($block) => [
                    $block->type, $block->sort_order, $block->is_visible, $block->settings,
                ])->all(),
            ])->all();

        return substr(hash('sha256', json_encode([$version->settings, $structure])), 0, 32);
    }

    /** @return array<string, mixed> */
    private function emptyPayload(string $page): array
    {
        return [
            'page'           => $page,
            'revision'       => 0,
            'schema_version' => ComponentCapabilityRegistry::SCHEMA_VERSION,
            'engine_version' => ComponentCapabilityRegistry::CURRENT_ENGINE_VERSION,
            'checksum'       => null,
            'published_at'   => null,
            'tokens'         => [],
            'sections'       => [],
            'compatibility'  => ['delivered' => 0, 'withheld' => []],
        ];
    }

    private function publishedVersion(): ?ThemeVersion
    {
        $theme = Theme::query()->where('is_active', true)->first();
        if ($theme === null) {
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