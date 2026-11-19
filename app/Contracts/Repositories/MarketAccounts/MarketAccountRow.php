<?php

namespace App\Contracts\Repositories\MarketAccounts;

/**
 * One market account as a repository hands it out: what it is, in which currency, and what it holds right now. The Eloquent model stays
 * inside the repository, so a service changes an account only by asking the repository to, never by saving a model of its own.
 */
final readonly class MarketAccountRow
{
    public function __construct(
        public int $id,
        public string $market,
        public string $currencyCode,
        public float $amount,
    ) {
    }
}
