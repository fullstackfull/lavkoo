<?php

namespace App\Services\Address;

use App\Models\AddressCity;
use App\Models\AddressCityAlias;
use App\Models\AddressRegion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reads the store's region and city lists: the only place a region or city is looked up, by every checkout, form, API and
 * integration. Searching works in Arabic and English and tolerates the usual spelling variants (see AddressText); choosing a
 * city always goes through its id.
 */
class AddressCatalog
{
    /** @return Collection<int, AddressRegion> */
    public function regions(bool $onlyActive = true): Collection
    {
        return AddressRegion::query()
            ->when($onlyActive, static fn (Builder $query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function region(int $regionId, bool $onlyActive = true): ?AddressRegion
    {
        return AddressRegion::query()
            ->whereKey($regionId)
            ->when($onlyActive, static fn (Builder $query) => $query->where('is_active', true))
            ->first();
    }

    public function city(int $cityId): ?AddressCity
    {
        return AddressCity::query()->with('region')->find($cityId);
    }

    /** The active city with this id, only if it lies in the given active region: the check every submitted pair must pass. */
    public function activeCityInRegion(int $cityId, int $regionId): ?AddressCity
    {
        $city = AddressCity::query()
            ->whereKey($cityId)
            ->where('region_id', $regionId)
            ->where('is_active', true)
            ->whereHas('region', static fn (Builder $query) => $query->where('is_active', true))
            ->first();

        return $city?->setRelation('region', $this->region($regionId, onlyActive: true));
    }

    /** The city with this id, active or not, if it lies in the given region: what an order that already names a place may keep. */
    public function cityInRegion(int $cityId, int $regionId): ?AddressCity
    {
        $city = AddressCity::query()->whereKey($cityId)->where('region_id', $regionId)->first();

        return $city?->setRelation('region', $this->region($regionId, onlyActive: false));
    }

    /**
     * @return Collection<int, AddressCity> cities of the region (or of the whole country when no region is given), best matches first
     */
    public function searchCities(?int $regionId = null, ?string $query = null, ?int $limit = null, bool $onlyActive = true): Collection
    {
        $limit = max(1, min((int) ($limit ?? config('address.search.limit', 25)), (int) config('address.search.max_limit', 100)));
        $keys = AddressText::searchKeys($query);

        $builder = AddressCity::query()
            ->when($regionId !== null, static fn (Builder $query) => $query->where('region_id', $regionId))
            ->when($onlyActive, static fn (Builder $query) => $query->where('is_active', true)->whereHas('region', static fn (Builder $region) => $region->where('is_active', true)));

        if ($keys === []) {
            return $builder->orderByDesc('sort_order')->orderBy('name_en')->orderBy('id')->limit($limit)->get();
        }

        $aliasCityIds = AddressCityAlias::query()
            ->where(static function (Builder $query) use ($keys): void {
                foreach ($keys as $key) {
                    $query->orWhere('normalized_alias', 'like', '%' . $key . '%');
                }
            })
            ->pluck('city_id')
            ->all();

        return $builder
            ->where(static function (Builder $query) use ($keys, $aliasCityIds): void {
                foreach ($keys as $key) {
                    $query->orWhere('normalized_ar', 'like', '%' . $key . '%')->orWhere('normalized_en', 'like', '%' . $key . '%');
                }
                if ($aliasCityIds !== []) {
                    $query->orWhereIn('id', $aliasCityIds);
                }
            })
            ->orderByRaw(
                'CASE WHEN normalized_ar = ? OR normalized_en = ? THEN 0 WHEN normalized_ar LIKE ? OR normalized_en LIKE ? THEN 1 ELSE 2 END',
                [$rankKey = end($keys), $rankKey, $rankKey . '%', $rankKey . '%'],
            )
            ->orderByDesc('sort_order')
            ->orderBy('name_en')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** @return array{id: int, code: string, name_ar: string, name_en: string, name: string} */
    public function regionPayload(AddressRegion $region): array
    {
        return [
            'id' => (int) $region->id,
            'code' => (string) $region->code,
            'name_ar' => (string) $region->name_ar,
            'name_en' => (string) $region->name_en,
            'name' => $region->name(),
        ];
    }

    /** @return array{id: int, region_id: int, name_ar: string, name_en: string, name: string} */
    public function cityPayload(AddressCity $city): array
    {
        return [
            'id' => (int) $city->id,
            'region_id' => (int) $city->region_id,
            'name_ar' => (string) $city->name_ar,
            'name_en' => (string) $city->name_en,
            'name' => $city->name(),
        ];
    }
}
