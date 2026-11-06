<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Http\Controllers\Admin\Product\ProductController;
use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductProviderChange;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\OrderEligibility;
use App\Services\Fulfillment\ProductFormProviderSelection;
use App\Services\Fulfillment\ProductFulfillmentPanel;
use App\Services\Fulfillment\ProductProviderAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;

/**
 * The only door through which a product's fulfillment provider changes, and the form that knocks on it.
 */
class ProductAssignmentTest extends FulfillmentTestCase
{
    private function assignments(): ProductProviderAssignmentService
    {
        return app(ProductProviderAssignmentService::class);
    }

    private function approveMapping(int $productId, ProviderCode $provider, string $variantKey = '', MappingStatus $status = MappingStatus::Approved): ProviderProductMapping
    {
        return ProviderProductMapping::create([
            'provider' => $provider->value, 'product_id' => $productId, 'variant_key' => $variantKey,
            'external_id' => 'EXT-' . $productId . '-' . $variantKey, 'external_variant_id' => $variantKey, 'status' => $status,
        ]);
    }

    private function providerOf(int $productId): ?string
    {
        return DB::table('products')->where('id', $productId)->value('fulfillment_provider');
    }

    /** A paid order whose single line is owned by the provider, submitted and therefore in flight. */
    private function inFlightOrder(int $orderId, int $productId, ProviderCode $provider, FulfillmentStatus $status = FulfillmentStatus::Submitted): ProviderFulfillment
    {
        $this->makeOrder($orderId);
        $this->addLine($orderId, $productId, 1, 50, $provider);
        $fulfillment = ProviderFulfillment::create([
            'reference' => $orderId . '-' . $provider->referenceCode() . '1', 'order_id' => $orderId, 'provider' => $provider->value, 'generation' => 1,
            'idempotency_key' => hash('sha256', $orderId . $provider->value), 'status' => $status,
        ]);
        ProviderFulfillmentItem::where('order_id', $orderId)->update(['provider_fulfillment_id' => $fulfillment->id, 'status' => ProviderFulfillmentItem::STATUS_ROUTED]);

        return $fulfillment;
    }

    public function test_a_provider_needs_an_approved_mapping(): void
    {
        $this->makeProduct(1);

        $result = $this->assignments()->assign(1, 'torod_point', 'admin', 1);

        $this->assertFalse($result->ok);
        $this->assertSame('fulfillment_mapping_required', $result->reasonKey);
        $this->assertNull($this->providerOf(1));
        $this->assertSame(0, ProductProviderChange::count());
    }

    public function test_pending_and_retired_mappings_do_not_count(): void
    {
        $this->makeProduct(1);
        $this->approveMapping(1, ProviderCode::TorodPoint, status: MappingStatus::Pending);
        $this->assertSame('fulfillment_mapping_required', $this->assignments()->assign(1, 'torod_point', 'admin', 1)->reasonKey);

        ProviderProductMapping::query()->update(['status' => MappingStatus::Retired]);
        $this->assertSame('fulfillment_mapping_required', $this->assignments()->assign(1, 'torod_point', 'admin', 1)->reasonKey);
    }

    public function test_a_mapping_with_the_other_provider_does_not_let_a_product_into_this_one(): void
    {
        $this->makeProduct(1);
        $this->approveMapping(1, ProviderCode::M5azn);

        $this->assertFalse($this->assignments()->assign(1, 'torod_point', 'admin', 1)->ok);
        $this->assertNull($this->providerOf(1));
    }

    public function test_an_approved_mapping_allows_the_assignment_and_records_who_changed_what_and_why(): void
    {
        $this->makeProduct(1);
        $this->approveMapping(1, ProviderCode::TorodPoint);

        $result = $this->assignments()->assign(1, 'torod_point', 'admin', 3, 'matched_to_warehouse_product');

        $this->assertTrue($result->ok);
        $this->assertSame('torod_point', $this->providerOf(1));
        $change = ProductProviderChange::sole();
        $this->assertSame([1, null, 'torod_point', 'admin', 3, 'matched_to_warehouse_product'], [
            $change->product_id, $change->from_provider, $change->to_provider, $change->actor_type, $change->actor_id, $change->reason,
        ]);
    }

    public function test_choosing_the_provider_a_product_already_has_changes_nothing(): void
    {
        $this->makeProduct(1, ProviderCode::TorodPoint);

        $this->assertTrue($this->assignments()->assign(1, 'torod_point', 'admin', 1)->ok);
        $this->assertTrue($this->assignments()->assign(1, 'default', 'admin', 1, 'x')->ok);
        $this->assertSame(1, ProductProviderChange::count(), 'only the real change (to default) is recorded');
    }

