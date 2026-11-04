<?php

namespace App\Services\Theme;

/**
 * Catalogue of the section types the storefront can render, and the settings each one exposes
 * (Phase 1.2 — Theme Builder).
 *
 * The registry is the contract between the builder UI (which renders a settings form from the
 * schema) and the storefront (which renders the section). Adding a new section type is a matter of
 * adding an entry here plus its blade partial — no builder code changes — which is the
 * extensibility the brief asked for.
 */
class SectionRegistry
{
    /** Field types the builder UI knows how to render. */
    public const FIELD_TYPES = ['text', 'textarea', 'number', 'boolean', 'select', 'color', 'image', 'link', 'source', 'banner', 'resource'];

    /** How many records one hand-picked list may hold, so a section cannot be made to query the whole catalogue. */
    public const MAX_PICKED_RESOURCES = 24;

    /** Block types whose cards can be backed by a row in Promotion -> Banners (see ThemeBannerLink). */
    public const BANNER_BACKED_BLOCK_TYPES = ['slide', 'banner', 'mosaic_tile', 'split'];

    /** Hard cap on repeatable child blocks per section, so a runaway UI cannot bloat a page. */
    public const MAX_BLOCKS_PER_SECTION = 24;

    /**
     * Banner types the storefront can pull straight from the dashboard's Banners CRUD.
     *
     * Kept as a static list rather than calling BannerService: the registry is a pure schema
     * catalogue used by import/validation paths that must not depend on the active folder theme.
     * "Popup Banner" is deliberately absent — it is chrome, not a page section.
     */
    public const STORE_BANNER_TYPES = [
        'Main Banner', 'Main Section Banner', 'Footer Banner',
        'Header Banner', 'Sidebar Banner', 'Top Side Banner',
        // The grid types: placing one of these as a section lets the merchant put the
        // promo grid wherever they want in the page order, instead of its default slot.
        'Home Promo Banner', 'Category Section Banner', 'Category Banner',
        // Banners minted by (or for) the Theme Builder itself — no built-in slot renders these,
        // so a section here is the only place they appear.
        'Theme Banner',
    ];

    /**
     * Settings shared by every section, so spacing/width/background behave consistently.
     * Responsive-capable keys are marked so the UI can offer desktop/tablet/mobile values.
     */
    public function commonSchema(): array
    {
        return [
            'background'     => ['type' => 'color',   'label' => 'background',      'default' => null],
            'padding_top'    => ['type' => 'number',  'label' => 'padding_top',     'default' => 40, 'responsive' => true],
            'padding_bottom' => ['type' => 'number',  'label' => 'padding_bottom',  'default' => 40, 'responsive' => true],
            'width'          => ['type' => 'select',  'label' => 'width',           'default' => 'container',
                                 'options' => ['container', 'full']],
            'alignment'      => ['type' => 'select',  'label' => 'alignment',       'default' => 'start',
                                 'options' => ['start', 'center', 'end']],
            'heading_style'  => ['type' => 'select',  'label' => 'heading_style',   'default' => 'standard',
                                 'options' => ['standard', 'editorial', 'compact']],
            'visible'        => ['type' => 'boolean', 'label' => 'visible',         'default' => true, 'responsive' => true],
        ];
    }


    /**
     * The families a merchant browses the library by.
     *
     * Order matters: this is the order the picker shows, and it runs from what a page opens with
     * to what it closes with.
     */
    public const CATEGORIES = [
        'hero'       => 'hero_and_promotional',
        'products'   => 'products',
        'categories' => 'categories',
        'brands'     => 'brands',
        'vendors'    => 'vendors',
        'content'    => 'content',
        'discovery'  => 'discovery',
        'commerce'   => 'commerce',
        'layout'     => 'layout',
    ];

    /**
     * Which family each type belongs to.
     *
     * A map rather than a key on all 39 definitions: this is the one piece of metadata that is
     * about presentation in the BUILDER rather than about the section, and keeping it here means a
     * recategorisation is one edit instead of thirty-nine.
     */
    private const TYPE_CATEGORIES = [
        'hero_banner' => 'hero', 'promotional_banner' => 'hero', 'split_banner' => 'hero',
        'banner_mosaic' => 'hero', 'banner_strip' => 'hero', 'store_banner' => 'hero',
        'announcement_bar' => 'hero',

        'product_slider' => 'products', 'flash_deal' => 'products', 'deal_of_the_day' => 'products',
        'featured_deal' => 'products', 'clearance_sale' => 'products', 'bundle' => 'products',
        'product_tabs' => 'products', 'category_showcase' => 'products', 'brand_showcase' => 'products',
        'recently_viewed' => 'products',

        'category_grid' => 'categories', 'interest_tiles' => 'categories',

        'brand_slider' => 'brands',

        'vendor_slider' => 'vendors', 'vendor_showcase' => 'vendors',

        'usp_strip' => 'content', 'stats_bar' => 'content', 'testimonials' => 'content',
        'faq' => 'content', 'stories' => 'content', 'before_after' => 'content',
        'blog_posts' => 'content', 'branches' => 'content', 'custom_html' => 'content',
        'app_download' => 'content',

        'trending_searches' => 'discovery', 'price_tiles' => 'discovery',

        'coupon_strip' => 'commerce', 'shipping_cutoff' => 'commerce', 'newsletter' => 'commerce',

        'spacer' => 'layout', 'footer_columns' => 'layout',
    ];

    /**
     * The schema field that carries a type's display variant, where it has one.
     *
     * Most types call it `style`; the dashboard-banner section calls it `layout` because the word
     * already meant something else there. Naming it here lets every consumer ask one question —
     * "what are this type's variants" — without knowing which word a given section chose.
     */
    private const VARIANT_KEYS = ['style', 'layout'];

    /**
     * Legacy type names that resolve to another type plus a variant.
     *
     * Empty today, and the mechanism ships before its first use on purpose: collapsing the six
     * banner types into one type with six variants is a library change, and it must not be the
     * same change as the compatibility path that lets already-published sections keep their names.
     * A section stored as an alias keeps rendering, because every reader resolves through here.
     *
     * @var array<string, array{type: string, variant: string}>
     */
    private const TYPE_ALIASES = [];

    /**
     * A type name as the engine knows it, plus the variant the alias implies.
     *
     * @return array{type: string, variant: ?string}
     */
    public function resolveAlias(string $type): array
    {
        $alias = self::TYPE_ALIASES[$type] ?? null;

        return $alias === null
            ? ['type' => $type, 'variant' => null]
            : ['type' => $alias['type'], 'variant' => $alias['variant']];
    }

    /** Every alias, for the compatibility tests and the portability importer. */
    public function aliases(): array
    {
        return self::TYPE_ALIASES;
    }

    /**
     * The library, grouped the way it is browsed.
     *
     * @return array<string, array{label: string, types: array<string, array>}>
     */
    public function catalogue(?string $page = null): array
    {
        $types = $page === null ? $this->types() : $this->forPage($page);
        $grouped = [];

        foreach (self::CATEGORIES as $key => $label) {
            $inCategory = array_filter($types, fn (array $definition) => ($definition['category'] ?? null) === $key);

            if ($inCategory !== []) {
                $grouped[$key] = ['label' => $label, 'types' => $inCategory];
            }
        }

        return $grouped;
    }

    /**
     * The display variants a type offers, read from the select that actually drives it.
     *
     * Derived rather than declared: a variant list written by hand beside the schema is a list
     * that will one day disagree with the field the storefront branches on.
     *
     * @return array<int, string>
     */
    public function variantsFor(string $type): array
    {
        $schema = $this->definitions()[$type]['schema'] ?? [];

        foreach (self::VARIANT_KEYS as $key) {
            if (isset($schema[$key]['options']) && is_array($schema[$key]['options'])) {
                return array_values($schema[$key]['options']);
            }
        }

        return [];
    }

