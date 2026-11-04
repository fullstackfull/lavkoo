<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Services\BannerPlacementService;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Theme\ActionResolver;
use App\Traits\CacheManagerTrait;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BannerController extends Controller
{
    use CacheManagerTrait;

    /** Banner types that belong to a specific screen and are served by that screen's own endpoint. */
    private const SCREEN_SCOPED_TYPES = [
        'Category Banner', 'Category Section Banner', 'Home Promo Banner', 'Brand Banner',
    ];

    public function __construct(
        private readonly BannerPlacementService $bannerPlacement,
        private readonly ActionResolver $actions,
    ) {
    }

    /**
     * The home screen's promo grid: banners that belong to no category. `layout`
     * says how the banner is meant to sit — `full` on its own row, `half` beside
     * the next half, `slider` pooled with the other sliders into one rotating
     * slot — and they arrive in the admin's display order.
     */
    #[ApiDoc(
        summary: 'The home screen\'s promo grid',
        description: '`layout` says how each banner is meant to sit (`full` / `half` / `slider`), '
            . '`photo_full_url` is the web image and `mobile_photo_full_url` the phone-shaped one '
            . '(it falls back to the web image when the merchant uploaded no mobile image), and '
            . '`action` is what tapping it should open — the same typed vocabulary the theme '
            . 'sections use, so a client never has to parse a storefront URL.',
        audience: ApiDoc::CUSTOMER_APP,
        visibility: ApiDoc::PARTNER_VISIBLE,
        stability: ApiDoc::STABLE,
        since: 'v1',
    )]
    public function getHomePromoBanners(): JsonResponse
    {
        $banners = $this->bannerPlacement->getHomePromoBanners()
            ->map(fn ($banner) => $this->formatBanner(banner: $banner))
            ->values();

        return response()->json($banners, 200);
    }

    /**
     * The home screen's category-section banners, one group per category and
     * rendered above that category's product row. `photo_full_url` is the wide
     * web image and `mobile_photo_full_url` the phone-shaped one (it falls back
     * to the web image when no mobile image was uploaded), so the app can pick
     * either; `layout` carries the same grid intent as the promo banners.
     */
    #[ApiDoc(
        summary: 'Banners that head a category\'s row on the home screen',
        description: 'One per category, carrying that category so the row can be labelled. Same '
            . 'image and `action` contract as the promo banners.',
        audience: ApiDoc::CUSTOMER_APP,
        visibility: ApiDoc::PARTNER_VISIBLE,
        stability: ApiDoc::STABLE,
        since: 'v1',
    )]
    public function getCategorySectionBanners(): JsonResponse
    {
        $banners = $this->bannerPlacement->getCategorySectionBanners()->flatten(1)
            ->map(function ($banner) {
                $category = Category::find($banner['resource_id']);

                return $this->formatBanner(banner: $banner) + [
                    'category_id' => (int)$banner['resource_id'],
                    'category' => $category ? [
                        'id' => $category['id'],
                        'name' => $category['name'],
                        'slug' => $category['slug'],
                    ] : null,
                ];
            })
            // a banner whose category was deleted has nothing to sit above
            ->filter(fn ($banner) => $banner['category'] !== null)
            ->values();

        return response()->json($banners, 200);
    }

    /** @return array<string, mixed> */
    private function formatBanner(object $banner): array
    {
        return [
            'id' => $banner['id'],
            'title' => $banner['title'],
            'sub_title' => $banner['sub_title'],
            'button_text' => $banner['button_text'],
            'background_color' => $banner['background_color'],
            'url' => $banner['url'],
            'action' => $this->actions->resolve($banner['url']),
            'layout' => $banner['layout'],
            'priority' => $banner['priority'],
            'resource_type' => $banner['resource_type'],
            'resource_id' => $banner['resource_id'],
            'photo_full_url' => $banner->photo_full_url,
            'mobile_photo_full_url' => $banner->mobile_photo_full_url,
        ];
    }

    #[ApiDoc(
        summary: 'Every home-screen banner, grouped by the client on `banner_type`',
        description: 'Screen-scoped banners (category, brand and promo placements) are excluded — '
            . 'each screen reads its own. A banner pointing at a product, shop, brand or category '
            . 'carries that resource inline, plus `action`: the destination resolved server-side, '
            . 'which is what a client should navigate by. `mobile_photo_full_url` is the '
            . 'phone-shaped image, falling back to the web one.',
        audience: ApiDoc::CUSTOMER_APP,
        visibility: ApiDoc::PARTNER_VISIBLE,
        stability: ApiDoc::STABLE,
        since: 'v1',
    )]
    public function getBannerList(Request $request): JsonResponse
    {
        // Screen-scoped banners are excluded so the installed apps keep receiving
        // exactly the home-screen banners they render today; the category screen
        // reads its own from /categories/page-header/{id}.
        $banners = $this->cacheBannerTable()->whereNotIn('banner_type', self::SCREEN_SCOPED_TYPES);
        $productIds = [];
        $shopIds = [];
        $brandIds = [];
        $categoryIds = [];
        $bannerData = [];
        foreach ($banners as $banner) {
            if ($banner['resource_type'] == 'product' && !in_array($banner['resource_id'], $productIds)) {
                $productIds[] = $banner['resource_id'];
                $product = Product::find($banner['resource_id']);
                $banner['product'] = Helpers::product_data_formatting($product);
            }
            if ($banner['resource_type'] == 'shop' && !in_array($banner['resource_id'], $shopIds)) {
                $shopIds[] = $banner['resource_id'];
                $banner['shop'] = Shop::where('id', $banner['resource_id'])->first();
            }
            if ($banner['resource_type'] == 'brand' && !in_array($banner['resource_id'], $brandIds)) {
                $brandIds[] = $banner['resource_id'];
                $banner['brand'] = Brand::where('id', $banner['resource_id'])->first();
            }
            if ($banner['resource_type'] == 'category' && !in_array($banner['resource_id'], $categoryIds)) {
                $categoryIds[] = $banner['resource_id'];
                $banner['category'] = Category::where('id', $banner['resource_id'])->first();
            }
            // What tapping this banner should open, in the same closed vocabulary the theme
            // sections use. The apps used to work this out from resource_type themselves and
            // could only do it for a resource whose list they happened to have already cached;
            // a banner pointing at a category is a category here, resolved once, for everyone.
            $banner['action'] = $this->actions->resolve($banner['url']);

            $bannerData[] = $banner;
        }

        return response()->json($bannerData, 200);

    }
}
