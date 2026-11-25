<?php

// The operation of the paid-callback concurrency test (tests/Feature/Concurrency/PaidCallbackConcurrencyTest.php), registered in every
// worker process.

use Tests\Support\Concurrency\Operations;
use Tests\Support\Concurrency\PaidSettlementOperations;

Operations::register('paid_settlement.settle', [PaidSettlementOperations::class, 'settle']);
