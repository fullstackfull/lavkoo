<?php

namespace Tests\Feature\OperationalCosts;

use App\Models\AuditLog;
use App\Models\ExternalOrderLine;
use App\Models\InventoryCostConsumption;
use App\Models\InventoryCostLayer;
use App\Models\InventoryLocation;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostReturnDecision;
use App\Services\OperationalCosts\Profit\ProfitabilityService;
use App\Services\OperationalCosts\Sales\SaleCostService;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use App\Services\SalesChannels\Support\ChannelSettings;
use App\Services\SalesChannels\Sync\ChannelSyncService;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;

/**
 * Returned units are decided, not assumed: put back on the shelf at the cost they left at (a layer, and that part of the cost of goods reversed), or written off as damaged (the cost
 * stays). Once per line and return event, never more than was sold or than the returns account for, never more than was taken from stock, and audited. The owner may switch on
 * putting the units of an accepted claim back automatically; it is off by default.
 *
 * The figures are invented: a line of 3 units sold from the own warehouse, bought at 10.00 each, so its cost of goods is 30.00.
 */
class ReturnDecisionTest extends ReturnsTestCase
{
    // ---- restock ---------------------------------------------------------------------------------------------------------

    public function test_units_of_an_accepted_claim_go_back_at_the_cost_they_left_at_and_that_part_of_the_cost_of_goods_is_reversed(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 2]);
        $this->assertSame('30.000000', $this->cogsNet($line));
        $this->assertSame(7, $this->onShelf());

        $result = $this->decide($line, 'claim:CLM-1', restock: 2);

        $this->assertFalse($result->alreadyDecided);
        $this->assertSame('10.000000', $this->cogsNet($line), '3 units were sold, 2 came back: 1 unit of cost.');
        $this->assertSame(9, $this->onShelf(), 'The two units are on the shelf again.');
        $layer = InventoryCostLayer::where('source_type', 'return')->firstOrFail();
        $this->assertSame([2, '10.000000', $this->warehouse->id], [$layer->quantity_received, $layer->unit_cost, $layer->inventory_location_id]);
        $decision = $result->decision->fresh();
        $this->assertSame([2, 0, $layer->id, false, 1], [$decision->units_restocked, $decision->units_written_off, $decision->layer_id, $decision->automatic, $decision->decided_by]);
        $this->assertNotNull($decision->entry_id);
    }

    public function test_deciding_the_same_return_twice_changes_nothing_and_is_audited_once(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 2]);

        $first = $this->decide($line, 'claim:CLM-1', restock: 2);
        $second = $this->decide($line, 'claim:CLM-1', restock: 2);
        $third = $this->decide($line, 'claim:CLM-1', restock: 1, writeOff: 1);

        $this->assertSame([false, true, true], [$first->alreadyDecided, $second->alreadyDecided, $third->alreadyDecided]);
        $this->assertSame(1, OperationalCostReturnDecision::count());
        $this->assertSame(1, InventoryCostLayer::where('source_type', 'return')->count());
        $this->assertSame(9, $this->onShelf());
        $this->assertSame('10.000000', $this->cogsNet($line));
        $this->assertSame(1, AuditLog::where('action', 'operational_cost.return_decided')->count());
    }

    public function test_damaged_units_are_written_off_with_no_layer_and_their_cost_stays(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 3]);

        $this->decide($line, 'claim:CLM-1', restock: 1, writeOff: 2);

        $this->assertSame('20.000000', $this->cogsNet($line), 'One unit was put back; the two damaged ones stay as cost.');
        $this->assertSame(1, (int) InventoryCostLayer::where('source_type', 'return')->sum('quantity_received'));
        $this->assertSame(8, $this->onShelf());
        $this->assertSame([1, 2], [OperationalCostReturnDecision::firstOrFail()->units_restocked, OperationalCostReturnDecision::firstOrFail()->units_written_off]);
    }

    public function test_every_unit_written_off_posts_no_layer_and_no_entry_at_all(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 3]);
        $entries = OperationalCostEntry::count();

        $this->decide($line, 'claim:CLM-1', restock: 0, writeOff: 3);

        $this->assertSame(0, InventoryCostLayer::where('source_type', 'return')->count());
        $this->assertSame($entries, OperationalCostEntry::count());
        $this->assertSame('30.000000', $this->cogsNet($line));
        $this->assertNull(OperationalCostReturnDecision::firstOrFail()->inventory_location_id);
    }

    public function test_the_units_can_go_back_to_another_location_than_the_one_they_were_sold_from(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 1]);
        $returns = $this->stockLocation('Returns shelf', type: InventoryLocation::TYPE_OWN, provider: null);

        $this->decide($line, 'claim:CLM-1', restock: 1, location: $returns->id);

        $this->assertSame($returns->id, InventoryCostLayer::where('source_type', 'return')->firstOrFail()->inventory_location_id);
        $this->assertSame($returns->id, OperationalCostReturnDecision::firstOrFail()->inventory_location_id);
    }

    // ---- caps ------------------------------------------------------------------------------------------------------------

    public function test_a_claim_is_decided_for_exactly_its_units(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 2]);

        $this->assertRefused('claim_units_must_all_be_decided', fn () => $this->decide($line, 'claim:CLM-1', restock: 1));
        $this->assertRefused('claim_units_must_all_be_decided', fn () => $this->decide($line, 'claim:CLM-1', restock: 2, writeOff: 1));
        $this->assertSame(0, OperationalCostReturnDecision::count());
    }

    public function test_a_claim_the_line_does_not_have_is_refused(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 2]);

        $this->assertRefused('unknown_return_event', fn () => $this->decide($line, 'claim:NOPE', restock: 2));
        $this->assertRefused('unknown_return_event', fn () => $this->decide($line, 'anything-else', restock: 2));
        $this->assertRefused('unknown_return_event', fn () => $this->decide($line, 'manual:', restock: 2));
    }

    public function test_units_put_back_never_exceed_what_was_taken_from_stock_over_all_the_returns_of_the_line(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 2, 'CLM-2' => 1]);
        $this->decide($line, 'claim:CLM-1', restock: 2);

        $this->assertSame(1, $this->returns->standing($line->fresh())['restockable'], '3 taken, 2 put back.');
        $this->decide($line->fresh(), 'claim:CLM-2', restock: 1);
        $this->assertSame(0, $this->returns->standing($line->fresh())['restockable']);
        $this->assertSame('0.000000', $this->cogsNet($line), 'Every unit came back.');
        $this->assertSame(10, $this->onShelf());
    }

    public function test_two_claims_cannot_put_back_more_than_was_sold(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 3, 'CLM-2' => 3]);

        $this->decide($line, 'claim:CLM-1', restock: 3);

        $this->assertRefused('unknown_return_event', fn () => $this->decide($line->fresh(), 'claim:CLM-2', restock: 3), 'The second claim has nothing left to account for: all 3 units are decided.');
        $this->assertSame(10, $this->onShelf());
    }

    public function test_a_return_a_person_records_is_capped_by_what_was_sold_less_what_was_decided(): void
    {
        $line = $this->soldLine();

        $this->assertRefused('more_than_sold', fn () => $this->decide($line, 'manual:0', restock: 4));
        $this->decide($line, 'manual:0', restock: 2);
        $this->assertRefused('more_than_sold', fn () => $this->decide($line->fresh(), 'manual:2', restock: 2));
        $this->decide($line->fresh(), 'manual:2', restock: 0, writeOff: 1);

        $this->assertSame(2, (int) OperationalCostReturnDecision::sum('units_restocked'));
        $this->assertSame(1, (int) OperationalCostReturnDecision::sum('units_written_off'));
        $this->assertSame('10.000000', $this->cogsNet($line), 'Two of three came back; the damaged one stays as cost.');
    }

    public function test_a_double_submit_of_a_recorded_return_is_one_decision(): void
    {
        $line = $this->soldLine();

        $this->decide($line, 'manual:0', restock: 1);
        $this->decide($line->fresh(), 'manual:0', restock: 1);

        $this->assertSame(1, OperationalCostReturnDecision::count());
        $this->assertSame(8, $this->onShelf());
    }

    public function test_a_line_the_channel_reports_returned_units_for_is_decided_through_its_claim_only(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 2]);

        $this->assertRefused('use_the_claim', fn () => $this->decide($line, 'manual:0', restock: 1));
    }

    public function test_nothing_can_be_put_back_when_no_cost_of_goods_was_recognised_but_damaged_units_can_still_be_written_off(): void
    {
        config(['operational_costs.enabled' => false]);
        $line = $this->soldLine();
        $this->assertSame(0, InventoryCostConsumption::count(), 'The module was off: no unit was taken from the layers.');

        $this->assertRefused('no_cost_recognised', fn () => $this->decide($line, 'manual:0', restock: 1));
        $this->decide($line, 'manual:0', restock: 0, writeOff: 1);

        $this->assertSame(1, OperationalCostReturnDecision::count());
    }

    public function test_the_units_cannot_be_put_back_without_a_product_a_location_or_a_location_of_the_sellers_own(): void
    {
        $line = $this->soldLine();
        $other = $this->stockLocation('Seller shelf', seller: 9, type: InventoryLocation::TYPE_OWN, provider: null);
        $closed = $this->stockLocation('Closed', type: InventoryLocation::TYPE_OWN, provider: null);
        $closed->forceFill(['is_active' => false])->save();

        $this->assertRefused('no_location', fn () => $this->returns->decide($line, 'manual:0', 1, 0, null, 1));
        $this->assertRefused('location_unknown', fn () => $this->returns->decide($line, 'manual:0', 1, 0, 99999, 1));
        $this->assertRefused('location_unknown', fn () => $this->returns->decide($line, 'manual:0', 1, 0, $other->id, 1));
        $this->assertRefused('location_unknown', fn () => $this->returns->decide($line, 'manual:0', 1, 0, $closed->id, 1));

        $line->forceFill(['product_id' => null])->save();
        $this->assertRefused('not_linked', fn () => $this->decide($line->fresh(), 'manual:0', restock: 1));
        $this->assertSame(0, OperationalCostReturnDecision::count());
    }

    public function test_a_return_event_too_long_to_be_stored_is_refused_and_never_truncated(): void
    {
        $claim = str_repeat('c', 200);
        $line = $this->acceptedReturn($this->soldLine(), [$claim => 2]);

        $this->assertRefused('unknown_return_event', fn () => $this->decide($line, 'claim:' . $claim, restock: 2));
        $this->assertSame(0, OperationalCostReturnDecision::count());
    }

    public function test_a_decision_needs_at_least_one_unit_and_never_a_negative_one(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 2]);

        $this->assertRefused('nothing_to_decide', fn () => $this->decide($line, 'claim:CLM-1', restock: 0, writeOff: 0));
        $this->assertRefused('nothing_to_decide', fn () => $this->decide($line, 'claim:CLM-1', restock: -1, writeOff: 3));
    }

    // ---- state of the line and the audit ----------------------------------------------------------------------------------

    public function test_the_line_says_what_awaits_a_decision_and_stops_saying_it_once_decided(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 2]);
        $this->assertSame(['claim:CLM-1' => 2], $this->returns->standing($line)['awaiting']);
        $this->assertSame([$line->id], ExternalOrderLine::awaitingReturnDecision()->pluck('id')->all());

        $this->decide($line, 'claim:CLM-1', restock: 1, writeOff: 1);

        $this->assertSame([], $this->returns->standing($line->fresh())['awaiting']);
        $this->assertSame([], ExternalOrderLine::awaitingReturnDecision()->pluck('id')->all());
    }

    public function test_a_line_with_nothing_returned_never_awaits_anything(): void
    {
        $line = $this->soldLine();

        $this->assertSame([], ExternalOrderLine::awaitingReturnDecision()->pluck('id')->all());
        $this->assertSame([], $this->returns->standing($line)['awaiting']);
        $this->assertTrue($this->returns->standing($line)['canRecordAReturn']);
    }

    public function test_each_decision_is_audited_with_who_made_it_what_and_where(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 3]);

        $this->decide($line, 'claim:CLM-1', restock: 2, writeOff: 1, admin: 1);

        $log = AuditLog::where('action', 'operational_cost.return_decided')->firstOrFail();
        $this->assertSame(ExternalOrderLine::class, $log->subject_type);
        $this->assertSame((int) $line->id, (int) $log->subject_id);
        $this->assertSame(['claim:CLM-1', 2, 1, $this->warehouse->id, false, 1], [
            $log->context['return_event'], $log->context['restocked'], $log->context['written_off'], $log->context['location_id'], $log->context['automatic'], $log->context['by'],
        ]);
    }

    public function test_the_profit_of_the_sale_follows_the_return_and_then_the_restock(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 1]);
        $mapping = $line->order;
        $profit = fn (): array => (function () use ($mapping): array {
            $result = app(ProfitabilityService::class)->forDocument(app(SaleDocumentReader::class)->forChannelOrder($mapping->id));

            return [$result->revenue->toStorage(), $result->actualCost->toStorage()];
        })();

        $this->assertSame(['200.000000', '30.000000'], $profit(), 'Revenue falls by the returned unit\'s share; its cost stays until it is decided.');

        $this->decide($line, 'claim:CLM-1', restock: 1);

        $this->assertSame(['200.000000', '20.000000'], $profit(), 'Put back on the shelf: its cost of goods is reversed.');
    }

    public function test_a_unit_written_off_keeps_its_cost_in_the_profit(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 1]);

        $this->decide($line, 'claim:CLM-1', restock: 0, writeOff: 1);

        $result = app(ProfitabilityService::class)->forDocument(app(SaleDocumentReader::class)->forChannelOrder($line->order->id));
        $this->assertSame(['200.000000', '30.000000'], [$result->revenue->toStorage(), $result->actualCost->toStorage()]);
    }

    // ---- the owner's automatic setting ------------------------------------------------------------------------------------

    public function test_the_setting_is_off_by_default_and_an_accepted_claim_waits_for_a_person(): void
    {
        $line = $this->soldLine();
        $this->claimIsAccepted();

        app(ChannelSyncService::class)->syncReturns($this->account);

        $this->assertFalse((bool) $this->account->setting('auto_restock_accepted_returns', false));
        $this->assertSame(2, $line->fresh()->quantity_returned);
        $this->assertSame(0, OperationalCostReturnDecision::count());
        $this->assertSame([$line->id], ExternalOrderLine::awaitingReturnDecision()->pluck('id')->all());
        $this->assertSame(7, $this->onShelf());
    }

    public function test_switched_on_the_units_of_an_accepted_claim_go_back_by_themselves_once_and_marked_automatic(): void
    {
        $line = $this->soldLine();
        $this->autoRestock(true);
        $this->claimIsAccepted();

        app(ChannelSyncService::class)->syncReturns($this->account);
        $this->travel(40)->minutes();
        app(ChannelSyncService::class)->syncReturns($this->account);

        $decision = OperationalCostReturnDecision::firstOrFail();
        $this->assertSame(1, OperationalCostReturnDecision::count());
        $this->assertSame(['claim:claim-1', 2, 0, true, null, $this->warehouse->id], [
            $decision->return_event, $decision->units_restocked, $decision->units_written_off, $decision->automatic, $decision->decided_by, $decision->inventory_location_id,
        ]);
        $this->assertSame(9, $this->onShelf());
        $this->assertSame('10.000000', $this->cogsNet($line));
        $this->assertSame([], ExternalOrderLine::awaitingReturnDecision()->pluck('id')->all());
        $this->assertTrue((bool) AuditLog::where('action', 'operational_cost.return_decided')->firstOrFail()->context['automatic']);
    }

    public function test_a_claim_the_channel_has_not_accepted_puts_nothing_back_even_when_the_setting_is_on(): void
    {
        $line = $this->soldLine();
        $this->autoRestock(true);
        $this->claimIsAccepted('Rejected');

        app(ChannelSyncService::class)->syncReturns($this->account);

        $this->assertSame(0, $line->fresh()->quantity_returned);
        $this->assertSame(0, OperationalCostReturnDecision::count());
    }

    public function test_reading_returns_goes_on_when_the_setting_is_on_and_a_claim_cannot_be_decided(): void
    {
        $line = $this->soldLine();
        $this->autoRestock(true);
        Http::fake([self::TY . '/order/sellers/1234567/claims*' => Http::response(Trendyol::claimsPage([Trendyol::claim(['Accepted'], str_repeat('c', 200)), Trendyol::claim(['Accepted'], 'claim-2')]))]);

        $result = app(ChannelSyncService::class)->syncReturns($this->account);

        $this->assertSame('ok', $result['result']);
        $this->assertSame(2, $line->fresh()->quantity_returned, 'Both claims are recorded on the line.');
        $this->assertSame(['claim:claim-2'], OperationalCostReturnDecision::pluck('return_event')->all(), 'The claim that fits is put back; the other waits for a person.');
    }

    public function test_the_setting_does_nothing_while_the_cost_module_is_switched_off(): void
    {
        $line = $this->soldLine();
        $this->autoRestock(true);
        $this->claimIsAccepted();
        config(['operational_costs.enabled' => false]);

        app(ChannelSyncService::class)->syncReturns($this->account);

        $this->assertSame(2, $line->fresh()->quantity_returned, 'The return is still recorded on the line.');
        $this->assertSame(0, OperationalCostReturnDecision::count(), 'Nothing starts by itself while the module is off.');
    }

    public function test_a_claim_whose_goods_were_not_yet_recognised_stays_on_the_list_and_is_put_back_when_the_cost_is_recognised_and_the_claim_read_again(): void
    {
        config(['operational_costs.enabled' => false]);
        $line = $this->soldLine();
        $this->autoRestock(true);
        $this->claimIsAccepted();
        app(ChannelSyncService::class)->syncReturns($this->account);
        $this->assertSame(0, OperationalCostReturnDecision::count(), 'Off: nothing was taken from the layers, and nothing was put back.');

        config(['operational_costs.enabled' => true]);
        app(SaleCostService::class)->refresh(app(SaleDocumentReader::class)->forChannelOrder($line->order->id));
        $this->travel(40)->minutes();
        app(ChannelSyncService::class)->syncReturns($this->account);

        $this->assertSame(1, OperationalCostReturnDecision::count());
        $this->assertSame(9, $this->onShelf());
    }

    public function test_the_automatic_setting_is_stored_per_account_and_defaults_to_off(): void
    {
        $this->assertFalse(ChannelSettings::defaults('trendyol')['auto_restock_accepted_returns']);
        $this->assertFalse(ChannelSettings::validate('trendyol', [])['auto_restock_accepted_returns']);
        $this->assertTrue(ChannelSettings::validate('trendyol', ['auto_restock_accepted_returns' => 'yes'])['auto_restock_accepted_returns']);
        $this->assertFalse(ChannelSettings::validate('trendyol', ['auto_restock_accepted_returns' => 'off'])['auto_restock_accepted_returns']);
        $this->assertArrayNotHasKey('auto_restock_accepted_returns', ChannelSettings::defaults('amazon'));
    }
}
