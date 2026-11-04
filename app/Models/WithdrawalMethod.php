<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class WithdrawalMethod
 *
 * @property int $id
 * @property string $method_name
 * @property array $method_fields
 * @property bool $is_default
 * @property bool $is_active
 * @property bool $vendor_status
 * @property bool $customer_status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @package App\Models
 */
class WithdrawalMethod extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'method_name',
        'method_fields',
        'is_default',
        'is_active',
        'vendor_status',
        'customer_status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'method_fields' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'vendor_status' => 'boolean',
        'customer_status' => 'boolean',
    ];

    protected function scopeOfStatus($query, $status): void
    {
        $query->where('is_active', $status);
    }

    public function vendor_withdraw_method(): HasMany
    {
        return $this->hasMany(VendorWithdrawMethodInfo::class, 'withdraw_method_id');
    }
}
