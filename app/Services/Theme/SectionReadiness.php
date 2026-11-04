<?php

namespace App\Services\Theme;

/**
 * Whether a section will actually appear on the storefront, and — when it will not — why.
 *
 * The storefront is right to skip a section that has nothing to show: a coupon strip with no live
 * coupon, a vendor showcase with no shop chosen, a stories row whose cards carry no image. Opening
 * a padded band with nothing inside it reads as a broken page.
 *
 * What was missing is the other half. In the builder those sections looked exactly like the ones
 * that work: added, visible, sitting in the structure panel, and invisible on the site with no
 * explanation anywhere. A merchant then either believes the theme is broken or, worse, believes
 * the section is there. Eight of the thirty-three could end up in that state.
 *
 * So the rule lives here once. The storefront asks "does this render" with what it has already
 * resolved — no second query on a page a customer is waiting for — and the builder asks "why not"
 * and gets a sentence and the fix. One rule, two readers, and a test that holds them together.
 */
class SectionReadiness
{
    /** It will render. */
    public const READY = 'ready';
    /** Nothing has been chosen yet: the merchant has to pick something. */
    public const NEEDS_CHOICE = 'needs_choice';
    /** Configured correctly, but the shop currently has nothing for it to show. */
    public const NO_CONTENT = 'no_content';
    /** It renders, but the moment has passed for today. */
    public const NOT_NOW = 'not_now';

    public function __construct(private readonly SectionDataResolver $data)
    {
    }

    /**
     * The storefront's question, answered from what it has already resolved.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<int, mixed>  $blocks   blocks that survived the content filter
     * @param  array<string, mixed>  $resolved  the section's own data, already fetched by the view
     */
    public function willRender(string $type, array $settings, array $blocks, array $resolved): bool
    {
        return match ($type) {
            'flash_deal' => !empty($resolved['deal']),
            'category_showcase' => !empty($resolved['showcase']),
            'vendor_slider' => !empty($resolved['vendors']),
            'vendor_showcase' => !empty($resolved['vendorShowcase']),
            'deal_of_the_day' => !empty($resolved['dealOfTheDay']),
            'featured_deal', 'clearance_sale' => !empty($resolved['offerProducts']),
            'coupon_strip' => !empty($resolved['coupons']),
            'product_slider' => !empty($resolved['sliderProducts']),
            'bundle' => !empty($resolved['bundle']),
            'blog_posts' => !empty($resolved['posts']),
            'shipping_cutoff' => !empty($resolved['secondsLeft']),
            'stats_bar', 'interest_tiles', 'stories', 'branches', 'before_after', 'product_tabs', 'price_tiles' => $blocks !== [],
            'brand_showcase' => !empty($resolved['brandShowcase']),
            'trending_searches' => !empty($resolved['searchTerms']),
            'recently_viewed' => !empty($resolved['seenProducts']),
            'app_download' => !empty($resolved['appStores']),
            default => true,
        };
    }

    /**
     * The builder's question: will this appear, and if not, what does the merchant do about it.
     *
     * Resolves for itself, because the builder is an admin screen where one extra query is worth a
     * merchant not having to publish a page to find out whether a section works.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array{state: string, reason_key: ?string}
     */
    public function verdict(string $type, array $settings, array $blocks): array
    {
        try {
            return $this->decide($type, $settings, $blocks);
        } catch (\Throwable) {
            // A readiness check that fails must never be read as "this section is broken": the
            // check is what broke, and the section may be perfectly fine.
            return $this->ready();
        }
    }

