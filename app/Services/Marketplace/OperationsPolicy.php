<?php

namespace App\Services\Marketplace;

use App\Services\Platform\Policy;
use App\Services\Platform\PolicyRegistry;

/**
 * The windows the marketplace judges its sellers by.
 *
 * Every number here decides when a seller is told they are late, and every one of them was a private
 * constant in whichever detector happened to use it. They could not be changed without a deploy, and
 * because each was written where it was needed they disagreed: `sla_processing_hours` is the declared
 * ship-by promise and is editable; beside it sat a fixed 72 hours after which an order was "stuck", a
 * fixed quarter-of-the-window at which the same order became "urgent", and fixed 2- and 8-hour bands
 * that decided when the countdown in the panel turned amber.
 *
 * The values themselves are declared in `PolicyRegistry` with every other rule the platform applies
 * to itself, and read through `Policy`. This class stays as the typed reader for the detectors and
 * the countdown, so a producer asks for `stuckOrderHours()` rather than remembering a settings key.
 */
class OperationsPolicy
{
    private const GROUP = 'operations';

    public function __construct(private readonly ?Policy $policy = null)
    {
    }

    public function stuckOrderHours(): int
    {
        return $this->policy()->int('ops_stuck_order_hours');
    }

    public function stuckStopAfterDays(): int
    {
        return $this->policy()->int('ops_stuck_stop_after_days');
    }

    public function slaUrgentFraction(): float
    {
        return $this->policy()->float('ops_sla_urgent_fraction');
    }

    /**
     * The countdown's two warning bands, in minutes, closest first.
     *
     * "Warned" cannot begin before "closing" ends, whatever the two settings say — a soon band
     * narrower than the closing one would make the amber state unreachable rather than wrong.
     *
     * @return array{closing: int, soon: int}
     */
    public function slaBands(): array
    {
        $closing = $this->policy()->int('ops_sla_closing_minutes');

        return ['closing' => $closing, 'soon' => max($this->policy()->int('ops_sla_soon_minutes'), $closing)];
    }

    public function returnsResponseHours(): int
    {
        return $this->policy()->int('ops_returns_response_hours');
    }

    public function returnsProcessingHours(): int
    {
        return $this->policy()->int('ops_returns_processing_hours');
    }

    public function financeGraceHours(): int
    {
        return $this->policy()->int('ops_finance_grace_hours');
    }

    public function batchExpiryDays(): int
    {
        return $this->policy()->int('ops_batch_expiry_days');
    }

    /** Every window with its current value, for the screen that edits them. */
    public function all(): array
    {
        return $this->policy()->all(self::GROUP);
    }

    /** @return array<string, array{min: int|float, max: int|float}> */
    public static function limits(): array
    {
        $limits = [];

        foreach (PolicyRegistry::GROUPS[self::GROUP]['policies'] as $key => $definition) {
            $limits[$key] = ['min' => $definition['min'], 'max' => $definition['max']];
        }

        return $limits;
    }

    /** @return array<string, int|float> */
    public static function defaults(): array
    {
        return array_map(
            static fn (array $definition) => $definition['default'],
            PolicyRegistry::GROUPS[self::GROUP]['policies'],
        );
    }

    private function policy(): Policy
    {
        return $this->policy ?? app(Policy::class);
    }
}
