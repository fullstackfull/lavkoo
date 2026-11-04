<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\ProductPriceChange;
use App\Services\Marketplace\PriceChangeRecorder;

/**
 * Records every price change, whoever made it.
 *
 * An observer rather than a call at each writer, because the writers are the problem: `ProductService`
 * alone moves `unit_price` at seven call sites, and the admin panel, the vendor panel, three API
 * versions and the bulk importer all reach the column by their own route. Any of them could be
 * changed tomorrow by someone who has never heard of the price history. The observer fires after the
 * write, on every path, including the ones nobody has written yet.
 *
 * It reads what actually changed from the model's original attributes, so a save that rewrites the
 * same price records nothing.
 */
class ProductPriceObserver
{
    /** The price fields worth a history line. Anything else is not a price change. */
    private const WATCHED = ['unit_price', 'discount', 'discount_type'];

    public function __construct(private readonly PriceChangeRecorder $recorder)
    {
    }

    public function updated(Product $product): void
    {
        if (!$product->wasChanged(self::WATCHED)) {
            return;
        }

        $this->recorder->record(
            product: $product,
            before: [
                'unit_price' => $product->getOriginal('unit_price'),
                'discount' => $product->getOriginal('discount'),
                'discount_type' => $product->getOriginal('discount_type'),
            ],
            after: [
                'unit_price' => $product->unit_price,
                'discount' => $product->discount,
                'discount_type' => $product->discount_type,
            ],
            source: PriceChangeRecorder::currentSource(),
            reason: PriceChangeRecorder::currentReason(),
        );
    }

    /**
     * A new listing gets its opening price recorded too.
     *
     * The row has a null previous price, which is what distinguishes "listed at 40" from "changed to
     * 40" — a distinction that matters the first time somebody asks what a product has ever cost.
     */
    public function created(Product $product): void
    {
        if ($product->unit_price === null) {
            return;
        }

        $this->recorder->record(
            product: $product,
            before: ['unit_price' => null, 'discount' => null, 'discount_type' => null],
            after: [
                'unit_price' => $product->unit_price,
                'discount' => $product->discount,
                'discount_type' => $product->discount_type,
            ],
            source: PriceChangeRecorder::currentSource(),
            reason: PriceChangeRecorder::currentReason(),
        );
    }
}
