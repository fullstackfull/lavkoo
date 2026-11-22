<?php

namespace App\Services\Payments;

use App\Models\PaymentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * What a gateway's confirmation may settle.
 *
 * A confirmation reaches the shop as a request, which means it can reach the shop again: the address a customer is returned to
 * carries it, and a notification is delivered more than once. Two questions are asked of every one of them here.
 *
 * IS IT THIS PAYMENT'S? The gateway signs the amount and the currency it took. A confirmation whose amount or currency is not
 * the payment's belongs to another payment, whatever the request says, and settles nothing. This needs no table and holds from
 * the moment the code is deployed.
 *
 * HAS IT BEEN USED? One transaction of a gateway settles one payment. The reference the gateway gives its own transaction is
 * written in a register under a unique key over the gateway and that reference, inside the same transaction as the settlement.
 * Until the register's migration has run there is no such table and this question is not asked, which is said out loud rather
 * than assumed: a shop that has the code and not the table is where it was before, minus the amount check.
 */
class GatewayConfirmations
{
    public const TABLE = 'gateway_confirmations';

    /** The smallest unit of a currency that has none: a gateway states its amount in minor units. */
    private const MINOR_UNITS_IN_ONE = 100;

    public function __construct(private readonly PaymentRequest $payments)
    {
    }

    /**
     * The payment this confirmation settles, or null when it settles none.
     *
     * @param string $gateway the key the shop stores a gateway's settings and payments under
     * @param string $reference what the gateway calls this transaction of its own
     * @param numeric-string|int|float $minorUnits the amount the gateway says it took, in the currency's smallest unit
     */
    public function thePaymentItSettles(string $gateway, string $paymentId, string $reference, mixed $minorUnits, string $currency): ?PaymentRequest
    {
        $payment = $this->payments->newQuery()->where('id', $paymentId)->where('is_paid', 0)->first();

        if ($payment === null || $payment->payment_method !== $gateway) {
            return null;
        }

        if (!$this->isTheAmountOf(payment: $payment, minorUnits: $minorUnits, currency: $currency)) {
            Log::critical('gateway.confirmation_for_another_amount', ['gateway' => $gateway, 'payment' => $paymentId, 'reference' => $reference]);

            return null;
        }

        return $payment;
    }

    /**
     * Writes down that this transaction of the gateway settled this payment. False when the gateway's reference is already in
     * the register: the confirmation has settled a payment before and settles no second one.
     *
     * Call inside the transaction that settles, so that a settlement which does not finish takes its entry with it.
     */
    public function hold(string $gateway, string $reference, string $paymentId): bool
    {
        if (!Schema::hasTable(self::TABLE)) {
            return true;
        }

        $held = DB::table(self::TABLE)->insertOrIgnore(['gateway' => $gateway, 'reference' => $reference, 'payment_request_id' => $paymentId, 'created_at' => now()]) === 1;

        if (!$held) {
            Log::critical('gateway.confirmation_presented_again', ['gateway' => $gateway, 'reference' => $reference, 'payment' => $paymentId]);
        }

        return $held;
    }

    /** @param numeric-string|int|float $minorUnits */
    private function isTheAmountOf(PaymentRequest $payment, mixed $minorUnits, string $currency): bool
    {
        if (!is_numeric($minorUnits) || strcasecmp($currency, (string) $payment->currency_code) !== 0) {
            return false;
        }

        return (int) round((float) $minorUnits) === (int) round((float) $payment->payment_amount * self::MINOR_UNITS_IN_ONE);
    }
}
