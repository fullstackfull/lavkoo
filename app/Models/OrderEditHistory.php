<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Class OrderEditHistory
 *
 * @property int $id
 * @property int $u_id
 * @property int $order_id
 * @property string|null $edit_by
 * @property int|null $edited_user_id
 * @property string|null $edited_user_name
 * @property float $order_amount
 * @property float $order_due_amount
 * @property string|null $order_due_payment_status
 * @property string|null $order_due_payment_method
 * @property string|null $order_due_transaction_ref
 * @property string|null $order_due_payment_note
 * @property float $order_return_amount
 * @property string|null $order_return_payment_status
 * @property string|null $order_return_payment_method
 * @property string|null $order_return_transaction_ref
 * @property string|null $order_return_payment_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrderEditHistory extends Model
{
    protected $fillable = [
        'u_id',
        'order_id',
        'edit_by',
        'edited_user_id',
        'edited_user_name',
        'order_amount',
        'order_due_amount',
        'order_due_payment_status',
        'order_due_payment_method',
        'order_due_payment_info',
        'order_due_transaction_ref',
        'order_due_payment_note',
        'order_return_amount',
        'order_return_payment_status',
        'order_return_payment_info',
        'order_return_payment_method',
        'order_return_transaction_ref',
        'order_return_payment_note',
    ];

    protected $casts = [
        'u_id' => 'integer',
        'edit_by' => 'string',
        'order_id' => 'integer',
        'edited_user_id' => 'integer',
        'edited_user_name' => 'string',
        'order_amount' => 'float',
        'order_due_amount' => 'float',
        'order_return_amount' => 'float',
        'order_due_payment_info' => 'array',
        'order_return_payment_info' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->u_id)) {
                $model->u_id = (string)Str::uuid();
            }
        });
    }
}
