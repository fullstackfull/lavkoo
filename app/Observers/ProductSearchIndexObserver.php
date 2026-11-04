<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\Search\ProductSearchIndexer;

/**
 * Keeps the product search index in step with the catalogue (Phase 2.1).
 *
 * An observer rather than calls sprinkled through the controllers: products are written from the
 * admin panel, the vendor panel, three API versions and the bulk importer, and a per-caller hook
 * would be forgotten by whichever path is added next. The model is the one thing they all go
 * through.
 *
 * Every method swallows its own failures. Indexing is an enhancement to search; it must never be
 * able to fail a merchant's product save, and the index can always be regenerated with
 * `php artisan search:reindex-products`.
 */
class ProductSearchIndexObserver
{
    public function __construct(private readonly ProductSearchIndexer $indexer)
    {
    }

    public function saved(Product $product): void
    {
        try {
            $this->indexer->index($product);
        } catch (\Throwable) {
            // the save itself has already succeeded — never undo it over an index write
        }
    }

    public function deleted(Product $product): void
    {
        try {
            // Explicit rather than relying on the foreign key: a soft delete leaves the row in
            // place, and a soft-deleted product must stop appearing in search immediately.
            $this->indexer->remove($product->id);
        } catch (\Throwable) {
            // derived data; a stale row is harmless and the rebuild command clears it
        }
    }

    public function restored(Product $product): void
    {
        $this->saved($product);
    }
}
