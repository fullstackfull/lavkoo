<?php

namespace App\Services\Address;

/**
 * Turns what SplLookupsClient read into the three data files `address:import` takes (regions, cities, curated aliases).
 *
 * It refuses, rather than guesses, whenever the answer is not the one the importer was written against: a region that is not one of the 13
 * (by id and by name), a city listed under two regions, a list far too short to be the whole country. The ISO 3166-2 code of a region is
 * a fact about the region and is looked up by the id the service gives; the name check makes sure the id means what it is taken to mean.
 *
 * The service gives no district count and no coordinates, so `rank` (what orders the places a picker offers first, and what tells a
 * city proper from a hamlet when an old typed city is matched) is: 1000 for the cities the curated spellings are about, 1 for the seat
 * of a governorate (a city whose name is the governorate's), 0 for the rest.
 */
final class SplLookupsConverter
{
    public const SOURCE = 'spl_lookups_v3_1';

    private const MINIMUM_CITIES = 1000;

    private const MAJOR_CITY_RANK = 1000;

    private const GOVERNORATE_SEAT_RANK = 1;

    /** SPL region id => ISO 3166-2 code, and the words (either language) that the name of that region must contain. */
    private const REGIONS = [
        '1' => ['code' => 'SA-01', 'names' => ['riyadh', 'الرياض']],
        '2' => ['code' => 'SA-02', 'names' => ['makkah', 'mecca', 'مكة']],
        '3' => ['code' => 'SA-03', 'names' => ['madinah', 'medina', 'madina', 'المدينة']],
        '4' => ['code' => 'SA-05', 'names' => ['qassim', 'qasim', 'qaseem', 'القصيم']],
        '5' => ['code' => 'SA-04', 'names' => ['eastern', 'sharqiyah', 'sharqiyyah', 'الشرقية']],
        '6' => ['code' => 'SA-14', 'names' => ['asir', 'aseer', 'عسير']],
        '7' => ['code' => 'SA-07', 'names' => ['tabuk', 'tabouk', 'تبوك']],
        '8' => ['code' => 'SA-06', 'names' => ['hail', 'hayil', 'حائل']],
        '9' => ['code' => 'SA-08', 'names' => ['northern', 'shamaliyah', 'الحدود الشمالية']],
        '10' => ['code' => 'SA-09', 'names' => ['jazan', 'jizan', 'gizan', 'جازان']],
        '11' => ['code' => 'SA-10', 'names' => ['najran', 'نجران']],
        '12' => ['code' => 'SA-11', 'names' => ['bahah', 'baha', 'الباحة']],
        '13' => ['code' => 'SA-12', 'names' => ['jawf', 'jouf', 'الجوف']],
    ];

    /**
     * @param array{regions: array<string, list<array{Id: string, Name: string, GovernorateName: ?string}>>, cities: array<string, array<string, list<array{Id: string, Name: string, GovernorateName: ?string}>>>} $read
     * @param list<array{alias: string, city_ref: string, city: string}> $curatedAliases
     * @return array{regions: array<string, mixed>, cities: array<string, mixed>, aliases: array<string, mixed>, report: array<string, mixed>}
     */
    public function convert(array $read, array $curatedAliases, string $date): array
    {
        $regions = $this->regions($read['regions']);
        [$cities, $missingEnglish, $missingArabic] = $this->cities($read, $this->majorCityRefs($curatedAliases));
        [$aliases, $skippedAliases] = $this->aliases($curatedAliases, $cities);

        $meta = [
            'source' => self::SOURCE,
            'origin' => 'Saudi Post (SPL), National Address Lookups API v3.1: https://apina.address.gov.sa/NationalAddress/v3.1/lookup',
            'origin_licence' => "Read by the store owner with the owner's own API key; the terms are the API Terms of use of Saudi Post (SPL) accepted when the key was issued. This file is a working copy for the store's database and is not part of the software package.",
            'snapshot_date' => $date,
            'prepared' => $date,
        ];

        return [
            'regions' => ['_meta' => $meta + ['description' => 'The 13 administrative regions with their ISO 3166-2:SA codes.', 'rows' => count($regions)], 'regions' => $regions],
            'cities' => ['_meta' => $meta + ['description' => 'Every city, town and village of the National Address register, by region. `rank` is 1000 for the cities the curated spellings are about, 1 for the seat of a governorate, 0 otherwise.', 'rows' => count($cities)], 'cities' => $cities],
            'aliases' => [
                '_meta' => ['source' => 'curated_aliases', 'city_source' => self::SOURCE, 'description' => 'Well-known alternative spellings of the largest places, kept only for the cities the service lists under the expected name.', 'prepared' => $date, 'rows' => count($aliases)],
                'aliases' => $aliases,
            ],
            'report' => ['regions' => count($regions), 'cities' => count($cities), 'english_name_missing' => $missingEnglish, 'arabic_name_missing' => $missingArabic, 'aliases' => count($aliases), 'aliases_skipped' => $skippedAliases],
        ];
    }

