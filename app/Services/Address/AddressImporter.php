<?php

namespace App\Services\Address;

use App\Models\AddressCity;
use App\Models\AddressRegion;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Loads a region and city data file into the master lists, repeatably and without ever deleting anything.
 *
 * A file is `{"_meta": {"source": ..., "snapshot_date": ...}, "regions": [...]}` or `{"_meta": ..., "cities": [...]}`. Every row keeps
 * the id the source gave it (`ref`), so running the same file again changes nothing and a newer file updates the same rows. An
 * administrator's choices survive an import: whether a place is active, and (unless names are explicitly refreshed) what it is called.
 * Places that a newer file no longer lists are reported, never removed — an address or an order may point at them.
 */
class AddressImporter
{
    private const CHUNK = 500;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * @return array{regions: array<string, int>, cities: array<string, int>, problems: list<string>, applied: bool}
     */
    public function import(?string $regionsPath, ?string $citiesPath, bool $apply, bool $refreshNames = false): array
    {
        $report = ['regions' => $this->emptyCounts(), 'cities' => $this->emptyCounts(), 'problems' => [], 'applied' => $apply];

        $regionsFile = $regionsPath === null ? null : $this->read($regionsPath, 'regions');
        $citiesFile = $citiesPath === null ? null : $this->read($citiesPath, 'cities');

        $run = function () use (&$report, $regionsFile, $citiesFile, $apply, $refreshNames): void {
            if ($regionsFile !== null) {
                $report['regions'] = $this->importRegions($regionsFile, $apply, $refreshNames);
            }
            if ($citiesFile !== null) {
                $announcedRegions = array_map(static fn (array $row): string => (string) ($row['ref'] ?? ''), $regionsFile['regions'] ?? []);
                [$report['cities'], $problems] = $this->importCities($citiesFile, $apply, $refreshNames, $announcedRegions);
                array_push($report['problems'], ...$problems);
            }
        };

        $apply ? DB::transaction($run) : $run();

        if ($apply) {
            $this->audit->record(action: 'address.imported', after: ['regions' => $report['regions'], 'cities' => $report['cities']], context: [
                'regions_source' => $regionsFile['_meta']['source'] ?? null,
                'cities_source' => $citiesFile['_meta']['source'] ?? null,
            ]);
            AddressSchema::flush();
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private function read(string $path, string $listKey): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException("The file {$path} cannot be read.");
        }

        $document = json_decode((string) file_get_contents($path), true);
        $source = is_array($document) ? ($document['_meta']['source'] ?? null) : null;
        if (!is_array($document) || !is_array($document[$listKey] ?? null) || !is_string($source) || $source === '' || mb_strlen($source) > 40) {
            throw new InvalidArgumentException("{$path} is not a {$listKey} data file: it needs a `_meta.source` of at most 40 characters and a `{$listKey}` list.");
        }

        return $document;
    }

    /** @return array<string, int> */
    private function importRegions(array $file, bool $apply, bool $refreshNames): array
    {
        $counts = $this->emptyCounts();
        $source = (string) $file['_meta']['source'];
        $snapshot = $this->snapshotDate($file);
        $now = now();

        foreach ($file['regions'] as $row) {
            $ref = (string) ($row['ref'] ?? '');
            $code = (string) ($row['code'] ?? '');
            $nameAr = $this->name($row['name_ar'] ?? null);
            $nameEn = $this->name($row['name_en'] ?? null);
            if ($ref === '' || $code === '' || $nameAr === '' || $nameEn === '') {
                $counts['skipped']++;

                continue;
            }

            $existing = AddressRegion::query()->where('source', $source)->where('source_ref', $ref)->first()
                ?? AddressRegion::query()->where('country_code', 'SA')->where('code', $code)->first();

            if ($existing === null) {
                $counts['created']++;
                if ($apply) {
                    AddressRegion::create([
                        'country_code' => 'SA', 'code' => $code, 'name_ar' => $nameAr, 'name_en' => $nameEn,
                        'normalized_ar' => AddressText::key($nameAr), 'normalized_en' => AddressText::key($nameEn),
                        'sort_order' => (int) ($row['sort'] ?? 0), 'is_active' => true,
                        'source' => $source, 'source_ref' => $ref, 'source_updated_at' => $snapshot ?? $now,
                    ]);
                }

                continue;
            }

            $changes = ['source' => $source, 'source_ref' => $ref, 'source_updated_at' => $snapshot ?? $existing->source_updated_at];
            if ($refreshNames) {
                $changes += ['name_ar' => $nameAr, 'name_en' => $nameEn, 'normalized_ar' => AddressText::key($nameAr), 'normalized_en' => AddressText::key($nameEn)];
            }
            $existing->fill($changes);
            if ($existing->isDirty()) {
                $counts['updated']++;
                if ($apply) {
                    $existing->save();
                }
            } else {
                $counts['unchanged']++;
            }
        }

        return $counts;
    }

