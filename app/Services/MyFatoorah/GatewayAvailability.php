<?php

namespace App\Services\MyFatoorah;

use App\Enums\MyFatoorah\GatewayStatus;
use App\Models\Currency;
use Illuminate\Support\Facades\Cache;

/**
 * The single answer to "may MyFatoorah take a payment right now?".
 *
 * Everything that needs the answer asks here: the storefront checkout, the mobile config endpoint, the
 * controller that starts a payment, and the admin panel. One implementation means the mobile app can
 * never be offered a method the server would then refuse — the bug that makes a customer pick a payment
 * method and get an error.
 *
 * Reading a payment's status is NOT gated by this. Verification, webhooks and reconciliation must keep
 * working after the gateway is switched off, or money already taken would never reach an order.
 */
class GatewayAvailability
{
    private const CURRENCY_CACHE_KEY = 'myfatoorah.active_currencies';

    public function __construct(private readonly GatewayConfigRepository $configRepo)
    {
    }

    /**
     * @return array{ready: bool, status: GatewayStatus, reason: string}
     */
    public function check(): array
    {
        $status = $this->configRepo->get()->status(
            environmentEnabled: $this->environmentEnabled(),
            digitalPaymentOn: $this->digitalPaymentOn(),
            activeCurrencies: $this->activeCurrencies(),
        );

        return [
            'ready' => $status->isReady(),
            'status' => $status,
            'reason' => $status->reason(),
        ];
    }

    public function isReady(): bool
    {
        return $this->check()['ready'];
    }

    /** May an outbound call be made at all? False also switches off the admin connection test. */
    public function environmentEnabled(): bool
    {
        return (bool) config('myfatoorah.enabled', false);
    }

    /**
     * May a payment that is already in flight still be verified? Deliberately independent of the kill
     * switch: a customer who paid in the seconds before someone switched the gateway off must still get
     * their order.
     */
    public function verificationAllowed(): bool
    {
        return $this->environmentEnabled() || (bool) config('myfatoorah.verify_when_disabled', true);
    }

    /** The store-wide digital-payment switch that already gates every other gateway. */
    private function digitalPaymentOn(): bool
    {
        try {
            $value = getWebConfig(name: 'digital_payment');
        } catch (\Throwable) {
            return false;
        }

        if (is_array($value)) {
            return (bool) ($value['status'] ?? 0);
        }

        return (bool) $value;
    }

    /**
     * Currency codes switched on in the store, or null if that could not be read.
     *
     * The distinction matters. An EMPTY list is a real answer — the store has no active currency that
     * MyFatoorah can settle in — and must fail the gateway. NULL means the question could not be
     * answered (no currencies table yet, a database problem), and must NOT fail it, because a
     * diagnostic failure should never take a working payment method off the checkout.
     *
     * Cached briefly: checkout and the mobile config endpoint both ask on every request.
     *
     * @return list<string>|null
     */
    private function activeCurrencies(): ?array
    {
        try {
            return Cache::remember(self::CURRENCY_CACHE_KEY, now()->addMinutes(5), static function (): array {
                return Currency::query()
                    ->where('status', 1)
                    ->pluck('code')
                    ->map(static fn ($code) => strtoupper((string) $code))
                    ->filter()
                    ->values()
                    ->all();
            });
        } catch (\Throwable) {
            return null;
        }
    }

    public static function flushCurrencyCache(): void
    {
        Cache::forget(self::CURRENCY_CACHE_KEY);
    }
}
