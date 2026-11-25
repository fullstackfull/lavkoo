<?php

namespace App\Services\Notification;

use App\Models\Subscription;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Which market a newsletter signup belongs to, and whose subscribers one send reaches (I1-04).
 *
 * `subscriptions` held an e-mail address and nothing else, so six markets shared one list and the only thing an operator could do was send
 * to all of it -- a message whose offer is in one market's currency, for one market's catalogue, reaching people who cannot buy from it.
 *
 * Three questions, answered here so the signup, the list and the export cannot disagree:
 *
 *  - which market a signup is recorded in: the market the visitor is on;
 *  - whether an address is already subscribed: in THAT MARKET, which is the part that had to change. The duplicate check was global, so a
 *    Kuwaiti visitor whose address was already on the Saudi list was told they had already subscribed and no Kuwaiti row was written --
 *    the Kuwaiti operator's list would simply never hold them;
 *  - which subscribers a list or an export may reach, narrowed to the market chosen and to the operator's own markets.
 *
 * **NULL is the default storefront's**, not "every market": an unmarked subscriber signed up on the only storefront there has ever been.
 * That is the opposite of the broadcast convention and the migration says why.
 *
 * Nothing is read while storefronts are off or before the migration has run: a signup writes the row it has always written, the duplicate
 * check is the global one, and the list is the whole list.
 */
#[Singleton]
final class NewsletterMarkets
{
    public const COLUMN = 'storefront_country';

    public const TABLE = 'subscriptions';

    /** Where the markets one request's screens are narrowed to live: on that request, never in the container, a static or the cache. */
    private const REQUEST_ATTRIBUTE = 'newsletter_markets';

    private ?bool $columnReady = null;

    /** Whether a signup can belong to a market at all: storefronts are on and the migration has run. */
    public function applies(): bool
    {
        return (bool) config('gcc.storefronts.enabled', false) && $this->columnExists();
    }

    /** The market a signup made now belongs to, or null while the mechanism is dormant. */
    public function marketOfThisSignup(): ?string
    {
        return $this->applies() ? strtoupper(trim(StorefrontContext::current()->country)) : null;
    }

    /**
     * What to write for the market of a new signup, so the writer never has to know whether the column is there.
     *
     * @return array{storefront_country: string}|array{}
     */
    public function columnForThisSignup(): array
    {
        $market = $this->marketOfThisSignup();

        return $market === null ? [] : [self::COLUMN => $market];
    }

    /**
     * Whether this address is already on the list of the market the visitor is on.
     *
     * The default storefront also owns the rows that name no market, so a Saudi visitor whose address was subscribed before the markets
     * existed is still told they have already subscribed -- which is the answer they get today.
     */
    public function alreadySubscribed(string $email): bool
    {
        $query = Subscription::query()->where('email', $email);

        if (!$this->applies()) {
            return $query->exists();
        }

        return $this->onlyTheseMarkets($query, [(string) $this->marketOfThisSignup()])->exists();
    }

    /**
     * The markets an operator may list, narrowed to their own.
     *
     * @return list<string>
     */
    public function choices(): array
    {
        if (!$this->applies()) {
            return [];
        }

        $configured = StorefrontResolver::fromConfig()->configuredCountries();
        $allowed = $this->scope();

        return $allowed === null ? $configured : array_values(array_intersect($configured, $allowed));
    }

    /** Whether this operator may see every market's subscribers at once. */
    public function mayChooseEveryMarket(): bool
    {
        return $this->scope() === null;
    }

    /** The market the screen is showing, as the chooser should render it. */
    public function chosen(mixed $asked): ?string
    {
        $asked = is_string($asked) ? strtoupper(trim($asked)) : '';

        return $asked !== '' && in_array($asked, $this->choices(), true) ? $asked : null;
    }

    /**
     * The markets a list or an export may reach. Null narrows nothing, and is returned only while the mechanism is dormant or for an
     * operator who may reach every market and has chosen none -- the screen of today.
     *
     * @return list<string>|null
     */
    public function inForce(mixed $asked): ?array
    {
        if (!$this->applies()) {
            return null;
        }

        $chosen = $this->chosen($asked);

        if ($chosen !== null) {
            return [$chosen];
        }

        return $this->mayChooseEveryMarket() ? null : $this->choices();
    }

    /**
     * Narrows a query of the subscribers to these markets, the default storefront's also covering the rows that name none.
     *
     * @param list<string> $markets
     */
    public function onlyTheseMarkets(Builder $query, array $markets): Builder
    {
        $markets = array_values(array_filter(array_map(static fn (mixed $code): string => strtoupper(trim((string) $code)), $markets)));

        if (!$this->applies() || $markets === []) {
            return $query;
        }

        $default = strtoupper(trim(StorefrontResolver::fromConfig()->defaultCountry()));

        return $query->where(function (Builder $scoped) use ($markets, $default): void {
            $scoped->whereIn(self::COLUMN, $markets);

            if (in_array($default, $markets, true)) {
                $scoped->orWhereNull(self::COLUMN);
            }
        });
    }

    /** The market a stored row belongs to, for a screen that prints it: the default storefront's for a row that names none. */
    public function of(mixed $stored): string
    {
        $code = is_string($stored) ? strtoupper(trim($stored)) : '';

        return $code !== '' ? $code : strtoupper(trim(StorefrontResolver::fromConfig()->defaultCountry()));
    }

    /**
     * Narrows the subscriber screens of THIS request to the market chosen, and to the operator's own markets.
     *
     * Bound to the request and read by {@see \App\Models\Scopes\NewsletterMarketScope} rather than passed to the repository, for the
     * reason the staff product scope is a scope: the list and the export go through the same repository method with different arguments,
     * and one rule on the model narrows both -- including whatever reads the table next -- without changing a shared interface.
     *
     * @return list<string>|null what the request was narrowed to, or null when it is not narrowed
     */
    public function bind(Request $request, mixed $asked): ?array
    {
        $markets = $this->inForce($asked);
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $markets);

        return $markets;
    }

    /**
     * The markets this request's subscriber reads are narrowed to, or null when nothing bound any: a storefront page, a queue, a console
     * command, and every screen while the mechanism is dormant.
     *
     * @return list<string>|null
     */
    public function boundMarkets(): ?array
    {
        $markets = request()->attributes->get(self::REQUEST_ATTRIBUTE);

        return is_array($markets) && $markets !== [] ? array_values($markets) : null;
    }

    /** Forgets what was read about the schema, for a test that adds the column after something has already asked. */
    public function flush(): void
    {
        $this->columnReady = null;
    }

    /** @return list<string>|null null when the operator is not restricted */
    private function scope(): ?array
    {
        return app(StaffCountryScope::class)->countriesOf(admin: auth('admin')->user());
    }

    private function columnExists(): bool
    {
        return $this->columnReady ??= Schema::hasTable(self::TABLE) && Schema::hasColumn(self::TABLE, self::COLUMN);
    }
}
