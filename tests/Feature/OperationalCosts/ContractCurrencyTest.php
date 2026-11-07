<?php

namespace Tests\Feature\OperationalCosts;

use App\Enums\OperationalCosts\CostComponent;
use App\Models\OperationalCostContract;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\Calculation\ServiceCostCalculator;
use App\Services\OperationalCosts\ContractService;
use App\Services\OperationalCosts\Data\ServiceContext;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/** A contract in one currency applied to a sale in another: the rule's percentages, tiers and limits meet the order's value in the rule's own currency. Rates are the fixture's (1 USD = 3.75 SAR). */
class ContractCurrencyTest extends TestCase
{
    use BuildsSalesChannelSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR']);
    }

    private function usdGatewayContract(): OperationalCostContract
    {
        $contracts = app(ContractService::class);
        $contract = $contracts->createDraft(['party_type' => 'payment_gateway', 'provider_code' => 'card', 'party_name' => 'Gateway', 'currency' => 'USD', 'effective_from' => '2026-01-01'], 1);
        $contracts->addRule($contract, ['service_type' => 'payment_gateway', 'calc_method' => 'percent_plus_fixed', 'params' => ['percent' => '3', 'amount' => '0.30']], 1);

        return $contracts->activate($contract, 1, 'test');
    }

    private function context(string $currency, string $value): ServiceContext
    {
        return new ServiceContext(serviceDate: CarbonImmutable::parse('2026-11-05'), currency: $currency, value: Money::of($value));
    }

    public function test_a_percentage_in_a_foreign_currency_contract_applies_to_the_value_in_that_currency(): void
    {
        $this->usdGatewayContract();

        $cost = app(ServiceCostCalculator::class)->price('card', [CostComponent::PaymentGateway], $this->context('SAR', '375'))[0];

        $this->assertTrue($cost->isPriced());
        $this->assertSame(['3.300000', 'USD'], [$cost->result->amount->toStorage(), $cost->result->currency], '375 SAR is 100 USD: 3% + 0.30 = 3.30 USD, not 3% of 375 + 0.30.');
    }

    public function test_a_value_in_the_contract_currency_is_used_as_it_is(): void
    {
        $this->usdGatewayContract();

        $cost = app(ServiceCostCalculator::class)->price('card', [CostComponent::PaymentGateway], $this->context('USD', '100'))[0];

        $this->assertSame('3.300000', $cost->result->amount->toStorage());
    }

    public function test_without_an_exchange_rate_the_cost_is_missing_and_says_why_not_a_wrong_figure(): void
    {
        $this->usdGatewayContract();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates(['USD' => 1.0, 'SAR' => 3.75]));

        $cost = app(ServiceCostCalculator::class)->price('card', [CostComponent::PaymentGateway], $this->context('TRY', '4000'))[0];

        $this->assertTrue($cost->isMissing());
        $this->assertSame('fx_unavailable', $cost->missingReason);
    }
}
