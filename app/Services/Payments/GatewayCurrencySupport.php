<?php

namespace App\Services\Payments;

use App\Models\Currency;

/**
 * Can this gateway take this currency? (D2-V1)
 *
 * The three places that start a gateway payment -- the checkout, the wallet top-up and an order edit's
 * due amount -- each resolved a currency the gateway would accept and then CONVERTED the amount into it.
 * A customer in a Gulf market whose gateway cannot take that market's currency was therefore charged a
 * converted amount in some other currency, and when nothing matched at all the amount went out labelled
 * USD. Nobody was told. This class is the one place that question is now asked, and the three callers
 * all read the same answer.
 *
 * SHIPS OFF. `gateway_currency_refusal` is a business setting whose shipped value is `0`: the migration
 * registers the row so an operator can find the switch at all, and never touches a value already set.
 * Every installation -- a shipped Saudi one included -- therefore behaves exactly as it does today until
 * somebody switches it on deliberately. The switch is read before anything else, so the cost of this on
 * a live payment start is one lookup in the settings cache the request has already warmed.
 *
 * SCOPED TO THE CONVERSION. An installation that prices in one currency converts nothing and so
 * substitutes nothing; the single-currency branch of all three callers charges the system default
 * currency as it stands. There is no silent substitution there to refuse, and refusing would turn a
 * working payment into a dead one, so this answers "allowed" for that money model whatever the switch
 * says.
 *
 * ONE CODE, JUDGED AND CHARGED. chargedCurrencyCode() is the only place the currency is read off the
 * request, and the three callers hand its answer BOTH to the question here and to the conversion. The
 * first round asked the question about an upper-cased code while the conversion was still given the raw
 * one, and the legacy resolver's own lookup is case-sensitive: `sar` was judged takeable as SAR and then
 * charged as KWD 31 on a cart worth SAR 375 (measured; found by Reviewer A). One code, read once, ends
 * that.
 *
 * A GATEWAY THAT DECLARES NOTHING IS NOT JUDGED. `mercadopago_pix` has a start route and no entry in
 * the declared-currency map at all, so a question asked of it refuses every currency there is and a
 * gateway an operator configured stops taking money (measured; found by both reviewers). The platform
 * has no claim about what such a gateway takes, exactly as it has none about a request that names no
 * currency, so the answer is the same: allowed, and the catalogue hole is recorded instead.
 */
final class GatewayCurrencySupport
{
    public const SETTING = 'gateway_currency_refusal';

    public function __construct(private readonly PaymentGatewayCatalog $catalog = new PaymentGatewayCatalog())
    {
    }

    /** True once an operator has switched the refusal on. Absent, empty or '0' -- the shipped state -- is off. */
    public function enabled(): bool
    {
        // is_scalar, because a settings row holding JSON would otherwise be cast to a string and take the
        // whole payment start down with it. Anything that is not the single character '1' is off.
        $value = getWebConfig(name: self::SETTING);

        return is_scalar($value) && (string) $value === '1';
    }

    /**
     * The currency this payment is being charged in: the one the question below is asked about AND the
     * one the three callers hand to the conversion, so the code judged and the code charged are one
     * string and cannot drift apart.
     *
     * SWITCHED OFF, THIS IS THE EXPRESSION THE CALLERS ALREADY HAD -- the posted field or the session,
     * untouched -- so a shipped installation reads exactly what it read before. Switched on it is
     * normalised, and a request that names no currency at all falls back to the currency the store
     * prices in by default: the shipped customer app's own checkout sends no `current_currency_code`
     * (measured against the Flutter payload; found by Reviewer A), and leaving that unjudged let the
     * resolver pick the gateway's first active declared currency and charge BDT 11,000 for a cart worth
     * SAR 375 with the refusal switched ON.
     */
    public function chargedCurrencyCode(\ArrayAccess|array $request): mixed
    {
        $named = $request['current_currency_code'] ?? session('currency_code');

        if (!$this->answersFor($request)) {
            return $named;
        }

        $code = is_scalar($named) ? strtoupper(trim((string) $named)) : '';

        return $code === '' ? $this->storeDefaultCurrencyCode() : $code;
    }

    /**
     * The answer for a payment the customer is starting, read off the request the middleware left.
     *
     * The gateway and the currency are taken from the request HERE and nowhere else, so the question is
     * asked about exactly the currency the conversion further down is given. Reading them at each call
     * site instead would let one of the three drift and judge a currency that was never charged.
     */
    public function decideForRequest(\ArrayAccess|array $request): GatewayCurrencyDecision
    {
        $gateway = $request['payment_method'] ?? null;
        $currency = $this->chargedCurrencyCode($request);

        // A posted field can be an array, and this must never be the thing that turns that into a 500:
        // anything that is not a plain value is "no answer", which leaves the payment exactly where it
        // was before this guard existed.
        return $this->decide(
            gateway: is_scalar($gateway) ? (string) $gateway : null,
            currencyCode: is_scalar($currency) ? (string) $currency : null,
        );
    }

    /** Is there a question to answer at all -- the switch on, and a money model that converts? */
    private function answersFor(\ArrayAccess|array $request): bool
    {
        return $this->enabled() && getWebConfig(name: 'currency_model') === 'multi_currency';
    }

    /** The code of the currency the store prices in by default, or null if it has none to read. */
    private function storeDefaultCurrencyCode(): ?string
    {
        $code = Currency::where('id', getWebConfig(name: 'system_default_currency'))->value('code');

        return is_scalar($code) ? strtoupper(trim((string) $code)) : null;
    }

    /**
     * The answer the checkout, the wallet top-up and an order edit's due payment all read.
     *
     * A currency is takeable when the gateway declares it AND the store still prices in it. Both halves
     * matter: a currency row an administrator has switched off has no rate anybody may charge against, so
     * a gateway declaring it does not make the charge possible -- and that is the case today's resolver
     * hid best, because it is the one that ended at a bare 'USD'.
     *
     * When no currency is named the question cannot be asked -- there is no claim about the customer's
     * money to test -- so the answer is "allowed" and the legacy resolver decides as it always has.
     */
    public function decide(?string $gateway, ?string $currencyCode): GatewayCurrencyDecision
    {
        $gateway = trim((string) $gateway);
        $code = strtoupper(trim((string) $currencyCode));

        if (!$this->enabled() || $code === '' || $gateway === '' || getWebConfig(name: 'currency_model') !== 'multi_currency') {
            return GatewayCurrencyDecision::allow(gateway: $gateway, currencyCode: $code === '' ? null : $code);
        }

        // A gateway with no entry in the declared-currency map makes no claim this can test, so there is
        // no claim to contradict. Judging it anyway refuses every currency there is.
        if ($this->catalog->declaresNoCurrency(gateway: $gateway)) {
            return GatewayCurrencyDecision::allow(gateway: $gateway, currencyCode: $code);
        }

        if (!$this->catalog->declaresCurrency(gateway: $gateway, currencyCode: $code)) {
            return GatewayCurrencyDecision::refuse(gateway: $gateway, currencyCode: $code, reason: GatewayCurrencyDecision::NOT_DECLARED);
        }

        if (!Currency::where(['code' => $code, 'status' => 1])->exists()) {
            return GatewayCurrencyDecision::refuse(gateway: $gateway, currencyCode: $code, reason: GatewayCurrencyDecision::CURRENCY_INACTIVE);
        }

        return GatewayCurrencyDecision::allow(gateway: $gateway, currencyCode: $code);
    }
}
