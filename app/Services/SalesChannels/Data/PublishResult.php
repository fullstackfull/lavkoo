<?php

namespace App\Services\SalesChannels\Data;

/**
 * What a publish came to, per listing. `accepted` means the channel took the request, not that it has been applied: channels that process asynchronously give a batch id
 * that is followed up, and `failed` carries the channel's own reason. Nothing is "assumed applied".
 */
final readonly class PublishResult
{
    /**
     * @param array<int, string> $accepted mapping ids the channel accepted
     * @param array<int, string> $failed mapping id => reason
     */
    public function __construct(
        public array $accepted,
        public array $failed,
        public ?string $batchId = null,
        public bool $dryRun = false,
    ) {
    }
}
