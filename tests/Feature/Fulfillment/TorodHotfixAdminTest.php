<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Enums\Fulfillment\StockSource;
use App\Models\Admin;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\Providers\TorodPoint\TorodFieldMaps;
use App\Services\Fulfillment\Providers\TorodPoint\TorodInvoiceRoundTrip;
use App\Services\Fulfillment\Providers\TorodPoint\TorodPointAdapter;
use App\Services\Fulfillment\Providers\TorodPoint\TorodStockConfirmation;
use App\Services\Fulfillment\Support\ProviderConfig;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * The administrator's side of Hotfix 2: verifying the orders map of a warehouse whose historical orders have no invoice number, recording
 * (or withdrawing) the proof that the invoice number comes back, confirming the stock field for the path it is read from, and the
 * product's stock when the warehouse controls it.
 */
class TorodHotfixAdminTest extends AdminPagesTestCase
{
    private const V5 = 'https://apisv5.logestechs.com/api';
    private const EVIDENCE = 'LogesTechs support wrote on 2026-11-02 that invoiceNumber is stored exactly as sent.';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('audit_logs', static function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    private function config(): ProviderConfig
    {
        return app(ProviderConnectionManager::class)->config(ProviderCode::TorodPoint);
    }

    private function roundTrip(): TorodInvoiceRoundTrip
    {
        return app(TorodInvoiceRoundTrip::class);
    }

    /** @return list<array<string, mixed>> */
    private function historicalOrders(int $count = 5): array
    {
        return array_map(static fn (int $n): array => ['id' => 9000 + $n, 'barcode' => 'O00' . $n, 'invoiceNumber' => null, 'status' => 'DELIVERED'], range(1, $count));
    }

    private function saveOrdersMap(array $orders, bool $verify = true, array $paths = ['invoice_number' => 'invoiceNumber', 'reference' => 'barcode', 'status' => 'status']): void
    {
        Http::fake([self::V5 . '/public/fulfillment/order*' => Http::response(['data' => $orders])]);

        $this->post(route('admin.fulfillment-providers.field-map.save', ['torod-point', TorodFieldMaps::ORDERS]), ['paths' => $paths] + ($verify ? ['verify' => 1] : []));
    }

    /** @return string every toast the last response flashed, title and message together */
    private function toasts(): string
    {
        return (string) json_encode(session('laravel-toaster-magic::messages', []), JSON_UNESCAPED_UNICODE);
    }

    private function ordersMap(): \App\Services\Fulfillment\Support\StoredFieldMap
    {
        return app(FieldMapStore::class)->get(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS);
    }

    private function confirmationRequest(array $overrides = []): array
    {
        return $overrides + ['confirm' => 1, 'evidence' => self::EVIDENCE, 'invoice_path' => 'invoiceNumber'];
    }

    // ---- verifying the orders map ---------------------------------------------------------------------------------------------

    public function test_the_orders_map_of_historical_orders_verifies_and_the_toast_says_it_is_the_shape_only(): void
    {
        $this->saveOrdersMap($this->historicalOrders());

        $this->assertTrue($this->ordersMap()->verified);
        $this->assertSame(0, $this->ordersMap()->filledIn('invoice_number'));
        $this->assertStringContainsString(translate('field_map_verified_shape_only'), $this->toasts());
        $this->assertStringContainsString(translate('map_field_invoice_number'), $this->toasts());
    }

    public function test_a_sample_whose_invoice_numbers_are_filled_gets_the_plain_success(): void
    {
        $orders = array_map(static fn (array $order, int $n): array => ['invoiceNumber' => $n . '-TP1'] + $order, $this->historicalOrders(), range(1, 5));

        $this->saveOrdersMap($orders);

        $this->assertTrue($this->ordersMap()->verified);
        $this->assertStringContainsString(translate('field_map_verified'), $this->toasts());
        $this->assertStringNotContainsString(translate('field_map_verified_shape_only'), $this->toasts());
    }

    public function test_orders_without_a_barcode_do_not_verify_through_the_page_either(): void
    {
        $this->saveOrdersMap(array_map(static fn (array $order): array => ['barcode' => null] + $order, $this->historicalOrders()));

        $this->assertFalse($this->ordersMap()->verified);
        $this->assertStringContainsString(translate('field_map_not_verified'), $this->toasts());
    }

    public function test_a_path_that_is_not_in_the_orders_does_not_verify_through_the_page_either(): void
    {
        $this->saveOrdersMap($this->historicalOrders(), paths: ['invoice_number' => 'invoiceNo', 'reference' => 'barcode']);

        $this->assertFalse($this->ordersMap()->verified);
    }

    public function test_the_page_says_how_many_of_the_sampled_orders_carry_an_invoice_number(): void
    {
        $this->saveOrdersMap($this->historicalOrders());

        $html = $this->get(route('admin.fulfillment-providers.show', 'torod-point'))->assertOk()->getContent();

        $this->assertStringContainsString('0 / 5 ' . e(translate('sampled_records_have_a_value')), $html);
        $this->assertStringContainsString(e(translate('field_map_shape_only_help')), $html);
    }

    // ---- the round-trip confirmation ------------------------------------------------------------------------------------------------

    public function test_the_page_asks_the_master_administrator_for_the_confirmation_and_shows_nothing_to_anyone_else(): void
    {
        $this->saveOrdersMap($this->historicalOrders());

        $master = $this->get(route('admin.fulfillment-providers.show', 'torod-point'))->assertOk()->getContent();
        $this->assertStringContainsString(e(translate('invoice_round_trip_unconfirmed_notice')), $master);
        $this->assertStringContainsString('action="' . route('admin.fulfillment-providers.torod.invoice-round-trip.confirm') . '"', $master);
        $this->assertStringContainsString('name="evidence"', $master);

        $this->actingAs(Admin::findOrFail(3), 'admin');
        $other = $this->get(route('admin.fulfillment-providers.show', 'torod-point'))->assertOk()->getContent();
        $this->assertStringContainsString(e(translate('invoice_round_trip_unconfirmed_notice')), $other);
        $this->assertStringNotContainsString('name="evidence"', $other);
        $this->assertStringContainsString(e(translate('invoice_round_trip_master_admin_only')), $other);

        $m5azn = $this->get(route('admin.fulfillment-providers.show', 'm5azn'))->assertOk()->getContent();
        $this->assertStringNotContainsString(e(translate('invoice_round_trip_help')), $m5azn, 'only the warehouse has the question');
    }

    public function test_the_page_asks_for_the_path_first_when_the_orders_map_has_none(): void
    {
        $html = $this->get(route('admin.fulfillment-providers.show', 'torod-point'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('invoice_round_trip_needs_invoice_path')), $html);
        $this->assertStringNotContainsString('name="evidence"', $html);
    }

    public function test_the_master_administrator_records_the_confirmation_with_the_evidence_and_it_is_audited(): void
    {
        $this->saveOrdersMap($this->historicalOrders());

        $this->post(route('admin.fulfillment-providers.torod.invoice-round-trip.confirm'), $this->confirmationRequest())->assertSessionHasNoErrors();

        $record = $this->roundTrip()->confirmation($this->config());
        $this->assertSame(1, $record['confirmed_by']);
        $this->assertSame('invoiceNumber', $record['invoice_path']);
        $this->assertSame(self::EVIDENCE, $record['evidence']);

        $audit = DB::table('audit_logs')->where('action', 'integration.provider_settings_updated')->orderByDesc('id')->first();
        $this->assertStringContainsString('invoice_round_trip_confirmed', (string) $audit->context);
        $this->assertStringContainsString(self::EVIDENCE, (string) $audit->after, 'the evidence is part of the record');

        $html = $this->get(route('admin.fulfillment-providers.show', 'torod-point'))->assertOk()->getContent();
        $this->assertStringContainsString(e(self::EVIDENCE), $html);
        $this->assertStringContainsString(route('admin.fulfillment-providers.torod.invoice-round-trip.withdraw'), $html);
    }

    public function test_the_form_carries_the_path_the_statement_names(): void
    {
        $this->saveOrdersMap($this->historicalOrders());

        $html = $this->get(route('admin.fulfillment-providers.show', 'torod-point'))->assertOk()->getContent();

        $this->assertStringContainsString('name="invoice_path" value="invoiceNumber"', $html);
        $this->assertStringContainsString(e(translate('invoice_round_trip_evidence_help')), $html);
    }

    public function test_a_page_left_open_across_a_change_of_the_path_confirms_nothing(): void
    {
        $this->saveOrdersMap($this->historicalOrders());

        $this->saveOrdersMap($this->historicalOrders(), verify: false, paths: ['invoice_number' => 'externalRef', 'reference' => 'barcode']);
        $this->post(route('admin.fulfillment-providers.torod.invoice-round-trip.confirm'), $this->confirmationRequest(['invoice_path' => 'invoiceNumber']));

        $this->assertFalse($this->roundTrip()->isConfirmed($this->config()));
        $this->assertNull($this->config()->get('invoice_round_trip'), 'not even for the path that is set now');
        $this->assertStringContainsString(translate('invoice_round_trip_not_confirmed'), $this->toasts());

        $this->post(route('admin.fulfillment-providers.torod.invoice-round-trip.confirm'), ['confirm' => 1, 'evidence' => self::EVIDENCE]);
        $this->assertNull($this->config()->get('invoice_round_trip'), 'a request that does not say which path it is about confirms nothing');
    }

    public function test_changing_the_invoice_path_removes_the_confirmation_so_the_old_path_cannot_bring_it_back(): void
    {
        $this->saveOrdersMap($this->historicalOrders());
        $this->post(route('admin.fulfillment-providers.torod.invoice-round-trip.confirm'), $this->confirmationRequest());
        $this->assertTrue($this->roundTrip()->isConfirmed($this->config()));

        $this->saveOrdersMap($this->historicalOrders(), verify: false, paths: ['invoice_number' => 'externalRef', 'reference' => 'barcode']);
        $this->saveOrdersMap($this->historicalOrders(), verify: false);

        $this->assertNull($this->config()->get('invoice_round_trip'), 'the statement was about another path and is gone');
        $this->assertFalse($this->roundTrip()->isConfirmed($this->config()), 'the original path is back, and nobody has said anything about it again');
    }

    public function test_saving_the_map_without_changing_the_invoice_path_keeps_the_confirmation(): void
    {
        $this->saveOrdersMap($this->historicalOrders());
        $this->post(route('admin.fulfillment-providers.torod.invoice-round-trip.confirm'), $this->confirmationRequest());

        $this->saveOrdersMap($this->historicalOrders());

        $this->assertTrue($this->roundTrip()->isConfirmed($this->config()));
    }

    public function test_the_evidence_is_stored_without_pasted_contact_details(): void
    {
        $this->saveOrdersMap($this->historicalOrders());

        $this->post(route('admin.fulfillment-providers.torod.invoice-round-trip.confirm'), $this->confirmationRequest(['evidence' => 'Support (anna@logestechs.example) wrote on 2026-11-02 that it is stored as sent.']));

        $evidence = $this->roundTrip()->confirmation($this->config())['evidence'];
        $this->assertStringNotContainsString('anna@logestechs.example', $evidence);
        $this->assertStringContainsString('wrote on 2026-11-02', $evidence);
    }

    public function test_a_change_of_a_map_is_in_the_audit_log_with_the_paths_and_never_a_sample(): void
    {
        $this->saveOrdersMap($this->historicalOrders(), verify: false, paths: ['invoice_number' => 'invoiceNumber', 'reference' => 'barcode']);

        $audit = DB::table('audit_logs')->where('action', 'integration.provider_settings_updated')->orderByDesc('id')->first();
        $context = json_decode((string) $audit->context, true);

        $this->assertSame('orders', $context['field_map_saved']);
        $this->assertEquals(['invoice_number' => 'invoiceNumber', 'reference' => 'barcode'], $context['paths'], 'a JSON column may list the keys in its own order');
        $this->assertStringNotContainsString('O001', (string) $audit->context);
    }

    public function test_a_shipment_held_for_the_confirmation_says_so_in_words(): void
    {
        $held = $this->shipment(100, ProviderCode::TorodPoint, FulfillmentStatus::Held, ['hold_reason' => HoldReason::LIVE_SUBMISSION_UNPROVEN]);

        $html = $this->get(route('admin.fulfillment-providers.fulfillments.show', $held->id))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('hold_live_submission_unproven')), $html);
        $this->assertStringNotContainsString('torod_invoice_round_trip_unconfirmed', $html, 'no translation key is printed as if it were text');
    }

    public function test_nobody_else_can_record_or_withdraw_it(): void
    {
        $this->saveOrdersMap($this->historicalOrders());
        $this->actingAs(Admin::findOrFail(3), 'admin');

        $this->post(route('admin.fulfillment-providers.torod.invoice-round-trip.confirm'), $this->confirmationRequest())->assertSessionHasErrors('confirm');
        $this->assertFalse($this->roundTrip()->isConfirmed($this->config()));

        $this->actingAs(Admin::findOrFail(1), 'admin');
        $this->post(route('admin.fulfillment-providers.torod.invoice-round-trip.confirm'), $this->confirmationRequest());
        $this->actingAs(Admin::findOrFail(3), 'admin');

        $this->post(route('admin.fulfillment-providers.torod.invoice-round-trip.withdraw'))->assertSessionHasErrors('confirm');
        $this->assertTrue($this->roundTrip()->isConfirmed($this->config()));
    }

    public function test_the_confirmation_needs_the_tick_and_the_evidence(): void
    {
        $this->saveOrdersMap($this->historicalOrders());

        $this->post(route('admin.fulfillment-providers.torod.invoice-round-trip.confirm'), ['evidence' => self::EVIDENCE])->assertSessionHasErrors('confirm');
        $this->post(route('admin.fulfillment-providers.torod.invoice-round-trip.confirm'), $this->confirmationRequest(['evidence' => 'yes']))->assertSessionHasErrors('evidence');
        $this->post(route('admin.fulfillment-providers.torod.invoice-round-trip.confirm'), $this->confirmationRequest(['evidence' => str_repeat('x', 501)]))->assertSessionHasErrors('evidence');

        $this->assertFalse($this->roundTrip()->isConfirmed($this->config()));
    }

    public function test_without_an_invoice_path_there_is_nothing_to_confirm(): void
    {
        $this->post(route('admin.fulfillment-providers.torod.invoice-round-trip.confirm'), $this->confirmationRequest())->assertSessionHasNoErrors();

        $this->assertFalse($this->roundTrip()->isConfirmed($this->config()));
        $this->assertStringContainsString(translate('invoice_round_trip_needs_invoice_path'), $this->toasts());
    }

    public function test_withdrawing_it_holds_real_orders_again(): void
    {
        $this->saveOrdersMap($this->historicalOrders());
        $this->post(route('admin.fulfillment-providers.torod.invoice-round-trip.confirm'), $this->confirmationRequest());
        $this->assertTrue(app(TorodPointAdapter::class)->readiness()->capability('live_submission')->isUsable());

        $this->post(route('admin.fulfillment-providers.torod.invoice-round-trip.withdraw'))->assertSessionHasNoErrors();

        $this->assertFalse(app(TorodPointAdapter::class)->readiness()->capability('live_submission')->isUsable());
        $this->assertNull($this->config()->get('invoice_round_trip'));
    }

    public function test_saving_the_settings_form_does_not_touch_the_confirmation(): void
    {
        $this->saveOrdersMap($this->historicalOrders());
        $this->post(route('admin.fulfillment-providers.torod.invoice-round-trip.confirm'), $this->confirmationRequest());

        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), ['enabled' => 1, 'company_id' => '714', 'dry_run' => 1])->assertSessionHasNoErrors();

        $this->assertTrue($this->roundTrip()->isConfirmed($this->config()));
    }

    public function test_the_field_map_form_changing_the_path_withdraws_it(): void
    {
        $this->saveOrdersMap($this->historicalOrders());
        $this->post(route('admin.fulfillment-providers.torod.invoice-round-trip.confirm'), $this->confirmationRequest());

        $this->saveOrdersMap($this->historicalOrders(), verify: false, paths: ['invoice_number' => 'invoice_number', 'reference' => 'barcode']);

        $this->assertFalse($this->roundTrip()->isConfirmed($this->config()));
    }

    // ---- the stock field ---------------------------------------------------------------------------------------------------------------

    private function verifiedProductsMap(string $stockPath): void
    {
        app(FieldMapStore::class)->save(ProviderCode::TorodPoint, TorodFieldMaps::PRODUCTS, ['id' => 'id', 'name' => 'name', 'stock' => $stockPath], null);
        $this->assertSame([], app(FieldMapStore::class)->verify(ProviderCode::TorodPoint, TorodFieldMaps::PRODUCTS, [['id' => 1, 'name' => 'A']], ['id', 'name']));
    }

    private function settingsForm(array $overrides = []): array
    {
        return $overrides + ['enabled' => 1, 'company_id' => '714', 'dry_run' => 1];
    }

    public function test_the_save_and_verify_button_reads_a_flag_in_the_barcode_as_no_barcode(): void
    {
        $orders = array_map(static fn (array $order): array => ['barcode' => true] + $order, $this->historicalOrders());

        $this->saveOrdersMap($orders);

        $this->assertFalse($this->ordersMap()->verified);
    }

    public function test_a_page_opened_before_the_stock_sync_was_stopped_cannot_switch_it_back_on(): void
    {
        $this->verifiedProductsMap('sellableQuantity');
        $on = $this->settingsForm(['stock_field_confirmed' => 1, 'stock_field_path' => 'sellableQuantity', 'inventory_sync_enabled' => 1]);
        $loaded = ['loaded_stock_field_confirmed' => 1, 'loaded_inventory_sync_enabled' => 1];
        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), $on);
        $this->assertTrue($this->config()->bool('inventory_sync_enabled'));
        $this->assertTrue(app(TorodStockConfirmation::class)->isConfirmed($this->config()));

        // Administrator A stops it: switches the sync off and withdraws the confirmation.
        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), array_replace($on, ['stock_field_confirmed' => 0, 'inventory_sync_enabled' => 0]) + $loaded);
        $this->assertFalse($this->config()->bool('inventory_sync_enabled'));
        $this->assertFalse(app(TorodStockConfirmation::class)->isStated($this->config()));

        // Administrator B saves something else from a page that was opened while both were still on.
        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), $on + $loaded + ['default_weight_kg' => 2]);

        $this->assertFalse($this->config()->bool('inventory_sync_enabled'), 'a switch B did not touch keeps what A set');
        $this->assertFalse(app(TorodStockConfirmation::class)->isStated($this->config()), 'nor can B bring back a statement A withdrew');
        $this->assertEquals(2.0, $this->config()->get('default_weight_kg'), 'what B did change is saved');
    }

    public function test_a_page_opened_before_the_stock_field_was_confirmed_cannot_withdraw_the_confirmation(): void
    {
        $this->verifiedProductsMap('sellableQuantity');
        $off = $this->settingsForm(['stock_field_path' => 'sellableQuantity', 'inventory_sync_enabled' => 0]);
        $loaded = ['loaded_stock_field_confirmed' => 0, 'loaded_inventory_sync_enabled' => 0];

        // Administrator A confirms it after B opened the page.
        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), array_replace($off, ['stock_field_confirmed' => 1]));
        $this->assertTrue(app(TorodStockConfirmation::class)->isConfirmed($this->config()));

        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), $off + $loaded + ['default_weight_kg' => 2]);

        $this->assertTrue(app(TorodStockConfirmation::class)->isConfirmed($this->config()), 'a box B never touched keeps what A ticked');
    }

    public function test_the_stock_confirmation_names_the_field_it_is_about(): void
    {
        $this->verifiedProductsMap('sellableQuantity');

        $html = $this->get(route('admin.fulfillment-providers.show', 'torod-point'))->assertOk()->getContent();

        $this->assertStringContainsString('name="stock_field_path" value="sellableQuantity"', $html);
        $this->assertStringContainsString('name="loaded_stock_field_confirmed" value="0"', $html);
        $this->assertStringContainsString('name="loaded_inventory_sync_enabled" value="0"', $html);
        $this->assertStringContainsString('<code>sellableQuantity</code>', $html);
        $this->assertStringNotContainsString('name="stock_field_confirmed" value="1" checked', $html);
    }

    public function test_the_page_asks_for_a_stock_path_before_anything_can_be_confirmed(): void
    {
        $html = $this->get(route('admin.fulfillment-providers.show', 'torod-point'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('stock_field_path_not_set')), $html);
        $this->assertStringNotContainsString('name="stock_field_confirmed"', $html);
    }

    public function test_ticking_the_box_records_the_field_that_was_shown(): void
    {
        $this->verifiedProductsMap('sellableQuantity');

        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), $this->settingsForm(['stock_field_confirmed' => 1, 'stock_field_path' => 'sellableQuantity']))->assertSessionHasNoErrors();

        $this->assertSame('sellableQuantity', $this->config()->string('stock_field_confirmed_path'));
        $this->assertNotNull($this->config()->get('stock_field_confirmed_at'));
        $this->assertTrue(app(TorodStockConfirmation::class)->isConfirmed($this->config()));

        $html = $this->get(route('admin.fulfillment-providers.show', 'torod-point'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="stock_field_confirmed" value="1"\s+checked/', $html);
    }

    public function test_a_page_left_open_across_a_change_of_the_field_confirms_nothing(): void
    {
        $this->verifiedProductsMap('onHandQuantity');

        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), $this->settingsForm(['stock_field_confirmed' => 1, 'stock_field_path' => 'sellableQuantity']))->assertSessionHasNoErrors();

        $this->assertNull($this->config()->get('stock_field_confirmed_at'));
        $this->assertSame('', $this->config()->string('stock_field_confirmed_path'));
    }

    public function test_unticking_the_box_withdraws_the_confirmation(): void
    {
        $this->verifiedProductsMap('sellableQuantity');
        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), $this->settingsForm(['stock_field_confirmed' => 1, 'stock_field_path' => 'sellableQuantity']));

        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), $this->settingsForm(['stock_field_path' => 'sellableQuantity']))->assertSessionHasNoErrors();

        $this->assertNull($this->config()->get('stock_field_confirmed_at'));
        $this->assertFalse(app(TorodStockConfirmation::class)->isConfirmed($this->config()));
    }

    public function test_changing_the_stock_path_shows_the_box_unticked_again(): void
    {
        $this->verifiedProductsMap('sellableQuantity');
        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), $this->settingsForm(['stock_field_confirmed' => 1, 'stock_field_path' => 'sellableQuantity']));

        $this->verifiedProductsMap('onHandQuantity');

        $html = $this->get(route('admin.fulfillment-providers.show', 'torod-point'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/name="stock_field_confirmed" value="1"\s+checked/', $html, 'the statement was about another field');
        $this->assertFalse(app(TorodStockConfirmation::class)->isConfirmed($this->config()));
    }

    public function test_changing_the_stock_path_removes_the_confirmation_so_the_old_path_cannot_bring_it_back(): void
    {
        $this->verifiedProductsMap('sellableQuantity');
        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), $this->settingsForm(['stock_field_confirmed' => 1, 'stock_field_path' => 'sellableQuantity']));
        $this->assertTrue(app(TorodStockConfirmation::class)->isConfirmed($this->config()));

        $paths = static fn (string $stock): array => ['paths' => ['id' => 'id', 'name' => 'name', 'stock' => $stock]];
        $this->post(route('admin.fulfillment-providers.field-map.save', ['torod-point', 'products']), $paths('onHandQuantity'));
        $this->post(route('admin.fulfillment-providers.field-map.save', ['torod-point', 'products']), $paths('sellableQuantity'));
        $this->verifiedProductsMap('sellableQuantity');

        $this->assertNull($this->config()->get('stock_field_confirmed_at'));
        $this->assertFalse(app(TorodStockConfirmation::class)->isConfirmed($this->config()), 'sellableQuantity is back, and nobody has confirmed it again');
    }

    public function test_the_update_stock_modal_stands_aside_for_a_product_the_warehouse_controls(): void
    {
        $this->verifiedProductsMap('sellableQuantity');
        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), $this->settingsForm(['stock_field_confirmed' => 1, 'stock_field_path' => 'sellableQuantity', 'inventory_sync_enabled' => 1]));
        $this->makeProduct(1, ProviderCode::TorodPoint, ['current_stock' => 12]);
        ProviderProductMapping::create(['provider' => 'torod_point', 'product_id' => 1, 'variant_key' => '', 'external_id' => '1', 'external_variant_id' => '', 'status' => MappingStatus::Approved, 'provider_stock' => 12, 'provider_stock_synced_at' => now(), 'stock_source' => StockSource::ProviderSync]);

        $this->post(route('admin.products.update-quantity'), ['product_id' => 1, 'current_stock' => 999]);

        $this->assertSame(12, (int) DB::table('products')->where('id', 1)->value('current_stock'));
        $this->assertStringContainsString(translate('stock_is_set_by_the_warehouse_sync'), $this->toasts());
    }

    public function test_the_matching_page_refuses_a_typed_stock_figure_for_a_product_the_warehouse_controls(): void
    {
        $this->verifiedProductsMap('sellableQuantity');
        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), $this->settingsForm(['stock_field_confirmed' => 1, 'stock_field_path' => 'sellableQuantity', 'inventory_sync_enabled' => 1]));
        $this->makeProduct(1, ProviderCode::TorodPoint);
        $mapping = ProviderProductMapping::create(['provider' => 'torod_point', 'product_id' => 1, 'variant_key' => '', 'external_id' => '1', 'external_variant_id' => '', 'status' => MappingStatus::Approved, 'provider_stock' => 40, 'provider_stock_synced_at' => now(), 'stock_source' => StockSource::ProviderSync]);

        $this->post(route('admin.fulfillment-providers.torod.stock'), ['mapping_id' => $mapping->id, 'quantity' => 5]);

        $this->assertStringContainsString(e(translate('stock_is_set_by_the_warehouse_sync')), $this->toasts());
        $this->assertSame(40, $mapping->fresh()->provider_stock);

        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), $this->settingsForm(['stock_field_confirmed' => 1, 'stock_field_path' => 'sellableQuantity', 'inventory_sync_enabled' => 0]));
        $this->post(route('admin.fulfillment-providers.torod.stock'), ['mapping_id' => $mapping->id, 'quantity' => 5]);

        $this->assertSame(5, $mapping->fresh()->provider_stock);
        $this->assertSame(StockSource::Manual, $mapping->fresh()->stock_source);
    }
}
