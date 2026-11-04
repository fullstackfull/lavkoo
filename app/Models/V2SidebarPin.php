<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * V2 admin / vendor sidebar pin entry.
 *
 * One row per (user_type, user_id, pin_id). Pin ids are either a
 * parent's `data-item` value (e.g. "orders") or a child marker
 * `"child:" + href` produced by injectChildPinButtons() in
 * admin-v2.js / vendor-v2.js. Stored server-side so pins follow the
 * user across browsers / devices / sessions instead of living only
 * in localStorage.
 *
 * The pin list is cached per user under a stable key for an hour to
 * skip the SELECT on every page render — the controllers explicitly
 * `forgetCacheFor()` after any insert/delete so the cache and the row
 * set never drift.
 */
class V2SidebarPin extends Model
{
    use HasFactory;

    protected $table = 'v2_sidebar_pins';

    protected $fillable = [
        'user_type',
        'user_id',
        'pin_id',
    ];

    public const TYPE_ADMIN  = 'admin';
    public const TYPE_SELLER = 'seller';
    public const CACHE_TTL_SECONDS = 3600;

    /**
     * Cache key used by pinsFor() / forgetCacheFor().
     */
    public static function cacheKey(string $userType, int $userId): string
    {
        return 'v2_sidebar_pins:' . $userType . ':' . $userId;
    }

    /**
     * Fetch every pin id for a given user as a plain array of strings.
     * Cached per-user for V2SidebarPin::CACHE_TTL_SECONDS so first
     * paint of the v2 chrome doesn't pay the DB round-trip on every
     * page render.
     */
    public static function pinsFor(string $userType, int $userId): array
    {
        return Cache::remember(self::cacheKey($userType, $userId), self::CACHE_TTL_SECONDS, function () use ($userType, $userId) {
            return static::where('user_type', $userType)
                ->where('user_id', $userId)
                ->orderBy('id')
                ->pluck('pin_id')
                ->toArray();
        });
    }

    /**
     * Drop the cached pin list for this user so the next pinsFor()
     * call re-reads from the DB. Call this from controllers after
     * any insert / delete / replace.
     */
    public static function forgetCacheFor(string $userType, int $userId): void
    {
        Cache::forget(self::cacheKey($userType, $userId));
    }
}
