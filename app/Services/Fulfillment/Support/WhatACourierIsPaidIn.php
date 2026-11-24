<?php

namespace App\Services\Fulfillment\Support;

use App\Models\Order;

/**
 * The currency a shipment is stamped with, and the one setting that settles which currency a courier is actually paid in
 * (C-15, finding E11-F02).
 *
 * Today every shipment carries the currency the configuration names, whatever market the order was sold in: a Kuwaiti order
 * routed to a provider would be sent a riyal figure and a riyal label, and the dinars the courier collected would be
 * reconciled against riyals. Nothing has paid for that yet because no non-Saudi provider account has ever been enabled -- it
 * is a precondition of the first non-Saudi shipment, not a live defect -- and which of the three answers is right is the
 * owner's to give, because each one changes what a courier invoices and who absorbs the rate difference. So all three are
 * reachable here and the default is the platform exactly as it runs today.
 *
 * This class answers only WHICH currency. It does not convert: {@see ProviderCurrency} does that, and it converts into the
 * configured currency alone. While those two can disagree, a shipment whose stamp is not the currency its figures were
 * converted into is HELD rather than sent -- {@see \App\Services\Fulfillment\FulfillmentMoney::assess()} -- because a right
 * label over a wrong figure is worse than no shipment: it is the figure the courier collects in cash. The hold is the
 * existing `hold_currency_unconvertible`, which an operator already knows and which releases itself once the money can be
 * assessed. Answering the question means making the converter order-aware; until then, choosing an option stops non-Saudi
 * shipments instead of mispricing them.
 */
final class WhatACourierIsPaidIn
{
    /** The default: one currency for every provider, as the configuration names it and as the platform has always done. */
    public const AS_CONFIGURED_TODAY = 'as_configured_today';

    /** Option B: the currency the customer was charged in, from the order's own storefront snapshot. */
    public const THE_ORDERS_MARKET = 'the_orders_market';

    /** Option C: the currency the provider account itself settles in (`fulfillment.providers.<code>.settlement_currency`). */
    public const THE_PROVIDER_ACCOUNT = 'the_provider_account';

    /** Which rule is in force. An unrecognised value is read as the default, so a typo in the environment cannot move money. */
    public static function rule(): string
    {
        $named = (string) config('fulfillment.settlement_currency');

        return in_array($named, [self::THE_ORDERS_MARKET, self::THE_PROVIDER_ACCOUNT], true) ? $named : self::AS_CONFIGURED_TODAY;
    }

    /**
     * The ISO code a shipment of this order through this provider is stamped with.
     *
     * Every answer falls back to the configured currency rather than to nothing: an order sold before the storefront snapshot
     * existed names no currency, and a provider account that has not been told which currency it settles in has not been
     * configured for one. Guessing in either case would stamp a label nobody chose.
     */
    public static function forOrder(?Order $order, ?string $providerCode = null): string
    {
        $configured = CurrencyCode::provider();

        return match (self::rule()) {
            self::THE_ORDERS_MARKET => CurrencyCode::canonical($order?->currency_code) ?? $configured,
            self::THE_PROVIDER_ACCOUNT => CurrencyCode::canonical(self::accountCurrency($providerCode)) ?? $configured,
            default => $configured,
        };
    }

    /** What the provider account names as its settlement currency, or null when it names none. */
    private static function accountCurrency(?string $providerCode): ?string
    {
        if ($providerCode === null || $providerCode === '') {
            return null;
        }

        $named = config("fulfillment.providers.{$providerCode}.settlement_currency");

        return is_string($named) || is_int($named) ? (string) $named : null;
    }
}
