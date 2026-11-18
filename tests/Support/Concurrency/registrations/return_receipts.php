<?php

// The operations of the return-receipt concurrency test (tests/Feature/Concurrency/ReturnReceiptConcurrencyTest.php), registered in every worker process.

use Tests\Support\Concurrency\Operations;
use Tests\Support\Concurrency\ReturnReceiptOperations;

Operations::register('returns.receive', [ReturnReceiptOperations::class, 'receive']);
