<?php

namespace App\Console\Commands;

use App\Services\Search\ProductSearchIndexer;
use Illuminate\Console\Command;

/**
 * Rebuilds the normalised product search index (Phase 2.1).
 *
 * Needed for three ordinary situations, all of which otherwise leave the index quietly stale:
 *   - first deployment, when the table exists but nothing has been indexed yet;
 *   - a bulk product import, which writes rows without going through the model save path;
 *   - a change to the normaliser, after which every stored value needs regenerating.
 *
 *   php artisan search:reindex-products
 */
class RebuildProductSearchIndex extends Command
{
    protected $signature = 'search:reindex-products {--chunk=200 : Products loaded per batch}';

    protected $description = 'Rebuild the normalised product search index used by storefront search';

    public function handle(ProductSearchIndexer $indexer): int
    {
        if (!$indexer->available()) {
            $this->error('The product_search_index table does not exist — run migrations first.');

            return self::FAILURE;
        }

        $this->info('Rebuilding the product search index…');

        $start = microtime(true);
        $indexed = $indexer->rebuild(
            progress: fn (int $total) => $this->output->write("\r  indexed {$total} products"),
            chunkSize: max(1, (int) $this->option('chunk')),
        );

        $this->newLine();
        $this->info(sprintf('Done: %d products in %.1fs.', $indexed, microtime(true) - $start));

        return self::SUCCESS;
    }
}
