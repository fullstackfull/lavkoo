<?php

namespace App\Services\Fulfillment;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The execution source a product is fulfilled from, as the admin product list shows and filters it (C-01).
 *
 * One product has exactly one source: the in-house default, or one carrier. It is stored in `products.fulfillment_provider`, where an
 * EMPTY value means in-house -- there is no row to add and nothing to back-fill, which is why the column was made that way.
 *
 * Three answers, not two. A stored value this installation does not know is its own answer, `unknown`, and the list says so instead of
 * printing "in house": the admin screens are where an operator would find out that a carrier was removed from the configuration while
 * products still name it, and showing those products as in-house would hide exactly the state C-01's first criterion is about. The
 * CHECKOUT's behaviour for such a value is a separate, open question (whether it refuses or holds the line); this only stops the screen
 * from lying about it.
 *
 * Nothing shows while the column has not been added: `isTracked()` is false, the column and the filter are not rendered, and the list is
 * the list it has always been.
 */
final class ProductSource
{
    public const COLUMN = 'fulfillment_provider';

    /** The product is fulfilled by the store itself. Stored as an empty value, because that is what every product had before carriers. */
    public const IN_HOUSE = 'in_house';

    /** The row names a carrier this installation does not know. Never stored: it is what a stored value READS as. */
    public const UNKNOWN = 'unknown';

    /** Whether the tools show and filter a product's source at all. */
    public static function isTracked(): bool
    {
        return FulfillmentSchema::productColumnReady();
    }

    /**
     * What the filter offers: in-house first, because it is the default and the most common, then every carrier this installation knows,
     * then `unknown` -- but only while some product actually names a carrier nobody knows, so the filter does not offer an empty answer.
     *
     * @return list<string>
     */
    public static function options(): array
    {
        if (!self::isTracked()) {
            return [];
        }

        $options = [self::IN_HOUSE, ...array_map(static fn ($identity): string => $identity->code(), app(ProviderRegistry::class)->identities())];

        return self::anyProductNamesAnUnknownCarrier() ? [...$options, self::UNKNOWN] : $options;
    }

    /** The source of one row: in-house for an empty value, the carrier's code when this installation knows it, `unknown` otherwise. */
    public static function of(mixed $storedValue): string
    {
        $code = trim((string) $storedValue);

        if ($code === '') {
            return self::IN_HOUSE;
        }

        return app(ProviderRegistry::class)->isKnown($code) ? $code : self::UNKNOWN;
    }

    /** The name a person reads. A carrier gives its own; the two answers that are not carriers are translated. */
    public static function label(string $source): string
    {
        if ($source === self::IN_HOUSE || $source === self::UNKNOWN) {
            return translate($source === self::IN_HOUSE ? 'in_house_fulfillment' : 'unknown_fulfillment_source');
        }

        return app(ProviderRegistry::class)->identity($source)?->label() ?? $source;
    }

    /**
     * The sources a request asked to be shown, keeping only answers this installation offers, in the order the options are declared so
     * that two requests with the same boxes ticked ask the same question.
     *
     * @return list<string>
     */
    public static function filterOf(mixed $requestedSources): array
    {
        if (!self::isTracked()) {
            return [];
        }

        $asked = array_filter(Arr::wrap($requestedSources), 'is_string');

        return array_values(array_intersect(self::options(), array_unique($asked)));
    }

    /**
     * Narrows a product query to the sources given. An empty list narrows nothing, as every other filter of this panel does.
     *
     * In-house is an empty value OR null, and `unknown` is "names something, and not one of the carriers we know" -- which has to be
     * written as the negation of the known codes rather than as a list of its own, because what is unknown is not enumerable.
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     * @param list<string> $sources
     */
    public static function narrow(Builder $query, array $sources): Builder
    {
        $wanted = self::filterOf($sources);

        if ($wanted === []) {
            return $query;
        }

        $column = $query->getModel()->qualifyColumn(self::COLUMN);
        $carriers = array_values(array_diff($wanted, [self::IN_HOUSE, self::UNKNOWN]));

        return $query->where(static function (Builder $inner) use ($column, $wanted, $carriers): void {
            if (in_array(self::IN_HOUSE, $wanted, true)) {
                $inner->orWhereNull($column)->orWhere($column, '');
            }

            if ($carriers !== []) {
                $inner->orWhereIn($column, $carriers);
            }

            if (in_array(self::UNKNOWN, $wanted, true)) {
                $inner->orWhere(static function (Builder $unknown) use ($column): void {
                    $unknown->whereNotNull($column)->where($column, '<>', '')->whereNotIn($column, self::knownCodes());
                });
            }
        });
    }

    /** @return list<string> */
    private static function knownCodes(): array
    {
        return array_map(static fn ($identity): string => $identity->code(), app(ProviderRegistry::class)->identities());
    }

    private static function anyProductNamesAnUnknownCarrier(): bool
    {
        return DB::table('products')
            ->whereNotNull(self::COLUMN)
            ->where(self::COLUMN, '<>', '')
            ->whereNotIn(self::COLUMN, self::knownCodes())
            ->exists();
    }
}
