<?php

namespace App\Services\Address;

use App\Enums\Address\CityMatchStatus;
use App\Models\AddressCity;
use App\Models\AddressCityAlias;
use App\Models\AddressRegion;
use Illuminate\Support\Collection;

/**
 * Reads the free-text city of an old address and says which city of the master list it is — only when that is certain.
 *
 * Certain means: an administrator-confirmed alias, or exactly one city whose Arabic or English name has the same key. Two cities
 * with that name (the list has many repeated names), a region text that points somewhere else, or no city at all are NOT matches,
 * and nothing is ever matched to a "nearest" or "similar" city. The old text is never changed by this class.
 */
class LegacyCityMatcher
{
    public function match(?string $cityText, ?string $regionText = null): CityMatch
    {
        $key = AddressText::key($cityText);
        if ($key === '') {
            return $this->result(CityMatchStatus::None, null, collect(), 'empty_city');
        }

        $alias = AddressCityAlias::query()->where('normalized_alias', $key)->first();
        if ($alias !== null) {
            $city = AddressCity::query()->with('region')->find($alias->city_id);

            return $city === null
                ? $this->result(CityMatchStatus::None, null, collect(), 'alias_city_missing')
                : $this->confirmRegion($city, $regionText, 'alias');
        }

        $candidates = AddressCity::query()
            ->with('region')
            ->where(static fn ($query) => $query->where('normalized_ar', $key)->orWhere('normalized_en', $key))
            ->orderBy('id')
            ->get();

        return match ($candidates->count()) {
            0 => $this->result(CityMatchStatus::None, null, collect(), 'no_candidate'),
            1 => $this->confirmRegion($candidates->first(), $regionText, 'unique_name'),
            default => $this->narrowByRegion($candidates, $regionText),
        };
    }

    /** @param Collection<int, AddressCity> $candidates */
    private function narrowByRegion(Collection $candidates, ?string $regionText): CityMatch
    {
        $region = $this->regionOf($regionText);
        if ($region !== null) {
            $inRegion = $candidates->where('region_id', $region->id)->values();
            if ($inRegion->count() === 1) {
                return $this->result(CityMatchStatus::Matched, $inRegion->first(), collect(), 'unique_name_in_region');
            }
        }

        return $this->result(CityMatchStatus::Ambiguous, null, $candidates, 'ambiguous_name');
    }

    private function confirmRegion(AddressCity $city, ?string $regionText, string $reason): CityMatch
    {
        $region = $this->regionOf($regionText);
        if ($region !== null && $region->id !== $city->region_id) {
            return $this->result(CityMatchStatus::Conflict, null, collect([$city]), 'region_conflict');
        }

        return $this->result(CityMatchStatus::Matched, $city, collect(), $reason);
    }

    /** The region an old "state" text names, if it names exactly one. */
    private function regionOf(?string $regionText): ?AddressRegion
    {
        $key = AddressText::key($regionText);
        if ($key === '') {
            return null;
        }

        $regions = AddressRegion::query()->where(static fn ($query) => $query->where('normalized_ar', $key)->orWhere('normalized_en', $key))->limit(2)->get();

        return $regions->count() === 1 ? $regions->first() : null;
    }

    /** @param Collection<int, AddressCity> $candidates */
    private function result(CityMatchStatus $status, ?AddressCity $city, Collection $candidates, string $reason): CityMatch
    {
        return new CityMatch(status: $status, city: $city, candidates: $candidates, reason: $reason);
    }
}
