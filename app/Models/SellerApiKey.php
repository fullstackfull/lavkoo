<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A key a seller issued to something that is not a person.
 *
 * The key itself is not here. Only its hash and the prefix that says which row to check — the
 * plaintext exists once, in the response that created it, and after that nobody can recover it,
 * including the marketplace.
 */
class SellerApiKey extends Model
{
    protected $fillable = [
        'seller_id',
        'created_by_staff_id',
        'name',
        'prefix',
        'token_hash',
        'scopes',
        'expires_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** Never serialise the hash, whatever a controller forgets to exclude. */
    protected $hidden = ['token_hash'];

    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
