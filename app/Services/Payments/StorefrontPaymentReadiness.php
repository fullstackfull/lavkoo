<?php

namespace App\Services\Payments;

use App\Services\Storefront\StorefrontCurrency;
use Illuminate\Container\Attributes\Singleton;

use function App\Utils\payment_gateways;

/**
 * Whether a storefront that takes online payments has a gateway that could actually take one (D2-05, D1-16).
 *
 * The selling gate asks four things of a market -- its legal texts, their independent review, its currency and its
 * selling company -- and nothing about whether a customer who reaches its checkout can pay. A market whose gateway
 * account is missing, whose credentials are saved under the mode that is switched off, or whose currency no gateway
 * will take is therefore allowed to open, and the refusal happens at the customer's checkout instead of before the
 * market ever sells. That is the wrong end: an operator finds out from a customer.
 *
 * Three things have to hold of at least ONE gateway, and each is already answered somewhere:
 *
 *  - it is switched on and its credentials are in the column its own mode reads ({@see GatewayReadiness}), which is
 *    the failure a merchant cannot see from the admin form;
 *  - it can take the money the market trades in -- the gateway declares that currency ({@see PaymentGatewayCatalog}),
 *    and a three-decimal currency only goes to a gateway somebody has verified for one, which is the rule
 *    `GatewayCurrencyPolicy` applies at the start of a payment. Asked of the MARKET's currency and not of the
 *    currency in force, because this answers for a market nobody is being served -- a readiness report, a console
 *    command, the gate of a market an operator is about to open;
 *  - the provider's support for that market has been CONFIRMED ({@see GatewayCapabilityRegistry}), because a
 *    country named in documentation counts for nothing.
 *
 * **A MARKET THAT TAKES NO ONLINE PAYMENTS IS READY, and that is not an oversight.** A storefront selling
 * cash-on-delivery only needs no gateway, and refusing it would stop a market the platform can serve perfectly
 * well. The criterion is about a storefront with online payment ON.
 *
 * **IT ANSWERS TRUE UNTIL IT IS SWITCHED ON.** `gcc.storefronts.sale_gate.needs_a_payment_account` ships false, so
 * the gate's answer is the answer it gives today, to the market. It has to ship off: what "ready to sell" includes
 * beyond the legal texts is the owner's open decision on D1-16, and the provider confirmations this reads are the
 * owner's open Q-33 -- a market cannot be refused for the absence of a record nobody has been asked for yet.
 */
#[Singleton]
final class StorefrontPaymentReadiness
{
    public const NOT_ASKED = 'not_asked';

    public const NO_ONLINE_PAYMENT = 'the_storefront_takes_no_online_payments_so_it_needs_no_gateway';

    public const READY = 'ready';

    public const NO_ELIGIBLE_GATEWAY = 'no_gateway_is_switched_on_with_usable_credentials_that_can_take_this_market_s_currency_and_is_confirmed_for_it';

    public function __construct(
        private readonly GatewayReadiness $readiness,
        private readonly GatewayCapabilityRegistry $capabilities,
        private readonly PaymentGatewayCatalog $catalog,
        private readonly StorefrontCurrency $currencies,
    ) {
    }

    public function isEnforced(): bool
    {
        return (bool) config('gcc.storefronts.sale_gate.needs_a_payment_account');
    }

    /** Whether this market may sell as far as paying for an order is concerned. True while the check is off. */
    public function isReady(string $country, string $currencyCode): bool
    {
        return in_array($this->verdict(country: $country, currencyCode: $currencyCode), [self::NOT_ASKED, self::NO_ONLINE_PAYMENT, self::READY], true);
    }

    /**
     * The verdict, in words, and answered whatever the switch says so a readiness report can show an operator what
     * they would have to configure BEFORE the check is turned on.
     */
    public function verdict(string $country, string $currencyCode): string
    {
        if (!$this->isEnforced()) {
            return self::NOT_ASKED;
        }

        if ((int) getWebConfig(name: 'digital_payment') !== 1 && (string) getWebConfig(name: 'digital_payment') !== '1') {
            return self::NO_ONLINE_PAYMENT;
        }

        return $this->anEligibleGateway(country: $country, currencyCode: $currencyCode) === null ? self::NO_ELIGIBLE_GATEWAY : self::READY;
    }

    /** The first gateway that could take a payment in this market, or null when there is none. */
    public function anEligibleGateway(string $country, string $currencyCode): ?string
    {
        $credentialsReady = [];
        foreach ($this->readiness->all() as $row) {
            if ($row['active'] && $row['ready']) {
                $credentialsReady[] = $row['gateway'];
            }
        }

        if ($credentialsReady === []) {
            return null;
        }

        $offered = payment_gateways()->pluck('key_name')->filter()->values()->all();

        foreach ($this->capabilities->confirmedOf(gateways: $credentialsReady, country: $country, currency: $currencyCode) as $gateway) {
            // Offered at all, and able to take the market's own money. A gateway with ready credentials that the
            // platform does not offer is not a way for a customer to pay.
            if (in_array($gateway, $offered, true) && $this->canTake(gateway: $gateway, currencyCode: $currencyCode)) {
                return $gateway;
            }
        }

        return null;
    }

    /**
     * Whether this gateway can take this currency, judged without reference to whatever currency is in force.
     *
     * A gateway absent from the declared-currency map makes no claim this can test, so there is no claim to
     * contradict and it is read as able -- the same reading `GatewayCurrencySupport::decide` gives, because judging
     * it anyway refuses every currency there is. A currency with three decimals needs a gateway somebody has
     * verified for three, which is the rule the payment start already applies.
     */
    private function canTake(string $gateway, string $currencyCode): bool
    {
        $currency = strtoupper(trim($currencyCode));

        if (!$this->catalog->declaresNoCurrency(gateway: $gateway) && !$this->catalog->declaresCurrency(gateway: $gateway, currencyCode: $currency)) {
            return false;
        }

        return $this->currencies->decimalsOf($currency) === 2
            || in_array($gateway, (array) config('gcc.storefronts.currency.verified_three_decimal_gateways'), true);
    }
}
