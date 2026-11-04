<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\TaxModule\app\Models\OrderTax;

/**
 * Class OrderTransaction
 *
 * @property int $seller_id
 * @property int $shop_id
 * @property int $order_id
 * @property float $order_amount
 * @property float $seller_amount
 * @property float $admin_commission
 * @property string|null $received_by
 * @property string|null $status
 * @property float $delivery_charge
 * @property float $tax
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int|null $customer_id
 * @property string|null $seller_is
 * @property string $delivered_by
 * @property string|null $payment_method
 * @property string|null $transaction_id
 *
 * @package App\Models
 */
class OrderTransaction extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'seller_id',
        'shop_id',
        'order_id',
        'order_amount',
        'seller_amount',
        'admin_commission',
        'received_by',
        'status',
        'delivery_charge',
        'tax',
        'customer_id',
        'seller_is',
        'delivered_by',
        'payment_method',
        'transaction_id',
        'created_at',
        'updated_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'seller_id' => 'integer',
        'order_id' => 'integer',
        'order_amount' => 'float',
        'seller_amount' => 'float',
        'admin_commission' => 'float',
        'received_by' => 'integer',
        'payment_method' => 'string',
        'tax' => 'float',
        'delivery_charge' => 'float',
        'admin_expense' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'seller_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function orderDetails(): HasMany
    {
        return $this->hasMany(OrderDetail::class, 'order_id', 'order_id');
    }

    public function orderTaxes(): HasMany
    {
        return $this->hasMany(OrderTax::class, 'order_id', 'order_id')
            ->where('tax_for', 'tax_for_orders');
    }

}
