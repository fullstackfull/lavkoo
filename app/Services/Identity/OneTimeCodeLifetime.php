<?php

namespace App\Services\Identity;

use Carbon\Carbon;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;

/**
 * How long a one-time code is good for, and the refusal of one that is older than that (A2-05).
 *
 * Both tables that hold a code have carried an `expires_at` column since 2023 and nothing has ever written or read it, so a code lives
 * until the next one for the same identity replaces it or the row is spent: a code texted last March still signs a customer in, and a reset
 * token mailed to an address they have since lost still changes their password. The attempt counter is no substitute -- it is cleared the
 * moment a new code is asked for, and the old row's code is good either way.
 *
 * The lifetime is measured from `created_at` and not from `expires_at`. Every issuing path in the application sets `created_at` to `now()`
 * as it writes the code (`CustomerPasswordResetService::issueCode`, the two resend actions, the seller and vendor reset mails), so it is the
 * moment the code was issued and it is already there on every row; `expires_at` is a second record of the same fact that nothing fills, and
 * a row where the two disagree has no answer. `updated_at` is NOT used: a wrong-code attempt moves it, so reading it would let a guesser
 * keep a dead code alive by guessing at it.
 *
 * Two lifetimes, because the two tables hold two different things: `phone_or_email_verifications` holds only codes a person TYPES, while
 * `password_resets` also holds the long random token that is MAILED as a link and is reached through an inbox. One figure for both would be
 * either too long for the code or too short for the link.
 *
 * While the switch is off `narrow()` hands the query back untouched -- not one clause is added -- so every path reads exactly the rows it
 * reads today.
 */
#[Singleton]
final class OneTimeCodeLifetime
{
    /** A code a person is sent and types back: the login OTP, the phone and e-mail confirmations. */
    public const CODE = 'code_minutes';

    /** A password-reset proof: the typed code, and the long token the seller reset mails as a link. */
    public const RESET = 'reset_minutes';

    private const FLOOR_MINUTES = 1;

    public function enabled(): bool
    {
        return (bool) config('gcc.identity.one_time_code_lifetime.enabled', false);
    }

    /** The configured lifetime of $kind. A figure below one minute is read as one: a lifetime of zero refuses the code as it is issued. */
    public function minutes(string $kind): int
    {
        return max(self::FLOOR_MINUTES, (int) config('gcc.identity.one_time_code_lifetime.' . $kind, 0));
    }

    /** The moment a code of $kind must have been issued after to still be good. */
    public function cutoff(string $kind): Carbon
    {
        return Carbon::now()->subMinutes($this->minutes($kind));
    }

    /**
     * Narrows a lookup of codes to the ones that are still within their lifetime. The query is handed back untouched while the switch is
     * off.
     *
     * A NULL `created_at` does not pass: nothing on the row says when its code was issued, so it cannot be shown to be live, and the safe
     * reading of a code of unknown age is that it has run out. No issuing path writes one.
     */
    public function narrow(Builder $query, string $kind): Builder
    {
        if (!$this->enabled()) {
            return $query;
        }

        return $query->whereNotNull('created_at')->where('created_at', '>', $this->cutoff($kind));
    }

    /** Whether the code on a row that has already been read has run out. False while the switch is off, and false when there is no row. */
    public function hasRunOut(?object $row, string $kind): bool
    {
        if (!$this->enabled() || $row === null) {
            return false;
        }

        $issuedAt = $row->created_at ?? null;

        return $issuedAt === null || Carbon::parse($issuedAt)->lessThanOrEqualTo($this->cutoff($kind));
    }
}
