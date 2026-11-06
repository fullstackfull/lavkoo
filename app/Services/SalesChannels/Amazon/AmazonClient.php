<?php

namespace App\Services\SalesChannels\Amazon;

use App\Enums\SalesChannels\IntegrationCapability;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\ChannelResponse;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Support\ChannelHttp;

/**
 * One call to the Selling Partner API: the regional endpoint, the mandatory User-Agent, the sign-in, and the one retry an expired token deserves.
 *
 * No AWS request signing is involved: since the 2023 change the only credential is the LWA access token in `x-amz-access-token`.
 * A 403 is first taken to be an expired token and answered with a fresh sign-in and one more try; a second 403 means this authorisation lacks a role
 * for that call, and is reported as such, not retried.
 */
class AmazonClient
{
    public function __construct(
        private readonly ChannelHttp $http,
        private readonly AmazonLwaAuth $auth,
    ) {
    }

    public function baseUrl(SalesChannelAccount $account): string
    {
        $environment = $account->isSandbox() ? 'sandbox' : 'production';
        $region = (string) $account->setting('region', 'eu');

        return rtrim((string) config("sales_channels.amazon.endpoints.{$environment}.{$region}", ''), '/');
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     * @throws ChannelPermissionDenied
     */
    public function call(SalesChannelAccount $account, string $operation, string $method, string $path, array $query = [], ?array $json = null, ?IntegrationCapability $capability = null, ?string $scope = null): ChannelResponse
    {
        $url = $this->baseUrl($account) . $path;
        $rehearsal = $this->http->assertAllowed($account, $operation, $method, $capability, $scope, $query);
        if ($rehearsal) {
            return $this->http->send($account, $operation, $method, $url, $this->headers(''), $json, $query, null, $capability, $scope);
        }

        $response = $this->http->send($account, $operation, $method, $url, $this->headers($this->auth->accessToken($account)), $json, $query, null, $capability, $scope);
        if ($response->kind !== ChannelResponse::AUTH_ERROR) {
            return $response;
        }

        // Most likely the token expired between two calls: sign in again and try once more. A refused request was not processed, so repeating it is safe.
        $response = $this->http->send($account, $operation, $method, $url, $this->headers($this->auth->accessToken($account, forceRefresh: true)), $json, $query, null, $capability, $scope);
        if ($response->kind === ChannelResponse::AUTH_ERROR) {
            throw new ChannelPermissionDenied($operation, 'Amazon refused this call (' . $response->message() . '). The authorisation probably lacks the role this operation needs; add the role to the application and authorise it again.');
        }

        return $response;
    }

    /** @return array<string, string> */
    public function headers(string $accessToken): array
    {
        $agent = (array) config('sales_channels.amazon.user_agent', []);

        return array_filter([
            'x-amz-access-token' => $accessToken,
            'x-amz-date' => gmdate('Ymd\THis\Z'),
            'user-agent' => sprintf('%s/%s (Language=PHP/%s; Platform=%s)', $agent['application'] ?? 'PharmacyChannelHub', $agent['version'] ?? '1.0', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, PHP_OS_FAMILY),
        ], static fn (string $value): bool => $value !== '');
    }
}
