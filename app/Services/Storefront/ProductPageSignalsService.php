<?php

namespace App\Services\Storefront;

use App\Models\Product;
use Illuminate\Support\Str;

/**
 * The trust and urgency signals a product page shows above the fold: how many people are looking
 * at this product, the short pitch under the title, and how much the discount saves.
 *
 * Live viewers are a MERCHANDISING signal, not analytics — the admin turns them on and sets the
 * range, exactly like the storefront's other promotional widgets. Two rules keep them honest to
 * the customer's eye: the number is derived from the product id and the current time window
 * (never random per request, so a refresh does not make it jump around), and it only appears for
 * products a customer can actually buy. Nothing here is stored or reported as real traffic.
 */
class ProductPageSignalsService
{
    /** How long one viewer count stands before it drifts, in seconds. */
    private const WINDOW = 600;

    public function isLiveViewersEnabled(): bool
    {
        return (bool) getWebConfig(name: 'product_live_viewers_status');
    }

    /**
     * The number to show beside "people are viewing this now", or null when the widget is off or
     * the product cannot be bought.
     */
    public function liveViewers(Product $product): ?int
    {
        if (!$this->isLiveViewersEnabled()) {
            return null;
        }

        if ($product->product_type === 'physical' && $product->current_stock <= 0) {
            return null;
        }

        [$min, $max] = $this->viewerRange();

        // Stable inside a window, different per product, and drifting on its own afterwards.
        $window = (int) floor(time() / self::WINDOW);
        $seed = crc32($product->id . ':' . $window);

        return $min + ($seed % max(1, $max - $min + 1));
    }

    /** @return array{0:int,1:int} the admin's viewer range, always sane */
    public function viewerRange(): array
    {
        $min = max(2, (int) getWebConfig(name: 'product_live_viewers_min') ?: 8);
        $max = max($min + 1, (int) getWebConfig(name: 'product_live_viewers_max') ?: 60);

        return [$min, $max];
    }

    /**
     * The one-paragraph pitch under the title.
     *
     * Uses the SEO meta description when the merchant wrote one — that field is already "the short
     * version of this product" — and otherwise the opening of the full description, stripped of
     * markup. So every product gets a short description without a new field to fill in.
     */
    public function shortDescription(Product $product, int $length = 220): ?string
    {
        foreach ([$product->meta_description, $product->details] as $source) {
            $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $source)));
            if ($text !== '') {
                return Str::limit($text, $length);
            }
        }

        return null;
    }

    /** Whole-percent saving off the unit price, or 0 when the product is not discounted. */
    public function discountPercentage(Product $product): int
    {
        $unitPrice = (float) $product->unit_price;
        $price = (float) getProductPriceByType(product: $product, type: 'discounted_unit_price', result: 'value');
        $saved = max(0, $unitPrice - $price);

        return $unitPrice > 0 && $saved > 0 ? (int) round($saved / $unitPrice * 100) : 0;
    }

    /** The merchant's authenticity badge ("100% original"), or null when it is switched off. */
    public function authenticityBadge(): ?string
    {
        if (!getWebConfig(name: 'product_authenticity_badge_status')) {
            return null;
        }

        $text = trim((string) getWebConfig(name: 'product_authenticity_badge_text'));

        return $text !== '' ? $text : translate('100_percent_authentic');
    }
}
