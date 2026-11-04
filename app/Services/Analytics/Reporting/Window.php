<?php

namespace App\Services\Analytics\Reporting;

use Illuminate\Support\Carbon;

/**
 * The date range a report covers, and the range it is compared against.
 *
 * A number on its own says almost nothing. "412 visits" is not information; "412 visits, up 18% on
 * the previous seven days" is. So a window always carries its comparison period, computed as the
 * same length immediately before it — which is the only comparison that is not arbitrary.
 *
 * Everything here works in whole days in the application's own timezone, because a merchant thinks
 * in days, not in rolling 24-hour periods. The monitoring side works in UTC minutes for the
 * opposite and equally correct reason: an outage does not respect a calendar.
 */
final class Window
{
    /** The ranges the UI offers, in days. */
    public const RANGES = [
        'today' => 1,
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
        '365d' => 365,
    ];

    private function __construct(
        public readonly string $key,
        public readonly int $days,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Carbon $previousFrom,
        public readonly Carbon $previousTo,
    ) {
    }

    public static function make(?string $key): self
    {
        $key = isset(self::RANGES[$key]) ? $key : '30d';
        $days = self::RANGES[$key];

        $to = Carbon::today();
        $from = $to->copy()->subDays($days - 1);

        return new self(
            key: $key,
            days: $days,
            from: $from,
            to: $to,
            // The same length, ending the day before this window starts. Comparing a 7-day window
            // against "last month" would make every Monday look like a collapse.
            previousFrom: $from->copy()->subDays($days),
            previousTo: $from->copy()->subDay(),
        );
    }

    /** A custom range an operator typed, clamped so a report cannot be asked for a decade. */
    public static function between(string $from, string $to): self
    {
        try {
            $start = Carbon::parse($from)->startOfDay();
            $end = Carbon::parse($to)->startOfDay();
        } catch (\Throwable) {
            return self::make('30d');
        }

        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        $days = min(730, max(1, (int) $start->diffInDays($end) + 1));
        $end = $start->copy()->addDays($days - 1);

        return new self(
            key: 'custom',
            days: $days,
            from: $start,
            to: $end,
            previousFrom: $start->copy()->subDays($days),
            previousTo: $start->copy()->subDay(),
        );
    }

    /** True when the window includes today, whose rollup is by definition incomplete. */
    public function includesToday(): bool
    {
        return $this->to->isToday() || $this->to->isFuture();
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    public function previousFromDate(): string
    {
        return $this->previousFrom->toDateString();
    }

    public function previousToDate(): string
    {
        return $this->previousTo->toDateString();
    }

    /** @return array<int, string> every date in the window, so a chart has no missing days */
    public function dates(): array
    {
        $dates = [];
        $cursor = $this->from->copy();

        while ($cursor->lte($this->to)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }

    public function label(): string
    {
        return match ($this->key) {
            'today' => 'today',
            '7d' => 'last_7_days',
            '30d' => 'last_30_days',
            '90d' => 'last_90_days',
            '365d' => 'last_365_days',
            default => 'custom_range',
        };
    }
}
