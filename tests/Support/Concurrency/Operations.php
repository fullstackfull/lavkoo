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
            'dispatch_channel_order' => DispatchOperations::dispatchChannelOrder($args),
            'mirror_managed_shipment' => DispatchOperations::mirrorManagedShipment($args),
            'layers_from_purchase_orders' => PurchaseOrderOperations::layersFromPurchaseOrders($args),
            'layer_purchase_order_tranche' => PurchaseOrderOperations::layerTranche($args),
            default => (self::$registered[$name] ?? throw new InvalidArgumentException("Unknown concurrent operation: {$name}"))($args, $index),
        };
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