    /**
     * @param list<string> $announcedRegions region refs the regions file of the same run lists: on a dry run they do not exist yet
     * @return array{0: array<string, int>, 1: list<string>}
     */
    private function importCities(array $file, bool $apply, bool $refreshNames, array $announcedRegions = []): array
    {
        $counts = $this->emptyCounts();
        $problems = [];
        $source = (string) $file['_meta']['source'];
        $snapshot = $this->snapshotDate($file);
        $now = now();

        $regionIds = AddressRegion::query()->pluck('id', 'source_ref')->all();
        $existing = AddressCity::query()->where('source', $source)->get()->keyBy('source_ref');
        $seen = [];
        $batch = [];

        foreach ($file['cities'] as $position => $row) {
            $ref = (string) ($row['ref'] ?? '');
            $nameAr = $this->name($row['ar'] ?? null);
            $nameEn = $this->name($row['en'] ?? null);
            $regionRef = (string) ($row['region'] ?? '');
            $regionId = $regionIds[$regionRef] ?? (!$apply && in_array($regionRef, $announcedRegions, true) ? 0 : null);

            if ($ref === '' || $nameAr === '' || $nameEn === '' || isset($seen[$ref])) {
                $counts['skipped']++;
                $problems[] = "row {$position}: " . ($ref === '' ? 'no ref' : (isset($seen[$ref]) ? "ref {$ref} repeated" : "ref {$ref} has no name"));

                continue;
            }
            $seen[$ref] = true;
            if ($regionId === null) {
                $counts['skipped']++;
                $problems[] = "ref {$ref}: region " . ($row['region'] ?? '?') . ' is not in the region list';

                continue;
            }

            $current = $existing->get($ref);
            if ($current === null) {
                $counts['created']++;
                $batch[] = [
                    'region_id' => $regionId, 'name_ar' => $nameAr, 'name_en' => $nameEn,
                    'normalized_ar' => AddressText::key($nameAr), 'normalized_en' => AddressText::key($nameEn),
                    'latitude' => $row['lat'] ?? null, 'longitude' => $row['lon'] ?? null, 'sort_order' => max(0, (int) ($row['rank'] ?? 0)), 'is_active' => true,
                    'source' => $source, 'source_ref' => $ref, 'source_updated_at' => $snapshot ?? $now,
                    'created_at' => $now, 'updated_at' => $now,
                ];
                if ($apply && count($batch) >= self::CHUNK) {
                    AddressCity::query()->insert($batch);
                    $batch = [];
                }

                continue;
            }

            $current->fill([
                'latitude' => $row['lat'] ?? null, 'longitude' => $row['lon'] ?? null,
                'sort_order' => max(0, (int) ($row['rank'] ?? 0)), 'source_updated_at' => $snapshot ?? $current->source_updated_at,
            ]);
            if ($refreshNames) {
                $current->fill(['name_ar' => $nameAr, 'name_en' => $nameEn, 'normalized_ar' => AddressText::key($nameAr), 'normalized_en' => AddressText::key($nameEn)]);
            }
            if ($current->isDirty()) {
                $counts['updated']++;
                if ($apply) {
                    $current->save();
                }
            } else {
                $counts['unchanged']++;
            }
        }

        if ($apply && $batch !== []) {
            AddressCity::query()->insert($batch);
        }

        $counts['not_in_file'] = $existing->keys()->diff(array_keys($seen))->count();

        return [$counts, $problems];
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        return ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'not_in_file' => 0];
    }

    private function name(mixed $value): string
    {
        return is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';
    }

    private function snapshotDate(array $file): ?Carbon
    {
        $date = $file['_meta']['snapshot_date'] ?? null;

        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? Carbon::parse($date) : null;
    }
}
