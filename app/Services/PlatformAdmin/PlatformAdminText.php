<?php

namespace App\Services\PlatformAdmin;

/**
 * Text a client sent, made fit to keep and to put in JSON: every byte that is not UTF-8 becomes a question mark and the rest is cut to its limit in
 * characters. The scrubbing is explicit because mb_substr() only does it from PHP 8.3 on: before that it hands the bytes through.
 */
final class PlatformAdminText
{
    public static function clipped(string $text, int $limit): string
    {
        return mb_substr(string: mb_scrub(string: $text, encoding: 'UTF-8'), start: 0, length: $limit, encoding: 'UTF-8');
    }
}
