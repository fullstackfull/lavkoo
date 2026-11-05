<?php

namespace App\Services\Fulfillment\Readiness\Staging;

/** A way the platform can reach a real person or a third party, with everything a restored database needs done to it. */
final readonly class OutboundChannel
{
    public const CRITICAL = 'critical';
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';

    /**
     * @param list<SinkTarget> $targets
     * @param string|null $manual what a person must do when no portable SQL can (the audit still detects it)
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $risk,
        public string $why,
        public array $targets,
        public ?string $manual = null,
    ) {
    }
}
