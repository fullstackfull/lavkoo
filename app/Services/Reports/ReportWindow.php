<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;

/**
 * The period a seller report covers, and the buckets its chart is drawn in.
 *
 * The vendor report controllers each grew their own copy of this: four near-identical
 * `*_same_year` / `*_same_month` / `*_this_week` / `*_different_year` families, one set for orders
 * and another for products, differing only in what they summed. Resolving the period once — and
 * deciding the bucket size from the period rather than at every call site — is what lets the order
 * report, the product report and the mobile API agree on what "this month" means.
 *
 * Everything works in whole days in the application's timezone: a merchant reads a report by the
 * calendar, not by rolling 24-hour periods.
 */
final class ReportWindow
{
    public const TODAY = 'today';
    public const THIS_WEEK = 'this_week';
    public const THIS_MONTH = 'this_month';
    public const THIS_YEAR = 'this_year';
    public const CUSTOM = 'custom_date';

    /** The periods the panel and the app offer, in the order they are shown. */
    public const TYPES = [self::TODAY, self::THIS_WEEK, self::THIS_MONTH, self::THIS_YEAR, self::CUSTOM];

    /** Chart buckets, chosen from the period's length rather than named by the caller. */
    public const BUCKET_HOUR = 'hour';
    public const BUCKET_WEEKDAY = 'weekday';
    public const BUCKET_DAY = 'day';
    public const BUCKET_MONTH = 'month';
    public const BUCKET_YEAR = 'year';

    /** A custom range wider than this is a request nobody means, and a query nobody should run. */
    private const MAX_CUSTOM_DAYS = 1096;

    private function __construct(
        public readonly string $type,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly string $bucket,
    ) {
    }

    /**
     * Resolve a period from the request's own vocabulary.
     *
     * An unknown type falls back to this_year, which is what the panel has always defaulted to. A
     * custom range missing either end is not custom at all, so it falls back the same way rather
     * than silently covering all of time.
     */
    public static function make(?string $type, ?string $from = null, ?string $to = null): self
    {
        $type = in_array($type, self::TYPES, true) ? $type : self::THIS_YEAR;

        if ($type === self::CUSTOM) {
            return self::custom(from: $from, to: $to);
        }

        return match ($type) {
            self::TODAY => new self(
                type: $type,
                from: Carbon::today(),
                to: Carbon::today()->endOfDay(),
                bucket: self::BUCKET_HOUR,
            ),
            self::THIS_WEEK => new self(
                type: $type,
                from: Carbon::now()->startOfWeek(),
                to: Carbon::now()->endOfWeek(),
                bucket: self::BUCKET_WEEKDAY,
            ),
            self::THIS_MONTH => new self(
                type: $type,
                from: Carbon::now()->startOfMonth(),
                to: Carbon::now()->endOfMonth(),
                bucket: self::BUCKET_DAY,
            ),
            default => new self(
                type: self::THIS_YEAR,
                from: Carbon::now()->startOfYear(),
                to: Carbon::now()->endOfYear(),
                bucket: self::BUCKET_MONTH,
            ),
        };
    }

    private static function custom(?string $from, ?string $to): self
    {
        $start = self::parse($from);
        $end = self::parse($to);

        if ($start === null || $end === null) {
            return self::make(self::THIS_YEAR);
        }

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        $start = $start->startOfDay();
        $end = $end->endOfDay();

        // Counted between the two days, not between midnight and one second to midnight: the
        // fractional day an end-of-day boundary adds used to push a calendar month over the
        // day-bucket threshold and draw it as two bars.
        $spanInDays = (int) $start->diffInDays($end->copy()->startOfDay());

        if ($spanInDays > self::MAX_CUSTOM_DAYS) {
            $end = $start->copy()->addDays(self::MAX_CUSTOM_DAYS)->endOfDay();
            $spanInDays = self::MAX_CUSTOM_DAYS;
        }

        return new self(
            type: self::CUSTOM,
            from: $start,
            to: $end,
            // Bucket by what the range spans, so a two-day custom range is not drawn as one point
            // and a three-year one is not drawn as a thousand.
            bucket: match (true) {
                $spanInDays <= 31 => self::BUCKET_DAY,
                $spanInDays > 366 => self::BUCKET_YEAR,
                default => self::BUCKET_MONTH,
            },
        );
    }

    private static function parse(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            // A date the panel could not have produced is a date we decline to guess at.
            return null;
        }
    }

    /**
     * Narrow a query to this period.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function apply($query, string $column = 'created_at')
    {
        return $query->whereBetween($column, [$this->from, $this->to]);
    }

    /**
     * The empty chart: every bucket the period contains, in order, at zero.
     *
     * Built from the calendar rather than from the rows, so a month with no sales draws a flat line
     * instead of disappearing — the difference between "nothing sold" and "no data".
     *
     * @return array<string, float|int>
     */
    public function emptySeries(): array
    {
        $series = [];
        $cursor = $this->from->copy();

        while ($cursor->lessThanOrEqualTo($this->to)) {
            $series[$this->labelFor($cursor)] = 0;
            $cursor = match ($this->bucket) {
                self::BUCKET_HOUR => $cursor->addHour(),
                self::BUCKET_WEEKDAY, self::BUCKET_DAY => $cursor->addDay(),
                self::BUCKET_MONTH => $cursor->addMonthNoOverflow(),
                default => $cursor->addYear(),
            };
        }

        return $series;
    }

    /** The bucket a row's timestamp belongs to, in the same vocabulary [emptySeries] uses. */
    public function labelFor(Carbon $moment): string
    {
        return match ($this->bucket) {
            self::BUCKET_HOUR => $moment->format('H:00'),
            self::BUCKET_WEEKDAY => $moment->format('l'),
            // Carries its month: a custom range from the 20th to the 20th would otherwise label two
            // different days the same and fold them into one bar.
            self::BUCKET_DAY => $moment->format('j M'),
            self::BUCKET_MONTH => $moment->format('M'),
            default => (string) $moment->year,
        };
    }

    /**
     * Bucket a set of rows into the chart series.
     *
     * @param  iterable<object|array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function series(iterable $rows, string $valueKey, string $dateKey = 'created_at'): array
    {
        $series = $this->emptySeries();

        foreach ($rows as $row) {
            $moment = self::parse((string) data_get($row, $dateKey));
            if ($moment === null) {
                continue;
            }

            $label = $this->labelFor($moment);
            if (array_key_exists($label, $series)) {
                $series[$label] += (float) data_get($row, $valueKey, 0);
            }
        }

        return $series;
    }

    /**
     * The chart's labels, in the order the series is drawn.
     *
     * @return array<int, string>
     */
    public function seriesLabels(): array
    {
        return array_keys($this->emptySeries());
    }
}
