<?php

namespace App\Http\Middleware;

use App\Models\PaymentRequest;
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

        return $payment === null || $payment->payment_method === $gateway;
    }
}
