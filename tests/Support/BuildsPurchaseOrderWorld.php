<?php

namespace Tests\Support;

use App\Models\InventoryCostLayer;
use App\Models\InventoryLocation;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\Invoices\InvoiceResult;
use App\Services\OperationalCosts\Invoices\InvoiceService;
use App\Services\OperationalCosts\PurchaseOrders\LayerRun;
use App\Services\OperationalCosts\PurchaseOrders\PurchaseOrderLayerService;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The world the purchase-order tests of the cost module start from: the module's real migrations, the platform's procurement tables as the module infers them, riyal as the reporting
 * currency, the cost module on and the purchase-order source in the given mode. Everything is built through the platform's own service and the module's own entry points.
 */
trait BuildsPurchaseOrderWorld
{
    use BuildsAuditLogTable;
    use BuildsPlatformProcurementSchema;
    use BuildsSalesChannelSchema;

    protected InventoryLocation $own;

    protected InventoryLocation $torod;

    protected function setUpPurchaseOrderWorld(string $mode = 'on'): void
    {
        $this->buildSalesChannelSchema();
        $this->buildPlatformProcurementSchema();
        $this->buildAuditLogTable();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR', 'operational_costs.enabled' => true, 'operational_costs.sources.purchase_orders' => $mode]);
        [$this->torod, , $this->own] = app(InventoryLocationService::class)->ensureDefaults();
    }

    protected function layerService(): PurchaseOrderLayerService
    {
        return app(PurchaseOrderLayerService::class);
    }

    /** @param list<int> $included */
    protected function applyLayers(string $since = '2000-01-01', array $included = []): LayerRun
    {
        return $this->layerService()->apply(since: CarbonImmutable::parse($since), included: $included);
    }

    /** @return array{0: int, 1: PurchaseOrderItem} a product and the first line of a placed order for it, nothing received yet */
    protected function placedLine(int $ordered = 10, float|null $cost = 12.5, ?string $currency = 'SAR', array $product = [], ?Supplier $supplier = null): array
    {
        $productId = $this->platformProduct($product);
        $order = $this->purchaseOrder($supplier ?? $this->supplier('Acme Pharma Supply'), [[$productId, $ordered, $cost]], currency: $currency);

        return [$productId, $order->items->first()];
    }

    protected function invoices(): InvoiceService
    {
        return app(InvoiceService::class);
    }

    /** @param list<array<string, mixed>> $lines @param array<string, mixed> $header */
    protected function purchaseInvoice(array $lines, array $header = [], string $number = 'INV-1'): InvoiceResult
    {
        $items = array_map(static fn (array $line): array => $line + ['cost_component' => 'cogs', 'currency' => 'SAR'], $lines);
        $total = array_sum(array_column($lines, 'amount'));

        return $this->invoices()->create($header + [
            'kind' => 'purchase', 'party_type' => 'supplier', 'provider_code' => 'acme-pharma', 'party_name' => 'Acme Pharma Supply', 'invoice_number' => $number, 'invoice_date' => '2026-11-10',
            'currency' => 'SAR', 'total' => (string) $total, 'inventory_location_id' => $this->own->id,
        ], $items, adminId: 1);
    }

    protected function layerUnits(int $product): int
    {
        return (int) InventoryCostLayer::where('product_id', $product)->sum('quantity_remaining');
    }

    /** @return array<string, int> */
    protected function rowCounts(): array
    {
        $counts = [];
        foreach (Schema::getTables(schema: Schema::getCurrentSchemaListing()) as $table) {
            $counts[$table['name']] = DB::table($table['name'])->count();
        }
        ksort($counts);

        return $counts;
    }

    /** @return list<string> every SQL statement the work issues */
    protected function statementsOf(callable $work): array
    {
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $work();

        return $statements;
    }

    /**
     * @param list<string> $statements
     * @return list<string> the ones that are not a read
     */
    protected function writesAmong(array $statements): array
    {
        return array_values(array_filter($statements, static fn (string $sql): bool => preg_match('/^\s*(select|pragma|with)\b/i', $sql) !== 1));
    }
}
