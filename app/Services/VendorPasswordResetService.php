<?php

namespace App\Services;

use App\Contracts\Repositories\PasswordResetRepositoryInterface;
use App\Contracts\Repositories\PhoneOrEmailVerificationRepositoryInterface;
use App\Models\Seller;
use App\Services\Fulfillment\Support\SaudiPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Whose password a reset changes, decided by equality and by nothing else.
 *
 * A proof is issued to one identity: the code texted to a seller's phone, the link mailed to their address, or a phone number Google has
 * verified. Redeeming it changes the one seller that identity names. The identity is never matched with LIKE, because `%` is a legal
 * identity and matching it that way made one proof good for every seller, and a seller who cannot be told from another by the identity
 * alone (two sellers hold it) is not reset at all.
 *
 * Only the server issues a proof. Whatever a caller stores through firebase-auth-token-store, or through the customer endpoint of the same
 * name that writes the same table, is no more than a note that a session exists: the proof is written by firebase-auth-verify once Google
 * has accepted the code and has said the session belongs to the seller's own phone.
 */
class VendorPasswordResetService
{
    public const EMAIL = 'email';

    public const PHONE = 'phone';

    private const USER_TYPE = 'seller';

    /** A phone Google has verified is good for the time it takes to choose a new password, not for ever. */
    private const VERIFIED_SESSION_MINUTES = 30;

    public function __construct(
        private readonly PasswordResetRepositoryInterface            $passwordResetRepo,
        private readonly PhoneOrEmailVerificationRepositoryInterface $phoneOrEmailVerificationRepo,
        private readonly PasswordResetService                        $passwordResetService,
    )
    {
    }

    public function findSeller(string $identity, ?string $method): ?Seller
    {
        $column = match ($method) {
            self::EMAIL => 'email',
            self::PHONE => 'phone',
            default => null,
        };
        if ($column === null) {
            return null;
        }

        $sellers = Seller::where($column, $identity)->limit(2)->get();

        return $sellers->count() === 1 ? $sellers->first() : null;
    }

    public function findSellerHoldingCode(string $identity, string $code, ?string $method): ?Seller
    {
        $issued = $this->passwordResetRepo->getFirstWhere(params: ['user_type' => self::USER_TYPE, 'identity' => $identity, 'token' => $code]);

        return $issued ? $this->findSeller(identity: $issued['identity'], method: $method) : null;
    }

    public function findSellerWithVerifiedSession(string $phone, string $session): ?Seller
    {
        $verified = $this->passwordResetRepo->getFirstWhere(params: ['user_type' => self::USER_TYPE, 'identity' => $phone, 'token' => $this->proofOfSession($session)]);

        return $verified?->created_at?->greaterThan(now()->subMinutes(self::VERIFIED_SESSION_MINUTES)) ? $this->findSeller(identity: $verified['identity'], method: self::PHONE) : null;
    }

    /**
     * Keeps the note firebase-auth-verify counts its attempts on, and nothing the caller sent: the table is shared with the customer
     * sign-in by code, so a token kept here for a phone that a customer also holds would be a code for that customer, and a row that
     * already exists (a code the server sent) is left as it is.
     */
    public function noteSessionRequested(string $phone): void
    {
        $this->phoneOrEmailVerificationRepo->updateOrCreate(params: ['phone_or_email' => $phone], value: []);
    }

    public function acceptSession(Seller $seller, string $session, string $verifiedPhone): bool
    {
        if (!SaudiPhone::isSameNumber(first: $verifiedPhone, second: (string)$seller['phone'])) {
            return false;
        }

        $this->passwordResetRepo->delete(params: ['user_type' => self::USER_TYPE, 'identity' => $seller['phone']]);
        $this->passwordResetRepo->add($this->passwordResetService->getAddData(
            identity: $seller['phone'],
            token: $this->proofOfSession($session),
            userType: self::USER_TYPE,
        ));

        return true;
    }

    /**
     * Changes the password of the one seller the proof was redeemed for, and spends every proof issued to this identity.
     *
     * `$endSessions` signs the seller out everywhere: someone who resets because they believe they were compromised must not leave the
     * attacker signed in, and a password that changed while the bearer token did not left the stolen token working.
     */
    public function resetPassword(Seller $seller, string $identity, string $proof, string $password, bool $endSessions = false): void
    {
        $changes = ['password' => bcrypt(str_replace(' ', '', $password))];
        if ($endSessions) {
            $changes['auth_token'] = Str::random(50);
        }

        DB::transaction(function () use ($seller, $identity, $proof, $changes): void {
            Seller::whereKey($seller->getKey())->update($changes);
            $this->passwordResetRepo->delete(params: ['user_type' => self::USER_TYPE, 'identity' => $identity]);
            $this->phoneOrEmailVerificationRepo->delete(params: ['phone_or_email' => $identity, 'token' => $proof]);
            $this->phoneOrEmailVerificationRepo->delete(params: ['phone_or_email' => $identity, 'token' => null]);
        });
    }

    /**
     * What stands for a verified Firebase session in password_resets: a keyed digest, so that the session id itself is not stored (it can be
     * longer than the column) and a row written by anyone who does not hold the application key is never mistaken for one.
     */
    private function proofOfSession(string $session): string
    {
        return hash_hmac('sha256', $session, (string)config('app.key'));
    }

}
