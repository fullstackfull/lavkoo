<?php

namespace Tests\Support\Concurrency;

use App\Models\Order;
use App\Models\ReturnShipment;
use App\Repositories\OrderRepository;
use App\Services\Marketplace\ReturnLogisticsService;
use App\Utils\OrderManager;

/** What a worker process does in the return-receipt concurrency test (tests/Feature/Concurrency/ReturnReceiptConcurrencyTest.php). */
final class ReturnReceiptOperations
{
    /**
     * Loads the return the way the admin and seller controllers do, outside any transaction, waits so every worker holds it while
     * it is still open, and then receives it.
     *
     * @param array{rma: int, hold_microseconds?: int} $args
     * @return array{ok: bool, restocked: bool|null, reason: string|null}
     */
    public static function receive(array $args): array
    {
        $rma = ReturnShipment::findOrFail($args['rma']);
        usleep((int) ($args['hold_microseconds'] ?? 0));

        $result = app(ReturnLogisticsService::class)->receive(rma: $rma, by: 1);

        return ['ok' => $result['ok'], 'restocked' => $result['restocked'] ?? null, 'reason' => $result['reason'] ?? null, 'restocked_units' => $result['restocked_units'] ?? null];
    }

    /**
     * Marks the order returned the way the panel's status change does, after the same wait, so this can be raced
     * against another process doing the same or against a return receipt.
     *
     * @param array{order: int, hold_microseconds?: int, status?: string} $args
     * @return array{ok: bool, status: string}
     */
    public static function changeOrderStatus(array $args): array
    {
        $order = Order::with('details')->findOrFail($args['order']);
        usleep((int) ($args['hold_microseconds'] ?? 0));

        OrderManager::getStockUpdateOnOrderStatusChange($order, $status = (string) ($args['status'] ?? 'returned'));

        return ['ok' => true, 'status' => $status];
    }

    /**
     * The same status change through the OTHER copy of the writer: OrderRepository::
     * updateStockOnOrderStatusChange, which is the one the admin and vendor panels run. The two copies
     * diverge in coverage (the first round's mutations M9 and M10 showed it), so both are raced.
     *
     * @param array{order: int, hold_microseconds?: int, status?: string} $args
     * @return array{ok: bool, status: string}
     */
    public static function changeOrderStatusThroughThePanelCopy(array $args): array
    {
        usleep((int) ($args['hold_microseconds'] ?? 0));

        app(OrderRepository::class)->updateStockOnOrderStatusChange($args['order'], $status = (string) ($args['status'] ?? 'returned'));

        return ['ok' => true, 'status' => $status];
    }
}
