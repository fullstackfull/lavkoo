<?php

namespace App\Services\Identity;

use Illuminate\Support\Facades\DB;

/**
 * One customer's e-mail address as an identity: the one spelling of it, and whether an account already holds it (A2-06, M2-04).
 *
 * The phone half of this card is {@see CustomerPhone}. This is the other half, and the defect is the same shape with a different cause.
 *
 * **Whether two spellings of one address are one account is decided by the DATABASE, not by the platform.** `unique:users` compares the
 * string that was typed, through the server's own collation, so what counts as one identity changes with the engine the shop runs on.
 * Measured on this platform's own servers:
 *
 *   - MariaDB 10.11 with `utf8mb4_general_ci` answers `'Foo@example.test' = 'foo@example.test'` TRUE, so the capitals are one account --
 *     and `' foo@example.test' = 'foo@example.test'` FALSE, so a leading space is a SECOND account for the same person, who then cannot
 *     sign in with the address they think they registered;
 *   - SQLite, which the test suite runs on, compares text case-sensitively, so there the capitals are a second account too.
 *
 * One identity that depends on which server answered is not an identity. The canonical key is the one spelling of an address -- trimmed,
 * lower-cased, with a trailing dot taken off the domain -- and {@see accountHoldingTheSameAddress()} looks for an account by it, so the
 * answer is the same on every engine.
 *
 * **What is deliberately NOT folded in.** A dot inside the local part, and a `+tag` after it, are left exactly as they were typed. Those
 * are one mailbox at some providers and two at others; folding them would merge accounts the platform has always kept apart, which is a
 * change to who owns what and not a repair of a spelling.
 *
 * **No schema change, and therefore one known limit.** Whether `users` gets a canonical column or a case-insensitive key is the owner's
 * open decision, because it is a migration over live data whose duplicates have to be counted first (A2-01). So the search is made from
 * the SPELLINGS the address could have been stored in -- an exact `whereIn`, which uses the index `users.email` has -- and every candidate
 * is confirmed in PHP with {@see isSameAddress()}. On a real server the collation widens that search to every difference of case by
 * itself; on SQLite a row stored `FOO@example.test` is not found by a search for `foo@example.test`, and that limit is pinned by a test
 * rather than papered over, because a guard that half works has to say which half.
 */
class CustomerEmail
{
    /** How many accounts are confirmed in PHP: a list of exact spellings of one address cannot honestly match more than a handful. */
    private const CANDIDATE_LIMIT = 50;

    /** Off as it ships: a creation path refuses a second account for an address exactly as it does today, by the string that was typed. */
    public static function isChecked(): bool
    {
        return (bool) config('gcc.identity.canonical_email.checked');
    }

    /**
     * The one spelling of this address: trimmed, lower-cased, and with a single trailing dot taken off the domain.
     *
     * Null when the value is not one address -- no `@`, more than one, or an empty side. A caller that gets null falls back to what it did
     * before, because a key nobody can compute must not become a refusal.
     *
     * It takes no market and no storefront on purpose: an address names its own mailbox wherever it is typed, so the key a customer is
     * known by is the same in all six markets and there is no country whose rule is the default.
     */
    public static function canonical(?string $raw): ?string
    {
        $trimmed = trim((string) $raw);
        $parts = explode('@', $trimmed);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        $domain = rtrim(mb_strtolower($parts[1]), '.');

        if ($domain === '') {
            return null;
        }

        return mb_strtolower($parts[0]) . '@' . $domain;
    }

    /** Whether two addresses are the same mailbox, which is the one question the exact-string key answers differently on each engine. */
    public static function isSameAddress(?string $first, ?string $second): bool
    {
        $canonical = self::canonical($first);

        return $canonical !== null && $canonical === self::canonical($second);
    }

    /**
     * Every spelling of this address a row could have been written in: the address as it was typed, the same trimmed, the canonical key,
     * and the local part as typed against the lower-cased domain.
     *
     * @return list<string>
     */
    public static function spellingsOf(?string $raw): array
    {
        $canonical = self::canonical($raw);

        if ($canonical === null) {
            return [];
        }

        $trimmed = trim((string) $raw);
        [$local, $domain] = explode('@', $trimmed);

        return array_values(array_unique([
            (string) $raw,
            $trimmed,
            $canonical,
            $canonical . '.',
            $local . '@' . rtrim(mb_strtolower($domain), '.'),
        ]));
    }

    /**
     * The id of an account that already holds this address in any spelling it is searched for, or null when none does.
     *
     * `$exceptId` is the account being edited, which holds its own address and is not a duplicate of itself.
     */
    public function accountHoldingTheSameAddress(?string $raw, int|string|null $exceptId = null): ?int
    {
        if (self::canonical($raw) === null) {
            return null;
        }

        foreach (self::accountsWithEmailIn(self::spellingsOf($raw), $exceptId) as $account) {
            if (self::isSameAddress((string) $account->email, $raw)) {
                return (int) $account->id;
            }
        }

        return null;
    }

    /**
     * @param list<string> $spellings
     * @return list<object{id: int|string, email: ?string}>
     */
    private static function accountsWithEmailIn(array $spellings, int|string|null $exceptId): array
    {
        if ($spellings === []) {
            return [];
        }

        return DB::table('users')
            ->whereIn('email', $spellings)
            ->when($exceptId !== null, static fn ($query) => $query->where('id', '!=', $exceptId))
            ->limit(self::CANDIDATE_LIMIT)
            ->get(['id', 'email'])
            ->all();
    }
}
