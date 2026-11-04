<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a platform order to its Delivery Syria courier parcel and records the courier's reported
 * delivery status (optional integration).
 *
 * The order's own delivery_service_name / third_party_delivery_tracking_id columns still hold the
 * human-facing courier name and tracking id; this row is the integration's private ledger — the exact
 * invoice_no we sent (for idempotent re-dispatch), the wallet outcome, and the latest webhook status,
 * kept separate from the platform's financially-loaded order_status.
 */
class DeliverySyriaParcel extends Model
{
    public const WALLET_DEDUCTED = 'deducted';
    public const WALLET_PENDING = 'pending_payment';

    protected $fillable = [
        'order_id', 'invoice_no', 'parcel_id', 'tracking_number', 'wallet_status', 'delivery_charge',
        'company_type', 'governorate', 'weight', 'courier_status', 'courier_status_label', 'note',
        'status_updated_at',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'delivery_charge' => 'decimal:2',
        'weight' => 'decimal:3',
        'courier_status' => 'integer',
        'status_updated_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
