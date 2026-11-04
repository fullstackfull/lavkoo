<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\BaseController;
use App\Models\Theme;
use App\Models\ThemeAsset;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use App\Services\Theme\Channel;
use App\Services\Theme\ExperiencePageService;
use App\Services\Theme\LinkComposer;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeAssetService;
use App\Services\Theme\ThemePermissionService;
use App\Services\Theme\ThemePreviewToken;
use App\Services\Theme\ThemeBuilderService;
use App\Services\Theme\ThemeManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Visual Theme Builder (Phase 1.2).
 *
 * The editor UI is a thin client over ThemeBuilderService: it renders the structure panel from
 * getPageStructure(), the settings panel from the SectionRegistry schema, and posts mutations back
 * as JSON. Everything is scoped to a DRAFT version — the service refuses published ones, so the
 * live storefront cannot be edited by accident.
 */
class ThemeBuilderController extends BaseController
{
    public function __construct(
        private readonly ThemeBuilderService $builder,
        private readonly SectionRegistry     $registry,
        private readonly ThemeManager        $themeManager,
        private readonly ThemeAssetService   $assets,
        private readonly ExperiencePageService $pages,
    )
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $versionId = $request?->get('version');
        $version = $versionId
            ? ThemeVersion::find($versionId)
            : $this->resolveEditableDraft();

        // Which channel this builder session is composing for. The App Builder opens on the
        // customer app; the theme editor keeps opening on the web, and both drive the same engine.
        $channel = Channel::normalize($request?->get('channel')) ?? Channel::WEB;

        // The pages this theme actually has, rather than a list written into three files. Falls
        // back to the guaranteed system pages while the pages table is still being migrated.
        $pages = $version?->theme
            ? $this->pages->forChannel($version->theme->id, $channel)
            : $this->pages->forChannel(0, $channel);

        $slugs = array_column($pages, 'slug');
        $page = $request?->get('page', 'home') ?: 'home';
        $page = in_array($page, $slugs, true) ? $page : ($slugs[0] ?? 'home');

        // Activate live preview for the version being edited so the builder's storefront iframe renders
        // this exact draft (its sections + global settings). Session-scoped and admin-only, exactly like
        // the explicit Preview button — nothing leaks to customers.
        if ($version && $this->builder->isEditable($version)) {
            session([StorefrontThemeRenderer::PREVIEW_SESSION_KEY => $version->id]);

            // A theme that predates the page table — or arrived through an import — gains its
            // pages the first time somebody opens the builder, rather than needing a command.
            if ($version->theme) {
                $this->pages->ensureSystemPages($version->theme);
            }

            // Register any banner-backed blocks composed before the smart link existed, so Banner
            // Setup catches up the moment the builder opens.
            app(\App\Services\Theme\ThemeBannerLink::class)->syncVersion($version);
        }

