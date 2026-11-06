<?php

namespace Tests\Feature\SalesChannels;

use App\Models\InventoryCostLayer;
use App\Models\InventoryLocation;
use App\Services\OperationalCosts\Data\LayerReceipt;
use App\Services\OperationalCosts\InventoryCostLayerService;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesOperationalCostData;

/** Product costs: stock comes in as cost layers, a repeated submit records once, and every figure is a decimal that was typed and kept exactly. */
class AdminOperationalCostsProductCostsTest extends AdminPagesTestCase
{
    use CreatesOperationalCostData;

    private InventoryLocation $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warehouse = $this->stockLocation();
        foreach ([[50, 'Vitamin C 1000', 'VC-1', '6281000000001'], [51, 'Panadol Extra', 'PX-9', '6281000000002']] as [$id, $name, $code, $barcode]) {
            DB::table('products')->insert(['id' => $id, 'name' => $name, 'code' => $code, 'barcode' => $barcode, 'added_by' => 'admin', 'product_type' => 'physical']);
        }
    }

    /** @return array<string, mixed> */
    private function purchase(array $overrides = []): array
    {
        return $overrides + [
            'location_id' => $this->warehouse->id, 'product_id' => 50, 'quantity' => '10', 'unit_cost' => '4.5', 'currency' => 'SAR',
            'received_at' => '2026-11-01', 'source_type' => 'purchase_invoice', 'reference' => 'PI-100', 'supplier_name' => 'Supplier A',
        ];
    }

    private function layer(int $quantity = 10, string $cost = '4.5', string $reference = 'PI-1', int $product = 50, ?InventoryLocation $location = null, string $day = '2026-11-01'): InventoryCostLayer
    {
        return app(InventoryCostLayerService::class)->receive(new LayerReceipt(
            locationId: ($location ?? $this->warehouse)->id, productId: $product, quantity: $quantity, unitCost: Money::of($cost), currency: 'SAR',
            receivedAt: CarbonImmutable::parse($day), sourceType: 'purchase_invoice', identity: $reference, sourceRef: $reference, sellerId: (int) ($location ?? $this->warehouse)->seller_id,
        ));
    }

    public function test_the_page_says_a_purchase_is_stock_and_a_transfer_is_not_a_sale(): void
    {
        $html = $this->get(route('admin.operational-costs.product-costs.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('a_purchase_is_stock_not_an_expense')), $html);
        $this->assertStringContainsString(e(translate('a_purchase_is_stock_not_an_expense_help')), $html);
        $this->assertStringContainsString(e(translate('moving_stock_is_a_transfer_not_a_sale')), $html);
        $this->assertStringContainsString(e(translate('moving_stock_is_a_transfer_not_a_sale_help')), $html);
        $this->assertStringContainsString(e(translate('no_cost_layers_yet')), $html);
    }

    public function test_the_form_fills_in_no_cost_and_no_quantity(): void
    {
        $html = $this->get(route('admin.operational-costs.product-costs.index'))->assertOk()->getContent();

        foreach (['quantity', 'unit_cost', 'landed_unit_cost', 'product_id'] as $field) {
            $this->assertMatchesRegularExpression('/<input[^>]*name="' . $field . '"(?![^>]*value="[^"]+")[^>]*>/', $html, $field . ' starts empty.');
        }
        foreach (['purchase_invoice', 'manual', 'opening_balance', 'transfer'] as $source) {
            $this->assertStringContainsString('<option value="' . $source . '"', $html);
        }
    }

    public function test_a_purchase_cost_is_recorded_as_a_layer_with_the_figures_that_were_typed(): void
    {
        $response = $this->post(route('admin.operational-costs.product-costs.store'), $this->purchase(['landed_unit_cost' => '0.25', 'variant_key' => 'Blue-M']));

        $layer = InventoryCostLayer::firstOrFail();
        $response->assertRedirect(route('admin.operational-costs.product-costs.show', $layer->id));
        $this->assertSame($this->warehouse->id, $layer->inventory_location_id);
        $this->assertSame(50, $layer->product_id);
        $this->assertSame('Blue-M', $layer->variant_key);
        $this->assertSame(10, $layer->quantity_received);
        $this->assertSame(10, $layer->quantity_remaining);
        $this->assertSame('4.500000', $layer->unit_cost);
        $this->assertSame('0.250000', $layer->landed_unit_cost);
        $this->assertSame('SAR', $layer->currency);
        $this->assertSame('purchase_invoice', $layer->source_type);
        $this->assertSame('PI-100', $layer->source_ref);
        $this->assertSame('Supplier A', $layer->supplier_name);
        $this->assertSame('2026-11-01', $layer->received_at->toDateString());
        $this->assertSame(InventoryCostLayer::OPEN, $layer->status);
        $this->assertContains(translate('purchase_cost_recorded'), $this->flashedToasts());
    }

    public function test_the_same_submit_twice_records_once_and_says_nothing_was_changed(): void
    {
        $this->post(route('admin.operational-costs.product-costs.store'), $this->purchase());
        $this->post(route('admin.operational-costs.product-costs.store'), $this->purchase());

        $this->assertSame(1, InventoryCostLayer::count());
        $this->assertContains(translate('purchase_cost_already_recorded_nothing_changed'), $this->flashedToasts());
    }

    public function test_the_reference_is_the_identity_even_when_its_letters_are_typed_differently(): void
    {
        $this->post(route('admin.operational-costs.product-costs.store'), $this->purchase(['reference' => 'INV-77']));
        $this->post(route('admin.operational-costs.product-costs.store'), $this->purchase(['reference' => ' inv-77 ', 'quantity' => '99']));

        $this->assertSame(1, InventoryCostLayer::count());
        $this->assertSame(10, InventoryCostLayer::firstOrFail()->quantity_received, 'A layer is never rewritten: the second submit changed nothing.');
    }

    public function test_another_reference_another_product_or_another_location_is_another_layer(): void
    {
        $other = $this->stockLocation('Second warehouse');
        $this->post(route('admin.operational-costs.product-costs.store'), $this->purchase());
        $this->post(route('admin.operational-costs.product-costs.store'), $this->purchase(['reference' => 'PI-101']));
        $this->post(route('admin.operational-costs.product-costs.store'), $this->purchase(['product_id' => 51]));
        $this->post(route('admin.operational-costs.product-costs.store'), $this->purchase(['location_id' => $other->id]));

        $this->assertSame(4, InventoryCostLayer::count());
    }

    public function test_a_cost_that_is_not_a_plain_positive_decimal_is_refused(): void
    {
        $form = route('admin.operational-costs.product-costs.index');

        foreach (['abc', '1e2', '4,5', '-4', '0', '0.000', '', '4.1234567', '٤'] as $bad) {
            $this->from($form)->post(route('admin.operational-costs.product-costs.store'), $this->purchase(['unit_cost' => $bad]))
                ->assertRedirect($form)
                ->assertSessionHasErrors('unit_cost');
        }
        $this->from($form)->post(route('admin.operational-costs.product-costs.store'), $this->purchase(['landed_unit_cost' => 'two']))->assertSessionHasErrors('landed_unit_cost');
        $this->assertSame(0, InventoryCostLayer::count());
    }

    public function test_everything_else_in_the_form_is_checked_too(): void
    {
        $form = route('admin.operational-costs.product-costs.index');

        foreach ([
            'quantity' => ['0', '-1', '1.5', 'many', ''],
            'reference' => [''],
            'currency' => ['S', 'SAR1', ''],
            'received_at' => ['yesterday', '2026-13-01', ''],
            'source_type' => ['sale', ''],
            'location_id' => ['999', ''],
            'product_id' => ['999', '0', ''],
        ] as $field => $values) {
            foreach ($values as $value) {
                $this->from($form)->post(route('admin.operational-costs.product-costs.store'), $this->purchase([$field => $value]))->assertSessionHasErrors($field);
            }
        }

        $this->assertSame(0, InventoryCostLayer::count());
    }

    public function test_goods_paid_for_in_a_currency_with_no_known_rate_are_refused_with_the_reason(): void
    {
        $this->post(route('admin.operational-costs.product-costs.store'), $this->purchase(['currency' => 'EUR']));

        $this->assertSame(0, InventoryCostLayer::count());
        $this->assertContains('No exchange rate between EUR and SAR: set it before recording these goods.', $this->flashedToasts());
    }

    public function test_goods_paid_for_in_another_currency_keep_what_was_paid_and_the_rate_used(): void
    {
        $this->post(route('admin.operational-costs.product-costs.store'), $this->purchase(['currency' => 'USD', 'unit_cost' => '2']));

        $layer = InventoryCostLayer::firstOrFail();
        $this->assertSame('USD', $layer->original_currency);
        $this->assertSame('2.000000', $layer->original_unit_cost);
        $this->assertSame('SAR', $layer->currency);
        $this->assertSame('7.500000', $layer->unit_cost, '2 USD at the store\'s own rate of 3.75.');

        $html = $this->get(route('admin.operational-costs.product-costs.index'))->assertOk()->getContent();
        $this->assertStringContainsString('2.00 USD', $html);
        $this->assertStringContainsString('7.50 SAR', $html);
        $this->assertStringContainsString(e(translate('rate')) . ' 3.75', $html);
    }

    public function test_a_layer_takes_its_books_from_the_location_it_arrived_at(): void
    {
        $sellers = $this->stockLocation('Seller stock', 9);

        $this->post(route('admin.operational-costs.product-costs.store'), $this->purchase(['location_id' => $sellers->id]));

        $this->assertSame(9, InventoryCostLayer::firstOrFail()->seller_id);
    }

    public function test_the_list_shows_the_layers_with_the_stock_still_on_the_shelf_at_cost(): void
    {
        $this->layer(10, '4.5', 'PI-1');
        $second = $this->layer(5, '6', 'PI-2', 51, day: '2026-11-03');
        app(InventoryCostLayerService::class)->consume('order_line', 1, $this->warehouse->id, 50, '', 4, CarbonImmutable::parse('2026-11-05'));

        $html = $this->get(route('admin.operational-costs.product-costs.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Vitamin C 1000', $html);
        $this->assertStringContainsString('Panadol Extra', $html);
        $this->assertStringContainsString('6 / 10', $html, 'Six of ten units are left after a sale of four.');
        $this->assertStringContainsString('5 / 5', $html);
        $this->assertStringContainsString('4.50 SAR', $html);
        $this->assertStringContainsString(e(route('admin.operational-costs.product-costs.show', $second->id)), $html);
        $this->assertStringContainsString('57.00 SAR', $html, 'Stock at cost: 6 x 4.50 + 5 x 6.00.');
        $this->assertStringContainsString('>11<', $html, 'Eleven units are in stock.');
    }

    public function test_the_list_filters_by_location_product_reference_and_open_layers(): void
    {
        $other = $this->stockLocation('Second warehouse');
        $this->layer(10, '4.5', 'PI-A');
        $this->layer(3, '9', 'REF-Z', 51, $other);
        $depleted = $this->layer(2, '1', 'PI-D');
        app(InventoryCostLayerService::class)->consume('order_line', 5, $this->warehouse->id, 50, '', 12, CarbonImmutable::parse('2026-11-05'));

        $byLocation = $this->get(route('admin.operational-costs.product-costs.index', ['location' => $other->id]))->getContent();
        $this->assertStringContainsString('Panadol Extra', $byLocation);
        $this->assertStringNotContainsString('Vitamin C 1000', $byLocation);

        $byName = $this->get(route('admin.operational-costs.product-costs.index', ['q' => 'vitamin']))->getContent();
        $this->assertStringContainsString('Vitamin C 1000', $byName);
        $this->assertStringNotContainsString('Panadol Extra', $byName);

        $this->assertStringContainsString('Panadol Extra', $this->get(route('admin.operational-costs.product-costs.index', ['q' => 'REF-Z']))->getContent());
        $this->assertStringContainsString('Panadol Extra', $this->get(route('admin.operational-costs.product-costs.index', ['q' => '51']))->getContent());

        $open = $this->get(route('admin.operational-costs.product-costs.index', ['open' => 1]))->getContent();
        $this->assertStringContainsString('Panadol Extra', $open);
        $this->assertStringNotContainsString(e(route('admin.operational-costs.product-costs.show', $depleted->id)), $open, 'A layer with nothing left is not open.');
        $this->get(route('admin.operational-costs.product-costs.index', ['q' => ['x'], 'location' => ['y'], 'open' => ['z']]))->assertOk();
    }

    public function test_the_books_of_one_seller_are_listed_apart_from_the_platforms(): void
    {
        $sellers = $this->stockLocation('Seller stock', 9);
        $this->layer(10, '4.5', 'PI-PLATFORM');
        $this->layer(3, '8', 'PI-SELLER', 51, $sellers);

        $platform = $this->get(route('admin.operational-costs.product-costs.index'))->getContent();
        $this->assertStringContainsString('PI-PLATFORM', $platform);
        $this->assertStringNotContainsString('PI-SELLER', $platform);
        $this->assertStringContainsString('PI-SELLER', $this->get(route('admin.operational-costs.product-costs.index', ['seller' => 9]))->getContent());
        $everyone = $this->get(route('admin.operational-costs.product-costs.index', ['seller' => 'all']))->getContent();
        $this->assertStringContainsString('PI-PLATFORM', $everyone);
        $this->assertStringContainsString('PI-SELLER', $everyone);
    }

    public function test_a_layer_page_shows_what_was_taken_from_it_and_what_was_put_back(): void
    {
        $layer = $this->layer(10, '4.5', 'PI-1');
        $layers = app(InventoryCostLayerService::class);
        $layers->consume('order_line', 11, $this->warehouse->id, 50, '', 3, CarbonImmutable::parse('2026-11-05'));
        $layers->consume('order_line', 12, $this->warehouse->id, 50, '', 2, CarbonImmutable::parse('2026-11-06'));
        $layers->reverse('order_line', 12);

        $html = $this->get(route('admin.operational-costs.product-costs.show', $layer->id))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('layer_status_open')), $html);
        $this->assertStringContainsString('7 / 10', $html);
        $this->assertStringContainsString('13.50 SAR', $html, 'Three units at 4.50.');
        $this->assertStringContainsString('9.00 SAR', $html, 'The reversed two units are listed with the cost they took.');
        $this->assertStringContainsString(e(translate('consumer_type_order_line')) . ' #11', $html);
        $this->assertStringContainsString(e(translate('put_back')), $html);
        $this->assertStringContainsString(e(translate('counted')), $html);
    }

    public function test_a_layer_nothing_was_taken_from_says_so(): void
    {
        $layer = $this->layer();

        $html = $this->get(route('admin.operational-costs.product-costs.show', $layer->id))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('nothing_has_been_taken_from_this_layer')), $html);
        $this->get(route('admin.operational-costs.product-costs.show', 999))->assertNotFound();
    }

    public function test_the_product_search_finds_physical_products_of_the_store_by_name_code_barcode_or_id(): void
    {
        DB::table('products')->insert(['id' => 60, 'name' => 'Vitamin C digital guide', 'code' => 'DG-1', 'added_by' => 'admin', 'product_type' => 'digital']);
        DB::table('products')->insert(['id' => 61, 'name' => 'Vitamin C of a seller', 'code' => 'SL-1', 'added_by' => 'seller', 'product_type' => 'physical']);
        $search = fn (string $term): array => $this->getJson(route('admin.operational-costs.product-costs.product-search', ['q' => $term]))->assertOk()->json();

        $this->assertSame([50], array_column($search('vitamin'), 'id'), 'Neither a digital product nor a seller\'s product is offered.');
        $this->assertSame([51], array_column($search('PX-9'), 'id'));
        $this->assertSame([51], array_column($search('6281000000002'), 'id'));
        $this->assertSame([50], array_column($search('50'), 'id'));
        $this->assertSame([], $search('no such product'));
        $this->assertSame(['id', 'name', 'code', 'barcode'], array_keys($search('vitamin')[0]));
    }
}
