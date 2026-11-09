<?php

// The operations of the package-split concurrency tests (tests/Feature/Concurrency/PackageSplitConcurrencyTest.php),
// registered in every worker process. A worker loads only these registration files, so an operation declared anywhere else
// would simply not exist in it.

use Tests\Support\Concurrency\Operations;
use Tests\Support\Concurrency\PackageSplitOperations;

Operations::register('splits.request_and_send', [PackageSplitOperations::class, 'requestAndSend']);
Operations::register('splits.send_with_a_stale_id_list', [PackageSplitOperations::class, 'sendWithAStaleIdList']);
