<?php

use Illuminate\Support\Facades\Cache;

if (!function_exists('getAppVersion')) {
    /**
     * The project's own release version (version.json in the repo root),
     * shown in the admin shell, the Monitoring header and the developer
     * portal. Separate from the vendor 6valley version on purpose: this
     * number tracks THIS store's releases.
     */
    function getAppVersion(): array
    {
        return Cache::remember('app_version_info', 300, function () {
            $path = base_path('version.json');
            $fallback = ['version' => '1.0.0', 'released_at' => null, 'channel' => 'stable'];
            if (!is_readable($path)) {
                return $fallback;
            }
            $data = json_decode((string) file_get_contents($path), true);
            return is_array($data) ? array_merge($fallback, $data) : $fallback;
        });
    }
}

if (!function_exists('app_release_version')) {
    /**
     * The identity of the running build, for tagging errors, traces and deployments.
     *
     * version.json is the release the merchant sees; the commit SHA is what an engineer needs to
     * know which code actually produced an error. Both when both are available — a deploy that
     * ships .git gets `1.7.0+364d5ea`, one that does not gets `1.7.0`, and neither is invented.
     */
    function app_release_version(): string
    {
        return Cache::remember('app_release_version', 300, function () {
            $version = getAppVersion()['version'] ?? '0.0.0';
            $sha = app_commit_sha();

            return $sha === null ? $version : $version . '+' . substr($sha, 0, 7);
        });
    }
}

if (!function_exists('app_commit_sha')) {
    /**
     * The deployed commit, read from .git without shelling out — many hosts disable exec, and a
     * deployment that exports the source has no .git at all. Null means "not available here",
     * which the monitoring UI reports rather than guessing.
     */
    function app_commit_sha(): ?string
    {
        $head = base_path('.git/HEAD');
        if (!is_readable($head)) {
            return null;
        }

        $pointer = trim((string) file_get_contents($head));
        if (str_starts_with($pointer, 'ref: ')) {
            $ref = base_path('.git/' . trim(substr($pointer, 5)));
            if (is_readable($ref)) {
                return trim((string) file_get_contents($ref)) ?: null;
            }
            // A packed ref: the loose file does not exist once git has packed it away.
            $packed = base_path('.git/packed-refs');
            if (is_readable($packed)) {
                $branch = trim(substr($pointer, 5));
                foreach (file($packed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if (str_ends_with($line, ' ' . $branch)) {
                        return strtok($line, ' ') ?: null;
                    }
                }
            }

            return null;
        }

        return preg_match('/^[0-9a-f]{40}$/', $pointer) === 1 ? $pointer : null;
    }
}
