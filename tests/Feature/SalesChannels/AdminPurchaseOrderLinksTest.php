<?php

namespace Tests\Feature\SalesChannels;

use App\Models\InventoryCostLayer;
use App\Models\OperationalCostInvoice;
use App\Models\OperationalCostInvoiceItem;
use App\Models\OperationalCostReconciliation;
use App\Services\OperationalCosts\PurchaseOrders\PurchaseOrderLayerService;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsPlatformProcurementSchema;
use Tests\Support\CreatesOperationalCostData;

/** The admin screens of a purchase invoice that meets goods a purchase order received: the line number on the form, the held invoice, and the layer's source. The platform's tables are INFERRED. */
class AdminPurchaseOrderLinksTest extends AdminPagesTestCase
{
    use BuildsPlatformProcurementSchema;
    use CreatesOperationalCostData;

    private int $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlatformProcurementSchema();
        config(['fulfillment.currency' => 'SAR', 'operational_costs.enabled' => true, 'operational_costs.sources.purchase_orders' => 'on']);
        $this->location = app(InventoryLocationService::class)->ensureDefaults()[2]->id;
    }

    /** @return array{0: int, 1: int} a product and the id of its line, received in full and made into a layer from the order */
    private function layeredReceipt(): array
    {
        $product = $this->platformProduct();
        $order = $this->purchaseOrder($this->supplier('Acme Pharma Supply'), [[$product, 10, 12.5]]);
        $line = $this->receiveOnPlatform($order->items->first(), 10);
        app(PurchaseOrderLayerService::class)->apply(since: CarbonImmutable::parse('2000-01-01'));

        return [$product, $line->id];
    }

    /** @param array<string, mixed> $line */
    private function postInvoice(array $line, string $number = 'INV-PO-1'): TestResponse
    {
        return $this->post(route('admin.operational-costs.invoices.store'), [
            'kind' => 'purchase', 'party_type' => 'supplier', 'provider_code' => 'acme-pharma', 'party_name' => 'Acme Pharma Supply', 'invoice_number' => $number, 'invoice_date' => '2026-11-10',
            'currency' => 'SAR', 'total' => '125', 'inventory_location_id' => $this->location,
            'items' => [$line + ['cost_component' => 'cogs', 'amount' => '125', 'quantity' => '10']],
        ]);
    }

    public function test_a_purchase_line_can_name_the_purchase_order_line_it_bills_and_the_page_of_the_invoice_shows_the_link_and_what_approving_recorded(): void
    {
        [$product, $line] = $this->layeredReceipt();

        $this->postInvoice(['product_id' => $product, 'purchase_order_item_id' => $line]);

        $invoice = OperationalCostInvoice::sole();
        $this->assertSame($line, OperationalCostInvoiceItem::sole()->purchase_order_item_id);
        $page = $this->get(route('admin.operational-costs.invoices.show', $invoice->id))->assertOk()->getContent();
        $this->assertStringContainsString(e(translate('purchase_order_line')) . ' #' . $line, $page);
        $this->assertStringNotContainsString(e(translate('this_invoice_looks_like_goods_already_received')), $page);

        $this->post(route('admin.operational-costs.invoices.approve', $invoice->id))->assertRedirect();
        $this->assertSame([1, 'matched'], [InventoryCostLayer::count(), OperationalCostReconciliation::sole()->status]);
    }

    public function test_a_line_nobody_linked_that_looks_like_goods_already_received_holds_the_invoice_and_one_press_of_a_button_says_it_is_a_separate_purchase(): void
    {
        [$product] = $this->layeredReceipt();

        $this->postInvoice(['product_id' => $product]);

        $this->assertContains(translate('invoice_saved_but_looks_like_goods_already_received'), $this->flashedToasts());
        $invoice = OperationalCostInvoice::sole();
        $this->assertSame(OperationalCostInvoice::STATUS_UNDER_REVIEW, $invoice->status);
        $show = route('admin.operational-costs.invoices.show', $invoice->id);
        $page = $this->get($show)->assertOk()->getContent();
        $this->assertStringContainsString(e(translate('this_invoice_looks_like_goods_already_received')), $page);
        $this->assertStringContainsString(e(translate('this_is_a_separate_purchase')), $page);
        $this->assertStringContainsString(route('admin.operational-costs.invoices.not-duplicate', $invoice->id), $page);

        $this->from($show)->post(route('admin.operational-costs.invoices.approve', $invoice->id));
        $this->assertSame(OperationalCostInvoice::STATUS_UNDER_REVIEW, $invoice->fresh()->status, 'Held: nothing was approved.');

        $this->post(route('admin.operational-costs.invoices.not-duplicate', $invoice->id))->assertRedirect();
        $this->assertStringNotContainsString(e(translate('this_invoice_looks_like_goods_already_received')), $this->get($show)->getContent());
        $this->post(route('admin.operational-costs.invoices.approve', $invoice->id));
        $this->assertSame(OperationalCostInvoice::STATUS_APPROVED, $invoice->fresh()->status);
    }

    public function test_a_link_the_server_cannot_honour_is_refused_with_its_reason_and_saves_nothing(): void
    {
        [$product] = $this->layeredReceipt();

        $this->postInvoice(['product_id' => $product, 'purchase_order_item_id' => 99999]);

        $this->assertSame(0, OperationalCostInvoice::count());
        $this->postInvoice(['product_id' => $product, 'purchase_order_item_id' => 'x'])->assertSessionHasErrors('items.0.purchase_order_item_id');
        $this->assertSame(0, OperationalCostInvoice::count());
    }

    public function test_a_layer_made_from_a_purchase_order_is_called_one_on_the_screens_that_list_layers(): void
    {
        $this->layeredReceipt();
        $layer = InventoryCostLayer::sole();

        $list = $this->get(route('admin.operational-costs.product-costs.index'))->assertOk()->getContent();
        $detail = $this->get(route('admin.operational-costs.product-costs.show', $layer->id))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('layer_source_purchase_order')), $list);
        $this->assertStringContainsString(e(translate('layer_source_purchase_order')) . ' · ' . e($layer->source_ref) . ' · Acme Pharma Supply', $detail, 'The order, and the supplier as it was named when the goods came in.');
        $this->assertSame('Purchase order', translate('layer_source_purchase_order'));
    }
}
