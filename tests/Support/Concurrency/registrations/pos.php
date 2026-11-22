<?php

// The operations of the seller app's point-of-sale concurrency test (tests/Feature/Concurrency/PosSaleIdempotencyConcurrencyTest.php),
// registered in every worker process.

use Tests\Support\Concurrency\Operations;
use Tests\Support\Concurrency\PosSaleOperations;

Operations::register('pos.seller_sale', [PosSaleOperations::class, 'placeSellerSale']);
