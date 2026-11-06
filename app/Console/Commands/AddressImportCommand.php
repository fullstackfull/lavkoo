<?php

namespace App\Console\Commands;

use App\Services\Address\AddressImporter;
use App\Services\Address\AddressSchema;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AddressImportCommand extends Command
{
    protected $signature = 'address:import
        {--apply : write the changes (without it the command only reports what it would do)}
        {--refresh-names : also overwrite the names of places that already exist (administrator edits to names are kept otherwise)}
        {--regions= : regions data file (default: the one shipped with the application)}
        {--cities= : cities data file (default: the one shipped with the application)}';

    protected $description = 'Load the region and city lists of the unified address from a data file (repeatable; never deletes)';

    public function handle(AddressImporter $importer): int
    {
        if (!AddressSchema::mastersReady()) {
            $this->line('The address migrations have not been run yet; nothing to do.');

            return self::SUCCESS;
        }

        try {
            $report = $importer->import(
                regionsPath: $this->option('regions') ?: config('address.seed.regions'),
                citiesPath: $this->option('cities') ?: config('address.seed.cities'),
                apply: (bool) $this->option('apply'),
                refreshNames: (bool) $this->option('refresh-names'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line($report['applied'] ? 'Applied.' : 'Dry run: nothing was written. Run again with --apply to write these changes.');
        foreach (['regions', 'cities'] as $kind) {
            $this->line(sprintf('%s: %s', $kind, json_encode($report[$kind])));
        }
        foreach (array_slice($report['problems'], 0, 20) as $problem) {
            $this->warn($problem);
        }
        if (count($report['problems']) > 20) {
            $this->warn('... and ' . (count($report['problems']) - 20) . ' more.');
        }

        return self::SUCCESS;
    }
}
