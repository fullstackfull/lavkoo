<?php

/*
| Stand-ins for the global payment hooks, for the MyFatoorah tests.
|
| This file declares NO namespace on purpose. The shared payment ledger stores the NAME of a hook in
| `payment_requests.success_hook` and calls it with `call_user_func`, and the real hooks
| (`digital_payment_success`, `digital_payment_fail` in app/Utils/module-helper.php) are global
| functions. A copy declared inside a test's namespace would be `Tests\...\digital_payment_success`,
| which `function_exists('digital_payment_success')` would never find — so the settlement path would
| report "no success hook is registered" and the tests would be measuring the wrong thing.
|
| Each call is recorded so a test can assert it happened exactly once, which is the property most of
| these tests exist to prove. One of them can be made to throw, to exercise recovery from a settlement
| that was interrupted after the money was recorded but before the order was created.
*/

if (!function_exists('myfatoorah_test_success_hook')) {
    function myfatoorah_test_success_hook($paymentData): void
    {
        $GLOBALS['myfatoorah_success_calls'][] = (string) $paymentData->id;

        if (!empty($GLOBALS['myfatoorah_success_should_throw'])) {
            throw new RuntimeException('Order creation failed on purpose.');
        }

        // Lets a test act as a second delivery arriving while this hook is mid-flight — before the
        // order below exists, which is the window the hook lease has to close.
        if (isset($GLOBALS['myfatoorah_success_reentry']) && is_callable($GLOBALS['myfatoorah_success_reentry'])) {
            $reentry = $GLOBALS['myfatoorah_success_reentry'];
            unset($GLOBALS['myfatoorah_success_reentry']);
            $reentry();
        }

        // What the real hook does that this integration depends on: it stamps the order it creates with
        // the payment's transaction reference. The duplicate-settlement check reads exactly that, so
        // without it the test would not be exercising the real idempotency boundary.
        Illuminate\Support\Facades\DB::table('orders')->insert([
            'id' => random_int(1, 2147483647),
            'customer_id' => 7,
            'order_status' => 'confirmed',
            'payment_status' => 'paid',
            'payment_method' => 'fatoorah',
            'transaction_ref' => (string) $paymentData->transaction_id,
            'order_amount' => (float) $paymentData->payment_amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (!function_exists('myfatoorah_test_failure_hook')) {
    function myfatoorah_test_failure_hook($paymentData): void
    {
        $GLOBALS['myfatoorah_failure_calls'][] = (string) $paymentData->id;
    }
}
