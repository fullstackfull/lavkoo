<?php

namespace App\Services\Fulfillment\Support;

use App\Models\ProviderSyncState;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps the four operational timestamps per provider capability that the Integration Center shows:
 * last received update, last success, last failure, last reconciliation. Bookkeeping only — it never
 * throws into the work it describes.
 */
final class ProviderSyncRecorder
{
    public const CONNECTION = 'connection';
    public const PRODUCTS = 'products';
    public const INVENTORY = 'inventory';
    public const ORDERS = 'orders';
    public const WEBHOOKS = 'webhooks';
    public const CITIES = 'cities';
    public const RECONCILIATION = 'reconciliation';

    public function attempted(string $provider, string $capability): void
    {
        $this->touch($provider, $capability, ['last_attempt_at' => now()]);
    }

    /** @param array<string, mixed>|null $stats */
    public function succeeded(string $provider, string $capability, ?array $stats = null): void
    {
        $values = ['last_attempt_at' => now(), 'last_success_at' => now(), 'last_error' => null];
        if ($stats !== null) {
            $values['stats'] = $stats;
        }
        $this->touch($provider, $capability, $values);
    }

    public function failed(string $provider, string $capability, string $message): void
    {
        $this->touch($provider, $capability, [
            'last_attempt_at' => now(),
            'last_failure_at' => now(),
            'last_error' => SecretRedactor::truncate(SecretRedactor::redactString($message), 1000),
        ]);
    }

    public function received(string $provider, string $capability): void
    {
        $this->touch($provider, $capability, ['last_received_at' => now()]);
    }

    public function reconciled(string $provider, string $capability = self::RECONCILIATION): void
    {
        $this->touch($provider, $capability, ['last_reconciled_at' => now()]);
    }

    /** @param array<string, mixed> $values */
    private function touch(string $provider, string $capability, array $values): void
    {
        try {
            ProviderSyncState::updateOrCreate(['provider' => $provider, 'capability' => $capability], $values);
        } catch (Throwable $exception) {
            Log::warning('Provider sync state not recorded', [
                'provider' => $provider,
                'capability' => $capability,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
