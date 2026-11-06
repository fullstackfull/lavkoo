<?php

namespace Tests\Feature\OperationalCosts;

use App\Enums\OperationalCosts\ContractStatus;
use App\Models\OperationalCostContract;
use App\Models\OperationalCostRule;
use App\Services\OperationalCosts\ContractService;
use App\Services\OperationalCosts\Exceptions\InvalidContractChange;
use LogicException;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * Every price in these tests is an arbitrary number made up for the test; the module ships with no price list.
 */
class ContractServiceTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private ContractService $contracts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->contracts = app(ContractService::class);
    }

    private function draft(string $from = '2026-01-01', string $provider = 'torod_point', string $currency = 'SAR'): OperationalCostContract
    {
        return $this->contracts->createDraft([
            'party_type' => 'fulfillment_provider', 'provider_code' => $provider, 'party_name' => 'Test warehouse',
            'currency' => $currency, 'effective_from' => $from, 'reference' => 'TEST-1',
        ], adminId: 1);
    }

    private function shippingRule(OperationalCostContract $contract, string $amount = '12'): OperationalCostRule
    {
        return $this->contracts->addRule($contract, ['service_type' => 'shipping', 'calc_method' => 'per_shipment', 'params' => ['amount' => $amount]], adminId: 1);
    }

    public function test_a_new_contract_is_a_draft_with_no_price_in_it(): void
    {
        $contract = $this->draft();

        $this->assertSame(ContractStatus::Draft, $contract->status);
        $this->assertSame(1, $contract->version);
        $this->assertSame($contract->id, $contract->root_id);
        $this->assertSame(0, $contract->rules()->count());
    }

    public function test_activation_needs_a_rule_and_a_reason(): void
    {
        $contract = $this->draft();

        try {
            $this->contracts->activate($contract, 1, 'go');
            $this->fail('A contract with no price rule must not be activated.');
        } catch (InvalidContractChange) {
            $this->assertSame(ContractStatus::Draft, $contract->fresh()->status);
        }

        $this->shippingRule($contract);
        $this->expectException(InvalidContractChange::class);
        $this->contracts->activate($contract, 1, '   ');
    }

    public function test_an_active_contract_cannot_be_edited_and_its_rules_cannot_change(): void
    {
        $contract = $this->draft();
        $rule = $this->shippingRule($contract);
        $this->contracts->activate($contract, 1, 'signed');

        try {
            $this->contracts->addRule($contract->fresh(), ['service_type' => 'picking', 'calc_method' => 'per_order', 'params' => ['amount' => '1']], 1);
            $this->fail('A rule was added to an active contract.');
        } catch (InvalidContractChange) {
        }

        $this->expectException(LogicException::class);
        $rule->fresh()->forceFill(['params' => ['amount' => '99']])->save();
    }

    public function test_rules_with_malformed_figures_are_refused_when_typed_in(): void
    {
        $contract = $this->draft();

        foreach ([
            ['service_type' => 'shipping', 'calc_method' => 'per_shipment', 'params' => ['amount' => 'abc']],
            ['service_type' => 'shipping', 'calc_method' => 'per_shipment', 'params' => []],
            ['service_type' => 'shipping', 'calc_method' => 'percent', 'params' => ['percent' => '150']],
            ['service_type' => 'shipping', 'calc_method' => 'by_weight', 'params' => ['steps' => [['up_to_kg' => '5', 'amount' => '1'], ['up_to_kg' => '3', 'amount' => '2']]]],
            ['service_type' => 'shipping', 'calc_method' => 'tiered_quantity', 'params' => ['tiers' => [['from' => '1', 'to' => '5', 'amount' => '1'], ['from' => '3', 'to' => '9', 'amount' => '2']]]],
            ['service_type' => 'cogs', 'calc_method' => 'fixed', 'params' => ['amount' => '1']],
            ['service_type' => 'shipping', 'calc_method' => 'made_up', 'params' => ['amount' => '1']],
        ] as $bad) {
            try {
                $this->contracts->addRule($contract, $bad, 1);
                $this->fail('A malformed rule was accepted: ' . json_encode($bad));
            } catch (InvalidContractChange) {
            }
        }

        $this->assertSame(0, $contract->rules()->count());
    }

    public function test_a_new_version_takes_over_from_its_start_date_and_the_old_one_still_prices_the_past(): void
    {
        $v1 = $this->draft('2026-01-01');
        $this->shippingRule($v1, '12');
        $this->contracts->activate($v1, 1, 'signed');

        $v2 = $this->contracts->startNewVersion($v1->fresh(), '2026-07-01', 1, 'price change');
        $this->assertSame(2, $v2->version);
        $this->assertSame($v1->root_id, $v2->root_id);
        $this->assertSame(1, $v2->rules()->count(), 'The new version starts from a copy of the rules.');
        $rule = $v2->rules()->first();
        $this->contracts->updateRule($rule, ['service_type' => 'shipping', 'calc_method' => 'per_shipment', 'params' => ['amount' => '15']], 1);
        $this->contracts->activate($v2, 1, 'new prices');

        $this->assertSame(ContractStatus::Superseded, $v1->fresh()->status);
        $this->assertSame('2026-06-30', $v1->fresh()->effective_to->toDateString());

        $inMay = $this->contracts->contractFor('torod_point', new \DateTimeImmutable('2026-05-15'));
        $inAugust = $this->contracts->contractFor('torod_point', new \DateTimeImmutable('2026-08-15'));
        $this->assertSame($v1->id, $inMay->id, 'A service in May is priced with the May list, whatever is signed later.');
        $this->assertSame($v2->id, $inAugust->id);
        $this->assertSame('12.000000', $inMay->rules()->first()->params['amount']);
        $this->assertSame('15.000000', $inAugust->rules()->first()->params['amount']);
    }

    public function test_two_contracts_of_one_company_cannot_cover_the_same_days(): void
    {
        $first = $this->draft('2026-01-01');
        $this->shippingRule($first);
        $this->contracts->activate($first, 1, 'signed');

        $second = $this->draft('2026-03-01');
        $this->shippingRule($second);

        $this->expectException(InvalidContractChange::class);
        $this->contracts->activate($second, 1, 'overlaps');
    }

    public function test_contracts_of_different_companies_or_currencies_do_not_collide(): void
    {
        $torod = $this->draft('2026-01-01', 'torod_point');
        $this->shippingRule($torod);
        $this->contracts->activate($torod, 1, 'signed');

        $other = $this->draft('2026-01-01', 'm5azn');
        $this->shippingRule($other);
        $this->contracts->activate($other, 1, 'signed');

        $dollars = $this->draft('2026-01-01', 'torod_point', 'USD');
        $this->shippingRule($dollars);
        $this->contracts->activate($dollars, 1, 'signed');

        $this->assertSame(3, OperationalCostContract::inForce()->count());
        $this->assertSame($dollars->id, $this->contracts->contractFor('torod_point', new \DateTimeImmutable('2026-02-01'), 0, 'USD')->id);
    }

    public function test_a_seller_never_sees_another_sellers_contract(): void
    {
        $mine = $this->contracts->createDraft([
            'party_type' => 'fulfillment_provider', 'provider_code' => 'torod_point', 'party_name' => 'Mine',
            'currency' => 'SAR', 'effective_from' => '2026-01-01',
        ], adminId: null, sellerId: 5);
        $this->shippingRule($mine);
        $this->contracts->activate($mine, null, 'signed');

        $this->assertNull($this->contracts->contractFor('torod_point', new \DateTimeImmutable('2026-02-01'), 0));
        $this->assertSame($mine->id, $this->contracts->contractFor('torod_point', new \DateTimeImmutable('2026-02-01'), 5)->id);
    }

    public function test_every_step_leaves_a_revision_with_who_and_why(): void
    {
        $contract = $this->draft();
        $this->shippingRule($contract);
        $this->contracts->activate($contract, 1, 'signed on paper');

        $actions = $contract->revisions()->pluck('action')->all();
        $this->assertSame(['created', 'rule_added', 'activated'], $actions);
        $activated = $contract->revisions()->where('action', 'activated')->first();
        $this->assertSame('signed on paper', $activated->reason);
        $this->assertSame(1, (int) $activated->actor_id);
        $this->assertSame('draft', $activated->before['status']);
        $this->assertSame('active', $activated->after['status']);
    }

    public function test_only_a_draft_can_be_archived(): void
    {
        $contract = $this->draft();
        $this->shippingRule($contract);
        $this->contracts->activate($contract, 1, 'signed');

        $this->expectException(InvalidContractChange::class);
        $this->contracts->archive($contract->fresh(), 1, 'oops');
    }
}
