<?php

namespace App\Services\Address;

use App\Models\AddressCity;
use App\Models\AddressCityAlias;
use App\Models\AddressRegion;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Loads a region and city data file into the master lists, repeatably and without ever deleting anything.
 *
 * A file is `{"_meta": {"source": ..., "snapshot_date": ...}, "regions": [...]}` or `{"_meta": ..., "cities": [...]}`. Every row keeps
 * the id the source gave it (`ref`), so running the same file again changes nothing and a newer file updates the same rows: its
 * rank, coordinates and source date. An administrator's choices survive an import: whether a place is active, and (unless names are
 * explicitly refreshed) what it is called. Places that a newer file no longer lists are reported, never removed — an address or an
 * order may point at them.
 */
class AddressImporter
{
    private const CHUNK = 500;

    private const MAX_NAME_LENGTH = 191;

    private const MAX_REF_LENGTH = 64;

    private const MAX_CODE_LENGTH = 16;

    private const MAX_REGION_SORT = 65535;

    private const MAX_CITY_RANK = 4294967295;

    private const MAX_LATITUDE = 90;

    private const MAX_LONGITUDE = 180;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * @return array{regions: array<string, int>, cities: array<string, int>, aliases: array<string, int>, problems: list<string>, applied: bool}
     */
    public function import(?string $regionsPath, ?string $citiesPath, bool $apply, bool $refreshNames = false, ?string $aliasesPath = null): array
    {
        $report = ['regions' => $this->emptyCounts(), 'cities' => $this->emptyCounts(), 'aliases' => $this->emptyCounts(), 'problems' => [], 'applied' => $apply];

        $regionsFile = $regionsPath === null ? null : $this->read($regionsPath, 'regions');
        $citiesFile = $citiesPath === null ? null : $this->read($citiesPath, 'cities');
        $aliasesFile = $aliasesPath === null ? null : $this->read($aliasesPath, 'aliases');

        $run = function () use (&$report, $regionsFile, $citiesFile, $aliasesFile, $apply, $refreshNames): void {
            if ($regionsFile !== null) {
                [$report['regions'], $problems] = $this->importRegions($regionsFile, $apply, $refreshNames);
                array_push($report['problems'], ...$problems);
            }
            if ($citiesFile !== null) {
                $announcedRegions = array_map(self::refOf(...), $regionsFile['regions'] ?? []);
                [$report['cities'], $problems] = $this->importCities($citiesFile, $apply, $refreshNames, $announcedRegions);
                array_push($report['problems'], ...$problems);
            }
            if ($aliasesFile !== null) {
                $announcedCities = $citiesFile !== null && ($citiesFile['_meta']['source'] ?? null) === ($aliasesFile['_meta']['city_source'] ?? null)
                    ? array_map(self::refOf(...), $citiesFile['cities'])
                    : [];
                [$report['aliases'], $problems] = $this->importAliases($aliasesFile, $apply, $announcedCities);
                array_push($report['problems'], ...$problems);
            }
        };

        $apply ? DB::transaction($run) : $run();

        if ($apply) {
            $this->audit->record(action: 'address.imported', after: ['regions' => $report['regions'], 'cities' => $report['cities'], 'aliases' => $report['aliases']], context: [
                'regions_source' => $regionsFile['_meta']['source'] ?? null,
                'cities_source' => $citiesFile['_meta']['source'] ?? null,
                'aliases_source' => $aliasesFile['_meta']['source'] ?? null,
            ]);
            AddressSchema::flush();
        }

        return $report;
    }

