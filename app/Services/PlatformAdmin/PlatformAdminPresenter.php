<?php

namespace App\Services\PlatformAdmin;

use App\Models\PlatformAdminToken;
use Carbon\CarbonInterface;

/** The arrays the API answers with for an admin and a session. Timestamps are UTC, in ISO 8601 with a trailing Z. */
final class PlatformAdminPresenter
{
    /** The fewest decimal places an amount is written with. The most is whatever the sum itself holds. */
    private const AMOUNT_DECIMALS = 2;

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

    /**
     * What `GET /me` answers: the admin as the sign-in shows them, what their grant includes and when it ends, and how this session stands with the second
     * factor. `enrolled` is whether the admin has a factor they may sign in with, `verified` whether this session passed it, and `method` how.
     *
     * @return array<string, mixed>
     */
    public static function profile(PlatformAdminPrincipal $principal, bool $enrolled): array
    {
        $method = $principal->token->mfaMethod();

        return self::me(principal: $principal) + [
            'capabilities' => $principal->capabilities(),
            'grant_expires_at' => self::timestamp(moment: $principal->grant->expires_at),
            'mfa' => ['enrolled' => $enrolled, 'verified' => $method !== null, 'method' => $method?->value],
        ];
    }

    /** @return array{id: int, device_name: string, created_at: ?string, last_used_at: ?string, expires_at: ?string, current: bool, mfa_method: ?string} */
    public static function session(PlatformAdminToken $token, int $currentTokenId): array
    {
        return [
            'id' => (int) $token->getKey(),
            'device_name' => $token->device_name,
            'created_at' => self::timestamp(moment: $token->created_at),
            'last_used_at' => self::timestamp(moment: $token->last_used_at),
            'expires_at' => self::timestamp(moment: $token->expires_at),
            'current' => (int) $token->getKey() === $currentTokenId,
            'mfa_method' => $token->mfaMethod()?->value,
        ];
    }

    public static function timestamp(?CarbonInterface $moment): ?string
    {
        return $moment?->copy()->utc()->toIso8601ZuluString();
    }

    /**
     * A sum of money as text, exactly as the database added it up, with the trailing zeros of the column's twenty decimal places taken off and
     * two always kept. Text and not a number: a JSON number of an amount is read back as a float, and the fractions of six currencies are not a
     * thing to lose a digit of. Nothing is rounded here -- a digit that is not a zero stays, however far out it is, because a figure that is not
     * what the engine summed is a figure nobody can check.
     */
    public static function amount(mixed $sum): ?string
    {
        if (!is_numeric($sum)) {
            return null;
        }

        $text = is_string($sum) ? trim($sum) : (string) $sum;

        if (stripos($text, 'e') !== false) {
            $text = number_format((float) $text, self::AMOUNT_DECIMALS, '.', '');
        }

        if (!str_contains($text, '.')) {
            return $text . '.' . str_repeat('0', self::AMOUNT_DECIMALS);
        }

        [$whole, $fraction] = explode('.', $text, 2);

        return $whole . '.' . str_pad(rtrim($fraction, '0'), self::AMOUNT_DECIMALS, '0');
    }
}
