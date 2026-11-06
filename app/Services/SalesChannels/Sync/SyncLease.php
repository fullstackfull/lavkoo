<?php

namespace App\Services\SalesChannels\Sync;

use Carbon\CarbonInterface;

/** The right to run one resource of one account's sync. Only one run holds it at a time. */
final readonly class SyncLease
{
    /** @param array<string, mixed>|null $cursor where the previous run stopped, if it did not finish */
    public function __construct(
        public int $accountId,
        public string $resource,
        public string $token,
        public ?CarbonInterface $watermark,
        public ?array $cursor,
    ) {
    }
}
