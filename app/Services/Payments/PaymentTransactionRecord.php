<?php

namespace App\Services\Payments;

/**
 * What a payment belongs to, as it was when the payment started (D2-10): the market, the selling company, the gateway account it is taken through, the
 * exchange rate in force for its currency, and the address its confirmation is expected at (GCC-21).
 *
 * Frozen, and read as stored. Every one of these four can move after a payment starts — a storefront is reconfigured, a company is edited, an account
 * is disabled, a rate is retyped — and a figure re-derived at read time is how a settled payment comes to disagree with the money it took. The same
 * discipline an order's company and currency snapshot already uses (App\Services\Storefront\OrderLegalEntitySnapshot).
 *
 * The rate is kept as the plain text that was read, not as a float: `currencies.exchange_rate` is a text column, and turning "3.75" into a float and
 * back is a way to store 3.7499999999999996 in a financial record.
 *
 * The confirmation address is kept as the ADDRESS and not as the account id, for the same reason and one more. An account row can be DELETED, and
 * App\Services\Payments\PaymentAccountBinding::accountOf() then answers null; an address re-derived from that null would read as the platform
 * address, so a payment that began under a company address would become platform-confirmable by the deletion of a row. The text that was recorded
 * cannot be moved by anything that happens to the account afterwards. Null means the platform address, which is every payment placed today.
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
        public ?string $confirmationAddress = null,
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
            confirmationAddress: self::text($stored['confirmation_address'] ?? null),
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
            'confirmation_address' => $this->confirmationAddress,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Whether all four of the values D2-10 names are on the record.
     *
     * The confirmation address is deliberately not one of them: it is GCC-21's field, and a payment whose confirmations are expected at the platform
     * address records none, which is a complete record of a payment and not an incomplete one.
     */
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
