<?php

namespace Tests\Feature\OperationalCosts;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\OperationalCostAllocation;
use App\Models\OperationalCostContract;
use App\Models\OperationalCostEntry;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\ContractService;
use App\Services\OperationalCosts\Shipments\ShipmentCostService;
use App\Services\OperationalCosts\Shipments\ShipmentFactsFactory;
use App\Services\OperationalCosts\Support\Money;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/** Every price here is an arbitrary figure invented for the test. */
class ShipmentCostServiceTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private ContractService $contracts;

    private ShipmentCostService $costs;

    private ShipmentFactsFactory $facts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR']);
        $this->contracts = app(ContractService::class);
        $this->costs = app(ShipmentCostService::class);
        $this->facts = app(ShipmentFactsFactory::class);
    }

    /** @param list<array{service_type: string, calc_method: string, params: array<string, mixed>, conditions?: array<string, mixed>}> $rules */
    private function contract(array $rules, string $from = '2026-01-01', string $provider = 'torod_point', ?array $settings = null): OperationalCostContract
    {
        $contract = $this->contracts->createDraft([
            'party_type' => 'fulfillment_provider', 'provider_code' => $provider, 'party_name' => 'Test warehouse',
            'currency' => 'SAR', 'effective_from' => $from, 'settings' => $settings,
        ], adminId: 1);
        foreach ($rules as $rule) {
            $this->contracts->addRule($contract, $rule, adminId: 1);
        }

        return $this->contracts->activate($contract, 1, 'test');
    }

    private function standardRules(): array
    {
        return [
            ['service_type' => 'shipping', 'calc_method' => 'by_weight', 'params' => ['steps' => [['up_to_kg' => '1', 'amount' => '10'], ['up_to_kg' => '5', 'amount' => '15']]]],
            ['service_type' => 'order_processing', 'calc_method' => 'per_order', 'params' => ['amount' => '2']],
            ['service_type' => 'picking', 'calc_method' => 'per_unit', 'params' => ['amount' => '0.5']],
            ['service_type' => 'cod_collection', 'calc_method' => 'percent', 'params' => ['percent' => '2', 'min' => '3'], 'conditions' => ['payment_methods' => ['cod']]],
        ];
    }

    /** @param list<array{qty: int, weight: ?string, total: string}> $lines */
    private function fulfillment(array $lines, bool $cod = false, string $city = 'Riyadh', string $submitted = '2026-11-10 10:00:00', int $orderId = 1000): ProviderFulfillment
    {
        DB::table('orders')->insert(['id' => $orderId, 'order_status' => 'confirmed', 'payment_status' => 'paid', 'shipping_address_data' => json_encode(['city' => $city, 'country' => 'SA']), 'seller_is' => 'admin']);
        $total = collect($lines)->sum(fn (array $line): float => (float) $line['total']);
        $fulfillment = ProviderFulfillment::create([
            'reference' => $orderId . '-TP1', 'order_id' => $orderId, 'provider' => 'torod_point', 'generation' => 1,
            'idempotency_key' => hash('sha256', 'k' . $orderId), 'status' => FulfillmentStatus::Submitted, 'currency' => 'SAR',
            'payment_mode' => $cod ? 'cod' : 'prepaid', 'cod_amount' => $cod ? $total : 0, 'items_total' => $total, 'total_amount' => $total,
            'submitted_at' => $submitted,
        ]);
        foreach ($lines as $index => $line) {
            ProviderFulfillmentItem::create([
                'provider_fulfillment_id' => $fulfillment->id, 'order_id' => $orderId, 'order_detail_id' => $orderId * 10 + $index, 'product_id' => 50 + $index,
                'provider' => 'torod_point', 'name' => 'Item ' . $index, 'quantity' => $line['qty'], 'line_total' => $line['total'], 'weight_kg' => $line['weight'], 'status' => 'routed',
            ]);
        }

        return $fulfillment->fresh();
    }

    private function entry(ProviderFulfillment $fulfillment, CostComponent $component): ?OperationalCostEntry
    {
        return OperationalCostEntry::where('provider_fulfillment_id', $fulfillment->id)->where('cost_component', $component->value)->where('is_effective', true)->first();
    }

    public function test_each_service_the_contract_prices_becomes_one_estimated_entry(): void
    {
        $this->contract($this->standardRules());
        $shipment = $this->fulfillment([['qty' => 1, 'weight' => '0.5', 'total' => '40'], ['qty' => 3, 'weight' => '0.4', 'total' => '60']]);

        $this->costs->estimate($this->facts->forFulfillment($shipment));

        $this->assertSame('15.000000', $this->entry($shipment, CostComponent::Shipping)->amount, '1.7 kg falls in the 5 kg step.');
        $this->assertSame('2.000000', $this->entry($shipment, CostComponent::OrderProcessing)->amount);
        $this->assertSame('2.000000', $this->entry($shipment, CostComponent::Picking)->amount, '4 units at 0.5.');
        $this->assertNull($this->entry($shipment, CostComponent::CodCollection), 'A prepaid shipment has no cash-collection fee.');
        $this->assertNull($this->entry($shipment, CostComponent::ZoneSurcharge), 'A service the contract does not price is not recorded at all.');
        $this->assertSame(CostState::Estimated, $this->entry($shipment, CostComponent::Shipping)->state);
        $this->assertSame('rule', $this->entry($shipment, CostComponent::Shipping)->source);
    }

    public function test_running_the_estimate_again_records_nothing_new(): void
    {
        $this->contract($this->standardRules());
        $shipment = $this->fulfillment([['qty' => 1, 'weight' => '0.5', 'total' => '40']]);

        $this->costs->estimate($this->facts->forFulfillment($shipment));
        $count = OperationalCostEntry::count();
        $allocations = OperationalCostAllocation::count();
        $this->costs->estimate($this->facts->forFulfillment($shipment));
        $this->costs->estimate($this->facts->forFulfillment($shipment));

        $this->assertSame($count, OperationalCostEntry::count());
        $this->assertSame($allocations, OperationalCostAllocation::count());
    }

    public function test_a_shared_fee_is_split_across_the_items_and_adds_up_exactly(): void
    {
        $this->contract($this->standardRules());
        $shipment = $this->fulfillment([['qty' => 1, 'weight' => '0.5', 'total' => '40'], ['qty' => 3, 'weight' => '0.4', 'total' => '60']]);

        $this->costs->estimate($this->facts->forFulfillment($shipment));

        $shipping = $this->entry($shipment, CostComponent::Shipping);
        $parts = $shipping->allocations()->orderBy('target_id')->get();
        $this->assertCount(2, $parts);
        $this->assertSame('15.000000', Money::sum($parts->pluck('amount'))->toStorage());
        $this->assertSame('weight', $parts[0]->basis);
        $this->assertSame('4.411765', $parts[0]->amount, '0.5 kg of 1.7 kg of the 15.00 fee.');
        $this->assertSame('allocated', $shipping->allocation_status);

        $picking = $this->entry($shipment, CostComponent::Picking)->allocations()->orderBy('target_id')->pluck('amount')->all();
        $this->assertSame(['0.500000', '1.500000'], $picking, 'Picking follows quantity: 1 unit and 3 units.');
    }

    public function test_a_fee_per_shipment_does_not_grow_with_the_number_of_items(): void
    {
        $this->contract($this->standardRules());
        $one = $this->fulfillment([['qty' => 1, 'weight' => '0.2', 'total' => '10']], orderId: 1001);
        $many = $this->fulfillment([['qty' => 1, 'weight' => '0.2', 'total' => '10'], ['qty' => 1, 'weight' => '0.2', 'total' => '10'], ['qty' => 1, 'weight' => '0.2', 'total' => '10'], ['qty' => 1, 'weight' => '0.2', 'total' => '10']], orderId: 1002);

        $this->costs->estimate($this->facts->forFulfillment($one));
        $this->costs->estimate($this->facts->forFulfillment($many));

        $this->assertSame($this->entry($one, CostComponent::OrderProcessing)->amount, $this->entry($many, CostComponent::OrderProcessing)->amount);
    }

    public function test_cash_on_delivery_adds_the_collection_fee_with_its_minimum(): void
    {
        $this->contract($this->standardRules());
        $cheap = $this->fulfillment([['qty' => 1, 'weight' => '0.5', 'total' => '50']], cod: true, orderId: 1003);
        $dear = $this->fulfillment([['qty' => 1, 'weight' => '0.5', 'total' => '400']], cod: true, orderId: 1004);

        $this->costs->estimate($this->facts->forFulfillment($cheap));
        $this->costs->estimate($this->facts->forFulfillment($dear));

        $this->assertSame('3.000000', $this->entry($cheap, CostComponent::CodCollection)->amount, '2 % of 50 is 1, below the 3.00 minimum.');
        $this->assertSame('8.000000', $this->entry($dear, CostComponent::CodCollection)->amount);
    }

    public function test_an_unknown_weight_makes_the_shipping_cost_missing_not_free(): void
    {
        $this->contract($this->standardRules());
        $shipment = $this->fulfillment([['qty' => 1, 'weight' => null, 'total' => '40']]);

        $this->costs->estimate($this->facts->forFulfillment($shipment));

        $shipping = $this->entry($shipment, CostComponent::Shipping);
        $this->assertSame(CostState::Missing, $shipping->state);
        $this->assertNull($shipping->amount);
        $this->assertSame('weight_unknown', $shipping->missing_reason);
        $this->assertSame('2.000000', $this->entry($shipment, CostComponent::OrderProcessing)->amount, 'What can be priced still is.');
    }

    public function test_a_shipment_with_no_contract_has_one_missing_cost_not_a_dozen(): void
    {
        $shipment = $this->fulfillment([['qty' => 1, 'weight' => '0.5', 'total' => '40']]);

        $this->costs->estimate($this->facts->forFulfillment($shipment));

        $entries = OperationalCostEntry::where('provider_fulfillment_id', $shipment->id)->get();
        $this->assertCount(1, $entries);
        $this->assertSame('shipping', $entries[0]->cost_component);
        $this->assertSame('no_contract', $entries[0]->missing_reason);
        $this->assertNull($entries[0]->amount);
    }

    public function test_a_contract_that_stops_covering_the_date_makes_the_cost_missing(): void
    {
        $this->contract($this->standardRules(), from: '2027-01-01');
        $shipment = $this->fulfillment([['qty' => 1, 'weight' => '0.5', 'total' => '40']], submitted: '2026-11-10 10:00:00');

        $this->costs->estimate($this->facts->forFulfillment($shipment));

        $this->assertSame('no_contract', $this->entry($shipment, CostComponent::Shipping)->missing_reason);
    }

    public function test_a_service_the_contract_is_expected_to_price_but_does_not_is_missing(): void
    {
        $this->contract([['service_type' => 'shipping', 'calc_method' => 'per_shipment', 'params' => ['amount' => '12']]], settings: ['expected_components' => ['packing']]);
        $shipment = $this->fulfillment([['qty' => 1, 'weight' => '0.5', 'total' => '40']]);

        $this->costs->estimate($this->facts->forFulfillment($shipment));

        $this->assertSame('not_in_contract', $this->entry($shipment, CostComponent::Packing)->missing_reason);
    }

    public function test_a_contract_change_never_rewrites_a_cost_already_estimated(): void
    {
        $v1 = $this->contract([['service_type' => 'shipping', 'calc_method' => 'per_shipment', 'params' => ['amount' => '12']]]);
        $old = $this->fulfillment([['qty' => 1, 'weight' => '0.5', 'total' => '40']], submitted: '2026-05-10 10:00:00', orderId: 1005);
        $this->costs->estimate($this->facts->forFulfillment($old));

        $v2 = $this->contracts->startNewVersion($v1->fresh(), '2026-07-01', 1, 'new prices');
        $this->contracts->updateRule($v2->rules()->first(), ['service_type' => 'shipping', 'calc_method' => 'per_shipment', 'params' => ['amount' => '20']], 1);
        $this->contracts->activate($v2, 1, 'new prices');

        $new = $this->fulfillment([['qty' => 1, 'weight' => '0.5', 'total' => '40']], submitted: '2026-11-10 10:00:00', orderId: 1006);
        $this->costs->estimate($this->facts->forFulfillment($new));
        $this->costs->estimate($this->facts->forFulfillment($old->fresh()));

        $this->assertSame('12.000000', $this->entry($old, CostComponent::Shipping)->amount, 'A May shipment keeps the May price.');
        $this->assertSame($v1->id, (int) $this->entry($old, CostComponent::Shipping)->contract_id);
        $this->assertSame('20.000000', $this->entry($new, CostComponent::Shipping)->amount);
        $this->assertSame($v2->id, (int) $this->entry($new, CostComponent::Shipping)->contract_id);
        $this->assertSame(2, (int) $this->entry($new, CostComponent::Shipping)->contract_version);
    }

    public function test_a_cancelled_shipment_adds_the_cancellation_fee_and_a_returned_one_the_return_fee(): void
    {
        $this->contract([
            ['service_type' => 'shipping', 'calc_method' => 'per_shipment', 'params' => ['amount' => '12']],
            ['service_type' => 'cancellation_fee', 'calc_method' => 'fixed', 'params' => ['amount' => '4']],
            ['service_type' => 'return_fee', 'calc_method' => 'fixed', 'params' => ['amount' => '9']],
        ]);
        $cancelled = $this->fulfillment([['qty' => 1, 'weight' => '0.5', 'total' => '40']], orderId: 1007);
        $cancelled->forceFill(['status' => FulfillmentStatus::Cancelled])->save();
        $returned = $this->fulfillment([['qty' => 1, 'weight' => '0.5', 'total' => '40']], orderId: 1008);
        $returned->forceFill(['status' => FulfillmentStatus::Returned])->save();

        $this->costs->estimate($this->facts->forFulfillment($cancelled->fresh()));
        $this->costs->estimate($this->facts->forFulfillment($returned->fresh()));

        $this->assertSame('4.000000', $this->entry($cancelled, CostComponent::CancellationFee)->amount);
        $this->assertNull($this->entry($cancelled, CostComponent::ReturnFee));
        $this->assertSame('9.000000', $this->entry($returned, CostComponent::ReturnFee)->amount);
    }

    public function test_a_city_outside_every_zone_is_a_missing_cost(): void
    {
        $this->contract([['service_type' => 'shipping', 'calc_method' => 'by_zone', 'params' => ['zones' => [['name' => 'Central', 'cities' => ['Riyadh', 'الرياض'], 'countries' => [], 'amount' => '12']]]]]);
        $inZone = $this->fulfillment([['qty' => 1, 'weight' => '0.5', 'total' => '40']], city: 'رياض', orderId: 1009);
        $outside = $this->fulfillment([['qty' => 1, 'weight' => '0.5', 'total' => '40']], city: 'Tabuk', orderId: 1010);

        $this->costs->estimate($this->facts->forFulfillment($inZone));
        $this->costs->estimate($this->facts->forFulfillment($outside));

        $this->assertSame('12.000000', $this->entry($inZone, CostComponent::Shipping)->amount, 'A city is matched across the spellings the zone lists, with or without the Arabic article.');
        $this->assertSame('outside_price_list', $this->entry($outside, CostComponent::Shipping)->missing_reason);
    }
}
