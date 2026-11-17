<?php

namespace Tests\Support;

use Monolog\Handler\TestHandler;

/** The application log, kept in memory for a test to read. */
trait CapturesLogRecords
{
    protected function captureLog(): TestHandler
    {
        config(['logging.default' => 'capture', 'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        app('log')->forgetChannel('capture');

        return app('log')->channel('capture')->getLogger()->getHandlers()[0];
    }
}
