<?php

namespace Tests;

use App\Services\Address\AddressSchema;
use App\Services\Fulfillment\FulfillmentSchema;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /** Every test builds its own database, so what the schema classes remembered about the previous one must not carry over. */
    protected function setUp(): void
    {
        parent::setUp();

        FulfillmentSchema::flush();
        AddressSchema::flush();
    }
}