    public function test_default_clears_the_provider_without_needing_a_mapping(): void
    {
        $this->makeProduct(1, ProviderCode::M5azn);

        $this->assertTrue($this->assignments()->assign(1, 'default', 'admin', 1)->ok);
        $this->assertNull($this->providerOf(1));
        $this->assertSame('m5azn', ProductProviderChange::sole()->from_provider);
        $this->assertNull(ProductProviderChange::sole()->to_provider);
    }

    public function test_moving_between_two_providers_needs_the_new_providers_mapping(): void
    {
        $this->makeProduct(1, ProviderCode::M5azn);

        $this->assertSame('fulfillment_mapping_required', $this->assignments()->assign(1, 'torod_point', 'admin', 1)->reasonKey);
        $this->assertSame('m5azn', $this->providerOf(1), 'a refused move leaves the owner untouched');

        $this->approveMapping(1, ProviderCode::TorodPoint);
        $this->assertTrue($this->assignments()->assign(1, 'torod_point', 'admin', 1)->ok);
        $this->assertSame('torod_point', $this->providerOf(1));
    }

    public function test_a_product_with_variants_needs_every_variant_mapped(): void
    {
        $this->makeProduct(1, overrides: ['variation' => json_encode([['type' => 'Red-M', 'price' => 10, 'qty' => 5], ['type' => 'Blue-M', 'price' => 10, 'qty' => 5]])]);
        $this->approveMapping(1, ProviderCode::TorodPoint, 'Red-M');

        $refused = $this->assignments()->assign(1, 'torod_point', 'admin', 1);
        $this->assertFalse($refused->ok);
        $this->assertSame('Blue-M', $refused->detail, 'the unmapped variant is named so the admin knows what to match');

        $this->approveMapping(1, ProviderCode::TorodPoint, 'Blue-M');
        $this->assertTrue($this->assignments()->assign(1, 'torod_point', 'admin', 1)->ok);
    }

    public function test_a_whole_product_mapping_covers_every_variant(): void
    {
        $this->makeProduct(1, overrides: ['variation' => json_encode([['type' => 'Red-M'], ['type' => 'Blue-M']])]);
        $this->approveMapping(1, ProviderCode::TorodPoint, '');

        $this->assertTrue($this->assignments()->assign(1, 'torod_point', 'admin', 1)->ok);
    }

    public function test_vendor_and_digital_products_can_never_belong_to_a_provider(): void
    {
        $this->makeProduct(1, overrides: ['added_by' => 'seller']);
        $this->makeProduct(2, overrides: ['product_type' => 'digital']);
        $this->approveMapping(1, ProviderCode::TorodPoint);
        $this->approveMapping(2, ProviderCode::TorodPoint);

        $this->assertSame('fulfillment_vendor_product_not_allowed', $this->assignments()->assign(1, 'torod_point', 'admin', 1)->reasonKey);
        $this->assertSame('fulfillment_digital_product_not_allowed', $this->assignments()->assign(2, 'torod_point', 'admin', 1)->reasonKey);
    }

    public function test_unknown_providers_and_products_are_refused(): void
    {
        $this->makeProduct(1);

        $this->assertSame('fulfillment_provider_unknown', $this->assignments()->assign(1, 'dhl', 'admin', 1)->reasonKey);
        $this->assertSame('fulfillment_product_not_found', $this->assignments()->assign(99, 'm5azn', 'admin', 1)->reasonKey);
    }

    public function test_the_provider_cannot_change_while_an_order_is_in_flight_with_it(): void
    {
        $this->makeProduct(1, ProviderCode::TorodPoint);
        $this->approveMapping(1, ProviderCode::M5azn);
        $fulfillment = $this->inFlightOrder(100, 1, ProviderCode::TorodPoint);

        $toOther = $this->assignments()->assign(1, 'm5azn', 'admin', 1);
        $toDefault = $this->assignments()->assign(1, 'default', 'admin', 1);

        $this->assertSame('fulfillment_active_operations_exist', $toOther->reasonKey);
        $this->assertSame('fulfillment_active_operations_exist', $toDefault->reasonKey);
        $this->assertSame('torod_point', $this->providerOf(1));

        $fulfillment->forceFill(['status' => FulfillmentStatus::Delivered])->save();

        $this->assertTrue($this->assignments()->assign(1, 'm5azn', 'admin', 1)->ok, 'finished work no longer pins the product');
    }

