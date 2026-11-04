<?php

namespace App\Services\SellerCenter;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One definition of a seller's revenue.
 *
 * Delivered lines only, net of the line's own discount. The rule matters more than the formula:
 * the briefing, the home KPIs, reconciliation, the statement and the payout all have to answer the
 * same question with the same number, and the way that stops being true is each of them writing
 * its own `SUM(...)`. When the definition changes, it changes here.
 *
 * "Delivered" rather than "placed" is the deliberate half: money the customer has not received
 * goods for is not revenue, and counting it makes every comparison with the payout wrong.
 */
class Revenue
{
    /** The canonical expression. Every caller selects this rather than writing its own. */
    public const NET_LINE = 'order_details.price * order_details.qty - order_details.discount';

    /** Lines that count: this seller's, delivered, inside the window. */
    public static function lines(int|string $sellerId, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): ?Builder
    {
        if (!Schema::hasTable('order_details')) {
            return null;
        }

        $query = DB::table('order_details')
            ->where('seller_id', $sellerId)
            ->where('delivery_status', 'delivered');

        if ($from !== null && $to !== null) {
            $query->whereBetween('created_at', [$from, $to]);
        }

        return $query;
    }

    public static function total(int|string $sellerId, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): float
    {
        $lines = self::lines($sellerId, $from, $to);

        return $lines === null ? 0.0 : (float) $lines->sum(DB::raw(self::NET_LINE));
    }

    public static function units(int|string $sellerId, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int
    {
        $lines = self::lines($sellerId, $from, $to);

        return $lines === null ? 0 : (int) $lines->sum('order_details.qty');
    }

    /**
     * A percentage change, or null when there is nothing to compare against.
     *
     * Null is a deliberate refusal and renders `—`. Reporting "+∞%" against a period with no sales,
     * or silently substituting 100, are both lies a seller would act on.
     */
    public static function change(float $current, float $previous): ?float
    {
        if ($previous <= 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
