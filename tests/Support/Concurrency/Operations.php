<?php

namespace Tests\Support\Concurrency;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** The operations the worker processes can run. Each takes the arguments given for its process and returns something JSON-serialisable. */
final class Operations
{
    /** @var array<string, callable(array<string, mixed>, int): mixed> */
    private static array $registered = [];

    public static function register(string $name, callable $operation): void
    {
        self::$registered[$name] = $operation;
    }

    public static function run(string $name, array $args, int $index): mixed
    {
        return match ($name) {
            'probe_insert' => self::probeInsert($args),
            'probe_rendezvous' => self::probeRendezvous($args),
            'dispatch_channel_order' => DispatchOperations::dispatchChannelOrder($args),
            'mirror_managed_shipment' => DispatchOperations::mirrorManagedShipment($args),
            'layers_from_purchase_orders' => PurchaseOrderOperations::layersFromPurchaseOrders($args),
            'layer_purchase_order_tranche' => PurchaseOrderOperations::layerTranche($args),
            'stock.ingest_many' => StockOperations::ingestMany($args),
            'wallet.repository' => WalletOperations::writeThroughRepository($args),
            'wallet.customer_manager' => WalletOperations::writeThroughCustomerManager($args),
            'wallet.order_manager' => WalletOperations::writeThroughOrderManager($args),
            'wallet.customer_trait' => WalletOperations::writeThroughCustomerTrait($args),
            'loyalty.repository' => LoyaltyOperations::creditThroughRepository($args),
            'loyalty.customer_manager' => LoyaltyOperations::creditThroughCustomerManager($args),
            'loyalty.convert' => LoyaltyOperations::convertToWallet($args),
            'wallet.pay_edit_due' => WalletOperations::payEditOrderDue($args),
            default => (self::$registered[$name] ?? throw new InvalidArgumentException("Unknown concurrent operation: {$name}"))($args, $index),
        };
    }

    /**
     * Writes one row of the scratch table of the harness self-test, waiting at a meeting point first and/or marking one after, so that the ORDER of two processes can be
     * arranged rather than left to the scheduler. The self-test above it gives the marking process a head start and still expects it to write first.
     *
     * @param array{key: string, wait_for?: string, mark?: string, hold_microseconds?: int} $args
     * @return array{key: string, held_for: float}
     */
    private static function probeRendezvous(array $args): array
    {
        $heldFor = isset($args['wait_for']) ? Rendezvous::waitFor((string) $args['wait_for']) : 0.0;
        usleep((int) ($args['hold_microseconds'] ?? 0));
        DB::table('concurrency_probe')->insert(['k' => (string) $args['key'], 'writer' => getmypid()]);
        if (isset($args['mark'])) {
            Rendezvous::mark((string) $args['mark']);
        }

        return ['key' => (string) $args['key'], 'held_for' => $heldFor];
    }

    /** Inserts a row with a unique key into the scratch table of the harness self-test; reports whether this process won. */
    private static function probeInsert(array $args): string
    {
        try {
            DB::table('concurrency_probe')->insert(['k' => (string) $args['key'], 'writer' => getmypid()]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return 'duplicate';
        }
        usleep((int) ($args['hold_microseconds'] ?? 0));

        return 'inserted';
    }
}
