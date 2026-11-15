<?php

namespace Tests\Support\Concurrency;

use App\Http\Controllers\Admin\Vendor\VendorController;
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
 *
 * A create reads no withdraw request, so it pauses after its first read of the wallet instead, inside a transaction or not: before the
 * lock both workers hold the same balance, after it the second one waits for the first to commit.
 *
 * The admin decision calls the controller action directly: the admin guard and the module permission in front of it are not what races.
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
     * @param array{token: string, method_id: int, amount: float, hold_microseconds?: int} $args
     * @return array{status: int, body: mixed}
     */
    public static function create(array $args): array
    {
        self::pauseAfterTheFirstReadOf('seller_wallets', (int) ($args['hold_microseconds'] ?? 0), outsideATransactionOnly: false);

        return self::handle($args, Request::create('/api/v3/seller/balance-withdraw', 'POST', [
            'withdraw_method_id' => $args['method_id'], 'amount' => $args['amount'], 'account' => 'SA00',
        ]));
    }

    /**
     * @param array{request_id: int, approved: int, note?: string, hold_microseconds?: int} $args
     * @return array{status: int, flash: array<string, mixed>}
     */
    public static function decide(array $args): array
    {
        self::pauseAfterTheFirstReadOf('withdraw_requests', (int) ($args['hold_microseconds'] ?? 0), outsideATransactionOnly: true);
        $request = Request::create('/admin/vendors/withdraw-status/' . $args['request_id'], 'POST', [
            'approved' => $args['approved'], 'note' => $args['note'] ?? 'paid',
        ]);
        app()->instance('request', $request);

        $response = app(VendorController::class)->withdrawStatus($request, $args['request_id']);

        return ['status' => $response->getStatusCode(), 'flash' => session()->all()];
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
        self::pauseAfterTheFirstReadOf('withdraw_requests', (int) ($args['hold_microseconds'] ?? 0), outsideATransactionOnly: true);

        return self::handle($args, $request);
    }

    /**
     * @param array{token: string} $args
     * @return array{status: int, body: mixed}
     */
    private static function handle(array $args, Request $request): array
    {
        $request->headers->set('Authorization', 'Bearer ' . $args['token']);
        $request->headers->set('Accept', 'application/json');

        $response = app(Kernel::class)->handle($request);

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
    }

    private static function pauseAfterTheFirstReadOf(string $table, int $holdMicroseconds, bool $outsideATransactionOnly): void
    {
        $hasPaused = false;
        DB::listen(static function (QueryExecuted $query) use (&$hasPaused, $holdMicroseconds, $table, $outsideATransactionOnly): void {
            if ($hasPaused || ($outsideATransactionOnly && DB::transactionLevel() > 0) || !str_starts_with(strtolower($query->sql), 'select') || !str_contains($query->sql, $table)) {
                return;
            }
            $hasPaused = true;
            usleep($holdMicroseconds);
        });
    }
}
