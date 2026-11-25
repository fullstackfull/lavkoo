<?php

namespace App\Models;

use App\Models\Scopes\StaffCountryOrderScope;
use App\Services\Staff\StaffCountryScope;
use Illuminate\Database\Eloquent\Model;

/**
 * The warehouse-side pick/pack/ship workflow for an order (Phase 3, Stage C).
 */
class OrderFulfillment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PICKING = 'picking';
    public const STATUS_PACKED = 'packed';
    public const STATUS_READY = 'ready';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_CANCELED = 'canceled';

    /** The forward order of the workflow — used to enforce that it only ever moves forward. */
    public const FLOW = [
        self::STATUS_PENDING,
        self::STATUS_PICKING,
        self::STATUS_PACKED,
        self::STATUS_READY,
        self::STATUS_SHIPPED,
    ];

    protected $fillable = [
        'reference', 'order_id', 'seller_id', 'warehouse_id', 'status', 'assigned_to',
        'carrier', 'tracking_number', 'picked_at', 'packed_at', 'shipped_at', 'note', 'created_by',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'seller_id' => 'integer',
        'warehouse_id' => 'integer',
        'assigned_to' => 'integer',
        'picked_at' => 'datetime',
        'packed_at' => 'datetime',
        'shipped_at' => 'datetime',
        'created_by' => 'integer',
    ];

    public function isOpen(): bool
    {
        return !in_array($this->status, [self::STATUS_SHIPPED, self::STATUS_CANCELED], true);
    }

    /** Position of a status in the forward flow, or -1 for terminal/off-flow states. */
    public static function flowIndex(string $status): int
    {
        return array_search($status, self::FLOW, true);
    }

    /**
     * A fulfilment ships one order, so a restricted employee reads the pick-pack-ship queue of their own countries' orders (GCC-14c, slice
     * 5). Only a screen that asked to list them is narrowed: opening a fulfilment must see the ones an order already has, whatever market
     * they are in, or a second one would be opened for an order that already has one. Inert for every request that binds no country.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope(new StaffCountryOrderScope(subject: StaffCountryScope::SUBJECT_FULFILLMENTS, orderIdColumn: 'order_id'));
    }
}
