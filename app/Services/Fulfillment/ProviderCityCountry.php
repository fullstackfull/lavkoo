<?php

namespace App\Services\Fulfillment;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which of a carrier's city lists a lookup reads, when one carrier serves more than one country (H1-11).
 *
 * A carrier that numbers its cities from 1 in every country it serves gives two countries the same external ids, so the lists have to be kept
 * apart. `provider_cities.country` is what keeps them apart, and `''` is the PLATFORM-WIDE list -- the one list every installation imported
 * before a carrier served a second market.
 *
 * **One list per lookup, chosen before the query.** A country that has imported a list of its OWN is read from that list and from no other; a
 * country that has not is read from the platform-wide list, which is what every lookup read before this existed. The choice is made first and
 * then applied as one equality, rather than matching both lists at once: a lookup that matched `country IN ('', 'KW')` would answer a city
 * name with whichever of two rows the engine reached first, and a shipment would go to the city of another country with nothing to say so.
 *
 * It is dormant twice over: before the migration there is no column to read and nothing is narrowed, and after it every row is the
 * platform-wide list until an operator imports a second country's.
 *
 * The answer is remembered for the request, because a basket of twenty lines asks this question twenty times.
 */
#[Scoped]
final class ProviderCityCountry
{
    public const COLUMN = 'country';

    /** The list that belongs to no country in particular. */
    public const PLATFORM_WIDE = '';

    private const TABLE = 'provider_cities';

    private ?bool $columnReady = null;

    /** @var array<string, string> */
    private array $listInForce = [];

    /** Whether a city row can carry a country at all. */
    public function applies(): bool
    {
        return $this->columnReady ??= Schema::hasTable(self::TABLE) && Schema::hasColumn(self::TABLE, self::COLUMN);
    }

    /**
     * Narrows a city query to the one list this country reads.
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     */
    public function narrow(Builder $query, string $provider, ?string $country): Builder
    {
        if (!$this->applies()) {
            return $query;
        }

        return $query->where($query->getModel()->qualifyColumn(self::COLUMN), $this->listFor(provider: $provider, country: $country));
    }

    /**
     * The list a country reads: its own when it has imported one, and the platform-wide one otherwise.
     *
     * This is also what an import writes, so a country importing its first list writes rows that nothing else will read, and the second
     * import of the same country adds to the list the first one made.
     */
    public function listFor(string $provider, ?string $country): string
    {
        if (!$this->applies()) {
            return self::PLATFORM_WIDE;
        }

        $code = $this->countryKey($country);

        if ($code === self::PLATFORM_WIDE) {
            return self::PLATFORM_WIDE;
        }

        return $this->listInForce["{$provider}:{$code}"] ??= DB::table(self::TABLE)
            ->where('provider', $provider)
            ->where(self::COLUMN, $code)
            ->exists()
                ? $code
                : self::PLATFORM_WIDE;
    }

    /** The country a row of a NEW import is stamped with, upper case and byte for byte as the column is declared. */
    public function stampFor(?string $country): string
    {
        return $this->applies() ? $this->countryKey($country) : self::PLATFORM_WIDE;
    }

    /** Forgets which list each country reads, for a test that imports one mid-run. */
    public function forget(): void
    {
        $this->columnReady = null;
        $this->listInForce = [];
    }

    private function countryKey(?string $country): string
    {
        $code = strtoupper(trim((string) $country));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : self::PLATFORM_WIDE;
    }
}
