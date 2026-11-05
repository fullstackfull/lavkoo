<?php

namespace App\Services\Fulfillment\Support;

interface CurrencyRateSource
{
    /** 'multi_currency' or 'single_currency'. */
    public function model(): string;

    /** Exchange rate (relative to the platform's USD base) for a currency code, or null if unknown. */
    public function rate(string $code): ?float;

    /** Code of the platform's default currency (what stored amounts are in under the single-currency model). */
    public function defaultCode(): ?string;

    public function decimals(): int;
}
