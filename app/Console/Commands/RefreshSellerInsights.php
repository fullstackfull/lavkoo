<?php

namespace App\Console\Commands;

use App\Models\Seller;
use App\Services\SellerIntelligence\SellerInsightEngine;
use App\Services\SellerIntelligence\SellerNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Recompute what every seller should be looking at.
 *
 * Runs for approved sellers only: a suspended or pending account has no Action Center to fill, and
 * computing for them would be work nobody reads.
 *
 * Recomputing is only half of it. Until now this wrote rows and told nobody, so a seller learned
 * their listing had been rejected by opening the app and looking. Anything urgent enough to be worth
 * interrupting someone is now announced, aggregated by type and once per window — `--quiet-notify`
 * turns that off for a targeted re-run that should not re-announce anything.
 */
class RefreshSellerInsights extends Command
{
    protected $signature = 'seller:refresh-insights
                            {--seller= : Only this seller, for a targeted refresh}
                            {--type=* : Only these insight types}
                            {--quiet-notify : Recompute without announcing anything}';

    protected $description = 'Recompute seller insights (Action Center, home alerts)';

    public function handle(SellerInsightEngine $engine, SellerNotifier $notifier): int
    {
        if (!Schema::hasTable('seller_insights')) {
            $this->warn('seller_insights table is not present; nothing to do.');

            return self::SUCCESS;
        }

        $types = $this->option('type') ?: null;
        $sellerIds = $this->option('seller')
            ? [(int) $this->option('seller')]
            : Seller::approved()->pluck('id')->all();

        $written = 0;
        $resolved = 0;
        $announced = 0;

        foreach ($sellerIds as $sellerId) {
            $result = $engine->refresh($sellerId, $types);
            $written += $result['written'];
            $resolved += $result['resolved'];

            if (!$this->option('quiet-notify')) {
                $announced += count($this->announce($engine, $notifier, $sellerId));
            }
        }

        $this->info(sprintf(
            '%d seller(s): %d insight(s) written, %d resolved, %d announced.',
            count($sellerIds), $written, $resolved, $announced,
        ));

        return self::SUCCESS;
    }

    /**
     * Tell one seller what is waiting, if anything is worth telling them.
     *
     * Only what the engine considers open — a resolved or dismissed insight is not news — and only
     * the severities that justify interrupting someone. The notifier itself refuses to repeat a fact
     * inside its window, so running this hourly does not announce the same thing hourly.
     *
     * @return array<int, \App\Models\SellerNotificationDelivery>
     */
    private function announce(SellerInsightEngine $engine, SellerNotifier $notifier, int|string $sellerId): array
    {
        $seller = Seller::find($sellerId);

        if (!$seller) {
            return [];
        }

        $urgent = $engine->open($sellerId, limit: 200)
            ->filter(fn ($insight) => in_array($insight->severity, ['critical', 'high'], true));

        return $urgent->isEmpty() ? [] : $notifier->announceInsights($seller, $urgent);
    }
}
