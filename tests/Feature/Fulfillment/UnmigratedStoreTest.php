<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Admin;
use App\Services\Fulfillment\FulfillmentHooks;
use App\Services\Fulfillment\FulfillmentOrderGuard;
use App\Services\Fulfillment\FulfillmentSchema;
use App\Services\Fulfillment\MixedProviderNotice;
use App\Services\Fulfillment\OrderItemOwnershipSnapshotter;
use App\Services\Fulfillment\CheckoutCodGuard;
use App\Services\Fulfillment\CustomerFulfillmentPresenter;
use App\Services\Fulfillment\ProductFormProviderSelection;
use App\Services\Fulfillment\Readiness\FulfillmentPreflight;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

/**
 * The code is deployed first and migrated later, after the read-only checks have been read. In between, the platform must behave exactly
 * as it did before: nothing scheduled may fail, no checkout, order, product or customer page may notice the integration, and the
 * Integration Center must say what is missing instead of failing on a table that does not exist.
 */
class UnmigratedStoreTest extends AdminPagesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_reverse(FulfillmentPreflight::MIGRATIONS) as $migration) {
            (require database_path("migrations/{$migration}.php"))->down();
        }
        FulfillmentSchema::flush();
        Queue::fake();
        Http::fake();
    }

    public function test_the_arrangement_really_is_a_store_without_the_new_tables_and_column(): void
    {
        $this->assertFalse(FulfillmentSchema::ready());
        $this->assertFalse(FulfillmentSchema::productColumnReady());
        $this->assertFalse(Schema::hasTable('provider_fulfillments'));
        $this->assertFalse(Schema::hasColumn('products', 'fulfillment_provider'));
        $this->assertTrue(Schema::hasTable('integration_settings'), 'the platform\'s own settings table is not ours to drop');
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function scheduledCommands(): array
    {
        return [
            'the per-minute sweep' => ['fulfillment:recover', []],
            'the status poll' => ['fulfillment:poll', []],
            'the product and stock sync' => ['fulfillment:sync', ['--only' => 'products,inventory', '--due' => true]],
            'the city sync' => ['fulfillment:sync', ['--only' => 'cities']],
            'the log pruning' => ['fulfillment:prune', []],
        ];
    }

    /**
     * @dataProvider scheduledCommands
     * @param array<string, mixed> $arguments
     */
    public function test_every_scheduled_command_does_nothing_and_succeeds_whatever_the_kill_switch_says(string $command, array $arguments): void
    {
        foreach ([false, true] as $switchedOn) {
            config(['fulfillment.enabled' => $switchedOn]);

            $this->assertSame(0, Artisan::call($command, $arguments), "{$command} with integrations " . ($switchedOn ? 'on' : 'off'));
        }
        Http::assertNothingSent();
    }

    public function test_the_commands_say_why_they_did_nothing(): void
    {
        config(['fulfillment.enabled' => true]);

        foreach (['fulfillment:recover', 'fulfillment:poll', 'fulfillment:sync', 'fulfillment:prune'] as $command) {
            $this->artisan($command)->expectsOutputToContain('migrations have not been run')->assertExitCode(0);
        }
    }

    public function test_the_preflight_reports_not_migrated_without_a_failure(): void
    {
        config(['fulfillment.enabled' => false, 'fulfillment.external_writes_enabled' => false, 'queue.default' => 'sync']);

        $this->artisan('fulfillment:preflight', ['--allow-production-read-only' => true])->expectsOutputToContain('not migrated yet')->assertExitCode(0);
    }

    public function test_the_checkout_and_status_hooks_do_nothing_and_never_throw(): void
    {
        $hooks = app(FulfillmentHooks::class);

        $hooks->ordersPlaced([100, 101]);
        $hooks->orderChanged(100, ['order_status' => 'canceled', 'payment_status' => 'paid']);

        Queue::assertNothingPushed();
    }

    public function test_a_product_line_is_captured_as_in_house_when_the_column_does_not_exist(): void
    {
        $captured = app(OrderItemOwnershipSnapshotter::class)->capture(
            orderId: 100, orderDetailId: 1, product: ['id' => 1, 'name' => 'Existing product', 'code' => 'SKU1'], variant: null,
            quantity: 1, unitPrice: 10.0, netAmount: 10.0, lineTax: 0.0, taxModel: 'exclude',
        );

        $this->assertNull($captured, 'an existing product writes nothing, exactly as before');
    }

    public function test_no_order_rule_blocks_or_changes_anything(): void
    {
        $guard = app(FulfillmentOrderGuard::class);

        $this->assertNull($guard->statusChangeBlockedReason(orderId: 100, newStatus: 'delivered'));
        $this->assertNull($guard->deliveryBlockedReason(100));
        $this->assertNull($guard->editBlockedReason(100));
        $this->assertNull(app(CheckoutCodGuard::class)->blockedReason(['group-1']));
        $this->assertNull(app(MixedProviderNotice::class)->forProducts([1, 2]));
        $this->assertNull(app(CustomerFulfillmentPresenter::class)->forOrder(100));
    }

    public function test_saving_a_product_with_a_provider_field_is_a_no_op_before_the_column_exists(): void
    {
        $request = Request::create('/admin/products/store', 'POST', [ProductFormProviderSelection::FIELD => 'm5azn']);

        $this->assertNull(app(ProductFormProviderSelection::class)->apply($request, 1));
    }

    public function test_the_store_pages_the_release_touched_render_as_they_did_before(): void
    {
        $this->assertSame('', trim(view('admin-views.order._fulfillments-card', ['order' => ['id' => 100, 'order_status' => 'pending']])->render()));
        $this->assertSame('', trim(view('admin-views.partials._fulfillment-provider')->render()));
        $this->assertSame('', trim(view('admin-views.partials._fulfillment-provider', ['product' => (object) ['id' => 1, 'added_by' => 'admin']])->render()));
        $this->assertSame('', trim(View::make('web-views.partials._fulfillment-shipments', ['orderId' => 100])->render()));
        $this->assertSame('', trim(View::make('web-views.partials._mixed-provider-notice', ['cartProductIds' => [1, 2]])->render()));
    }

    public function test_the_integration_center_explains_itself_instead_of_failing(): void
    {
        $this->actingAs(Admin::findOrFail(1), 'admin');

        foreach ([
            route('admin.fulfillment-providers.index'),
            route('admin.fulfillment-providers.show', 'm5azn'),
            route('admin.fulfillment-providers.show', 'torod-point'),
            route('admin.fulfillment-providers.fulfillments.index'),
            route('admin.fulfillment-providers.logs'),
        ] as $url) {
            $html = $this->get($url)->assertStatus(503)->getContent();

            $this->assertStringContainsString(e(translate('fulfillment_not_migrated')), $html, $url);
            foreach (FulfillmentPreflight::MIGRATIONS as $migration) {
                $this->assertStringContainsString("--path=database/migrations/{$migration}.php", $html, $url);
            }
        }
    }

    public function test_nothing_can_be_saved_through_the_integration_center_before_the_migration(): void
    {
        $before = DB::table('integration_settings')->where('provider', 'm5azn')->first();

        $this->post(route('admin.fulfillment-providers.update', 'm5azn'), ['enabled' => 0, 'dispatch_enabled' => 0, 'auto_dispatch' => 0, 'dry_run' => 1, 'cod_verified' => 1])
            ->assertStatus(503);

        $this->assertEquals($before, DB::table('integration_settings')->where('provider', 'm5azn')->first(), 'the form was refused before it could change anything');
    }

    public function test_once_the_migrations_have_run_the_center_works_again(): void
    {
        foreach (FulfillmentPreflight::MIGRATIONS as $migration) {
            (require database_path("migrations/{$migration}.php"))->up();
        }
        FulfillmentSchema::flush();

        $this->get(route('admin.fulfillment-providers.index'))->assertOk();
    }
}