    /**
     * @param array<string, list<array{Id: string, Name: string, GovernorateName: ?string}>> $lists
     * @return list<array{ref: string, code: string, name_ar: string, name_en: string, sort: int}>
     */
    private function regions(array $lists): array
    {
        $english = $this->indexed($lists['E'] ?? []);
        $rows = [];
        foreach ($lists['A'] ?? [] as $region) {
            $known = self::REGIONS[$region['Id']] ?? throw new SplLookupsException("Saudi Post lists a region (Id {$region['Id']}) that this importer does not know. Nothing was written.");
            $nameEnglish = $english[$region['Id']]['Name'] ?? '';
            if (!$this->namesTheRegion(arabic: $region['Name'], english: $nameEnglish, words: $known['names'])) {
                throw new SplLookupsException("Region {$region['Id']} is called \"{$region['Name']}\" / \"{$nameEnglish}\" by Saudi Post, which is not the region this importer takes it for ({$known['code']}). Nothing was written.");
            }
            $rows[] = ['ref' => $region['Id'], 'code' => $known['code'], 'name_ar' => $region['Name'], 'name_en' => $nameEnglish, 'sort' => (int) $region['Id']];
        }

        $missing = array_diff(array_keys(self::REGIONS), array_column($rows, 'ref'));
        if ($missing !== []) {
            throw new SplLookupsException('Saudi Post did not list region(s) ' . implode(', ', $missing) . '. Nothing was written.');
        }

        return $rows;
    }

