<?php

namespace App\Services;

use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\PasswordResetRepositoryInterface;
use App\Contracts\Repositories\PhoneOrEmailVerificationRepositoryInterface;
use App\Models\User;
use App\Services\Fulfillment\Support\SaudiPhone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Whose password a reset changes, decided by equality and by nothing else.
 *
 * A proof is issued to one identity: the code texted to a customer's phone, the link mailed to their address, or a phone number Google has
 * verified. Redeeming it changes the one customer that identity names. The identity is never matched with LIKE, because `%` is a legal
 * identity and matching it that way made one proof good for every customer, and a customer who cannot be told from another by the identity
 * alone (two customers hold it) is not reset at all.
 *
 * Only the server issues a proof, and it keeps it in password_resets. phone_or_email_verifications, the table of the codes that sign
 * a customer in or confirm a phone, is written by firebase-auth-token-store for anybody, so nothing read from it is ever a reset proof: the
 * store keeps a note that a session was asked for and the proof is written once Google has accepted the code and has said the session
 * belongs to the customer's own phone.
 */
class CustomerPasswordResetService
{
    public const PHONE = 'phone';

    public const EMAIL = 'email';

    private const USER_TYPE = 'customer';

    public function __construct(
        private readonly CustomerRepositoryInterface                 $customerRepo,
        private readonly PasswordResetRepositoryInterface            $passwordResetRepo,
        private readonly PhoneOrEmailVerificationRepositoryInterface $phoneOrEmailVerificationRepo,
    )
    {
    }

    /** The customer holding $identity as their phone or, when $kind does not say which, as their phone or their e-mail; none when two hold it. */
    public function findCustomer(string $identity, ?string $kind = null): ?User
    {
        if ($identity === '') {
            return null;
        }

        $columns = match ($kind) {
            self::PHONE => ['phone'],
            self::EMAIL => ['email'],
            null => ['phone', 'email'],
        };
        $customers = User::where(function (Builder $query) use ($columns, $identity): void {
            foreach ($columns as $column) {
                $query->orWhere($column, $identity);
            }
        })->limit(2)->get();

        return $customers->count() === 1 ? $customers->first() : null;
    }

    /** Records the code the server has just sent (or mailed) to the phone or e-mail of $customer, in place of the one sent before. */
    public function issueCode(User $customer, string $kind, string|int $code): void
    {
        $this->passwordResetRepo->updateOrCreate(
            params: ['user_type' => self::USER_TYPE, 'identity' => $customer[$kind]],
            value: ['token' => (string)$code, 'created_at' => now()],
        );
    }

    /** The customer a proof was issued to, when $code is what was issued to $identity: the code, the mailed token or the verified session. */
    public function findCustomerHoldingCode(string $identity, string $code): ?User
    {
        if ($identity === '' || $code === '') {
            return null;
        }

        $issued = collect([$code, $this->digestOf($code)])->contains(
            fn (string $token): bool => $this->passwordResetRepo->getFirstWhere(params: ['user_type' => self::USER_TYPE, 'identity' => $identity, 'token' => $token]) !== null
        );

        return $issued ? $this->findCustomer(identity: $identity) : null;
    }

    /**
     * Records that Google has verified $proof (the code the customer typed, or the Firebase session), if the phone it says the code belongs to
     * is the phone of $customer. The proof is kept as a keyed digest and is redeemed by presenting $proof itself.
     */
    public function acceptVerifiedProof(User $customer, string $proof, string $verifiedPhone): bool
    {
        if (!SaudiPhone::isSameNumber(first: $verifiedPhone, second: (string)$customer['phone'])) {
            return false;
        }

        $this->passwordResetRepo->updateOrCreate(
            params: ['user_type' => self::USER_TYPE, 'identity' => $customer['phone']],
            value: ['token' => $this->digestOf($proof), 'created_at' => now()],
        );

        return true;
    }

    /** The app tells the server it has asked Google for a session. Only the fact is kept: it opens the row firebase-auth-verify counts attempts on. */
    public function noteFirebaseSessionRequested(string $phone): void
    {
        $this->phoneOrEmailVerificationRepo->updateOrCreate(params: ['phone_or_email' => $phone], value: []);
    }

    /**
     * Changes the password of $customer, by id, spends every proof issued to them and ends their app sessions: someone who resets because
     * they believe they were compromised must not leave the attacker signed in.
     *
     * @param array<string, mixed> $alsoSet what else the page that reset the password has always set on the customer
     */
    public function resetPassword(User $customer, string $password, array $alsoSet = []): void
    {
        $identities = array_filter([$customer['phone'], $customer['email']], static fn (?string $identity): bool => $identity !== null && $identity !== '');

        DB::transaction(function () use ($customer, $password, $alsoSet, $identities): void {
            $this->customerRepo->updateWhere(
                params: ['id' => $customer->getKey()],
                data: array_merge(['password' => bcrypt(str_replace(' ', '', $password))], $alsoSet),
            );
            foreach ($identities as $identity) {
                $this->passwordResetRepo->delete(params: ['user_type' => self::USER_TYPE, 'identity' => $identity]);
                $this->phoneOrEmailVerificationRepo->delete(params: ['phone_or_email' => $identity]);
            }
            $this->customerRepo->deleteAuthAccessTokens(id: $customer->getKey());
        });
    }

    /**
     * What stands for a code Google has verified in password_resets: a keyed digest, so that a Firebase session is not stored (it can be
     * longer than the column) and a row written by anyone who does not hold the application key is never mistaken for one.
     */
    private function digestOf(string $proof): string
    {
        return hash_hmac('sha256', $proof, (string)config('app.key'));
    }
}
