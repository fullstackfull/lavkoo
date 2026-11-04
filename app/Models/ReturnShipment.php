<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A return authorisation and its physical journey back (Phase 3, Stage C).
 */
class ReturnShipment extends Model
{
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_RESTOCKED = 'restocked';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'reference', 'order_id', 'order_details_id', 'refund_request_id', 'product_id', 'seller_id',
        'qty', 'reason', 'status', 'restock', 'carrier', 'tracking_number', 'received_at',
        'note', 'created_by', 'created_by_type',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'order_details_id' => 'integer',
        'refund_request_id' => 'integer',
        'product_id' => 'integer',
        'seller_id' => 'integer',
        'qty' => 'integer',
        'restock' => 'boolean',
        'received_at' => 'datetime',
        'created_by' => 'integer',
    ];

    /** A return still moving — can be advanced or received. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_AUTHORIZED, self::STATUS_IN_TRANSIT], true);
    }

    /** Terminal states — nothing more happens to these. */
    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_RESTOCKED, self::STATUS_RECEIVED, self::STATUS_REJECTED], true);
    }
}
