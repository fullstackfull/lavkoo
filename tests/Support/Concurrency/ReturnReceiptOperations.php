<?php

namespace Tests\Support\Concurrency;

use App\Models\ReturnShipment;
use App\Services\Marketplace\ReturnLogisticsService;

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

        return ['ok' => $result['ok'], 'restocked' => $result['restocked'] ?? null, 'reason' => $result['reason'] ?? null];
    }
}
