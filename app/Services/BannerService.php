<?php

namespace App\Services;

use App\Traits\ManagesOptionalImage;

class BannerService
{
    use ManagesOptionalImage;

    /** How a grid banner sits: its own row, half a row, or pooled into the slider. */
    public const LAYOUTS = ['full', 'half', 'slider'];

    /** The banner types laid out as a grid, so the form only offers layout where it applies. */
    public const GRID_TYPES = ['Home Promo Banner', 'Category Section Banner'];

    /**
     * Placement types that find their page through the resource they point at: a
     * category banner with a product resource names no page and would never render,
     * so the resource type is part of the contract, not a free choice.
     */
    public const REQUIRED_RESOURCE_TYPES = [
        'Category Banner' => 'category',
        'Category Section Banner' => 'category',
        'Brand Banner' => 'brand',
    ];

    /**
     * The resource a banner type must point at, or null when any resource will do.
     * Callers reject the save rather than storing a banner that can never appear.
     */
    /**
     * Where each banner type lands on the storefront — the tag shown beside every row in Banner
     * Setup, so a list of thumbnails is never ambiguous about what it will do.
     *
     * Keys mirror getBannerTypes(); the resource-scoped ones say WHICH page they head, and the
     * caller appends the resource's own name (category / brand).
     */
    public const PLACEMENT_TAGS = [
        'Main Banner'             => 'home_page_top_slider',
        'Popup Banner'            => 'the_welcome_popup',
        'Footer Banner'           => 'above_the_footer',
        'Main Section Banner'     => 'the_home_page_middle_section',
        'Category Banner'         => 'heads_the_page_of_its_category',
        'Category Section Banner' => 'above_its_category_row_on_the_home_page',
        'Home Promo Banner'       => 'the_home_page_promo_grid',
        'Brand Banner'            => 'heads_the_page_of_its_brand',
        'Theme Banner'            => 'wherever_the_theme_builder_places_it',
    ];

    /** Translated placement tag for a banner type, or null for an unknown type. */
    public function getPlacementTag(?string $bannerType): ?string
    {
        $key = self::PLACEMENT_TAGS[$bannerType] ?? null;

        return $key ? translate($key) : null;
    }

    public function getRequiredResourceType(?string $bannerType): ?string
    {
        return self::REQUIRED_RESOURCE_TYPES[$bannerType] ?? null;
    }

    public function getProcessedData(object $request, ?string $bannerUrl = null, ?string $image = null, ?string $mobileImage = null): array
    {
        if ($image) {
            $imageName = $request->file('image') ? $this->update(dir:'banner/', oldImage:$image, format: 'webp', image: $request->file('image')) : $image;
        }else {
            $imageName = $this->upload(dir:'banner/', format: 'webp', image: $request->file('image'));
        }

        return [
            'banner_type' => $request['banner_type'],
            'mobile_photo' => $this->getProcessedMobileImage(
                request: $request,
                directory: 'banner/',
                storedImage: $mobileImage,
            ),
            'layout' => in_array($request['layout'], self::LAYOUTS) ? $request['layout'] : 'full',
            'priority' => (int)($request['priority'] ?? 0),
            'resource_type' => $request['resource_type'],
            'resource_id' => $request[$request->resource_type . '_id'],
            'theme' => theme_root_path(),
            'title' => $request['title'],
            'sub_title' => $request['sub_title'],
            'button_text' => $request['button_text'],
            'background_color' => $request['background_color'],
            'url' => $bannerUrl,
            'photo' => $imageName,
        ];
    }

    public function getBannerTypes(): array
    {
        return [
            "Main Banner" => translate('main_Banner'),
            "Popup Banner" => translate('popup_Banner'),
            "Footer Banner" => translate('footer_Banner'),
            "Main Section Banner" => translate('main_Section_Banner'),
            // Placed on the category page named by its category resource, and
            // inherited by that category's sub-categories.
            "Category Banner" => translate('category_Banner'),
            // Placed above its category's product row on the home page; the apps
            // render it inside that same row using the banner's mobile image.
            "Category Section Banner" => translate('category_Section_Banner'),
            // A home-page promo grid that belongs to no category: each banner
            // takes a full row, half a row, or joins the rotating slider.
            "Home Promo Banner" => translate('home_Promo_Banner'),
            // Heads the page of the brand named by its brand resource.
            "Brand Banner" => translate('brand_Banner'),
            // Rendered only where the Theme Builder places it (a linked block or a
            // banners-from-dashboard section) — it has no built-in slot of its own.
            "Theme Banner" => translate('theme_Banner'),
        ];
    }

}
