<?php

// The operations of the shared-stock concurrency tests (tests/Feature/Concurrency/StockConcurrencyTest.php), registered in every worker process.

use Tests\Support\Concurrency\Operations;
use Tests\Support\Concurrency\StockOperations;

Operations::register('stock.mixed', [StockOperations::class, 'mixed']);
Operations::register('stock.allocate', [StockOperations::class, 'allocate']);
Operations::register('stock.allocate_repeatedly', [StockOperations::class, 'allocateRepeatedly']);
Operations::register('stock.allocate_and_list_locks', [StockOperations::class, 'allocateAndListLocks']);
Operations::register('stock.hold_pool_lock', [StockOperations::class, 'holdPoolLock']);
Operations::register('stock.hold_product_lock', [StockOperations::class, 'holdProductLock']);
Operations::register('stock.write_storefront', [StockOperations::class, 'writeStorefront']);
Operations::register('stock.reserve_repeatedly', [StockOperations::class, 'reserveRepeatedly']);
Operations::register('stock.publish', [StockOperations::class, 'publish']);
Operations::register('stock.ingest', [StockOperations::class, 'ingest']);
Operations::register('stock.checkout', [StockOperations::class, 'checkout']);
Operations::register('stock.hold_lease', [StockOperations::class, 'holdLease']);
Operations::register('stock.acquire', [StockOperations::class, 'acquire']);
Operations::register('stock.claim_events', [StockOperations::class, 'claimStockEvents']);
Operations::register('stock.drain_events', [StockOperations::class, 'drainStockEvents']);
