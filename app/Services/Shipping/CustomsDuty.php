<?php

namespace App\Services\Shipping;

/**
 * Customs duty on a cross-border order (H1-09): whether the route can be quoted at all, and what it comes to.
 *
 * A duty rate is not a thing a shop may invent. It belongs to the destination's tariff, it depends on what the goods are and where they
 * were made, and it is the owner's to give per route with a competent source behind it (question Q-11). So nothing here guesses one: this
 * holds whatever rules are written down and answers {@see CustomsDutyQuote::NOT_QUOTED} for every route that has none, which is every
 * route as it ships.
 *
 * Three modes, OFF as it ships:
 *
 *  - `off`: there is no duty on this platform, which is the truth for a shop selling inside one country. Every order quotes `none` and
 *    nothing is shown, stored or refused -- byte for byte the platform as it runs today;
 *  - `declare`: a cross-border order carries its quote, and an unquotable route says so rather than claiming zero. Nothing is refused;
 *  - `strict`: a cross-border order on a route nobody has written a rule for is REFUSED before payment. That is the requirement's own
 *    sentence -- a route whose duty cannot be determined refuses the order and never charges zero -- and it is why `canQuote` is a
 *    question about the ROUTE, answerable before a basket has a total.
 *
 * The amount needs the order's value and so is computed where that is known ({@see OrderCustomsQuote}); whether a route is quotable at all
 * does not, which is what the checkout engine asks.
 */
final class CustomsDuty
{
    public const MODE_OFF = 'off';
    public const MODE_DECLARE = 'declare';
    public const MODE_STRICT = 'strict';

    /** `off`, `declare` or `strict`. Anything unrecognised is read as `off`, so a typo never starts refusing orders. */
    public static function mode(): string
    {
        $mode = (string) config('gcc.shipping.duty.mode', self::MODE_OFF);

        return in_array($mode, [self::MODE_DECLARE, self::MODE_STRICT], true) ? $mode : self::MODE_OFF;
    }

    public static function isOn(): bool
    {
        return self::mode() !== self::MODE_OFF;
    }

    /** Whether the duty on this route can be determined at all. A domestic route always can: nothing crosses a border, so nothing is due. */
    public static function canQuote(?string $source, ?string $destination): bool
    {
        $from = self::country($source);
        $to = self::country($destination);

        if ($from === null || $to === null) {
            return false;
        }

        return $from === $to || self::ruleFor($from, $to) !== null;
    }

    /**
     * The quote for an order of this value on this route.
     *
     * The de minimis is the destination's own threshold, under which a parcel enters free. It is read as a value in the order's own
     * currency, because that is what the order's total is in and converting it here would invent a rate.
     */
    public static function quoteFor(?string $source, ?string $destination, float $value): CustomsDutyQuote
    {
        $from = self::country($source);
        $to = self::country($destination);

        if (!self::isOn() || $from === null || $to === null || $from === $to) {
            return CustomsDutyQuote::none(route: self::route($from, $to));
        }

        $route = self::route($from, $to);
        $rule = self::ruleFor($from, $to);

        if ($rule === null) {
            return CustomsDutyQuote::notQuoted(route: $route);
        }

        $payer = (string) ($rule['payer'] ?? '');

        if (!in_array($payer, [CustomsDutyQuote::BUYER_ON_DELIVERY, CustomsDutyQuote::SELLER], true)) {
            // An amount nobody is said to owe is not a quote: who pays is a commercial term, not a rounding.
            return CustomsDutyQuote::notQuoted(route: $route);
        }

        $deMinimis = (float) ($rule['de_minimis'] ?? 0);

        if ($deMinimis > 0 && $value <= $deMinimis) {
            return CustomsDutyQuote::none(route: $route);
        }

        $percentage = (float) ($rule['percentage'] ?? -1);

        if ($percentage < 0) {
            return CustomsDutyQuote::notQuoted(route: $route);
        }

        return $percentage === 0.0
            ? CustomsDutyQuote::none(route: $route)
            : CustomsDutyQuote::payable(amount: round($value * $percentage / 100, 3), payer: $payer, basis: $value, route: $route);
    }

    /**
     * The owner's rule for one route, or null where there is none.
     *
     * @return array{percentage?: float|int|string, payer?: string, de_minimis?: float|int|string}|null
     */
    private static function ruleFor(string $source, string $destination): ?array
    {
        $rules = (array) config('gcc.shipping.duty.routes', []);

        foreach ([self::route($source, $destination), self::route('*', $destination), self::route($source, '*')] as $key) {
            $rule = $rules[$key] ?? null;
            if (is_array($rule) && $rule !== []) {
                return $rule;
            }
        }

        return null;
    }

    private static function route(?string $source, ?string $destination): ?string
    {
        return $source === null || $destination === null ? null : $source . ':' . $destination;
    }

    private static function country(?string $code): ?string
    {
        // Through the one normaliser, because the values do not agree: a product's market is a code and the address book holds the English
        // NAME. Comparing the two directly made a Riyadh-to-Riyadh order read as crossing a border.
        return DestinationCountryCode::of($code);
    }
}
