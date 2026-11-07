<?php

namespace App\Services\Address;

/**
 * Every spelling of one old free-text city (the same normalised text), with the state texts it came with.
 * This is the unit a person decides on: one decision covers all of its addresses.
 */
final class LegacyCityGroup
{
    private const LISTED = 4;

    public readonly int $rows;

    /** @param list<LegacyPair> $pairs */
    public function __construct(public readonly string $key, public readonly array $pairs, public readonly ?CityMatch $match = null)
    {
        $this->rows = array_sum(array_map(static fn (LegacyPair $pair): int => $pair->rows, $pairs));
    }

    public function withMatch(CityMatch $match): self
    {
        return new self(key: $this->key, pairs: $this->pairs, match: $match);
    }

    /** The spelling most addresses use: what the alias is named after. */
    public function sample(): string
    {
        return (string) (array_key_first($this->spellings()) ?? '');
    }

    /** @return array<string, int> typed city text => addresses, most used first */
    public function spellings(): array
    {
        return $this->tally(fn (LegacyPair $pair): ?string => $pair->city);
    }

    /** @return array<string, int> typed state text => addresses, most used first (blank states left out) */
    public function states(): array
    {
        return array_slice($this->tally(fn (LegacyPair $pair): ?string => $pair->state), 0, self::LISTED, preserve_keys: true);
    }

    /** @return array<string, int> */
    private function tally(callable $text): array
    {
        $counts = [];
        foreach ($this->pairs as $pair) {
            $value = trim((string) $text($pair));
            if ($value !== '') {
                $counts[$value] = ($counts[$value] ?? 0) + $pair->rows;
            }
        }
        arsort($counts);

        return $counts;
    }
}
