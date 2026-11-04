<?php

namespace App\Console\Commands;

use App\Services\DeveloperPortal\ApiManifest;
use Illuminate\Console\Command;

/**
 * Rebuild the API manifest the Developer Portal reads.
 *
 * The manifest rebuilds itself whenever the route table changes, so this is not needed for
 * correctness — it is here so a deployment can warm the cache before the first developer opens the
 * portal, rather than making that person wait for the reflection pass.
 */
class ApiManifestCommand extends Command
{
    protected $signature = 'api:manifest
                            {--refresh : Discard the cached manifest and rebuild it}
                            {--json= : Write the manifest to this path as JSON}';

    protected $description = 'Build and inspect the normalised API manifest';

    public function handle(ApiManifest $manifest): int
    {
        if ($this->option('refresh')) {
            $manifest->forget();
        }

        $started = microtime(true);
        $built = $manifest->get();
        $elapsed = round((microtime(true) - $started) * 1000);

        $summary = $built['summary'];

        $this->info("API manifest built in {$elapsed} ms.");
        $this->table(['Metric', 'Value'], [
            ['Endpoints (API)', $summary['api']],
            ['Endpoints (panel)', $summary['panel']],
            ['Public', $summary['public']],
            ['Authenticated', $summary['authenticated']],
            ['Rate limited', $summary['rate_limited']],
            ['With a request schema', $summary['with_body_schema']],
            ['Documented by hand', $summary['documented']],
            ['Deprecated', $summary['deprecated']],
            ['Unclassified', $summary['unclassified']],
        ]);

        $this->line('By audience: ' . collect($summary['by_audience'])->map(fn ($count, $key) => "{$key}={$count}")->implode(', '));
        $this->line('By version:  ' . collect($summary['by_version'])->map(fn ($count, $key) => "{$key}={$count}")->implode(', '));

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode($built, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Written to {$path}.");
        }

        return self::SUCCESS;
    }
}
