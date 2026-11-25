<?php

namespace App\Services\Address;

use App\Models\AddressCity;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontMarketRows;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Which country's namespace a city alias lives in (H1-10).
 *
 * An alias is a SPELLING a person typed: "al khobar" is a spelling of our Khobar. Two countries may both have a city whose name
 * normalises that way, so one alias table shared across six markets has to keep the spellings apart or the legacy matcher resolves a
 * customer's typed city to another country's city -- which is how a parcel crosses a border with nothing to say so.
 *
 * **One market, one namespace.** `''` is the DEFAULT storefront's, which is where every alias on this platform already is, and the
 * default storefront's own code normalises BACK to `''` so that a market can never end up with two namespaces (some rows `''`, some
 * `'SA'`) and the unique key stop biting. A country the platform has no storefront for gets `''` too: there is nowhere else to put it,
 * and the default market is where the legacy data is.
 *
 * It is dormant before the migration: `applies()` is false, nothing is narrowed, nothing is written, and the table behaves exactly as it
 * does today -- one namespace, which is the same answer.
 */
#[Scoped]
final class CityAliasNamespace
{
    public const COLUMN = 'country_code';

    /** The namespace of the default storefront, and of everything written before markets existed. */
    public const DEFAULT_STOREFRONT = '';

    private const TABLE = 'address_city_aliases';

    private ?bool $columnReady = null;

    /** Whether an alias can carry a country at all, and whether anything should read or write it. */
    public function applies(): bool
    {
        return $this->columnReady ??= Schema::hasTable(self::TABLE) && Schema::hasColumn(self::TABLE, self::COLUMN);
    }

    /** The namespace a country's aliases live in: its own code, or `''` for the default storefront and for a country we do not serve. */
    public function of(?string $country): string
    {
        if (!$this->applies()) {
            return self::DEFAULT_STOREFRONT;
        }

        $code = strtoupper(trim((string) $country));

        if ($code === '' || $code === $this->defaultCountry() || !in_array($code, $this->configuredCountries(), true)) {
            return self::DEFAULT_STOREFRONT;
        }

        return $code;
    }

    /** The namespace the aliases of one of OUR cities live in, read from the city's region. */
    public function forCity(AddressCity|int|null $city): string
    {
        if (!$this->applies() || $city === null) {
            return self::DEFAULT_STOREFRONT;
        }

        $row = $city instanceof AddressCity ? $city : AddressCity::query()->with('region')->find($city);
        $row?->loadMissing('region');

        return $this->of($row?->region?->country_code);
    }

    /** The namespace of the storefront this request is being served from, which is the default one everywhere it is not bound. */
    public function ofTheRequest(): string
    {
        return $this->of(StorefrontContext::current()->country);
    }

    /**
     * Narrows an alias query to one namespace. Before the migration it narrows nothing, which is the same answer: one namespace.
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $aliases
     */
    public function narrow(Builder $aliases, string $namespace): Builder
    {
        return $this->applies() ? $aliases->where($aliases->getModel()->qualifyColumn(self::COLUMN), $namespace) : $aliases;
    }

    /**
     * The namespace as a writer adds it to the row it is making: nothing at all before the migration, so an insert written then is the
     * insert this platform has always written.
     *
     * @return array<string, string>
     */
    public function stamp(string $namespace): array
    {
        return $this->applies() ? [self::COLUMN => $namespace] : [];
    }

    /** Forgets what it read about the schema, for a test that runs the migration mid-run. */
    public function forget(): void
    {
        $this->columnReady = null;
    }

    /** Asked of `StorefrontMarketRows`, which is the one place that answers which market the unmarked rows of any table belong to. */
    private function defaultCountry(): string
    {
        return app(StorefrontMarketRows::class)->defaultCountry();
    }

    /** @return list<string> */
    private function configuredCountries(): array
    {
        return array_map(strtoupper(...), array_keys((array) config('gcc.storefronts.countries', [])));
    }
}
