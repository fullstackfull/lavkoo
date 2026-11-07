<?php

namespace App\Services\Fulfillment\Providers\TorodPoint;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\AddressCity;
use App\Models\ProviderCity;
use App\Models\ProviderCityAlias;
use App\Services\Address\AddressSchema;
use App\Services\Address\AddressSnapshot;
use App\Services\Address\LegacyCityMatcher;
use App\Services\Fulfillment\Data\CityResolution;
use App\Services\Fulfillment\ProviderCityMappingService;
use App\Services\Fulfillment\Support\ArabicText;
use App\Services\Fulfillment\Support\FieldMap;
use App\Services\Fulfillment\Support\ProviderSyncRecorder;
use Illuminate\Support\Collection;

/**
 * Finds the Torod Point city for an order's address — exactly, or not at all.
 *
 * An address that carries one of OUR cities (its `city_id`) goes through that city's approved mapping and nothing else: a name is
 * never compared and an id of ours is never sent. An old address that only has typed text goes through an administrator's alias
 * first, then through the address system's own unique, region-consistent match to one of our cities, and then through that city's
 * mapping. Whatever cannot be settled that way is `unmapped`, `ambiguous` (several candidates) or `pending_review` (one candidate
 * waiting for a person) — the order is held, and is never sent to a "nearest" city. The region of an address is a filter, never a
 * second guess at the city.
 *
 * Until the address and mapping tables have been migrated the resolver behaves as it always did: an alias, then a unique exact
 * match on the normalised name in the cached list.
 *
 * Resolving only reads the local database, because the dispatcher asks while it holds a row lock.
 */
class TorodCityResolver
{
    private const PROVIDER = ProviderCode::TorodPoint->value;

    public function __construct(
        private readonly TorodLastmileClient $client,
        private readonly ProviderSyncRecorder $sync,
        private readonly TorodCityDirectory $directory,
        private readonly ProviderCityMappingService $mappings,
        private readonly LegacyCityMatcher $matcher,
    ) {
    }

    /** @param array<array-key, mixed> $address the order's address snapshot as an array */
    public function resolveForAddress(array $address): CityResolution
    {
        $typedCity = trim((string) ($address['city'] ?? ''));
        if (!$this->mappings->available()) {
            $city = $this->resolve($typedCity);

            return $city === null ? CityResolution::unmappedAsBefore($typedCity) : CityResolution::resolved(city: $city);
        }

        $cityId = (int) ($address['city_id'] ?? 0);
        if ($cityId > 0) {
            return $this->fromApprovedMapping(cityId: $cityId, cityName: AddressSnapshot::cityName($address, 'ar') ?? $typedCity);
        }

        $aliased = $this->fromAlias(ArabicText::normalize($typedCity));
        if ($aliased !== null) {
            return CityResolution::resolved(city: $aliased);
        }

        return $this->fromTypedCity(address: $address, typedCity: $typedCity);
    }

    /** The cached city for a typed name: an administrator's alias, else the one city that name matches exactly. */
    public function resolve(?string $city): ?ProviderCity
    {
        $normalized = ArabicText::normalize($city);

        return $normalized === '' ? null : ($this->fromAlias($normalized) ?? $this->fromCityList($normalized));
    }

    /** @return array{ok: bool, count?: int, verdict?: string, reason?: ?string, error?: string} */
    public function sync(): array
    {
        return $this->mappings->available() ? $this->directory->sync() : $this->syncOnce();
    }

    /**
     * Looks for the provider's cities that are named like one of ours, and proposes the exact ones for a person to approve.
     *
     * @param bool $apply false only asks the provider and reports
     * @return array{ok: bool, error?: string, found?: int, matches?: list<string>, proposed?: ?int, applied?: bool}
     */
    public function discoverForCity(AddressCity $city, bool $apply = true): array
    {
        return $this->directory->discoverForCity(city: $city, apply: $apply);
    }

    public function addAlias(string $alias, string $externalCityId, ?int $by = null): ProviderCityAlias
    {
        return ProviderCityAlias::updateOrCreate(
            ['provider' => self::PROVIDER, 'alias' => ArabicText::normalize($alias)],
            ['external_city_id' => $externalCityId, 'created_by' => $by],
        );
    }

    /** @return Collection<int, ProviderCity> cached cities whose name contains the query, for the mapping screen */
    public function search(string $query, int $limit = 20): Collection
    {
        $normalized = ArabicText::normalize($query);
        if ($normalized === '') {
            return collect();
        }

        return ProviderCity::where('provider', self::PROVIDER)
            ->where(static fn ($builder) => $builder->where('normalized_ar', 'like', '%' . $normalized . '%')->orWhere('normalized_en', 'like', '%' . $normalized . '%'))
            ->orderBy('name_en')
            ->limit($limit)
            ->get();
    }

