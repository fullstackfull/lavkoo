<?php

namespace App\Services\OperationalCosts\Support;

use App\Services\Fulfillment\Support\CurrencyCode;
use App\Services\Fulfillment\Support\CurrencyRateSource;

/**
 * Converts an amount between two currencies with the store's own rates, or says it cannot.
 *
 * The store keeps each currency's rate relative to one base (USD). A conversion needs both rates; with either unknown, or in a store that
 * runs a single currency and is asked for another, the answer is null: the caller keeps the amount in its own currency and flags the
 * total as incomplete. It never guesses a rate and never treats a missing rate as 1.
 */
class FxConverter
{
    public function __construct(private readonly CurrencyRateSource $rates)
    {
    }

    /** @return array{rate: Money, amount: Money}|null */
    public function convert(Money $amount, string $from, string $to): ?array
    {
        $from = CurrencyCode::canonical($from);
        $to = CurrencyCode::canonical($to);
        if ($from === null || $to === null) {
            return null;
        }
        if ($from === $to) {
            return ['rate' => Money::of(1), 'amount' => $amount];
        }
        if ($this->rates->model() !== 'multi_currency') {
            return null;
        }

        $fromRate = $this->rates->rate($from);
        $toRate = $this->rates->rate($to);
        if ($fromRate === null || $toRate === null || $fromRate <= 0.0 || $toRate <= 0.0) {
            return null;
        }

        // Both rates are "units of that currency per base unit", so one unit of $from is ($toRate / $fromRate) units of $to.
        $rate = Money::of($toRate)->dividedBy($fromRate, 10);

        return ['rate' => $rate, 'amount' => $amount->times($rate)];
    }

    public function baseCurrency(): string
    {
        return CurrencyCode::canonical(config('operational_costs.base_currency', 'SAR')) ?? 'SAR';
    }
}
