<?php

namespace App\Services\Theme;

use Illuminate\Support\Facades\Route;

/**
 * Where a section's "view all" leads.
 *
 * A rail shows the merchant's limit and stops; the link beside its heading is the shopper's way
 * past that. Both clients drew that link, and both decided its destination for themselves — the
 * blade with a `route()` call per section type, the app with a switch over section types. They
 * agreed on the easy half and disagreed on the half that matters: a rail scoped to one category
 * sent the shopper to the entire catalogue, on the phone AND on the web, because neither side
 * looked at what the rail was actually showing.
 *
 * So the destination is decided once, here, from the same {@see ContentSource} that decides which
 * products the rail holds — and then expressed the way each client already understands. The web
 * takes the URL; the app takes the typed action {@see ActionResolver} parses out of it and opens
 * the native screen. One answer, two renderings of it, and no way for them to drift apart.
 */
class SectionDestination
{
    public function __construct(private readonly ActionResolver $actions)
    {
    }

    /**
     * The storefront URL this section's heading link points at, or null when it leads nowhere.
     *
     * Null is a real answer, not a failure: a hand-picked rail IS the whole set, and a link to
     * "more of these" would open a list the shopper did not ask for.
     *
     * @param  array<string, mixed>  $settings
     */
    public function urlFor(string $type, array $settings): ?string
    {
        return match ($type) {
            'product_slider' => $this->forSource(ContentSource::fromSettings($settings, defaultLimit: 8)),

            'category_showcase' => $this->forSource(
                ContentSource::scoped('category', $settings['category_id'] ?? null),
            ),
            'brand_showcase' => $this->forSource(
                ContentSource::scoped('brand', $settings['brand_id'] ?? null),
            ),

            'category_grid', 'interest_tiles' => route('categories'),
            'brand_slider' => route('brands'),
            'vendor_slider' => route('vendors'),

            // The Blog module can be absent; a link to a route that is not registered is a 500 on
            // the home page, which is a far worse outcome than no link.
            'blog_posts' => Route::has('frontend.blog.index') ? route('frontend.blog.index') : null,

            default => null,
        };
    }

    /**
     * The same destination as a typed action, for a client that navigates natively.
     *
     * @param  array<string, mixed>  $settings
     * @return array{type: string, url: ?string}|array<string, mixed>
     */
    public function actionFor(string $type, array $settings): array
    {
        return $this->actions->resolve($this->urlFor($type, $settings));
    }

    /**
     * The list page that holds everything a source draws from.
     *
     * Each of these is a page the storefront already has, which is what makes the app's half free:
     * the resolver recognises them all and hands back the collection or subject the app has a
     * screen for.
     */
    private function forSource(ContentSource $source): ?string
    {
        return match ($source->kind) {
            'best_selling' => route('best-selling-products'),
            'new_arrival'  => route('latest-products'),
            'top_rated'    => route('top-rated-products'),
            'featured'     => route('featured-products'),

            // The filtered catalogue, spelled the way the admin's own link picker spells it, so it
            // resolves to the category or the brand rather than to "all products".
            'category' => $source->id === null
                ? null
                : route('products', ['category_id' => $source->id, 'data_from' => 'category']),
            'brand' => $source->id === null
                ? null
                : route('products', ['brand_id' => $source->id, 'data_from' => 'brand']),

            default => null,
        };
    }
}
