<?php

// The operation of the wallet top-up concurrency test (tests/Feature/Concurrency/WalletTopUpConcurrencyTest.php), registered in
// every worker process.

use Tests\Support\Concurrency\Operations;
use Tests\Support\Concurrency\WalletTopUpOperations;

Operations::register('wallet.hand_over_top_up', [WalletTopUpOperations::class, 'handOver']);