    /** An old address: only its typed text says where it goes, so its internal city must be certain before a mapping is consulted. */
    private function fromTypedCity(array $address, string $typedCity): CityResolution
    {
        if (!AddressSchema::hasCities()) {
            $listed = $this->fromCityList(ArabicText::normalize($typedCity));

            return $listed === null ? CityResolution::unmapped(cityName: $typedCity) : CityResolution::resolved(city: $listed);
        }

        $state = $address['state'] ?? null;
        $match = $this->matcher->match(cityText: $typedCity, regionText: is_scalar($state) ? (string) $state : null);

        return $match->matched()
            ? $this->fromApprovedMapping(cityId: (int) $match->city->id, cityName: (string) $match->city->name_ar)
            : CityResolution::unmapped(cityName: $typedCity);
    }

    private function fromApprovedMapping(int $cityId, string $cityName): CityResolution
    {
        $mapping = $this->mappings->approvedFor(provider: self::PROVIDER, cityId: $cityId);
        if ($mapping !== null) {
            $city = ProviderCity::where('provider', self::PROVIDER)->where('external_id', $mapping->external_city_id)->first();

            return $city !== null && $city->missing_since === null
                ? CityResolution::resolved(city: $city, addressCityId: $cityId)
                : CityResolution::providerCityMissing(cityName: $cityName, addressCityId: $cityId);
        }

        $pending = $this->mappings->pendingExternalIds(provider: self::PROVIDER, cityId: $cityId);

        return match (count($pending)) {
            0 => CityResolution::unmapped(cityName: $cityName, addressCityId: $cityId),
            1 => CityResolution::pendingReview(cityName: $cityName, addressCityId: $cityId, candidateId: $pending[0]),
            default => CityResolution::ambiguous(cityName: $cityName, addressCityId: $cityId, candidateIds: $pending),
        };
    }

    private function fromAlias(string $normalized): ?ProviderCity
    {
        $alias = ProviderCityAlias::where('provider', self::PROVIDER)->where('alias', $normalized)->first();

        return $alias === null ? null : ProviderCity::where('provider', self::PROVIDER)->where('external_id', $alias->external_city_id)->first();
    }

    private function fromCityList(string $normalized): ?ProviderCity
    {
        $matches = ProviderCity::where('provider', self::PROVIDER)
            ->where(static fn ($builder) => $builder->where('normalized_ar', $normalized)->orWhere('normalized_en', $normalized))
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * The city list as it has always been read: one response, whatever it holds, written city by city. It runs only on a store that
     * has not migrated the tables a completeness-aware reading records itself in.
     *
     * @return array{ok: bool, count?: int, error?: string}
     */
    private function syncOnce(): array
    {
        $this->sync->attempted(self::PROVIDER, ProviderSyncRecorder::CITIES);

        $result = $this->client->cities();
        $cities = $result->ok() && $result->json !== null ? FieldMap::extractItems($result->json) : null;
        if ($cities === null) {
            $message = $result->ok() ? 'The city list response contains no list.' : $result->message();
            $this->sync->failed(self::PROVIDER, ProviderSyncRecorder::CITIES, $message);

            return ['ok' => false, 'error' => $message];
        }

        $count = 0;
        foreach ($cities as $city) {
            if (!isset($city['id'])) {
                continue;
            }
            $arabic = isset($city['arabicName']) ? (string) $city['arabicName'] : null;
            $english = (string) ($city['englishName'] ?? $city['name'] ?? '');

            ProviderCity::updateOrCreate(
                ['provider' => self::PROVIDER, 'external_id' => (string) $city['id']],
                [
                    'name_ar' => $arabic,
                    'name_en' => $english !== '' ? $english : null,
                    'region_external_id' => isset($city['regionId']) ? (string) $city['regionId'] : null,
                    'normalized_ar' => $arabic !== null ? ArabicText::normalize($arabic) : null,
                    'normalized_en' => $english !== '' ? ArabicText::normalize($english) : null,
                    'raw' => $city,
                    'synced_at' => now(),
                ],
            );
            $count++;
        }

        $this->sync->succeeded(self::PROVIDER, ProviderSyncRecorder::CITIES, ['cities' => $count]);

        return ['ok' => true, 'count' => $count];
    }
}
