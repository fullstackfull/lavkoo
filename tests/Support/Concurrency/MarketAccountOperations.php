<?php

namespace Tests\Support\Concurrency;

use App\Enums\Wallet\MarketBalanceKind;
use App\Services\Wallet\CustomerMarketBalances;

/**
 * What a worker process does in the market account concurrency test: one credit to one customer's account in one market, through the
 * service, with the key on in that process.
 *
 * The interleaving this exists for is the FIRST movement a market ever sees. Two writers then arrive at an account that is not there yet,
 * and whether they queue or kill each other is a property of the engine's locking, not of PHP: a locking read that misses holds the gap of
 * the unique key rather than a record, and two inserts into each other's gap deadlock. SQLite cannot show it — its in-memory database is
 * private to one process — so this only ever runs on the real engines.
 */
final class MarketAccountOperations
{
    /**
     * @param array{kind: string, customer: int, market: string, amount: float, hold_microseconds?: int} $args
     * @return array{applied: bool, refusal: string|null, amount: float, pid: int}
     */
    public static function credit(array $args): array
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => true,
            'gcc.storefronts.market_accounts.enabled' => true,
        ]);
        usleep((int) ($args['hold_microseconds'] ?? 0));

        $change = app(CustomerMarketBalances::class)->credit(
            kind: MarketBalanceKind::from((string) $args['kind']),
            customerId: (int) $args['customer'],
            market: (string) $args['market'],
            amount: (float) $args['amount'],
        );

        return ['applied' => $change->applied, 'refusal' => $change->refusal?->value, 'amount' => $change->amount, 'pid' => getmypid()];
    }
}
