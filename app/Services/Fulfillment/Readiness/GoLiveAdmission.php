<?php

namespace App\Services\Fulfillment\Readiness;

/**
 * What the go-live checklist decided about a settings change that would make automatic sending live.
 *
 * `settings` are the settings to save: the submitted ones, except that `auto_dispatch` is taken back when the checklist held it
 * up. `overridden` names the unmet items an administrator chose to go ahead without, for the audit trail.
 */
final readonly class GoLiveAdmission
{
    /**
     * @param array<string, mixed> $settings
     * @param list<GoLiveItem> $blocked
     * @param list<string> $overridden
     */
    public function __construct(
        public array $settings,
        public array $blocked = [],
        public array $overridden = [],
    ) {
    }

    public function wasBlocked(): bool
    {
        return $this->blocked !== [];
    }

    /** @return array<string, mixed> */
    public function auditContext(): array
    {
        return $this->overridden === [] ? [] : ['go_live_gate_overridden' => $this->overridden];
    }
}
