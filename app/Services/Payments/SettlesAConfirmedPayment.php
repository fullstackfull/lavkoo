<?php

namespace App\Services\Payments;

use App\Models\PaymentRequest;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;

/**
 * Marks the payment a gateway's confirmation settles as paid, once (D2-12).
 *
 * This is the arithmetic-free half of a settlement and the half that must not have two copies. It was a
 * private method on `PaymobController`, which was fine while a browser return was the only way a Paymob
 * payment could be settled. A recovery sweep is a second way, and a second copy of these four lines is
 * how the two would come to disagree about what "settled" means -- which of them writes the gateway's
 * reference, which of them holds the confirmation register, whether either re-reads the row.
 *
 * Three claims, in this order, and each is somebody else's:
 *
 *   - the confirmation is THIS payment's, by its amount and currency ({@see GatewayConfirmations});
 *   - the gateway's own transaction has not settled a payment before, held in a register under a unique
 *     key inside this transaction, so a confirmation presented twice settles once;
 *   - the paid flag is set only while it is still unset, so two confirmations arriving at one instant
 *     settle once even before the register's migration has run.
 *
 * It does NOT run the success hook. What a settled payment then causes -- an order, a wallet credit --
 * belongs to the caller, because a browser return answers the customer with it and a sweep does not.
 */
#[Singleton]
final class SettlesAConfirmedPayment
{
    public function __construct(private readonly GatewayConfirmations $confirmations)
    {
    }

    /**
     * @param string $gateway the key this shop stores the gateway's settings and payments under
     * @param string $reference what the GATEWAY calls this transaction of its own, never our reference
     * @param numeric-string|int|float|null $minorUnits the amount the gateway says it took
     *
     * @return PaymentRequest|null the settled payment, or null when this confirmation settles none
     */
    public function settle(string $gateway, string $paymentId, string $reference, mixed $minorUnits, string $currency): ?PaymentRequest
    {
        $payment = $this->confirmations->thePaymentItSettles(
            gateway: $gateway,
            paymentId: $paymentId,
            reference: $reference,
            minorUnits: $minorUnits,
            currency: $currency,
        );

        if ($payment === null || trim($reference) === '') {
            return null;
        }

        return DB::transaction(function () use ($gateway, $payment, $reference): ?PaymentRequest {
            if (!$this->confirmations->hold(gateway: $gateway, reference: $reference, paymentId: $payment->id)) {
                return null;
            }

            $settled = PaymentRequest::query()
                ->where(['id' => $payment->id, 'is_paid' => 0])
                ->update(['payment_method' => $gateway, 'is_paid' => 1, 'transaction_id' => $reference]);

            return $settled === 1 ? $payment->refresh() : null;
        });
    }
}
