<?php

namespace Tests\Support\Concurrency;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What a worker process does in the seller withdraw concurrency tests (tests/Feature/Concurrency/SellerWithdrawConcurrencyTest.php).
 *
 * Each worker sends one real v3 seller request through the HTTP kernel, so the bearer-token middleware and the controller run exactly
 * as in production. After the controller's first read of the withdraw request, outside any transaction, the worker pauses: every worker
 * has then read the same wallet before any of them writes, which is the stale read the row lock inside the transaction has to survive.
 */
final class SellerWithdrawOperations
{
    /**
     * @param array{token: string, request_id: int, method_id: int, amount: float, hold_microseconds?: int} $args
     * @return array{status: int, body: mixed}
     */
    public static function update(array $args): array
    {
        return self::send($args, Request::create('/api/v3/seller/balance-withdraw-update', 'POST', [
            'withdraw_request_id' => $args['request_id'], 'withdraw_method_id' => $args['method_id'], 'amount' => $args['amount'], 'account' => 'SA00',
        ]));
    }

    /**
     * @param array{token: string, request_id: int, hold_microseconds?: int} $args
     * @return array{status: int, body: mixed}
     */
    public static function close(array $args): array
    {
        return self::send($args, Request::create('/api/v3/seller/close-withdraw-request', 'DELETE', ['id' => $args['request_id']]));
    }

    /**
     * @param array{token: string, hold_microseconds?: int} $args
     * @return array{status: int, body: mixed}
     */
    private static function send(array $args, Request $request): array
    {
        self::pauseAfterTheFirstReadOutsideATransaction((int) ($args['hold_microseconds'] ?? 0));
        $request->headers->set('Authorization', 'Bearer ' . $args['token']);
        $request->headers->set('Accept', 'application/json');

        $response = app(Kernel::class)->handle($request);

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
    }

    private static function pauseAfterTheFirstReadOutsideATransaction(int $holdMicroseconds): void
    {
        $hasPaused = false;
        DB::listen(static function (QueryExecuted $query) use (&$hasPaused, $holdMicroseconds): void {
            if ($hasPaused || DB::transactionLevel() > 0 || !str_starts_with(strtolower($query->sql), 'select') || !str_contains($query->sql, 'withdraw_requests')) {
                return;
            }
            $hasPaused = true;
            usleep($holdMicroseconds);
        });
    }
}
