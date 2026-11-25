<?php

namespace App\Services\Theme;

use App\Models\Banner;
use App\Models\Theme;
use App\Models\ThemeAsset;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Storefront\StorefrontVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copying one storefront's theme into another market (GCC F-11).
 *
 * A merchant who has composed the Saudi storefront should be able to start the Emirati one from it rather than from an empty draft. The
 * export/import pair already moves a theme's sections through a file, so this is that pair with three things added that a file cannot
 * carry -- and each of the three was a defect waiting in the plain import:
 *
 *  - **the copy is OWNED by the target market and is a DRAFT.** `ThemePortabilityService::import()` creates a theme with no
 *    `storefront_country`, and a theme with none is the DEFAULT storefront's ({@see StorefrontThemeResolver}). So importing an exported
 *    Saudi theme to start the Emirati storefront produced a second theme of Saudi Arabia.
 *  - **the sections get FRESH identities.** The export carries each section's `uuid` on purpose -- per-section app state and analytics
 *    history hang off it, and an export/import round trip of the SAME theme must not re-mint them. A copy into another market is the
 *    opposite case: two markets' sections sharing one uuid means one market's analytics and one market's dismissed-banner state answer
 *    for both. The payload's uuids are stripped, and `ThemeSection::booted` mints one per row.
 *  - **the banner rows travel, and the assets travel.** A `store_banner` block points at a row in `banners`, which is shared by every
 *    storefront but ASSIGNED per market ({@see ThemeBannerLink}): a copied block pointing at the source market's row renders nothing in
 *    the target market, because that market is not assigned it. Assets belong to a `theme_id`, so a copy with no assets of its own has
 *    no logo and no section backgrounds.
 *
 * And it reports what it could not bring: a product, category, brand or deal the source theme names BY ID that the target market does
 * not offer. A section pointing at a product the Emirates does not sell renders an empty rail, and the merchant would find out from a
 * shopper.
 *
 * **Q-19 is open on whether a market's banners and assets should be duplicated or shared**, so the banner half is a setting
 * (`gcc.storefronts.themes.copy.banners`, shipping `duplicate`) and the asset half is not, for a reason worth writing down rather than
 * pretending to offer: `theme_assets.theme_id` is a single column, so "the Emirati theme shares the Saudi theme's assets" cannot be
 * expressed without a schema change, and a shared FILE cannot be offered either, because
 * {@see ThemeAssetService::delete()} deletes the file with the row -- deleting the Emirati copy's logo would take the Saudi
 * storefront's logo with it. So an asset is copied, bytes and all, or it is reported as not copied and no row is written. A row
 * pointing at somebody else's file is the one outcome that must not happen.
 */
class ThemeCopyToMarket
{
    public const BANNERS_DUPLICATE = 'duplicate';

    public const BANNERS_SHARE = 'share';

    /** What the registry's resource kinds are checked against in the target market, and how. */
    private const CHECKED_BY_MAPPING = [
        'category' => StorefrontVisibility::CATEGORY,
        'brand' => StorefrontVisibility::BRAND,
        'flash_deal' => StorefrontVisibility::FLASH_DEAL,
    ];

    /** Resource kinds that have no market of their own on this platform yet, so a copy reports them rather than passing them silently. */
    private const NOT_CHECKABLE = ['shop', 'product_collection'];

    public function __construct(
        private readonly SectionRegistry $registry,
        private readonly ThemePortabilityService $portability,
        private readonly StorefrontThemeResolver $themes,
    ) {
    }

