<?php

namespace App\Contracts\Repositories\MarketAccounts;

/**
 * What both market account repositories do: read one customer's account in one market, and change what it holds while its row is locked.
 *
 * It is deliberately narrow. These accounts have no panel screen yet, so the six methods of `RepositoryInterface` would be unused code; the
 * repository a screen needs extends this one when that screen is built. It lives in a sub-namespace so the convention-based binder in
 * `App\Providers\InterfaceServiceProvider`, which pairs each file of `app/Repositories` with the interface of the same name, never sees it.
 */
interface MarketAccountRepositoryInterface
{
    /** What the customer holds in this market, or null when they have no account there. */
    public function amountOf(int|string $customerId, string $market): ?float;

    /**
     * The customer's account in this market with its row locked for the rest of the transaction, or null when they have none. Call it inside
     * a transaction: outside one the lock is released at once and two writers can still overwrite each other.
     */
    public function locked(int|string $customerId, string $market): ?MarketAccountRow;

    /**
     * Opens the account with nothing in it, or leaves the one that is already there alone. Call it BEFORE the transaction that locks the
     * account, never inside it: see the note on the implementation for what holding an opening inside a transaction does to two writers
     * racing for the same new account.
     */
    public function open(int|string $customerId, string $market, string $currencyCode, ?string $legalEntity): void;

    /** Writes what the account holds. The row must already be locked by `locked` in the same transaction. */
    public function writeAmount(int $accountId, float $amount): void;
}
