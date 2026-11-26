<?php

namespace App\Services\Identity;

use Illuminate\Http\Request;

/**
 * Whether a provider callback belongs to the browser that started the sign-in (A2-04).
 *
 * The website sends a customer to Google, Facebook or Apple with `redirect()`, which writes a one-time `state` into the session and
 * asks the provider to hand it back. The callback then reads the profile with `stateless()`, which tells Socialite to skip the
 * comparison -- so a callback another site forged, or one captured from a shared log or a referrer header and replayed, is treated
 * exactly like one this store started. That is the whole purpose of `state`, and nothing was comparing it.
 *
 * The comparison is made HERE rather than by handing Socialite the stateful driver, for one reason that matters: a callback this
 * browser did not start is refused before the authorization code is spent at the provider. Socialite's own check happens inside
 * `user()`, which is also before the token exchange, but it cannot be reached without also letting the provider call happen on the
 * paths that do not carry a state at all.
 *
 * The state is SPENT by reading it, so the same callback cannot be used twice -- which is `pull()`, the same method Socialite uses.
 */
final class OAuthCallbackState
{
    /** Socialite's own session key, written by `AbstractProvider::redirect()`. Nothing here writes it; this class only reads it. */
    private const SESSION_KEY = 'state';

    /** Off as it ships: the callback is handled exactly as it is today, state or no state. */
    public static function isChecked(): bool
    {
        return (bool) config('gcc.identity.oauth_callback_state.checked');
    }

    /**
     * Does this callback carry the state this browser's session is waiting for?
     *
     * An absent session state is a NO, not a pass: it is the state of a browser that never started a sign-in here, and of a second
     * use of a callback whose state has already been spent.
     */
    public static function matchesTheBrowsersSession(Request $request): bool
    {
        $expected = $request->hasSession() ? $request->session()->pull(self::SESSION_KEY) : null;
        $returned = $request->input(self::SESSION_KEY);

        if (!is_string($expected) || $expected === '' || !is_string($returned) || $returned === '') {
            return false;
        }

        return hash_equals($expected, $returned);
    }
}
