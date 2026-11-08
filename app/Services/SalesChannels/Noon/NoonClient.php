<?php

namespace App\Services\SalesChannels\Noon;

use App\Enums\SalesChannels\IntegrationCapability;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\ChannelResponse;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use App\Services\SalesChannels\Support\ChannelHttp;
use Carbon\CarbonImmutable;

/**
 * One call to noon's partner API, and the sign-in that every other call depends on.
 *
 * The sign-in is the one part of noon's API whose request IS documented, so it is the one part this class can implement with confidence:
 *
 *  1. a JWT is signed RS256 with the service account's private key, carrying `sub` (the key id), `iat` (now, in seconds) and `jti` (a fresh uuid);
 *  2. it is POSTed with the project code to `/identity/public/v1/api/login`, **on the production host even for a sandbox account** — the documentation is explicit that only
 *     the other calls move to the sandbox, and a sandbox-issued session would be one production does not know;
 *  3. noon answers with `Set-Cookie`, and that cookie is what authenticates everything afterwards, for up to thirty days.
 *
 * THE COOKIE IS NOT NAMED in the documentation. So the whole cookie string is kept and replayed rather than one value picked out by a name we would have had to guess; it
 * costs nothing and it cannot be wrong. It is a runtime secret: it lives in the account's encrypted credentials, never in a column, a log or a cache.
 *
 * A JWT is never reused. noon rejects one more than five minutes from its clock, and the documentation says to make a new one per attempt, so there is nothing to gain by
 * holding on to it and a stale clock to lose by.
 */
class NoonClient
{
    public function __construct(
        private readonly ChannelHttp $http,
        private readonly ChannelCredentialStore $credentials,
    ) {
    }

    /** Where this account's ordinary calls go: the sandbox host for a sandbox account, production otherwise. */
    public function baseUrl(SalesChannelAccount $account): string
    {
        $environment = $account->isSandbox() ? 'sandbox' : 'production';

        return rtrim((string) config("sales_channels.noon.bases.{$environment}", ''), '/');
    }

    /** Where the SIGN-IN goes, which is production whatever the account is. */
    public function loginUrl(): string
    {
        return rtrim((string) config('sales_channels.noon.bases.production', ''), '/') . NoonOperations::paths()['noon.login'];
    }

    /**
     * One authenticated call. The session is signed in for if there is none, or if it is near its end.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     * @throws ChannelUnavailableException
     * @throws ChannelPermissionDenied
     */
    public function call(SalesChannelAccount $account, string $operation, string $method, string $path, array $query = [], ?array $json = null, ?IntegrationCapability $capability = null, ?string $scope = null): ChannelResponse
    {
        $url = $this->baseUrl($account) . $path;
        if ($this->http->assertAllowed($account, $operation, $method, $capability, $scope, $query)) {
            // A rehearsal: nothing is signed in for and nothing leaves the host.
            return $this->http->send($account, $operation, $method, $url, $this->headers(null), $json, $query, null, $capability, $scope);
        }

        $response = $this->http->send($account, $operation, $method, $url, $this->headers($this->session($account)), $json, $query, null, $capability, $scope);
        if ($response->kind !== ChannelResponse::AUTH_ERROR) {
            return $response;
        }

        // Most likely the session lapsed between two calls. Sign in once more and try again; a refused request was not processed, so repeating it is safe.
        $response = $this->http->send($account, $operation, $method, $url, $this->headers($this->session($account, forceRefresh: true)), $json, $query, null, $capability, $scope);
        if ($response->kind === ChannelResponse::AUTH_ERROR) {
            throw new ChannelPermissionDenied($operation, 'noon refused this call after a fresh sign-in (' . $response->message() . '). The service account probably lacks the role this call needs, or its key has expired or is not allowed from this address; check it in noon\'s Access app.');
        }

        return $response;
    }

    /**
     * The session cookie to send, signing in if there is none or it is nearly spent.
     *
     * @throws ChannelUnavailableException when the account has no usable key file
     */
    public function session(SalesChannelAccount $account, bool $forceRefresh = false): string
    {
        $credentials = $this->credentials->get($account);
        $cookie = (string) ($credentials->secret('session_cookie') ?? '');
        $expires = $credentials->secret('session_expires_at');
        $margin = max(1, (int) config('sales_channels.noon.session_refresh_margin_hours', 24));
        $usable = $cookie !== '' && $expires !== null && CarbonImmutable::parse((string) $expires)->gt(now()->addHours($margin));

        if (!$forceRefresh && $usable) {
            return $cookie;
        }

        return $this->signIn($account);
    }