    /** The settings key a type stores its variant under, or null when it has one look only. */
    public function variantKeyFor(string $type): ?string
    {
        $schema = $this->definitions()[$type]['schema'] ?? [];

        foreach (self::VARIANT_KEYS as $key) {
            if (isset($schema[$key]['options'])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Section definitions, each carrying what a builder needs to place it: its family, its
     * variants, the surfaces that can draw it, and the contract version clients negotiate on.
     *
     * @return array<string, array>
     */
    public function types(): array
    {
        if ($this->decorated !== null) {
            return $this->decorated;
        }

        $capabilities = app(ComponentCapabilityRegistry::class);
        $types = [];

        foreach ($this->definitions() as $key => $definition) {
            $types[$key] = $definition + [
                'category'    => self::TYPE_CATEGORIES[$key] ?? 'content',
                'icon'        => $definition['preview'] ?? 'bar',
                'description' => $definition['hint'] ?? null,
                'variant_key' => $this->variantKeyFor($key),
                'variants'    => $this->variantsFor($key),
                'channels'    => $capabilities->channelsFor($key),
                // The contract generation a client negotiates on. One today for every type; a bump
                // is how a settings change that an older renderer would draw wrongly stops
                // reaching that renderer.
                'version'     => $capabilities->requirementFor($key)['version'] ?? 1,
            ];
        }

        return $this->decorated = $types;
    }

    /** Memoised: types() is asked for on every builder render and every delivery assembly. */
    private ?array $decorated = null;

    /**
     * Section type definitions: page it belongs to, label, and its own settings schema.
     *
     * The catalogue metadata a builder needs to ORGANISE these — which family a type belongs to,
     * which surfaces can draw it, which layout variants it offers — is not repeated in all 39
     * entries. It is derived in {@see types()} from the maps below and from each type's own schema,
     * so a variant list can never disagree with the select that produces it.
     *
     * @return array<string, array>
     */
    private function definitions(): array
    {
        return [
            'announcement_bar' => [
                'preview' => 'bar', 'label' => 'announcement_bar', 'pages' => ['header'], 'hint' => 'a_thin_message_bar_above_the_header',
                'schema' => [
                    'text'      => ['type' => 'text', 'label' => 'text', 'default' => ''],
                    'link'      => ['type' => 'link', 'label' => 'link', 'default' => null],
                    'dismissible' => ['type' => 'boolean', 'label' => 'dismissible', 'default' => true],
                ],
            ],
            'hero_banner' => [
                'preview' => 'hero', 'label' => 'hero_banner', 'pages' => ['home'], 'blocks' => ['slide'], 'hint' => 'full_width_slideshow_add_a_slide_per_campaign',
                'schema' => [
                    'autoplay'  => ['type' => 'boolean', 'label' => 'autoplay', 'default' => true],
                    'interval'  => ['type' => 'number',  'label' => 'interval_ms', 'default' => 5000],
                    'height'    => ['type' => 'number',  'label' => 'height', 'default' => 420, 'responsive' => true],
                    'ken_burns' => ['type' => 'boolean', 'label' => 'slow_zoom_animation', 'default' => true],
                ],
            ],
            'category_grid' => [
                'preview' => 'circles', 'label' => 'category_grid', 'pages' => ['home'], 'hint' => 'round_category_shortcuts_pulled_from_your_categories',
                'schema' => [
                    'eyebrow' => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'   => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    // Hand-pick the categories, in the order you pick them. Empty = the top-level
                    // categories by priority, which is what the section did before.
                    'category_ids' => ['type' => 'resource', 'label' => 'choose_categories', 'default' => null,
                                       'resource' => 'category', 'multiple' => true],
                    'style'   => ['type' => 'select', 'label' => 'display_style', 'default' => 'circles',
                                  'options' => ['circles', 'cards', 'tiles', 'chips']],
                    'limit'   => ['type' => 'number', 'label' => 'max_items', 'default' => 12],
                    'columns' => ['type' => 'number', 'label' => 'columns', 'default' => 6, 'responsive' => true],
                ],
            ],
            'product_slider' => [
                'preview' => 'rail', 'label' => 'product_slider', 'pages' => ['home'], 'hint' => 'a_row_of_products_choose_the_source_new_best_selling_category',
                'schema' => [
                    'eyebrow'     => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'       => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'subtitle'    => ['type' => 'text',   'label' => 'subtitle', 'default' => ''],
                    'source'      => ['type' => 'source', 'label' => 'product_source', 'default' => 'featured',
                                      'options' => ['featured', 'best_selling', 'new_arrival', 'top_rated', 'category', 'brand', 'manual', 'collection']],
                    // Which category / brand, picked by name. The picker follows the source above:
                    // choose "category" and it lists categories, "brand" and it lists brands.
                    'source_id'   => ['type' => 'resource', 'label' => 'choose_category_or_brand', 'default' => null,
                                      'resource_from' => 'source', 'depends_on' => ['source' => ['category', 'brand']]],
                    // Source "manual" means: exactly these products, in this order.
                    'product_ids' => ['type' => 'resource', 'label' => 'choose_products', 'default' => null,
                                      'resource' => 'product', 'multiple' => true,
                                      'depends_on' => ['source' => ['manual']]],
                    // Source "collection" means: whatever this dynamic collection currently ranks.
                    'collection_id' => ['type' => 'resource', 'label' => 'choose_a_collection', 'default' => null,
                                      'resource' => 'product_collection',
                                      'depends_on' => ['source' => ['collection']]],
                    'style'       => ['type' => 'select', 'label' => 'display_style', 'default' => 'rail',
                                      'options' => ['rail', 'grid', 'carousel', 'spotlight', 'list']],
                    'limit'       => ['type' => 'number', 'label' => 'max_products', 'default' => 10],
                    'columns'     => ['type' => 'number', 'label' => 'columns', 'default' => 5, 'responsive' => true],
                    'autoplay'    => ['type' => 'boolean','label' => 'autoplay', 'default' => false],
                    'interval'    => ['type' => 'number', 'label' => 'interval_ms', 'default' => 4000],
                    'arrows'      => ['type' => 'boolean','label' => 'navigation_arrows', 'default' => true],
                    'pagination'  => ['type' => 'boolean','label' => 'pagination_dots', 'default' => false],
                    'view_all'    => ['type' => 'boolean','label' => 'view_all_button', 'default' => true],
                    'add_to_cart' => ['type' => 'boolean','label' => 'add_to_cart_button_on_each_card', 'default' => true],
                ],
            ],
            'promotional_banner' => [
                'preview' => 'tiles', 'label' => 'promotional_banner', 'pages' => ['home'], 'blocks' => ['banner'], 'hint' => 'equal_banner_tiles_side_by_side',
                'schema' => [
                    'style'    => ['type' => 'select',  'label' => 'display_style', 'default' => 'tiles',
                                   'options' => ['tiles', 'rail', 'overlap', 'swipe']],
                    'columns'  => ['type' => 'number',  'label' => 'columns', 'default' => 2, 'responsive' => true],
                    'gap'      => ['type' => 'number',  'label' => 'gap', 'default' => 24],
                    'ratio'    => ['type' => 'select',  'label' => 'image_ratio', 'default' => 'wide',
                                   'options' => ['wide', 'square', 'portrait', 'auto']],
                    'overlay'  => ['type' => 'boolean', 'label' => 'show_text_overlay', 'default' => true],
                    'height'    => ['type' => 'number', 'label' => 'swipe_row_height', 'default' => 240,
                                    'depends_on' => ['style' => ['swipe']]],
                    'rotate_ms' => ['type' => 'number', 'label' => 'image_rotate_interval_ms', 'default' => 4000],
                ],
            ],
            // --- banner presentations beyond the plain rectangle -------------------------------
            'split_banner' => [
                'preview' => 'split', 'label' => 'split_banner', 'pages' => ['home'], 'blocks' => ['split'], 'hint' => 'image_on_one_side_text_on_the_other_editorial_look',
                'schema' => [
                    'height' => ['type' => 'number',  'label' => 'media_height', 'default' => 460, 'responsive' => true],
                    'gap'    => ['type' => 'number',  'label' => 'gap', 'default' => 0],
                ],
            ],
            'banner_mosaic' => [
                'preview' => 'mosaic', 'label' => 'banner_mosaic', 'pages' => ['home'], 'blocks' => ['mosaic_tile'], 'hint' => 'asymmetric_grid_one_large_tile_beside_smaller_ones',
                'schema' => [
                    'height' => ['type' => 'number', 'label' => 'row_height', 'default' => 240, 'responsive' => true],
                    'gap'    => ['type' => 'number', 'label' => 'gap', 'default' => 16],
                    // Locked = the composition never reflows: four columns on every screen, tiles
                    // scaling with the container like one picture, instead of collapsing to two
                    // columns on phones and stretching on wide monitors.
                    'layout_lock' => ['type' => 'boolean', 'label' => 'lock_layout_on_all_screen_sizes', 'default' => false],
                    // grid = the asymmetric wall above. swipe = ONE horizontally swipeable row —
                    // small squares side by side that scroll, in the merchant's words. Squares
                    // over a rectangle strip = a swipe section stacked above a grid/strip one.
                    'display'     => ['type' => 'select', 'label' => 'display_mode', 'default' => 'grid',
                                      'options' => ['grid', 'swipe']],
                    // How long each frame of a multi-image tile holds before crossfading.
                    'rotate_ms'   => ['type' => 'number', 'label' => 'image_rotate_interval_ms', 'default' => 4000],
                ],
            ],
            'banner_strip' => [
                'preview' => 'strip', 'label' => 'banner_strip', 'pages' => ['home'], 'hint' => 'full_width_campaign_strip_with_parallax_background',
                'schema' => [
                    'image'       => ['type' => 'image',   'label' => 'background_image', 'default' => ''],
                    'eyebrow'     => ['type' => 'text',    'label' => 'eyebrow', 'default' => ''],
                    'title'       => ['type' => 'text',    'label' => 'title', 'default' => ''],
                    'subtitle'    => ['type' => 'text',    'label' => 'subtitle', 'default' => ''],
                    'link'        => ['type' => 'link',    'label' => 'link', 'default' => ''],
                    'button_text' => ['type' => 'text',    'label' => 'button_text', 'default' => ''],
                    'height'      => ['type' => 'number',  'label' => 'height', 'default' => 320, 'responsive' => true],
                    'overlay'     => ['type' => 'number',  'label' => 'overlay_opacity', 'default' => 45],
                    'text_color'  => ['type' => 'color',   'label' => 'text_color', 'default' => '#ffffff'],
                    'parallax'    => ['type' => 'boolean', 'label' => 'parallax', 'default' => true],
                ],
            ],
            // The bridge to the dashboard's own Banners CRUD: whatever is published there for the
            // chosen banner type renders here, in the layout the merchant picks. Adding a banner in
            // Promotion -> Banners is therefore immediately visible on the themed home page.
            'store_banner' => [
                'preview' => 'hero', 'label' => 'banners_from_dashboard', 'pages' => ['home'], 'hint' => 'shows_banners_you_created_in_promotion_banners',
                'schema' => [
                    'banner_type' => ['type' => 'select', 'label' => 'banner_type', 'default' => 'Main Banner',
                                      'options' => self::STORE_BANNER_TYPES],
                    'layout'      => ['type' => 'select', 'label' => 'display_style', 'default' => 'carousel',
                                      'options' => ['carousel', 'grid', 'mosaic', 'strip', 'split', 'swipe']],
                    'title'       => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'subtitle'    => ['type' => 'text',   'label' => 'subtitle', 'default' => ''],
                    'limit'       => ['type' => 'number', 'label' => 'max_items', 'default' => 6],
                    'columns'     => ['type' => 'number', 'label' => 'columns', 'default' => 3, 'responsive' => true],
                    'height'      => ['type' => 'number', 'label' => 'height', 'default' => 420, 'responsive' => true],
                    'gap'         => ['type' => 'number', 'label' => 'gap', 'default' => 16],
                ],
            ],
            'usp_strip' => [
                'preview' => 'usp', 'label' => 'service_highlights', 'pages' => ['home'], 'blocks' => ['usp'], 'hint' => 'trust_badges_such_as_free_shipping_and_authentic_products',
                'schema' => [
                    'columns' => ['type' => 'number',  'label' => 'columns', 'default' => 4, 'responsive' => true],
                    'style'   => ['type' => 'select',  'label' => 'display_style', 'default' => 'boxed',
                                  'options' => ['boxed', 'plain', 'dark']],
                ],
            ],
            'brand_slider' => [
                'preview' => 'marquee', 'label' => 'brand_slider', 'pages' => ['home'], 'hint' => 'brands_as_marquee_grid_or_story_cards',
                'schema' => [
                    'eyebrow' => ['type' => 'text',    'label' => 'eyebrow', 'default' => ''],
                    'title'   => ['type' => 'text',    'label' => 'title', 'default' => ''],
                    'style'   => ['type' => 'select',  'label' => 'display_style', 'default' => 'marquee',
                                  'options' => ['marquee', 'grid', 'story']],
                    'limit'   => ['type' => 'number',  'label' => 'max_items', 'default' => 12],
                ],
            ],
            'flash_deal' => [
                'preview' => 'flash', 'label' => 'flash_deals', 'pages' => ['home'], 'hint' => 'gradient_strip_with_a_live_countdown_from_your_running_flash_deal',
                'schema' => [
                    'title'     => ['type' => 'text',    'label' => 'title', 'default' => ''],
                    'subtitle'  => ['type' => 'text',    'label' => 'subtitle', 'default' => ''],
                    // Which deal to feature. Empty = whichever deal is running now, so the section
                    // keeps working after a campaign ends without a theme edit.
                    // Only one flash deal can be ACTIVE at a time (the dashboard deactivates the
                    // rest), so picking a deal here is how a second section shows a different one.
                    'deal_id'   => ['type' => 'resource', 'label' => 'choose_flash_deal', 'default' => null,
                                    'resource' => 'flash_deal',
                                    'hint' => 'only_one_deal_can_be_active_at_a_time_pick_a_deal_here_to_show_a_different_one'],
                    'style'     => ['type' => 'select',  'label' => 'display_style', 'default' => 'strip',
                                    'options' => ['strip', 'banner', 'grid']],
                    'countdown' => ['type' => 'boolean', 'label' => 'show_countdown', 'default' => true],
                    'products'  => ['type' => 'boolean', 'label' => 'show_the_deals_products', 'default' => true],
                    'limit'     => ['type' => 'number',  'label' => 'max_products', 'default' => 10],
                    'add_to_cart' => ['type' => 'boolean','label' => 'add_to_cart_button_on_each_card', 'default' => true],
                ],
            ],
            // One chosen category, presented as its own storefront block: its page banner on top,
            // its sub-categories as entry chips, then its products — the "show me this category
            // with its banner" arrangement, composable anywhere on the home page.
            'category_showcase' => [
                'preview' => 'showcase', 'label' => 'category_showcase', 'pages' => ['home'],
                'hint' => 'one_category_with_its_banner_its_sub_categories_and_its_products',
                'schema' => [
                    'category_id'  => ['type' => 'resource', 'label' => 'choose_category', 'default' => null,
                                       'resource' => 'category'],
                    'eyebrow'      => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'        => ['type' => 'text',   'label' => 'title_leave_empty_for_the_category_name', 'default' => ''],
                    'banner'       => ['type' => 'boolean','label' => 'show_the_category_banner', 'default' => true],
                    'sub_categories' => ['type' => 'boolean', 'label' => 'show_sub_category_chips', 'default' => true],
                    'style'        => ['type' => 'select', 'label' => 'display_style', 'default' => 'rail',
                                       'options' => ['rail', 'grid']],
                    'limit'        => ['type' => 'number', 'label' => 'max_products', 'default' => 10],
                    'columns'      => ['type' => 'number', 'label' => 'columns', 'default' => 5, 'responsive' => true],
                    'view_all'     => ['type' => 'boolean','label' => 'view_all_button', 'default' => true],
                    'add_to_cart'  => ['type' => 'boolean','label' => 'add_to_cart_button_on_each_card', 'default' => true],
                ],
            ],
            // --- Offers & Deals: windows onto the Promotion screens the merchant already uses ---
            'deal_of_the_day' => [
                'preview' => 'split', 'label' => 'deal_of_the_day', 'pages' => ['home'],
                'hint' => 'the_single_product_you_set_as_todays_deal_with_a_countdown_to_midnight',
                'schema' => [
                    'eyebrow'     => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'       => ['type' => 'text',   'label' => 'title_leave_empty_for_the_deal_title', 'default' => ''],
                    'style'       => ['type' => 'select', 'label' => 'display_style', 'default' => 'split',
                                      'options' => ['split', 'banner', 'card']],
                    'countdown'   => ['type' => 'boolean','label' => 'show_countdown', 'default' => true],
                    'add_to_cart' => ['type' => 'boolean','label' => 'add_to_cart_button_on_each_card', 'default' => true],
                ],
            ],
            'featured_deal' => [
                'preview' => 'rail', 'label' => 'featured_deal', 'pages' => ['home'],
                'hint' => 'products_of_the_running_featured_deal_campaign',
                'schema' => [
                    'eyebrow'     => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'       => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'style'       => ['type' => 'select', 'label' => 'display_style', 'default' => 'rail',
                                      'options' => ['rail', 'grid']],
                    'limit'       => ['type' => 'number', 'label' => 'max_products', 'default' => 10],
                    'columns'     => ['type' => 'number', 'label' => 'columns', 'default' => 5, 'responsive' => true],
                    'add_to_cart' => ['type' => 'boolean','label' => 'add_to_cart_button_on_each_card', 'default' => true],
                ],
            ],
            'clearance_sale' => [
                'preview' => 'rail', 'label' => 'clearance_sale', 'pages' => ['home'],
                'hint' => 'products_you_put_on_clearance_from_promotion_clearance_sale',
                'schema' => [
                    'eyebrow'     => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'       => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'style'       => ['type' => 'select', 'label' => 'display_style', 'default' => 'rail',
                                      'options' => ['rail', 'grid']],
                    'limit'       => ['type' => 'number', 'label' => 'max_products', 'default' => 10],
                    'columns'     => ['type' => 'number', 'label' => 'columns', 'default' => 5, 'responsive' => true],
                    'add_to_cart' => ['type' => 'boolean','label' => 'add_to_cart_button_on_each_card', 'default' => true],
                ],
            ],
            'coupon_strip' => [
                'preview' => 'coupon', 'label' => 'coupons', 'pages' => ['home'],
                'hint' => 'your_live_coupons_as_cards_the_customer_can_copy_with_one_tap',
                'schema' => [
                    'eyebrow' => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'   => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'style'   => ['type' => 'select', 'label' => 'display_style', 'default' => 'tickets',
                                  'options' => ['tickets', 'cards', 'strip']],
                    'limit'   => ['type' => 'number', 'label' => 'max_items', 'default' => 4],
                    'columns' => ['type' => 'number', 'label' => 'columns', 'default' => 4, 'responsive' => true],
                ],
            ],

            // --- Storytelling, trust and conversion -------------------------------------------
            'stats_bar' => [
                'preview' => 'usp', 'label' => 'store_stats', 'pages' => ['home'],
                'hint' => 'counters_that_count_up_products_brands_customers_and_anything_you_add',
                'blocks' => ['stat'],
                'schema' => [
                    'eyebrow'    => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'      => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'columns'    => ['type' => 'number', 'label' => 'columns', 'default' => 4, 'responsive' => true],
                    'style'      => ['type' => 'select', 'label' => 'display_style', 'default' => 'boxed',
                                     'options' => ['boxed', 'dark']],
                    'animate'    => ['type' => 'boolean','label' => 'count_up_when_it_scrolls_into_view', 'default' => true],
                ],
            ],
            'bundle' => [
                'preview' => 'showcase', 'label' => 'bundle_buy_the_set', 'pages' => ['home'],
                'hint' => 'pick_the_products_of_a_set_show_what_it_costs_and_add_them_all_with_one_button',
                'schema' => [
                    'eyebrow'     => ['type' => 'text',    'label' => 'eyebrow', 'default' => ''],
                    'title'       => ['type' => 'text',    'label' => 'title', 'default' => ''],
                    'subtitle'    => ['type' => 'text',    'label' => 'subtitle', 'default' => ''],
                    'product_ids' => ['type' => 'resource','label' => 'choose_products', 'default' => null,
                                      'resource' => 'product', 'multiple' => true,
                                      'hint' => 'pick_at_least_two_products_they_are_added_to_the_cart_together'],
                    'discount'    => ['type' => 'number',  'label' => 'bundle_discount_percent', 'default' => 0],
                    'button_text' => ['type' => 'text',    'label' => 'button_text', 'default' => ''],
                ],
            ],
            'interest_tiles' => [
                'preview' => 'mosaic', 'label' => 'shop_by_interest', 'pages' => ['home'],
                'blocks' => ['interest'],
                'hint' => 'large_tiles_that_lead_to_a_ready_made_filtered_page_skin_type_concern_routine',
                'schema' => [
                    'eyebrow' => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'   => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'style'   => ['type' => 'select', 'label' => 'display_style', 'default' => 'tiles',
                                  'options' => ['tiles', 'circles', 'rail']],
                    'columns' => ['type' => 'number', 'label' => 'columns', 'default' => 3, 'responsive' => true],
                    'gap'     => ['type' => 'number', 'label' => 'gap', 'default' => 16],
                    'height'  => ['type' => 'number', 'label' => 'tile_height', 'default' => 260, 'responsive' => true],
                ],
            ],
            'stories' => [
                'preview' => 'stories', 'label' => 'stories', 'pages' => ['home'],
                'blocks' => ['story'],
                'hint' => 'vertical_story_cards_that_open_full_screen_and_link_to_a_product',
                'schema' => [
                    'eyebrow' => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'   => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'style'   => ['type' => 'select', 'label' => 'display_style', 'default' => 'bubbles',
                                  'options' => ['bubbles', 'cards']],
                ],
            ],
            'blog_posts' => [
                'preview' => 'tiles', 'label' => 'from_the_blog', 'pages' => ['home'],
                'hint' => 'the_newest_published_posts_from_your_blog',
                'schema' => [
                    'eyebrow'  => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'    => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'style'    => ['type' => 'select', 'label' => 'display_style', 'default' => 'cards',
                                   'options' => ['cards', 'list', 'featured']],
                    'limit'    => ['type' => 'number', 'label' => 'max_items', 'default' => 3],
                    'columns'  => ['type' => 'number', 'label' => 'columns', 'default' => 3, 'responsive' => true],
                    'view_all' => ['type' => 'boolean','label' => 'view_all_button', 'default' => true],
                ],
            ],
            'branches' => [
                'preview' => 'columns', 'label' => 'our_branches', 'pages' => ['home'],
                'blocks' => ['branch'],
                'hint' => 'addresses_opening_hours_and_a_map_link_for_each_branch',
                'schema' => [
                    'eyebrow' => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'   => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'style'   => ['type' => 'select', 'label' => 'display_style', 'default' => 'cards',
                                  'options' => ['cards', 'list']],
                    'columns' => ['type' => 'number', 'label' => 'columns', 'default' => 3, 'responsive' => true],
                ],
            ],
            'shipping_cutoff' => [
                'preview' => 'bar', 'label' => 'ship_today_countdown', 'pages' => ['home'],
                'hint' => 'order_within_x_to_have_it_shipped_today_counts_down_to_your_cut_off_time',
                'schema' => [
                    'cutoff'   => ['type' => 'text',   'label' => 'cut_off_time_24h', 'default' => '16:00', 'plain' => true],
                    'title'    => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'subtitle' => ['type' => 'text',   'label' => 'subtitle', 'default' => ''],
                    'style'    => ['type' => 'select', 'label' => 'display_style', 'default' => 'strip',
                                   'options' => ['strip', 'card']],
                ],
            ],
            'before_after' => [
                'preview' => 'beforeafter', 'label' => 'before_and_after', 'pages' => ['home'],
                'blocks' => ['comparison'],
                'hint' => 'a_slider_the_customer_drags_to_compare_two_photos',
                'schema' => [
                    'eyebrow' => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'   => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'columns' => ['type' => 'number', 'label' => 'columns', 'default' => 2, 'responsive' => true],
                    'height'  => ['type' => 'number', 'label' => 'height', 'default' => 360, 'responsive' => true],
                ],
            ],

            // --- multi-vendor -----------------------------------------------------------------
            // A marketplace's sellers are as much a browse entry as its categories: one section
            // lists the shops, the other features a single shop with its products.
            'vendor_slider' => [
                'preview' => 'vendors', 'label' => 'our_vendors', 'pages' => ['home'],
                'hint' => 'the_shops_selling_on_your_marketplace_with_their_rating_and_product_count',
                'schema' => [
                    'eyebrow'   => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'     => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'shop_ids'  => ['type' => 'resource', 'label' => 'choose_vendors', 'default' => null,
                                    'resource' => 'shop', 'multiple' => true,
                                    'hint' => 'leave_empty_to_show_the_highest_rated_shops_automatically'],
                    'style'     => ['type' => 'select', 'label' => 'display_style', 'default' => 'cards',
                                    // storefront: the shop's own cover, its logo, an official
                                    // badge where the platform owns the store, its rating and
                                    // range, and a way in. logos: a bare discovery strip.
                                    'options' => ['cards', 'storefront', 'rail', 'compact', 'logos']],
                    'limit'     => ['type' => 'number', 'label' => 'max_items', 'default' => 8],
                    'columns'   => ['type' => 'number', 'label' => 'columns', 'default' => 4, 'responsive' => true],
                    'stats'     => ['type' => 'boolean','label' => 'show_rating_and_product_count', 'default' => true],
                    'view_all'  => ['type' => 'boolean','label' => 'view_all_button', 'default' => true],
                ],
            ],
            'vendor_showcase' => [
                'preview' => 'showcase', 'label' => 'vendor_showcase', 'pages' => ['home'],
                'hint' => 'one_shop_with_its_cover_rating_and_products',
                'schema' => [
                    'shop_id'     => ['type' => 'resource', 'label' => 'choose_vendor', 'default' => null,
                                      'resource' => 'shop'],
                    'eyebrow'     => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'       => ['type' => 'text',   'label' => 'title_leave_empty_for_the_shop_name', 'default' => ''],
                    'cover'       => ['type' => 'boolean','label' => 'show_the_shop_cover', 'default' => true],
                    'stats'       => ['type' => 'boolean','label' => 'show_rating_and_product_count', 'default' => true],
                    'style'       => ['type' => 'select', 'label' => 'display_style', 'default' => 'rail',
                                      'options' => ['rail', 'grid']],
                    'limit'       => ['type' => 'number', 'label' => 'max_products', 'default' => 10],
                    'columns'     => ['type' => 'number', 'label' => 'columns', 'default' => 5, 'responsive' => true],
                    'view_all'    => ['type' => 'boolean','label' => 'visit_the_shop_button', 'default' => true],
                    'add_to_cart' => ['type' => 'boolean','label' => 'add_to_cart_button_on_each_card', 'default' => true],
                ],
            ],
            'testimonials' => [
                'preview' => 'quotes', 'label' => 'customer_voices', 'pages' => ['home'], 'hint' => 'real_product_reviews_from_your_customers',
                'schema' => [
                    'eyebrow'    => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'      => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'style'      => ['type' => 'select', 'label' => 'display_style', 'default' => 'cards',
                                     'options' => ['cards', 'wall', 'compact']],
                    'limit'      => ['type' => 'number', 'label' => 'max_items', 'default' => 3],
                    'columns'    => ['type' => 'number', 'label' => 'columns', 'default' => 3, 'responsive' => true],
                    'min_rating' => ['type' => 'number', 'label' => 'minimum_rating', 'default' => 4],
                ],
            ],
            'faq' => [
                'preview' => 'faq', 'label' => 'faq', 'pages' => ['home'], 'blocks' => ['qa'], 'hint' => 'questions_and_answers_with_a_help_panel',
                'schema' => [
                    'eyebrow'     => ['type' => 'text', 'label' => 'eyebrow', 'default' => ''],
                    'title'       => ['type' => 'text', 'label' => 'title', 'default' => ''],
                    'subtitle'    => ['type' => 'text', 'label' => 'subtitle', 'default' => ''],
                    'style'       => ['type' => 'select', 'label' => 'display_style', 'default' => 'panel',
                                      'options' => ['panel', 'two_column', 'cards']],
                    'button_text' => ['type' => 'text', 'label' => 'button_text', 'default' => ''],
                    'link'        => ['type' => 'link', 'label' => 'link', 'default' => ''],
                ],
            ],
            // --- new: sections that draw on what the shop already knows ------------------------
            // One row, several sources, one line of tabs. A home page that wants "new", "best
            // selling" and "top rated" needed three sections and three screens of height; this is
            // the same products in one, and the merchant names the tabs.
            'product_tabs' => [
                'preview' => 'rail', 'label' => 'product_tabs', 'pages' => ['home'],
                'blocks' => ['tab'],
                'hint' => 'one_row_of_products_with_tabs_across_the_top_each_tab_its_own_source',
                'schema' => [
                    'eyebrow'     => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'       => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'style'       => ['type' => 'select', 'label' => 'display_style', 'default' => 'rail',
                                      'options' => ['rail', 'grid']],
                    'limit'       => ['type' => 'number', 'label' => 'max_products_per_tab', 'default' => 8],
                    'columns'     => ['type' => 'number', 'label' => 'columns', 'default' => 5, 'responsive' => true],
                    'add_to_cart' => ['type' => 'boolean','label' => 'add_to_cart_button_on_each_card', 'default' => true],
                ],
            ],
            // The brand equivalent of category_showcase: one brand, its logo, its products.
            'brand_showcase' => [
                'preview' => 'showcase', 'label' => 'brand_showcase', 'pages' => ['home'],
                'hint' => 'one_brand_with_its_logo_and_its_products',
                'schema' => [
                    'brand_id'    => ['type' => 'resource', 'label' => 'choose_brand', 'default' => null,
                                      'resource' => 'brand'],
                    'eyebrow'     => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'       => ['type' => 'text',   'label' => 'title_leave_empty_for_the_brand_name', 'default' => ''],
                    'logo'        => ['type' => 'boolean','label' => 'show_the_brand_logo', 'default' => true],
                    'style'       => ['type' => 'select', 'label' => 'display_style', 'default' => 'rail',
                                      'options' => ['rail', 'grid']],
                    'limit'       => ['type' => 'number', 'label' => 'max_products', 'default' => 10],
                    'columns'     => ['type' => 'number', 'label' => 'columns', 'default' => 5, 'responsive' => true],
                    'view_all'    => ['type' => 'boolean','label' => 'view_all_button', 'default' => true],
                    'add_to_cart' => ['type' => 'boolean','label' => 'add_to_cart_button_on_each_card', 'default' => true],
                ],
            ],
            // What customers are actually typing into the search box, from the analytics rollup.
            // Not a guess and not a hand-written list: a shop's own demand, on its own home page.
            'trending_searches' => [
                'preview' => 'coupon', 'label' => 'trending_searches', 'pages' => ['home'],
                'hint' => 'the_terms_customers_actually_searched_for_taken_from_your_analytics',
                'schema' => [
                    'eyebrow' => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'   => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'days'    => ['type' => 'number', 'label' => 'look_back_days', 'default' => 30],
                    'limit'   => ['type' => 'number', 'label' => 'max_items', 'default' => 10],
                    'style'   => ['type' => 'select', 'label' => 'display_style', 'default' => 'chips',
                                  'options' => ['chips', 'ranked']],
                ],
            ],
            // The products this visitor looked at, from their own events and nobody else's.
            'recently_viewed' => [
                'preview' => 'rail', 'label' => 'recently_viewed', 'pages' => ['home'],
                'hint' => 'the_products_this_visitor_looked_at_shown_back_to_them',
                'schema' => [
                    'eyebrow'     => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'       => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'limit'       => ['type' => 'number', 'label' => 'max_products', 'default' => 8],
                    'columns'     => ['type' => 'number', 'label' => 'columns', 'default' => 5, 'responsive' => true],
                    'style'       => ['type' => 'select', 'label' => 'display_style', 'default' => 'rail',
                                      'options' => ['rail', 'grid']],
                    'add_to_cart' => ['type' => 'boolean','label' => 'add_to_cart_button_on_each_card', 'default' => true],
                ],
            ],
            // Get the app — with the QR the deep-link system can already generate, so the code on
            // the poster and the code on the page are the same link.
            'app_download' => [
                'preview' => 'split', 'label' => 'get_the_app', 'pages' => ['home', 'footer'],
                'hint' => 'app_store_buttons_and_a_qr_code_that_opens_the_app_or_the_store',
                'schema' => [
                    'eyebrow'   => ['type' => 'text',    'label' => 'eyebrow', 'default' => ''],
                    'title'     => ['type' => 'text',    'label' => 'title', 'default' => ''],
                    'subtitle'  => ['type' => 'text',    'label' => 'subtitle', 'default' => ''],
                    'image'     => ['type' => 'image',   'label' => 'phone_image', 'default' => ''],
                    'qr'        => ['type' => 'boolean', 'label' => 'show_a_qr_code', 'default' => true],
                    'style'     => ['type' => 'select',  'label' => 'display_style', 'default' => 'split',
                                    'options' => ['split', 'strip']],
                ],
            ],
            // Shop by what the customer can spend, which is how a lot of people actually shop.
            'price_tiles' => [
                'preview' => 'tiles', 'label' => 'shop_by_price', 'pages' => ['home'],
                'blocks' => ['price_band'],
                'hint' => 'tiles_that_lead_to_a_price_filtered_listing',
                'schema' => [
                    'eyebrow' => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'   => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'columns' => ['type' => 'number', 'label' => 'columns', 'default' => 4, 'responsive' => true],
                ],
            ],
            'custom_html' => [
                'preview' => 'text', 'label' => 'custom_content', 'pages' => ['home', 'footer'], 'hint' => 'your_own_text_block',
                'schema' => ['content' => ['type' => 'textarea', 'label' => 'content', 'default' => '']],
            ],
            'newsletter' => [
                'preview' => 'form', 'label' => 'newsletter', 'pages' => ['home', 'footer'], 'hint' => 'email_signup_panel',
                'schema' => [
                    'title'    => ['type' => 'text', 'label' => 'title', 'default' => ''],
                    'subtitle' => ['type' => 'text', 'label' => 'subtitle', 'default' => ''],
                    'style'    => ['type' => 'select', 'label' => 'display_style', 'default' => 'panel',
                                   'options' => ['panel', 'inline', 'split']],
                ],
            ],
            'spacer' => [
                'preview' => 'spacer', 'label' => 'spacer', 'pages' => ['home'], 'hint' => 'empty_vertical_space_between_sections',
                'schema' => ['height' => ['type' => 'number', 'label' => 'height', 'default' => 40, 'responsive' => true]],
            ],
            'footer_columns' => [
                'preview' => 'columns', 'label' => 'footer_columns', 'pages' => ['footer'], 'blocks' => ['menu', 'text', 'contact', 'social', 'apps'], 'hint' => 'link_and_contact_columns_in_the_footer',
                'schema' => ['columns' => ['type' => 'number', 'label' => 'columns', 'default' => 4, 'responsive' => true]],
            ],
        ];
    }

    /**
     * Repeatable child elements a section can hold (a hero slide, a promo tile, a footer column…).
     *
     * A section declares which of these it accepts via its `blocks` key; the schema lives here so
     * one block type can be reused by several sections and the builder renders its form the same
     * way it renders a section's.
     *
     * @return array<string, array{label:string, schema:array, title_key?:string, image_key?:string}>
     */
    public function blockTypes(): array
    {
        return [
            'slide' => [
                'label' => 'hero_slide', 'title_key' => 'title', 'image_key' => 'image',
                'schema' => [
                    'banner_id'    => ['type' => 'banner', 'label' => 'linked_dashboard_banner', 'default' => null],
                    'image'        => ['type' => 'image',  'label' => 'image', 'default' => ''],
                    'image_mobile' => ['type' => 'image',  'label' => 'mobile_image', 'default' => ''],
                    // Extra frames for the SAME slide, crossfading in place.
                    'image_2'      => ['type' => 'image',  'label' => 'second_image_optional', 'default' => ''],
                    'image_3'      => ['type' => 'image',  'label' => 'third_image_optional', 'default' => ''],
                    'eyebrow'      => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'        => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'subtitle'     => ['type' => 'textarea', 'label' => 'subtitle', 'default' => ''],
                    'link'         => ['type' => 'link',   'label' => 'link', 'default' => ''],
                    'button_text'  => ['type' => 'text',   'label' => 'button_text', 'default' => ''],
                    'align'        => ['type' => 'select', 'label' => 'content_position', 'default' => 'start',
                                       'options' => ['start', 'center', 'end']],
                    'text_color'   => ['type' => 'color',  'label' => 'text_color', 'default' => '#ffffff'],
                    'overlay'      => ['type' => 'number', 'label' => 'overlay_opacity', 'default' => 35],
                ],
            ],
            'banner' => [
                'label' => 'banner_tile', 'title_key' => 'title', 'image_key' => 'image',
                'schema' => [
                    'banner_id'   => ['type' => 'banner', 'label' => 'linked_dashboard_banner', 'default' => null],
                    'image'       => ['type' => 'image', 'label' => 'image', 'default' => ''],
                    // Extra frames for the SAME card: it crossfades through them in place, so
                    // one slot carries a campaign's several visuals without several cards.
                    'image_2'     => ['type' => 'image', 'label' => 'second_image_optional', 'default' => ''],
                    'image_3'     => ['type' => 'image', 'label' => 'third_image_optional', 'default' => ''],
                    'badge'       => ['type' => 'text',  'label' => 'badge', 'default' => ''],
                    'title'       => ['type' => 'text',  'label' => 'title', 'default' => ''],
                    'subtitle'    => ['type' => 'text',  'label' => 'subtitle', 'default' => ''],
                    'link'        => ['type' => 'link',  'label' => 'link', 'default' => ''],
                    'button_text' => ['type' => 'text',  'label' => 'button_text', 'default' => ''],
                ],
            ],
            'split' => [
                'label' => 'split_panel', 'title_key' => 'title', 'image_key' => 'image',
                'schema' => [
                    'banner_id'   => ['type' => 'banner', 'label' => 'linked_dashboard_banner', 'default' => null],
                    'image'       => ['type' => 'image',  'label' => 'image', 'default' => ''],
                    'media_side'  => ['type' => 'select', 'label' => 'image_side', 'default' => 'start',
                                      'options' => ['start', 'end']],
                    'eyebrow'     => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'       => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'body'        => ['type' => 'textarea', 'label' => 'body_text', 'default' => ''],
                    'link'        => ['type' => 'link',   'label' => 'link', 'default' => ''],
                    'button_text' => ['type' => 'text',   'label' => 'button_text', 'default' => ''],
                    'background'  => ['type' => 'color',  'label' => 'panel_background', 'default' => null],
                ],
            ],
            'mosaic_tile' => [
                'label' => 'mosaic_tile', 'title_key' => 'title', 'image_key' => 'image',
                'schema' => [
                    'banner_id'   => ['type' => 'banner', 'label' => 'linked_dashboard_banner', 'default' => null],
                    'image'       => ['type' => 'image',  'label' => 'image', 'default' => ''],
                    // The tile's shape. In the grid: small/square 1x1, wide 2x1, tall 1x2,
                    // large 2x2, strip a full-width rectangle row. In swipe display the shape
                    // sets the card's width against the row height instead.
                    'span'        => ['type' => 'select', 'label' => 'tile_shape', 'default' => 'small',
                                      'options' => ['small', 'square', 'wide', 'tall', 'large', 'strip']],
                    // Extra frames for the SAME tile: it crossfades through them in place, so one
                    // slot can carry a campaign's three visuals without three tiles.
                    'image_2'     => ['type' => 'image',  'label' => 'second_image_optional', 'default' => ''],
                    'image_3'     => ['type' => 'image',  'label' => 'third_image_optional', 'default' => ''],
                    'eyebrow'     => ['type' => 'text',   'label' => 'eyebrow', 'default' => ''],
                    'title'       => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'link'        => ['type' => 'link',   'label' => 'link', 'default' => ''],
                    'button_text' => ['type' => 'text',   'label' => 'button_text', 'default' => ''],
                    'text_color'  => ['type' => 'color',  'label' => 'text_color', 'default' => '#ffffff'],
                    'overlay'     => ['type' => 'number', 'label' => 'overlay_opacity', 'default' => 30],
                ],
            ],
            'usp' => [
                'label' => 'highlight', 'title_key' => 'title', 'image_key' => 'image',
                'schema' => [
                    'icon'     => ['type' => 'select', 'label' => 'icon', 'default' => 'shipping',
                                   'options' => ['shipping', 'authentic', 'returns', 'support', 'gift', 'secure']],
                    'image'    => ['type' => 'image',  'label' => 'custom_icon_image', 'default' => ''],
                    'title'    => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'subtitle' => ['type' => 'text',   'label' => 'subtitle', 'default' => ''],
                    'link'     => ['type' => 'link',   'label' => 'link', 'default' => ''],
                ],
            ],
            'stat' => [
                'label' => 'stat', 'title_key' => 'label',
                'schema' => [
                    'source' => ['type' => 'select', 'label' => 'value_source', 'default' => 'products',
                                 'options' => ['products', 'brands', 'categories', 'customers', 'orders', 'custom']],
                    'value'  => ['type' => 'text',   'label' => 'custom_value', 'default' => '',
                                 'depends_on' => ['source' => ['custom']]],
                    'label'  => ['type' => 'text',   'label' => 'label', 'default' => ''],
                    'suffix' => ['type' => 'text',   'label' => 'suffix_for_example_plus', 'default' => ''],
                    'icon'   => ['type' => 'select', 'label' => 'icon', 'default' => 'shipping',
                                 'options' => ['shipping', 'authentic', 'returns', 'support', 'gift', 'secure']],
                ],
            ],
            'interest' => [
                'label' => 'interest_tile', 'title_key' => 'title', 'image_key' => 'image',
                'schema' => [
                    'image'      => ['type' => 'image',   'label' => 'image', 'default' => ''],
                    'eyebrow'    => ['type' => 'text',    'label' => 'eyebrow', 'default' => ''],
                    'title'      => ['type' => 'text',    'label' => 'title', 'default' => ''],
                    'subtitle'   => ['type' => 'text',    'label' => 'subtitle', 'default' => ''],
                    'link'       => ['type' => 'link',    'label' => 'link', 'default' => ''],
                    'text_color' => ['type' => 'color',   'label' => 'text_color', 'default' => '#ffffff'],
                    'overlay'    => ['type' => 'number',  'label' => 'overlay_opacity', 'default' => 35],
                ],
            ],
            'story' => [
                'label' => 'story', 'title_key' => 'title', 'image_key' => 'image',
                'schema' => [
                    'image'      => ['type' => 'image', 'label' => 'cover_image', 'default' => ''],
                    'video'      => ['type' => 'link',  'label' => 'video_url_mp4_optional', 'default' => ''],
                    'title'      => ['type' => 'text',  'label' => 'title', 'default' => ''],
                    'link'       => ['type' => 'link',  'label' => 'link', 'default' => ''],
                    'button_text'=> ['type' => 'text',  'label' => 'button_text', 'default' => ''],
                ],
            ],
            'branch' => [
                'label' => 'branch', 'title_key' => 'title',
                'schema' => [
                    'title'   => ['type' => 'text',     'label' => 'branch_name', 'default' => ''],
                    'address' => ['type' => 'textarea', 'label' => 'address', 'default' => ''],
                    'hours'   => ['type' => 'text',     'label' => 'opening_hours', 'default' => ''],
                    'phone'   => ['type' => 'text',     'label' => 'phone', 'default' => '', 'plain' => true],
                    'link'    => ['type' => 'link',     'label' => 'map_link', 'default' => ''],
                ],
            ],
            'comparison' => [
                'label' => 'before_after_pair', 'title_key' => 'title', 'image_key' => 'image',
                'schema' => [
                    'image'  => ['type' => 'image', 'label' => 'before_image', 'default' => ''],
                    'after'  => ['type' => 'image', 'label' => 'after_image', 'default' => ''],
                    'title'  => ['type' => 'text',  'label' => 'title', 'default' => ''],
                    'caption'=> ['type' => 'text',  'label' => 'caption', 'default' => ''],
                ],
            ],
            'qa' => [
                'label' => 'faq_item', 'title_key' => 'question',
                'schema' => [
                    'question' => ['type' => 'text',     'label' => 'question', 'default' => ''],
                    'answer'   => ['type' => 'textarea', 'label' => 'answer', 'default' => ''],
                ],
            ],
            'tab' => [
                'label' => 'product_tab', 'title_key' => 'label',
                'schema' => [
                    'label'       => ['type' => 'text',   'label' => 'tab_label', 'default' => ''],
                    'source'      => ['type' => 'source', 'label' => 'product_source', 'default' => 'new_arrival',
                                      'options' => ['featured', 'best_selling', 'new_arrival', 'top_rated', 'category', 'brand', 'manual', 'collection']],
                    'source_id'   => ['type' => 'resource', 'label' => 'choose_category_or_brand', 'default' => null,
                                      'resource_from' => 'source', 'depends_on' => ['source' => ['category', 'brand']]],
                    'product_ids' => ['type' => 'resource', 'label' => 'choose_products', 'default' => null,
                                      'resource' => 'product', 'multiple' => true,
                                      'depends_on' => ['source' => ['manual']]],
                    'collection_id' => ['type' => 'resource', 'label' => 'choose_a_collection', 'default' => null,
                                      'resource' => 'product_collection',
                                      'depends_on' => ['source' => ['collection']]],
                ],
            ],
            'price_band' => [
                'label' => 'price_band', 'title_key' => 'label',
                'schema' => [
                    'label' => ['type' => 'text',   'label' => 'label_leave_empty_to_build_it_from_the_range', 'default' => ''],
                    'min'   => ['type' => 'number', 'label' => 'from', 'default' => 0],
                    'max'   => ['type' => 'number', 'label' => 'to_leave_empty_for_no_ceiling', 'default' => 0],
                    'image' => ['type' => 'image',  'label' => 'background_image', 'default' => ''],
                ],
            ],
            'menu' => [
                'label' => 'menu_column', 'title_key' => 'title',
                'schema' => [
                    'title' => ['type' => 'text', 'label' => 'title', 'default' => ''],
                    'links' => ['type' => 'textarea', 'label' => 'one_link_per_line_label_pipe_url', 'default' => ''],
                ],
            ],
            'text' => [
                'label' => 'text_column', 'title_key' => 'title',
                'schema' => [
                    'title'   => ['type' => 'text', 'label' => 'title', 'default' => ''],
                    'content' => ['type' => 'textarea', 'label' => 'content', 'default' => ''],
                ],
            ],
            'contact' => [
                'label' => 'contact_column', 'title_key' => 'title',
                'schema' => [
                    'title'   => ['type' => 'text', 'label' => 'title', 'default' => ''],
                    'address' => ['type' => 'textarea', 'label' => 'address', 'default' => ''],
                    'phone'   => ['type' => 'text', 'label' => 'phone', 'default' => '', 'plain' => true],
                    'email'   => ['type' => 'text', 'label' => 'email', 'default' => '', 'plain' => true],
                ],
            ],
            'social' => [
                'label' => 'social_links', 'title_key' => 'title',
                'schema' => [
                    'title'     => ['type' => 'text', 'label' => 'title', 'default' => ''],
                    'facebook'  => ['type' => 'link', 'label' => 'facebook', 'default' => ''],
                    'instagram' => ['type' => 'link', 'label' => 'instagram', 'default' => ''],
                    'tiktok'    => ['type' => 'link', 'label' => 'tiktok', 'default' => ''],
                    'youtube'   => ['type' => 'link', 'label' => 'youtube', 'default' => ''],
                    'x'         => ['type' => 'link', 'label' => 'x_twitter', 'default' => ''],
                ],
            ],
            'apps' => [
                'label' => 'app_links', 'title_key' => 'title', 'image_key' => 'image',
                'schema' => [
                    'title'       => ['type' => 'text',  'label' => 'title', 'default' => ''],
                    'image'       => ['type' => 'image', 'label' => 'image', 'default' => ''],
                    'google_play' => ['type' => 'link',  'label' => 'google_play_url', 'default' => ''],
                    'app_store'   => ['type' => 'link',  'label' => 'app_store_url', 'default' => ''],
                ],
            ],
        ];
    }

    public function has(string $type): bool
    {
        return array_key_exists($type, $this->types());
    }

    /** Block types the given section accepts, in the order the builder should offer them. */
    public function blockTypesFor(string $sectionType): array
    {
        $allowed = $this->types()[$sectionType]['blocks'] ?? [];

        return array_values(array_filter($allowed, fn ($type) => isset($this->blockTypes()[$type])));
    }

    public function allowsBlocks(string $sectionType): bool
    {
        return $this->blockTypesFor($sectionType) !== [];
    }

    public function hasBlockType(string $sectionType, string $blockType): bool
    {
        return in_array($blockType, $this->blockTypesFor($sectionType), true);
    }

    /** The block type a "+ Add" button should create when a section accepts only one kind. */
    public function defaultBlockType(string $sectionType): ?string
    {
        return $this->blockTypesFor($sectionType)[0] ?? null;
    }

    public function blockSchemaFor(string $blockType): array
    {
        return $this->blockTypes()[$blockType]['schema'] ?? [];
    }

    /** Same contract as normalizeSettings(), for a block: unknown keys dropped, values coerced. */
    public function normalizeBlockSettings(string $blockType, array $settings): array
    {
        $schema = $this->blockSchemaFor($blockType);
        $clean = [];

        foreach ($schema as $key => $field) {
            $clean[$key] = $this->coerce($settings[$key] ?? $field['default'] ?? null, $field);
            $this->keepLocaleOverrides($clean, $settings, $key, $field);
        }

        return $clean;
    }

    /** A short human label for one saved block — what the builder's block list shows. */
    public function blockLabel(string $blockType, array $settings = []): string
    {
        $definition = $this->blockTypes()[$blockType] ?? null;
        $titleKey = $definition['title_key'] ?? null;
        $title = $titleKey ? trim((string) ($settings[$titleKey] ?? '')) : '';

        return $title !== '' ? $title : ($definition['label'] ?? $blockType);
    }

    /** The settings key holding a block's preview image, when it has one. */
    public function blockImageKey(string $blockType): ?string
    {
        return $this->blockTypes()[$blockType]['image_key'] ?? null;
    }

    /** Section types available for a given page (home/header/footer). */
    public function forPage(string $page): array
    {
        return array_filter($this->types(), fn ($def) => in_array($page, $def['pages'] ?? [], true));
    }

    /** Only the type's own fields — the builder's "Content" tab, without the shared style fields. */
    public function ownSchemaFor(string $type): array
    {
        return $this->types()[$type]['schema'] ?? [];
    }

    /**
     * Which drawer of the inspector each setting belongs in.
     *
     * The inspector showed one long list per section: a product rail put its title, its data
     * source, its column count, its card style and its "view all" link in one column of twenty
     * fields, and finding the one you wanted meant reading all of them.
     *
     * Grouped by key rather than per type on purpose. Every type calls its source `source`, its
     * arrangement `style` or `layout`, its destination `link` — that shared vocabulary is what the
     * whole registry is built on, and a group written into each of thirty-nine definitions would
     * be thirty-nine chances to disagree with it. A key in none of these lists is content, which
     * is the right default: content is what a merchant types.
     */
    public const FIELD_GROUPS = [
        // What the section shows, as opposed to how it shows it.
        'source' => [
            'source', 'source_id', 'product_ids', 'collection_id', 'category_id', 'category_ids',
            'brand_id', 'shop_id', 'shop_ids', 'deal_id', 'banner_type', 'limit', 'days',
            'min_rating', 'discount', 'cutoff',
        ],
        // The arrangement, and the geometry of it.
        'layout' => [
            'style', 'layout', 'display', 'columns', 'gap', 'ratio', 'height', 'arrows',
            'pagination', 'autoplay', 'interval', 'view_all', 'sub_categories', 'banner', 'logo',
            'overlay', 'add_to_cart', 'rotate_ms', 'animate', 'ken_burns', 'parallax',
            'layout_lock', 'text_color', 'cover', 'countdown', 'dismissible', 'qr',
        ],
        // Where it leads.
        'action' => ['link', 'button_text'],
    ];

    /**
     * The section's own settings, split into the drawers the inspector opens.
     *
     * @return array<string, array<int, string>>  group => keys, in schema order, empty groups dropped
     */
    public function fieldGroupsFor(string $type): array
    {
        $groups = ['content' => [], 'source' => [], 'layout' => [], 'action' => []];

        foreach (array_keys($this->ownSchemaFor($type)) as $key) {
            $group = 'content';

            foreach (self::FIELD_GROUPS as $candidate => $keys) {
                if (in_array($key, $keys, true)) {
                    $group = $candidate;
                    break;
                }
            }

            $groups[$group][] = $key;
        }

        return array_filter($groups, static fn (array $keys) => $keys !== []);
    }

    /** Full schema for a type: its own fields plus the common ones. */
    public function schemaFor(string $type): array
    {
        $types = $this->types();
        if (!isset($types[$type])) {
            return [];
        }
        return array_merge($types[$type]['schema'] ?? [], $this->commonSchema());
    }

    /**
     * Validate and normalize a settings payload against the type's schema: unknown keys are
     * dropped (so the builder can never write arbitrary data), missing keys get defaults, and
     * values are coerced to the declared type.
     */
    public function normalizeSettings(string $type, array $settings): array
    {
        $schema = $this->schemaFor($type);
        $clean = [];

        foreach ($schema as $key => $field) {
            $clean[$key] = $this->coerce($settings[$key] ?? $field['default'] ?? null, $field);

            // Responsive overrides: settings may carry key_tablet / key_mobile variants.
            //
            // These get the SAME coercion as the base value. They used to be copied through raw,
            // which meant an override reached the storefront unvalidated while the base value was
            // sanitised — the one path an attacker-controlled or corrupted value could take.
            //
            // An absent override is left absent rather than defaulted: the renderer treats a
            // missing breakpoint key as "inherit the base value", so writing a default here would
            // silently pin every section to its desktop value on tablet and mobile.
            if (!empty($field['responsive'])) {
                foreach (['tablet', 'mobile'] as $breakpoint) {
                    $rKey = $key . '_' . $breakpoint;
                    if (!array_key_exists($rKey, $settings)) {
                        continue;
                    }
                    if ($settings[$rKey] === null || $settings[$rKey] === '') {
                        continue; // explicit "inherit"
                    }
                    $clean[$rKey] = $this->coerce($settings[$rKey], $field);
                }
            }

            $this->keepLocaleOverrides($clean, $settings, $key, $field);
        }

        return $clean;
    }

    /**
     * Locale overrides ride beside the base value the way breakpoint overrides do: `title_ar`
     * survives normalisation for a text field, with the same coercion as `title` — and only for a
     * text field, because a translated number is not a thing and an unexpected key on a numeric
     * setting is exactly the shape corruption normalisation exists to stop. Absent and blank both
     * mean "inherit the base", so nothing is defaulted.
     *
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $field
     */
    /**
     * The locale-override keys this type's schema can legitimately carry, mapped to what each
     * folds into: ['title_ar' => ['title', 'ar'], ...]. This map is what makes the collapse
     * schema-aware — without it, a shop adding a language whose code collides with a real
     * settings suffix (Indonesian's `id` against `source_id`) would have genuine settings
     * deleted from every payload as "someone else's override".
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function localeOverrideKeys(string $type): array
    {
        return $this->overrideKeyMap($this->schemaFor($type));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public function blockLocaleOverrideKeys(string $blockType): array
    {
        return $this->overrideKeyMap($this->blockSchemaFor($blockType));
    }

    /** @return array<string, array{0: string, 1: string}> */
    private function overrideKeyMap(array $schema): array
    {
        $map = [];

        foreach ($schema as $key => $field) {
            // Same rule keepLocaleOverrides applies at save time: plain fields carry no language.
            if (!in_array($field['type'] ?? '', ['text', 'textarea'], true) || !empty($field['plain'])) {
                continue;
            }
            foreach (LocalisedSettings::activeLocales() as $code) {
                $map[$key . '_' . $code] = [$key, $code];
            }
        }

        return $map;
    }

    private function keepLocaleOverrides(array &$clean, array $settings, string $key, array $field): void
    {
        if (!in_array($field['type'] ?? '', ['text', 'textarea'], true) || !empty($field['plain'])) {
            return;
        }

        foreach (LocalisedSettings::activeLocales() as $code) {
            $lKey = $key . '_' . $code;
            if (!array_key_exists($lKey, $settings)
                || $settings[$lKey] === null || $settings[$lKey] === '') {
                continue;
            }
            $clean[$lKey] = $this->coerce($settings[$lKey], $field);
        }
    }

    /** Coerce one value to the type its schema field declares. */
    private function coerce(mixed $value, array $field): mixed
    {
        return match ($field['type']) {
            'number'  => is_numeric($value) ? $value + 0 : ($field['default'] ?? null),
            'banner'  => (is_numeric($value) && (int) $value > 0) ? (int) $value : null,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) ($field['default'] ?? false),
            'select', 'source' => in_array($value, $field['options'] ?? [], true) ? $value : ($field['default'] ?? null),
            'resource' => $this->coerceResource($value, !empty($field['multiple'])),
            // A link or image lands in an href/src on the storefront and in the app. A value whose
            // scheme is not a web one — javascript:, data:, vbscript: — is the classic stored-XSS
            // shape, and nothing legitimate a merchant pastes ever carries one.
            'link', 'image' => $this->safeUrl($value),
            // A color lands in inline CSS on the storefront; anything that is not a plausible
            // CSS color literal is dropped rather than echoed into a style attribute.
            'color' => is_scalar($value)
                && preg_match('/^(#[0-9a-f]{3,8}|(rgb|rgba|hsl|hsla)\([0-9.,%\s\/]{1,40}\)|[a-z-]{1,30}|(linear|radial)-gradient\([^;{}<>]{1,200}\))$/i', trim((string) $value))
                ? trim((string) $value) : ($field['default'] ?? null),
            'text'     => is_scalar($value) ? mb_substr((string) $value, 0, 1000) : ($field['default'] ?? null),
            'textarea' => is_scalar($value) ? mb_substr((string) $value, 0, 20000) : ($field['default'] ?? null),
            default   => is_scalar($value) ? (string) $value : ($field['default'] ?? null),
        };
    }

    /**
     * A URL safe to place in an href/src: relative paths, anchors, and http(s)/mailto/tel
     * absolute URLs. Anything else — above all executable schemes — becomes null, which every
     * renderer already treats as "no link".
     */
    private function safeUrl(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $url = trim((string) $value);
        if ($url === '') {
            return null;
        }
        if (mb_strlen($url) > 2000) {
            return null;
        }

        // No scheme at all: a storefront path, an anchor, a query — all fine as-is.
        if (!preg_match('/^[a-z][a-z0-9+.\-]*:/i', $url)) {
            return $url;
        }

        return preg_match('#^(https?:|mailto:|tel:)#i', $url) ? $url : null;
    }

    /**
     * A picked catalogue record (a category, brand, product or flash deal).
     *
     * Stored as an id, or as a comma-separated list of ids for a multi-picker — a list keeps the
     * merchant's own order, which is the point of hand-picking. Everything that is not a positive
     * integer is dropped, so a settings payload can never carry anything else into a query.
     */
    private function coerceResource(mixed $value, bool $multiple): int|string|null
    {
        $ids = is_array($value) ? $value : explode(',', (string) (is_scalar($value) ? $value : ''));
        $ids = array_values(array_filter(array_map('intval', $ids), fn ($id) => $id > 0));

        if (!$multiple) {
            return $ids[0] ?? null;
        }

        return implode(',', array_slice(array_unique($ids), 0, self::MAX_PICKED_RESOURCES));
    }
}