    public function test_an_open_order_whose_line_is_not_routed_yet_also_blocks_the_change(): void
    {
        $this->makeProduct(1, ProviderCode::TorodPoint);
        $this->makeOrder(100, ['order_status' => 'confirmed']);
        $this->addLine(100, 1, 1, 50, ProviderCode::TorodPoint);

        $this->assertSame('fulfillment_active_operations_exist', $this->assignments()->assign(1, 'default', 'admin', 1)->reasonKey);
    }

    public function test_a_closed_order_does_not_block_the_change(): void
    {
        $this->makeProduct(1, ProviderCode::TorodPoint);
        $this->makeOrder(100, ['order_status' => OrderEligibility::CLOSED_STATUSES[0]]);
        $this->addLine(100, 1, 1, 50, ProviderCode::TorodPoint);

        $this->assertTrue($this->assignments()->assign(1, 'default', 'admin', 1)->ok);
    }

    public function test_an_existing_order_keeps_the_provider_it_was_placed_with_after_the_product_moves(): void
    {
        $this->makeProduct(1, ProviderCode::TorodPoint);
        $this->makeOrder(100, ['order_status' => 'delivered']);
        $this->addLine(100, 1, 1, 50, ProviderCode::TorodPoint);

        $this->assertTrue($this->assignments()->assign(1, 'default', 'admin', 1)->ok);

        $this->assertSame('torod_point', ProviderFulfillmentItem::where('order_id', 100)->value('provider'), 'the snapshot is immutable');
    }

    public function test_the_form_for_a_new_product_offers_only_default(): void
    {
        $form = $this->assignments()->choicesFor(null);

        $this->assertSame('default', $form['current']);
        $byValue = collect($form['choices'])->keyBy('value');
        $this->assertTrue($byValue['default']['selectable']);
        foreach (['m5azn', 'torod_point'] as $provider) {
            $this->assertFalse($byValue[$provider]['selectable']);
            $this->assertSame('fulfillment_save_product_first', $byValue[$provider]['reason']);
        }
    }

    public function test_the_form_says_why_a_provider_cannot_be_picked(): void
    {
        $this->makeProduct(1);
        $this->approveMapping(1, ProviderCode::TorodPoint);

        $byValue = collect($this->assignments()->choicesFor(1)['choices'])->keyBy('value');

        $this->assertTrue($byValue['default']['selectable']);
        $this->assertTrue($byValue['torod_point']['selectable']);
        $this->assertFalse($byValue['m5azn']['selectable']);
        $this->assertSame('fulfillment_mapping_required', $byValue['m5azn']['reason']);
    }

    public function test_the_form_locks_a_product_with_work_in_flight_but_keeps_its_current_choice(): void
    {
        $this->makeProduct(1, ProviderCode::TorodPoint);
        $this->approveMapping(1, ProviderCode::M5azn);
        $this->inFlightOrder(100, 1, ProviderCode::TorodPoint);

        $form = $this->assignments()->choicesFor(1);
        $byValue = collect($form['choices'])->keyBy('value');

        $this->assertSame('torod_point', $form['current']);
        $this->assertTrue($byValue['torod_point']['selectable'], 'staying put is always allowed');
        foreach (['default', 'm5azn'] as $value) {
            $this->assertFalse($byValue[$value]['selectable']);
            $this->assertSame('fulfillment_active_operations_exist', $byValue[$value]['reason']);
        }
    }

    public function test_the_form_and_the_rule_never_disagree(): void
    {
        $this->makeProduct(1, ProviderCode::M5azn);
        $this->approveMapping(1, ProviderCode::TorodPoint);
        $this->makeProduct(2, overrides: ['added_by' => 'seller']);
        $this->makeProduct(3);

        foreach ([1, 2, 3] as $productId) {
            foreach ($this->assignments()->choicesFor($productId)['choices'] as $choice) {
                $before = $this->providerOf($productId);
                $result = $this->assignments()->assign($productId, $choice['value'], 'admin', 1);

                $this->assertSame($choice['selectable'], $result->ok, "product {$productId} → {$choice['value']}");
                DB::table('products')->where('id', $productId)->update(['fulfillment_provider' => $before]);
            }
        }
    }

    public function test_the_panel_lists_links_and_the_last_changes(): void
    {
        $this->makeProduct(1);
        $this->approveMapping(1, ProviderCode::TorodPoint);
        $this->assignments()->assign(1, 'torod_point', 'admin', 1);

        $panel = app(ProductFulfillmentPanel::class)->for(1);

        $this->assertSame('torod_point', $panel['current']);
        $this->assertCount(1, $panel['mappings']);
        $this->assertCount(1, $panel['history']);
        $this->assertCount(0, app(ProductFulfillmentPanel::class)->for(null)['mappings']);
    }

