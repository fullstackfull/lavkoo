<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * App\Models\OrderDetailsRewards
 *
 * @property int $id
 * @property int $order_id
 * @property int $order_details_id
 * @property string|null $reward_type (coupon or loyalty_point)
 * @property array|null $reward_details
 * @property float $reward_amount
 * @property int $reward_delivered (0 = not delivered, 1 = delivered)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrderDetailsRewards extends Model
{
    use HasFactory;

    protected $table = 'order_details_rewards';

    protected   $fillable = [
        'order_id',
        'order_details_id',
        'reward_type',
        'reward_details',
        'reward_amount',
        'reward_delivered',
    ];
    protected $casts = [
        'reward_details' => 'array',
        'reward_amount' => 'float',
    ];

    public function order() : BelongsTo
    {
        return $this->belongsTo(Order::class,'order_id', 'id');
    }
}
