<?php

namespace App\Jobs;

use App\Services\Monitoring\Panels\SearchIndexPanel;
use App\Services\Monitoring\Support\Clock;
use App\Models\BusinessSetting;
use App\Services\Search\ProductSearchIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuild the whole product search index, on the queue rather than in a web request.
 *
 * The reason an administrator needs this at all is the bulk importer: it writes product rows
 * without going through the model save path the index observer listens on, so a large import
 * leaves the catalogue searchable only up to the last product somebody saved by hand. Before this
 * the only cure was shell access.
 *
 * Queued because a rebuild walks every product in chunks, and a catalogue of any size outlives a
 * request. Unique so that an operator pressing the button twice — or pressing it while the weekly
 * task is running — does not start a second walk over the same table.
 */
class RebuildProductSearchIndex implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** One rebuild at a time, and never a lock left behind by a worker that died mid-walk. */
    public int $uniqueFor = 3600;

    public function __construct(public readonly ?string $requestedBy = null)
    {
    }

    public function uniqueId(): string
    {
        return 'product-search-index-rebuild';
    }

    public function handle(ProductSearchIndexer $indexer): void
    {
        if (!$indexer->available()) {
            return;
        }

        $startedAt = Clock::now();
        $indexed = $indexer->rebuild();

        $this->recordCompletion($startedAt, $indexed);
    }

    /**
     * Write down that it finished, so the operations page can say when — and, because this is a
     * settings write, so the audit trail records the rebuild without a second mechanism.
     */
    private function recordCompletion(\Illuminate\Support\Carbon $startedAt, int $indexed): void
    {
        if (!Schema::hasTable('business_settings')) {
            return;
        }

        // Through the model rather than the query builder: the settings model carries the audited
        // builder, so the rebuild leaves an audit line without a second mechanism to write one.
        BusinessSetting::updateOrInsert(
            ['type' => SearchIndexPanel::LAST_REBUILD_SETTING],
            [
                'value' => json_encode([
                    'finished_at' => Clock::now()->toDateTimeString(),
                    'duration_seconds' => Clock::now()->getTimestamp() - $startedAt->getTimestamp(),
                    'indexed' => $indexed,
                    'requested_by' => $this->requestedBy,
                ]),
                'updated_at' => now(),
            ],
        );
    }
}
