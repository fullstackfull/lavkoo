<?php

// The operations of the market account concurrency test (tests/Feature/Concurrency/MarketAccountConcurrencyTest.php), registered in every worker process.

use Tests\Support\Concurrency\MarketAccountOperations;
use Tests\Support\Concurrency\Operations;

Operations::register('market_accounts.credit', [MarketAccountOperations::class, 'credit']);
