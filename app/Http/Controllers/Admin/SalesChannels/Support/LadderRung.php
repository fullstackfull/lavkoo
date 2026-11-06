<?php

namespace App\Http\Controllers\Admin\SalesChannels\Support;

use App\Enums\SalesChannels\IntegrationCapability;
use Carbon\CarbonInterface;

/** One step of the activation ladder as the account page shows it: whether it is on, what it needs, what stops it being switched on, and who approved it. */
final readonly class LadderRung
{
    /**
     * @param list<array{capability: IntegrationCapability, held: bool}> $requires the capabilities that must already be on
     * @param string|null $blocker a code of ChannelLabels::ladderBlocker(); null when the step can be switched on
     * @param array{by: string|null, at: CarbonInterface|null, reason: string|null}|null $approval the recorded approval of a write capability
     * @param list<IntegrationCapability> $dependents what is switched off together with this step
     */
    public function __construct(
        public IntegrationCapability $capability,
        public bool $held,
        public array $requires,
        public ?string $blocker,
        public ?string $blockerDetail,
        public ?array $approval,
        public array $dependents,
    ) {
    }

    public function needsApproval(): bool
    {
        return $this->capability->needsApproval();
    }

    public function canSwitchOn(): bool
    {
        return !$this->held && $this->blocker === null;
    }

    /** On, but nobody's approval is on record: a write of this kind is refused until it is approved again. */
    public function approvalWithdrawn(): bool
    {
        return $this->held && $this->needsApproval() && $this->approval === null;
    }
}
