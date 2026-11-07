<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilAddressMigrated;
use App\Services\Address\LegacyBackfill;
use Illuminate\Console\Command;

class AddressBackfillLegacyCommand extends Command
{
    use SkipsUntilAddressMigrated;

    private const LABELS = [
        'matched' => 'Matched (one city, confirmed)',
        'ambiguous' => 'Ambiguous (several cities share the name)',
        'conflict' => 'Conflict (the state text points at another region)',
        'none' => 'No city of the list',
        'inactive' => 'Matched a city that is switched off',
    ];

    protected $signature = 'address:backfill-legacy {--apply : write the region and city of the certain matches (without it the command only reports what it would do)}';

    protected $description = 'Give old Saudi addresses their region and city where the typed city names exactly one city (dry run by default; never touches the typed text, the ZIP or the National Address)';

    public function handle(LegacyBackfill $backfill): int
    {
        if ($this->addressNotMigratedYet()) {
            return self::SUCCESS;
        }

        $report = $backfill->run(apply: (bool) $this->option('apply'));

        $this->line($report['applied'] ? 'Applied.' : 'Dry run: nothing was written. Run again with --apply to write the matches.');
        $this->table(
            ['Outcome', 'Addresses', 'Distinct typed texts'],
            array_map(static fn (string $status): array => [self::LABELS[$status], $report['rows'][$status], $report['texts'][$status]], LegacyBackfill::STATUSES),
        );

        if ($report['applied']) {
            $this->line(sprintf('Addresses given a region and city: %d.', $report['updated']));
        } elseif ($report['rows']['matched'] > 0) {
            $this->line(sprintf('%d address(es) would be given a region and city.', $report['rows']['matched']));
        }

        return self::SUCCESS;
    }
}
