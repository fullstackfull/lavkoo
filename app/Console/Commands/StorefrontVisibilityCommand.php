<?php

namespace App\Console\Commands;

use App\Services\Storefront\StorefrontVisibility;
use Illuminate\Console\Command;

/**
 * Reads and writes the mapping that decides what a storefront shows (B-13, B-15, B-16), for an operator who has no admin screen for it
 * yet. It only ever writes the mapping: no central row of a category, a brand, a banner, a coupon or a campaign is touched, and clearing
 * the storefronts of an entity restores it everywhere.
 */
class StorefrontVisibilityCommand extends Command
{
    protected $signature = 'storefront:visibility
        {entity-type : category, brand, banner, coupon, flash_deal, deal_of_the_day, clearance_sale or experience_campaign}
        {entity-id : the id of the central row}
        {storefronts?* : the storefront countries the mapping names; none at all removes the mapping}
        {--show : print the storefronts the mapping names and write nothing}';

    protected $description = 'Show or set the storefronts an entity is hidden in (a category or a brand) or assigned to (a banner, a coupon, a deal, a clearance sale or a campaign)';

    public function handle(): int
    {
        $entityType = (string) $this->argument('entity-type');
        $mode = StorefrontVisibility::modeOf($entityType);

        if ($mode === null) {
            $this->error(sprintf('Unknown entity type "%s": use %s.', $entityType, implode(', ', StorefrontVisibility::entityTypes())));

            return self::INVALID;
        }

        if (!StorefrontVisibility::isTracked()) {
            $this->error('Storefronts are switched off, or the storefront_entity_visibility migration has not run. Nothing is read or written.');

            return self::FAILURE;
        }

        $entityId = (int) $this->argument('entity-id');

        if (!$this->option('show')) {
            StorefrontVisibility::set(entityType: $entityType, entityId: $entityId, countries: (array) $this->argument('storefronts'));
        }

        $storefronts = StorefrontVisibility::storefrontsOf(entityType: $entityType, entityId: $entityId);
        $verb = $mode === StorefrontVisibility::MODE_HIDDEN ? 'hidden in' : 'assigned to';

        $this->line($storefronts === []
            ? sprintf('%s %d: no mapping, so every storefront shows it.', $entityType, $entityId)
            : sprintf('%s %d: %s %s.', $entityType, $entityId, $verb, implode(', ', $storefronts)));

        return self::SUCCESS;
    }
}
