<?php

// The operations of the MyFatoorah concurrency tests (tests/Feature/Concurrency/MyFatoorahConcurrencyTest.php),
// registered in every worker process.

use Tests\Support\Concurrency\MyFatoorahOperations;
use Tests\Support\Concurrency\Operations;

// The success hooks the workers call. Each worker is its own process and loads only these registration
// files, so a hook declared anywhere else would not exist in it — and the settlement path would report
// "no success hook is registered" instead of applying the payment.
require_once __DIR__ . '/../../myfatoorah_test_hooks.php';

Operations::register('myfatoorah.start_payment', [MyFatoorahOperations::class, 'startPayment']);
Operations::register('myfatoorah.settle_attempt', [MyFatoorahOperations::class, 'settleAttempt']);
