<?php

namespace App\Console\Commands;

use App\Services\Monitoring\Checks\SyntheticCheck;
use App\Services\Monitoring\EventLog;
use App\Services\Monitoring\Support\MonitoringSettings;
use Illuminate\Console\Command;

/**
 * Define the customer journeys SyntheticCheck fetches every five minutes.
 *
 * SyntheticCheck's "nothing configured" message used to send an operator to Monitoring → Settings
 * → Synthetic tests. That screen is read-only — it reports what monitoring is configured with, it
 * does not write settings — so the one instruction the check gave led nowhere, and the only way to
 * define a journey was an INSERT into monitoring_settings by hand.
 *
 * A journey is deliberately small: a name, a URL, the status it must return, optionally a phrase
 * the page must contain and a millisecond budget. The phrase is what turns "the server answered"
 * into "the shop works" — a 200 whose product grid is empty is the failure server metrics cannot
 * see.
 *
 *   php artisan monitoring:synthetic add "Home page" https://shop.example/ --contains="Add to cart"
 *   php artisan monitoring:synthetic list
 *   php artisan monitoring:synthetic remove "Home page"
 */
class MonitoringSynthetic extends Command
{
    protected $signature = 'monitoring:synthetic
                            {action=list : list, add or remove}
                            {name? : the journey name, for add and remove}
                            {url? : the URL to fetch, for add}
                            {--status=200 : the HTTP status the page must return}
                            {--contains= : a phrase the body must contain for the journey to pass}
                            {--max-ms= : flag the journey as degraded above this many milliseconds}
                            {--timeout=15 : seconds to wait before the fetch counts as failed}';

    protected $description = 'List, add or remove the synthetic journeys monitoring probes';

    /** The check itself stops at ten per run; refusing the eleventh here says so at the point of entry. */
    private const MAX_JOURNEYS = 10;

    public function handle(MonitoringSettings $settings, EventLog $events): int
    {
        $journeys = $this->stored($settings);

        return match ((string) $this->argument('action')) {
            'add' => $this->add($settings, $events, $journeys),
            'remove' => $this->remove($settings, $events, $journeys),
            'list' => $this->list($journeys),
            default => $this->invalidAction(),
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function stored(MonitoringSettings $settings): array
    {
        $stored = $settings->get('synthetics', []);

        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        return is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
    }

    /** @param  array<int, array<string, mixed>>  $journeys */
    private function add(MonitoringSettings $settings, EventLog $events, array $journeys): int
    {
        $name = trim((string) $this->argument('name'));
        $url = trim((string) $this->argument('url'));

        if ($name === '' || $url === '') {
            $this->error('Both a name and a URL are required: monitoring:synthetic add "Home page" https://shop.example/');

            return self::FAILURE;
        }

        if (!SyntheticCheck::isProbeable($url)) {
            // The same rule the check applies, applied here so the refusal names the reason rather
            // than the journey silently never running.
            $this->error('Only http(s) URLs can be probed, and never a cloud metadata address.');

            return self::FAILURE;
        }

        $journeys = array_values(array_filter($journeys, fn (array $journey): bool => ($journey['name'] ?? null) !== $name));

        if (count($journeys) >= self::MAX_JOURNEYS) {
            $this->error('There are already ' . self::MAX_JOURNEYS . ' journeys, which is all one run will probe. Remove one first.');

            return self::FAILURE;
        }

        $journey = array_filter([
            'name' => $name,
            'url' => $url,
            'expect_status' => (int) $this->option('status'),
            'expect_text' => $this->option('contains') !== null ? (string) $this->option('contains') : null,
            'max_ms' => $this->option('max-ms') !== null ? max(1, (int) $this->option('max-ms')) : null,
            'timeout' => max(1, (int) $this->option('timeout')),
        ], static fn ($value): bool => $value !== null && $value !== '');

        $journeys[] = $journey;
        $settings->put('synthetics', $journeys);

        $events->record(
            type: EventLog::CONFIG,
            severity: EventLog::INFO,
            title: 'Synthetic journey defined: ' . $name,
            key: 'synthetics',
            description: $url,
            context: ['expect_status' => $journey['expect_status'], 'journeys' => count($journeys)],
        );

        $this->info("Journey \"{$name}\" will be fetched on the next run of `php artisan monitoring:check`.");

        return self::SUCCESS;
    }

    /** @param  array<int, array<string, mixed>>  $journeys */
    private function remove(MonitoringSettings $settings, EventLog $events, array $journeys): int
    {
        $name = trim((string) $this->argument('name'));

        if ($name === '') {
            $this->error('Which journey? monitoring:synthetic remove "Home page"');

            return self::FAILURE;
        }

        $remaining = array_values(array_filter($journeys, fn (array $journey): bool => ($journey['name'] ?? null) !== $name));

        if (count($remaining) === count($journeys)) {
            $this->error("No journey is named \"{$name}\".");

            return self::FAILURE;
        }

        $settings->put('synthetics', $remaining);

        $events->record(
            type: EventLog::CONFIG,
            severity: EventLog::INFO,
            title: 'Synthetic journey removed: ' . $name,
            key: 'synthetics',
            context: ['journeys' => count($remaining)],
        );

        $this->info("Journey \"{$name}\" removed.");

        return self::SUCCESS;
    }

    /** @param  array<int, array<string, mixed>>  $journeys */
    private function list(array $journeys): int
    {
        if ($journeys === []) {
            $this->warn('No synthetic journey is defined, so nothing is being fetched.');
            $this->line('Add one:  php artisan monitoring:synthetic add "Home page" ' . rtrim(config('app.url'), '/') . '/ --contains="Add to cart"');

            return self::SUCCESS;
        }

        $this->table(
            ['name', 'url', 'expects', 'contains', 'budget'],
            array_map(static fn (array $journey): array => [
                $journey['name'] ?? '—',
                $journey['url'] ?? '—',
                'HTTP ' . ($journey['expect_status'] ?? 200),
                $journey['expect_text'] ?? '—',
                isset($journey['max_ms']) ? $journey['max_ms'] . ' ms' : '—',
            ], $journeys),
        );

        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error('Unknown action. Use list, add or remove.');

        return self::FAILURE;
    }
}