    public function test_a_form_without_the_field_changes_nothing(): void
    {
        $this->makeProduct(1);
        $this->approveMapping(1, ProviderCode::TorodPoint);
        $this->actingAs(Admin::findOrFail(1), 'admin');

        $outcome = app(ProductFormProviderSelection::class)->apply(Request::create('/', 'POST', ['name' => 'x']), 1);

        $this->assertNull($outcome);
        $this->assertNull($this->providerOf(1));
    }

    public function test_an_admin_without_the_third_party_permission_cannot_change_the_provider(): void
    {
        $this->makeProduct(1);
        $this->approveMapping(1, ProviderCode::TorodPoint);
        $this->actingAs(Admin::findOrFail(2), 'admin');

        $outcome = app(ProductFormProviderSelection::class)->apply(Request::create('/', 'POST', ['fulfillment_provider' => 'torod_point']), 1);

        $this->assertNull($outcome);
        $this->assertNull($this->providerOf(1));
    }

    public function test_a_guest_cannot_change_the_provider(): void
    {
        $this->makeProduct(1);
        $this->approveMapping(1, ProviderCode::TorodPoint);

        $this->assertNull(app(ProductFormProviderSelection::class)->apply(Request::create('/', 'POST', ['fulfillment_provider' => 'torod_point']), 1));
        $this->assertNull($this->providerOf(1));
    }

    public function test_admins_with_the_permission_can_change_it_and_are_recorded_as_the_actor(): void
    {
        $this->makeProduct(1);
        $this->makeProduct(2);
        $this->approveMapping(1, ProviderCode::TorodPoint);
        $this->approveMapping(2, ProviderCode::TorodPoint);

        $this->actingAs(Admin::findOrFail(3), 'admin');
        $this->assertTrue(app(ProductFormProviderSelection::class)->apply(Request::create('/', 'POST', ['fulfillment_provider' => 'torod_point']), 1)->ok);
        $this->assertSame(3, ProductProviderChange::where('product_id', 1)->value('actor_id'));

        $this->actingAs(Admin::findOrFail(1), 'admin');
        $this->assertTrue(app(ProductFormProviderSelection::class)->apply(Request::create('/', 'POST', ['fulfillment_provider' => 'torod_point']), 2)->ok, 'a master admin has every module');
    }

    public function test_a_refused_choice_is_reported_not_thrown(): void
    {
        $this->makeProduct(1);
        $this->actingAs(Admin::findOrFail(1), 'admin');

        $outcome = app(ProductFormProviderSelection::class)->apply(Request::create('/', 'POST', ['fulfillment_provider' => 'torod_point']), 1);

        $this->assertFalse($outcome->ok);
        $this->assertSame('fulfillment_mapping_required', $outcome->reasonKey);
    }

    public function test_the_provider_column_cannot_be_mass_assigned(): void
    {
        $product = new Product();
        $product->fill(['fulfillment_provider' => 'torod_point']);

        $this->assertNull($product->fulfillment_provider, 'the form, an API payload or an import cannot set it by accident');
    }

    public function test_the_admin_product_controller_applies_the_selection_without_ever_failing_the_save(): void
    {
        $this->makeProduct(1);
        $this->makeProduct(2);
        $this->approveMapping(1, ProviderCode::TorodPoint);
        $this->actingAs(Admin::findOrFail(1), 'admin');

        $controller = (new ReflectionClass(ProductController::class))->newInstanceWithoutConstructor();
        $apply = new ReflectionMethod($controller, 'applyFulfillmentProviderSelection');

        $apply->invoke($controller, Request::create('/', 'POST', ['fulfillment_provider' => 'torod_point']), 1);
        $apply->invoke($controller, Request::create('/', 'POST', ['fulfillment_provider' => 'torod_point']), '2');

        $this->assertSame('torod_point', $this->providerOf(1));
        $this->assertNull($this->providerOf(2), 'a refusal leaves the product as it was and does not throw');
    }

    /**
     * add() and update() run only against the full installation schema, which is not available here, so the
     * wiring itself is asserted from the source: both actions call the same private hook.
     */
    public function test_both_the_create_and_the_update_action_call_the_selection_hook(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ProductController::class))->getFileName());

        foreach (['add', 'update'] as $action) {
            $method = new ReflectionMethod(ProductController::class, $action);
            $body = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

            $this->assertStringContainsString('applyFulfillmentProviderSelection(', $body, "{$action}() must apply the provider choice");
        }
        $this->assertStringContainsString('ToastMagic::warning($selection->message())', $source);
    }
}
