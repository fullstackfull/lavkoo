<?php

namespace App\Services\Shipping;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps on the order what the shipping engine decided about it (H1-06).
 *
 * A decision that is recomputed is a decision that changes. A permission withdrawn next month, a carrier that stops serving a route, the
 * switch turned off -- any of them would make an order that was correctly accepted look as though it never should have been, and nobody
 * reading it a year later could tell what was true on the day. So it is written once, as it was given, and never rewritten: an order
 * already carrying a decision keeps it, because the decision belongs to the moment the order was taken.
 *
 * Silent before its migration runs, and silent while the cross-border switch is off: a platform serving one country has taken only local
 * orders, and writing "local" onto every row of its history would be a claim made by a migration rather than by an engine.
 */
final class OrderShippingDecision
{
    /** @var bool|null whether the columns exist; not re-read for every order of a batch */
    private static ?bool $columnsReady = null;

    public static function record(int|string|null $orderId, EligibilityDecision $decision): void
    {
        if ($orderId === null || !ShippingEligibility::isOn() || !self::columnsReady()) {
            return;
        }

        $orders = DB::table('orders')->where('id', $orderId);

        // Written once. An order that already carries a decision keeps the one it was taken under.
        if ($orders->whereNotNull('shipping_kind')->exists()) {
            return;
        }

        DB::table('orders')->where('id', $orderId)->update([
            'shipping_kind' => $decision->kind,
            'shipping_decision' => json_encode($decision->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** What an order was decided to be, or null when it carries no decision -- which is every order taken before this existed. */
    public static function of(int|string|null $orderId): ?array
    {
        if ($orderId === null || !self::columnsReady()) {
            return null;
        }

        $row = DB::table('orders')->where('id', $orderId)->first(['shipping_kind', 'shipping_decision']);

        if ($row === null || $row->shipping_kind === null) {
            return null;
        }

        $decision = json_decode((string) $row->shipping_decision, associative: true);

        return is_array($decision) ? $decision : ['kind' => $row->shipping_kind, 'allowed' => true, 'reasons' => []];
    }

    /** Tests build and drop the columns inside one process, so what was read once is not remembered. */
    public static function forget(): void
    {
        self::$columnsReady = null;
    }

    private static function columnsReady(): bool
    {
        return self::$columnsReady ??= Schema::hasColumn('orders', 'shipping_kind') && Schema::hasColumn('orders', 'shipping_decision');
    }
}
