<?php

namespace App\Services\Theme;

use App\Models\ThemeVersion;
use Illuminate\Support\Carbon;

/**
 * Permission to see one unpublished version, on one phone, for one hour.
 *
 * The builder's phone frame is a browser drawing an approximation. What a merchant actually wants
 * to know before publishing — do the images crop right on a real screen, does the Arabic wrap, is
 * the rail reachable with a thumb — only the app can answer, and the app only ever sees what is
 * published. So the choice was publish and look, or do not look.
 *
 * A token closes that without opening anything: it names a version and an expiry, signed with the
 * application key. It is not a session, carries no identity, and grants exactly one thing — that
 * this version may be delivered to whoever holds it, until it expires. A tampered id, a moved
 * expiry or a token from another installation all fail the same way, as no token at all.
 *
 * Short-lived on purpose. A preview link ends up in a chat message, and a layout a merchant
 * decided against should not stay readable to whoever scrolls back to it next month.
 */
class ThemePreviewToken
{
    /** Long enough to walk around the shop with the phone, short enough to be forgotten safely. */
    public const DEFAULT_MINUTES = 60;

    /** The ceiling on what an admin may ask for, so a link cannot be made effectively permanent. */
    public const MAX_MINUTES = 1440;

    /** Sign a version for preview, returning the opaque token a client presents. */
    public function mint(ThemeVersion $version, int $minutes = self::DEFAULT_MINUTES): string
    {
        $minutes = max(5, min(self::MAX_MINUTES, $minutes));
        $body = $version->id . '.' . Carbon::now()->addMinutes($minutes)->getTimestamp();

        return $body . '.' . $this->signature($body);
    }

    /**
     * The version a token names, or null for anything that is not a live, untampered token.
     *
     * Every failure returns null rather than explaining itself: a caller cannot act differently on
     * "expired" than on "forged", and saying which is a hint for whoever is guessing.
     */
    public function version(?string $token): ?ThemeVersion
    {
        if (!is_string($token) || substr_count($token, '.') !== 2) {
            return null;
        }

        [$versionId, $expires, $signature] = explode('.', $token);

        if (!ctype_digit($versionId) || !ctype_digit($expires)) {
            return null;
        }

        if (!hash_equals($this->signature($versionId . '.' . $expires), $signature)) {
            return null;
        }

        if (Carbon::createFromTimestamp((int) $expires)->isPast()) {
            return null;
        }

        return ThemeVersion::find((int) $versionId);
    }

    /** Seconds a freshly minted token of this length will remain valid, for a client to display. */
    public function expiresIn(int $minutes = self::DEFAULT_MINUTES): int
    {
        return max(5, min(self::MAX_MINUTES, $minutes)) * 60;
    }

    private function signature(string $body): string
    {
        return substr(hash_hmac('sha256', $body, (string) config('app.key')), 0, 32);
    }
}
