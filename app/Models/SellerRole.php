<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A seller-defined staff role with its permission set (Phase 3, Stage A).
 */
class SellerRole extends Model
{
    public const STATUS_ACTIVE = 'active';

    protected $fillable = ['seller_id', 'name', 'permissions', 'status', 'created_by'];

    protected $casts = [
        'seller_id' => 'integer',
        'permissions' => 'array',
        'created_by' => 'integer',
    ];

    /** Does this role grant a permission key? */
    public function grants(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? [], true);
    }
}
