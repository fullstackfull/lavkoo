<?php

namespace App\Services\Payments;

use App\Models\Currency;

/**
 * The currency an order's own money is written down in.
 *
 * This answer was written out four times, from four different sources, for ONE column --
 * `orders.bring_change_amount_currency`, which an operator reads off the order screen as the currency
 * the courier is told to collect:
 *
 *   - RestAPI\v1\OrderController and RestAPI\v1\OrderEditController: the currency the customer is
 *     shopping in, falling back to the platform's default. Correct, and the pattern followed here.
 *   - Web\WebController: `session('currency_code')` alone, which is null before a customer has picked
 *     one, so the column can be written empty.
 *   - Customer\PaymentController: `getPaymentGatewayCurrencyCode('cash_on_delivery', ...)`, which asks
 *     a GATEWAY CAPABILITY MAP what currency a cash payment is in. `cash_on_delivery` has no entry in
 *     that map, so the lookup matched nothing and fell through to the map's last line, `return 'USD'`.
 *     In multi-currency mode EVERY cash-on-delivery order was therefore labelled USD, whatever the
 *     customer was shopping in -- and that label is printed beside the amount on the admin order
 *     screen. In single-currency mode the same method took the other branch and was correct, which is
 *     why it was never seen.
 *
 * Cash is not a gateway, and what currency a customer pays in is not a question about what a gateway
 * can accept. One answer, in one place, so the four cannot disagree again.
 */
final class CheckoutCurrency
{
    /**
     * The currency to record, given what the customer is shopping in.
     *
     * @param string|null $shoppingIn the currency the customer picked -- `current_currency_code` on an
     *                                API request, `session('currency_code')` on the website -- or null
     * @return string an active currency code, never empty and never a guess
     */
    public static function forOrder(?string $shoppingIn = null): string
    {
        $shoppingIn = trim((string) $shoppingIn);

        if (getWebConfig(name: 'currency_model') === 'multi_currency' && $shoppingIn !== '') {
            return strtoupper($shoppingIn);
        }

        return self::platformDefault();
    }

    /** The platform's own default currency code. */
    public static function platformDefault(): string
    {
        return (string) (Currency::find(getWebConfig(name: 'system_default_currency'))?->code ?? '');
    }
}