    // -----------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array{state: string, reason_key: ?string}
     */
    private function decide(string $type, array $settings, array $blocks): array
    {
        return match ($type) {
            'flash_deal' => $this->data->flashDeal(isset($settings['deal_id']) ? (int) $settings['deal_id'] : null) !== null
                ? $this->ready()
                : $this->missing(self::NO_CONTENT, 'no_flash_deal_is_running_so_this_section_stays_hidden_until_one_is'),

            'category_showcase' => empty($settings['category_id'])
                ? $this->missing(self::NEEDS_CHOICE, 'choose_a_category_for_this_section_to_have_something_to_show')
                : ($this->data->categoryShowcase($settings) !== null
                    ? $this->ready()
                    : $this->missing(self::NO_CONTENT, 'the_chosen_category_has_no_published_products_yet')),

            'vendor_slider' => $this->data->vendors((int) ($settings['limit'] ?? 8), $settings['shop_ids'] ?? null)->isNotEmpty()
                ? $this->ready()
                : $this->missing(self::NO_CONTENT, 'no_vendor_shop_is_available_to_show'),

            'vendor_showcase' => empty($settings['shop_id'])
                ? $this->missing(self::NEEDS_CHOICE, 'choose_a_vendor_for_this_section_to_have_something_to_show')
                : ($this->data->vendorShowcase($settings) !== null
                    ? $this->ready()
                    : $this->missing(self::NO_CONTENT, 'the_chosen_vendor_has_no_published_products_yet')),

            'deal_of_the_day' => $this->data->dealOfTheDay() !== null
                ? $this->ready()
                : $this->missing(self::NO_CONTENT, 'set_a_deal_of_the_day_in_promotion_for_this_section_to_appear'),

            'featured_deal' => $this->data->featuredDealProducts((int) ($settings['limit'] ?? 10))->isNotEmpty()
                ? $this->ready()
                : $this->missing(self::NO_CONTENT, 'no_featured_deal_campaign_is_running'),

            'clearance_sale' => $this->data->clearanceProducts((int) ($settings['limit'] ?? 10))->isNotEmpty()
                ? $this->ready()
                : $this->missing(self::NO_CONTENT, 'no_product_is_on_clearance_right_now'),

            'coupon_strip' => $this->data->coupons((int) ($settings['limit'] ?? 4))->isNotEmpty()
                ? $this->ready()
                : $this->missing(self::NO_CONTENT, 'no_coupon_is_live_right_now'),

            'bundle' => ContentSource::picked($settings['product_ids'] ?? null)->ids === []
                ? $this->missing(self::NEEDS_CHOICE, 'pick_at_least_two_products_for_the_bundle')
                : ($this->data->bundle($settings) !== null
                    ? $this->ready()
                    : $this->missing(self::NO_CONTENT, 'the_chosen_products_are_no_longer_available')),

            'blog_posts' => $this->data->blogPosts((int) ($settings['limit'] ?? 3))->isNotEmpty()
                ? $this->ready()
                : $this->missing(self::NO_CONTENT, 'no_blog_post_has_been_published_yet'),

            'shipping_cutoff' => $this->data->shippingCutoff((string) ($settings['cutoff'] ?? '16:00')) !== null
                ? $this->ready()
                : $this->missing(self::NOT_NOW, 'todays_cut_off_time_has_passed_so_this_appears_again_tomorrow_morning'),

            'product_slider' => $this->sourceVerdict(ContentSource::fromSettings($settings, defaultLimit: 8)),

            'category_grid' => $this->data->categories((int) ($settings['limit'] ?? 12), $settings['category_ids'] ?? null)->isNotEmpty()
                ? $this->ready()
                : $this->missing(self::NO_CONTENT, 'no_category_is_available_to_show'),

            'brand_slider' => $this->data->brands((int) ($settings['limit'] ?? 12))->isNotEmpty()
                ? $this->ready()
                : $this->missing(self::NO_CONTENT, 'no_brand_is_available_to_show'),

            'testimonials' => $this->data->testimonials((int) ($settings['limit'] ?? 3), (int) ($settings['min_rating'] ?? 4))->isNotEmpty()
                ? $this->ready()
                : $this->missing(self::NO_CONTENT, 'no_review_meets_the_minimum_rating_yet'),

            'store_banner' => $this->data->dashboardBanners((string) ($settings['banner_type'] ?? 'Main Banner'), (int) ($settings['limit'] ?? 6)) !== []
                ? $this->ready()
                : $this->missing(self::NO_CONTENT, 'no_banner_of_this_type_is_published_in_promotion_banners'),

            'brand_showcase' => empty($settings['brand_id'])
                ? $this->missing(self::NEEDS_CHOICE, 'choose_a_brand_for_this_section_to_have_something_to_show')
                : ($this->data->brandShowcase($settings) !== null
                    ? $this->ready()
                    : $this->missing(self::NO_CONTENT, 'the_chosen_brand_has_no_published_products_yet')),

            // Both of these are drawn from what the shop has measured, so "nothing yet" is a real
            // and temporary state rather than a misconfiguration — and saying which it is matters:
            // one is fixed by waiting, the other by installing analytics.
            'trending_searches' => $this->data->trendingSearches((int) ($settings['days'] ?? 30), (int) ($settings['limit'] ?? 10))->isNotEmpty()
                ? $this->ready()
                : $this->missing(self::NO_CONTENT, 'no_search_has_been_rolled_up_yet_this_fills_in_once_customers_search'),

            'recently_viewed' => $this->missing(self::NOT_NOW, 'this_shows_each_visitor_their_own_history_so_it_is_empty_until_they_have_one'),

            // Nothing to link to is not something the merchant can fix from the theme: the app's
            // store listings live in the deep-link settings, so the badge points there.
            'app_download' => app(\App\Services\DeepLink\AppLinkService::class)->storeUrl('android') !== null
                || app(\App\Services\DeepLink\AppLinkService::class)->storeUrl('ios') !== null
                    ? $this->ready()
                    : $this->missing(self::NEEDS_CHOICE, 'add_the_app_store_links_in_settings_deep_links_for_this_section_to_have_somewhere_to_send_people'),

            // Block-driven sections are nothing but their blocks.
            'stats_bar', 'interest_tiles', 'stories', 'branches', 'before_after', 'hero_banner',
            'promotional_banner', 'split_banner', 'banner_mosaic', 'footer_columns',
            'product_tabs', 'price_tiles' => $this->blockVerdict($type, $blocks),

            default => $this->ready(),
        };
    }

