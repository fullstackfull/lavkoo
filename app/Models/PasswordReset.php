<?php

namespace App\Models;

use App\Services\Identity\OneTimeCodeLifetime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Class YourModel
 *
 * @package App\Models
 *
 * @property int $id
 * @property string $identity
 * @property string $token
 * @property int $otp_hit_count
 * @property bool $is_temp_blocked
 * @property Carbon $temp_block_time
 * @property Carbon $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string $user_type
 * @property mixed $doc
 */
class PasswordReset extends Model
{
    use HasFactory;
    protected $fillable = [
        'identity',
        'token',
        'otp_hit_count',
        'is_temp_blocked',
        'temp_block_time',
        'expires_at',
        'created_at',
        'updated_at',
        'user_type',
    ];

    protected $casts = [
        'identity' => 'string',
        'token' => 'string',
        'otp_hit_count' => 'integer',
        'is_temp_blocked' => 'boolean',
        'temp_block_time' => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The proofs that are still within their lifetime (A2-05). Hands the query back untouched while the lifetime switch is off, which is
     * every installation today. This table holds the typed codes AND the long token the seller reset mails as a link, so it is judged by
     * `OneTimeCodeLifetime::RESET`, the longer of the two figures.
     */
    public function scopeWithinLifetime(Builder $query): Builder
    {
        return app(OneTimeCodeLifetime::class)->narrow(query: $query, kind: OneTimeCodeLifetime::RESET);
    }
}
