<?php

namespace Tests\Feature\MyFatoorah;

use App\Services\MyFatoorah\GatewayConfigRepository;
use Illuminate\Support\Facades\DB;

use function App\Utils\payment_gateways;

/**
 * Whether MyFatoorah is offered to a customer.
 *
 * One function answers this for both surfaces: `payment_gateways()` feeds the web checkout AND the
 * `payment_methods` array the mobile app reads from `/api/v1/config`. Testing it therefore tests both,
 * and more importantly means the app can never be offered a method the server would refuse — the bug
 * that has a customer pick a payment method and get an error they cannot act on.
 */
class CheckoutVisibilityTest extends MyFatoorahTestCase
{
    private function offeredKeys(): array
    {
        return payment_gateways()->pluck('key_name')->all();
    }

    /** Another gateway, so the tests can tell "MyFatoorah was dropped" from "the list was empty". */
    private function anotherActiveGateway(): void
    {
        DB::table('addon_settings')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'key_name' => 'paytabs',
            'settings_type' => 'payment_config',
            'mode' => 'live',
            'is_active' => 1,
            'live_values' => json_encode(['status' => 1, 'mode' => 'live', 'server_key' => 'k']),
            'test_values' => json_encode(['status' => 1, 'mode' => 'live', 'server_key' => 'k']),
            'additional_data' => json_encode(['gateway_title' => 'PayTabs']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_it_is_offered_when_switched_on_and_configured(): void
    {
        $this->configureGateway();

        $this->assertContains(GatewayConfigRepository::KEY, $this->offeredKeys());
    }

    public function test_it_is_not_offered_when_switched_off(): void
    {
        $this->anotherActiveGateway();
        $this->configureGateway(active: false);

        $offered = $this->offeredKeys();

        $this->assertNotContains(GatewayConfigRepository::KEY, $offered);
        $this->assertContains('paytabs', $offered, 'the other gateways are untouched');
    }

    public function test_it_is_not_offered_when_switched_on_but_no_api_key_is_saved(): void
    {
        // Being switched on is not the same as being usable. Offering it here means the customer picks
        // it, waits, and gets an error — the server refuses such a payment either way.
        $this->anotherActiveGateway();
        $this->configureGateway(apiKey: null, webhookSecret: 'secret');

        $this->assertNotContains(GatewayConfigRepository::KEY, $this->offeredKeys());
        $this->assertContains('paytabs', $this->offeredKeys());
    }

    public function test_it_is_not_offered_when_no_webhook_secret_is_saved(): void
    {
        $this->configureGateway(apiKey: 'key', webhookSecret: null);

        $this->assertNotContains(GatewayConfigRepository::KEY, $this->offeredKeys());
    }

    public function test_it_is_not_offered_while_the_environment_kill_switch_is_off(): void
    {
        $this->anotherActiveGateway();
        $this->configureGateway();
        config(['myfatoorah.enabled' => false]);

        $this->assertNotContains(GatewayConfigRepository::KEY, $this->offeredKeys());
        $this->assertContains('paytabs', $this->offeredKeys(), 'only MyFatoorah is affected');
    }

    public function test_it_is_not_offered_when_sar_is_switched_off_in_the_store(): void
    {
        $this->configureGateway();
        DB::table('currencies')->where('code', 'SAR')->update(['status' => 0]);
        \App\Services\MyFatoorah\GatewayAvailability::flushCurrencyCache();

        $this->assertNotContains(GatewayConfigRepository::KEY, $this->offeredKeys());
    }

    public function test_the_other_gateways_are_never_filtered_by_this_check(): void
    {
        // The readiness check is scoped to MyFatoorah on purpose: the others have no equivalent and
        // their behaviour must be exactly what it was.
        $this->anotherActiveGateway();
        DB::table('addon_settings')->where('key_name', 'paytabs')->update([
            // Deliberately unusable, the way an unconfigured gateway looks today.
            'live_values' => json_encode(['status' => 1, 'mode' => 'live', 'server_key' => '']),
        ]);
        $this->configureGateway(active: false);

        $this->assertContains('paytabs', $this->offeredKeys());
    }

    public function test_no_credential_reaches_the_payment_method_list(): void
    {
        // This list is serialised into the mobile app's config response.
        $this->configureGateway(apiKey: 'super-secret-key', webhookSecret: 'super-secret-hook');

        $serialised = json_encode(payment_gateways()->toArray());

        $this->assertStringNotContainsString('super-secret-key', (string) $serialised);
        $this->assertStringNotContainsString('super-secret-hook', (string) $serialised);
    }

    public function test_the_customer_facing_title_is_the_one_an_operator_set(): void
    {
        $this->configureGateway();
        $this->configRepo()->save(settings: ['gateway_title' => 'MyFatoorah — ماي فاتورة السعودية']);

        $row = payment_gateways()->firstWhere('key_name', GatewayConfigRepository::KEY);
        $additional = json_decode((string) $row->additional_data, true);

        $this->assertSame('MyFatoorah — ماي فاتورة السعودية', $additional['gateway_title']);
    }
}
