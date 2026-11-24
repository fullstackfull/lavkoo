<?php

// The operations of the delivery man withdraw concurrency test
// (tests/Feature/Concurrency/DeliveryManWithdrawConcurrencyTest.php), registered in every worker process.

use Tests\Support\Concurrency\DeliveryManWithdrawOperations;
use Tests\Support\Concurrency\Operations;

Operations::register('deliveryman_withdraw.decide_as_admin', [DeliveryManWithdrawOperations::class, 'decideAsAdmin']);
Operations::register('deliveryman_withdraw.decide_as_vendor', [DeliveryManWithdrawOperations::class, 'decideAsVendor']);
