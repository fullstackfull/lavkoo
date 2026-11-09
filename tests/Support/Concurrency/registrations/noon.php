<?php

// The operations of the noon concurrency tests (tests/Feature/Concurrency/NoonOrderConcurrencyTest.php), registered in every worker process.

use Tests\Support\Concurrency\NoonOperations;
use Tests\Support\Concurrency\Operations;

Operations::register('noon.ingest_same_order', [NoonOperations::class, 'ingestSameOrder']);