        return view('admin-views.theme.builder', [
            'bannerGaps'    => $version ? $this->bannerGaps($version, $page) : [],
            'version'       => $version,
            'theme'         => $version?->theme,
            'page'          => $page,
            'previewUrl'    => $this->builderPreviewUrl($page),
            'structure'     => $version ? $this->builder->getPageStructure($version, $page) : [],
            'sectionTypes'  => $version ? $this->registry->forPage($page) : [],
            // The same types, grouped into the families the picker browses by.
            'sectionCatalogue' => $version ? $this->registry->catalogue($page) : [],
            'blockLabels'   => array_map(fn ($block) => $block['label'], $this->registry->blockTypes()),
            'goLive'        => $version ? $this->goLiveState($version) : null,
            // What the customer app will and will not show of this version — surfaced while the
            // merchant can still act on it, not discovered on a shopper's phone (spec §54–55).
            'compatibility' => $version ? app(\App\Services\Theme\ThemeCompatibilityReport::class)->for($version) : null,
            // And what would stop it going live at all: a section added and never finished shows
            // here, beside the panel that fixes it, instead of on the storefront.
            'publishCheck'  => $version ? app(\App\Services\Theme\PublishValidator::class)->inspect($version) : null,
            // How many shoppers actually reached each section over the last month. The builder
            // answers what was arranged; this is the only thing that answers whether it worked.
            'reach'         => $version ? app(\App\Services\Theme\SectionReach::class)->visitors() : [],
            'themeSettings' => $this->themeManager->resolveSettings($version),
            'pages'         => $pages,
            'channel'       => $channel,
            // The languages a text field can carry an override for — every live language except
            // the default, whose text is the base value itself.
            'localeOverrides' => \App\Services\Theme\LocalisedSettings::overridable(),
            // Live customer segments, offered beside guest/customer in the Visibility tab.
            'segmentAudiences' => \Illuminate\Support\Facades\Schema::hasTable('customer_segments')
                ? \App\Models\CustomerSegment::query()->where('status', true)->orderBy('name')
                    ->pluck('name', 'key')->all()
                : [],
            'editable'      => $version ? $this->builder->isEditable($version) : false,
            'uploadAccept'  => '.' . implode(',.', ThemeAssetService::acceptedExtensions()),
        ]);
    }

    /**
     * The storefront URL the builder iframe previews. Header/footer sections are global chrome, so the
     * home page is the meaningful canvas for all three builder pages. Returns null if the storefront
     * home route is unavailable, so the blade can fall back gracefully.
     */
    private function builderPreviewUrl(string $page): ?string
    {
        try {
            return RouteFacade::has('home') ? route('home') : url('/');
        } catch (\Throwable) {
            return null;
        }
    }

    public function addSection(Request $request): JsonResponse
    {
        $version = ThemeVersion::find($request['version_id']);
        if (!$version) {
            return $this->fail(translate('theme_version_not_found'));
        }

        $section = $this->builder->addSection($version, (string) $request['page'], (string) $request['type']);

        return $section
            ? $this->ok(['section' => $this->builder->getPageStructure($version, (string) $request['page'])])
            : $this->fail(translate('this_section_could_not_be_added_the_version_may_be_published'));
    }

    public function updateSection(Request $request): JsonResponse
    {
        $section = ThemeSection::find($request['section_id']);
        if (!$section) {
            return $this->fail(translate('section_not_found'));
        }

        $settings = $request->get('settings', []);
        $saved = $this->builder->updateSection($section, is_array($settings) ? $settings : []);

        return $saved
            ? $this->ok(['settings' => $section->fresh()->settings])
            : $this->fail(translate('published_versions_cannot_be_edited_duplicate_it_to_a_draft_first'));
    }

    public function reorderSections(Request $request): JsonResponse
    {
        $version = ThemeVersion::find($request['version_id']);
        if (!$version) {
            return $this->fail(translate('theme_version_not_found'));
        }

        $ids = $request->get('order', []);
        $done = $this->builder->reorderSections($version, (string) $request['page'], is_array($ids) ? $ids : []);

        return $done ? $this->ok() : $this->fail(translate('published_versions_cannot_be_edited_duplicate_it_to_a_draft_first'));
    }

    public function toggleSection(Request $request): JsonResponse
    {
        $section = ThemeSection::find($request['section_id']);
        if (!$section) {
            return $this->fail(translate('section_not_found'));
        }

        $done = $this->builder->setSectionVisibility($section, $request->boolean('visible'));

        return $done ? $this->ok(['is_visible' => $section->fresh()->is_visible]) : $this->fail(translate('published_versions_cannot_be_edited_duplicate_it_to_a_draft_first'));
    }

    public function duplicateSection(Request $request): JsonResponse
    {
        $section = ThemeSection::find($request['section_id']);
        if (!$section) {
            return $this->fail(translate('section_not_found'));
        }

        $copy = $this->builder->duplicateSection($section);

        return $copy ? $this->ok(['id' => $copy->id]) : $this->fail(translate('published_versions_cannot_be_edited_duplicate_it_to_a_draft_first'));
    }

    public function deleteSection(Request $request): JsonResponse
    {
        $section = ThemeSection::find($request['section_id']);
        if (!$section) {
            return $this->fail(translate('section_not_found'));
        }

        $done = $this->builder->deleteSection($section);

        return $done ? $this->ok() : $this->fail(translate('published_versions_cannot_be_edited_duplicate_it_to_a_draft_first'));
    }

    /**
     * Settings schema for a section type, together with the SAVED settings of the section being
     * edited — the right-hand panel renders its form from both.
     *
     * The saved settings are not optional extra data. Without them the form fell back to schema
     * defaults for every field, so opening a configured section showed defaults, and because the
     * autosave posts the whole form, editing one field overwrote every other setting on that
     * section with its default. That is silent data loss on a merchant's theme.
     */
    public function sectionSchema(Request $request): JsonResponse
    {
        $type = (string) $request['type'];

        if (!$this->registry->has($type)) {
            return $this->fail(translate('unknown_section_type'));
        }

        $settings = [];
        $blocks = [];
        if ($request->filled('section_id')) {
            $section = ThemeSection::find($request['section_id']);
            // Normalising here means the form receives coerced values and drops stale keys, exactly
            // as the storefront sees them — so what is edited is what renders.
            if ($section && $section->type === $type) {
                $settings = $this->registry->normalizeSettings($type, $section->settings ?? []);
                $blocks = $this->builder->getBlocks($section);
            }
        }

        return $this->ok([
            'schema'       => $this->localizeSchema(
                app(\App\Services\Theme\ThemeBannerLink::class)->hydrateSchema($this->registry->schemaFor($type))
            ),
            'settings'     => $settings,
            // The builder splits the form into Content / Design tabs; the registry decides which
            // fields belong where, so a new section type needs no UI change.
            'contentKeys'  => array_keys($this->registry->ownSchemaFor($type)),
            // The same keys, split into the drawers the inspector opens: what it shows, how it is
            // arranged, where it leads, and what the merchant types.
            'fieldGroups'  => $this->registry->fieldGroupsFor($type),
            'styleKeys'    => array_keys($this->registry->commonSchema()),
            'accepts'      => $this->registry->blockTypesFor($type),
            'blockLabels'  => $this->blockLabelMap($type),
            'blocks'       => $blocks,
            'dataNote'     => $this->dataNote($type, $settings),
            'delivery'     => isset($section) && $section
                ? $this->builder->deliverySummary($section)
                : null,
            'links'        => $this->linkContext($this->registry->schemaFor($type), $settings),
        ]);
    }

    /**
     * Save a section's delivery rules — schedule window, platforms, audience.
     *
     * Separate from updateSection on purpose: settings live in the normalized JSON blob the
     * storefront renders from, while these are indexed columns the delivery pipeline filters on.
     * One endpoint per storage shape keeps each side's validation honest.
     */
    public function updateDeliveryRules(Request $request): JsonResponse
    {
        $section = ThemeSection::find($request['section_id']);
        if (!$section) {
            return $this->fail(translate('section_not_found'));
        }

        $saved = $this->builder->setDeliveryRules($section, [
            'starts_at' => $request->input('starts_at'),
            'ends_at'   => $request->input('ends_at'),
            'platforms' => $request->input('platforms'),
            'audience'  => $request->input('audience'),
        ]);

        return $saved
            ? $this->ok(['delivery' => $this->builder->deliverySummary($section->fresh())])
            : $this->fail(translate('published_versions_cannot_be_edited_duplicate_it_to_a_draft_first'));
    }

    /**
     * Warning for a section that draws its content from the catalogue and would currently render
     * nothing — a flash-deal countdown with no running deal, a review wall with no reviews.
     *
     * These sections deliberately output nothing rather than an empty frame, which looks to a
     * merchant like a broken option ("the timer is not working"). Naming the missing data, and
     * where to create it, is the difference between a bug report and a two-minute fix.
     */
    private function dataNote(string $type, array $settings): ?string
    {
        $resolver = app(\App\Services\Theme\SectionDataResolver::class);

        return match ($type) {
            'vendor_showcase' => (int) ($settings['shop_id'] ?? 0) > 0
                ? null
                : translate('no_vendor_chosen_yet_pick_one_so_this_section_can_render'),
            'vendor_slider' => $resolver->vendors(1)->isNotEmpty()
                ? null
                : translate('no_active_shops_yet_so_this_section_stays_hidden'),
            'category_showcase' => (int) ($settings['category_id'] ?? 0) > 0
                ? null
                : translate('no_category_chosen_yet_pick_one_so_this_section_can_render'),
            'product_slider' => match (true) {
                ($settings['source'] ?? '') === 'manual' && empty($settings['product_ids'])
                    => translate('pick_the_products_this_section_should_show'),
                in_array($settings['source'] ?? '', ['category', 'brand'], true) && empty($settings['source_id'])
                    => translate('pick_the_category_or_brand_this_section_should_show'),
                default => null,
            },
            'flash_deal' => $resolver->flashDeal((int) ($settings['deal_id'] ?? 0) ?: null)
                ? null
                : ((int) ($settings['deal_id'] ?? 0) > 0
                    ? translate('the_deal_you_picked_has_ended_so_this_section_stays_hidden_pick_another_one')
                    : translate('no_flash_deal_is_running_right_now_so_this_section_stays_hidden_create_one_under_promotion_flash_deals')),
            'testimonials' => $resolver->testimonials((int) ($settings['limit'] ?? 3), (int) ($settings['min_rating'] ?? 4))->isNotEmpty()
                ? null
                : translate('no_approved_reviews_match_this_rating_yet_so_this_section_stays_hidden'),
            'store_banner' => count($resolver->dashboardBanners((string) ($settings['banner_type'] ?? 'Main Banner'), (int) ($settings['limit'] ?? 6)))
                ? null
                : translate('no_published_banners_of_this_type_yet_add_one_under_promotion_banners'),
            default => null,
        };
    }

    /**
     * One picker parameter as a single string, or empty when it is not one.
     *
     * Every value here comes off a URL the inspector builds and anybody can hand-edit, so any of
     * them can arrive as an array — `?resource[]=x`. Casting one to a string is a PHP warning this
     * application's handler turns into a throw, which 500s the picker instead of simply answering
     * with no options. A term nobody can spell is not searched, and an unreadable resource name
     * falls through to the match's default arm.
     */
    private function queryString(Request $request, string $key): string
    {
        $value = $request->query($key, '');

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Catalogue records a `resource` field can pick from — categories, brands, products, flash
     * deals — as {value,label} pairs.
     *
     * Read-only and admin-scoped like the rest of the builder. The list is capped and filtered by
     * an optional search term so a store with fifty thousand products still answers instantly.
     */
    public function resources(Request $request): JsonResponse
    {
        $term = $this->queryString($request, 'q');
        $like = '%' . $term . '%';
        $limit = 40;

        $rows = match ($this->queryString($request, 'resource')) {
            'category' => \App\Models\Category::query()
                ->when($term !== '', fn ($query) => $query->where('name', 'like', $like))
                ->orderBy('position')->orderBy('priority')
                ->take($limit)->get(['id', 'name', 'position'])
                ->map(fn ($category) => [
                    'value' => $category->id,
                    // The level tells a merchant why two categories share a name.
                    'label' => $category->name . ($category->position ? ' · ' . translate('sub_category') : ''),
                ]),
            'brand' => \App\Models\Brand::query()
                ->when($term !== '', fn ($query) => $query->where('name', 'like', $like))
                ->where('status', 1)->orderBy('name')
                ->take($limit)->get(['id', 'name'])
                ->map(fn ($brand) => ['value' => $brand->id, 'label' => $brand->name]),
            'product' => \App\Models\Product::active()
                ->when($term !== '', fn ($query) => $query->where('name', 'like', $like))
                ->latest('id')
                ->take($limit)->get(['id', 'name'])
                ->map(fn ($product) => ['value' => $product->id, 'label' => $product->name]),
            'shop' => \App\Models\Shop::active()
                ->when($term !== '', fn ($query) => $query->where('name', 'like', $like))
                ->withCount(['products' => fn ($query) => $query->active()])
                ->orderBy('name')
                ->take($limit)->get()
                ->map(fn ($shop) => [
                    'value' => $shop->id,
                    'label' => $shop->name . ' · ' . $shop->products_count . ' ' . translate('products'),
                ]),
            'flash_deal' => \App\Models\FlashDeal::where('deal_type', 'flash_deal')
                ->when($term !== '', fn ($query) => $query->where('title', 'like', $like))
                ->orderByDesc('id')
                ->take($limit)->get(['id', 'title', 'status', 'end_date'])
                ->map(fn ($deal) => [
                    'value' => $deal->id,
                    'label' => $deal->title . ' · ' . ($deal->status ? translate('active') : translate('inactive')),
                ]),
            // Dynamic collections a section can be sourced from (Phase 3.1). Live ones only:
            // offering a disabled collection would compose a section that renders nothing.
            'product_collection' => !app(\App\Services\Commerce\CollectionResolver::class)->ready()
                ? collect()
                : \App\Models\ProductCollection::query()->live()
                    ->when($term !== '', fn ($query) => $query->where('name', 'like', $like))
                    ->orderBy('name')
                    ->take($limit)->get(['id', 'name', 'sort_by'])
                    ->map(fn ($row) => [
                        'value' => $row->id,
                        'label' => $row->name . ' · ' . translate($row->sort_by),
                    ]),

            // Pages the merchant composed. Valued by slug rather than id: the slug is what both
            // clients ask for, and what survives an export and an import. Scoped to the channel
            // being composed, so an app-only page — the kind the App Builder creates — is offered
            // where the app will read it and hidden where the web would 404 on it.
            'experience_page' => collect($this->pages->forChannel(
                (int) (\App\Models\Theme::query()->where('is_active', true)->value('id') ?? 0),
                Channel::normalize($request->get('channel')) ?? Channel::WEB,
            ))
                ->filter(fn (array $page) => $page['enabled']
                    // Only pages a shopper can be sent to. Home has its own address, and the
                    // header and footer are fragments of other pages, not destinations.
                    && $page['kind'] === \App\Models\ExperiencePage::KIND_CUSTOM
                    && ($term === '' || str_contains(strtolower($page['title'] . ' ' . $page['slug']), strtolower($term))))
                ->map(fn (array $page) => ['value' => $page['slug'], 'label' => $page['title'] . ' · /p/' . $page['slug']])
                ->values(),

            default => collect(),
        };

        return $this->ok(['options' => $rows->values()->all()]);
    }

    /**
     * Translate the human-facing parts of a schema before it reaches the inspector.
     *
     * The registry stores translation KEYS so it stays a pure catalogue; the form is what a
     * merchant reads, and an Arabic dashboard should not show English field names.
     */
    private function localizeSchema(array $schema): array
    {
        foreach ($schema as $key => $field) {
            $schema[$key]['label'] = translate($field['label'] ?? $key);
            if (!empty($field['hint'])) {
                $schema[$key]['hint'] = translate($field['hint']);
            }
        }

        return $schema;
    }

    /** Labels for already-picked ids, so the inspector can show names instead of numbers. */
    public function resourceLabels(Request $request): JsonResponse
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', $this->queryString($request, 'ids'))), fn ($id) => $id > 0));
        if ($ids === []) {
            return $this->ok(['options' => []]);
        }

        $rows = match ($this->queryString($request, 'resource')) {
            'category'   => \App\Models\Category::whereIn('id', $ids)->get(['id', 'name'])
                ->map(fn ($row) => ['value' => $row->id, 'label' => $row->name]),
            'brand'      => \App\Models\Brand::whereIn('id', $ids)->get(['id', 'name'])
                ->map(fn ($row) => ['value' => $row->id, 'label' => $row->name]),
            'product'    => \App\Models\Product::whereIn('id', $ids)->get(['id', 'name'])
                ->map(fn ($row) => ['value' => $row->id, 'label' => $row->name]),
            'shop'       => \App\Models\Shop::whereIn('id', $ids)->get(['id', 'name'])
                ->map(fn ($row) => ['value' => $row->id, 'label' => $row->name]),
            'flash_deal' => \App\Models\FlashDeal::whereIn('id', $ids)->get(['id', 'title'])
                ->map(fn ($row) => ['value' => $row->id, 'label' => $row->title]),
            default      => collect(),
        };

        // Keep the merchant's own order, which is what a hand-picked list means.
        $ordered = $rows->sortBy(fn ($row) => array_search((int) $row['value'], $ids, true))->values();

        return $this->ok(['options' => $ordered->all()]);
    }

    /** Schema + saved settings for one block, so the inspector can render its form. */
    public function blockSchema(Request $request): JsonResponse
    {
        $block = ThemeBlock::find($request['block_id']);
        if (!$block) {
            return $this->fail(translate('block_not_found'));
        }

        return $this->ok([
            'schema'   => $this->localizeSchema(
                app(\App\Services\Theme\ThemeBannerLink::class)->hydrateSchema($this->registry->blockSchemaFor($block->type))
            ),
            'settings' => $this->registry->normalizeBlockSettings($block->type, $block->settings ?? []),
            'type'     => $block->type,
            'label'    => $this->registry->blockLabel($block->type, $block->settings ?? []),
            'links'    => $this->linkContext(
                $this->registry->blockSchemaFor($block->type),
                $this->registry->normalizeBlockSettings($block->type, $block->settings ?? []),
            ),
        ]);
    }

    public function addBlock(Request $request): JsonResponse
    {
        $section = ThemeSection::find($request['section_id']);
        if (!$section) {
            return $this->fail(translate('section_not_found'));
        }

        $type = (string) ($request['type'] ?: $this->registry->defaultBlockType($section->type));
        $block = $this->builder->addBlock($section, $type);

        return $block
            ? $this->ok(['id' => $block->id, 'blocks' => $this->builder->getBlocks($section->fresh())])
            : $this->fail(translate('this_block_could_not_be_added_check_the_type_and_that_the_version_is_a_draft'));
    }

    public function updateBlock(Request $request): JsonResponse
    {
        $block = ThemeBlock::find($request['block_id']);
        if (!$block) {
            return $this->fail(translate('block_not_found'));
        }

        $settings = $request->get('settings', []);
        $saved = $this->builder->updateBlock($block, is_array($settings) ? $settings : []);

        return $saved
            ? $this->ok([
                'settings' => $block->fresh()->settings,
                'blocks'   => $this->builder->getBlocks($block->section),
            ])
            : $this->fail(translate('published_versions_cannot_be_edited_duplicate_it_to_a_draft_first'));
    }

    public function toggleBlock(Request $request): JsonResponse
    {
        $block = ThemeBlock::find($request['block_id']);
        if (!$block) {
            return $this->fail(translate('block_not_found'));
        }

        $done = $this->builder->setBlockVisibility($block, $request->boolean('visible'));

        return $done
            ? $this->ok(['blocks' => $this->builder->getBlocks($block->section)])
            : $this->fail(translate('published_versions_cannot_be_edited_duplicate_it_to_a_draft_first'));
    }

    public function reorderBlocks(Request $request): JsonResponse
    {
        $section = ThemeSection::find($request['section_id']);
        if (!$section) {
            return $this->fail(translate('section_not_found'));
        }

        $ids = $request->get('order', []);
        $done = $this->builder->reorderBlocks($section, is_array($ids) ? $ids : []);

        return $done
            ? $this->ok(['blocks' => $this->builder->getBlocks($section)])
            : $this->fail(translate('published_versions_cannot_be_edited_duplicate_it_to_a_draft_first'));
    }

    public function duplicateBlock(Request $request): JsonResponse
    {
        $block = ThemeBlock::find($request['block_id']);
        if (!$block) {
            return $this->fail(translate('block_not_found'));
        }

        $copy = $this->builder->duplicateBlock($block);

        return $copy
            ? $this->ok(['id' => $copy->id, 'blocks' => $this->builder->getBlocks($block->section)])
            : $this->fail(translate('this_block_could_not_be_duplicated'));
    }

    public function deleteBlock(Request $request): JsonResponse
    {
        $block = ThemeBlock::find($request['block_id']);
        if (!$block) {
            return $this->fail(translate('block_not_found'));
        }

        $section = $block->section;
        $done = $this->builder->deleteBlock($block);

        return $done
            ? $this->ok(['blocks' => $section ? $this->builder->getBlocks($section) : []])
            : $this->fail(translate('published_versions_cannot_be_edited_duplicate_it_to_a_draft_first'));
    }

    /**
     * Upload an image straight from the builder's image field.
     *
     * Reuses ThemeAssetService, which sniffs the real MIME with finfo, generates the filename and
     * fixes the directory — the builder adds no new upload surface of its own.
     */
    public function uploadMedia(Request $request): JsonResponse
    {
        $theme = $this->resolveThemeFor($request);
        if (!$theme) {
            return $this->fail(translate('theme_not_found'));
        }

        $file = $request->file('image');
        if (!$file) {
            return $this->fail(translate('no_file_was_uploaded'));
        }

        $result = $this->assets->upload($theme, $file, $request->get('label'), auth('admin')->id());

        return $result['asset']
            ? $this->ok(['url' => $result['asset']->url, 'id' => $result['asset']->id])
            : $this->fail(translate($result['error'] ?? 'the_upload_failed'));
    }

    /** Images already uploaded for this theme — the builder's "choose from library" picker. */
    public function mediaLibrary(Request $request): JsonResponse
    {
        $theme = $this->resolveThemeFor($request);
        if (!$theme) {
            return $this->ok(['items' => []]);
        }

        $items = ThemeAsset::where('theme_id', $theme->id)
            ->latest('id')->take(60)->get()
            ->map(fn (ThemeAsset $asset) => [
                'id' => $asset->id, 'url' => $asset->url, 'label' => $asset->label, 'size' => $asset->size_for_humans,
            ])
            ->filter(fn (array $item) => !empty($item['url']))
            ->values()->all();

        return $this->ok(['items' => $items]);
    }

    /**
     * Delete an image from the theme's library (builder-side, JSON).
     *
     * The management screen already had a delete, but it redirects — the builder needs an answer
     * it can act on without leaving the editor. Deleting only removes the LIBRARY entry and its
     * file; a section still pointing at the URL keeps its own copy of the string, so a delete can
     * never blank a published page on its own.
     */
    public function deleteMedia(Request $request): JsonResponse
    {
        if (config('app.mode') == 'demo') {
            return $this->fail(translate('you_can_not_update_this_on_demo_mode'));
        }
        if (!app(ThemePermissionService::class)->canEdit()) {
            return $this->fail(translate('you_do_not_have_permission_to_edit_a_theme'));
        }

        $asset = ThemeAsset::find($request['id']);
        if (!$asset) {
            return $this->fail(translate('the_image_was_not_found'));
        }

        $this->assets->delete($asset);

        return $this->ok();
    }

    /**
     * Turn a chosen destination into the URL the field stores.
     *
     * Composed on the server rather than in the browser so there is one definition of what "the
     * category page" means — the same one {@see ActionResolver} reads back. A second copy in
     * JavaScript would drift the first time a route changed, and the symptom would be a link that
     * works on the web and opens the wrong screen on a phone.
     */
    public function composeLink(Request $request): JsonResponse
    {
        $url = app(LinkComposer::class)->compose(
            (string) ($request['kind'] ?? 'none'),
            $request['reference'] ?? null,
        );

        return $this->ok(['url' => $url ?? '']);
    }

    /**
     * Everything the destination control needs, for whichever fields on this form are links.
     *
     * The kinds and collections are constant and small enough to travel with the form rather than
     * cost a second request. `current` is the important half: a link already stored has to come
     * back as the choice that produced it — a control that reset to "nothing" every time a merchant
     * opened a section would quietly wipe working links on the next save.
     *
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function linkContext(array $schema, array $settings): array
    {
        $fields = array_keys(array_filter($schema, static fn (array $field) => ($field['type'] ?? null) === 'link'));

        if ($fields === []) {
            return [];
        }

        $composer = app(LinkComposer::class);

        return [
            'kinds' => [
                ['value' => 'none',       'label' => translate('no_link')],
                ['value' => 'product',    'label' => translate('product'),    'resource' => 'product'],
                ['value' => 'category',   'label' => translate('category'),   'resource' => 'category'],
                ['value' => 'brand',      'label' => translate('brand'),      'resource' => 'brand'],
                ['value' => 'vendor',     'label' => translate('vendor'),     'resource' => 'shop'],
                ['value' => 'campaign',   'label' => translate('flash_deal'), 'resource' => 'flash_deal'],
                ['value' => 'collection', 'label' => translate('a_list_page')],
                ['value' => 'page',       'label' => translate('a_page_you_composed'), 'resource' => 'experience_page'],
                ['value' => 'search',     'label' => translate('search_results')],
                ['value' => 'cart',       'label' => translate('cart')],
                ['value' => 'wishlist',   'label' => translate('wishlist')],
                ['value' => 'url',        'label' => translate('another_address')],
            ],
            'collections' => array_map(
                static fn (string $name) => ['value' => $name, 'label' => translate($name)],
                array_keys(LinkComposer::COLLECTIONS),
            ),
            'current' => array_combine(
                $fields,
                array_map(
                    static fn (string $field) => $composer->describe($settings[$field] ?? null),
                    $fields,
                ),
            ),
        ];
    }

    private function resolveThemeFor(Request $request): ?Theme
    {
        $version = ThemeVersion::find($request['version_id']);
        if ($version?->theme) {
            return $version->theme;
        }

        return Theme::where('is_active', true)->first();
    }

    /**
     * What still stands between this draft and the live storefront.
     *
     * Composing sections changes nothing for customers until the theme is ACTIVE and a version is
     * PUBLISHED — the single most common reason a merchant reports "the look did not change" or
     * "my colours were not applied". The builder turns this into a visible checklist with the
     * matching one-click action instead of leaving it to the documentation.
     *
     * @return array{active:bool, published:bool, live:bool, sections:int}
     */
    private function goLiveState(ThemeVersion $version): array
    {
        $publishedId = $version->theme?->versions()->where('status', ThemeVersion::STATUS_PUBLISHED)->value('id');

        return [
            'active'    => (bool) $version->theme?->is_active,
            'published' => $publishedId !== null,
            'live'      => (bool) $version->theme?->is_active && $publishedId !== null,
            'sections'  => ThemeSection::where('theme_version_id', $version->id)->count(),
        ];
    }

    /**
     * Published dashboard banners a COMPOSED home would silently drop.
     *
     * A themed home replaces the built-in page wholesale — including the Main Banner slider and
     * the other built-in banner slots. When the draft has home sections but none renders a banner
     * type that has published rows, the merchant's banners vanish without explanation ('they do
     * not show as a slider'). This names each dropped type so the builder can warn and offer the
     * one-click fix.
     *
     * @return array<int, array{type:string,label:string,count:int}>
     */
    private function bannerGaps(ThemeVersion $version, string $page): array
    {
        if ($page !== 'home') {
            return [];
        }

        try {
            $sections = \App\Models\ThemeSection::where('theme_version_id', $version->id)
                ->where('page', 'home')->where('is_visible', true)->get();

            if ($sections->isEmpty()) {
                return []; // built-in home renders — every slot still works
            }

            $covered = function (string $type) use ($sections): bool {
                foreach ($sections as $section) {
                    if ($section->type === 'store_banner' && ($section->settings['banner_type'] ?? null) === $type) {
                        return true;
                    }
                    // A hero section fills the top-slider role, so no nagging about Main Banner.
                    if ($type === 'Main Banner' && $section->type === 'hero_banner') {
                        return true;
                    }
                }
                return false;
            };

            $homeSlotTypes = [
                'Main Banner'             => translate('main_Banner'),
                'Main Section Banner'     => translate('main_Section_Banner'),
                'Home Promo Banner'       => translate('home_Promo_Banner'),
                'Category Section Banner' => translate('category_Section_Banner'),
            ];

            $counts = \App\Models\Banner::query()
                ->where('theme', theme_root_path())
                ->where('published', 1)
                ->whereIn('banner_type', array_keys($homeSlotTypes))
                ->selectRaw('banner_type, COUNT(*) AS total')
                ->groupBy('banner_type')
                ->pluck('total', 'banner_type');

            $gaps = [];
            foreach ($homeSlotTypes as $type => $label) {
                $count = (int) ($counts[$type] ?? 0);
                if ($count > 0 && !$covered($type)) {
                    $gaps[] = ['type' => $type, 'label' => $label, 'count' => $count];
                }
            }
            return $gaps;
        } catch (\Throwable $exception) {
            report($exception);
            return [];
        }
    }

    /** type => translated label, for every block type a section accepts. */
    private function blockLabelMap(string $sectionType): array
    {
        $labels = [];
        foreach ($this->registry->blockTypesFor($sectionType) as $blockType) {
            $labels[$blockType] = translate($this->registry->blockTypes()[$blockType]['label'] ?? $blockType);
        }

        return $labels;
    }

    /** The active theme's draft, creating one from the published version when needed. */
    private function resolveEditableDraft(): ?ThemeVersion
    {
        $published = $this->themeManager->activeThemePublishedVersion();
        if ($published) {
            $draft = ThemeVersion::where('theme_id', $published->theme_id)
                ->where('status', ThemeVersion::STATUS_DRAFT)
                ->latest('id')->first();

            return $draft ?: $this->themeManager->createDraftFrom($published);
        }

        return ThemeVersion::where('status', ThemeVersion::STATUS_DRAFT)->latest('id')->first();
    }

    private function ok(array $data = []): JsonResponse
    {
        return response()->json(['status' => 'success'] + $data);
    }

    private function fail(string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], 422);
    }
    /**
     * Preview a DRAFT on the real storefront (Phase 1.2 draft -> preview -> publish).
     *
     * The version id is stored in the ADMIN SESSION, not the URL: a ?preview_version=N link could be
     * shared or crawled, exposing an unpublished design to customers and to search engines. The
     * renderer additionally requires an authenticated admin, so the preview cannot leak even if the
     * session cookie were replayed by a guest.
     */
    public function startPreview(Request $request): RedirectResponse
    {
        $version = ThemeVersion::find($request['version_id']);
        if (!$version) {
            ToastMagic::error(translate('theme_version_not_found') . '!');
            return back();
        }

        session([StorefrontThemeRenderer::PREVIEW_SESSION_KEY => $version->id]);
        ToastMagic::success(translate('previewing_draft_on_the_storefront') . ' #' . $version->id);

        return redirect('/');
    }

    /**
     * A link that shows this draft on any device, without an admin session.
     *
     * The builder's phone frame is a browser drawing an approximation; whether the artwork crops
     * right, whether the Arabic wraps, whether the rail is reachable with a thumb are questions
     * only a real phone answers. The link carries a signed, expiring token rather than a version
     * id, so it can be scanned off the screen and cannot be edited into somebody else's draft.
     */
    public function previewLink(Request $request): JsonResponse
    {
        // The token confers exactly the access this screen already grants — reading an unpublished
        // version — so it is gated on the same permission rather than on merely being an admin.
        if (!app(ThemePermissionService::class)->canView()) {
            return $this->fail(translate('you_do_not_have_permission_to_view_a_theme'));
        }

        $version = ThemeVersion::find($request['version_id']);

        if (!$version) {
            return response()->json(['status' => 'error', 'message' => translate('theme_version_not_found')], 404);
        }

        $minutes = (int) ($request['minutes'] ?? ThemePreviewToken::DEFAULT_MINUTES);
        $tokens = app(ThemePreviewToken::class);

        $url = url('/') . '?' . http_build_query([
            StorefrontThemeRenderer::PREVIEW_TOKEN_KEY => $tokens->mint($version, $minutes),
        ]);

        return response()->json([
            'status'     => 'success',
            'url'        => $url,
            'expires_in' => $tokens->expiresIn($minutes),
            // Rendered server-side with the shop's own dependency-free encoder — the same one the
            // campaign posters use — so the builder needs no script to draw it.
            'qr'         => app(\App\Services\Analytics\Support\QrCode::class)->svg($url, 200),
        ]);
    }

    public function stopPreview(): RedirectResponse
    {
        session()->forget(StorefrontThemeRenderer::PREVIEW_SESSION_KEY);
        ToastMagic::success(translate('preview_ended'));

        return redirect()->route('admin.theme.builder.index');
    }

}
