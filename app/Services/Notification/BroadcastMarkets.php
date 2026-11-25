<?php

namespace App\Services\Notification;

use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontPushTopics;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;
use JsonException;

/**
 * Which markets one admin broadcast is for (I1-04, A1-17).
 *
 * The four questions a broadcast asks about markets are answered here once, because they have to agree with each other exactly: which
 * markets an operator may choose, what is stored, which push topics it is published to, and which customers' app inbox lists it. A second
 * copy of any of them is a broadcast that is published to Kuwait and listed in Saudi Arabia.
 *
 * **No market chosen means EVERY market**, which is the platform of today and every row an installation already has -- see the migration for
 * why that is the opposite of the convention the configuration tables use. A market chosen means that market and no other.
 *
 * **The topic a market's broadcast is published to is the market's own**, built by {@see StorefrontPushTopics} so that the server and the
 * app build one name in one place; the platform-wide topic is still what an unmarked broadcast goes to, so a device of an app build older
 * than the market topics is not cut off.
 */
#[Singleton]
final class BroadcastMarkets
{
    public const COLUMN = 'storefront_countries';

    public const TABLE = 'notifications';

    private ?bool $columnReady = null;

    /** Whether a broadcast can name markets at all: storefronts are on and the migration has run. */
    public function applies(): bool
    {
        return (bool) config('gcc.storefronts.enabled', false) && $this->columnExists();
    }

    /**
     * The markets an operator may choose from, narrowed to the employee's own.
     *
     * An employee restricted to Kuwait is offered Kuwait, so a broadcast of theirs can reach Kuwait and nothing else; the "every market"
     * choice is not theirs to make and the screen does not offer it (see {@see mayChooseEveryMarket()}).
     *
     * @return list<string>
     */
    public function choices(): array
    {
        if (!$this->applies()) {
            return [];
        }

        $configured = StorefrontResolver::fromConfig()->configuredCountries();
        $allowed = app(StaffCountryScope::class)->countriesOf(admin: auth('admin')->user());

        return $allowed === null ? $configured : array_values(array_intersect($configured, $allowed));
    }

    /** Whether this employee may send a broadcast to every market. A restricted employee may not: their reach is their own markets. */
    public function mayChooseEveryMarket(): bool
    {
        return app(StaffCountryScope::class)->countriesOf(admin: auth('admin')->user()) === null;
    }

    /**
     * What to store for the markets a broadcast is for -- an already accepted list, so that the markets stored and the topics published to
     * are the same list and cannot drift.
     *
     * @param list<string> $markets
     * @return array{storefront_countries: string|null}|array{} the column to write, or nothing at all while the migration has not run
     */
    public function columnFor(array $markets): array
    {
        return $this->columnExists() ? [self::COLUMN => $this->encode($markets)] : [];
    }

    /**
     * Whether this employee may send a broadcast at all.
     *
     * An employee whose country scope grants them NO market reaches nobody, and an empty list of markets means "every market" -- so without
     * this question such an employee would send platform-wide, which is the widest reach on the platform and the exact opposite of what their
     * scope says. While storefronts are off nothing is narrowed and every employee may broadcast, as they always have.
     */
    public function mayBroadcast(): bool
    {
        return !$this->applies() || $this->mayChooseEveryMarket() || $this->choices() !== [];
    }

    /**
     * The markets of the chosen value that this employee may actually send to, in the order they were configured.
     *
     * An empty list is returned ONLY for an employee who may reach every market: that is what an unmarked broadcast has always been. For a
     * RESTRICTED employee the result is always one or more of their own markets, whatever the form carried -- nothing chosen, a market that
     * is not theirs, or a code that is not a market at all -- because "every market" is a reach they do not have and a form value must never
     * be able to widen it. An employee whose scope grants nothing is refused before this, by {@see mayBroadcast()}.
     *
     * @return list<string>
     */
    public function accepted(mixed $chosen): array
    {
        if (!$this->applies()) {
            return [];
        }

        $choices = $this->choices();
        $asked = array_values(array_filter(array_map(
            static fn (mixed $code): string => strtoupper(trim((string) $code)),
            is_array($chosen) ? $chosen : ($chosen === null || $chosen === '' ? [] : [$chosen]),
        )));
        $accepted = $asked === [] ? [] : array_values(array_intersect($choices, $asked));

        return $accepted !== [] || $this->mayChooseEveryMarket() ? $accepted : $choices;
    }

    /**
     * The markets a stored broadcast is for, as the row holds them.
     *
     * @return list<string> empty for every market
     */
    public function of(mixed $stored): array
    {
        if (!is_string($stored) || trim($stored) === '') {
            return [];
        }

        try {
            $decoded = json_decode($stored, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded)
            ? array_values(array_filter(array_map(static fn (mixed $code): string => strtoupper(trim((string) $code)), $decoded)))
            : [];
    }

    /**
     * The push topics one broadcast is published to: one per market it names, else the platform-wide topic it has always gone to.
     *
     * @param list<string> $markets
     * @return list<string>
     */
    public function topicsFor(array $markets): array
    {
        if ($markets === []) {
            return [StorefrontPushTopics::CUSTOMER_MARKETING];
        }

        return array_values(array_filter(array_map(
            static fn (string $market): ?string => StorefrontPushTopics::customerMarketing(country: $market),
            $markets,
        )));
    }

    /**
     * Narrows a query of the broadcasts to the ones a customer of the market in force should be shown: the ones that name their market and
     * the ones that name none.
     *
     * While storefronts are off, or before the migration has run, the query comes back untouched and the inbox is the one every customer has
     * always been shown.
     */
    public function onlyForTheMarketInForce(Builder $query): Builder
    {
        if (!$this->applies()) {
            return $query;
        }

        $market = strtoupper(trim(StorefrontContext::current()->country));

        return $query->where(static function (Builder $scoped) use ($market): void {
            $scoped->whereNull(self::COLUMN)
                ->orWhere(self::COLUMN, '')
                ->orWhere(self::COLUMN, '[]')
                // The stored list is a JSON array of two-letter codes, so the market's own code IN QUOTES cannot match a fragment of
                // another: "KW" is not a substring of any other code, and the quotes stop a future value from colliding with one.
                // The column takes the server's own `utf8mb4_unicode_ci` collation, so this match is case-INSENSITIVE -- which is the safe
                // direction and is deliberate rather than overlooked: it can only widen the match, never hide a market's own broadcast from
                // it, and nothing but `accepted()` writes the column, which upper-cases every code it stores.
                ->orWhere(self::COLUMN, 'like', '%"' . $market . '"%');
        });
    }

    /** Forgets what was read about the schema, for a test that adds the column after something has already asked. */
    public function flush(): void
    {
        $this->columnReady = null;
    }

    /**
     * @param list<string> $markets
     */
    private function encode(array $markets): ?string
    {
        if ($markets === []) {
            return null;
        }

        try {
            return json_encode(array_values(array_unique($markets)), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    private function columnExists(): bool
    {
        return $this->columnReady ??= Schema::hasTable(self::TABLE) && Schema::hasColumn(self::TABLE, self::COLUMN);
    }
}
