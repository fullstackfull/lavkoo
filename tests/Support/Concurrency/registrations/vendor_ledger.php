<?php

// The operations of the seller-ledger concurrency test (tests/Feature/Concurrency/VendorLedgerConcurrencyTest.php), registered in every worker process.

use Tests\Support\Concurrency\Operations;
use Tests\Support\Concurrency\VendorLedgerOperations;

Operations::register('vendor_ledger.append', [VendorLedgerOperations::class, 'append']);
// The two halves of one arranged interleaving: the second delivery of an order line reads the ledger before the first delivery commits (Rendezvous).
Operations::register('vendor_ledger.append_once_the_other_has_read', [VendorLedgerOperations::class, 'appendOnceTheOtherHasRead']);
Operations::register('vendor_ledger.append_with_a_snapshot_taken_first', [VendorLedgerOperations::class, 'appendWithASnapshotTakenFirst']);