    /** The ref a row announces, or an empty one for a row that is not an object or whose ref is not a plain value: the row itself is reported later. */
    private static function refOf(mixed $row): string
    {
        return is_array($row) && is_scalar($row['ref'] ?? null) ? (string) $row['ref'] : '';
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

    /** @return array{0: array<string, int>, 1: list<string>} */
    private function importRegions(array $file, bool $apply, bool $refreshNames): array
    {
        $counts = $this->emptyCounts();
        $problems = [];
        $source = (string) $file['_meta']['source'];
        $snapshot = $this->snapshotDate($file);
        $now = now();

        foreach ($file['regions'] as $position => $row) {
            $problem = $this->regionProblem($row);
            if ($problem !== null) {
                $counts['skipped']++;
                $problems[] = "region row {$position}: {$problem}";

                continue;
            }

            $ref = (string) $row['ref'];
            $code = (string) $row['code'];
            $nameAr = $this->name($row['name_ar']);
            $nameEn = $this->name($row['name_en']);

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

        return [$counts, $problems];
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
            $problem = $this->cityProblem($row);
            if ($problem === null && isset($seen[(string) $row['ref']])) {
                $problem = "ref {$row['ref']} repeated";
            }
            if ($problem !== null) {
                $counts['skipped']++;
                $problems[] = "row {$position}: {$problem}";

                continue;
            }

            $ref = (string) $row['ref'];
            $nameAr = $this->name($row['ar']);
            $nameEn = $this->name($row['en']);
            $regionRef = (string) $row['region'];
            $regionId = $regionIds[$regionRef] ?? (!$apply && in_array($regionRef, $announcedRegions, true) ? 0 : null);
            $seen[$ref] = true;
            if ($regionId === null) {
                $counts['skipped']++;
                $problems[] = "ref {$ref}: region {$regionRef} is not in the region list";

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

    /**
     * Curated alternative spellings. An alias an administrator already pointed at another city is never overridden.
     *
     * @param list<string> $announcedCities refs of the cities file of the same run: on a dry run they do not exist yet
     * @return array{0: array<string, int>, 1: list<string>}
     */
    private function importAliases(array $file, bool $apply, array $announcedCities): array
    {
        $counts = $this->emptyCounts();
        $problems = [];
        $citySource = (string) ($file['_meta']['city_source'] ?? '');
        $cityIds = AddressCity::query()->where('source', $citySource)->pluck('id', 'source_ref')->all();
        $plannedCityRefs = [];

        foreach ($file['aliases'] as $row) {
            $alias = $this->name($row['alias'] ?? null);
            $key = AddressText::key($alias);
            $cityRef = (string) ($row['city_ref'] ?? '');
            $cityId = $cityIds[$cityRef] ?? (!$apply && in_array($cityRef, $announcedCities, true) ? 0 : null);

            if ($key === '' || $cityId === null) {
                $counts['skipped']++;
                $problems[] = $key === '' ? 'alias without a name' : "alias {$alias}: city {$cityRef} is not in the city list";

                continue;
            }

            $existing = AddressCityAlias::query()->where('normalized_alias', $key)->first();
            if ($existing === null && !array_key_exists($key, $plannedCityRefs)) {
                $counts['created']++;
                if ($apply) {
                    AddressCityAlias::create(['city_id' => $cityId, 'alias' => $alias, 'normalized_alias' => $key, 'source' => 'curated']);
                } else {
                    $plannedCityRefs[$key] = $cityRef;
                }
            } elseif ($existing !== null ? $existing->city_id === $cityId : $plannedCityRefs[$key] === $cityRef) {
                $counts['unchanged']++;
            } else {
                $counts['skipped']++;
                $problems[] = "alias {$alias}: already points at another city, kept";
            }
        }

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

    /** What keeps a row of the regions file from being stored (the same answer for a dry run and for the import), or null when it can be. */
    private function regionProblem(mixed $row): ?string
    {
        if (!is_array($row)) {
            return 'not an object';
        }

        return $this->identifierProblem(value: $row['ref'] ?? null, label: 'ref', maxLength: self::MAX_REF_LENGTH)
            ?? $this->identifierProblem(value: $row['code'] ?? null, label: 'code', maxLength: self::MAX_CODE_LENGTH)
            ?? $this->nameProblem($row['name_ar'] ?? null, 'name_ar')
            ?? $this->nameProblem($row['name_en'] ?? null, 'name_en')
            ?? $this->numberProblem(value: $row['sort'] ?? null, label: 'sort', max: self::MAX_REGION_SORT);
    }

    /** What keeps a row of the cities file from being stored, or null when it can be. */
    private function cityProblem(mixed $row): ?string
    {
        if (!is_array($row)) {
            return 'not an object';
        }

        return $this->identifierProblem(value: $row['ref'] ?? null, label: 'ref', maxLength: self::MAX_REF_LENGTH)
            ?? $this->identifierProblem(value: $row['region'] ?? null, label: 'region', maxLength: self::MAX_REF_LENGTH)
            ?? $this->nameProblem($row['ar'] ?? null, 'ar')
            ?? $this->nameProblem($row['en'] ?? null, 'en')
            ?? $this->numberProblem(value: $row['lat'] ?? null, label: 'lat', max: self::MAX_LATITUDE, min: -self::MAX_LATITUDE)
            ?? $this->numberProblem(value: $row['lon'] ?? null, label: 'lon', max: self::MAX_LONGITUDE, min: -self::MAX_LONGITUDE)
            ?? $this->numberProblem(value: $row['rank'] ?? null, label: 'rank', max: self::MAX_CITY_RANK);
    }

    private function identifierProblem(mixed $value, string $label, int $maxLength): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return "no {$label}";
        }

        $text = (string) $value;

        return $text === '' || mb_strlen($text) > $maxLength ? "{$label} is empty or longer than {$maxLength} characters" : null;
    }

    private function nameProblem(mixed $value, string $label): ?string
    {
        $name = $this->name($value);

        return $name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH || AddressText::key($name) === ''
            ? "{$label} is empty, longer than " . self::MAX_NAME_LENGTH . ' characters, or has no letter or digit'
            : null;
    }

    /** A number a column can hold; a value that is absent (null) is fine. */
    private function numberProblem(mixed $value, string $label, float|int $max, float|int $min = 0): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return "{$label} is not a number";
        }

        return $value < $min || $value > $max ? "{$label} is outside {$min} to {$max}" : null;
    }

    private function snapshotDate(array $file): ?Carbon
    {
        $date = $file['_meta']['snapshot_date'] ?? null;

        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? Carbon::parse($date) : null;
    }
}
