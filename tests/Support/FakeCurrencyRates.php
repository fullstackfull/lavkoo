<?php

namespace Tests\Support;

use App\Services\Fulfillment\Support\CurrencyRateSource;

/** A currency table held in memory: rates are "units of the currency per base (USD) unit". */
final class FakeCurrencyRates implements CurrencyRateSource
{
    /** @param array<string, float> $rates */
    public function __construct(private readonly array $rates = ['USD' => 1.0, 'SAR' => 3.75, 'TRY' => 40.0], private readonly string $model = 'multi_currency')
    {
    }

    public function model(): string
    {
        return $this->model;
    }

    public function rate(string $code): ?float
    {
        return $this->rates[strtoupper($code)] ?? null;
    }

    public function defaultCode(): ?string
    {
        return 'SAR';
    }

    public function decimals(): int
    {
        return 2;
    }
}
