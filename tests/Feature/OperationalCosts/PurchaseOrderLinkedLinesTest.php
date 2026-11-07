<?php

namespace Tests\Feature\OperationalCosts;

use App\Models\InventoryCostLayer;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostInvoice;
use App\Models\OperationalCostReconciliation;
use App\Services\OperationalCosts\Exceptions\InvalidContractChange;
use App\Services\OperationalCosts\Invoices\InvoiceResult;
use App\Services\OperationalCosts\Sales\SaleCostService;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsPurchaseOrderWorld;
use Tests\Support\CreatesOperationalCostData;
use Tests\TestCase;

/**
 * A linked invoice line makes no layer of its own, because the order's receipt is the layer. So the order line has to be able to make one: a link to a line that cannot (a cost of
 * zero, a product with variants, ...) is refused with the reason the layers give, when the invoice is saved and again when it is approved, instead of leaving the goods with no
 * layer at all. The platform's tables are INFERRED.
 */
class PurchaseOrderLinkedLinesTest extends TestCase
{
    use BuildsPurchaseOrderWorld;
    use CreatesOperationalCostData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00'));
        $this->setUpPurchaseOrderWorld();
    }

    /** @return array<string, array{0: array<string, mixed>, 1: float|null, 2: string|null, 3: string}> */
    public static function linesThatMakeNoLayer(): array
    {
        return [
            'a cost of zero, which is what the order form writes for a missing one' => [[], 0.0, 'SAR', 'cost_missing'],
            'a product with variants, which no order line can name' => [['variation' => [['type' => 'Red', 'qty' => 5]]], 12.5, 'SAR', 'variant_product'],
            'a product of a vendor' => [['added_by' => 'seller'], 12.5, 'SAR', 'vendor_product'],
            'a product that is not physical' => [['product_type' => 'digital'], 12.5, 'SAR', 'not_physical'],
            'an order with no currency' => [[], 12.5, null, 'currency_unknown'],
        ];
    }

    /**
     * @param array<string, mixed> $product
     */
    #[DataProvider('linesThatMakeNoLayer')]
    public function test_a_link_to_an_order_line_that_cannot_make_a_layer_is_refused_with_the_reason_and_nothing_is_saved(array $product, ?float $cost, ?string $currency, string $reason): void
    {
        [$productId, $line] = $this->placedLine(ordered: 10, cost: $cost, currency: $currency, product: $product);
        $line = $this->receiveOnPlatform($line, 10);
        $this->applyLayers();
        $this->assertSame(0, InventoryCostLayer::count(), 'The order line makes no layer.');

        try {
            $this->purchaseInvoice([['product_id' => $productId, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $line->id]]);
            $this->fail('The invoice was saved with a link that leaves the goods with no layer.');
        } catch (InvalidContractChange $refusal) {
            $this->assertStringContainsString("purchase order line {$line->id} cannot be made into a cost layer ({$reason}:", $refusal->getMessage());
            $this->assertStringContainsString('without the link', $refusal->getMessage());
        }

        $this->assertSame(0, OperationalCostInvoice::count());
    }

    public function test_the_goods_of_a_line_that_cannot_make_a_layer_get_one_from_the_invoice_entered_without_the_link_and_have_a_cost_when_they_are_sold(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 0);
        $this->receiveOnPlatform($line, 10);
        $this->applyLayers();

        $held = $this->purchaseInvoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00']]);
        $this->assertSame(InvoiceResult::RESEMBLES_RECEIPT, $held->result, 'Goods the order received: a person has to say it is a separate purchase.');
        $this->invoices()->markNotDuplicate($held->invoice, adminId: 1);
        $this->invoices()->approve($held->invoice->fresh(), adminId: 1);
        $this->storefrontSale(orderId: 985, productId: $product);
        app(SaleCostService::class)->refresh(app(SaleDocumentReader::class)->forOrder(985));

        $cost = OperationalCostEntry::where('subject_type', 'order_line')->where('product_id', $product)->where('cost_component', 'cogs')->where('is_effective', true)->sole();

        $this->assertSame(['actual', '12.500000'], [$cost->state->value, (string) $cost->amount]);
    }

    public function test_a_link_to_a_line_nothing_has_been_received_on_yet_is_accepted_because_the_order_will_make_the_layer(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);

        $result = $this->purchaseInvoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $line->id]]);
        $this->invoices()->approve($result->invoice, adminId: 1);

        $this->assertSame([InvoiceResult::CREATED, 0, 'matched'], [$result->result, InventoryCostLayer::count(), OperationalCostReconciliation::sole()->status]);
    }

    public function test_a_link_saved_while_the_line_could_make_a_layer_is_refused_at_approval_when_it_no_longer_can(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $line = $this->receiveOnPlatform($line, 10);
        $this->applyLayers();
        $invoice = $this->purchaseInvoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $line->id]])->invoice;
        DB::table('purchase_order_items')->where('id', $line->id)->update(['unit_cost' => 0]);

        try {
            $this->invoices()->approve($invoice, adminId: 1);
            $this->fail('The invoice was approved.');
        } catch (InvalidContractChange $refusal) {
            $this->assertStringContainsString('cost_missing', $refusal->getMessage());
        }

        $this->assertSame(OperationalCostInvoice::STATUS_DRAFT, $invoice->fresh()->status);
        $this->assertSame(0, OperationalCostReconciliation::count());
    }

    public function test_a_link_to_a_line_the_platform_has_deleted_since_is_refused_at_approval(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $invoice = $this->purchaseInvoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $line->id]])->invoice;
        DB::table('purchase_order_items')->where('id', $line->id)->delete();

        $this->expectException(InvalidContractChange::class);
        $this->expectExceptionMessage('is not there any more');
        $this->invoices()->approve($invoice, adminId: 1);
    }
}
