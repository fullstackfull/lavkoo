<?php

namespace App\Services\Marketplace;

use App\Services\Platform\Policy;

/**
 * One answer to "how much stock is left, and is that a problem".
 *
 * The audit found four: the inventory screen coloured a row red under one day of cover and amber
 * under three, the daily briefing raised a restock under seven, an opportunity card suggested one
 * under fourteen — and the briefing measured the sales rate over thirty days while the screen the
 * seller then opened measured it over fourteen. So the same shop was told three different low-stock
 * counts, and the cover figure on the finding did not match the cover figure on the screen it linked
 * to.
 *
 * The four thresholds survive, because they are four different actions rather than four opinions,
 * but they are one declared and ordered ladder now: a step can never sit above the step outside it,
 * whatever the settings say, because an amber band tighter than the red one is unreachable rather
 * than merely wrong.
 *
 * The measurement window does not survive twice. Every cover figure on every surface is measured
 * over `stock_velocity_days`, so a seller who clicks a finding lands on a screen that agrees with it.
 */
class StockPolicy
{
    public function __construct(private readonly ?Policy $policy = null)
    {
    }

    /**
     * The cover ladder in days, tightest first and non-decreasing.
     *
     * @return array{critical: float, low: float, raise: float, opportunity: float}
     */
    public function coverBands(): array
    {
        $critical = $this->policy()->float('stock_cover_critical_days');
        $low = max($this->policy()->float('stock_cover_low_days'), $critical);
        $raise = max($this->policy()->float('stock_cover_raise_days'), $low);
        $opportunity = max($this->policy()->float('stock_cover_opportunity_days'), $raise);

        return ['critical' => $critical, 'low' => $low, 'raise' => $raise, 'opportunity' => $opportunity];
    }

    /** The window every cover figure is measured over. */
    public function velocityDays(): int
    {
        return $this->policy()->int('stock_velocity_days');
    }

    public function staleDays(): int
    {
        return $this->policy()->int('stock_stale_days');
    }

    public function staleMinimumUnits(): int
    {
        return $this->policy()->int('stock_stale_minimum_units');
    }

    private function policy(): Policy
    {
        return $this->policy ?? app(Policy::class);
    }
}
