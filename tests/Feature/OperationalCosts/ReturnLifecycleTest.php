<?php

namespace Tests\Feature\OperationalCosts;

use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryCostLayer;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostReturnDecision;
use App\Services\OperationalCosts\Data\LayerReceipt;
use App\Services\OperationalCosts\InventoryCostLayerService;
use App\Services\OperationalCosts\Sales\SaleCostService;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Sync\ChannelSyncService;
use Carbon\CarbonImmutable;

/**
 * What happens to a return over time, which the first decision does not settle: a claim that GROWS after it was decided, two forms for the one parcel that came back, a sale that
 * is cancelled after some of its units were put back, and a restock that must give back exactly what the units cost. The figures are those of ReturnsTestCase: 3 units sold,
 * 30.00 of cost of goods, 10 units bought.
 */
class ReturnLifecycleTest extends ReturnsTestCase
{
    /** The claim `CLM-1` of the line now says `$units` units were accepted. */
    private function claimNowSays(ExternalOrderLine $line, int $units): ExternalOrderLine
    {
        return $this->acceptedReturn($line->fresh(), ['CLM-1' => $units]);
    }

    private function restockEntries(ExternalOrderLine $line): array
    {
        return OperationalCostEntry::where('subject_type', 'channel_order_line')->where('subject_id', $line->id)->where('cost_component', 'cogs')->where('is_effective', true)
            ->orderBy('id')->pluck('amount')->all();
    }

    // ---- a claim that grows after it was decided -------------------------------------------------------------------------------

