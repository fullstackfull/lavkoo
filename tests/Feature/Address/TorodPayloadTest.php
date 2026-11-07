<?php

namespace Tests\Feature\Address;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\Order;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\Providers\M5azn\M5aznPayloadBuilder;
use App\Services\Fulfillment\Providers\TorodPoint\TorodOrderPayloadBuilder;
use Illuminate\Support\Facades\Http;

/**
 * What the providers are told about where an order goes: Torod's receiverAddress from the approved mapping and the street text,
 * and M5AZN's city name from the store's own city when the address has one.
 */
class TorodPayloadTest extends TorodAddressTestCase
{
    /** @return array<string, mixed> Torod's receiverAddress for an order with the given snapshot (Riyadh, mapped) */
    private function receiverAddress(array $snapshot): array
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['company_id' => '714', 'dry_run' => true]);
        app(\App\Services\Fulfillment\ProviderCityMappingService::class)->approve(provider: 'torod_point', cityId: $this->ids['riyadh'], externalCityId: '36189');
        $fulfillment = $this->torodFulfillment(['shipping_address_data' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);
        Http::fake();

        return $this->submitAsClaimed($this->adapter(), $fulfillment)->meta['payload_preview']['receiverAddress'];
    }

    public function test_the_street_text_comes_first_and_the_district_follows(): void
    {
        $address = $this->receiverAddress($this->unifiedSnapshot('riyadh', ['address' => 'King Fahd Rd 12', 'district' => 'Al Olaya']));

        $this->assertSame(['cityId' => '36189', 'regionId' => '1204', 'addressLine1' => 'King Fahd Rd 12, Al Olaya'], $address);
    }

    public function test_a_district_the_street_text_already_names_is_not_repeated(): void
    {
        $address = $this->receiverAddress($this->unifiedSnapshot('riyadh', ['address' => 'Olaya St, AL OLAYA, building 4', 'district' => 'Al Olaya']));

        $this->assertSame('Olaya St, AL OLAYA, building 4', $address['addressLine1']);
    }

    public function test_an_old_address_without_a_district_sends_its_address_text_exactly_as_before(): void
    {
        $address = $this->receiverAddress(['contact_person_name' => 'Sara', 'phone' => '0551234567', 'address' => '  King Fahd Rd 12 ', 'city' => 'الرياض']);

        $this->assertSame('King Fahd Rd 12', $address['addressLine1']);
    }

    public function test_no_national_address_village_or_coordinate_is_ever_added(): void
    {
        $snapshot = $this->unifiedSnapshot('riyadh', ['latitude' => '24.7', 'longitude' => '46.6']);
        $this->enableProvider(ProviderCode::TorodPoint, ['company_id' => '714', 'dry_run' => true]);
        app(\App\Services\Fulfillment\ProviderCityMappingService::class)->approve(provider: 'torod_point', cityId: $this->ids['riyadh'], externalCityId: '36189');
        $fulfillment = $this->torodFulfillment(['shipping_address_data' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);
        Http::fake();

        $preview = $this->submitAsClaimed($this->adapter(), $fulfillment)->meta['payload_preview'];

        $encoded = json_encode($preview, JSON_UNESCAPED_UNICODE);
        foreach (['RAGI2929', 'nationalAddress', 'national_address', 'villageId', 'latitude', '24.7', '46.6', 'zip', 'postal'] as $absent) {
            $this->assertStringNotContainsString($absent, $encoded, $absent);
        }
        $this->assertSame(['cityId', 'regionId', 'addressLine1'], array_keys($preview['receiverAddress']));
    }

    public function test_the_builder_signature_and_the_province_ids_are_unchanged(): void
    {
        $fulfillment = $this->torodFulfillment(['shipping_address_data' => $this->snapshotJson('riyadh')]);
        $order = Order::findOrFail($fulfillment->order_id);
        $city = $this->torodCity('99001', 'مدينة', 'Place', '4321');

        $payload = app(TorodOrderPayloadBuilder::class)->build($fulfillment->load('items'), $order, app(ProviderConnectionManager::class)->config(ProviderCode::TorodPoint), $city);

        $this->assertSame('99001', $payload['receiverAddress']['cityId'], 'a string, as before');
        $this->assertSame('4321', $payload['receiverAddress']['regionId'], 'the region exactly as the provider gave it, not one of the 13 official regions');
    }

    // ---- M5AZN ---------------------------------------------------------------------------------------------------------------------

    private function m5aznCity(string $snapshotJson): string
    {
        $this->enableProvider(ProviderCode::M5azn, [], ['email' => 'seller@example.test', 'password' => 'pw-very-secret-1']);
        $fulfillment = $this->routeSingleProviderOrder(ProviderCode::M5azn, ['shipping_address_data' => $snapshotJson]);
        $config = app(ProviderConnectionManager::class)->config(ProviderCode::M5azn);

        return app(M5aznPayloadBuilder::class)->build($this->m5aznFulfillment($fulfillment), Order::findOrFail($fulfillment->order_id), $config)['shipping_info']['city'];
    }

    private function m5aznFulfillment(ProviderFulfillment $fulfillment): ProviderFulfillment
    {
        return $fulfillment->load('items');
    }

    public function test_m5azn_is_given_the_stores_own_city_name_when_the_address_has_a_city_of_ours(): void
    {
        $snapshot = $this->snapshotJson('jeddah', ['city' => 'Jiddah  (typed)']);

        $this->assertSame('جدة', $this->m5aznCity($snapshot));
    }

    public function test_m5azn_is_given_the_typed_text_for_an_older_address(): void
    {
        $snapshot = json_encode(['contact_person_name' => 'A B', 'phone' => '0551234567', 'address' => 'x', 'city' => '  الرياض ', 'email' => 'a@b.test'], JSON_UNESCAPED_UNICODE);

        $this->assertSame('الرياض', $this->m5aznCity((string) $snapshot));
    }
}
