<?php

// The operations of the return-decision concurrency tests (tests/Feature/Concurrency/ReturnDecisionConcurrencyTest.php),
// registered in every worker process. A worker loads only these registration files, so an operation declared anywhere else
// would simply not exist in it.

use Tests\Support\Concurrency\Operations;
use Tests\Support\Concurrency\ReturnDecisionOperations;

Operations::register('returns.decide_and_send', [ReturnDecisionOperations::class, 'decideAndSend']);
Operations::register('returns.send_with_a_stale_id_list', [ReturnDecisionOperations::class, 'sendWithAStaleIdList']);
