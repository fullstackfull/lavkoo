<?php

namespace App\Http\Controllers\Admin\OperationalCosts\Filters;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The date range of a screen that adds sales or costs up: always a range, the current month when none (or an unusable one) is given. Such a screen is never
 * asked to read "everything".
 */
final readonly class ReportPeriod
{
    public function __construct(public CarbonImmutable $from, public CarbonImmutable $to, public bool $wasInvalid = false)
    {
    }

    public static function fromRequest(Request $request): self
    {
        if (!QueryInput::sent($request, 'from') && !QueryInput::sent($request, 'to')) {
            return self::currentMonth();
        }
        $from = QueryInput::date($request, 'from');
        $to = QueryInput::date($request, 'to');

        return $from !== null && $to !== null && $to->gte($from) ? new self($from, $to) : self::currentMonth(wasInvalid: true);
    }

    public static function currentMonth(bool $wasInvalid = false): self
    {
        $today = CarbonImmutable::today();

        return new self($today->startOfMonth(), $today->endOfMonth()->startOfDay(), $wasInvalid);
    }
}
