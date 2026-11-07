<?php

namespace App\Services\Address;

use App\Enums\Address\CityMatchStatus;
use App\Models\AddressCity;
use Illuminate\Support\Collection;

/** What reading an old free-text city found. Only `matched` carries a city; the others carry the candidates a person should choose from. */
final class CityMatch
{
    /** @param Collection<int, AddressCity> $candidates */
    public function __construct(
        public readonly CityMatchStatus $status,
        public readonly ?AddressCity $city,
        public readonly Collection $candidates,
        public readonly string $reason,
    ) {
    }

    public function matched(): bool
    {
        return $this->status === CityMatchStatus::Matched;
    }
}
