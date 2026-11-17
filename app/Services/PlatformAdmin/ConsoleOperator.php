<?php

namespace App\Services\PlatformAdmin;

/**
 * Who ran a console command, as far as the process can say: the operating system user it runs as and the host it runs on. It is what the audit line of a
 * command on the server carries, so that a reader knows which account on which machine made the change. It is not an authenticated identity: the server's
 * own login records are what vouch for it.
 */
final class ConsoleOperator
{
    private const TEXT_LIMIT = 64;

    private const UNKNOWN = 'unknown';

    /** @return array{user: string, host: string} */
    public static function describe(): array
    {
        return [
            'user' => PlatformAdminText::clipped(text: self::user(), limit: self::TEXT_LIMIT),
            'host' => PlatformAdminText::clipped(text: (string) gethostname() ?: self::UNKNOWN, limit: self::TEXT_LIMIT),
        ];
    }

    /** The user the process runs as: not the owner of the script file, which is what get_current_user() answers. */
    private static function user(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid(posix_geteuid());

            if (is_array($entry) && ($entry['name'] ?? '') !== '') {
                return $entry['name'];
            }
        }

        return (string) (getenv('USER') ?: getenv('USERNAME') ?: self::UNKNOWN);
    }
}