    /**
     * Copy a version into a market as that market's own draft.
     *
     * @return array{theme: ?Theme, version: ?ThemeVersion, report: array<string, mixed>, refused: ?string}
     */
    public function copy(ThemeVersion $source, string $market, ?string $name = null, ?int $adminId = null): array
    {
        $market = strtoupper(trim($market));
        $refused = $this->whyItMayNotCopy($source, $market);

        if ($refused !== null) {
            return ['theme' => null, 'version' => null, 'report' => [], 'refused' => $refused];
        }

        $payload = $this->portability->export($source);
        $payload['sections'] = array_map(static function (array $section): array {
            // The one difference from an export/import round trip, and the whole reason this is not just "download and upload".
            unset($section['uuid']);

            return $section;
        }, $payload['sections'] ?? []);

        $imported = $this->portability->import(
            $payload,
            $name ?: $this->nameFor($source, $market),
            $adminId,
        );

        if ($imported['theme'] === null || $imported['version'] === null) {
            return ['theme' => null, 'version' => null, 'report' => $imported['result'], 'refused' => 'the_theme_could_not_be_copied'];
        }

        $theme = $imported['theme'];
        $version = $imported['version'];

        $theme->storefront_country = $market;
        $theme->save();

        $report = [
            'market' => $market,
            'sections' => ThemeSection::query()->where('theme_version_id', $version->id)->count(),
            'banners' => $this->moveTheBanners(version: $version, market: $market, sourceMarket: $this->themes->storefrontOf(theme: $source->theme)),
            'assets' => $this->copyTheAssets(source: $source->theme, copy: $theme),
            'catalogue_missing' => $this->catalogueTheTargetDoesNotOffer(version: $version, market: $market),
            'catalogue_not_checked' => $this->catalogueNothingCanCheck(version: $version),
        ];

        return ['theme' => $theme->refresh(), 'version' => $version->refresh(), 'report' => $report, 'refused' => null];
    }

    /** Why this copy cannot be made, or null when it can. */
    public function whyItMayNotCopy(ThemeVersion $source, string $market): ?string
    {
        if (!$this->themes->keepsThemesByStorefront()) {
            return 'themes_do_not_belong_to_storefronts_yet';
        }

        if (!in_array(strtoupper(trim($market)), StorefrontResolver::fromConfig()->configuredCountries(), true)) {
            return 'that_is_not_a_market_this_platform_serves';
        }

        if ($source->theme === null) {
            return 'that_version_belongs_to_no_theme';
        }

        if ($this->themes->storefrontOf(theme: $source->theme) === strtoupper(trim($market))) {
            return 'that_theme_already_belongs_to_that_market';
        }

        return null;
    }

    /** Which markets a version may be copied into: the configured ones, minus the one it already belongs to. */
    public function marketsFor(ThemeVersion $source): array
    {
        if (!$this->themes->keepsThemesByStorefront() || $source->theme === null) {
            return [];
        }

        $owner = $this->themes->storefrontOf(theme: $source->theme);

        return array_values(array_filter(
            StorefrontResolver::fromConfig()->configuredCountries(),
            static fn (string $country): bool => $country !== $owner,
        ));
    }

    /** How a copy's banners are handled, per the open Q-19 decision. */
    public function bannerMode(): string
    {
        $mode = (string) config('gcc.storefronts.themes.copy.banners', self::BANNERS_DUPLICATE);

        return $mode === self::BANNERS_SHARE ? self::BANNERS_SHARE : self::BANNERS_DUPLICATE;
    }

    /**
     * Give the copy banners its market will actually show.
     *
     * `duplicate` writes a row of the target market's own, so editing the Emirati banner in Banner Setup leaves the Saudi one alone --
     * which is what a merchant means by "start the Emirati storefront from the Saudi one". `share` assigns the ONE row to both markets
     * instead, so a single edit reaches both; it is the other answer to Q-19 and is one setting away.
     *
     * A block whose banner row has gone is left pointing at nothing and reported, because inventing a replacement would put a picture
     * nobody chose on a storefront.
     *
     * @return array{mode: string, duplicated: array<int, int>, shared: array<int, int>, missing: array<int, int>}
     */
    private function moveTheBanners(ThemeVersion $version, string $market, string $sourceMarket): array
    {
        $mode = $this->bannerMode();
        $report = ['mode' => $mode, 'duplicated' => [], 'shared' => [], 'missing' => []];

        if (!Schema::hasTable('banners')) {
            return $report;
        }

        foreach ($this->blocksOf($version) as $block) {
            $settings = $block->settings ?? [];
            $bannerId = (int) ($settings['banner_id'] ?? 0);

            if ($bannerId <= 0) {
                continue;
            }

            $banner = Banner::query()->find($bannerId);

            if ($banner === null) {
                $report['missing'][] = $bannerId;
                continue;
            }

            if ($mode === self::BANNERS_SHARE) {
                $this->assign($banner, array_values(array_unique(array_filter([
                    ...StorefrontVisibility::storefrontsOf(entityType: StorefrontVisibility::BANNER, entityId: $banner->id),
                    $sourceMarket,
                    $market,
                ]))));
                $report['shared'][] = $banner->id;
                continue;
            }

            $copy = $this->duplicateBanner($banner);

            if ($copy === null) {
                $report['missing'][] = $bannerId;
                continue;
            }

            $this->assign($copy, [$market]);
            $settings['banner_id'] = $copy->id;
            $block->settings = $settings;
            $block->saveQuietly();
            $report['duplicated'][] = $copy->id;
        }

        return $report;
    }

