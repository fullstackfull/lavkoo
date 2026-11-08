<?php

// The operations of the MyFatoorah concurrency tests (tests/Feature/Concurrency/MyFatoorahConcurrencyTest.php),
// registered in every worker process.

use Tests\Support\Concurrency\MyFatoorahOperations;
use Tests\Support\Concurrency\Operations;

Operations::register('myfatoorah.start_payment', [MyFatoorahOperations::class, 'startPayment']);
