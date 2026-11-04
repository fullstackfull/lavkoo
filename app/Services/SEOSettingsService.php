<?php

namespace App\Services;

use App\Traits\FileManagerTrait;

class SEOSettingsService
{
    use FileManagerTrait;

    public function getRobotsMetaContentPages(object|array $businessPages): array
    {
        $allPages = [
            "brands" => ['title' => 'all_Brands', 'route' => route('brands')],
            "categories" => ['title' => 'all_Categories', 'route' => route('categories')],
            "vendors" => ['title' => 'vendor_List', 'route' => route('vendors')],
            "contacts" => ['title' => 'contact_us', 'route' => route('contacts')],
            "helpTopic" => ['title' => 'FAQ', 'route' => route('helpTopic')],
            "track-order" => ['title' => 'track_order', 'route' => route('track-order.index')],
            "featured-products" => ['title' => 'Featured_Products', 'route' => route('featured-products')],
            "latest-products" => ['title' => 'Latest_Products', 'route' => route('latest-products')],
            "best-selling-products" => ['title' => 'Best_Selling_Products', 'route' => route('best-selling-products')],
            "top-rated-products" => ['title' => 'Top_Rated_Products', 'route' => route('top-rated-products')],
            "most-favorite-products" => ['title' => 'Most_Favorite_Products', 'route' => route('most-favorite-products')],
            "discounted-products" => ['title' => 'Discounted_Products', 'route' => route('discounted-products')],
        ];
        foreach ($businessPages as $businessPage) {
            $allPages[$businessPage['slug']] = ['title' => $businessPage['title'], 'route' => route('business-page.view', ['slug' => $businessPage['slug']])];
        }

        if (getCheckAddonPublishedStatus(moduleName: 'Auction') && getWebConfig(name: 'auction_feature_status')) {
            $auctionPages = [
                "auction_brands_products" => [
                    'title' => 'Auction_Brands_Products',
                    'route' => route('auction.brands-products'),
                ],
                "auction_ending_soon" => [
                    'title' => 'Auction_Ending_Soon_Products',
                    'route' => route('auction.ending-soon-products'),
                ],
                "auction_trending_products" => [
                    'title' => 'Auction_Trending_Products',
                    'route' => route('auction.trending-products'),
                ],
                "auction_upcoming_products" => [
                    'title' => 'Auction_Upcoming_Products',
                    'route' => route('auction.upcoming-products'),
                ]
            ];

            $allPages = array_merge($allPages, $auctionPages);
        }

        ksort($allPages);
        return $allPages;
    }

    public function getRobotsMetaContentPageName(string $name, object|array $businessPages): array
    {
        return self::getRobotsMetaContentPages(businessPages: $businessPages)[$name] ?? [];
    }

    public function getRobotsMetaContentData(object $request, object|null $oldData = null, object|array $businessPages = []): array
    {
        if ($oldData) {
            $metaImage = $request->file('meta_image') ? $this->update(dir: 'robots-meta-content/', oldImage: $oldData['meta_image'], format: 'png', image: $request['meta_image']) : $oldData['meta_image'];
        } else {
            $metaImage = $request->file('meta_image') ? $this->upload(dir: 'robots-meta-content/', format: 'webp', image: $request['meta_image']) : null;
        }
        return [
            "page_title" => self::getRobotsMetaContentPageName(name: $request['page_name'], businessPages: $businessPages)['title'] ?? '',
            "page_name" => $request['page_name'],
            "meta_title" => $request['meta_title'],
            "meta_description" => $request['meta_description'],
            "canonicals_url" => $request['canonicals_url'],
            "index" => $request['meta_index'] != 'noindex' ? '' : 'noindex',
            "no_follow" => $request['meta_no_follow'] ? 'nofollow' : '',
            "no_image_index" => $request['meta_no_image_index'] ? 'noimageindex' : '',
            "no_archive" => $request['meta_no_archive'] ? 'noarchive' : '',
            "no_snippet" => $request['meta_no_snippet'] ?? 0,
            "max_snippet" => $request['meta_max_snippet'] ?? 0,
            "max_snippet_value" => $request['meta_max_snippet_value'] ?? 0,
            "max_video_preview" => $request['meta_max_video_preview'] ?? 0,
            "max_video_preview_value" => $request['meta_max_video_preview_value'] ?? 0,
            "max_image_preview" => $request['meta_max_image_preview'] ?? 0,
            "max_image_preview_value" => $request['meta_max_image_preview_value'] ?? 0,
            "meta_image" => $metaImage ?? ($oldData ? $oldData['meta_image'] : null),
            "updated_at" => now(),
        ];
    }
}
