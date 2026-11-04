<?php

namespace App\Services\Theme;

use App\Models\Banner;
use App\Models\Theme;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The bridge between the Theme Builder's banner-shaped blocks and Promotion -> Banners.
 *
 * One source of truth: the banners table. A block that carries a `banner_id` renders that row's
 * image/link/text live, so editing the banner in Banner Setup changes the storefront without
 * touching the theme. And a block whose image was uploaded straight in the builder is registered
 * as a "Theme Banner" row automatically, so it shows up in Banner Setup for later editing —
 * closing the "I added a banner in the theme but Banner Setup doesn't know about it" gap.
 */
class ThemeBannerLink
{
    /** The banner_type auto-registered rows get. No built-in storefront slot renders this type. */
    public const THEME_BANNER_TYPE = 'Theme Banner';

    /** Picker choices for a `banner` schema field: every banner of the storefront theme. */
    public function choices(): array
    {
        try {
            return Banner::query()
                ->where('theme', theme_root_path())
                ->orderByDesc('id')
                ->take(100)
                ->get(['id', 'banner_type', 'title', 'published'])
                ->map(fn (Banner $banner) => [
                    'value' => $banner->id,
                    'label' => '#' . $banner->id . ' — ' . translate(str_replace('_', ' ', $banner->banner_type))
                        . ($banner->title ? ' — ' . Str::limit($banner->title, 30) : '')
                        . ($banner->published ? '' : ' (' . translate('unpublished') . ')'),
                ])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Add live picker choices (and the Banner Setup url) to every `banner` field of a schema. */
    public function hydrateSchema(array $schema): array
    {
        $choices = null;
        foreach ($schema as $key => $field) {
            if (($field['type'] ?? null) === 'banner') {
                $choices ??= $this->choices();
                $schema[$key]['choices'] = $choices;
                $schema[$key]['manage_url'] = route('admin.banner.list');
            }
        }
        return $schema;
    }

    /**
     * Register a builder-uploaded banner image in Promotion -> Banners.
     *
     * Runs after a banner-backed block is saved: when it has an image but no linked banner, and the
     * image lives on our own public disk, the file is copied into the banners directory and a
     * "Theme Banner" row is created, then linked back into the block. From that moment Banner Setup
     * lists it and edits there render on the storefront. Never throws — a sync problem must not
     * break the builder's autosave.
     */
    public function syncBlock(ThemeBlock $block, ?array $previousSettings = null): void
    {
        try {
            if (!in_array($block->type, SectionRegistry::BANNER_BACKED_BLOCK_TYPES, true)) {
                return;
            }

            $settings = $block->settings ?? [];

            if (!empty($settings['banner_id'])) {
                $this->pushBlockEdits($settings, $previousSettings);
                return;
            }
            if (empty($settings['image'])) {
                return;
            }

            $sourcePath = $this->publicDiskPathFromUrl((string) $settings['image']);
            if ($sourcePath === null || !Storage::disk('public')->exists($sourcePath)) {
                return;
            }

            $extension = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'webp';
            $fileName = now()->format('Y-m-d-His') . '-' . Str::random(8) . '.' . $extension;
            if (!Storage::disk('public')->copy($sourcePath, 'banner/' . $fileName)) {
                return;
            }

            $banner = Banner::create([
                'photo'         => $fileName,
                'banner_type'   => self::THEME_BANNER_TYPE,
                'theme'         => theme_root_path(),
                'published'     => 1,
                'url'           => $settings['link'] ?? null,
                'resource_type' => 'custom',
                'title'         => $settings['title'] ?? null,
                'sub_title'     => $settings['subtitle'] ?? ($settings['body'] ?? null),
                'button_text'   => $settings['button_text'] ?? null,
            ]);

            $settings['banner_id'] = $banner->id;
            $block->settings = $settings;
            $block->save();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Carry a builder edit into the linked Banner Setup row — the other half of "edit in either place".
     *
     * The banner wins at render (cardOverrides), so before this, editing a LINKED tile in the
     * builder changed nothing on the storefront and said nothing about why. Now the edit lands in
     * the row both screens read. Two rules keep that safe:
     *
     *  - Only rows the builder itself minted (banner_type = Theme Banner). A dashboard banner the
     *    merchant deliberately linked into a tile keeps Banner Setup as its source of truth — the
     *    theme must not be able to rewrite the Main Banner.
     *
     *  - Only fields that CHANGED IN THIS SAVE. The builder form holds the block's own copy, which
     *    goes stale the moment somebody edits the banner in Banner Setup; pushing every field on
     *    every save would silently undo that edit with the stale copy. Diffing against the
     *    previous settings makes the contract "last editor wins, per field" — which is what
     *    editing in either place has to mean.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>|null  $previous  the block's settings before this save; null
     *                                               means the caller cannot say what changed, so
     *                                               nothing is pushed
     */
    private function pushBlockEdits(array $settings, ?array $previous): void
    {
        if ($previous === null) {
            return;
        }

        $banner = Banner::find((int) $settings['banner_id']);
        if (!$banner || $banner->banner_type !== self::THEME_BANNER_TYPE) {
            return;
        }

        $map = ['link' => 'url', 'title' => 'title', 'subtitle' => 'sub_title', 'button_text' => 'button_text'];
        foreach ($map as $settingKey => $column) {
            $value = $settings[$settingKey] ?? null;
            if ($value !== ($previous[$settingKey] ?? null)) {
                $banner->{$column} = $value !== '' ? $value : null;
            }
        }

        // A replaced image travels too, through the same copy the mint path uses, so Banner Setup
        // never shows yesterday's picture for today's tile.
        $image = $settings['image'] ?? null;
        if (is_string($image) && $image !== '' && $image !== ($previous['image'] ?? null)) {
            $sourcePath = $this->publicDiskPathFromUrl($image);
            if ($sourcePath !== null && Storage::disk('public')->exists($sourcePath)) {
                $extension = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'webp';
                $fileName = now()->format('Y-m-d-His') . '-' . Str::random(8) . '.' . $extension;
                if (Storage::disk('public')->copy($sourcePath, 'banner/' . $fileName)) {
                    $banner->photo = $fileName;
                }
            }
        }

        if ($banner->isDirty()) {
            $banner->save();
        }
    }

    /**
     * Live card data for linked banners, keyed by banner id.
     * `published` travels along so the renderer can hide a card whose banner was unpublished.
     *
     * @return array<int, array>
     */
    public function cardOverrides(array $bannerIds): array
    {
        $bannerIds = array_values(array_unique(array_filter(array_map('intval', $bannerIds))));
        if ($bannerIds === []) {
            return [];
        }

        try {
            return Banner::with('storage')
                ->whereIn('id', $bannerIds)
                ->get()
                ->keyBy('id')
                ->map(fn (Banner $banner) => [
                    'banner_id'    => (int) $banner->id,
                    'published'    => (int) $banner->published === 1,
                    'image'        => getStorageImages(path: $banner->photo_full_url, type: 'banner'),
                    'image_mobile' => $banner->mobile_photo
                        ? getStorageImages(path: $banner->mobile_photo_full_url, type: 'banner')
                        : null,
                    'link'         => $banner->url,
                    'title'        => $banner->title,
                    'subtitle'     => $banner->sub_title,
                    'button_text'  => $banner->button_text,
                    'background'   => $banner->background_color,
                    // What the banner POINTS AT, structurally — the Banner Setup form's own
                    // resource picker. This is what lets an API tile say "I open product 41"
                    // instead of handing the app a URL to reverse-engineer.
                    'resource_type' => $banner->resource_type,
                    'resource_id'   => $banner->resource_id ? (int) $banner->resource_id : null,
                ])->all();
        } catch (\Throwable $exception) {
            report($exception);
            return [];
        }
    }

    /**
     * Where each banner appears in the active theme, for the Banner Setup list's badges:
     * - by id: blocks that link the banner directly (`banner_id`)
     * - by type: store_banner sections that render a whole banner type
     *
     * Each entry names the section, the page, the version state — and for a directly linked
     * block, its size/role in that section ("Banner Mosaic — wide tile — home, draft"), so a
     * merchant can tell WHICH picture in the list is which tile of the theme.
     *
     * @return array{ids: array<int, array<int, string>>, types: array<string, array<int, string>>}
     */
    public function usage(): array
    {
        $usage = ['ids' => [], 'types' => []];

        try {
            $registry = app(SectionRegistry::class);

            foreach ($this->activeThemeSections() as $section) {
                $where = translate($registry->types()[$section->type]['label'] ?? $section->type)
                    . ' — ' . translate($section->page)
                    . ' (' . ($section->version->status === 'published' ? translate('published') : translate('draft')) . ')';

                if ($section->type === 'store_banner') {
                    $type = (string) ($section->settings['banner_type'] ?? '');
                    if ($type !== '') {
                        $usage['types'][$type][] = $where;
                    }
                }

                foreach ($section->blocks as $block) {
                    $bannerId = (int) ($block->settings['banner_id'] ?? 0);
                    if ($bannerId > 0) {
                        $usage['ids'][$bannerId][] = $where . $this->blockRoleSuffix($block);
                    }
                }
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $usage;
    }

    /**
     * The active theme's banner-carrying sections, arranged EXACTLY as the storefront lays them
     * out — what the "theme banners as displayed" screen renders (per the merchant's ask: show
     * which image is the mosaic, which tile is small, which is square — not a flat table).
     *
     * @return array<int, array{page:string,status:string,section_id:int,type:string,label:string,
     *                          layout:?string,columns:int,cards:array}>
     */
    public function themeLayout(): array
    {
        try {
            $registry = app(SectionRegistry::class);
            $resolver = app(SectionDataResolver::class);
            $groups = [];

            foreach ($this->activeThemeSections(bannerOnly: true) as $section) {
                $settings = $section->settings ?? [];
                $cards = [];

                if ($section->type === 'store_banner') {
                    $bannerType = (string) ($settings['banner_type'] ?? '');
                    foreach ($bannerType === '' ? [] : $resolver->dashboardBanners($bannerType, (int) ($settings['limit'] ?? 6)) as $card) {
                        $cards[] = $card + ['frames' => array_filter([$card['image'] ?? null]), 'banner_id' => null, 'block_id' => null, 'linked' => false];
                    }
                } elseif ($section->type === 'banner_strip') {
                    $cards[] = [
                        'image' => $settings['image'] ?? null, 'title' => $settings['title'] ?? null,
                        'frames' => array_filter([$settings['image'] ?? null]),
                        'span' => 'wide', 'banner_id' => null, 'block_id' => null, 'linked' => false,
                    ];
                } else {
                    $blocks = $section->blocks->where('is_visible', true)->values();
                    $overrides = $this->cardOverrides(
                        $blocks->map(fn ($block) => (int) ($block->settings['banner_id'] ?? 0))->all()
                    );

                    foreach ($blocks as $block) {
                        $blockSettings = $block->settings ?? [];
                        $bannerId = (int) ($blockSettings['banner_id'] ?? 0);
                        $linked = $overrides[$bannerId] ?? null;

                        // Every frame of the tile, lead first — a tile carrying three pictures
                        // looked identical here to one carrying a single picture, which is exactly
                        // the "I can't see there is more than one image" the merchant reported.
                        $frames = array_values(array_filter([
                            $linked['image'] ?? ($blockSettings['image'] ?? null),
                            $blockSettings['image_2'] ?? null,
                            $blockSettings['image_3'] ?? null,
                        ], fn ($frame) => is_string($frame) && $frame !== ''));

                        $cards[] = [
                            'image'     => $frames[0] ?? null,
                            'frames'    => $frames,
                            'title'     => ($linked['title'] ?? null) ?: ($blockSettings['title'] ?? null),
                            'span'      => $blockSettings['span'] ?? null,
                            'banner_id' => $bannerId > 0 ? $bannerId : null,
                            'block_id'  => $block->id,
                            'linked'    => $linked !== null,
                            'published' => $linked['published'] ?? true,
                        ];
                    }
                }

                if ($cards === []) {
                    continue;
                }

                $groups[] = [
                    'page'       => $section->page,
                    'status'     => $section->version->status,
                    'section_id' => $section->id,
                    'type'       => $section->type,
                    'label'      => translate($registry->types()[$section->type]['label'] ?? $section->type),
                    'layout'     => $settings['layout'] ?? null,
                    'display'    => $settings['display'] ?? null,
                    'columns'    => max(1, (int) ($settings['columns'] ?? 2)),
                    'cards'      => $cards,
                ];
            }

            return $groups;
        } catch (\Throwable $exception) {
            report($exception);
            return [];
        }
    }

    /** Banner-carrying sections of the active theme, published version first, then drafts. */
    private function activeThemeSections(bool $bannerOnly = false)
    {
        $theme = Theme::query()->where('is_active', true)->first();
        if (!$theme) {
            return collect();
        }

        $bannerSections = ['hero_banner', 'promotional_banner', 'banner_mosaic', 'split_banner', 'banner_strip', 'store_banner'];

        return ThemeSection::with(['blocks', 'version'])
            ->whereHas('version', fn ($query) => $query->where('theme_id', $theme->id)
                ->whereIn('status', ['published', 'draft']))
            ->where('is_visible', true)
            ->get()
            // usage() needs every block-carrying section (a linked banner can sit anywhere);
            // the LAYOUT panel wants only the banner family — footer columns with ten images
            // "in builder" are exactly the noise a screen called organized must not show.
            ->filter(fn (ThemeSection $section) => in_array($section->type, $bannerSections, true)
                || (!$bannerOnly && $section->blocks->isNotEmpty()))
            ->sortBy(fn (ThemeSection $section) => [
                $section->version->status === 'published' ? 0 : 1,
                $section->page,
                $section->sort_order,
            ])
            ->values();
    }

    /** " — wide tile" style suffix describing a block's role inside its section. */
    private function blockRoleSuffix(ThemeBlock $block): string
    {
        $span = $block->settings['span'] ?? null;
        if ($block->type === 'mosaic_tile' && $span) {
            return ' — ' . translate($span . '_tile');
        }
        if ($block->type === 'slide') {
            return ' — ' . translate('slide') . ' ' . $block->sort_order;
        }
        return '';
    }

    /**
     * Register every not-yet-linked banner-backed block of a version.
     *
     * The per-save hook only sees blocks edited AFTER the smart link shipped; this sweep catches
     * everything else — mosaics and slides composed earlier register the moment the builder opens
     * or the version is published, so Banner Setup can never be behind the theme.
     */
    public function syncVersion(ThemeVersion $version): void
    {
        try {
            $sections = ThemeSection::with('blocks')
                ->where('theme_version_id', $version->id)
                ->get();

            foreach ($sections as $section) {
                foreach ($section->blocks as $block) {
                    $this->syncBlock($block);
                }
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Resolve a builder image URL to its path on the public disk, or null for foreign URLs.
     *
     * Matching is host-insensitive (an APP_URL/https/www mismatch must not silently break the
     * sync) and accepts both public-disk URL shapes this app produces: the storage symlink
     * (`/storage/<path>`) and the direct form (`/storage/app/public/<path>`).
     */
    private function publicDiskPathFromUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $urlPath = parse_url($url, PHP_URL_PATH) ?: $url;

        $prefixes = ['/storage/app/public/'];
        $basePath = parse_url(rtrim(Storage::disk('public')->url(''), '/') . '/', PHP_URL_PATH);
        if ($basePath) {
            $prefixes[] = $basePath;
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($urlPath, $prefix)) {
                return rawurldecode(substr($urlPath, strlen($prefix)));
            }
        }

        return null;
    }
}
