<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Console\Commands\Concerns\SkipsWhileOperationalCostsAreOff;
use App\Services\OperationalCosts\PurchaseOrders\PlatformReconciliation;
use Illuminate\Console\Command;

class CostsReconcilePlatformCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;
    use SkipsWhileOperationalCostsAreOff;

    protected $signature = 'costs:reconcile-platform {--limit=50 : rows listed in each section (the totals always cover everything)} {--product= : only this product id} {--json : print the report as JSON}';

    protected $description = 'Read-only: where the cost layers and the platform\'s purchase orders, stock and store orders disagree. Writes nothing and changes nothing it finds';

    public function handle(PlatformReconciliation $reconciliation): int
    {
        if ($this->operationalCostsAreOff() || $this->salesChannelsNotMigrated()) {
            return self::SUCCESS;
        }
        $product = $this->option('product');
        if ($product !== null && !ctype_digit((string) $product)) {
            $this->error('The product must be an id.');

            return self::FAILURE;
        }

        $report = $reconciliation->report(limit: max(1, (int) $this->option('limit')), productId: $product === null ? null : (int) $product);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif (!$report['available']) {
            $this->line($report['message']);
        } else {
            $this->print($report);
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $report */
    private function print(array $report): void
    {
        $orders = $report['purchase_orders'];
        $this->line(sprintf('Purchase orders: %d received lines, %d units received, %d units layered, %d units (%d lines) with no layer yet, %d lines that cannot be layered.', $orders['lines_received'], $orders['units_received'], $orders['units_layered'], $orders['units_without_layer'], $orders['lines_without_layer'], $orders['lines_never_layered']));
        foreach ($orders['never_layered']['by_reason'] as $reason => $figures) {
            $this->line(sprintf('  not layered, %s: %d lines, %d units: %s', $reason, $figures['lines'], $figures['units'], $figures['meaning']));
        }
        $this->section('Received lines with no layer yet', ['line', 'order', 'product', 'received', 'unlayered', 'received at'], array_map(
            static fn (array $row): array => [$row['line_id'], $row['purchase_order'], $row['product_id'], $row['units_received'], $row['units_without_layer'], $row['received_at'] . ($row['date_is_approximate'] ? ' (approximate)' : '')],
            $orders['without_layer']['rows'],
        ), $orders['without_layer']['truncated']);
        $this->section('Lines that cannot be layered', ['line', 'order', 'product', 'received', 'reason'], array_map(
            static fn (array $row): array => [$row['line_id'], $row['purchase_order'], $row['product_id'] ?? '-', $row['units_received'], $row['reason']],
            $orders['never_layered']['rows'],
        ), $orders['never_layered']['truncated']);
        $this->section('Received quantity fell below what was layered (reported, never reversed)', ['line', 'order', 'product', 'received now', 'layered up to'], array_map(
            static fn (array $row): array => [$row['line_id'], $row['purchase_order'], $row['product_id'], $row['units_received'], $row['layered_up_to']],
            $orders['received_below_layered']['rows'],
        ), $orders['received_below_layered']['truncated']);

        $products = $report['products'];
        $this->line(sprintf('Products compared: %d (balanced %d, layers above the platform %d, layers below the platform %d, not in the catalogue %d). Expected layer units = platform stock + store-order units not delivered yet.', $products['examined'], $products['balanced'], $products['layers_above_platform'], $products['layers_below_platform'], $products['product_not_found']));
        $this->section('Layers against the platform', ['product', 'name', 'platform stock', 'undelivered', 'layers', 'difference', 'channel took', 'PO received', 'PO layered', 'status'], array_map(
            static fn (array $row): array => [$row['product_id'], $row['name'] ?? '-', $row['platform_stock'] ?? '-', $row['undelivered_store_units'], $row['layer_units'], $row['difference'], $row['channel_units_taken'], $row['purchase_order_units_received'], $row['purchase_order_units_layered'], $row['status'] . ($row['has_variants'] ? ' (variants)' : '')],
            $products['rows'],
        ), $products['truncated']);

        foreach (['stock_without_layers' => 'Platform stock that no layer covers', 'negative_stock' => 'Negative platform stock'] as $key => $title) {
            $this->line(sprintf('%s: %d products, %d units.', $title, $report[$key]['products'], $report[$key]['units']));
            $this->section($title, ['product', 'name', 'platform stock'], array_map(static fn (array $row): array => [$row['product_id'], $row['name'], $row['platform_stock']], $report[$key]['rows']), $report[$key]['truncated']);
        }
        $this->line('Nothing was written. The platform\'s tables are the truth: this only shows the difference.');
    }

    /**
     * @param list<string> $headers
     * @param list<list<mixed>> $rows
     */
    private function section(string $title, array $headers, array $rows, bool $truncated): void
    {
        if ($rows === []) {
            return;
        }
        $this->line('');
        $this->line($title . ($truncated ? ' (the first ' . count($rows) . '; raise --limit for more)' : ''));
        $this->table($headers, $rows);
    }
}
