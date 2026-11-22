<?php

// The operation of the wallet checkout concurrency test (tests/Feature/Concurrency/WalletCheckoutConcurrencyTest.php), registered in
// every worker process.

use Tests\Support\Concurrency\Operations;
use Tests\Support\Concurrency\WalletCheckoutOperations;

Operations::register('wallet.checkout', [WalletCheckoutOperations::class, 'checkout']);
