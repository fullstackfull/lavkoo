<?php

namespace Tests\Support\Concurrency;

use App\Http\Controllers\Admin\Deliveryman\DeliverymanWithdrawController;
use App\Http\Controllers\Vendor\DeliveryMan\DeliveryManWithdrawController as VendorDeliveryManWithdrawController;
use App\Http\Requests\Vendor\DeliveryManWithdrawRequest;
use App\Models\Seller;
use App\Services\DeliveryManService;
use App\Services\DeliveryManWalletService;
use App\Services\DeliveryManWithdrawService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * What a worker process does in tests/Feature/Concurrency/DeliveryManWithdrawConcurrencyTest.php.
 *
 * Each worker calls one of the two screens that decide a delivery man's withdrawal, and pauses after its first read of the request --
 * the read the handler makes before any transaction. Every worker has then seen the same pending request and the same wallet before any
 * of them writes, which is the stale read the row lock inside the settlement has to survive.
 *
 * The actions are called directly: the admin guard and the module permission in front of them are not what races.
 */
final class DeliveryManWithdrawOperations
{
    /**
     * @param array{request_id: int, approved: int, note?: string, hold_microseconds?: int} $args
     * @return array{status: int, body: mixed}
     */
    public static function decideAsAdmin(array $args): array
    {
        self::pauseAfterTheFirstReadOfTheRequest((int) ($args['hold_microseconds'] ?? 0));

        $response = app(DeliverymanWithdrawController::class)->updateStatus(
            request: self::decision($args),
            withdrawId: $args['request_id'],
            deliveryManWithdrawService: app(DeliveryManWithdrawService::class),
            deliveryManWalletService: app(DeliveryManWalletService::class),
        );

        return ['status' => $response->getStatusCode(), 'body' => $response->getData(true)];
    }

    /**
     * @param array{request_id: int, seller_id: int, approved: int, note?: string, hold_microseconds?: int} $args
     * @return array{status: int, body: mixed}
     */
    public static function decideAsVendor(array $args): array
    {
        self::pauseAfterTheFirstReadOfTheRequest((int) ($args['hold_microseconds'] ?? 0));
        auth('seller')->setUser((new Seller())->forceFill(['id' => $args['seller_id'], 'f_name' => 'Shop']));

        $response = (new VendorDeliveryManWithdrawController(
            app(\App\Contracts\Repositories\WithdrawRequestRepositoryInterface::class),
            app(DeliveryManWithdrawService::class),
            app(DeliveryManService::class),
        ))->updateStatus(self::decision($args), $args['request_id']);

        return ['status' => $response->getStatusCode(), 'body' => $response->getData(true)];
    }

    /** @param array{approved: int, note?: string} $args */
    private static function decision(array $args): DeliveryManWithdrawRequest
    {
        return DeliveryManWithdrawRequest::create('/', 'POST', [
            'approved' => $args['approved'],
            'note' => $args['note'] ?? 'decided',
        ]);
    }

    /**
     * Holds the worker after the handler's first read of the withdrawal request, which is made outside any transaction. A read inside one
     * is not paused: the point is to let every worker leave the starting line with the same picture, not to hold a lock open.
     */
    private static function pauseAfterTheFirstReadOfTheRequest(int $microseconds): void
    {
        if ($microseconds <= 0) {
            return;
        }

        $held = false;
        DB::listen(static function (QueryExecuted $query) use (&$held, $microseconds): void {
            if ($held || !str_contains($query->sql, 'withdraw_requests') || DB::transactionLevel() > 0) {
                return;
            }

            $held = true;
            usleep($microseconds);
        });
    }
}
