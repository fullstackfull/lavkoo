<?php

namespace App\Services\Address;

use App\Models\AddressCity;
use App\Models\AddressCityAlias;
use App\Models\AddressRegion;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reads the store's region and city lists: the only place a region or city is looked up, by every checkout, form, API and
 * integration. Searching works in Arabic and English and tolerates the usual spelling variants (see AddressText); choosing a
 * city always goes through its id.
 *
 * **A LIST belongs to the market that is being served (A2-06, A2-12).** A region carries its own `country_code` and a city takes
 * its region's, so a storefront offers the places of its own country and no other: without this, the Kuwaiti storefront's
 * checkout offered Saudi regions, and a customer could have picked Riyadh as their Kuwaiti city. The market comes from
 * {@see StorefrontCatalog::country()}, which is the one reader that already knows which requests belong to a storefront and
 * which work across every country -- so the admin and vendor panels, the seller APIs, a webhook, a queue and a console command
 * read every country's list exactly as they do today, and a market is never guessed for them.
 *
 * It is dormant while storefronts are switched off: the country is then null, nothing is narrowed, and the lists are the lists
 * this store has always had. With them on, the Saudi storefront's lists are also unchanged, because every row the importer
 * writes is Saudi.
 *
 * **What is deliberately NOT narrowed is the lookup of a place by its id** ({@see city()}, {@see cityInRegion()},
 * {@see activeCityInRegion()}). A storefront may deliver to another country (H1-01, H1-05) and an order keeps the place it was
 * placed with for good, so an id that names a real place must go on resolving whichever market is asking. The rules an address
 * is judged by follow the ADDRESS's own country, not the storefront's; what a storefront may deliver to is
 * {@see \App\Services\Shipping\ShippingEligibility}'s question and not this class's.
 */
class AddressCatalog
{
    /** @return Collection<int, AddressRegion> */
    public function regions(bool $onlyActive = true): Collection
    {
        return $this->ofTheMarketBeingServed(AddressRegion::query())
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

        $builder = $this->citiesOfTheMarketBeingServed(AddressCity::query())
            ->when($regionId !== null, static fn (Builder $query) => $query->where('region_id', $regionId))
            ->when($onlyActive, static fn (Builder $query) => $query->where('is_active', true)->whereHas('region', static fn (Builder $region) => $region->where('is_active', true)));

        if ($keys === []) {
            return $builder->orderByDesc('sort_order')->orderBy('name_en')->orderBy('id')->limit($limit)->get();
        }

        // The spellings of the storefront being served (H1-10). One market has one namespace and a one-country platform asks the default
        // one, so this is the same query it has always been; a second market's spellings are simply not its own.
        $aliasNamespace = app(CityAliasNamespace::class);
        $matchingAliases = $aliasNamespace->narrow(AddressCityAlias::query(), $aliasNamespace->ofTheRequest())
            ->where(static function (Builder $query) use ($keys): void {
                foreach ($keys as $key) {
                    $query->orWhere('normalized_alias', 'like', '%' . $key . '%');
                }
            })
            ->get(['city_id', 'normalized_alias']);
        $aliasCityIds = $matchingAliases->pluck('city_id')->all();
        $exactAliasCityIds = $matchingAliases->whereIn('normalized_alias', $keys)->pluck('city_id')->all();
        $exactAliasCondition = $exactAliasCityIds === [] ? '' : ' OR id IN (' . implode(',', array_fill(0, count($exactAliasCityIds), '?')) . ')';

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
                'CASE WHEN normalized_ar = ? OR normalized_en = ?' . $exactAliasCondition . ' THEN 0 WHEN normalized_ar LIKE ? OR normalized_en LIKE ? THEN 1 ELSE 2 END',
                [$rankKey = end($keys), $rankKey, ...$exactAliasCityIds, $rankKey . '%', $rankKey . '%'],
            )
            ->orderByDesc('sort_order')
            ->orderBy('name_en')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Keeps the regions of the market this request is served from. Untouched for a request that belongs to no one market: a panel, a
     * seller API, a webhook, a queue, a console command, and every request while storefronts are off.
     *
     * @param Builder<AddressRegion> $regions
     * @return Builder<AddressRegion>
     */
    private function ofTheMarketBeingServed(Builder $regions): Builder
    {
        $market = StorefrontCatalog::country();

        return $market === null ? $regions : $regions->where($regions->getModel()->qualifyColumn('country_code'), $market);
    }

    /**
     * The same bound for a list of cities, which take their country from their region: the master keeps the country in one place, so
     * a city cannot disagree with the region it is in.
     *
     * @param Builder<AddressCity> $cities
     * @return Builder<AddressCity>
     */
    private function citiesOfTheMarketBeingServed(Builder $cities): Builder
    {
        $market = StorefrontCatalog::country();

        return $market === null
            ? $cities
            : $cities->whereHas('region', static fn (Builder $region) => $region->where('country_code', $market));
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
