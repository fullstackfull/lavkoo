<?php

// The operations of the two-market pool concurrency test (tests/Feature/Concurrency/CountryPoolPublishTest.php), registered in every worker process.

use Tests\Support\Concurrency\CountryPoolOperations;
use Tests\Support\Concurrency\Operations;

Operations::register('country_pool.divide', [CountryPoolOperations::class, 'divide']);
Operations::register('country_pool.buy', [CountryPoolOperations::class, 'buy']);
Operations::register('country_pool.publish', [CountryPoolOperations::class, 'publish']);
