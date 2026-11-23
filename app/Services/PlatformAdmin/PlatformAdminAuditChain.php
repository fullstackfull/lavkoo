<?php

namespace App\Services\PlatformAdmin;

/**
 * What a line of the audit trail is hashed as. The hash is the SHA-256 of a canonical JSON of everything about the line except its id (the database gives that
 * after the line is written, and the order of the chain pins it): the admin, the action, the market, the session, the address, the user agent, the context with
 * its keys sorted at every depth, the moment it was written as the database holds it, and the hash of the line before. Text is not escaped, so that an engine
 * that stores it either way gives the same hash, and a list keeps its order.
 *
 * Changing any of this makes every chain that exists fail its verification, which is why the form is spelled out in a test.
 */
final class PlatformAdminAuditChain
{
    /** What the first line of a chain follows: there is no line before it. */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** @param array<array-key, mixed>|null $context */
    public static function hashOf(
        ?int $adminId,
        string $action,
        ?string $market,
        ?int $tokenId,
        ?string $ip,
        ?string $userAgent,
        ?array $context,
        string $createdAt,
        string $previousHash,
    ): string {
        return hash('sha256', json_encode([
            'admin_id' => $adminId,
            'action' => $action,
            'market' => $market,
            'token_id' => $tokenId,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'context' => $context === null ? null : self::sortedByKey(value: $context),
            'created_at' => $createdAt,
            'prev_hash' => $previousHash,
        ], self::JSON_FLAGS));
    }

    /** The hash a line as it is stored should have, from the row as the database gives it back: numbers may arrive as text and a JSON column in another order. */
    public static function hashOfRow(object $row): string
    {
        return self::hashOf(
            adminId: $row->admin_id === null ? null : (int) $row->admin_id,
            action: (string) $row->action,
            market: $row->market === null ? null : (string) $row->market,
            tokenId: $row->token_id === null ? null : (int) $row->token_id,
            ip: $row->ip === null ? null : (string) $row->ip,
            userAgent: $row->user_agent === null ? null : (string) $row->user_agent,
            context: self::contextOf(stored: $row->context),
            createdAt: (string) $row->created_at,
            previousHash: (string) $row->prev_hash,
        );
    }

    /** @return array<array-key, mixed>|null a context that cannot be read is not taken for none: it hashes as what it holds */
    private static function contextOf(mixed $stored): ?array
    {
        if ($stored === null) {
            return null;
        }

        $decoded = json_decode((string) $stored, true);

        return is_array($decoded) ? $decoded : ['unreadable' => (string) $stored];
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function sortedByKey(array $value): array
    {
        $sorted = array_map(static fn (mixed $item): mixed => is_array($item) ? self::sortedByKey(value: $item) : $item, $value);

        if (!array_is_list($sorted)) {
            ksort($sorted, SORT_STRING);
        }

        return $sorted;
    }
}
