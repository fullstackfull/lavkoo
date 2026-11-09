<?php

namespace App\Services\SalesChannels\Trendyol;

use App\Enums\SalesChannels\IntegrationCapability;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\ChannelResponse;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use App\Services\SalesChannels\Support\ChannelHttp;

/**
 * One call to the Trendyol Marketplace Integration API.
 *
 * Every request carries HTTP Basic credentials (API key and secret), the mandatory `User-Agent: {sellerId} - {integration name}` (a request without it is answered
 * 403) and, for the international (Gulf) marketplace, the `storeFrontCode` header (a request without it is processed as the Turkish storefront). There is no
 * token to refresh: a 401 means the key or secret is wrong, and the account is marked so a person fixes it; a 403 is reported with the usual causes.
 */
class TrendyolClient
{
    public function __construct(
        private readonly ChannelHttp $http,
        private readonly ChannelCredentialStore $credentials,
    ) {
    }

    public function baseUrl(SalesChannelAccount $account): string
    {
        $environment = $account->isSandbox() ? 'sandbox' : 'production';

        return rtrim((string) config("sales_channels.trendyol.bases.{$environment}", ''), '/') . '/integration';
    }

    /** @throws ChannelUnavailableException when the account has no usable Seller ID */
    public function sellerId(SalesChannelAccount $account): string
    {
        $sellerId = trim((string) $account->external_account_id);
        if ($sellerId === '' || !ctype_digit($sellerId)) {
            throw new ChannelUnavailableException(ChannelUnavailableException::AUTH_NEEDED, 'Enter the Trendyol Seller ID (the numeric id shown with the API key under Account Information, Integration Information).');
        }

        return $sellerId;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     * @throws ChannelUnavailableException
     * @throws ChannelPermissionDenied
     */
    public function call(SalesChannelAccount $account, string $operation, string $method, string $path, array $query = [], ?array $json = null, ?IntegrationCapability $capability = null, ?string $scope = null, ?array $multipart = null): ChannelResponse
    {
        $url = $this->baseUrl($account) . $path;
        $rehearsal = $this->http->assertAllowed($account, $operation, $method, $capability, $scope, $query);
        // `$multipart` is for the two endpoints Trendyol documents with no other encoding: the return rejection and the invoice
        // file. It carries plain fields only -- no document's bytes ever travel through a parameter a logger would then have to
        // be trusted to leave out.
        // NAMED, NOT POSITIONAL. `ChannelHttp::send()` is shared with every other channel and a new body kind can be added to
        // it between `$scope` and `$multipart`; passing these by position would then hand a multipart body to whatever parameter
        // had taken that slot, and the request would leave with no body at all.
        $response = $this->http->send(
            account: $account,
            operation: $operation,
            method: $method,
            url: $url,
            headers: $this->headers($account, withCredentials: !$rehearsal),
            json: $json,
            query: $query,
            capability: $capability,
            scope: $scope,
            multipart: $multipart,
        );

        return match ($response->status) {
            401 => throw new ChannelUnavailableException(ChannelUnavailableException::AUTH_NEEDED, 'Trendyol rejected the API key or secret (HTTP 401). Copy them again from the Trendyol seller panel (Account Information, Integration Information); stage and production keys differ.'),
            403 => throw new ChannelPermissionDenied($operation, 'Trendyol refused this call (HTTP 403). The usual causes are a missing or wrong User-Agent ("{sellerId} - {integration name}"), an endpoint or parameter that does not exist for this storefront, or this server\'s IP address being blocked: ask Trendyol integration support with the server\'s IP address.'),
            default => $response,
        };
    }

    /** @return array<string, string> */
    public function headers(SalesChannelAccount $account, bool $withCredentials = true): array
    {
        $headers = [
            'User-Agent' => $this->sellerId($account) . ' - ' . $account->setting('integration_name', 'SelfIntegration'),
            'Accept-Language' => (string) $account->setting('accept_language', 'ar'),
        ];
        $storefront = (string) $account->setting('storefront_code', 'SA');
        if ($storefront !== '') {
            $headers['storeFrontCode'] = $storefront;
        }
        if ($withCredentials) {
            $secrets = $this->credentials->get($account);
            if (!$secrets->has('api_key') || !$secrets->has('api_secret')) {
                throw new ChannelUnavailableException(ChannelUnavailableException::AUTH_NEEDED, 'The Trendyol API key and API secret are both needed.');
            }
            $headers['Authorization'] = 'Basic ' . base64_encode($secrets->secret('api_key') . ':' . $secrets->secret('api_secret'));
        }

        return $headers;
    }
}
