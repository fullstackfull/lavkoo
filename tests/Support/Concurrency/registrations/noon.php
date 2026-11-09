<?php

// The operations of the noon concurrency tests (tests/Feature/Concurrency/NoonOrderConcurrencyTest.php), registered in every worker process.

use Tests\Support\Concurrency\NoonOperations;
use Tests\Support\Concurrency\Operations;

Operations::register('noon.ingest_same_order', [NoonOperations::class, 'ingestSameOrder']);
// The dispatcher a MIXED run needs: ConcurrentRunner::run() takes one operation name for the whole run, so a run where each
// worker does something different calls this and it forwards to the worker's own operation.
Operations::register('noon.page', [NoonOperations::class, 'page']);
