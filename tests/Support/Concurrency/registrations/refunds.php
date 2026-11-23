<?php

// The operations of the refund concurrency tests (tests/Feature/Concurrency/RefundConcurrencyTest.php), registered in every
// worker process.

use Tests\Support\Concurrency\Operations;
use Tests\Support\Concurrency\RefundOperations;

Operations::register('refunds.mark_refunded', [RefundOperations::class, 'markRefunded']);
Operations::register('refunds.return_amount_to_wallet', [RefundOperations::class, 'returnAmountToWallet']);
Operations::register('refunds.change_order_status', [RefundOperations::class, 'changeOrderStatus']);
