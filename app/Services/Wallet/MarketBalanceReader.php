<?php

namespace App\Services\Wallet;

use App\Enums\Wallet\MarketBalanceKind;
use App\Models\User;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Container\Attributes\Singleton;

/**
 * The balance a market answers: its own account's, for a market that has been switched over, and the one `users` column for every other
 * (E-01, E-04; GCC-15 slice 3).
 *
 * The accounts exist and are mirrored (E-07), but nothing read them: every surface still read `users.wallet_balance` and
 * `users.loyalty_point`, so a customer with a hundred dinars in Kuwait and nothing in Riyadh saw one number that was neither. This is the
 * switch that moves one market at a time onto its own account — the reads first, because a read tells a customer what they have and a
 * refusal tells them what they cannot spend, and neither moves money.
 *
 * **It ships answering exactly what the platform answers today.** `gcc.storefronts.market_accounts.answers_for` is empty, so every market
 * reads the `users` column, and the fallback is that column itself rather than a query of its own — the same figure, from the same row the
 * caller already had in hand. A market is only listed after the backfill has been rehearsed and approved: a market switched over before
 * its accounts were filled would read as a customer with nothing.
 *
 * **The writes are not changed by this.** Every movement still goes to the `users` column and is mirrored into the account of the market
 * it names. Once a second market holds money the two legitimately part company, which is what the mirror's divergence line is for
 * ({@see MarketShadowWrite}); this class decides which of the two a reader is shown, and nothing else.
 *
 * **A market nobody is serving keeps the column.** `StorefrontCatalog::country()` answers null for a panel, a seller API, a queue, a
 * console command and every request while storefronts are off. Such a reader sees every market at once or none, so the one global balance
 * is the only answer that is not a lie about a market it did not ask about.
 */
#[Singleton]
final class MarketBalanceReader
{
    public function __construct(private readonly CustomerMarketBalances $accounts)
    {
    }

    /** Whether the market being served answers from its own account rather than from the one balance the `users` row holds. */
    public function answersFromItsAccount(?string $market = null): bool
    {
        $code = strtoupper(trim((string) ($market ?? StorefrontCatalog::country())));

        return $code !== '' && $this->accounts->isEnabled() && in_array($code, $this->marketsAnsweringFromTheirAccount(), true);
    }

    /**
     * The markets whose own account is the balance they answer, upper-cased and without the blanks a configuration may carry.
     *
     * @return list<string>
     */
    public function marketsAnsweringFromTheirAccount(): array
    {
        $configured = array_map(
            static fn (mixed $code): string => strtoupper(trim((string) $code)),
            (array) config('gcc.storefronts.market_accounts.answers_for'),
        );

        return array_values(array_unique(array_filter($configured, static fn (string $code): bool => $code !== '')));
    }

    /**
     * What this customer has to spend in the market being served.
     *
     * The customer is passed as the row the caller already read, so a market that is not switched over costs no query **and the value is
     * handed back untouched** -- not cast, not rounded, the same thing the caller would have read itself. That matters more than it looks:
     * the apps read `wallet_balance` as a number with decimals and `loyalty_point` as a whole number, and a cast here would change the
     * shape of an answer that is under contract (E-04). On the account path the points are rounded to a whole number for the same reason,
     * because an account column holds decimals and a point has never had any.
     */
    public function balance(MarketBalanceKind $kind, User $customer, ?string $market = null): float|int
    {
        $code = strtoupper(trim((string) ($market ?? StorefrontCatalog::country())));

        if (!$this->answersFromItsAccount($code)) {
            return $customer->{$kind->legacyColumn()};
        }

        $held = $this->accounts->amount(kind: $kind, customerId: $customer->id, market: $code);

        return $kind === MarketBalanceKind::Loyalty ? (int) round($held) : $held;
    }

    /** The wallet money of the market being served. */
    public function wallet(User $customer, ?string $market = null): float|int
    {
        return $this->balance(kind: MarketBalanceKind::Wallet, customer: $customer, market: $market);
    }

    /** The loyalty points of the market being served, which are whole numbers wherever they are read from. */
    public function points(User $customer, ?string $market = null): int
    {
        return (int) $this->balance(kind: MarketBalanceKind::Loyalty, customer: $customer, market: $market);
    }
}
