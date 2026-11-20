<?php

// The operations of the seller-ledger concurrency test (tests/Feature/Concurrency/VendorLedgerConcurrencyTest.php), registered in every worker process.

use Tests\Support\Concurrency\Operations;
use Tests\Support\Concurrency\VendorLedgerOperations;

Operations::register('vendor_ledger.append', [VendorLedgerOperations::class, 'append']);
