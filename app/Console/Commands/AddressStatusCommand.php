<?php

namespace App\Console\Commands;

use App\Services\Address\AddressStatus;
use Illuminate\Console\Command;

class AddressStatusCommand extends Command
{
    protected $signature = 'address:status';

    protected $description = 'Read-only report on the unified address: migrations, mode, lists, old addresses to complete, provider mappings (counts only, no personal data)';

    public function handle(AddressStatus $status): int
    {
        $report = $status->report();

        $this->line('Migrations:');
        foreach ($report['migrations'] as $migration => $done) {
            $this->line(sprintf('  %-7s %s', $done ? 'done' : 'pending', $migration));
        }

        $this->line(sprintf(
            'Mode: %s configured, %s in force%s. ZIP delivery restriction: %s.',
            $report['mode']['configured'],
            $report['mode']['in_force'],
            $report['mode']['configured'] !== $report['mode']['in_force'] ? ' (the store is held at off until the migrations are run and a city is active)' : '',
            $report['zip_restriction'] ? 'on' : 'off',
        ));

        if (!$report['ready']) {
            $this->line('The address migrations have not been run yet; nothing to report.');

            return self::SUCCESS;
        }

        $catalog = $report['catalog'];
        $this->line(sprintf('Regions: %d (%d active). Cities: %d (%d active). Aliases: %d.', $catalog['regions'], $catalog['regions_active'], $catalog['cities'], $catalog['cities_active'], $catalog['aliases']));
        $import = $report['last_import'];
        $this->line($import === null ? 'Data: no imported cities.' : sprintf(
            'Data: %s, %d cities, snapshot %s, loaded %s.',
            $import['source'] ?? 'unknown source',
            $import['cities'],
            $import['snapshot_at']?->toDateString() ?? 'unknown',
            $import['loaded_at']?->toDateString() ?? 'unknown',
        ));

        $addresses = $report['addresses'];
        $this->line(sprintf('Saved Saudi addresses: %d. With a region and city: %d. Old (typed city only): %d.', $addresses['saudi'], $addresses['unified'], $addresses['legacy']));
        $this->line(sprintf('Old addresses a backfill would match: %d. Left for a person: %d.', $addresses['legacy_matched'], $addresses['legacy_unmatched']));
        $this->line(sprintf('Addresses to complete (no city of the list, or no National Address): %d. Without a ZIP code: %d.', $addresses['needing_completion'], $addresses['without_zip']));

        if (!$report['mappings_ready']) {
            $this->line('Provider mappings: the mapping migrations have not been run.');
        }
        foreach ($report['providers'] as $provider) {
            $this->line(sprintf(
                'Provider %s: %d approved, %d pending, %d rejected, %d retired; active cities without an approved mapping: %d; last city sync: %s.',
                $provider['label'],
                $provider['approved'],
                $provider['pending'],
                $provider['rejected'],
                $provider['retired'],
                $provider['cities_without_approved'],
                $provider['last_sync'] === null ? 'never' : $provider['last_sync']['verdict'] . ' ' . $provider['last_sync']['at']?->toDateString(),
            ));
        }

        return self::SUCCESS;
    }
}
