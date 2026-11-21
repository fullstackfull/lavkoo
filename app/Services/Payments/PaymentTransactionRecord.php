<?php

namespace App\Services\Payments;

/**
 * What a payment belongs to, as it was when the payment started (D2-10): the market, the selling company, the gateway account it is taken through, and
 * the exchange rate in force for its currency.
 *
 * Frozen, and read as stored. Every one of these four can move after a payment starts — a storefront is reconfigured, a company is edited, an account
 * is disabled, a rate is retyped — and a figure re-derived at read time is how a settled payment comes to disagree with the money it took. The same
 * discipline an order's company and currency snapshot already uses (App\Services\Storefront\OrderLegalEntitySnapshot).
 *
 * The rate is kept as the plain text that was read, not as a float: `currencies.exchange_rate` is a text column, and turning "3.75" into a float and
 * back is a way to store 3.7499999999999996 in a financial record.
 */
final readonly class PaymentTransactionRecord
{
    /** @param array<string, int|string|null> $company the company's identity as the registry freezes it */
    public function __construct(
        public ?string $market,
        public ?int $companyId,
        public array $company,
        public ?int $accountId,
        public ?string $gateway,
        public ?string $environment,
        public ?string $currency,
        public ?string $exchangeRate,
        public ?string $recordedAt,
    ) {
    }

    /** @param array<string, mixed> $stored what PaymentAccountBinding wrote under its key */
    public static function fromStored(array $stored): self
    {
        return new self(
            market: self::text($stored['market'] ?? null),
            companyId: isset($stored['company_id']) && is_numeric($stored['company_id']) ? (int) $stored['company_id'] : null,
            company: is_array($stored['company'] ?? null) ? $stored['company'] : [],
            accountId: isset($stored['account_id']) && is_numeric($stored['account_id']) ? (int) $stored['account_id'] : null,
            gateway: self::text($stored['gateway'] ?? null),
            environment: self::text($stored['environment'] ?? null),
            currency: self::text($stored['currency'] ?? null),
            exchangeRate: self::text($stored['exchange_rate'] ?? null),
            recordedAt: self::text($stored['recorded_at'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toStored(): array
    {
        return array_filter([
            'market' => $this->market,
            'company_id' => $this->companyId,
            'company' => $this->company === [] ? null : $this->company,
            'account_id' => $this->accountId,
            'gateway' => $this->gateway,
            'environment' => $this->environment,
            'currency' => $this->currency,
            'exchange_rate' => $this->exchangeRate,
            'recorded_at' => $this->recordedAt,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** Whether all four of the values this requirement names are on the record. */
    public function isComplete(): bool
    {
        return $this->market !== null && $this->companyId !== null && $this->accountId !== null && $this->exchangeRate !== null;
    }

    private static function text(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
