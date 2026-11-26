<?php

namespace App\Services\Identity;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * What proves that the e-mail or the phone an account is about to be created for belongs to the person creating it (A2-04).
 *
 * Two REST endpoints create a customer account with no proof at all. `registration-with-otp` takes a phone number, writes the row with
 * `is_phone_verified = 1` and hands back an access token; `registration-with-social-media` takes an e-mail, stamps `email_verified_at`
 * and calls the account a social one. Both are open endpoints, so anybody could claim an address or a number that is not theirs --
 * before its owner gets there, which is the pre-claim this requirement is about, and with a verified flag nobody earned.
 *
 * The proof is not missing from the flow, only from the server's memory of it: the call BEFORE each registration is the one that proves
 * the identity -- `verify-otp` matched a one-time code against the phone, `social-customer-login` asked Google, Facebook or Apple about
 * the e-mail -- and each already answers with a throwaway 40-character token the client sends back and nobody checks. This class makes
 * that token the proof: it names one e-mail or one phone and one way of proving it, it lasts ten minutes, and redeeming it spends it.
 *
 * The response shape does not change: the token is still a 40-character random string under the key it already had, so issuing the proof
 * needs no app release. Requiring it does, which is why {@see self::isRequired()} is a switch and ships off.
 */
class RegistrationProof
{
    private const LIFETIME_MINUTES = 10;

    /** A one-time code sent to the phone was matched (`verify-otp`). */
    public const ONE_TIME_CODE = 'one_time_code';

    /** An OAuth provider answered for the e-mail with its own access token (`social-customer-login`). */
    public const PROVIDER = 'provider';

    /** The two keys the two issuing endpoints already answer under; a client sends back whichever it was given. */
    private const REQUEST_KEYS = ['temporary_token', 'temp_token'];

    /** Off as it ships: both registration endpoints behave exactly as they do today and no proof is asked for. */
    public static function isRequired(): bool
    {
        return (bool) config('gcc.identity.registration_proof.required');
    }

    /** The token the client was given by whichever endpoint proved the identity, under either of the names those endpoints use. */
    public static function sentWith(Request $request): string
    {
        foreach (self::REQUEST_KEYS as $key) {
            $sent = $request->input($key);
            if (is_string($sent) && $sent !== '') {
                return $sent;
            }
        }

        return '';
    }

    public function issue(string $proved, string $how): string
    {
        $token = Str::random(40);

        Cache::put(
            key: $this->cacheKey(token: $token),
            value: ['proved' => $this->canonical(value: $proved), 'how' => $how],
            ttl: now()->addMinutes(value: self::LIFETIME_MINUTES),
        );

        return $token;
    }

    /**
     * Was this e-mail or phone proved this way, by this token? The token is spent whether the answer is yes or no, so a wrong guess
     * cannot be tried again with a different address.
     *
     * An e-mail is compared without regard to case, which is how every other comparison of an address in this flow is made; a phone is
     * compared as it was given, because the client sends the same field it has just had a code sent to.
     */
    public function redeem(string $token, string $proved, string $how): bool
    {
        if ($token === '') {
            return false;
        }

        $proof = Cache::pull($this->cacheKey(token: $token));

        return is_array($proof)
            && ($proof['how'] ?? null) === $how
            && ($proof['proved'] ?? null) === $this->canonical(value: $proved);
    }

    private function canonical(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function cacheKey(string $token): string
    {
        return 'registration_proof_' . hash('sha256', $token);
    }
}
