<?php

namespace App\Services\PlatformAdmin;

use App\Models\PlatformAdminToken;
use Carbon\CarbonInterface;

/** The arrays the API answers with for an admin and a session. Timestamps are UTC, in ISO 8601 with a trailing Z. */
final class PlatformAdminPresenter
{
    /** @return array{admin_id: int, name: string, level: int, scope: string, markets: list<string>, token_expires_at: ?string} */
    public static function me(PlatformAdminPrincipal $principal): array
    {
        return [
            'admin_id' => $principal->adminId(),
            'name' => (string) $principal->admin->name,
            'level' => $principal->level(),
            'scope' => $principal->scope(),
            'markets' => $principal->markets,
            'token_expires_at' => self::timestamp(moment: $principal->token->expires_at),
        ];
    }

    /** @return array{id: int, device_name: string, created_at: ?string, last_used_at: ?string, expires_at: ?string, current: bool} */
    public static function session(PlatformAdminToken $token, int $currentTokenId): array
    {
        return [
            'id' => (int) $token->getKey(),
            'device_name' => $token->device_name,
            'created_at' => self::timestamp(moment: $token->created_at),
            'last_used_at' => self::timestamp(moment: $token->last_used_at),
            'expires_at' => self::timestamp(moment: $token->expires_at),
            'current' => (int) $token->getKey() === $currentTokenId,
        ];
    }

    public static function timestamp(?CarbonInterface $moment): ?string
    {
        return $moment?->copy()->utc()->toIso8601ZuluString();
    }
}
