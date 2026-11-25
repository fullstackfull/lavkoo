<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One device a platform admin receives alerts on (L-16). It belongs to the SESSION that registered it, so it ends when that session does.
 *
 * The push token is encrypted with the application key and is hidden from every serialisation: it is a credential for sending to a particular
 * phone, and no answer of this API ever carries it back. The hash beside it is how a second registration of the same phone is recognised.
 */
class PlatformAdminDevice extends Model
{
    public const ANDROID = 'android';

    public const IOS = 'ios';

    /** The platforms a device may say it is. Anything else is not registered. */
    public const PLATFORMS = [self::ANDROID, self::IOS];

    protected $fillable = ['admin_id', 'token_id', 'platform', 'push_token', 'push_token_hash', 'topics', 'last_sent_at'];

    protected $casts = [
        'admin_id' => 'integer',
        'token_id' => 'integer',
        'push_token' => 'encrypted',
        'topics' => 'array',
        'last_sent_at' => 'datetime',
    ];

    protected $hidden = ['push_token', 'push_token_hash'];

    /** How a device is recognised again: the hash of its push token, never the token itself, which is encrypted afresh on every write. */
    public static function hashOf(string $pushToken): string
    {
        return hash('sha256', $pushToken);
    }

    /** The push token, or null when it cannot be read: it was encrypted with another application key, or the row is damaged. */
    public function pushToken(): ?string
    {
        try {
            $token = $this->push_token;
        } catch (DecryptException) {
            return null;
        }

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** @return list<string> */
    public function topicList(): array
    {
        return array_values(array_filter((array) $this->topics, static fn ($topic): bool => is_string($topic) && $topic !== ''));
    }

    /** The devices of sessions that are still open: a session that was ended, expired or merely went idle is sent nothing. */
    public function scopeOfOpenSessions(Builder $query, int $idleMinutes): void
    {
        $query->whereIn('token_id', PlatformAdminToken::query()->active($idleMinutes)->select('id'));
    }
}
