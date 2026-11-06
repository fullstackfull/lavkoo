<?php

namespace App\Services\Fulfillment\Readiness;

/** What the go-live checklist decided about a settings change that would make automatic sending live. */
final readonly class GoLiveAdmission
{
    /**
     * @param list<GoLiveItem> $blocked unmet items that held automatic sending back
     * @param list<string> $overridden the unmet items an administrator went ahead without
     */
    public function __construct(
        public array $blocked = [],
        public array $overridden = [],
    ) {
    }

    public function wasBlocked(): bool
    {
        return $this->blocked !== [];
    }
}
