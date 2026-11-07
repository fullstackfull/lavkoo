<?php

namespace App\Console\Commands;

use App\Services\SalesChannels\Support\PlatformSchemaCheck;
use Illuminate\Console\Command;

class SalesChannelsCheckPlatformSchemaCommand extends Command
{
    protected $signature = 'channels:check-platform-schema {--json : print the report as JSON}';

    protected $description = 'Check that the platform\'s own tables (orders, order_details, products, provider tables) have the columns the sales-channel module reads and writes. Read-only; run it on a copy of the real database first.';

    public function handle(PlatformSchemaCheck $check): int
    {
        $report = $check->run();
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT));

            return PlatformSchemaCheck::passes($report) ? self::SUCCESS : self::FAILURE;
        }

        foreach ($report as $table => $row) {
            $problems = array_filter([
                $row['exists'] ? null : 'the table does not exist',
                $row['exists'] && $row['missing'] !== [] ? 'columns missing: ' . implode(', ', $row['missing']) : null,
                $row['required_unfilled'] !== [] ? 'columns the table insists on and the dispatcher does not fill: ' . implode(', ', $row['required_unfilled']) : null,
            ]);
            $problems === [] ? $this->info("{$table}: ok") : $this->warn("{$table}: " . implode('; ', $problems));
            if ($row['not_written'] !== []) {
                $this->line("  {$table}: the dispatcher will not fill (the table has no such column): " . implode(', ', $row['not_written']));
            }
        }

        return PlatformSchemaCheck::passes($report) ? self::SUCCESS : self::FAILURE;
    }
}
