<?php

namespace App\Services\SalesChannels\Trendyol;

use App\Enums\SalesChannels\IntegrationCapability;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use App\Services\SalesChannels\Support\ChannelOperations;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Registers, lists and switches the order-package webhook at Trendyol. Registering is a WRITE at Trendyol: it needs the webhook-management approval like any other.
 *
 * Trendyol authenticates to us with the API key we choose at registration, sent in `x-api-key`. The key is generated here, stored encrypted with the account's other
 * secrets, and is never shown. Trendyol allows 15 webhooks per seller and refuses an address containing "Trendyol", "Dolap" or "localhost".
 */
class TrendyolWebhookManager
{
    public const STATUSES = ['CREATED', 'PICKING', 'INVOICED', 'SHIPPED', 'CANCELLED', 'DELIVERED', 'UNDELIVERED', 'RETURNED', 'UNSUPPLIED', 'AWAITING', 'UNPACKED', 'AT_COLLECTION_POINT', 'VERIFIED'];

    private const FORBIDDEN_IN_URL = ['trendyol', 'dolap', 'localhost'];

    public function __construct(
        private readonly TrendyolClient $client,
        private readonly ChannelCredentialStore $credentials,
    ) {
    }

    /** @return list<array<string, mixed>> the webhooks Trendyol holds for the seller (the authentication secrets are never returned by Trendyol) */
    public function list(SalesChannelAccount $account): array
    {
        $seller = $this->client->sellerId($account);
        $response = $this->client->call($account, 'trendyol.webhooks_list', 'GET', "/webhook/sellers/{$seller}/webhooks", capability: IntegrationCapability::OrderImport);
        if (!$response->ok()) {
            throw new RuntimeException('Trendyol webhook list failed (HTTP ' . $response->status . '): ' . $response->message());
        }

        return array_values(array_filter((array) $response->json, 'is_array'));
    }

    /**
     * @param list<string> $statuses empty means every status, now and in future
     * @param list<string> $countryCodes empty means every active country
     * @return string|null the webhook id Trendyol gave, or null when nothing was sent (a dry run)
     * @throws InvalidArgumentException
     */
    public function register(SalesChannelAccount $account, string $url, array $statuses = [], array $countryCodes = []): ?string
    {
        $this->assertUsableUrl($url);
        $unknown = array_diff($statuses, self::STATUSES);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown webhook status: ' . implode(', ', $unknown));
        }

        $seller = $this->client->sellerId($account);
        $body = array_filter([
            'url' => $url,
            'authenticationType' => 'API_KEY',
            'apiKey' => $this->webhookKey($account),
            'subscribedStatuses' => $statuses === [] ? null : array_values($statuses),
            'countryCodes' => $countryCodes === [] ? null : array_values($countryCodes),
        ], static fn ($value): bool => $value !== null);

        $response = $this->client->call($account, 'trendyol.webhooks_write', 'POST', "/webhook/sellers/{$seller}/webhooks", json: $body, capability: IntegrationCapability::OrderImport, scope: ChannelOperations::SCOPE_WEBHOOK_MANAGEMENT);
        if ($response->isDryRun()) {
            return null;
        }
        if (!$response->ok()) {
            throw new RuntimeException('Trendyol did not register the webhook (HTTP ' . $response->status . '): ' . $response->message());
        }

        return (string) $response->get('id');
    }

    public function activate(SalesChannelAccount $account, string $webhookId): bool
    {
        return $this->switchState($account, $webhookId, 'activate');
    }

    public function deactivate(SalesChannelAccount $account, string $webhookId): bool
    {
        return $this->switchState($account, $webhookId, 'deactivate');
    }

    public function delete(SalesChannelAccount $account, string $webhookId): bool
    {
        $seller = $this->client->sellerId($account);
        $response = $this->client->call($account, 'trendyol.webhooks_write', 'DELETE', "/webhook/sellers/{$seller}/webhooks/" . rawurlencode($webhookId), capability: IntegrationCapability::OrderImport, scope: ChannelOperations::SCOPE_WEBHOOK_MANAGEMENT);

        return $response->ok();
    }

    private function switchState(SalesChannelAccount $account, string $webhookId, string $action): bool
    {
        $seller = $this->client->sellerId($account);
        $response = $this->client->call($account, 'trendyol.webhooks_write', 'PUT', "/webhook/sellers/{$seller}/webhooks/" . rawurlencode($webhookId) . "/{$action}", capability: IntegrationCapability::OrderImport, scope: ChannelOperations::SCOPE_WEBHOOK_MANAGEMENT);

        return $response->ok();
    }

    private function assertUsableUrl(string $url): void
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new InvalidArgumentException('The webhook address must be an https URL.');
        }
        foreach (self::FORBIDDEN_IN_URL as $word) {
            if (str_contains(strtolower($url), $word)) {
                throw new InvalidArgumentException("Trendyol refuses a webhook address containing '{$word}'.");
            }
        }
    }

    /** The key Trendyol will send back, created once and kept. */
    private function webhookKey(SalesChannelAccount $account): string
    {
        $stored = $this->credentials->get($account)->secret('webhook_api_key');
        if ($stored !== null) {
            return $stored;
        }
        $key = Str::random(48);
        $this->credentials->save($account, ['webhook_api_key' => $key]);

        return $key;
    }
}
