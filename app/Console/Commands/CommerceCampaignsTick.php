<?php

namespace App\Console\Commands;

use App\Models\ExperienceCampaign;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Move campaigns through their lifecycle on schedule (Phase 3.3).
 *
 * The serve path trusts the WINDOW, not the status — a campaign ends on time whether or not this
 * ran. What this adds is tidiness and cache freshness: statuses that read true in the admin, and
 * a delivery cache flushed at the transition so clients see it now rather than a TTL later.
 */
class CommerceCampaignsTick extends Command
{
    protected $signature = 'commerce:campaigns-tick';

    protected $description = 'Activate scheduled campaigns whose window opened and end those whose window closed';

    public function handle(ThemeDelivery $delivery, StorefrontThemeRenderer $renderer): int
    {
        if (!Schema::hasTable('experience_campaigns')) {
            return self::SUCCESS;
        }

        $now = now();

        $activated = ExperienceCampaign::query()
            ->where('status', ExperienceCampaign::STATUS_SCHEDULED)
            ->where('starts_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->update(['status' => ExperienceCampaign::STATUS_ACTIVE]);

        $ended = ExperienceCampaign::query()
            ->whereIn('status', ExperienceCampaign::SERVABLE_STATUSES)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $now)
            ->update(['status' => ExperienceCampaign::STATUS_ENDED]);

        if ($activated > 0 || $ended > 0) {
            \App\Services\Commerce\CampaignResolver::forget();
            $delivery->flush();
            $renderer->flush();
            $this->info("Activated {$activated}, ended {$ended} campaign(s).");
        }

        return self::SUCCESS;
    }
}
