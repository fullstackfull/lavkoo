<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A seller's staff member, assigned a role (Phase 3, Stage A).
 *
 * A sign-in account: the member authenticates with these (hashed) credentials. On the web panel the
 * staff login signs them in as their parent seller and SellerStaffAccessMiddleware scopes what they
 * may do; on the API they hold an `auth_token` of their own, and SellerApiAuthMiddleware resolves it
 * into a principal that names both the shop and the person.
 */
class SellerStaff extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'seller_staff';

    protected $fillable = ['seller_id', 'seller_role_id', 'name', 'email', 'password', 'auth_token', 'status', 'last_login_at'];

    // Both are credentials. The token is a live API session, so it must never be serialised into a
    // response the way the seller model's own token once was.
    protected $hidden = ['password', 'auth_token'];

    protected $casts = [
        'seller_id' => 'integer',
        'seller_role_id' => 'integer',
        'last_login_at' => 'datetime',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(SellerRole::class, 'seller_role_id');
    }
}
