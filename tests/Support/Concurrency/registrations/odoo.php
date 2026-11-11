<?php

// The operations of the Odoo posting concurrency tests, registered in every worker process.

use Tests\Support\Concurrency\OdooOperations;
use Tests\Support\Concurrency\Operations;

Operations::register('odoo.post_same_fact', [OdooOperations::class, 'postSameFact']);
