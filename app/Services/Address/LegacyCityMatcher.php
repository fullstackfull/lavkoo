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
 * Certain means: an administrator-confirmed alias, or exactly one city whose Arabic or English name has the same key and that is a
 * registered city (or whose region the typed state names). Two cities with that name (the list has many repeated names), exactly one
 * small place with it (a district typed as a city looks just like that), a region text that points somewhere else, a city text that is
 * really the name of a region ("المنطقة الشرقية", "Asir"), or no city at all are NOT matches, and nothing is ever matched to a
 * "nearest" or "similar" city.
 * The old text is never changed by this class.
 */
class LegacyCityMatcher
{
    public function match(?string $cityText, ?string $regionText = null): CityMatch
    {
        $key = AddressText::key($cityText);
        if ($key === '') {
            return $this->result(status: CityMatchStatus::None, city: null, candidates: collect(), reason: 'empty_city');
        }

        $alias = AddressCityAlias::query()->where('normalized_alias', $key)->first();
        if ($alias !== null) {
            $city = AddressCity::query()->with('region')->find($alias->city_id);

            return $city === null
                ? $this->result(status: CityMatchStatus::None, city: null, candidates: collect(), reason: 'alias_city_missing')
                : $this->confirmRegion($city, $regionText, 'alias');
        }

        if (AddressText::namesRegion($cityText)) {
            return $this->result(status: CityMatchStatus::None, city: null, candidates: collect(), reason: 'region_typed_as_city');
        }

        $candidates = AddressCity::query()
            ->with('region')
            ->where(static fn ($query) => $query->where('normalized_ar', $key)->orWhere('normalized_en', $key))
            ->orderBy('id')
            ->get();

        if ($candidates->isNotEmpty() && $this->lieElsewhereThanTheRegionNamed($key, $candidates)) {
            return $this->result(status: CityMatchStatus::Conflict, city: null, candidates: $candidates, reason: 'city_text_names_another_region');
        }

        return match ($candidates->count()) {
            0 => $this->result(status: CityMatchStatus::None, city: null, candidates: collect(), reason: 'no_candidate'),
            1 => $this->uniqueName($candidates->first(), $regionText),
            default => $this->narrowByRegion($candidates, $regionText),
        };
    }

    /**
     * A typed "الشرقية" is the Eastern Province before it is a village called Al Sharqiyyah in Asir: when the text is the name of a region
     * and none of the cities that share it lies in that region, the text names the region and the cities are coincidences.
     *
     * @param Collection<int, AddressCity> $candidates
     */
    private function lieElsewhereThanTheRegionNamed(string $key, Collection $candidates): bool
    {
        $named = $this->regionWithKey($key);

        return $named !== null && $candidates->every(static fn (AddressCity $city): bool => $city->region_id !== $named->id);
    }

    /**
     * One place has this name. For a registered city (one the register lists districts for) that is certain enough; for a small place the
     * list is simply missing the districts, and a typed district is as likely as a typed village, so unless the typed state names that
     * place's region a person looks at it first.
     */
    private function uniqueName(AddressCity $city, ?string $regionText): CityMatch
    {
        $match = $this->confirmRegion($city, $regionText, 'unique_name');
        if (!$match->matched() || $city->sort_order > 0 || $this->regionOf($regionText)?->id === $city->region_id) {
            return $match;
        }

        return $this->result(status: CityMatchStatus::Unconfirmed, city: null, candidates: collect([$city]), reason: 'unique_name_of_a_small_place');
    }

    /** @param Collection<int, AddressCity> $candidates */
    private function narrowByRegion(Collection $candidates, ?string $regionText): CityMatch
    {
        $region = $this->regionOf($regionText);
        if ($region !== null) {
            $inRegion = $candidates->where('region_id', $region->id)->values();
            if ($inRegion->count() === 1) {
                return $this->result(status: CityMatchStatus::Matched, city: $inRegion->first(), candidates: collect(), reason: 'unique_name_in_region');
            }
        }

        return $this->result(status: CityMatchStatus::Ambiguous, city: null, candidates: $candidates, reason: 'ambiguous_name');
    }

    private function confirmRegion(AddressCity $city, ?string $regionText, string $reason): CityMatch
    {
        $region = $this->regionOf($regionText);
        if ($region !== null && $region->id !== $city->region_id) {
            return $this->result(status: CityMatchStatus::Conflict, city: null, candidates: collect([$city]), reason: 'region_conflict');
        }

        return $this->result(status: CityMatchStatus::Matched, city: $city, candidates: collect(), reason: $reason);
    }

    /** The region an old "state" text names, if it names exactly one. */
    private function regionOf(?string $regionText): ?AddressRegion
    {
        return $this->regionWithKey(AddressText::key($regionText));
    }

    private function regionWithKey(string $key): ?AddressRegion
    {
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
