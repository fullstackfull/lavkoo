<?php

namespace App\Services\Payments;

/**
 * The answer to "can this gateway take this currency": one object, read the same way by every caller.
 *
 * `refused()` is the only question a caller asks. A decision that was never really asked -- the feature
 * is switched off, or this installation prices in one currency and converts nothing -- answers false, so
 * no caller needs a condition of its own and the switch lives in one place.
 */
final class GatewayCurrencyDecision
{
    /** The gateway's declared currency list does not contain the one the customer is being charged in. */
    public const NOT_DECLARED = 'not_declared';

    /** The gateway declares the currency, but the store has switched that currency off, so nothing can be priced in it. */
    public const CURRENCY_INACTIVE = 'currency_inactive';

    private function __construct(
        private readonly bool $isRefused,
        public readonly string $gateway,
        public readonly ?string $currencyCode,
        public readonly ?string $reason,
    ) {
    }

    public static function allow(string $gateway, ?string $currencyCode): self
    {
        return new self(isRefused: false, gateway: $gateway, currencyCode: $currencyCode, reason: null);
    }

    public static function refuse(string $gateway, ?string $currencyCode, string $reason): self
    {
        return new self(isRefused: true, gateway: $gateway, currencyCode: $currencyCode, reason: $reason);
    }

    public function refused(): bool
    {
        return $this->isRefused;
    }

    /**
     * What the customer is told, in their own language, naming the currency they were being charged in so
     * the message is about their money and not about a configuration they cannot see.
     */
    public function message(): string
    {
        $message = translate('this_payment_method_cannot_take_payments_in_your_currency');

        return $this->currencyCode === null || $this->currencyCode === '' ? $message : $message . ' (' . $this->currencyCode . ')';
    }
}
