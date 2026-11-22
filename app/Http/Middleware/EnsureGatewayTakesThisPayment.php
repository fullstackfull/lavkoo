<?php

namespace App\Http\Middleware;

use App\Models\PaymentRequest;
use App\Services\Payments\PaymentAccountBinding;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

use function App\Utils\payment_gateways;

/**
 * Stands in front of the addresses of the payment gateways under /payment.
 *
 * Every gateway the code base knows has its addresses registered whether the shop uses that gateway or not, and several of
 * them confirm whatever payment the request names on the word of the request alone. So an address of a gateway is answered
 * only while the shop has that gateway switched on, which is the rule a customer is offered a gateway by, and a payment that
 * the request names is touched only by the gateway it was started with.
 *
 * A payment also belongs to one company's account at that gateway (D2-07). A payment stamped with one account is reachable at
 * this gateway's addresses only while that account is still the gateway's own: a request for a payment of another company's
 * account, or for a payment whose account has been deleted, is answered the same 404 as a payment of another gateway. The
 * stamp is read, never re-resolved, so a company's payment stays its company's after the registry has been changed.
 */
class EnsureGatewayTakesThisPayment
{
    /** The addresses of a gateway under /payment, and the key its settings and its payments are stored under (App\Traits\Payment). */
    public const GATEWAY_OF_ADDRESS = [
        'sslcommerz' => 'ssl_commerz',
        'stripe' => 'stripe',
        'razor-pay' => 'razor_pay',
        'paypal' => 'paypal',
        'senang-pay' => 'senang_pay',
        'paytm' => 'paytm',
        'flutterwave-v3' => 'flutterwave',
        'paystack' => 'paystack',
        'bkash' => 'bkash',
        'liqpay' => 'liqpay',
        'mercadopago' => 'mercadopago',
        'paymob' => 'paymob_accept',
        'paytabs' => 'paytabs',
        'mtn-cash' => 'mtn_cash',
        'paymera' => 'paymera',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        $gateway = self::GATEWAY_OF_ADDRESS[$request->segment(2)] ?? null;
        if ($gateway !== null) {
            abort_unless($this->isSwitchedOn(gateway: $gateway) && $this->mayTouchThePaymentNamed(request: $request, gateway: $gateway), 404);
        }

        return $next($request);
    }

    private function isSwitchedOn(string $gateway): bool
    {
        return payment_gateways()->contains('key_name', $gateway);
    }

    /** A request that names no payment is left to its gateway; one that names a payment must name it by its id and belong to this gateway. */
    private function mayTouchThePaymentNamed(Request $request, string $gateway): bool
    {
        if (!$request->has('payment_id')) {
            return true;
        }

        $paymentId = $request->input('payment_id');
        if (!is_string($paymentId) || !Str::isUuid($paymentId)) {
            return false;
        }

        $payment = PaymentRequest::query()->find($paymentId);

        if ($payment === null) {
            return true;
        }

        return $payment->payment_method === $gateway && $this->accountIsThisGateways(payment: $payment, gateway: $gateway);
    }

    /**
     * Whether the account this payment was stamped with is an account of THIS gateway.
     *
     * A payment with no stamp passes, which is every payment while per-company accounts are off. A payment whose stamped account row is gone does not:
     * the account it was taken through can no longer be named, and settling it through whatever the registry holds now would charge it to a company it
     * was not taken for.
     */
    private function accountIsThisGateways(PaymentRequest $payment, string $gateway): bool
    {
        $binding = app(PaymentAccountBinding::class);
        $record = $binding->of(payment: $payment);

        if ($record === null || $record->accountId === null) {
            return true;
        }

        $account = $binding->accountOf(payment: $payment);

        return $account !== null && $account->gateway === $gateway;
    }
}
