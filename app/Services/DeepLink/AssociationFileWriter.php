<?php

namespace App\Services\DeepLink;

/**
 * Writes the two files a phone reads before it will open a link in an app.
 *
 * This used to live in BusinessSettingsController, which meant the only way to publish the files
 * was for an administrator to open the deep-link screen and press save. That is fine for the day
 * the app is set up and wrong for every day after: when the published path list changes in code —
 * as it does when campaign short links become app links — every existing installation keeps
 * serving the old association file until somebody happens to re-save a form. Here, both the screen
 * and `php artisan deeplinks:publish` write through the same code.
 *
 * Both locations are written. The web root is what a phone actually fetches; the project root copy
 * is what deployments that point the domain one level up serve instead.
 */
class AssociationFileWriter
{
    public function __construct(private readonly AppLinkService $links)
    {
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<int, array{path: string, written: bool, reason: ?string}>
     */
    public function publish(array $settings): array
    {
        $results = [];

        foreach ([base_path('.well-known'), public_path('.well-known')] as $directory) {
            $results[] = $this->write($directory . '/assetlinks.json', $this->androidDocument($settings));
            $results[] = $this->write($directory . '/apple-app-site-association', $this->appleDocument($settings));
        }

        return $results;
    }

    /**
     * Android verifies the whole host, so this file carries no path list — the app's intent filters
     * decide which paths it claims. config('deeplinks.android_paths') is what the app team has to
     * mirror there, and it is published on the setup screen and in the API documentation instead.
     *
     * @param  array<string, mixed>  $settings
     * @return array<int, array<string, mixed>>|null
     */
    public function androidDocument(array $settings): ?array
    {
        $package = trim((string) ($settings['android_package_name'] ?? ''));
        $fingerprint = trim((string) ($settings['android_sha256_fingerprint'] ?? ''));

        if ($package === '' || $fingerprint === '') {
            return null;
        }

        return [
            [
                'relation' => ['delegate_permission/common.handle_all_urls'],
                'target' => [
                    'namespace' => 'android_app',
                    'package_name' => $package,
                    'sha256_cert_fingerprints' => [$fingerprint],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    public function appleDocument(array $settings): ?array
    {
        $bundle = trim((string) ($settings['ios_bundle_id'] ?? ''));
        $team = trim((string) ($settings['ios_team_id'] ?? ''));

        if ($bundle === '' || $team === '') {
            return null;
        }

        return [
            'applinks' => [
                'apps' => [],
                'details' => [
                    [
                        'appID' => $team . '.' . $bundle,
                        'paths' => $this->links->paths(AppLinkService::PLATFORM_IOS) ?: ['*'],
                    ],
                ],
            ],
        ];
    }

    /**
     * What is on disk right now, so a screen can show the published list rather than the intended
     * one. The difference between the two is the whole failure mode this class exists to prevent.
     *
     * `claims_an_app` is the part that matters and the part that is easy to miss: a file can exist,
     * be valid JSON and carry the full path list while naming no app at all. The shipped
     * association file did exactly that — an appID of "." — and iOS discards such an entry, so
     * every universal link opened Safari while every screen reported the paths as published.
     *
     * @return array{exists: bool, claims_an_app: bool, paths: array<int, string>, app_id: ?string, package: ?string}
     */
    public function published(): array
    {
        $apple = $this->readJson(public_path('.well-known/apple-app-site-association'));
        $android = $this->readJson(public_path('.well-known/assetlinks.json'));

        $appId = $apple['applinks']['details'][0]['appID'] ?? null;
        $package = $android[0]['target']['package_name'] ?? null;

        return [
            'exists' => $apple !== null || $android !== null,
            'claims_an_app' => $this->namesAnApp($appId) || $this->namesAnApp($package),
            'paths' => (array) ($apple['applinks']['details'][0]['paths'] ?? []),
            'app_id' => $appId,
            'package' => $package,
        ];
    }

    /**
     * Is this identifier a real one, or a placeholder shaped like one?
     *
     * "." is what the writer produces from an empty team id and an empty bundle id, and it is what
     * shipped in the repository. It passes every test for "the file exists and parses" and claims
     * nothing.
     */
    private function namesAnApp(mixed $identifier): bool
    {
        $identifier = is_string($identifier) ? trim($identifier) : '';

        if ($identifier === '' || trim($identifier, '.') === '') {
            return false;
        }

        // An Apple appID is TEAMID.bundle.id: a leading or trailing dot means one half is missing.
        return !str_starts_with($identifier, '.') && !str_ends_with($identifier, '.');
    }

    /**
     * Does what is on disk match what this deployment would publish now?
     *
     * Compares the identifiers as well as the paths. Comparing paths alone reported the shipped
     * file — full path list, no app — as up to date.
     *
     * @param  array<string, mixed>  $settings
     */
    public function isCurrent(array $settings): bool
    {
        $published = $this->published();

        if (!$published['exists'] || !$published['claims_an_app']) {
            return false;
        }

        $apple = $this->appleDocument($settings);
        $android = $this->androidDocument($settings);

        if ($apple !== null && $published['app_id'] !== $apple['applinks']['details'][0]['appID']) {
            return false;
        }

        if ($android !== null && $published['package'] !== $android[0]['target']['package_name']) {
            return false;
        }

        return $published['paths'] === $this->links->paths(AppLinkService::PLATFORM_IOS);
    }

    /**
     * @return array<mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (!is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<mixed>|null  $document
     * @return array{path: string, written: bool, reason: ?string}
     */
    private function write(string $path, ?array $document): array
    {
        if ($document === null) {
            return ['path' => $path, 'written' => false, 'reason' => 'not_configured'];
        }

        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return ['path' => $path, 'written' => false, 'reason' => 'directory_could_not_be_created'];
        }

        if (!is_writable($directory)) {
            return ['path' => $path, 'written' => false, 'reason' => 'directory_is_not_writable'];
        }

        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($json === false || file_put_contents($path, $json) === false) {
            return ['path' => $path, 'written' => false, 'reason' => 'file_could_not_be_written'];
        }

        chmod($path, 0644);

        return ['path' => $path, 'written' => true, 'reason' => null];
    }
}
