<?php

namespace App\Services\PlatformAdmin;

/**
 * Which markets one request asked about and may see.
 *
 * `requested` is what the request said: `all` or one market. `markets` is what that comes to for this admin: every market they may see for
 * `all`, which for a restricted admin means all of theirs, and the one market otherwise. The orders that belong to no market are shown only
 * to the platform owner and only when asking about all of them, because they might belong to a market a restricted admin may not see.
 */
final readonly class MarketSelection
{
    public const ALL = 'all';

    /** @param list<string> $markets */
    public function __construct(
        public string $requested,
        public array $markets,
        public string $scope,
        public bool $includesUnattributed,
    ) {
    }
}
