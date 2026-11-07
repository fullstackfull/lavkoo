<?php

namespace App\Services\Address;

/** The city and state text some saved Saudi addresses were typed with, and how many addresses carry exactly that. */
final class LegacyPair
{
    public readonly string $cityKey;

    public readonly string $stateKey;

    public function __construct(public readonly ?string $city, public readonly ?string $state, public readonly int $rows)
    {
        $this->cityKey = AddressText::key($city);
        $this->stateKey = AddressText::key($state);
    }

    /** What decides the answer of the matcher: two pairs with the same key are read the same way. */
    public function readingKey(): string
    {
        return $this->cityKey . '|' . $this->stateKey;
    }
}
