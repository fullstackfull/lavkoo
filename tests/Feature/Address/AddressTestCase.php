<?php

namespace Tests\Feature\Address;

use Tests\Support\BuildsAddressSchema;
use Tests\TestCase;

/** Base for address tests: legacy address book + the real address migrations, no catalog until a test asks for one. */
abstract class AddressTestCase extends TestCase
{
    use BuildsAddressSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildAddressSchema();
    }
}
