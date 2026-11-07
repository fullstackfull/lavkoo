<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\Refusal;
use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Console\Commands\Concerns\SkipsWhileOperationalCostsAreOff;
use App\Enums\OperationalCosts\PurchaseLineRefusal;
use App\Services\OperationalCosts\PurchaseOrders\LayerRun;
use App\Services\OperationalCosts\PurchaseOrders\PurchaseOrderLayerService;
use App\Services\OperationalCosts\PurchaseOrders\PurchaseOrderSchema;
use App\Services\OperationalCosts\PurchaseOrders\PurchaseOrderSourceMode;
use App\Services\SalesChannels\Support\SalesChannelSetup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Manual only, and never scheduled: the goods the platform already received before the cost module existed need a cutover date and an opening balance, which is the owner's decision
 * (see docs/sales-channels/costs.md, "Sources of truth").
 */
class CostsLayersFromPurchaseOrdersCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;
    use SkipsWhileOperationalCostsAreOff;

    protected $signature = 'costs:layers-from-purchase-orders
        {--dry-run : report what would be made and write nothing anywhere (the default)}
        {--apply : make the layers; needs the purchase-order source on, the cost module on and --since}
        {--since= : the cutover date (YYYY-MM-DD): units received before it belong to the opening balance and get no layer}
        {--product= : only this product id}
        {--line= : only this purchase order line id}
        {--include= : line ids (comma separated) that you have looked at and that are separate purchases: they are layered although an invoice or a cost typed by hand may hold the same goods}
        {--limit=50 : layers listed}
        {--json : print the result as JSON}';

    protected $description = 'Make FIFO cost layers from the goods received on the platform\'s purchase orders (the orders are only read). Dry run by default; needs OPERATIONAL_COSTS_SOURCE_PURCHASE_ORDERS=shadow to report and =on to make layers';

    public function handle(PurchaseOrderLayerService $layers, PurchaseOrderSchema $schema): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('dry-run')) {
            Refusal::say($this, 'Choose --dry-run or --apply, not both.', error: true);

            return self::FAILURE;
        }
        $filters = $this->filters();
        if ($filters === null) {
            return self::FAILURE;
        }

        $refused = $apply ? self::FAILURE : self::SUCCESS;
        if ($this->operationalCostsAreOff()) {
            return $refused;
        }
        if (!PurchaseOrderSourceMode::reports()) {
            Refusal::say($this, PurchaseOrderSourceMode::OFF_MESSAGE);

            return $refused;
        }
        if ($apply && !PurchaseOrderSourceMode::active()) {
            Refusal::say($this, 'The purchase-order source is in shadow mode: it only reports. Set OPERATIONAL_COSTS_SOURCE_PURCHASE_ORDERS=on (and run `php artisan config:clear`) to make layers. Nothing was written.');

            return self::FAILURE;
        }
        if ($apply && $filters['since'] === null) {
            Refusal::say($this, 'Making layers needs a cutover date: --since=YYYY-MM-DD. Units received before it belong to the opening balance; layering all history would count units that were sold long ago. Nothing was written.', error: true);

            return self::FAILURE;
        }
        if ($this->salesChannelsNotMigrated()) {
            return $refused;
        }
        $unavailable = $schema->unavailable();
        if ($unavailable !== null) {
            Refusal::say($this, $unavailable);

            return $refused;
        }
        if ($apply) {
            SalesChannelSetup::prepare();
        }

        try {
            $run = $apply
                ? $layers->apply(since: $filters['since'], productId: $filters['product'], lineId: $filters['line'], sample: $filters['limit'], included: $filters['included'])
                : $layers->preview(productId: $filters['product'], lineId: $filters['line'], since: $filters['since'], sample: $filters['limit'], included: $filters['included']);
        } catch (Throwable $exception) {
            report($exception);
            Refusal::say($this, 'The layers could not be made: ' . $exception::class . '. What was made before it stays; run it again to carry on.', error: true);

            return self::FAILURE;
        }

        $this->option('json') ? $this->line((string) json_encode(['mode' => $apply ? 'apply' : 'dry-run'] + $run->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : $this->print($run, $apply, $filters['since']);

        return self::SUCCESS;
    }

    /** @return array{since: ?CarbonImmutable, product: ?int, line: ?int, included: list<int>, limit: int}|null null (after saying why) when an option is not what it should be */
    private function filters(): ?array
    {
        $since = $this->option('since');
        if ($since !== null) {
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $since) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $since) : null;
            if ($date === null || $date->toDateString() !== $since) {
                Refusal::say($this, 'The cutover date must be a date written YYYY-MM-DD.', error: true);

                return null;
            }
            $since = $date;
        }
        foreach (['product', 'line'] as $option) {
            if ($this->option($option) !== null && !ctype_digit((string) $this->option($option))) {
                Refusal::say($this, "The {$option} must be an id.", error: true);

                return null;
            }
        }

        $included = $this->option('include') === null ? [] : explode(',', (string) $this->option('include'));
        if (array_filter($included, static fn (string $id): bool => !ctype_digit($id)) !== []) {
            Refusal::say($this, 'The lines to include are ids separated by commas.', error: true);

            return null;
        }

        return [
            'since' => $since,
            'product' => $this->option('product') === null ? null : (int) $this->option('product'),
            'line' => $this->option('line') === null ? null : (int) $this->option('line'),
            'included' => array_values(array_unique(array_map('intval', $included))),
            'limit' => max(1, (int) $this->option('limit')),
        ];
    }

    private function print(LayerRun $run, bool $apply, ?CarbonImmutable $since): void
    {
        $this->line(sprintf(
            '%d received lines looked at%s: %d up to date, %d with %d units to layer, %d that cannot be layered, %d whose received quantity fell below what was layered (reported, never reversed).',
            $run->lines, $since === null ? ' (no cutover date: every unit ever received counts; --apply needs --since)' : ' (units received before ' . $since->toDateString() . ' are left to the opening balance)',
            $run->upToDate, $run->planned, $run->plannedUnits, array_sum($run->refused), $run->receivedBelowLayered,
        ));
        foreach ($run->refused as $reason => $lines) {
            $this->line(sprintf('  not layered, %s: %d lines: %s', $reason, $lines, PurchaseLineRefusal::from($reason)->meaning()));
        }
        if ($run->coveredLines !== []) {
            $this->line(sprintf('  covered lines: %s. When an invoice or a typed cost already holds these goods, leave them. A line that is a separate purchase is layered by running again with --include=%s.', implode(', ', $run->coveredLines), implode(',', $run->coveredLines)));
        }
        if ($run->approximateDates > 0) {
            $this->line(sprintf('  %d of the layers are dated by the line\'s last change, because the stock ledger does not account for what the line received (marked approximate).', $run->approximateDates));
        }
        if ($run->tranches !== []) {
            $this->table(['line', 'order', 'product', 'location', 'units', 'from-to', 'unit cost', 'received at'], array_map(
                static fn (array $row): array => [$row['line_id'], $row['purchase_order'], $row['product_id'], $row['location_id'] ?? 'default', $row['units'], $row['from'] . '-' . $row['to'], $row['unit_cost'], $row['received_at'] . ($row['date_is_approximate'] ? ' (approximate)' : '')],
                $run->tranches,
            ));
        }
        $this->line($apply
            ? sprintf('%d layers made (%d units); %d were already there.', $run->created, $run->createdUnits, $run->alreadyMade)
            : 'Dry run: nothing was written anywhere. Run it with --apply (and --since) to make these layers.');
    }
}
