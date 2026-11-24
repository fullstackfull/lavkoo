<?php

namespace App\Services\Payments;

use App\Traits\PaymentGatewayTrait;
use App\Utils\Helpers;

/**
 * The one place that knows which payment gateways exist, and under which key.
 *
 * A gateway is described in THREE separate places, and nothing used to compare them:
 *
 *   - the START ROUTE map, which turns a payment method into the URL the customer is sent to. It
 *     lived as a local variable inside Payment::generate_link(), so no test and no other caller
 *     could read it;
 *   - the DEFAULT LIST (Helpers::getDefaultPaymentGateways()), which is what payment_gateways()
 *     filters on and therefore governs both the web checkout and the `payment_methods` array the
 *     mobile app reads from /api/v1/config;
 *   - the SUPPORTED CURRENCY map (PaymentGatewayTrait::getPaymentGatewaySupportedCurrencies()).
 *
 * Three lists maintained by hand drift, and the drift is invisible until a customer picks a method
 * that cannot take their money. Reading them from here is what lets a test say so out loud.
 *
 * ALIASES ARE DECLARED RATHER THAN LEFT TO BE DISCOVERED. Two keys reach the MyFatoorah route --
 * `fatoorah`, which is the canonical one and the one in the default list, and `my_fatoorah`, which
 * older links carry. Both must keep working, because a payment link already issued names one of
 * them. But an alias is not a second gateway: it has no entry of its own in the default list or the
 * currency map, and a check that did not know the difference would either report a false fault or,
 * worse, let a genuine duplicate gateway through. So the pair is written down here, and
 * canonicalKey() is what every comparison goes through.
 */
class PaymentGatewayCatalog
{
    use PaymentGatewayTrait;

    /**
     * Keys that are another key's older spelling: alias => the gateway it is.
     *
     * An alias keeps its start route so links already in customers' inboxes resolve. It is NOT a
     * gateway in its own right, and must not be offered, configured or counted as one.
     */
    public const ALIASES = [
        'my_fatoorah' => 'fatoorah',
    ];

    /**
     * The URL a payment of each method starts at, relative to the application root.
     *
     * Moved here verbatim from Payment::generate_link(), which now reads it from this method, so the
     * link a customer is sent to is unchanged for every key.
     *
     * @return array<string, string> gateway key => start path
     */
    public static function startRoutes(): array
    {
        return [
            'ssl_commerz' => 'payment/sslcommerz/pay',
            'stripe' => 'payment/stripe/pay',
            'paymob_accept' => 'payment/paymob/pay',
            'flutterwave' => 'payment/flutterwave-v3/pay',
            'paytm' => 'payment/paytm/pay',
            'paypal' => 'payment/paypal/pay',
            'paytabs' => 'payment/paytabs/pay',
            'liqpay' => 'payment/liqpay/pay',
            'razor_pay' => 'payment/razor-pay/pay',
            'senang_pay' => 'payment/senang-pay/pay',
            'mercadopago' => 'payment/mercadopago/pay',
            'bkash' => 'payment/bkash/make-payment',
            'paystack' => 'payment/paystack/pay',
            'paymera' => 'payment/paymera/pay',
            'mtn_cash' => 'payment/mtn-cash/pay',
            // MyFatoorah. Both `payment/myfatoorah/pay` and `payment/fatoorah/pay` are registered and
            // reach the same controller; the canonical one is used for new links.
            'fatoorah' => 'payment/myfatoorah/pay',
            'my_fatoorah' => 'payment/myfatoorah/pay',
            'xendit' => 'payment/xendit/pay',
            'amazon_pay' => 'payment/amazon/pay',
            'iyzi_pay' => 'payment/iyzipay/pay',
            'hyper_pay' => 'payment/hyperpay/pay',
            'foloosi' => 'payment/foloosi/pay',
            'ccavenue' => 'payment/ccavenue/pay',
            'pvit' => 'payment/pvit/pay',
            'moncash' => 'payment/moncash/pay',
            'thawani' => 'payment/thawani/pay',
            'tap' => 'payment/tap/pay',
            'viva_wallet' => 'payment/viva/pay',
            'hubtel' => 'payment/hubtel/pay',
            'maxicash' => 'payment/maxicash/pay',
            'esewa' => 'payment/esewa/pay',
            'swish' => 'payment/swish/pay',
            'momo' => 'payment/momo/pay',
            'payfast' => 'payment/payfast/pay',
            'worldpay' => 'payment/worldpay/pay',
            'sixcash' => 'payment/sixcash/pay',
            'phonepe' => 'payment/phonepe/pay',
            'cashfree' => 'payment/cashfree/pay',
            'instamojo' => 'payment/instamojo/pay',
            'mercadopago_pix' => 'payment/mercadopago_pix/pay',
        ];
    }

    /** The gateway a key names: itself, or, for an older spelling, the gateway it is an alias of. */
    public static function canonicalKey(string $key): string
    {
        return self::ALIASES[$key] ?? $key;
    }

    /** True when this key is only an older spelling of another gateway. */
    public static function isAlias(string $key): bool
    {
        return array_key_exists($key, self::ALIASES);
    }

    /** The start routes of real gateways, with the aliases left out. */
    public static function canonicalStartRoutes(): array
    {
        return array_diff_key(self::startRoutes(), self::ALIASES);
    }

    /**
     * The gateways eligible to be offered before the admin panel's own switches are applied.
     *
     * @return array<int, string>
     */
    public static function defaultKeys(): array
    {
        return Helpers::getDefaultPaymentGateways();
    }

    /**
     * The currencies each gateway declares it can take.
     *
     * @return array<string, array<string, string>> gateway key => currency code => currency name
     */
    public function declaredCurrencies(?string $key = null): array
    {
        return $this->getPaymentGatewaySupportedCurrencies($key);
    }

    /** Does this gateway declare it can take this currency? */
    public function declaresCurrency(string $gateway, string $currencyCode): bool
    {
        $declared = $this->getPaymentGatewaySupportedCurrencies(self::canonicalKey($gateway));

        return array_key_exists(strtoupper(trim($currencyCode)), $declared);
    }

    /**
     * Is this gateway absent from the declared-currency map, or present with an empty list?
     *
     * Then it makes no claim about which currencies it takes, and a question about one of them has no
     * answer here. `mercadopago_pix` is the measured instance: a start route and no currency entry, which
     * the test beside this one pins. Asked through canonicalKey(), so an older spelling reads its real
     * gateway's declaration rather than looking like a gateway that declares nothing.
     */
    public function declaresNoCurrency(string $gateway): bool
    {
        return $this->getPaymentGatewaySupportedCurrencies(self::canonicalKey(trim($gateway))) === [];
    }
}
