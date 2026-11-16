<?php

namespace App\Services;

use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Who may answer "is it you?" when a social login finds an account that already holds the provider's email.
 *
 * Only the person the provider has just vouched for may answer, so the question comes with a temporary token that nobody else is given: it
 * is issued by social-customer-login (as `temporary_token`) once the provider has returned the email, it names one account, one email and
 * one provider, and it is good for one answer within a few minutes. existing-account-check takes it back under the same name. What a
 * caller merely says (an email address, a provider name) is never enough.
 */
class SocialLoginConfirmationService
{
    private const LIFETIME_MINUTES = 10;

    public function __construct(private readonly CustomerRepositoryInterface $customerRepo)
    {
    }

    public function issue(User $customer, string $medium): string
    {
        $temporaryToken = Str::random(40);

        Cache::put(
            key: $this->cacheKey(temporaryToken: $temporaryToken),
            value: ['customer_id' => $customer['id'], 'email' => $customer['email'], 'medium' => $medium],
            ttl: now()->addMinutes(value: self::LIFETIME_MINUTES),
        );

        return $temporaryToken;
    }

    /** The account the token was issued for, or null when the token is unknown, spent, expired or was issued for another email or provider. */
    public function redeem(string $temporaryToken, string $email, string $medium): ?User
    {
        $confirmation = Cache::pull($this->cacheKey(temporaryToken: $temporaryToken));

        if (!$confirmation || $confirmation['medium'] !== $medium || strcasecmp($confirmation['email'], $email) !== 0) {
            return null;
        }

        return $this->customerRepo->getFirstWhere(params: ['id' => $confirmation['customer_id'], 'email' => $confirmation['email']]);
    }

    private function cacheKey(string $temporaryToken): string
    {
        return 'social_login_confirmation_' . hash('sha256', $temporaryToken);
    }
}