    /** @throws ChannelUnavailableException */
    private function signIn(SalesChannelAccount $account): string
    {
        $credentials = $this->credentials->get($account);
        $keyId = trim((string) ($credentials->secret('key_id') ?? ''));
        $privateKey = trim((string) ($credentials->secret('private_key') ?? ''));
        $projectCode = trim((string) ($credentials->secret('project_code') ?? ''));

        if ($keyId === '' || $privateKey === '' || $projectCode === '') {
            throw new ChannelUnavailableException(
                ChannelUnavailableException::AUTH_NEEDED,
                'This noon account has no key file yet. Create a service account in noon\'s Access app, download the JSON key once, and enter its key_id, private_key and project_code here.',
            );
        }

        $response = $this->http->send(
            $account,
            'noon.login',
            'POST',
            $this->loginUrl(),
            ['user-agent' => $this->userAgent()],
            ['token' => $this->jwt($keyId, $privateKey), 'default_project_code' => $projectCode],
            [],
            null,
            null,
            null,
        );

        if (!$response->ok()) {
            throw new ChannelUnavailableException(
                ChannelUnavailableException::AUTH_NEEDED,
                'noon refused the sign-in (HTTP ' . $response->status . '). The usual causes are a key that has expired or been revoked, a project code that is not the key\'s own, or this server\'s address not being on the key\'s allow-list.',
            );
        }

        $cookie = $this->cookieFrom($response);
        if ($cookie === '') {
            throw new ChannelUnavailableException(
                ChannelUnavailableException::AUTH_NEEDED,
                'noon accepted the sign-in but set no cookie, so there is nothing to authenticate the next call with. Nothing was sent anywhere else.',
            );
        }

        // Thirty days is what the documentation states; it is stored rather than assumed at use time so that a shorter session noon starts handing out would show up here.
        $this->credentials->putRuntime($account, 'session_cookie', $cookie);
        $this->credentials->putRuntime($account, 'session_expires_at', now()->addDays(30)->toIso8601String());

        return $cookie;
    }

    /**
     * The JWT noon's sign-in takes: RS256 over the documented three claims.
     *
     * @throws ChannelUnavailableException when the stored private key is not one OpenSSL will sign with
     */
    private function jwt(string $keyId, string $privateKey): string
    {
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode([
            'sub' => $keyId,
            'iat' => now()->getTimestamp(),
            // A fresh id per attempt: the documentation says to make a new token each time, and a repeated jti is the thing a server would refuse.
            'jti' => (string) \Illuminate\Support\Str::uuid(),
        ], JSON_THROW_ON_ERROR));

        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new ChannelUnavailableException(
                ChannelUnavailableException::AUTH_NEEDED,
                'The private key stored for this noon account is not a key OpenSSL can read. Paste the `private_key` from noon\'s JSON key file exactly as it is, including its BEGIN and END lines.',
            );
        }

        $signature = '';
        if (!openssl_sign($header . '.' . $claims, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new ChannelUnavailableException(ChannelUnavailableException::AUTH_NEEDED, 'The private key stored for this noon account could not sign the sign-in token.');
        }

        return $header . '.' . $claims . '.' . $this->base64Url($signature);
    }

    /**
     * Every cookie noon set, as one `Cookie` header value.
     *
     * The documentation never names the cookie, so none is singled out: each `Set-Cookie` contributes its `name=value` and its attributes are dropped, which is what a client
     * sends back.
     */
    private function cookieFrom(ChannelResponse $response): string
    {
        $pairs = [];
        foreach ($this->setCookieHeaders($response) as $header) {
            $pair = trim(explode(';', $header, 2)[0]);
            if ($pair !== '' && str_contains($pair, '=')) {
                [$name] = explode('=', $pair, 2);
                $pairs[trim($name)] = $pair;
            }
        }

        return implode('; ', $pairs);
    }

    /** @return list<string> */
    private function setCookieHeaders(ChannelResponse $response): array
    {
        $raw = $response->headers['set-cookie'] ?? null;

        return match (true) {
            is_array($raw) => array_values(array_map('strval', $raw)),
            is_string($raw) && $raw !== '' => [$raw],
            default => [],
        };
    }

    /** @return array<string, string> */
    private function headers(?string $cookie): array
    {
        return array_filter([
            // noon answers 400 to a request without one.
            'user-agent' => $this->userAgent(),
            'cookie' => $cookie,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    private function userAgent(): string
    {
        $agent = (array) config('sales_channels.noon.user_agent', []);

        return ($agent['application'] ?? 'PharmacyChannelHub') . '/' . ($agent['version'] ?? '1.0');
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
