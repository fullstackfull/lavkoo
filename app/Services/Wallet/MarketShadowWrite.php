<?php

namespace App\Services\Wallet;

use App\Enums\Wallet\MarketBalanceKind;
use App\Enums\Wallet\MarketBalanceRefusal;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Log;

/**
 * The shadow half of the move to per-market balances (E-07): every movement written to the one balance a customer has is mirrored into
 * their account in the market that movement names, in the same transaction, and the two are compared.
 *
 * It is a mirror and never an authority. The `users` row stays the only balance anything reads, the writers that keep it are untouched,
 * and a mirror that cannot be made — a market nobody named, an account the backfill has not opened, a figure the account refuses — is
 * written down and never allowed to fail the write it was following. That is the whole point of a shadow: it is how the two shapes are
 * proved to agree before a market is switched over to read its own account, and it must not be able to break the shape that works.
 *
 * It hangs off the two ledgers rather than off the writers. There are five writers of a wallet balance and two of a points balance, and
 * each of them ends by saving a ledger row inside the customer's balance lock; a mirror at each would be seven copies of one rule, which
 * is how every drift in this part of the application started. The row also already carries what the mirror needs: the market, from the
 * attribution the writer asked for (E-05), and the amounts.
 *
 * The movement is read as the balance writers compute it: credit plus the add-fund bonus, less the debit. The bonus is part of what the
 * `users` column moves by, so a mirror that left it out would disagree with the balance by exactly the bonus on every top-up — and the
 * ledger row's own `balance` column does leave it out, which is a separate open point (E-05.4) and not something to copy here.
 *
 * Dormant unless `gcc.storefronts.market_accounts.shadow_writes` is on, which needs the market accounts themselves to be on. While it is
 * off nothing here asks the database anything.
 */
#[Singleton]
final class MarketShadowWrite
{
    /** How far the mirrored account and the one balance may differ before it is written down, in the unit of the balance. */
    private const TOLERANCE = 0.000001;

    public function __construct(private readonly CustomerMarketBalances $balances)
    {
    }

    public function isEnabled(): bool
    {
        return (bool) config('gcc.storefronts.market_accounts.shadow_writes') && $this->balances->isEnabled();
    }

    /**
     * Mirrors one ledger row into the account of the market it names.
     *
     * @param object $row the ledger row as it was saved: its market, its credit, its debit and, on a wallet row, its add-fund bonus
     */
    public function mirror(MarketBalanceKind $kind, object $row): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $market = strtoupper(trim((string) ($row->{MarketBalanceKind::MARKET_COLUMN} ?? '')));
        $customerId = $row->user_id ?? null;

        if ($market === '' || $customerId === null) {
            return;
        }

        $delta = (float) ($row->credit ?? 0) + (float) ($row->admin_bonus ?? 0) - (float) ($row->debit ?? 0);

        if (abs($delta) <= self::TOLERANCE) {
            return;
        }

        $change = $this->balances->moveOpenAccount(kind: $kind, customerId: $customerId, market: $market, delta: $delta);

        if (!$change->applied) {
            $this->report('wallet.market_shadow_refused', $kind, $customerId, $market, [
                'refusal' => $change->refusal?->value,
                'because' => $change->refusal?->because(),
                'delta' => $delta,
            ]);

            return;
        }

        $this->compare(kind: $kind, customerId: $customerId, market: $market, held: $change->amount);
    }

    /**
     * The comparison the shadow exists for: what the account now holds against the one balance the platform reads.
     *
     * They are expected to agree only while the market is the one the customer's whole balance belongs to, which is the Saudi market
     * until another is switched over. A movement in a second market moves that market's account and the same one global balance, so the
     * two legitimately part company; that is what a divergence line is for — somebody reads it and decides, rather than the mirror
     * deciding for itself that one of them is wrong.
     */
    private function compare(MarketBalanceKind $kind, int|string $customerId, string $market, float $held): void
    {
        $balance = (float) User::query()->whereKey($customerId)->value($kind->legacyColumn());

        if (abs($held - $balance) <= self::TOLERANCE) {
            return;
        }

        $this->report('wallet.market_shadow_divergence', $kind, $customerId, $market, [
            'account' => $held,
            'balance' => $balance,
            'difference' => round($held - $balance, 8),
        ]);
    }

    /** @param array<string, mixed> $detail */
    private function report(string $event, MarketBalanceKind $kind, int|string $customerId, string $market, array $detail): void
    {
        Log::warning($event, ['ledger' => $kind->value, 'customer_id' => $customerId, 'market' => $market] + $detail);
    }
}
