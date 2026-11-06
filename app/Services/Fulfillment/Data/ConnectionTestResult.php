<?php

namespace App\Services\Fulfillment\Data;

final readonly class ConnectionTestResult
{
    public function __construct(
        public bool $ok,
        public string $message,
        public ?int $httpStatus = null,
    ) {
    }
}
