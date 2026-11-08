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
| The real success hook creates an order, decrements stock, books a shipment with a courier and (on the
| wallet path) credits a balance. Each of those is recorded separately here, because "the hook ran once"
| and "each of its side effects happened once" are different claims and the second is the one that
| matters: a second run that stops early still leaves a duplicate shipment behind.
*/

if (!function_exists('myfatoorah_test_record_effect')) {
    /** Record one side effect of the success hook, so a test can count it. */
    function myfatoorah_test_record_effect(string $effect, string $reference): void
    {
        $GLOBALS['myfatoorah_effects'][$effect][] = $reference;
    }
}

if (!function_exists('myfatoorah_test_throw_if_requested')) {
    /**
     * Throw straight after one named side effect, if a test asked for it.
     *
     * The real hook can die at any point in its sequence, and where it died decides what a retry would
     * duplicate — stock taken but no order, an order with no shipment, and so on. A test sets
     * `$GLOBALS['myfatoorah_success_throw_after']` to the effect it wants to survive.
     */
    function myfatoorah_test_throw_if_requested(string $effect): void
    {
        if (($GLOBALS['myfatoorah_success_throw_after'] ?? null) === $effect) {
            throw new RuntimeException("Died straight after {$effect}.");
        }
    }
}

if (!function_exists('myfatoorah_test_effect_count')) {
    function myfatoorah_test_effect_count(string $effect): int
    {
        return count($GLOBALS['myfatoorah_effects'][$effect] ?? []);
    }
}

if (!function_exists('myfatoorah_test_success_hook')) {
    function myfatoorah_test_success_hook($paymentData): void
    {
        $GLOBALS['myfatoorah_success_calls'][] = (string) $paymentData->id;
        $reference = (string) $paymentData->transaction_id;

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

        // The wallet path creates no order; it credits a balance, with nothing to look the credit up by
        // afterwards. That is why recovery refuses to retry it.
        if ((string) ($paymentData->attribute ?? 'order') !== 'order') {
            myfatoorah_test_record_effect('wallet_credit', $reference);
            myfatoorah_test_throw_if_requested('wallet_credit');

            return;
        }

        // Stock first, then the order, then the courier — the real hook's order, so a test can stop it
        // part way through and see which effects a retry would duplicate.
        myfatoorah_test_record_effect('stock_reservation', $reference);
        myfatoorah_test_throw_if_requested('stock_reservation');

        // What the real hook does that this integration depends on: it stamps the order it creates with
        // the payment's transaction reference. The duplicate-settlement check reads exactly that, so
        // without it the test would not be exercising the real idempotency boundary.
        Illuminate\Support\Facades\DB::table('orders')->insert([
            'id' => random_int(1, 2147483647),
            'customer_id' => 7,
            'order_status' => 'confirmed',
            'payment_status' => 'paid',
            'payment_method' => 'fatoorah',
            'transaction_ref' => $reference,
            'order_amount' => (float) $paymentData->payment_amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        myfatoorah_test_record_effect('order', $reference);
        myfatoorah_test_throw_if_requested('order');

        myfatoorah_test_record_effect('shipment', $reference);
        myfatoorah_test_throw_if_requested('shipment');
    }
}

if (!function_exists('myfatoorah_test_failure_hook')) {
    function myfatoorah_test_failure_hook($paymentData): void
    {
        $GLOBALS['myfatoorah_failure_calls'][] = (string) $paymentData->id;
    }
}

if (!function_exists('myfatoorah_concurrency_order_hook')) {
    /**
     * The success hook the concurrency workers use: it creates an order stamped with the payment's
     * transaction reference, which is the idempotency boundary the settlement path reads. Separate
     * processes cannot share PHP globals, so counting happens in the database here.
     */
    function myfatoorah_concurrency_order_hook($paymentData): void
    {
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

if (!function_exists('myfatoorah_concurrency_noop_hook')) {
    /** The concurrency workers only need the hook to exist and be harmless. */
    function myfatoorah_concurrency_noop_hook($paymentData): void
    {
    }
}
