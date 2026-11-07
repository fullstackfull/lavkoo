<?php

namespace App\Console\Commands;

use App\Services\Address\AddressSchema;
use App\Services\Address\SplLookupsClient;
use App\Services\Address\SplLookupsConverter;
use App\Services\Address\SplLookupsException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Reads the regions and cities of Saudi Arabia from the official source, Saudi Post's (SPL) National Address Lookups API, with the store
 * owner's own API key, writes them as the three data files `address:import` takes, and imports them. Nothing from the source is kept in the
 * software package: the store reads its own copy under its own subscription.
 */
class AddressFetchSplCommand extends Command
{
    protected $signature = 'address:fetch-spl
        {--key= : the API key (default: the SPL_API_KEY environment variable); it is never printed or written}
        {--output= : the directory for the three data files (default: storage/app/address/spl)}
        {--pause=13 : seconds between calls (the free Starter plan allows 5 a minute and 100 a week; a full read is 28 calls)}
        {--apply : write the lists into the store (without it the files are written and an import is only shown as a dry run)}';

    protected $description = "Read the regions and cities of Saudi Arabia from Saudi Post's National Address Lookups API and import them (GET requests only)";

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $apiKey = trim((string) ($this->option('key') ?: getenv('SPL_API_KEY') ?: config('address.spl.api_key')));
        if ($apiKey === '') {
            $this->error('No API key: pass --key or set SPL_API_KEY. The key is issued free by Saudi Post (SPL) when you register on the National Address API portal.');

            return self::FAILURE;
        }

        if ($apply && !AddressSchema::mastersReady()) {
            $this->error('The address migrations have not been run yet, so there is nowhere to import into. Run them first; nothing was requested from Saudi Post.');

            return self::FAILURE;
        }

        $directory = rtrim((string) ($this->option('output') ?: config('address.spl.directory')), '/');
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            $this->error("The directory {$directory} cannot be created.");

            return self::FAILURE;
        }

        $client = new SplLookupsClient(baseUrl: (string) config('address.spl.base_url'), pauseSeconds: max(0, (int) $this->option('pause')));

        try {
            $read = $client->read($apiKey, fn (string $what) => $this->line("  reading {$what}"));
            $converted = (new SplLookupsConverter())->convert(read: $read, curatedAliases: $this->curatedAliases(), date: Carbon::now()->toDateString());
        } catch (SplLookupsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $paths = [];
        foreach (['regions', 'cities', 'aliases'] as $kind) {
            $paths[$kind] = "{$directory}/{$kind}.json";
            $this->write($paths[$kind], $converted[$kind]);
        }

        $report = $converted['report'];
        $this->info(sprintf('Read %d regions and %d places in %d calls; files written to %s.', $report['regions'], $report['cities'], $read['calls'], $directory));
        $this->line(sprintf('  curated spellings kept: %d%s', $report['aliases'], $report['aliases_skipped'] === [] ? '' : '; left out because the service does not list the expected city: ' . implode(', ', $report['aliases_skipped'])));
        if ($report['english_name_missing'] + $report['arabic_name_missing'] > 0) {
            $this->line(sprintf('  names the service left blank, filled with the other language: %d English, %d Arabic', $report['english_name_missing'], $report['arabic_name_missing']));
        }

        if (!AddressSchema::mastersReady()) {
            $this->warn('The address migrations have not been run yet: the files are ready, import them after the migrations with address:import --regions --cities --aliases.');

            return self::SUCCESS;
        }

        return $this->call('address:import', array_filter([
            '--regions' => $paths['regions'],
            '--cities' => $paths['cities'],
            '--aliases' => $paths['aliases'],
            '--apply' => $apply ?: null,
        ]));
    }

    /** @return list<array{alias: string, city_ref: string, city: string}> */
    private function curatedAliases(): array
    {
        $path = (string) config('address.seed.aliases');
        $document = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($document['aliases'] ?? null) ? $document['aliases'] : [];
    }

    /** @param array<string, mixed> $document */
    private function write(string $path, array $document): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($temporary, json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        chmod($temporary, 0640);
        rename($temporary, $path);
    }
}
