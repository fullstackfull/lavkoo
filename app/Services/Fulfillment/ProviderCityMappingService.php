<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\CitySyncVerdict;
use App\Enums\Fulfillment\MappingStatus;
use App\Models\AddressCity;
use App\Models\AddressRegion;
use App\Models\ProviderCity;
use App\Models\ProviderCityMapping;
use App\Models\ProviderCitySyncRun;
use App\Services\Address\AddressSchema;
use App\Services\Address\AddressText;
use App\Services\AuditLogger;
use App\Services\Fulfillment\Data\CityCandidate;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * "For this provider, our city is that provider city" — decided by a person, or by the one case that needs no person.
 *
 * Works for any provider (keyed by the provider's code) and never lets an id of one provider reach another: every lookup names the
 * provider, and a mapping can only point at a city that provider's own list holds.
 *
 * A name that matches is a CANDIDATE, nothing more. Candidates are found by an exact comparison of place-name keys (never by
 * similarity); a person approves one. The only automatic approval is for a city with exactly one candidate, when the provider's
 * whole list was read, when nothing about the provider's region contradicts it, and when no person has ever decided on that city.
 * Everything else waits as `pending`, with the evidence a person needs to decide.
 *
 * One instance serves a whole request or console run, so the answers it remembers about the schema are asked once.
 */
#[Singleton]
class ProviderCityMappingService
{
    public const METHOD_EXACT_NAME = 'exact_name';

    public const METHOD_DISCOVERY = 'discovery';

    public const METHOD_MANUAL = 'manual';

    public const METHOD_AUTO_UNIQUE = 'auto_unique';

    private const INSERT_CHUNK = 250;

    private const DIRECTORY_COLUMNS = ['region_name_ar', 'region_name_en', 'prefix', 'is_selected', 'last_seen_at', 'missing_since'];

    private ?bool $directoryColumns = null;

    private ?bool $available = null;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Have the address and mapping tables been migrated? Until they have, a store behaves exactly as it did before.
     *
     * Asked afresh the first time this instance needs it, never taken from the answer AddressSchema keeps in a static: that one
     * survives from one test (or one job of a long-lived worker) to the next, and a stale "ready" would send the old city
     * lookups to tables that are not there.
     */
    public function available(): bool
    {
        if ($this->available === null) {
            AddressSchema::flush();
            $this->available = AddressSchema::mappingsReady() && AddressSchema::mastersReady();
        }

        return $this->available;
    }

    /** Does `provider_cities` have the columns the directory promotes out of the provider's records? */
    public function hasDirectoryColumns(): bool
    {
        return $this->directoryColumns ??= Schema::hasColumns('provider_cities', self::DIRECTORY_COLUMNS);
    }

    /** Can the city list be read completely and matched to our cities, with the review and the discovery that go with it? */
    public function directoryReady(): bool
    {
        return $this->available() && $this->hasDirectoryColumns();
    }

    public function approvedFor(string $provider, int $cityId): ?ProviderCityMapping
    {
        return ProviderCityMapping::query()->approved()->where('provider', $provider)->where('address_city_id', $cityId)->first();
    }

    /** @return list<string> the provider's ids of the candidates waiting for a person, smallest first */
    public function pendingExternalIds(string $provider, int $cityId): array
    {
        $ids = ProviderCityMapping::query()
            ->where('provider', $provider)
            ->where('address_city_id', $cityId)
            ->where('status', MappingStatus::Pending->value)
            ->pluck('external_city_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
        sort($ids, SORT_NATURAL);

        return $ids;
    }

    /** @return Collection<int, CityCandidate> */
    public function candidatesFor(string $provider, AddressCity $city): Collection
    {
        return $this->candidatesForMany(provider: $provider, cities: collect([$city]))[$city->id];
    }

    /**
     * @param Collection<int, AddressCity> $cities
     * @return array<int, Collection<int, CityCandidate>> by city id; every given city has an entry
     */
    public function candidatesForMany(string $provider, Collection $cities): array
    {
        if ($cities->isEmpty()) {
            return [];
        }

        $names = $this->providerNameIndex($provider);
        $regions = $this->regionIdsByKey();

        $candidates = [];
        foreach ($cities as $city) {
            $candidates[$city->id] = $this->candidatesAmong(index: $names, regionIdsByKey: $regions, city: $city);
        }

        return $candidates;
    }

    /**
     * Writes a `pending` mapping for every candidate of the given cities (default: every active city without an approved mapping) that has
     * no row yet. A candidate a person already rejected or retired is never proposed again.
     *
     * @param Collection<int, AddressCity>|null $cities
     * @return int the proposals written
     */
    public function proposeCandidates(string $provider, ?Collection $cities = null, string $method = self::METHOD_EXACT_NAME): int
    {
        $known = $this->mappingsByCity($provider);
        $cities = ($cities ?? AddressCity::query()->with('region')->where('is_active', true)->get())
            ->reject(fn (AddressCity $city): bool => $this->hasApproved($known->get($city->id)))
            ->keyBy('id');

        $rows = [];
        foreach ($this->candidatesForMany(provider: $provider, cities: $cities) as $cityId => $candidates) {
            $taken = ($known->get($cityId) ?? collect())->pluck('external_city_id')->map(static fn ($id): string => (string) $id)->all();
            $considered = $candidates->map(static fn (CityCandidate $candidate): string => (string) $candidate->city->external_id)->values()->all();
            foreach ($candidates as $candidate) {
                if (!in_array((string) $candidate->city->external_id, $taken, true)) {
                    $rows[] = $this->pendingRow(provider: $provider, city: $cities[$cityId], candidate: $candidate, method: $method, considered: $considered);
                }
            }
        }

        $written = 0;
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $written += ProviderCityMapping::insertOrIgnore($chunk);
        }

        return $written;
    }

    /**
     * Approves the mapping of one of our cities to one of the provider's, as a person's decision.
     *
     * Nothing is demoted silently: when the city already has an approved mapping to another provider city this refuses, unless the
     * caller names that mapping in `$replacing` (it is then retired in the same step and the audit trail says so).
     *
     * @throws InvalidArgumentException when the decision cannot be made, with a message fit for the administrator
     */
    public function approve(
        string $provider,
        int $cityId,
        string $externalCityId,
        ?int $adminId = null,
        ?string $adminName = null,
        string $method = self::METHOD_MANUAL,
        ?int $replacing = null,
    ): ProviderCityMapping {
        try {
            return DB::transaction(fn (): ProviderCityMapping => $this->approveWithin(provider: $provider, cityId: $cityId, externalCityId: $externalCityId, adminId: $adminId, adminName: $adminName, method: $method, replacing: $replacing));
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('Another mapping for this city was approved a moment ago. Reload the page and look at it first.');
        }
    }

    /** @throws InvalidArgumentException for an approved mapping: that one is retired, not rejected */
    public function reject(string $provider, int $cityId, string $externalCityId, ?int $adminId = null, ?string $adminName = null): ProviderCityMapping
    {
        return DB::transaction(function () use ($provider, $cityId, $externalCityId, $adminId, $adminName): ProviderCityMapping {
            $city = $this->cityOrFail($cityId);
            $providerCity = $this->providerCityOrFail(provider: $provider, externalCityId: $externalCityId);

            $mapping = $this->lockedRow(provider: $provider, cityId: $cityId, externalCityId: $externalCityId) ?? $this->newRow(provider: $provider, cityId: $cityId, externalCityId: $externalCityId);
            if ($mapping->isApproved()) {
                throw new InvalidArgumentException('An approved mapping is retired, not rejected.');
            }

            $before = $mapping->exists ? ['status' => $mapping->status?->value] : null;
            $mapping->forceFill([
                'status' => MappingStatus::Rejected,
                'approved_slot' => null,
                'external_region_id' => $providerCity->region_external_id,
                'evidence' => $this->withDecision(evidence: $mapping->evidence ?? $this->evidence(city: $city, providerCity: $providerCity, why: 'rejected_candidate'), decision: 'rejected', adminId: $adminId, adminName: $adminName),
            ])->save();

            $this->audit->record(
                action: 'provider_city_mapping.rejected',
                subject: ['type' => 'provider_city_mapping', 'id' => $mapping->id],
                before: $before,
                after: $this->auditSummary($mapping),
            );

            return $mapping;
        });
    }

    /** @throws InvalidArgumentException when the mapping is not an approved one */
    public function retire(int $mappingId, ?int $adminId = null, ?string $adminName = null): ProviderCityMapping
    {
        return DB::transaction(function () use ($mappingId, $adminId, $adminName): ProviderCityMapping {
            $mapping = ProviderCityMapping::query()->whereKey($mappingId)->lockForUpdate()->firstOrFail();
            if (!$mapping->isApproved()) {
                throw new InvalidArgumentException('Only an approved mapping can be retired.');
            }

            $this->retireRow(mapping: $mapping, adminId: $adminId, adminName: $adminName);

            return $mapping;
        });
    }

    /**
     * Approves, without a person, every city of ours that has exactly ONE candidate — when the provider's list was proven complete by its
     * last synchronisation (not merely long), the provider city is not wanted by another of our cities or already mapped to one, its region
     * (when it names one) does not contradict ours, and nobody ever approved, rejected or retired a mapping for that city.
     *
     * @return array{approved: int, skipped: ?string}
     */
    public function autoApproveUnique(string $provider): array
    {
        $run = $this->lastStoredRun($provider);
        if ($run?->verdict !== CitySyncVerdict::Full->value) {
            return ['approved' => 0, 'skipped' => 'sync_not_complete'];
        }
        if (!$run->completenessProven()) {
            return ['approved' => 0, 'skipped' => 'completeness_not_proven'];
        }

        $known = $this->mappingsByCity($provider);
        $cities = AddressCity::query()->with('region')->where('is_active', true)->get()
            ->reject(fn (AddressCity $city): bool => $this->hasPersonDecision($known->get($city->id)));
        $candidates = $this->candidatesForMany(provider: $provider, cities: $cities);

        $wanted = [];
        foreach ($candidates as $set) {
            foreach ($set as $candidate) {
                $wanted[(string) $candidate->city->external_id] = ($wanted[(string) $candidate->city->external_id] ?? 0) + 1;
            }
        }
        $alreadyMapped = $known->flatten(1)->filter(fn (ProviderCityMapping $row): bool => $row->isApproved())->pluck('external_city_id')->map(static fn ($id): string => (string) $id)->all();

        $inserts = [];
        $approved = 0;
        foreach ($cities as $city) {
            $only = $this->soleAutomaticCandidate(candidates: $candidates[$city->id], wanted: $wanted, alreadyMapped: $alreadyMapped);
            if ($only === null) {
                continue;
            }

            $pending = ($known->get($city->id) ?? collect())->first(static fn (ProviderCityMapping $row): bool => (string) $row->external_city_id === (string) $only->city->external_id);
            $evidence = $this->evidence(city: $city, providerCity: $only->city, why: 'unique_exact_name', extra: ['region_check' => $only->region, 'sync_run_id' => $run->id]);
            $fields = $this->approvedFields(method: self::METHOD_AUTO_UNIQUE, providerCity: $only->city, evidence: $evidence, adminId: null);
            if ($pending !== null) {
                $pending->forceFill($fields)->save();
                $approved++;
            } else {
                $inserts[] = $this->insertRow(provider: $provider, cityId: $city->id, externalCityId: (string) $only->city->external_id, fields: $fields);
            }
        }

        foreach (array_chunk($inserts, self::INSERT_CHUNK) as $chunk) {
            $approved += ProviderCityMapping::insertOrIgnore($chunk);
        }

        if ($approved > 0) {
            $this->audit->record(
                action: 'provider_city_mapping.auto_approved',
                subject: ['type' => 'provider_city_sync_run', 'id' => $run->id],
                after: ['provider' => $provider, 'approved' => $approved],
                context: ['method' => self::METHOD_AUTO_UNIQUE],
            );
        }

        return ['approved' => $approved, 'skipped' => null];
    }

    /** @return list<int> our cities with more than one candidate waiting and no approved mapping */
    public function conflictedCityIds(string $provider, int $limit = 50): array
    {
        return ProviderCityMapping::query()
            ->where('provider', $provider)
            ->where('status', MappingStatus::Pending->value)
            ->whereNotIn('address_city_id', ProviderCityMapping::query()->approved()->where('provider', $provider)->select('address_city_id'))
            ->groupBy('address_city_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('address_city_id')
            ->limit($limit)
            ->pluck('address_city_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function lastStoredRun(string $provider): ?ProviderCitySyncRun
    {
        return ProviderCitySyncRun::query()
            ->where('provider', $provider)
            ->whereIn('verdict', [CitySyncVerdict::Full->value, CitySyncVerdict::Partial->value])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param Collection<int, CityCandidate> $candidates
     * @param array<string, int> $wanted how many of our cities each provider city is a candidate of
     * @param list<string> $alreadyMapped provider cities that already carry an approved mapping
     */
    private function soleAutomaticCandidate(Collection $candidates, array $wanted, array $alreadyMapped): ?CityCandidate
    {
        if ($candidates->count() !== 1) {
            return null;
        }

        $only = $candidates->first();
        $id = (string) $only->city->external_id;

        return $only->canBeApprovedAutomatically() && ($wanted[$id] ?? 0) === 1 && !in_array($id, $alreadyMapped, true) ? $only : null;
    }

    private function approveWithin(string $provider, int $cityId, string $externalCityId, ?int $adminId, ?string $adminName, string $method, ?int $replacing): ProviderCityMapping
    {
        $city = $this->cityOrFail($cityId);
        $providerCity = $this->providerCityOrFail(provider: $provider, externalCityId: $externalCityId);
        if ($providerCity->missing_since !== null) {
            throw new InvalidArgumentException('The provider no longer lists this city.');
        }

        $rows = ProviderCityMapping::query()->where('provider', $provider)->where('address_city_id', $cityId)->lockForUpdate()->get();
        $approved = $rows->first(static fn (ProviderCityMapping $row): bool => $row->isApproved());
        if ($approved !== null && (string) $approved->external_city_id === $externalCityId) {
            return $approved;
        }
        if ($approved !== null) {
            if ($replacing !== $approved->id) {
                throw new InvalidArgumentException('This city already has an approved mapping. Retire it first, or replace it knowingly.');
            }
            $this->retireRow(mapping: $approved, adminId: $adminId, adminName: $adminName);
        }

        $mapping = $rows->first(static fn (ProviderCityMapping $row): bool => (string) $row->external_city_id === $externalCityId) ?? $this->newRow(provider: $provider, cityId: $cityId, externalCityId: $externalCityId);
        $before = $mapping->exists ? ['status' => $mapping->status?->value] : null;

        $evidence = $this->withDecision(
            evidence: $mapping->evidence ?? $this->evidence(city: $city, providerCity: $providerCity, why: $method === self::METHOD_MANUAL ? 'chosen_by_person' : $method),
            decision: 'approved',
            adminId: $adminId,
            adminName: $adminName,
        );
        $mapping->forceFill($this->approvedFields(method: $method, providerCity: $providerCity, evidence: $evidence, adminId: $adminId))->save();

        $this->audit->record(
            action: 'provider_city_mapping.approved',
            subject: ['type' => 'provider_city_mapping', 'id' => $mapping->id],
            before: $before,
            after: $this->auditSummary($mapping),
            context: array_filter(['replaced_mapping_id' => $replacing, 'approved_by_name' => $adminName]),
        );

        return $mapping;
    }

    private function retireRow(ProviderCityMapping $mapping, ?int $adminId, ?string $adminName): void
    {
        $mapping->forceFill([
            'status' => MappingStatus::Retired,
            'approved_slot' => null,
            'evidence' => $this->withDecision(evidence: $mapping->evidence ?? [], decision: 'retired', adminId: $adminId, adminName: $adminName),
        ])->save();

        $this->audit->record(
            action: 'provider_city_mapping.retired',
            subject: ['type' => 'provider_city_mapping', 'id' => $mapping->id],
            before: ['status' => MappingStatus::Approved->value],
            after: $this->auditSummary($mapping),
            context: array_filter(['retired_by_name' => $adminName]),
        );
    }

    /** @return array<string, mixed> */
    private function approvedFields(string $method, ProviderCity $providerCity, array $evidence, ?int $adminId): array
    {
        return [
            'status' => MappingStatus::Approved,
            'method' => $method,
            'approved_slot' => ProviderCityMapping::APPROVED_SLOT,
            'external_region_id' => $providerCity->region_external_id,
            'evidence' => $evidence,
            'approved_by' => $adminId,
            'approved_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function insertRow(string $provider, int $cityId, string $externalCityId, array $fields): array
    {
        return [
            'provider' => $provider,
            'address_city_id' => $cityId,
            'external_city_id' => $externalCityId,
            'external_region_id' => $fields['external_region_id'],
            'status' => $fields['status']->value,
            'method' => $fields['method'],
            'approved_slot' => $fields['approved_slot'],
            'evidence' => json_encode($fields['evidence'], JSON_UNESCAPED_UNICODE),
            'approved_by' => $fields['approved_by'],
            'approved_at' => $fields['approved_at'],
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param list<string> $considered every candidate the city had when this one was proposed
     * @return array<string, mixed>
     */
    private function pendingRow(string $provider, AddressCity $city, CityCandidate $candidate, string $method, array $considered): array
    {
        return [
            'provider' => $provider,
            'address_city_id' => $city->id,
            'external_city_id' => (string) $candidate->city->external_id,
            'external_region_id' => $candidate->city->region_external_id,
            'status' => MappingStatus::Pending->value,
            'method' => $method,
            'approved_slot' => null,
            'evidence' => json_encode($this->evidence(city: $city, providerCity: $candidate->city, why: $method, extra: ['region_check' => $candidate->region, 'candidates_considered' => $considered]), JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * What a person needs in order to judge the pair later: both sides' names and regions as they were when the decision was made.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function evidence(AddressCity $city, ProviderCity $providerCity, string $why, array $extra = []): array
    {
        $city->loadMissing('region');

        return $extra + [
            'why' => $why,
            'city' => [
                'id' => $city->id, 'name_ar' => $city->name_ar, 'name_en' => $city->name_en,
                'region_id' => $city->region_id, 'region_name_ar' => $city->region?->name_ar, 'region_name_en' => $city->region?->name_en,
            ],
            'provider_city' => [
                'external_id' => (string) $providerCity->external_id, 'name_ar' => $providerCity->name_ar, 'name_en' => $providerCity->name_en,
                'region_id' => $providerCity->region_external_id, 'region_name_ar' => $providerCity->region_name_ar, 'region_name_en' => $providerCity->region_name_en,
                'is_selected' => $providerCity->is_selected, 'prefix' => $providerCity->prefix,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function withDecision(array $evidence, string $decision, ?int $adminId, ?string $adminName): array
    {
        $evidence['decisions'] = [...($evidence['decisions'] ?? []), [
            'decision' => $decision,
            'by' => array_filter(['id' => $adminId, 'name' => $adminName]) ?: null,
            'at' => now()->toIso8601String(),
        ]];

        return $evidence;
    }

    /** @return array<string, mixed> */
    private function auditSummary(ProviderCityMapping $mapping): array
    {
        return [
            'provider' => $mapping->provider,
            'address_city_id' => $mapping->address_city_id,
            'external_city_id' => (string) $mapping->external_city_id,
            'external_region_id' => $mapping->external_region_id,
            'status' => $mapping->status?->value,
            'method' => $mapping->method,
        ];
    }

    private function cityOrFail(int $cityId): AddressCity
    {
        return AddressCity::query()->with('region')->find($cityId) ?? throw new InvalidArgumentException('This city is not in the store\'s city list.');
    }

    private function providerCityOrFail(string $provider, string $externalCityId): ProviderCity
    {
        return ProviderCity::query()->where('provider', $provider)->where('external_id', $externalCityId)->first()
            ?? throw new InvalidArgumentException('This city is not in the provider\'s city list.');
    }

    private function lockedRow(string $provider, int $cityId, string $externalCityId): ?ProviderCityMapping
    {
        return ProviderCityMapping::query()
            ->where('provider', $provider)
            ->where('address_city_id', $cityId)
            ->where('external_city_id', $externalCityId)
            ->lockForUpdate()
            ->first();
    }

    private function newRow(string $provider, int $cityId, string $externalCityId): ProviderCityMapping
    {
        return new ProviderCityMapping(['provider' => $provider, 'address_city_id' => $cityId, 'external_city_id' => $externalCityId]);
    }

    /** @return Collection<int, Collection<int, ProviderCityMapping>> every mapping row of the provider, by city */
    private function mappingsByCity(string $provider): Collection
    {
        return ProviderCityMapping::query()
            ->where('provider', $provider)
            ->get(['id', 'address_city_id', 'external_city_id', 'status'])
            ->groupBy('address_city_id');
    }

    /** @param Collection<int, ProviderCityMapping>|null $rows */
    private function hasApproved(?Collection $rows): bool
    {
        return $rows !== null && $rows->contains(static fn (ProviderCityMapping $row): bool => $row->isApproved());
    }

    /** @param Collection<int, ProviderCityMapping>|null $rows an approval, a rejection or a retirement: someone has already looked at this city */
    private function hasPersonDecision(?Collection $rows): bool
    {
        return $rows !== null && $rows->contains(static fn (ProviderCityMapping $row): bool => $row->status !== MappingStatus::Pending);
    }

    /** @return array<string, array<string, ProviderCity>> provider cities by name key, then by the provider's id */
    private function providerNameIndex(string $provider): array
    {
        $index = [];
        foreach ($this->liveCities($provider)->cursor() as $providerCity) {
            foreach ($this->keysOf($providerCity->name_ar, $providerCity->name_en) as $key) {
                $index[$key][(string) $providerCity->external_id] = $providerCity;
            }
        }

        return $index;
    }

    /** @return Builder<ProviderCity> the cities the provider still lists */
    private function liveCities(string $provider): Builder
    {
        $columns = ['id', 'provider', 'external_id', 'name_ar', 'name_en', 'region_external_id'];

        return $this->hasDirectoryColumns()
            ? ProviderCity::query()->select([...$columns, ...self::DIRECTORY_COLUMNS])->where('provider', $provider)->whereNull('missing_since')
            : ProviderCity::query()->select($columns)->where('provider', $provider);
    }

    /** @return array<string, list<int>> our regions' ids by name key */
    private function regionIdsByKey(): array
    {
        $regions = [];
        foreach (AddressRegion::query()->get(['id', 'name_ar', 'name_en']) as $region) {
            foreach ($this->keysOf($region->name_ar, $region->name_en) as $key) {
                $regions[$key][] = (int) $region->id;
            }
        }

        return $regions;
    }

    /**
     * @param array<string, array<string, ProviderCity>> $index
     * @param array<string, list<int>> $regionIdsByKey
     * @return Collection<int, CityCandidate>
     */
    private function candidatesAmong(array $index, array $regionIdsByKey, AddressCity $city): Collection
    {
        $matches = [];
        foreach ($this->keysOf($city->name_ar, $city->name_en) as $key) {
            $matches += $index[$key] ?? [];
        }

        $candidates = [];
        foreach ($matches as $providerCity) {
            $region = $this->regionCheck(providerCity: $providerCity, regionId: (int) $city->region_id, regionIdsByKey: $regionIdsByKey);
            if ($region !== null) {
                $candidates[] = new CityCandidate(city: $providerCity, region: $region);
            }
        }

        usort($candidates, static fn (CityCandidate $a, CityCandidate $b): int => [$a->region === CityCandidate::REGION_CONFIRMED ? 0 : 1, $a->city->is_selected ? 0 : 1, (int) $a->city->external_id, (string) $a->city->external_id]
            <=> [$b->region === CityCandidate::REGION_CONFIRMED ? 0 : 1, $b->city->is_selected ? 0 : 1, (int) $b->city->external_id, (string) $b->city->external_id]);

        return collect($candidates);
    }

    /**
     * What the provider city's region name says about OUR city's region: confirmed, nothing to go on, a name we cannot place — or
     * null, when it names another of our regions (then it is not a candidate for this city at all).
     *
     * @param array<string, list<int>> $regionIdsByKey
     */
    private function regionCheck(ProviderCity $providerCity, int $regionId, array $regionIdsByKey): ?string
    {
        $keys = $this->keysOf($providerCity->region_name_ar, $providerCity->region_name_en);
        if ($keys === []) {
            return CityCandidate::REGION_UNKNOWN;
        }

        foreach ($keys as $key) {
            if (in_array($regionId, $regionIdsByKey[$key] ?? [], true)) {
                return CityCandidate::REGION_CONFIRMED;
            }
        }
        foreach ($keys as $key) {
            if (isset($regionIdsByKey[$key])) {
                return null;
            }
        }

        return CityCandidate::REGION_UNPLACEABLE;
    }

    /** @return list<string> the distinct, non-empty comparison keys of the given names */
    private function keysOf(?string ...$names): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (?string $name): string => AddressText::key($name), $names),
            static fn (string $key): bool => $key !== '',
        )));
    }
}
