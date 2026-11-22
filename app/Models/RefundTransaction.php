<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $customer_id
 * @property int|null $order_id
 * @property string|null $payment_for
 * @property int|null $payer_id
 * @property int|null $payment_receiver_id
 * @property string|null $paid_by
 * @property string|null $paid_to
 * @property string|null $payment_method
 * @property string|null $payment_status
 * @property float|null $amount
 * @property string|null $transaction_type
 * @property int|null $order_details_id
 * @property int|null $refund_id
 * @property int|null $payment_gateway_account_id
 * @property int|null $payment_legal_entity_id
 * @property string|null $original_payment_amount
 * @property string|null $original_payment_currency
 */
class RefundTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'payment_for',
        'payer_id',
        'payment_receiver_id',
        'paid_by',
        'paid_to',
        'payment_method',
        'payment_status',
        'amount',
        'transaction_type',
        'order_details_id',
        'created_at',
        'updated_at',
        'refund_id',
        'storefront_country',
        'currency_code',
        'currency_rate',
        'legal_entity_id',
        'payment_gateway_account_id',
        'payment_legal_entity_id',
        'original_payment_amount',
        'original_payment_currency',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'payment_for' => 'string',
        'payer_id' => 'integer',
        'payment_receiver_id' => 'integer',
        'paid_by' => 'string',
        'paid_to' => 'string',
        'payment_method' => 'string',
        'payment_status' => 'string',
        'order_details_id' => 'integer',
        'amount' => 'float',
        'transaction_type' => 'string',
        'refund_id' => 'string'
    ];

    public function orderDetails(): BelongsTo
    {
        return $this->belongsTo(OrderDetail::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
