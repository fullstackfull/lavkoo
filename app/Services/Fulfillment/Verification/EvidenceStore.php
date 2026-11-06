<?php

namespace App\Services\Fulfillment\Verification;

/**
 * Keeps each verification run as one JSON file outside the web root (storage/app/fulfillment-evidence/<provider>/), so that
 * the evidence can be downloaded, sent for review, and compared with a later run. The files hold the response SHAPE and nothing
 * personal or secret (see VerificationRun::toArray), and storage/app is not tracked by git.
 */
final class EvidenceStore
{
    private const RUN_ID = '/^(m5azn|torod_point)_\d{8}_\d{6}_[a-f0-9]{8}$/';

    private const DENY_RULES = <<<'HTACCESS'
# Verification evidence is read in the admin panel only; the web server never serves it directly.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>

HTACCESS;

    public function newRunId(string $provider): string
    {
        return $provider . '_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
    }

    /**
     * @throws \RuntimeException when the evidence could not be written; the caller records that, so a run is never counted as evidence it did not keep
     */
    public function save(VerificationRun $run): string
    {
        $directory = $this->directory($run->provider);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('The evidence directory could not be created.');
        }
        $this->denyDirectWebAccess();

        // Text the provider sent that is not valid UTF-8 is substituted, a number that cannot be written (INF) is zeroed: neither may turn the file into nothing.
        $json = json_encode($run->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $path = $directory . '/' . $run->id . '.json';
        if ($json === false || file_put_contents($path, $json . "\n") === false) {
            throw new \RuntimeException('The evidence file could not be written.');
        }
        chmod($path, 0640);

        return $path;
    }

    /** @return list<array{run_id: string, started_at: string, summary: array<string, int>}> newest first */
    public function recent(string $provider, int $limit = 5): array
    {
        $files = glob($this->directory($provider) . '/*.json') ?: [];
        rsort($files);

        $runs = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded) && isset($decoded['run_id'], $decoded['summary'])) {
                $runs[] = ['run_id' => (string) $decoded['run_id'], 'started_at' => (string) ($decoded['started_at'] ?? ''), 'summary' => (array) $decoded['summary']];
            }
        }

        return $runs;
    }

    /** The saved run, or null. The id must be one this store made: it is part of a file path. */
    public function find(string $provider, string $runId): ?array
    {
        $path = $this->path($provider, $runId);
        $decoded = $path === null ? null : json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function path(string $provider, string $runId): ?string
    {
        if (preg_match(self::RUN_ID, $runId, $match) !== 1 || $match[1] !== $provider) {
            return null;
        }

        $path = $this->directory($provider) . '/' . $runId . '.json';

        return is_file($path) ? $path : null;
    }

    /**
     * This platform can be served from the application's root directory instead of public/, and there any existing file is handed to
     * whoever asks for its address. The evidence is read through the admin panel only, so the web server is told never to serve it.
     */
    private function denyDirectWebAccess(): void
    {
        $rules = dirname($this->directory('x')) . '/.htaccess';
        if (!is_file($rules)) {
            file_put_contents($rules, self::DENY_RULES);
        }
    }

    private function directory(string $provider): string
    {
        return storage_path('app/fulfillment-evidence/' . $provider);
    }
}
