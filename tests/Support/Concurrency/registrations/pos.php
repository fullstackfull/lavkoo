<?php

// The operations of the till race (tests/Feature/Concurrency/PosStockConcurrencyTest.php), registered in every worker process.

use Tests\Support\Concurrency\Operations;
use Tests\Support\Concurrency\PosOperations;

Operations::register('pos.place_sale', [PosOperations::class, 'placeSale']);
