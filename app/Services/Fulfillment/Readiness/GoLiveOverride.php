<?php

namespace App\Services\Fulfillment\Readiness;

/** An administrator's decision to turn automatic sending on although the checklist is not met: who decided, and why. */
final readonly class GoLiveOverride
{
    public function __construct(
        public int|string|null $adminId,
        public string $adminName,
        public string $reason,
    ) {
    }

    /**
     * What is kept on the provider's settings for as long as automatic sending runs on this decision, so it stays visible on the page and
     * does not depend on an audit table that a particular installation may not have.
     *
     * @param list<string> $unmetKeys
     * @return array<string, mixed>
     */
    public function record(array $unmetKeys): array
    {
        return [
            'at' => now()->toDateTimeString(),
            'by_id' => $this->adminId,
            'by' => $this->adminName,
            'reason' => $this->reason,
            'unmet' => $unmetKeys,
        ];
    }
}
