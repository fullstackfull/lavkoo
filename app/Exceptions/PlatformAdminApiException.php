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

    /** @param list<string> $valid the values the market parameter takes */
    public static function invalidMarket(array $valid): self
    {
        return new self(errorCode: 'invalid_market', message: 'The market must be all or the code of a market.', status: 422, extra: ['valid' => $valid]);
    }

    public static function capabilityNotAllowed(string $capability): self
    {
        return new self(errorCode: 'capability_not_allowed', message: 'This account may not use that part of the API.', status: 403, extra: ['capability' => $capability]);
    }

    public static function marketNotAllowed(): self
    {
        return new self(errorCode: 'market_not_allowed', message: 'This account may not see that market.', status: 403);
    }

    /** @param list<string> $valid */
    public static function invalidSeverity(array $valid): self
    {
        return new self(errorCode: 'invalid_severity', message: 'The severity must be info, warning or critical.', status: 422, extra: ['valid' => $valid]);
    }

    /** The session holds a good token and has not proved the second factor again within the minutes an export asks for (L-15). */
    public static function stepUpRequired(int $withinMinutes): self
    {
        return new self(
            errorCode: 'step_up_required',
            message: 'This action needs the second factor proved again.',
            status: 403,
            extra: ['within_minutes' => $withinMinutes],
        );
    }

    /** An export of a market's orders cannot be made on an installation where an order records no market (the GCC-05 migration has not run). */
    public static function orderMarketNotRecorded(): self
    {
        return new self(
            errorCode: 'order_market_not_recorded',
            message: 'Orders do not record which market they were sold in on this installation, so there is nothing to export by market.',
            status: 409,
        );
    }

    /** An export was asked for of every market at once. One export is one market's rows, so that what left the platform is a recorded fact. */
    public static function oneMarketOnly(): self
    {
        return new self(errorCode: 'one_market_only', message: 'An export is of one market. Name it with the market parameter.', status: 422);
    }

    public static function invalidWindow(string $message): self
    {
        return new self(errorCode: 'invalid_window', message: $message, status: 422);
    }

    public function render(): JsonResponse
    {
        return PlatformAdminResponses::error(code: $this->errorCode, message: $this->getMessage(), status: $this->status, extra: $this->extra);
    }
}
