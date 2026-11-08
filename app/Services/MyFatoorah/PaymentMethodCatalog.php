<?php

namespace App\Services\MyFatoorah;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Which payment methods does this merchant account actually have enabled?
 *
 * mada, Visa, Mastercard, Apple Pay, STC Pay and Google Pay are each enabled per account by MyFatoorah,
 * so a hard-coded list — or worse, the ids from their documentation's examples — would offer customers
 * methods the account cannot take and hide ones it can. `GET /v3/payment-methods` is asked instead, and
 * the answer is cached in the gateway settings so a checkout never waits on it.
 *
 * The identifier used when creating a payment is `ApiName`, which is what MyFatoorah documents for that
 * purpose. `Name` is for display only.
 */
class PaymentMethodCatalog
{
    public function __construct(
        private readonly GatewayConfigRepository $configRepo,
        private readonly MyFatoorahClient $client,
    ) {
    }

    /**
     * Ask MyFatoorah and cache the answer.
     *
     * @return array{ok: bool, methods: list<array<string,mixed>>, error?: string}
     */
    public function refresh(?string $mode = null): array
    {
        $config = $mode !== null ? $this->configRepo->forMode($mode) : $this->configRepo->get();

        $response = $this->client->get($config, 'payment_methods');

        if (!$response->succeeded) {
            return ['ok' => false, 'methods' => [], 'error' => $response->message];
        }

        $raw = $response->get('PaymentMethods', []);

        if (!is_array($raw)) {
            return ['ok' => false, 'methods' => [], 'error' => 'MyFatoorah returned no payment methods.'];
        }

        $methods = [];

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $apiName = trim((string) ($entry['ApiName'] ?? ''));
            $name = trim((string) ($entry['Name'] ?? ''));

            if ($apiName === '' && $name === '') {
                continue;
            }

            $methods[] = [
                'api_name' => $apiName !== '' ? $apiName : $name,
                'name' => $name !== '' ? $name : $apiName,
                'currency' => strtoupper((string) ($entry['CurrencyIso'] ?? '')),
                // MyFatoorah returns these as the strings "Yes"/"No" in their examples and declares them
                // as strings in the schema, so both that and a real boolean are accepted.
                'direct' => $this->yes($entry['IsDirectPayment'] ?? null),
                'embedded' => $this->yes($entry['IsEmbeddedSupported'] ?? null),
                'logo_url' => $this->safeLogo($entry['LogoUrl'] ?? null),
                'commission' => Str::limit((string) ($entry['CommissionPercentage'] ?? ''), 16, ''),
            ];
        }

        $this->configRepo->storeAccountMethods($methods);

        Log::info('myfatoorah.payment_methods_refreshed', ['count' => count($methods), 'mode' => $config->mode]);

        return ['ok' => true, 'methods' => $methods];
    }

    /** The cached catalogue, for the admin panel. Never calls out. */
    public function cached(): array
    {
        return $this->configRepo->get()->accountMethods;
    }

    private function yes(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['yes', 'true', '1'], true);
    }

    /** Only an https URL on a MyFatoorah host is kept, so the admin page cannot be made to load anything else. */
    private function safeLogo(mixed $value): ?string
    {
        if (!is_string($value) || !str_starts_with($value, 'https://')) {
            return null;
        }

        $host = strtolower((string) parse_url($value, PHP_URL_HOST));

        return preg_match('/(^|\.)myfatoorah\.com$/', $host) === 1 ? $value : null;
    }
}
