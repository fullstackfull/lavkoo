<?php

// The operation of the coupon race (tests/Feature/Concurrency/CouponGrantConcurrencyTest.php), registered in every worker process.

use Tests\Support\Concurrency\CouponOperations;
use Tests\Support\Concurrency\Operations;

Operations::register('coupon.place_order', [CouponOperations::class, 'placeAnOrderTakingACoupon']);
Operations::register('coupon.record_one_redemption', [CouponOperations::class, 'recordOneRedemption']);
