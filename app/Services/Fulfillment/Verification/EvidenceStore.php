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

    public function newRunId(string $provider): string
    {
        return $provider . '_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
    }

    public function save(VerificationRun $run): string
    {
        $directory = $this->directory($run->provider);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('The evidence directory could not be created.');
        }

        $path = $directory . '/' . $run->id . '.json';
        file_put_contents($path, json_encode($run->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
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

    private function directory(string $provider): string
    {
        return storage_path('app/fulfillment-evidence/' . $provider);
    }
}
