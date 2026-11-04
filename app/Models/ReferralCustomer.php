<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\ReferralCustomer
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $refer_by
 * @property float|null $ref_by_earning_amount
 * @property float|null $customer_discount_amount
 * @property string|null $customer_discount_amount_type
 * @property int|null $customer_discount_validity
 * @property string|null $customer_discount_validity_type
 * @property bool $is_used
 * @property bool $is_used_by_refer
 * @property bool $is_checked
 *@property Carbon $created_at
 * @property Carbon $updated_at
 */
class ReferralCustomer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'refer_by',
        'ref_by_earning_amount',
        'customer_discount_amount',
        'customer_discount_amount_type',
        'customer_discount_validity',
        'customer_discount_validity_type',
        'is_used',
        'is_used_by_refer',
        'is_checked',
        'delivered_notify'
    ];

}
