<?php

// The operations of the referral reward concurrency tests (tests/Feature/Concurrency/ReferralRewardConcurrencyTest.php),
// registered in every worker process. A worker loads only these registration files, so an operation declared anywhere else
// would simply not exist in it.

use Tests\Support\Concurrency\Operations;
use Tests\Support\Concurrency\ReferralRewardOperations;

Operations::register('referral.credit_once', [ReferralRewardOperations::class, 'creditOnce']);
