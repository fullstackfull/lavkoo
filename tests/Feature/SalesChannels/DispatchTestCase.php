<?php

namespace Tests\Feature\SalesChannels;

use Tests\Feature\Fulfillment\FulfillmentTestCase;
use Tests\Support\BuildsDispatchWorld;

/** The dispatch tests' base: the platform's own fulfilment test base (single currency, a scripted provider) plus the dispatch world. */
abstract class DispatchTestCase extends FulfillmentTestCase
{
    use BuildsDispatchWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDispatchWorld();
    }
}
