<?php

namespace Tests\Support\Concurrency;

use App\Services\OperationalCosts\PurchaseOrders\LinePlan;
use App\Services\OperationalCosts\PurchaseOrders\PlatformProcurement;
use App\Services\OperationalCosts\PurchaseOrders\PurchaseLineEvaluator;
use App\Services\OperationalCosts\PurchaseOrders\PurchaseOrderLayerService;
use App\Services\OperationalCosts\PurchaseOrders\Tranche;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** What a worker process does for the purchase-order layer concurrency tests: the real service, against the database the test built. Nothing here reaches outside it. */
final class PurchaseOrderOperations
{
    /**
     * A whole run, as the command makes it.
     *
     * @return array{created: int, already_made: int, up_to_date: int}
     */
    public static function layersFromPurchaseOrders(array $args): array
    {
        self::switchOn();
        $run = app(PurchaseOrderLayerService::class)->apply(since: CarbonImmutable::parse((string) $args['since']));

        return ['created' => $run->created, 'already_made' => $run->alreadyMade, 'up_to_date' => $run->upToDate];
    }

    /**
     * One tranche named by the caller, whatever the line says it has received: units `from` + 1 to `to`. Returns what became of it. With `hold_microseconds` the layer is written in
     * a transaction that stays open that long, so that two processes overlap for certain and only the database's own key can decide between them.
     */
    public static function layerTranche(array $args): string
    {
        self::switchOn();
        $procurement = app(PlatformProcurement::class);
        $line = $procurement->receivedLines(0, 1, lineId: (int) $args['line_id'])[0];
        $verdict = app(PurchaseLineEvaluator::class)->verdict($line, $procurement->products([$line->productId])[$line->productId] ?? null);
        $tranche = new Tranche(from: (int) $args['from'], to: (int) $args['to'], receivedAt: CarbonImmutable::parse((string) $args['received_at']), dateBasis: Tranche::BASIS_MOVEMENTS);
        $make = static fn (): string => app(PurchaseOrderLayerService::class)->layerTranche(new LinePlan(line: $line, verdict: $verdict, accountedUnits: 0, tranche: $tranche, reducedBy: 0))->state;

        if ((int) ($args['hold_microseconds'] ?? 0) === 0) {
            return $make();
        }
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

        return DB::transaction(static function () use ($make, $args): string {
            $state = $make();
            usleep((int) $args['hold_microseconds']);

            return $state;
        });
    }

    private static function switchOn(): void
    {
        config(['operational_costs.enabled' => true, 'operational_costs.sources.purchase_orders' => 'on', 'operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR']);
    }
}
