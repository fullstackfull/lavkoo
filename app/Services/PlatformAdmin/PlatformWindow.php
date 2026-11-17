<?php

namespace App\Services\PlatformAdmin;

use App\Exceptions\PlatformAdminApiException;
use Carbon\CarbonImmutable;

/**
 * The span of calendar days an overview reads, in the application time zone: from the first moment of the first day to the last moment of the last.
 * No query of the API reads further back than the longest window. A window that asks for more is cut at its old end, and says so; one that
 * cannot be read, or whose end comes before its start, is refused.
 */
final readonly class PlatformWindow
{
    private function __construct(public CarbonImmutable $from, public CarbonImmutable $to, public bool $clamped)
    {
    }

    /**
     * @throws PlatformAdminApiException
     */
    public static function resolve(mixed $from, mixed $to, int $defaultDays, int $maxDays): self
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();

        $end = $to === null ? $today : self::parse($to);
        $start = $from === null ? $end->subDays($defaultDays - 1) : self::parse($from);

        if ($start->greaterThan($end)) {
            throw PlatformAdminApiException::invalidWindow('The start of the window must not be after its end.');
        }

        $earliestAllowed = $end->subDays($maxDays - 1);
        $clamped = $start->lessThan($earliestAllowed);

        return new self(from: $clamped ? $earliestAllowed : $start, to: $end, clamped: $clamped);
    }

    /** The last days up to today, the span the queries that are not about a window (a backlog, an alert) read at most. */
    public static function lastDays(int $days): self
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();

        return new self(from: $today->subDays($days - 1), to: $today, clamped: false);
    }

    public function startsAt(): CarbonImmutable
    {
        return $this->from;
    }

    /** The first moment after the window: a window ends before it, so that its last second is inside. */
    public function endsBefore(): CarbonImmutable
    {
        return $this->to->addDay();
    }

    /** @return array{from: string, to: string, days: int, clamped: bool, timezone: string} */
    public function toArray(): array
    {
        return [
            'from' => $this->from->format('Y-m-d'),
            'to' => $this->to->format('Y-m-d'),
            'days' => (int) round($this->from->diffInDays($this->to)) + 1,
            'clamped' => $this->clamped,
            'timezone' => (string) config('app.timezone'),
        ];
    }

    private static function parse(mixed $value): CarbonImmutable
    {
        $isDate = is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);

        if (!$isDate) {
            throw PlatformAdminApiException::invalidWindow('The window must be given as dates written YYYY-MM-DD.');
        }

        return CarbonImmutable::create((int) $parts[1], (int) $parts[2], (int) $parts[3], 0, 0, 0, config('app.timezone'));
    }
}
