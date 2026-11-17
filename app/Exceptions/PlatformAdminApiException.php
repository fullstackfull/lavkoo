<?php

namespace App\Exceptions;

use App\Services\PlatformAdmin\PlatformAdminResponses;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A request of the platform admin API that cannot be served as asked. It renders itself as the API's error body, and it is not reported:
 * a refused request is the API working, not a fault to page somebody about.
 */
class PlatformAdminApiException extends RuntimeException implements ShouldntReport
{
    /** @param array<string, mixed> $extra */
    private function __construct(private readonly string $errorCode, string $message, private readonly int $status, private readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public static function sessionNotFound(): self
    {
        return new self(errorCode: 'session_not_found', message: 'No such session.', status: 404);
    }

    public function render(): JsonResponse
    {
        return PlatformAdminResponses::error(code: $this->errorCode, message: $this->getMessage(), status: $this->status, extra: $this->extra);
    }
}
