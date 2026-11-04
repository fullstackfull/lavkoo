<?php

namespace App\Services\Theme;

use App\Models\Theme;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Theme import / export / presets (Phase 1.1).
 *
 * Import is the security-sensitive half: the payload is untrusted (an uploaded file or a pasted
 * blob), so nothing from it is written verbatim. Section and block types must exist in the
 * SectionRegistry, settings go through the same normalizeSettings() the builder uses (unknown keys
 * dropped, values coerced), and ownership/status fields are set by us — never taken from the file.
 *
 * Export produces a plain array so the caller can present it as JSON, a download, or a stored
 * preset without this service knowing about HTTP or storage.
 */
class ThemePortabilityService
{
    /** Bumped when the payload shape changes, so old files can be recognised. */
    public const FORMAT_VERSION = 1;

    /** Hard caps so a malicious payload can't exhaust memory or create thousands of rows. */
    private const MAX_SECTIONS = 200;
    private const MAX_BLOCKS_PER_SECTION = SectionRegistry::MAX_BLOCKS_PER_SECTION;

    public function __construct(
        private readonly SectionRegistry $registry,
        private readonly ThemeManager    $manager,
    )
    {
    }

    /** Serialize a version (settings + sections + blocks) into a portable array. */
    public function export(ThemeVersion $version): array
    {
        $sections = ThemeSection::with('blocks')
            ->where('theme_version_id', $version->id)
            ->orderBy('page')->orderBy('sort_order')
            ->get();

        return [
            'format_version' => self::FORMAT_VERSION,
            'exported_at'    => now()->toIso8601String(),
            'theme'          => [
                'name'        => $version->theme?->name,
                'description' => $version->theme?->description,
            ],
            'settings' => $version->settings ?? [],
            'sections' => $sections->map(fn (ThemeSection $s) => [
                'page'       => $s->page,
                'type'       => $s->type,
                'sort_order' => $s->sort_order,
                'is_visible' => (bool) $s->is_visible,
                'settings'   => $s->settings ?? [],
                // Identity and delivery rules travel with the section: an export that dropped
                // them silently un-scheduled every timed section and re-minted every uuid on
                // import — per-section app state and analytics history broke with it.
                'uuid'       => $s->uuid ?? null,
                'starts_at'  => $s->starts_at?->toIso8601String(),
                'ends_at'    => $s->ends_at?->toIso8601String(),
                'platforms'  => $s->platforms ?? null,
                'audience'   => $s->audience ?? null,
                'channels'   => $s->channels ?? null,
                'blocks'     => $s->blocks->map(fn (ThemeBlock $b) => [
                    'type'       => $b->type,
                    'sort_order' => $b->sort_order,
                    'is_visible' => (bool) $b->is_visible,
                    'settings'   => $b->settings ?? [],
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Validate an untrusted payload without writing anything.
     *
     * @return array{valid:bool, errors:array<int,string>, warnings:array<int,string>, section_count:int}
     */
    public function validate(mixed $payload): array
    {
        $errors = [];
        $warnings = [];

        if (!is_array($payload)) {
            return ['valid' => false, 'errors' => ['payload_is_not_an_object'], 'warnings' => [], 'section_count' => 0];
        }

        $format = $payload['format_version'] ?? null;
        if ($format === null) {
            $errors[] = 'missing_format_version';
        } elseif ((int) $format > self::FORMAT_VERSION) {
            $errors[] = 'format_version_is_newer_than_this_installation_supports';
        }

        $sections = $payload['sections'] ?? [];
        if (!is_array($sections)) {
            $errors[] = 'sections_must_be_a_list';
            $sections = [];
        }

        if (count($sections) > self::MAX_SECTIONS) {
            $errors[] = 'too_many_sections';
        }

        $accepted = 0;
        foreach ($sections as $section) {
            if (!is_array($section) || empty($section['type'])) {
                $warnings[] = 'a_section_without_a_type_was_skipped';
                continue;
            }
            if (!$this->registry->has((string) $section['type'])) {
                // Unknown types are skipped, not fatal: a theme exported from an installation with
                // extra section types should still import the parts this one understands.
                $warnings[] = 'unknown_section_type_skipped: ' . (string) $section['type'];
                continue;
            }
            if (isset($section['blocks']) && is_array($section['blocks'])
                && count($section['blocks']) > self::MAX_BLOCKS_PER_SECTION) {
                $warnings[] = 'block_list_truncated_for: ' . (string) $section['type'];
            }
            $accepted++;
        }

        if ($accepted === 0 && $errors === []) {
            $errors[] = 'no_importable_sections_found';
        }

        return [
            'valid'         => $errors === [],
            'errors'        => $errors,
            'warnings'      => $warnings,
            'section_count' => $accepted,
        ];
    }

    /**
     * Import a validated payload into a NEW theme (never overwrites an existing one) with a single
     * draft version. The caller publishes it explicitly, so importing can't change the live store.
     *
     * @return array{theme:?Theme, version:?ThemeVersion, result:array}
     */
    public function import(mixed $payload, ?string $nameOverride = null, ?int $adminId = null): array
    {
        $result = $this->validate($payload);
        if (!$result['valid']) {
            return ['theme' => null, 'version' => null, 'result' => $result];
        }

        $name = $nameOverride
            ?: (string) ($payload['theme']['name'] ?? 'Imported theme');
        $name = trim($name) !== '' ? trim($name) : 'Imported theme';

        return DB::transaction(function () use ($payload, $name, $adminId, $result) {
            $theme = Theme::create([
                'name'            => $name,
                'slug'            => $this->uniqueSlug($name),
                'description'     => is_string($payload['theme']['description'] ?? null)
                    ? $payload['theme']['description'] : null,
                'is_active'       => false,   // never auto-activate an imported theme
                'is_system'       => false,   // never let a payload claim system status
                'created_by_type' => 'admin',
                'created_by_id'   => $adminId,
            ]);

            $version = ThemeVersion::create([
                'theme_id' => $theme->id,
                'label'    => 'Imported',
                'status'   => ThemeVersion::STATUS_DRAFT,   // always a draft
                'settings' => $this->sanitizeGlobalSettings($payload['settings'] ?? []),
            ]);

            $order = [];
            foreach (($payload['sections'] ?? []) as $raw) {
                if (!is_array($raw) || empty($raw['type']) || !$this->registry->has((string) $raw['type'])) {
                    continue;
                }
                $page = is_string($raw['page'] ?? null) ? strtolower(trim($raw['page'])) : 'home';
                if (!preg_match('/^[a-z0-9\-]{1,60}$/', $page)) {
                    $page = 'home';
                }
                // A type may only land on a page its definition names — a file must not graft a
                // footer column into the home page or invent pages a typo at a time.
                $allowedPages = $this->registry->types()[(string) $raw['type']]['pages'] ?? ['home'];
                if (!in_array($page, $allowedPages, true) && !in_array('home', $allowedPages, true)) {
                    $page = $allowedPages[0] ?? 'home';
                } elseif (!in_array($page, $allowedPages, true)) {
                    // Custom pages are legal homes for home-capable types; anything else falls
                    // back to the type's first legal page.
                    $page = in_array('home', $allowedPages, true) ? $page : ($allowedPages[0] ?? 'home');
                }
                $order[$page] = ($order[$page] ?? 0) + 1;

                $uuid = is_string($raw['uuid'] ?? null)
                    && preg_match('/^[0-9a-f\-]{36}$/i', $raw['uuid'])
                    ? strtolower($raw['uuid']) : null;

                $attributes = [
                    'theme_version_id' => $version->id,
                    'page'             => $page,
                    'type'             => (string) $raw['type'],
                    'sort_order'       => $order[$page],   // re-sequenced; the file's order is not trusted
                    'is_visible'       => (bool) ($raw['is_visible'] ?? true),
                    'uuid'             => $uuid,
                    'starts_at'        => $this->moment($raw['starts_at'] ?? null),
                    'ends_at'          => $this->moment($raw['ends_at'] ?? null),
                    'platforms'        => $this->tokenList($raw['platforms'] ?? null),
                    'audience'         => $this->tokenList($raw['audience'] ?? null),
                    'channels'         => $this->tokenList($raw['channels'] ?? null),
                    'settings'         => $this->registry->normalizeSettings(
                        (string) $raw['type'],
                        is_array($raw['settings'] ?? null) ? $raw['settings'] : []
                    ),
                ];

                // Identity and delivery-rule columns are additive migrations; an install that
                // predates them must still import the parts its schema holds.
                foreach (['uuid', 'starts_at', 'ends_at', 'platforms', 'audience', 'channels'] as $additive) {
                    if (!$this->sectionColumn($additive)) {
                        unset($attributes[$additive]);
                    }
                }

                $section = ThemeSection::create($attributes);

                $blocks = is_array($raw['blocks'] ?? null) ? array_slice($raw['blocks'], 0, self::MAX_BLOCKS_PER_SECTION) : [];
                $blockOrder = 0;
                foreach ($blocks as $block) {
                    if (!is_array($block) || empty($block['type'])) {
                        continue;
                    }
                    $blockType = (string) $block['type'];
                    // Only block types the parent section actually declares, with settings
                    // normalized against their schema — an imported file is untrusted input, so it
                    // may not graft an arbitrary block type or arbitrary keys into a section.
                    if (!$this->registry->hasBlockType((string) $raw['type'], $blockType)) {
                        continue;
                    }

                    ThemeBlock::create([
                        'theme_section_id' => $section->id,
                        'type'             => $blockType,
                        'sort_order'       => ++$blockOrder,
                        'is_visible'       => (bool) ($block['is_visible'] ?? true),
                        'settings'         => $this->registry->normalizeBlockSettings(
                            $blockType,
                            is_array($block['settings'] ?? null) ? $block['settings'] : []
                        ),
                    ]);
                }
            }

            return ['theme' => $theme->refresh(), 'version' => $version->refresh(), 'result' => $result];
        });
    }

    /** Built-in starting points a merchant can import in one click. */
    /** @var array<string, bool> memo: which additive theme_sections columns this install has */
    private array $sectionColumns = [];

    private function sectionColumn(string $column): bool
    {
        return $this->sectionColumns[$column] ??= \Illuminate\Support\Facades\Schema::hasColumn('theme_sections', $column);
    }

    /** A moment from an untrusted file, or null. */
    private function moment(mixed $value): ?\Illuminate\Support\Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A list of short tokens from an untrusted file, or null. Values are strings the delivery
     * rules read back (platform names, audience/segment keys, channels) — anything else is noise.
     *
     * @return array<int, string>|null
     */
    private function tokenList(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $tokens = array_values(array_filter(array_map(
            static fn ($token) => is_string($token) ? substr(trim($token), 0, 40) : '',
            $value,
        ), static fn (string $token) => $token !== '' && preg_match('/^[a-z0-9_\-]+$/i', $token)));

        return $tokens === [] ? null : array_slice($tokens, 0, 12);
    }

    public function presets(): array
    {
        return [
            'starter' => [
                'label'   => 'starter_storefront',
                'payload' => [
                    'format_version' => self::FORMAT_VERSION,
                    'theme'    => ['name' => 'Starter storefront'],
                    'settings' => ['colors' => ['primary' => '#0f766e']],
                    'sections' => [
                        ['page' => 'home', 'type' => 'hero_banner',     'sort_order' => 1, 'is_visible' => true, 'settings' => []],
                        ['page' => 'home', 'type' => 'category_grid',   'sort_order' => 2, 'is_visible' => true, 'settings' => ['limit' => 12]],
                        ['page' => 'home', 'type' => 'product_slider',  'sort_order' => 3, 'is_visible' => true, 'settings' => ['source' => 'featured', 'limit' => 10]],
                        ['page' => 'home', 'type' => 'brand_slider',    'sort_order' => 4, 'is_visible' => true, 'settings' => []],
                        ['page' => 'footer', 'type' => 'footer_columns','sort_order' => 1, 'is_visible' => true, 'settings' => ['columns' => 4]],
                    ],
                ],
            ],
            'promotional' => [
                'label'   => 'promotional_storefront',
                'payload' => [
                    'format_version' => self::FORMAT_VERSION,
                    'theme'    => ['name' => 'Promotional storefront'],
                    'settings' => ['colors' => ['primary' => '#c2410c', 'accent' => '#f59e0b']],
                    'sections' => [
                        ['page' => 'header', 'type' => 'announcement_bar', 'sort_order' => 1, 'is_visible' => true, 'settings' => []],
                        ['page' => 'home', 'type' => 'hero_banner',        'sort_order' => 1, 'is_visible' => true, 'settings' => []],
                        ['page' => 'home', 'type' => 'flash_deal',         'sort_order' => 2, 'is_visible' => true, 'settings' => ['countdown' => true]],
                        ['page' => 'home', 'type' => 'product_slider',     'sort_order' => 3, 'is_visible' => true, 'settings' => ['source' => 'best_selling']],
                        ['page' => 'home', 'type' => 'promotional_banner', 'sort_order' => 4, 'is_visible' => true, 'settings' => []],
                        ['page' => 'home', 'type' => 'newsletter',         'sort_order' => 5, 'is_visible' => true, 'settings' => []],
                    ],
                ],
            ],
            // A professional cosmetics/beauty storefront: a full home composed for the Minimal Luxury
            // renderer (refined neutrals, gold accent, generous spacing). One-click starting point.
            'minimal_luxury' => [
                'label'   => 'minimal_luxury_beauty',
                'payload' => [
                    'format_version' => self::FORMAT_VERSION,
                    'theme'    => ['name' => 'Glossy Beauty', 'description' => 'A glossy, gradient-led storefront for beauty & cosmetics.'],
                    'settings' => [
                        // The palette the glossy renderer was designed around; it reaches the
                        // storefront as --web-primary / --web-secondary once the version is published.
                        'colors' => [
                            'primary' => '#7B3FE4', 'secondary' => '#26ABF2', 'accent' => '#BF23CD',
                            'background' => '#ffffff', 'surface' => '#F7F5FD', 'text' => '#14082E',
                            'muted_text' => '#8B84A3', 'border' => '#E4DFF2',
                        ],
                        'typography' => ['base_font_size' => 16, 'line_height' => 1.7],
                        'layout' => ['container_width' => 1380, 'page_spacing' => 32, 'border_radius' => 12, 'button_style' => 'rounded', 'card_style' => 'shadow'],
                    ],
                    'sections' => $this->minimalLuxurySections(),
                ],
            ],
        ];
    }

    /**
     * The example storefront, as a downloadable export file.
     *
     * Same payload the one-click preset imports — offered as a file so a merchant can read the
     * format, edit it, and import it back, which is the answer to "what shape does a theme file
     * have and where do I get one".
     */
    public function exampleTheme(): array
    {
        return $this->presets()['minimal_luxury']['payload'];
    }

    /**
     * A complete beauty home page: hero with three slides, highlights, categories, an editorial
     * split, product rows, a mosaic, the dashboard's own Main Banner, brands and a newsletter.
     *
     * Images are left blank on purpose — the storefront falls back to its placeholder, so the
     * layout is visible immediately and the merchant only has to drop their own photos in.
     */
    private function minimalLuxurySections(): array
    {
        return [
            ['page' => 'home', 'type' => 'hero_banner', 'sort_order' => 1, 'is_visible' => true,
             'settings' => ['height' => 620, 'interval' => 6000, 'autoplay' => true, 'ken_burns' => true,
                            'width' => 'full', 'padding_top' => 0, 'padding_bottom' => 0],
             'blocks' => [
                 ['type' => 'slide', 'is_visible' => true, 'settings' => [
                     'eyebrow' => 'New Season', 'title' => 'Radiance, refined.',
                     'subtitle' => 'Clean formulas and considered rituals for skin that looks like itself.',
                     'button_text' => 'Shop the edit', 'align' => 'start', 'overlay' => 38]],
                 ['type' => 'slide', 'is_visible' => true, 'settings' => [
                     'eyebrow' => 'Bestsellers', 'title' => 'The glow everyone asks about',
                     'subtitle' => 'Our most-loved serums, back in stock.',
                     'button_text' => 'Discover', 'align' => 'center', 'overlay' => 42]],
                 ['type' => 'slide', 'is_visible' => true, 'settings' => [
                     'eyebrow' => 'Gifting', 'title' => 'Wrapped, ready, unforgettable',
                     'subtitle' => 'Limited sets curated for the season.',
                     'button_text' => 'Explore gifts', 'align' => 'end', 'overlay' => 40]],
             ]],
            ['page' => 'home', 'type' => 'usp_strip', 'sort_order' => 2, 'is_visible' => true,
             'settings' => ['columns' => 4, 'style' => 'boxed', 'padding_top' => 36, 'padding_bottom' => 36],
             'blocks' => [
                 ['type' => 'usp', 'is_visible' => true, 'settings' => ['icon' => 'shipping', 'title' => 'Free delivery', 'subtitle' => 'On orders over the free-shipping threshold']],
                 ['type' => 'usp', 'is_visible' => true, 'settings' => ['icon' => 'authentic', 'title' => '100% authentic', 'subtitle' => 'Sourced from authorised suppliers']],
                 ['type' => 'usp', 'is_visible' => true, 'settings' => ['icon' => 'returns', 'title' => 'Easy returns', 'subtitle' => 'Change your mind within the return window']],
                 ['type' => 'usp', 'is_visible' => true, 'settings' => ['icon' => 'support', 'title' => 'Beauty advice', 'subtitle' => 'Talk to us before you buy']],
             ]],
            ['page' => 'home', 'type' => 'category_grid', 'sort_order' => 3, 'is_visible' => true,
             'settings' => ['eyebrow' => 'Explore', 'title' => 'Shop by Category', 'limit' => 6, 'columns' => 6,
                            'padding_top' => 64, 'padding_bottom' => 40]],
            ['page' => 'home', 'type' => 'product_slider', 'sort_order' => 4, 'is_visible' => true,
             'settings' => ['eyebrow' => 'Just In', 'title' => 'New Arrivals', 'source' => 'new_arrival',
                            'limit' => 8, 'columns' => 4, 'padding_top' => 40, 'padding_bottom' => 48]],
            // A live countdown to the running flash deal (Promotion -> Flash deals). Renders nothing
            // when no deal is running, so the preset is safe on a store without one.
            ['page' => 'home', 'type' => 'flash_deal', 'sort_order' => 5, 'is_visible' => true,
             'settings' => ['countdown' => true, 'width' => 'container', 'padding_top' => 8, 'padding_bottom' => 40]],
            ['page' => 'home', 'type' => 'split_banner', 'sort_order' => 6, 'is_visible' => true,
             'settings' => ['height' => 480, 'gap' => 0, 'padding_top' => 8, 'padding_bottom' => 48],
             'blocks' => [
                 ['type' => 'split', 'is_visible' => true, 'settings' => [
                     'media_side' => 'start', 'eyebrow' => 'The Ritual',
                     'title' => 'Three steps to a quiet glow',
                     'body' => 'Cleanse, treat, protect. A short routine built from formulas that respect the skin barrier.',
                     'button_text' => 'Build your routine']],
             ]],
            ['page' => 'home', 'type' => 'banner_mosaic', 'sort_order' => 7, 'is_visible' => true,
             'settings' => ['height' => 240, 'gap' => 16, 'padding_top' => 8, 'padding_bottom' => 48],
             'blocks' => [
                 ['type' => 'mosaic_tile', 'is_visible' => true, 'settings' => ['span' => 'large', 'eyebrow' => 'Skincare', 'title' => 'Serums & Essences', 'button_text' => 'Shop']],
                 ['type' => 'mosaic_tile', 'is_visible' => true, 'settings' => ['span' => 'small', 'title' => 'Lips']],
                 ['type' => 'mosaic_tile', 'is_visible' => true, 'settings' => ['span' => 'small', 'title' => 'Fragrance']],
                 ['type' => 'mosaic_tile', 'is_visible' => true, 'settings' => ['span' => 'wide', 'title' => 'Hair care', 'button_text' => 'Shop']],
             ]],
            ['page' => 'home', 'type' => 'product_slider', 'sort_order' => 8, 'is_visible' => true,
             'settings' => ['eyebrow' => 'Loved by many', 'title' => 'Bestsellers', 'source' => 'best_selling',
                            'limit' => 8, 'columns' => 4, 'padding_top' => 40, 'padding_bottom' => 48,
                            'background' => '#f8f4ef']],
            // Proof that the dashboard's Banners screen feeds the theme: whatever is published as a
            // "Main Banner" appears here as a full-width strip, no theme edit needed.
            ['page' => 'home', 'type' => 'store_banner', 'sort_order' => 9, 'is_visible' => true,
             'settings' => ['banner_type' => 'Main Banner', 'layout' => 'strip', 'limit' => 1, 'height' => 360,
                            'width' => 'full', 'padding_top' => 0, 'padding_bottom' => 0]],
            ['page' => 'home', 'type' => 'brand_slider', 'sort_order' => 10, 'is_visible' => true,
             'settings' => ['eyebrow' => 'Houses we carry', 'title' => 'Our Brands', 'limit' => 12,
                            'style' => 'marquee', 'padding_top' => 56, 'padding_bottom' => 56]],
            ['page' => 'home', 'type' => 'testimonials', 'sort_order' => 11, 'is_visible' => true,
             'settings' => ['eyebrow' => 'Customer voices', 'title' => 'What shoppers say', 'limit' => 3,
                            'min_rating' => 4, 'alignment' => 'center', 'padding_top' => 56, 'padding_bottom' => 56]],
            ['page' => 'home', 'type' => 'faq', 'sort_order' => 12, 'is_visible' => true,
             'settings' => ['eyebrow' => 'Help center', 'title' => 'Frequently asked questions',
                            'subtitle' => 'Delivery, returns and authenticity - answered.',
                            'button_text' => 'Contact us', 'padding_top' => 8, 'padding_bottom' => 64],
             'blocks' => [
                 ['type' => 'qa', 'is_visible' => true, 'settings' => ['question' => 'How long does delivery take?', 'answer' => 'Orders are prepared within one working day and delivered by the courier you choose at checkout.']],
                 ['type' => 'qa', 'is_visible' => true, 'settings' => ['question' => 'Are the products original?', 'answer' => 'Every item is sourced from authorised suppliers and stored under the conditions the manufacturer requires.']],
                 ['type' => 'qa', 'is_visible' => true, 'settings' => ['question' => 'Can I return an item?', 'answer' => 'Unopened items can be returned within the return window shown on the product page.']],
             ]],
            ['page' => 'home', 'type' => 'newsletter', 'sort_order' => 13, 'is_visible' => true,
             'settings' => ['title' => 'Join the List', 'subtitle' => 'Be first to know about new arrivals and exclusive offers.',
                            'width' => 'full', 'padding_top' => 0, 'padding_bottom' => 0]],
        ];
    }

    /** Keep only known global-settings groups/keys, coerced to the default's type. */
    private function sanitizeGlobalSettings(mixed $settings): array
    {
        if (!is_array($settings)) {
            return [];
        }

        $schema = $this->manager->defaultSettings();
        $clean = [];

        foreach ($schema as $group => $fields) {
            if (!is_array($fields) || !isset($settings[$group]) || !is_array($settings[$group])) {
                continue;
            }
            foreach ($fields as $key => $default) {
                if (!array_key_exists($key, $settings[$group])) {
                    continue;
                }
                $value = $settings[$group][$key];
                if (is_int($default)) {
                    $clean[$group][$key] = is_numeric($value) ? (int) $value : $default;
                } elseif (is_float($default)) {
                    $clean[$group][$key] = is_numeric($value) ? (float) $value : $default;
                } else {
                    $clean[$group][$key] = is_scalar($value) ? trim((string) $value) : $default;
                }
            }
        }

        return $clean;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'theme';
        $slug = $base;
        $i = 2;
        while (Theme::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
