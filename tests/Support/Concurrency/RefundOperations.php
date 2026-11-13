<?php

namespace Tests\Support\Concurrency;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Refund\RefundFlows;

/**
 * The admin refund, return-amount and order-status actions, each run in its own process against the same order. Events and mail
 * are faked in the worker, so no notification leaves the process.
 */
final class RefundOperations
{
    public static function markRefunded(): bool
    {
        self::silence();

        return array_key_exists('message', RefundFlows::markRefunded());
    }

    public static function returnAmountToWallet(array $args): bool
    {
        self::silence();
        RefundFlows::returnAmountToWallet(amount: (string) $args['amount']);

        return true;
    }

    public static function changeOrderStatus(array $args): bool
    {
        self::silence();
        RefundFlows::changeOrderStatus(status: (string) $args['status']);

        return true;
    }

    private static function silence(): void
    {
        Event::fake();
        Mail::fake();
    }
}
