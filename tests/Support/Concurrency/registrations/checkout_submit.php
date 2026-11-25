<?php

// The operation of the checkout-submit concurrency test (tests/Feature/Concurrency/CheckoutSubmitConcurrencyTest.php), registered in
// every worker process.

use Tests\Support\Concurrency\CheckoutSubmitOperations;
use Tests\Support\Concurrency\Operations;

Operations::register('checkout.submit_with_a_key', [CheckoutSubmitOperations::class, 'submit']);
