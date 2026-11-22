<?php

// The operations of the seller withdraw concurrency tests (tests/Feature/Concurrency/SellerWithdrawConcurrencyTest.php),
// registered in every worker process.

use Tests\Support\Concurrency\Operations;
use Tests\Support\Concurrency\SellerWithdrawOperations;

Operations::register('seller_withdraw.create', [SellerWithdrawOperations::class, 'create']);
Operations::register('seller_withdraw.decide', [SellerWithdrawOperations::class, 'decide']);
Operations::register('seller_withdraw.update', [SellerWithdrawOperations::class, 'update']);
Operations::register('seller_withdraw.close', [SellerWithdrawOperations::class, 'close']);
