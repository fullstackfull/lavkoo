<?php

namespace App\Services\Address;

use App\Models\AddressCity;
use App\Models\ShippingAddress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The saved Saudi addresses, and the ones among them that still carry only the old free-text city.
 *
 * "Saudi" is decided by AddressCountry, so every spelling the address book holds ("Saudi Arabia", "SA", an Arabic name) is found. The only
 * thing ever written here is the region and city id of an address that has none: the typed city, the state, the postal code and the
 * National Address of an address are not touched, and no timestamp moves, so "latest address" lists keep their order.
 */
class SavedSaudiAddresses
{
    private const WRITE_CHUNK = 500;

    /** @var list<string>|null */
    private ?array $saudiCountries = null;

    /** Every saved address whose country is Saudi Arabia, structured or not. */
    public function saudi(): Builder
    {
        return ShippingAddress::query()->whereIn('country', $this->saudiCountries());
    }

    /** The Saudi addresses that do not point at a city of the master list yet. */
    public function withoutCity(): Builder
    {
        return $this->saudi()->whereNull('city_id');
    }

    /** The Saudi addresses a customer still has to complete before they ship: no city of the list, or no National Address. */
    public function needingCompletion(): Builder
    {
        return $this->saudi()->where(static fn (Builder $query) => $query->whereNull('city_id')->orWhereNull('national_address')->orWhere('national_address', ''));
    }

    /** The Saudi addresses with a blank postal code. They are valid: the postal code is optional and never derived from anything else. */
    public function withoutZip(): Builder
    {
        return $this->saudi()->whereRaw("TRIM(COALESCE(zip, '')) = ''");
    }

    /** @return Collection<int, LegacyPair> one per distinct typed city and state, with the number of addresses typed that way */
    public function pairs(): Collection
    {
        return $this->withoutCity()->toBase()
            ->select('city', 'state')
            ->selectRaw('COUNT(*) as address_rows')
            ->groupBy('city', 'state')
            ->get()
            ->map(static fn (object $row): LegacyPair => new LegacyPair(city: $row->city, state: $row->state, rows: (int) $row->address_rows));
    }

    /**
     * The addresses typed with exactly this city and state text. The database finds them by comparing text, the keys then confirm each
     * one, so a collation that treats two different spellings as equal can never make an address join a decision about another text.
     *
     * @return list<int>
     */
    public function idsTypedAs(?string $city, ?string $state): array
    {
        $query = $this->withoutCity()
            ->when($city === null, static fn (Builder $inner) => $inner->whereNull('city'), static fn (Builder $inner) => $inner->where('city', $city))
            ->when($state === null, static fn (Builder $inner) => $inner->whereNull('state'), static fn (Builder $inner) => $inner->where('state', $state));

        $cityKey = AddressText::key($city);
        $stateKey = AddressText::key($state);

        return $this->confirmed($query, static fn (object $row): bool => AddressText::key($row->city) === $cityKey && AddressText::key($row->state) === $stateKey);
    }

    /**
     * The addresses whose typed city has this key, whatever state they came with.
     *
     * @return list<int>
     */
    public function idsWithCityKey(string $cityKey): array
    {
        $spellings = $this->pairs()
            ->filter(static fn (LegacyPair $pair): bool => $pair->cityKey === $cityKey && $pair->city !== null)
            ->map(static fn (LegacyPair $pair): string => (string) $pair->city)
            ->unique()
            ->values()
            ->all();

        if ($spellings === []) {
            return [];
        }

        return $this->confirmed($this->withoutCity()->whereIn('city', $spellings), static fn (object $row): bool => AddressText::key($row->city) === $cityKey);
    }

    /**
     * Points these addresses at a city. An address that has meanwhile been given a city by someone else is left alone.
     *
     * @param list<int> $ids
     * @return int addresses written
     */
    public function assign(array $ids, AddressCity $city): int
    {
        $written = 0;
        foreach (array_chunk($ids, self::WRITE_CHUNK) as $chunk) {
            // The base query builder, not the model: the model would also move updated_at, which says when a customer last changed an address.
            $written += ShippingAddress::query()
                ->whereIn('id', $chunk)
                ->whereNull('city_id')
                ->toBase()
                ->update(['region_id' => $city->region_id, 'city_id' => $city->id]);
        }

        return $written;
    }

    /**
     * @param callable(object): bool $belongs
     * @return list<int>
     */
    private function confirmed(Builder $query, callable $belongs): array
    {
        $ids = [];
        foreach ($query->select('id', 'city', 'state')->lazyById(1000) as $row) {
            if ($belongs($row)) {
                $ids[] = (int) $row->id;
            }
        }

        return $ids;
    }

    /** @return list<string> */
    private function saudiCountries(): array
    {
        return $this->saudiCountries ??= ShippingAddress::query()
            ->whereNotNull('country')
            ->distinct()
            ->pluck('country')
            ->filter(static fn ($country): bool => AddressCountry::isSaudi((string) $country))
            ->map(static fn ($country): string => (string) $country)
            ->values()
            ->all();
    }
}
