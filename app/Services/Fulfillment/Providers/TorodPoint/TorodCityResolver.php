<?php

namespace App\Services\Fulfillment\Providers\TorodPoint;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderCity;
use App\Models\ProviderCityAlias;
use App\Services\Fulfillment\Support\ArabicText;
use App\Services\Fulfillment\Support\FieldMap;
use App\Services\Fulfillment\Support\ProviderSyncRecorder;
use Illuminate\Support\Collection;

/**
 * Maps the city a customer typed at checkout to a Torod Point city id — exactly, or not at all.
 *
 * The city list is cached from the Lastmile API. A checkout string is matched only after both sides are
 * normalised (Arabic letter variants, diacritics, the leading "ال", case); an ambiguous match is refused
 * rather than guessed, and an administrator can add an explicit alias for an unusual spelling. An address
 * whose city cannot be resolved is held for correction — it is never sent to a "nearest" city.
 */
class TorodCityResolver
{
    public function __construct(
        private readonly TorodLastmileClient $client,
        private readonly ProviderSyncRecorder $sync,
    ) {
    }

    /** @return array{ok: bool, count?: int, error?: string} */
    public function sync(): array
    {
        $provider = ProviderCode::TorodPoint->value;
        $this->sync->attempted($provider, ProviderSyncRecorder::CITIES);

        $result = $this->client->cities();
        $cities = $result->ok() && $result->json !== null ? FieldMap::extractItems($result->json) : null;
        if ($cities === null) {
            $message = $result->ok() ? 'The city list response contains no list.' : $result->message();
            $this->sync->failed($provider, ProviderSyncRecorder::CITIES, $message);

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
                ['provider' => $provider, 'external_id' => (string) $city['id']],
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

        $this->sync->succeeded($provider, ProviderSyncRecorder::CITIES, ['cities' => $count]);

        return ['ok' => true, 'count' => $count];
    }

    public function resolve(?string $city, ?string $state = null): ?ProviderCity
    {
        foreach ([$city, $state] as $text) {
            $normalized = ArabicText::normalize($text);
            if ($normalized === '') {
                continue;
            }

            $resolved = $this->fromAlias($normalized) ?? $this->fromCityList($normalized);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    public function addAlias(string $alias, string $externalCityId, ?int $by = null): ProviderCityAlias
    {
        return ProviderCityAlias::updateOrCreate(
            ['provider' => ProviderCode::TorodPoint->value, 'alias' => ArabicText::normalize($alias)],
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

        return ProviderCity::where('provider', ProviderCode::TorodPoint->value)
            ->where(static fn ($builder) => $builder->where('normalized_ar', 'like', '%' . $normalized . '%')->orWhere('normalized_en', 'like', '%' . $normalized . '%'))
            ->orderBy('name_en')
            ->limit($limit)
            ->get();
    }

    private function fromAlias(string $normalized): ?ProviderCity
    {
        $alias = ProviderCityAlias::where('provider', ProviderCode::TorodPoint->value)->where('alias', $normalized)->first();

        return $alias === null ? null : ProviderCity::where('provider', ProviderCode::TorodPoint->value)->where('external_id', $alias->external_city_id)->first();
    }

    private function fromCityList(string $normalized): ?ProviderCity
    {
        $matches = ProviderCity::where('provider', ProviderCode::TorodPoint->value)
            ->where(static fn ($builder) => $builder->where('normalized_ar', $normalized)->orWhere('normalized_en', $normalized))
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