    /**
     * A banner row of the target market's own.
     *
     * The stored photo is referenced, not re-uploaded: `banners.photo` is a file name under the banner directory and nothing deletes it
     * when a row goes, so two rows naming one picture is safe in a way two `theme_assets` rows naming one file is not.
     *
     * **In a transaction, and that is a fix rather than a habit.** `Banner::boot()` hangs a `saved` hook on the model that writes the
     * disk of `photo` into `storages`, and on an installation where that table is absent the hook THROWS AFTER THE INSERT HAS HAPPENED.
     * Without the transaction the `catch` below would report the banner as not copied while a row for it existed in `banners` --
     * assigned to no market, pointing at nothing, and invisible to the operator who was told the copy was fine. Rolled back, "not
     * copied" is true. The hook itself is the root cause and is not this service's to repair.
     */
    private function duplicateBanner(Banner $banner): ?Banner
    {
        try {
            return DB::transaction(static function () use ($banner): Banner {
                $copy = $banner->replicate(['id', 'created_at', 'updated_at']);
                $copy->save();

                return $copy;
            });
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<string> $markets */
    private function assign(Banner $banner, array $markets): void
    {
        StorefrontVisibility::set(entityType: StorefrontVisibility::BANNER, entityId: $banner->id, countries: $markets);
    }

    /**
     * Give the copy its own assets, file and all.
     *
     * A row pointing at the source theme's file is the one outcome that must not happen: `ThemeAssetService::delete()` removes the file
     * with the row, so deleting the Emirati logo would take the Saudi storefront's logo with it. When the bytes cannot be copied the row
     * is NOT written and the asset is reported, because a theme with a missing logo an operator can see beats a theme that silently
     * shares one.
     *
     * @return array{duplicated: int, not_copied: array<int, string>}
     */
    private function copyTheAssets(?Theme $source, Theme $copy): array
    {
        $report = ['duplicated' => 0, 'not_copied' => []];

        if ($source === null || !Schema::hasTable('theme_assets')) {
            return $report;
        }

        foreach (ThemeAsset::query()->where('theme_id', $source->id)->get() as $asset) {
            $path = $this->copyTheFile($asset, $copy);

            if ($path === null) {
                $report['not_copied'][] = (string) ($asset->label ?: $asset->path);
                continue;
            }

            ThemeAsset::create([
                'theme_id' => $copy->id,
                'label' => $asset->label,
                'path' => $path,
                'disk' => $asset->disk,
                'mime_type' => $asset->mime_type,
                'size_bytes' => $asset->size_bytes,
                'uploaded_by_type' => $asset->uploaded_by_type,
                'uploaded_by_id' => $asset->uploaded_by_id,
            ]);
            $report['duplicated']++;
        }

        return $report;
    }

    private function copyTheFile(ThemeAsset $asset, Theme $copy): ?string
    {
        try {
            $disk = Storage::disk((string) $asset->disk);

            if (!$disk->exists((string) $asset->path)) {
                return null;
            }

            $extension = pathinfo((string) $asset->path, PATHINFO_EXTENSION) ?: 'img';
            $path = 'theme-assets/' . $copy->id . '/theme-' . $copy->id . '-' . Str::random(24) . '.' . $extension;

            return $disk->copy((string) $asset->path, $path) ? $path : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The catalogue rows the copy names by id that the target market does not offer.
     *
     * Driven from the registry's own `resource` declarations rather than a hand-written list of setting keys, so a section type added
     * tomorrow is checked without anybody remembering to come back here.
     *
     * @return array<string, array<int, int>>
     */
    private function catalogueTheTargetDoesNotOffer(ThemeVersion $version, string $market): array
    {
        $missing = [];

        foreach ($this->referencedIds($version) as $kind => $ids) {
            foreach ($ids as $id) {
                if ($kind === 'product' ? $this->marketSellsTheProduct($id, $market) : $this->marketShows($kind, $id, $market)) {
                    continue;
                }

                $missing[$kind][] = $id;
            }
        }

        return array_map(static fn (array $ids): array => array_values(array_unique($ids)), $missing);
    }

    /**
     * The ids a copy carries that nothing on this platform can place in a market yet: a vendor shop and a dynamic collection have no
     * market dimension. Reported rather than passed, so "we checked and it is fine" is never said about a question nobody asked.
     *
     * @return array<string, array<int, int>>
     */
    private function catalogueNothingCanCheck(ThemeVersion $version): array
    {
        $referenced = $this->referencedIds($version);

        return array_filter(array_intersect_key($referenced, array_flip(self::NOT_CHECKABLE)));
    }

    private function marketSellsTheProduct(int $id, string $market): bool
    {
        if (!StorefrontCatalog::tracksProductCountry()) {
            return true;
        }

        try {
            // The query builder, not the model: `Product` carries the storefront scope and a panel read of another market's product
            // would come back empty for the wrong reason.
            return DB::table('products')->where('id', $id)->value(StorefrontCatalog::COLUMN) === $market;
        } catch (\Throwable) {
            return true;
        }
    }

    private function marketShows(string $kind, int $id, string $market): bool
    {
        $entityType = self::CHECKED_BY_MAPPING[$kind] ?? null;

        if ($entityType === null) {
            return true;
        }

        return StorefrontVisibility::isVisible(entityType: $entityType, entityId: $id, country: $market);
    }

    /**
     * Every catalogue id this version's sections and blocks name, by the registry's resource kind.
     *
     * @return array<string, array<int, int>>
     */
    private function referencedIds(ThemeVersion $version): array
    {
        $ids = [];
        $types = $this->registry->types();

        foreach (ThemeSection::query()->with('blocks')->where('theme_version_id', $version->id)->get() as $section) {
            $this->collect($ids, $types[$section->type]['schema'] ?? [], $section->settings ?? []);

            foreach ($section->blocks as $block) {
                $this->collect($ids, $this->registry->blockSchemaFor($block->type), $block->settings ?? []);
            }
        }

        return array_map(static fn (array $found): array => array_values(array_unique($found)), $ids);
    }

    /**
     * @param array<string, array<int, int>> $ids
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $settings
     */
    private function collect(array &$ids, array $schema, array $settings): void
    {
        foreach ($schema as $key => $field) {
            if (($field['type'] ?? null) !== 'resource' || !array_key_exists($key, $settings)) {
                continue;
            }

            // A field whose resource depends on another field names its kind there: `source_id` is a category or a brand, by `source`.
            $kind = is_string($field['resource'] ?? null)
                ? $field['resource']
                : (is_string($field['resource_from'] ?? null) ? (string) ($settings[$field['resource_from']] ?? '') : '');

            if ($kind === '') {
                continue;
            }

            foreach ($this->idsOf($settings[$key]) as $id) {
                $ids[$kind][] = $id;
            }
        }
    }

    /** @return array<int, int> */
    private function idsOf(mixed $picked): array
    {
        $values = is_array($picked) ? $picked : (is_string($picked) ? explode(',', $picked) : [$picked]);

        return array_values(array_filter(array_map(
            static fn ($value): int => is_numeric(is_string($value) ? trim($value) : $value) ? (int) $value : 0,
            $values,
        ), static fn (int $id): bool => $id > 0));
    }

    /** @return \Illuminate\Support\Collection<int, ThemeBlock> */
    private function blocksOf(ThemeVersion $version): \Illuminate\Support\Collection
    {
        return ThemeBlock::query()
            ->whereIn('theme_section_id', ThemeSection::query()->where('theme_version_id', $version->id)->select('id'))
            ->get();
    }

    private function nameFor(ThemeVersion $source, string $market): string
    {
        return trim(($source->theme?->name ?: 'Theme') . ' — ' . $market);
    }
}
