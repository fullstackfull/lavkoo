<?php

namespace App\Services\SalesChannels\Amazon;

use App\Enums\SalesChannels\ConnectionStatus;
use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\Support\SecretRedactor;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use App\Services\SalesChannels\Support\ChannelHttp;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Login with Amazon: turns the seller's long-lived refresh token into the one-hour access token every Selling Partner API call carries.
 *
 * The token is kept (encrypted) with its expiry and reused until shortly before it ends, so a run of calls makes one sign-in. A refresh token Amazon
 * no longer honours (`invalid_grant`: the seller revoked the authorisation, or it lapsed after 365 days) or a client secret it rejects (`invalid_client`:
 * the 180-day rotation was missed) marks the account so a person re-authorises it, and everything waits instead of retrying a refusal.
 */
class AmazonLwaAuth
{
    public function __construct(
        private readonly ChannelHttp $http,
        private readonly ChannelCredentialStore $credentials,
        private readonly ChannelAccountService $accounts,
    ) {
    }

    /** @throws ChannelUnavailableException when the credentials are missing or Amazon refused them */
    public function accessToken(SalesChannelAccount $account, bool $forceRefresh = false): string
    {
        $secrets = $this->credentials->get($account);
        if (!$forceRefresh) {
            $held = $secrets->secret('access_token');
            $expiresAt = $secrets->secret('access_token_expires_at');
            if ($held !== null && $expiresAt !== null && CarbonImmutable::parse($expiresAt)->gt(now()->addSeconds((int) config('sales_channels.amazon.token_refresh_margin_seconds', 300)))) {
                return $held;
            }
        }

        foreach (['lwa_client_id', 'lwa_client_secret', 'refresh_token'] as $required) {
            if (!$secrets->has($required)) {
                throw new ChannelUnavailableException(ChannelUnavailableException::AUTH_NEEDED, 'The Amazon credentials are incomplete: the client id, the client secret and the refresh token are all needed.');
            }
        }

        $response = $this->http->send(
            account: $account,
            operation: 'amazon.lwa_token',
            method: 'POST',
            url: (string) config('sales_channels.amazon.lwa_url'),
            form: [
                'grant_type' => 'refresh_token',
                'refresh_token' => $secrets->secret('refresh_token'),
                'client_id' => $secrets->secret('lwa_client_id'),
                'client_secret' => $secrets->secret('lwa_client_secret'),
            ],
        );

        if (!$response->ok()) {
            $this->refused($account, (string) $response->get('error', ''), $response->message(), $response->status);
        }

        $token = $response->get('access_token');
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Amazon answered the sign-in without an access token.');
        }
        $lifetime = max(60, (int) $response->get('expires_in', 3600));
        $this->credentials->putRuntime($account, 'access_token', $token);
        $this->credentials->putRuntime($account, 'access_token_expires_at', now()->addSeconds($lifetime)->toIso8601String());

        return $token;
    }

    /** @return never */
    private function refused(SalesChannelAccount $account, string $error, string $message, ?int $status): void
    {
        $detail = SecretRedactor::redactString($message);
        if ($error === 'invalid_grant') {
            $this->accounts->markAuthorisationLost($account, 'Amazon no longer honours the refresh token (revoked or expired). Authorise the application again in Seller Central. ' . $detail, ConnectionStatus::Revoked);
            throw new ChannelUnavailableException(ChannelUnavailableException::AUTH_NEEDED, 'Amazon refused the refresh token: the seller must authorise the application again.');
        }
        if ($error === 'invalid_client' || $error === 'unauthorized_client') {
            $this->accounts->markAuthorisationLost($account, 'Amazon rejected the application credentials (the client secret may have been rotated or expired). ' . $detail, ConnectionStatus::AuthExpired);
            throw new ChannelUnavailableException(ChannelUnavailableException::AUTH_NEEDED, 'Amazon rejected the application credentials: update the client id and secret.');
        }

        throw new RuntimeException("Amazon sign-in failed (HTTP {$status}): {$detail}");
    }
}