    /** @param list<string> $words */
    private function namesTheRegion(string $arabic, string $english, array $words): bool
    {
        foreach ($words as $word) {
            $wanted = AddressText::key($word);
            foreach ([$arabic, $english] as $name) {
                if ($wanted !== '' && str_contains(AddressText::key($name), $wanted)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array{regions: array<string, list<array{Id: string, Name: string, GovernorateName: ?string}>>, cities: array<string, array<string, list<array{Id: string, Name: string, GovernorateName: ?string}>>>} $read
     * @param array<string, true> $majorCities
     * @return array{0: list<array{ref: string, region: string, ar: string, en: string, rank: int}>, 1: int, 2: int}
     */
    private function cities(array $read, array $majorCities): array
    {
        $rows = [];
        $regionOf = [];
        $missingEnglish = 0;
        $missingArabic = 0;

        foreach ($read['regions']['A'] as $region) {
            $arabic = $this->indexed($read['cities'][$region['Id']]['A'] ?? []);
            $english = $this->indexed($read['cities'][$region['Id']]['E'] ?? []);

            foreach (array_unique([...array_keys($arabic), ...array_keys($english)]) as $key) {
                $id = (string) $key;
                $nameArabic = $arabic[$id]['Name'] ?? '';
                $nameEnglish = $english[$id]['Name'] ?? '';
                if ($nameArabic === '' && $nameEnglish === '') {
                    continue;
                }
                if (isset($regionOf[$id])) {
                    throw new SplLookupsException("City {$id} is listed under regions {$regionOf[$id]} and {$region['Id']}: the ids of Saudi Post are not unique. Nothing was written.");
                }
                $regionOf[$id] = $region['Id'];

                $missingEnglish += $nameEnglish === '' ? 1 : 0;
                $missingArabic += $nameArabic === '' ? 1 : 0;
                $nameEnglish = $nameEnglish === '' ? $nameArabic : $nameEnglish;
                $nameArabic = $nameArabic === '' ? $nameEnglish : $nameArabic;

                $rows[] = ['ref' => $id, 'region' => $region['Id'], 'ar' => $nameArabic, 'en' => $nameEnglish, 'rank' => $this->rank($id, $arabic[$id] ?? null, $majorCities)];
            }
        }

        if (count($rows) < self::MINIMUM_CITIES) {
            throw new SplLookupsException('Saudi Post listed only ' . count($rows) . ' places; the whole country has several thousand. The answer is incomplete, so nothing was written.');
        }

        return [$rows, $missingEnglish, $missingArabic];
    }

    /** @param array{Id: string, Name: string, GovernorateName: ?string}|null $arabicRow */
    private function rank(string $id, ?array $arabicRow, array $majorCities): int
    {
        if (isset($majorCities[$id])) {
            return self::MAJOR_CITY_RANK;
        }

        $governorate = AddressText::key($arabicRow['GovernorateName'] ?? null);

        return $arabicRow !== null && $governorate !== '' && $governorate === AddressText::key($arabicRow['Name']) ? self::GOVERNORATE_SEAT_RANK : 0;
    }

    /**
     * @param list<array{alias: string, city_ref: string, city: string}> $curated
     * @param list<array{ref: string, region: string, ar: string, en: string, rank: int}> $cities
     * @return array{0: list<array{alias: string, city_ref: string, city: string}>, 1: list<string>}
     */
    private function aliases(array $curated, array $cities): array
    {
        $byRef = [];
        foreach ($cities as $city) {
            $byRef[$city['ref']] = $city;
        }

        $groups = [];
        foreach ($curated as $row) {
            $groups[(string) $row['city_ref']][] = $row;
        }

        $kept = [];
        $skipped = [];
        foreach ($groups as $ref => $rows) {
            $city = $byRef[$ref] ?? null;
            if ($city !== null && $this->confirms($city, $rows)) {
                array_push($kept, ...$rows);

                continue;
            }
            $skipped[] = "{$rows[0]['city']} (id {$ref})";
        }

        return [$kept, $skipped];
    }

    /**
     * The service lists the city a group of curated spellings is about when its English name is the one written in the curated file, or its Arabic
     * name is one of the spellings: either way the id is the id of that city and not of a namesake.
     *
     * @param array{ref: string, region: string, ar: string, en: string, rank: int} $city
     * @param list<array{alias: string, city_ref: string, city: string}> $rows
     */
    private function confirms(array $city, array $rows): bool
    {
        if (AddressText::key($city['en']) === AddressText::key($rows[0]['city'])) {
            return true;
        }

        return in_array(AddressText::key($city['ar']), array_map(static fn (array $row): string => AddressText::key($row['alias']), $rows), true);
    }

    /**
     * @param list<array{alias: string, city_ref: string, city: string}> $curated
     * @return array<string, true>
     */
    private function majorCityRefs(array $curated): array
    {
        return array_fill_keys(array_map(static fn (array $row): string => (string) $row['city_ref'], $curated), true);
    }

    /**
     * @param list<array{Id: string, Name: string, GovernorateName: ?string}> $rows
     * @return array<string, array{Id: string, Name: string, GovernorateName: ?string}>
     */
    private function indexed(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['Id']] = $row;
        }

        return $indexed;
    }
}
