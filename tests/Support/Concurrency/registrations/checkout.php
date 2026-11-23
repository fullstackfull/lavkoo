<?php

// The operation of the storefront checkout concurrency test (tests/Feature/Concurrency/CheckoutStockConcurrencyTest.php), registered in every worker process.

use Tests\Support\Concurrency\CheckoutOperations;
use Tests\Support\Concurrency\Operations;

Operations::register('checkout.generate_order', [CheckoutOperations::class, 'generateOrder']);