    /**
     * Why a product rail is empty, told apart from whether it is.
     *
     * The three ways a rail comes up empty need three different sentences: a source scoped to a
     * category nobody chose, a manual source with nothing picked, and a properly configured source
     * the shop currently has no products for. Only the last is something the merchant waits out;
     * the first two they fix in the panel they are already looking at.
     *
     * @return array{state: string, reason_key: ?string}
     */
    private function sourceVerdict(ContentSource $source): array
    {
        if ($source->needsSubject()) {
            return $this->missing(self::NEEDS_CHOICE, $source->kind === 'brand'
                ? 'choose_a_brand_for_this_section_to_have_something_to_show'
                : 'choose_a_category_for_this_section_to_have_something_to_show');
        }

        if ($source->isManual() && $source->ids === []) {
            return $this->missing(self::NEEDS_CHOICE, 'pick_the_products_this_section_should_show');
        }

        return $this->data->productsFrom($source)->isNotEmpty()
            ? $this->ready()
            : $this->missing(self::NO_CONTENT, 'this_product_source_returns_nothing_at_the_moment');
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array{state: string, reason_key: ?string}
     */
    private function blockVerdict(string $type, array $blocks): array
    {
        $withContent = match ($type) {
            'stories' => $this->data->blocksWithContent($blocks, either: ['image', 'video']),
            'product_tabs' => $this->data->blocksWithContent($blocks, required: ['label']),
            'branches' => $this->data->blocksWithContent($blocks, required: ['title']),
            'before_after' => $this->data->blocksWithContent($blocks, required: ['image', 'after']),
            default => $blocks,
        };

        if ($withContent !== []) {
            return $this->ready();
        }

        return $this->missing(
            self::NEEDS_CHOICE,
            $blocks === []
                ? 'add_at_least_one_card_to_this_section'
                : 'the_cards_in_this_section_have_no_content_yet',
        );
    }

    /** @return array{state: string, reason_key: ?string} */
    private function ready(): array
    {
        return ['state' => self::READY, 'reason_key' => null];
    }

    /** @return array{state: string, reason_key: ?string} */
    private function missing(string $state, string $reasonKey): array
    {
        return ['state' => $state, 'reason_key' => $reasonKey];
    }
}
