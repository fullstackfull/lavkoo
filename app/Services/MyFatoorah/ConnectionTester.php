<?php

namespace App\Services\MyFatoorah;

/**
 * The admin panel's "test connection" button.
 *
 * Deliberately a READ: `GET /v3/payment-methods` proves the API key is valid, is for the right country
 * and has permission, and it returns the catalogue of methods the account can actually take — which is
 * the other thing an operator wants to see. Creating a test invoice would prove the same thing while
 * leaving litter in the merchant's dashboard, and on a live account it would be a real invoice.
 *
 * The result is stored per mode, so testing the sandbox key never overwrites what we know about live.
 */
class ConnectionTester
{
    public function __construct(
        private readonly GatewayConfigRepository $configRepo,
        private readonly PaymentMethodCatalog $catalog,
        private readonly GatewayAvailability $availability,
    ) {
    }

    /**
     * @return array{ok: bool, message: string, methods: list<array<string,mixed>>}
     */
    public function test(string $mode): array
    {
        $mode = $mode === 'live' ? 'live' : 'test';

        if (!$this->availability->environmentEnabled()) {
            // No outbound call while the kill switch is off, not even a read.
            $message = 'MyFatoorah is switched off for this deployment (MYFATOORAH_ENABLED), so no connection test was made.';
            $this->configRepo->recordTest($mode, false, $message);

            return ['ok' => false, 'message' => $message, 'methods' => []];
        }

        $config = $this->configRepo->forMode($mode);

        if (!$config->hasApiKey()) {
            $message = 'No API key is saved for ' . $mode . ' mode.';
            $this->configRepo->recordTest($mode, false, $message);

            return ['ok' => false, 'message' => $message, 'methods' => []];
        }

        $result = $this->catalog->refresh($mode);

        if (!$result['ok']) {
            $this->configRepo->recordTest($mode, false, (string) ($result['error'] ?? 'The connection test failed.'));

            return ['ok' => false, 'message' => (string) ($result['error'] ?? 'The connection test failed.'), 'methods' => []];
        }

        $methods = $result['methods'];
        $currency = $config->currency;

        // A key that works but an account that cannot settle in the store's currency is a problem worth
        // naming now rather than at the first checkout.
        $currencies = array_values(array_unique(array_filter(array_column($methods, 'currency'))));
        $supportsCurrency = $currencies === [] || in_array($currency, $currencies, true);

        $message = $supportsCurrency
            ? 'Connected. ' . count($methods) . ' payment method(s) are enabled on this MyFatoorah account.'
            : 'Connected, but this account reports no ' . $currency . ' payment methods. Ask MyFatoorah to enable ' . $currency . '.';

        $this->configRepo->recordTest(
            $mode,
            $supportsCurrency,
            $message,
            accountStatus: $supportsCurrency ? 'active' : 'currency_not_enabled',
        );

        return ['ok' => $supportsCurrency, 'message' => $message, 'methods' => $methods];
    }
}
