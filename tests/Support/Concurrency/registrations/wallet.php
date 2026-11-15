<?php

// The operations of the wallet checkout concurrency tests (tests/Feature/Concurrency/WalletBalanceCurrencyConcurrencyTest.php),
// registered in every worker process.

use Tests\Support\Concurrency\Operations;
use Tests\Support\Concurrency\WalletCheckoutOperations;

Operations::register('wallet.place_api_wallet_order', [WalletCheckoutOperations::class, 'placeApiWalletOrder']);
