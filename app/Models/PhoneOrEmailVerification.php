<?php

namespace App\Models;

use App\Utils\RequestText;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Class UserToken
 *
 * @property int $id
 * @property string|null $phone_or_email
 * @property string|null $token
 * @property int $otp_hit_count
 * @property bool $is_temp_blocked
 * @property Carbon|null $temp_block_time
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhoneOrEmailVerification extends Model
{
    protected $fillable = [
        'phone_or_email',
        'token',
        'otp_hit_count',
        'is_temp_blocked',
        'temp_block_time',
        'expires_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'phone_or_email' => 'string',
        'token' => 'string',
        'otp_hit_count' => 'integer',
        'is_temp_blocked' => 'integer',
        'temp_block_time' => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The row that holds this code. The table also holds rows without a token (a note that a session was asked for), and a raw
     * `where token = ?` takes a missing token for `IS NULL` and, under MySQL, the number 0 for every non-numeric token. A token that is not
     * a non-empty text, or a number, matches nothing; a number is compared as the text it was typed as.
     */
    public function scopeHoldingToken(Builder $query, mixed $token): Builder
    {
        $code = RequestText::of($token);

        return $code === '' ? $query->whereRaw('1 = 0') : $query->where('token', $code);
    }
}
