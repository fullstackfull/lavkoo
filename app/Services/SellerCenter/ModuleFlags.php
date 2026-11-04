<?php

namespace App\Services\SellerCenter;

use App\Models\ProductBatch;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Schema;

/**
 * Which optional modules are running for one seller.
 *
 * Decided from the shop's own data rather than from a switch, for two reasons.
 *
 * The switch could not be thrown. `seller_warehouses_enabled` and `seller_batches_enabled` were
 * read in exactly one place each and written nowhere — no screen, no seeder, no installer — so the
 * warehouse section of the Seller Center could never appear, whatever a marketplace wanted. A flag
 * with no way to set it is not a feature gate; it is a permanent no.
 *
 * And the two clients disagreed. The seller app answered the same question by counting the
 * seller's warehouses, so a seller with warehouses saw the module on their phone and never in the
 * panel. Asking one service settles which answer is right.
 *
 * An absent module is absent, not disabled (handoff 07.8): a seller the marketplace does not run
 * warehouses for is shown no warehouse section at all, rather than an empty one implying they
 * ought to have some.
 */
class ModuleFlags
{
    /** @return array<string, bool> */
    public static function forSeller(int|string|null $sellerId): array
    {
        return [
            'warehouses_enabled' => self::hasWarehouses($sellerId),
            'batches_enabled' => self::hasBatches($sellerId),
        ];
    }

    public static function hasWarehouses(int|string|null $sellerId): bool
    {
        return $sellerId !== null
            && Schema::hasTable('warehouses')
            && Warehouse::where('seller_id', $sellerId)->exists();
    }

    /**
     * Batches count only while they hold something.
     *
     * A shop that once tracked expiry and has since sold through every batch is not running the
     * module today, and a section listing nothing expiring is a section about nothing.
     */
    public static function hasBatches(int|string|null $sellerId): bool
    {
        return $sellerId !== null
            && Schema::hasTable('product_batches')
            && ProductBatch::where('seller_id', $sellerId)->where('quantity', '>', 0)->exists();
    }
}
