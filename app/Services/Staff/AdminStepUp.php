<?php

namespace App\Services\Staff;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Proving again, inside a session that is already signed in, that the person at the keyboard is the employee (G-14).
 *
 * Signing in proves it once, for a session that lasts as long as the panel's session lifetime. An action that changes who may do what --
 * adding an employee, rewriting a role, emptying a table -- asks for the password again within minutes, so that an unattended panel on a
 * desk is not the same thing as the employee.
 *
 * The proof travels WITH the action: there is no separate confirmation address, because a second address would be a second place to get
 * the authorization of it wrong, and the field is read from the request the action itself carries. Proving it stamps the session, so a run
 * of actions inside the window asks once rather than once per click.
 *
 * Three things this deliberately does NOT do, each because of what it would cost:
 *
 * - **It does not trust a stamp made by another account.** The stamp records the admin who made it, so an account that signs out and
 *   another that signs in on the same session inherits nothing. Laravel regenerates the session id on sign-in, which already makes this
 *   unlikely; the id is compared anyway, because "unlikely" is not a property an authorization check should rest on.
 * - **It does not treat a stamp in the future as fresh.** A clock moved backwards, or a stamp written by anything other than this service,
 *   would otherwise be fresh until the clock caught up. The window has both ends.
 * - **It does not answer a password check without a password.** An employee row whose `password` is empty -- which the panel's own
 *   seeding can leave -- would otherwise be confirmable by anybody typing anything, since `Hash::check` against an empty hash is a
 *   refusal only by accident of the driver. It is refused by name instead.
 *
 * Guessing at a step-up is guessing at the password, so a failure counts towards a per-admin, per-minute limit, and a caller is told only
 * that the proof was not accepted.
 */
final class AdminStepUp
{
    /** Where the proof is stamped on the panel's session. */
    public const SESSION_KEY = 'gcc_admin_step_up';

    /** The field a sensitive action carries its proof in. */
    public const PASSWORD_FIELD = 'step_up_password';

    public function isRequired(): bool
    {
        return (bool) config('gcc.staff.step_up.enabled', false);
    }

    /** How long one proof lasts. A window of less than a minute is read as one minute: see the config note. */
    public function minutes(): int
    {
        return max(1, (int) config('gcc.staff.step_up.minutes', 10));
    }

    /** Whether the session already holds a proof this employee made inside the window. */
    public function isFresh(Request $request): bool
    {
        $admin = $this->signedInAdmin();
        $stamp = $this->stamp(request: $request);

        if ($admin === null || $stamp === null || $stamp['admin'] !== (int) $admin->getKey()) {
            return false;
        }

        $at = Carbon::createFromTimestamp($stamp['at']);

        return !$at->isFuture() && $at->greaterThanOrEqualTo(now()->subMinutes($this->minutes()));
    }

    /**
     * Reads the proof the action carries, and stamps the session when it is the signed-in employee's own password.
     *
     * Returns false for a missing field, a wrong password, an employee with no password stored, and an employee who has tried too often in
     * the last minute -- the caller refuses the action in every one of those cases and is told no more than that.
     */
    public function confirmFromRequest(Request $request): bool
    {
        $admin = $this->signedInAdmin();
        $typed = $request->input(self::PASSWORD_FIELD);

        if ($admin === null || !is_string($typed) || $typed === '') {
            return false;
        }

        $limit = 'gcc-admin-step-up:' . $admin->getKey();
        if (RateLimiter::tooManyAttempts(key: $limit, maxAttempts: $this->attemptsPerMinute())) {
            return false;
        }

        if (!$this->isTheirOwnPassword(admin: $admin, typed: $typed)) {
            RateLimiter::hit(key: $limit);

            return false;
        }

        RateLimiter::clear(key: $limit);
        $this->stampTheSession(request: $request, admin: $admin);

        return true;
    }

    /** Takes a proof back, for a sign-out or a test. */
    public function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }

    private function attemptsPerMinute(): int
    {
        return max(1, (int) config('gcc.staff.step_up.attempts_per_minute', 5));
    }

    private function isTheirOwnPassword(Admin $admin, string $typed): bool
    {
        $stored = (string) ($admin->password ?? '');

        return $stored !== '' && Hash::check($typed, $stored);
    }

    private function stampTheSession(Request $request, Admin $admin): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, ['admin' => (int) $admin->getKey(), 'at' => now()->getTimestamp()]);
        }
    }

    /** @return array{admin: int, at: int}|null */
    private function stamp(Request $request): ?array
    {
        if (!$request->hasSession()) {
            return null;
        }

        $stamp = $request->session()->get(self::SESSION_KEY);

        return is_array($stamp) && isset($stamp['admin'], $stamp['at']) && is_numeric($stamp['admin']) && is_numeric($stamp['at'])
            ? ['admin' => (int) $stamp['admin'], 'at' => (int) $stamp['at']]
            : null;
    }

    private function signedInAdmin(): ?Admin
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin ? $admin : null;
    }
}
