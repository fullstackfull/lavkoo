<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * App\Models\VendorWithdrawMethodInfo
 *
 * @property int $id
 * @property string $user_id
 * @property int $withdraw_method_id
 * @property string|null $method_name
 * @property array $method_info
 * @property bool $is_active
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 */
class VendorWithdrawMethodInfo extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'withdraw_method_id',
        'method_name',
        'method_info',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'method_info' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function withdraw_method(): BelongsTo
    {
        return $this->belongsTo(WithdrawalMethod::class, 'withdraw_method_id');
    }
}