    public function test_a_unit_the_channel_accepts_after_the_claim_was_decided_waits_for_a_decision_of_its_own(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 1]);
        $this->decide($line, 'claim:CLM-1', restock: 1);
        $this->assertSame([], $this->returns->standing($line->fresh())['awaiting']);

        $line = $this->claimNowSays($line, 2);

        $this->assertSame(['claim:CLM-1+1' => 1], $this->returns->standing($line)['awaiting'], 'The unit the claim gained, by the event that decides it.');
        $this->assertSame([$line->id], ExternalOrderLine::awaitingReturnDecision()->pluck('id')->all(), 'The line is on the worklist again, with a form to decide it.');

        $this->decide($line, 'claim:CLM-1+1', restock: 0, writeOff: 1);

        $this->assertSame([], $this->returns->standing($line->fresh())['awaiting']);
        $this->assertSame([], ExternalOrderLine::awaitingReturnDecision()->pluck('id')->all());
        $this->assertSame('20.000000', $this->cogsNet($line), '3 sold, 1 put back, 1 written off: the damaged unit\'s cost stays.');
        $this->assertSame(8, $this->onShelf());
    }

    public function test_the_follow_up_is_decided_once_and_for_exactly_the_units_the_claim_gained(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 1]);
        $first = $this->decide($line, 'claim:CLM-1', restock: 1);
        $line = $this->claimNowSays($line, 3);

        $this->assertRefused('claim_units_must_all_be_decided', fn () => $this->decide($line, 'claim:CLM-1+1', restock: 1));
        $this->assertRefused('unknown_return_event', fn () => $this->decide($line, 'claim:CLM-1+2', restock: 2), 'Only the event of the units decided so far.');
        $followUp = $this->decide($line, 'claim:CLM-1+1', restock: 2);
        $again = $this->decide($line->fresh(), 'claim:CLM-1+1', restock: 2);
        $firstAgain = $this->decide($line->fresh(), 'claim:CLM-1', restock: 1);

        $this->assertSame([false, false, true, true], [$first->alreadyDecided, $followUp->alreadyDecided, $again->alreadyDecided, $firstAgain->alreadyDecided]);
        $this->assertSame(2, OperationalCostReturnDecision::count());
        $this->assertSame(10, $this->onShelf(), 'All 3 units are back: 7 + 3.');
        $this->assertSame('0.000000', $this->cogsNet($line));
    }

    public function test_a_claim_that_grows_twice_has_a_follow_up_for_each_step(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 1]);
        $this->decide($line, 'claim:CLM-1', restock: 1);
        $line = $this->claimNowSays($line, 2);
        $this->decide($line, 'claim:CLM-1+1', restock: 1);

        $line = $this->claimNowSays($line, 3);

        $this->assertSame(['claim:CLM-1+2' => 1], $this->returns->standing($line)['awaiting']);
        $this->decide($line, 'claim:CLM-1+2', restock: 1);
        $this->assertSame(['claim:CLM-1', 'claim:CLM-1+1', 'claim:CLM-1+2'], OperationalCostReturnDecision::orderBy('id')->pluck('return_event')->all());
    }

    public function test_a_claim_that_shrinks_after_its_decision_asks_for_nothing_and_undoes_nothing(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 2]);
        $this->decide($line, 'claim:CLM-1', restock: 2);

        $line = $this->claimNowSays($line, 1);

        $this->assertSame([], $this->returns->standing($line)['awaiting']);
        $this->assertSame(1, OperationalCostReturnDecision::count(), 'Reversing a put-back is not built: the decision stands.');
        $this->assertSame(9, $this->onShelf());
        $line = $this->claimNowSays($line, 2);
        $this->assertSame([], $this->returns->standing($line)['awaiting'], 'It grows back to what was decided: nothing new.');
    }

    public function test_two_claims_on_one_line_each_grow_on_their_own(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 1, 'CLM-2' => 1]);
        $this->decide($line, 'claim:CLM-1', restock: 1);
        $this->decide($line->fresh(), 'claim:CLM-2', restock: 1);

        $line = $this->acceptedReturn($line->fresh(), ['CLM-1' => 2, 'CLM-2' => 1]);

        $this->assertSame(['claim:CLM-1+1' => 1], $this->returns->standing($line)['awaiting']);
        $line = $this->acceptedReturn($line->fresh(), ['CLM-1' => 2, 'CLM-2' => 2]);
        $this->assertSame(['claim:CLM-1+1' => 1], $this->returns->standing($line)['awaiting'], 'Only 3 units were sold: the second claim\'s extra unit has no unit left to be about.');
    }

    public function test_with_the_owner_setting_on_the_unit_a_claim_gains_later_goes_back_by_itself_once(): void
    {
        $line = $this->soldLine();
        $this->autoRestock(true);
        $sync = app(ChannelSyncService::class);

        $this->claimsAre(['Accepted', 'Created'], ['Accepted', 'Accepted']);
        $sync->syncReturns($this->account);
        $this->assertSame(['claim:claim-1'], OperationalCostReturnDecision::pluck('return_event')->all());
        $this->assertSame(8, $this->onShelf());

        $this->travel(40)->minutes();
        $sync->syncReturns($this->account);
        $this->travel(40)->minutes();
        $sync->syncReturns($this->account);

        $this->assertSame(['claim:claim-1', 'claim:claim-1+1'], OperationalCostReturnDecision::orderBy('id')->pluck('return_event')->all());
        $this->assertSame([true, true], OperationalCostReturnDecision::orderBy('id')->pluck('automatic')->map(fn ($automatic): bool => (bool) $automatic)->all());
        $this->assertSame(9, $this->onShelf());
        $this->assertSame('10.000000', $this->cogsNet($line));
    }

    public function test_with_the_setting_off_the_unit_a_claim_gains_later_waits_for_a_person(): void
    {
        $line = $this->soldLine();
        $sync = app(ChannelSyncService::class);
        $this->claimsAre(['Accepted', 'Created'], ['Accepted', 'Accepted']);
        $sync->syncReturns($this->account);
        $this->decide($line->fresh(), 'claim:claim-1', restock: 1);

        $this->travel(40)->minutes();
        $sync->syncReturns($this->account);

        $this->assertSame(['claim:claim-1+1' => 1], $this->returns->standing($line->fresh())['awaiting']);
        $this->assertSame(1, OperationalCostReturnDecision::count());
    }

    // ---- one parcel, two forms -------------------------------------------------------------------------------------------------

    public function test_two_forms_opened_at_the_same_moment_for_the_one_parcel_that_came_back_put_one_unit_back(): void
    {
        $line = $this->soldLine();
        $this->assertSame(7, $this->onShelf());
        $this->assertSame(0, $this->returns->standing($line)['decidedUnits'], 'Both forms are opened at position 0: nothing is decided yet.');

        $first = $this->decide($line, 'manual:0', restock: 1, admin: 1);
        $second = $this->decide($line->fresh(), 'manual:0', restock: 1, admin: 2);

        $this->assertSame([false, true], [$first->alreadyDecided, $second->alreadyDecided]);
        $this->assertSame(8, $this->onShelf(), 'One parcel came back: one unit goes back, not two.');
        $this->assertSame('20.000000', $this->cogsNet($line));
        $this->assertSame([1], OperationalCostReturnDecision::pluck('decided_by')->all(), 'The decision is the first admin\'s.');
    }

    public function test_a_form_opened_after_the_first_record_is_for_the_next_parcel(): void
    {
        $line = $this->soldLine();
        $this->decide($line, 'manual:0', restock: 1);
        $this->assertSame(1, $this->returns->standing($line->fresh())['decidedUnits']);

        $this->decide($line->fresh(), 'manual:1', restock: 1);

        $this->assertSame(9, $this->onShelf(), 'Two parcels, two units.');
        $this->assertSame(['manual:0', 'manual:1'], OperationalCostReturnDecision::orderBy('id')->pluck('return_event')->all());
    }

    public function test_a_form_at_a_position_the_line_is_not_at_is_refused_and_a_token_that_is_no_position_is_not_a_return(): void
    {
        $line = $this->soldLine();
        $this->decide($line, 'manual:0', restock: 1);

        $this->assertRefused('stale_form', fn () => $this->decide($line->fresh(), 'manual:5', restock: 1));
        $this->assertRefused('unknown_return_event', fn () => $this->decide($line->fresh(), 'manual:abc', restock: 1));
        $this->assertRefused('unknown_return_event', fn () => $this->decide($line->fresh(), 'manual:-1', restock: 1));
        $this->assertSame(1, OperationalCostReturnDecision::count());
        $this->assertSame(8, $this->onShelf());
    }

    // ---- a restock, then the sale is cancelled ---------------------------------------------------------------------------------

    private function cancel(ExternalOrderLine $line): void
    {
        ExternalOrderMapping::whereKey($line->mapping_id)->update(['status' => 'cancelled']);
        app(SaleCostService::class)->refresh(app(SaleDocumentReader::class)->forChannelOrder($line->mapping_id));
    }

    public function test_a_sale_cancelled_after_a_unit_was_put_back_leaves_the_stock_and_the_cost_as_if_it_had_never_been_sold(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 1]);
        $this->decide($line, 'claim:CLM-1', restock: 1);
        $this->assertSame([8, '20.000000'], [$this->onShelf(), $this->cogsNet($line)]);

        $this->cancel($line);

        $this->assertSame(10, $this->onShelf(), '10 bought, nothing sold: 10 on the shelf, not 11.');
        $this->assertSame('0.000000', $this->cogsNet($line), 'A cancelled sale costs nothing, not -10.');
        $layer = InventoryCostLayer::where('source_type', 'return')->firstOrFail();
        $this->assertSame([InventoryCostLayer::VOID, 0], [$layer->status, $layer->quantity_remaining], 'The unit that was put back is counted by the sale giving its units back.');
    }

    public function test_a_sale_cancelled_after_two_returns_were_put_back_takes_both_back(): void
    {
        $line = $this->soldLine();
        $this->decide($line, 'manual:0', restock: 1);
        $this->decide($line->fresh(), 'manual:1', restock: 2);
        $this->assertSame(10, $this->onShelf());
        $this->assertSame('0.000000', $this->cogsNet($line));

        $this->cancel($line);

        $this->assertSame(10, $this->onShelf(), 'The three units of the sale come back through the sale; the layers of the returns are taken off.');
        $this->assertSame('0.000000', $this->cogsNet($line));
        $this->assertSame(2, InventoryCostLayer::where('source_type', 'return')->where('status', InventoryCostLayer::VOID)->count());
    }

    public function test_cancelling_again_changes_nothing_and_nothing_more_can_be_put_back_on_the_cancelled_sale(): void
    {
        $line = $this->acceptedReturn($this->soldLine(), ['CLM-1' => 2]);
        $this->decide($line, 'claim:CLM-1', restock: 1, writeOff: 1);
        $this->cancel($line);
        $entries = OperationalCostEntry::count();

        $this->cancel($line);

        $this->assertSame($entries, OperationalCostEntry::count());
        $this->assertSame(10, $this->onShelf());
        $this->assertSame(0, $this->returns->standing($line->fresh())['restockable']);
        $this->assertRefused('no_cost_recognised', fn () => $this->decide($this->claimNowSays($line, 3), 'claim:CLM-1+2', restock: 1));
    }

    // ---- exactly what the units cost -------------------------------------------------------------------------------------------

    /** 3 units sold for 30.02 in all: 1 from a layer at 10.00 and 2 from a layer at 10.01 (10.006667 each on average, which does not divide). */
    private function awkwardSale(): ExternalOrderLine
    {
        $layers = app(InventoryCostLayerService::class);
        foreach ([[1, '10.00', 'a'], [5, '10.01', 'b']] as [$quantity, $cost, $identity]) {
            $layers->receive(new LayerReceipt(
                locationId: $this->warehouse->id, productId: 50, quantity: $quantity, unitCost: Money::of($cost), currency: 'SAR', receivedAt: CarbonImmutable::parse('2026-01-0' . ($identity === 'a' ? 1 : 2)),
                sourceType: 'purchase_invoice', identity: $identity,
            ));
        }
        $mapping = ExternalOrderMapping::create([
            'account_id' => $this->account->id, 'sales_channel' => 'trendyol', 'seller_id' => 0, 'external_order_id' => '555', 'external_package_id' => '1', 'status' => 'shipped', 'currency' => 'SAR',
            'prices_include_tax' => false, 'purchased_at' => '2026-11-05 09:00:00', 'order_total' => '300', 'items_total' => '300', 'fulfillment_mode' => 'merchant_managed',
        ]);
        $line = ExternalOrderLine::create([
            'mapping_id' => $mapping->id, 'external_line_id' => 'L1', 'sku' => 'SKU-1', 'title' => 'x', 'product_id' => 50, 'inventory_location_id' => $this->warehouse->id, 'quantity' => 3,
            'quantity_cancelled' => 0, 'line_total' => '300', 'discount_total' => '0', 'tax_total' => '0', 'currency' => 'SAR', 'status' => 'open',
        ]);
        app(SaleCostService::class)->refresh(app(SaleDocumentReader::class)->forChannelOrder($mapping->id));

        return $line->fresh();
    }

    public function test_putting_every_unit_back_reverses_exactly_what_they_cost_and_leaves_no_cost_of_goods_behind(): void
    {
        $line = $this->awkwardSale();
        $this->assertSame('30.020000', $this->cogsNet($line));

        $this->decide($this->acceptedReturn($line, ['CLM-1' => 3]), 'claim:CLM-1', restock: 3);

        $this->assertSame('0.000000', $this->cogsNet($line), 'Not -0.000001.');
    }

    public function test_putting_the_units_back_in_steps_reverses_the_same_total_as_all_at_once(): void
    {
        $line = $this->awkwardSale();

        $this->decide($line, 'manual:0', restock: 1);
        $this->assertSame('-10.006667', OperationalCostEntry::where('subject_id', $line->id)->where('cost_component', 'cogs')->where('amount', '<', 0)->orderBy('id')->firstOrFail()->amount, 'The first unit: a third of 30.02, rounded once.');
        $this->decide($line->fresh(), 'manual:1', restock: 2);

        $this->assertSame('0.000000', $this->cogsNet($line), 'The two steps add up to the 30.02 the units cost.');
        $this->assertSame(['-10.006667', '-20.013333'], OperationalCostEntry::where('subject_id', $line->id)->where('cost_component', 'cogs')->where('amount', '<', 0)->orderBy('id')->pluck('amount')->all());
    }

    public function test_the_units_put_back_in_a_second_step_are_costed_by_what_remains_not_by_a_new_average(): void
    {
        $line = $this->awkwardSale();

        $this->decide($line, 'manual:0', restock: 2);
        $this->decide($line->fresh(), 'manual:2', restock: 1);

        $this->assertSame('0.000000', $this->cogsNet($line));
        $this->assertSame(['-20.013333', '-10.006667'], OperationalCostEntry::where('subject_id', $line->id)->where('cost_component', 'cogs')->where('amount', '<', 0)->orderBy('id')->pluck('amount')->all());
    }
}
