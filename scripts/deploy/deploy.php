#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Applies a reviewed overlay (the added and modified files of a release) to an application tree that may have drifted from
 * the code the release was built against, and can take every change back. Standalone: it needs only PHP, not Composer, git,
 * artisan or a database, and it never prints the contents of a file.
 *
 *   php deploy.php verify   --app=/path/to/app [--package=/path/to/overlay] [--json]
 *   php deploy.php apply    --app=/path/to/app [--package=...] --yes [--backup-dir=...] [--accept-drift=a.php,b.php]
 *   php deploy.php rollback --backup-dir=/path/to/the/backup/apply/printed [--app=...] [--force]
 *
 * `verify` and a plain `apply` change nothing. A file that differs from the baseline the release was built against is DRIFT:
 * `apply` refuses it until a person has looked at it (--accept-drift names the files explicitly). The language files are
 * never overwritten: this application rewrites them at request time, so their keys are MERGED, keeping everything the
 * live site has accumulated. Every file that is replaced is first copied to a backup directory OUTSIDE the application tree.
 *
 * This script does not run migrations, clear caches or restart anything: those are separate, deliberate steps.
 */

const FORMAT = 1;
const ALLOWED_TOP_LEVEL = ['app', 'bootstrap', 'config', 'database', 'resources', 'routes'];
const LANGUAGE_FILE = '#^resources/lang/[a-z_]+/new-messages\.php$#';

exit((new Deploy())->run($argv));

final class Deploy
{
    private bool $json = false;

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? '';
        $options = $this->options(array_slice($argv, 2));
        $this->json = isset($options['json']);

        try {
            return match ($command) {
                'verify' => $this->verifyCommand($options),
                'apply' => $this->applyCommand($options),
                'rollback' => $this->rollbackCommand($options),
                default => $this->usage(),
            };
        } catch (RuntimeException $exception) {
            $this->say('error', $exception->getMessage());

            return 2;
        }
    }

    private function usage(): int
    {
        fwrite(STDERR, "Usage: php deploy.php verify|apply|rollback --app=PATH [--package=PATH] [--backup-dir=PATH] [--accept-drift=a,b] [--yes] [--force] [--json]\n");

        return 2;
    }

    // ── verify ───────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, string|true> $options */
    private function verifyCommand(array $options): int
    {
        $report = $this->verify($this->app($options), $this->package($options), $this->acceptedDrift($options));
        $this->printReport($report);

        return $report['ok'] ? 0 : 1;
    }

    /**
     * @param list<string> $accepted
     * @return array{ok: bool, counts: array<string, int>, files: list<array<string, string>>, problems: list<string>}
     */
    private function verify(string $app, string $package, array $accepted): array
    {
        $manifest = $this->manifest($package);
        $files = [];
        $problems = [];

        foreach ($manifest['files'] as $entry) {
            [$state, $detail] = $this->stateOf($app, $entry, $accepted);
            $files[] = ['path' => $entry['path'], 'status' => $entry['status'], 'state' => $state, 'detail' => $detail];
            if (in_array($state, ['DRIFTED', 'CONFLICT', 'MISSING', 'UNWRITABLE', 'SYMLINK'], true)) {
                $problems[] = "{$entry['path']}: {$state}. {$detail}";
            }
        }

        $counts = array_count_values(array_column($files, 'state'));
        ksort($counts);

        return ['ok' => $problems === [], 'counts' => $counts, 'files' => $files, 'problems' => $problems];
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<string> $accepted
     * @return array{0: string, 1: string} [state, explanation]
     */
    private function stateOf(string $app, array $entry, array $accepted): array
    {
        $path = $app . '/' . $entry['path'];
        $exists = is_file($path);

        if (is_link($path)) {
            return ['SYMLINK', 'The target is a symbolic link; the deployment will not write through it.'];
        }
        if (!$this->writable($path)) {
            return ['UNWRITABLE', 'The web or deploy user cannot write here.'];
        }
        if ($entry['strategy'] === 'merge_language_keys') {
            return $exists ? ['MERGE', 'The language keys the release adds are merged into the live file; nothing the site added is lost.'] : ['NEW', 'The language file does not exist yet.'];
        }
        if (!$exists) {
            return $entry['status'] === 'A' ? ['NEW', 'Will be created.'] : ['MISSING', 'The release changes this file, but it is not on the server: this is not the code the release was built against.'];
        }

        $live = hash_file('sha256', $path);
        if ($live === $entry['new_sha256']) {
            return ['ALREADY_APPLIED', 'Identical to the release.'];
        }
        if ($entry['status'] === 'A') {
            return $this->unlessAccepted($entry['path'], $accepted, 'CONFLICT', 'A file with this name already exists and differs from the one in the release.');
        }
        if ($live === $entry['base_sha256']) {
            return ['CLEAN', 'Identical to the baseline the release was built against: will be replaced.'];
        }

        return $this->unlessAccepted($entry['path'], $accepted, 'DRIFTED', 'Differs from the baseline the release was built against (a hotfix or a runtime rewrite). Look at it before replacing it.');
    }

    /** @param list<string> $accepted @return array{0: string, 1: string} */
    private function unlessAccepted(string $path, array $accepted, string $state, string $detail): array
    {
        return in_array($path, $accepted, true) ? ['ACCEPTED', 'Differs, and a person accepted replacing it (its live version is backed up).'] : [$state, $detail];
    }

    // ── apply ────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, string|true> $options */
    private function applyCommand(array $options): int
    {
        $app = $this->app($options);
        $package = $this->package($options);
        $report = $this->verify($app, $package, $this->acceptedDrift($options));
        $this->printReport($report);

        if (!$report['ok']) {
            $this->say('error', 'Refusing to apply: resolve the files listed above first. Nothing was changed.');

            return 1;
        }
        if (!isset($options['yes'])) {
            $this->say('info', 'Dry run: nothing was changed. Add --yes to apply.');

            return 0;
        }

        $backup = $this->backupDirectory($app, $options);
        $applied = $this->applyFiles($app, $package, $report['files'], $backup);
        file_put_contents($backup . '/applied.json', json_encode(['app' => $app, 'applied_at' => gmdate('c'), 'files' => $applied['files'], 'created_directories' => $applied['directories']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        chmod($backup . '/applied.json', 0600);

        $this->say('ok', count($applied['files']) . " file(s) written. The originals are in {$backup}");
        $this->say('next', "To undo: php deploy.php rollback --backup-dir={$backup}");

        return 0;
    }

    /**
     * @param list<array<string, string>> $files the verify report's files
     * @return array{files: list<array<string, mixed>>, directories: list<string>}
     */
    private function applyFiles(string $app, string $package, array $files, string $backup): array
    {
        $manifest = $this->manifest($package);
        $entries = array_column($manifest['files'], null, 'path');
        $written = [];
        $directories = [];

        foreach ($files as $file) {
            if ($file['state'] === 'ALREADY_APPLIED') {
                continue;
            }

            $entry = $entries[$file['path']];
            $target = $app . '/' . $entry['path'];
            $before = is_file($target) ? $this->backUp($target, $backup, $entry['path']) : null;
            $merge = $entry['strategy'] === 'merge_language_keys' && $before !== null ? $this->mergedLanguage($target, $package . '/files/' . $entry['path']) : null;
            $content = $merge['content'] ?? (string) file_get_contents($package . '/files/' . $entry['path']);
            $this->assertParses($entry['path'], $content);

            array_push($directories, ...$this->makeDirectories(dirname($target), $app));
            $this->writeAtomically($target, $content, $before === null ? octdec($entry['mode']) : null);
            $written[] = [
                'path' => $entry['path'], 'strategy' => $entry['strategy'], 'existed' => $before !== null, 'sha256_before' => $before, 'sha256_after' => hash('sha256', $content),
                'merge' => $merge === null ? null : ['added' => $merge['added'], 'replaced' => $merge['replaced']],
            ];
        }

        return ['files' => $written, 'directories' => array_values(array_unique($directories))];
    }

    private function backUp(string $target, string $backup, string $relative): string
    {
        $copy = $backup . '/files/' . $relative;
        $this->makeDirectories(dirname($copy), $backup);
        if (!copy($target, $copy)) {
            throw new RuntimeException("Could not back up {$relative}; nothing further was written.");
        }
        chmod($copy, 0600);

        return (string) hash_file('sha256', $target);
    }

    // ── language files ───────────────────────────────────────────────────────────────────────────────────────

    /**
     * The live language file with the release's keys added. A live value that is only the placeholder translate() wrote when the
     * key was first asked for (the key, humanised) is replaced by the release's real text; every other live value is kept.
     *
     * @return array{content: string, added: list<string>, replaced: array<string, string>} what was written, and what a rollback must undo
     */
    private function mergedLanguage(string $livePath, string $releasePath): array
    {
        $live = $this->languageArray($livePath);
        $release = $this->languageArray($releasePath);
        $added = [];
        $replaced = [];

        foreach ($release as $key => $value) {
            if (!array_key_exists($key, $live)) {
                $live[$key] = $value;
                $added[] = (string) $key;
            } elseif ($live[$key] === $this->placeholder((string) $key) && $value !== $live[$key]) {
                $replaced[(string) $key] = (string) $live[$key];
                $live[$key] = $value;
            }
        }

        return ['content' => $this->languageFile($live), 'added' => $added, 'replaced' => $replaced];
    }

    /** @param array<string, string> $messages the file in the exact form this application writes it */
    private function languageFile(array $messages): string
    {
        $contents = "<?php\n\nreturn [\n";
        foreach ($messages as $key => $value) {
            $contents .= "\t" . var_export((string) $key, true) . ' => ' . var_export((string) $value, true) . ",\n";
        }

        return $contents . "];\n";
    }

    /** @return array<string, string> */
    private function languageArray(string $path): array
    {
        $array = (static fn (string $file): mixed => include $file)($path);
        if (!is_array($array)) {
            throw new RuntimeException("{$path} does not return an array; the language merge was not attempted.");
        }

        return $array;
    }

    /** What translate() stores for a key nobody has translated: the same transformation as app/Utils/language.php. */
    private function placeholder(string $key): string
    {
        $key = str_replace(' ', '_', str_replace('"', '', $key));
        $clean = str_ireplace(['\'', '"', ';', '<', '>', '?', '“', '”'], ' ', (string) preg_replace('/\s\s+/', ' ', $key));

        return ucfirst(str_replace('_', ' ', $clean));
    }

    // ── rollback ─────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, string|true> $options */
    private function rollbackCommand(array $options): int
    {
        $backup = rtrim((string) ($options['backup-dir'] ?? ''), '/');
        $appliedFile = $backup . '/applied.json';
        if ($backup === '' || !is_file($appliedFile)) {
            throw new RuntimeException('--backup-dir must be the directory `apply` printed (it holds applied.json).');
        }

        $applied = json_decode((string) file_get_contents($appliedFile), true, 512, JSON_THROW_ON_ERROR);
        $app = isset($options['app']) ? $this->app($options) : (string) $applied['app'];
        $force = isset($options['force']);
        $problems = [];

        foreach ($applied['files'] as $file) {
            $target = $app . '/' . $file['path'];
            if ($file['merge'] === null && is_file($target) && hash_file('sha256', $target) !== $file['sha256_after'] && !$force) {
                $problems[] = "{$file['path']} was changed after the deployment; its current version would be lost (use --force to restore the original anyway).";
            }
        }
        if ($problems !== []) {
            array_map(fn (string $problem) => $this->say('error', $problem), $problems);

            return 1;
        }

        foreach ($applied['files'] as $file) {
            $target = $app . '/' . $file['path'];
            if ($file['merge'] !== null) {
                $this->undoLanguageMerge($target, $file['merge']);
            } elseif ($file['existed']) {
                $this->writeAtomically($target, (string) file_get_contents($backup . '/files/' . $file['path']), null);
            } elseif (is_file($target)) {
                unlink($target);
            }
        }
        foreach (array_reverse($applied['created_directories']) as $directory) {
            @rmdir($directory);
        }

        $this->say('ok', count($applied['files']) . ' file(s) restored to what they were before the deployment.');
        $this->say('next', 'Now run php artisan optimize:clear, reset OPcache (reload PHP-FPM or restart the web server) and, if a queue worker runs, php artisan queue:restart. The database is untouched by this script.');

        return 0;
    }

    /**
     * Takes back only what the deployment put into a language file. The live site writes to these files while it runs, so
     * restoring the copy taken before the deployment would erase everything it added since; this keeps all of that.
     *
     * @param array{added: list<string>, replaced: array<string, string>} $merge
     */
    private function undoLanguageMerge(string $target, array $merge): void
    {
        if (!is_file($target)) {
            return;
        }

        $messages = $this->languageArray($target);
        foreach ($merge['added'] as $key) {
            unset($messages[$key]);
        }
        foreach ($merge['replaced'] as $key => $previous) {
            $messages[$key] = $previous;
        }

        $this->writeAtomically($target, $this->languageFile($messages), null);
    }

    // ── plumbing ─────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, string|true> $options */
    private function app(array $options): string
    {
        $app = realpath((string) ($options['app'] ?? getcwd()));
        if ($app === false || !is_file($app . '/artisan') || !is_file($app . '/bootstrap/app.php')) {
            throw new RuntimeException('--app must be the application directory (the one that holds artisan and bootstrap/app.php).');
        }

        return $app;
    }

    /** @param array<string, string|true> $options */
    private function package(array $options): string
    {
        $package = realpath((string) ($options['package'] ?? __DIR__));
        if ($package === false || !is_file($package . '/MANIFEST.json')) {
            throw new RuntimeException('--package must be the unpacked overlay directory (it holds MANIFEST.json and files/).');
        }

        return $package;
    }

    /** @param array<string, string|true> $options @return list<string> */
    private function acceptedDrift(array $options): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) ($options['accept-drift'] ?? '')))));
    }

    /** @param array<string, string|true> $options */
    private function backupDirectory(string $app, array $options): string
    {
        $root = isset($options['backup-dir']) ? rtrim((string) $options['backup-dir'], '/') : dirname($app) . '/pharmacy-deploy-backups/' . gmdate('Ymd-His');
        $inside = str_starts_with($root . '/', $app . '/');
        if ($inside && !isset($options['allow-backup-inside-app'])) {
            throw new RuntimeException("The backup directory {$root} is inside the application tree, which may be served to the web. Choose a directory outside it.");
        }
        if (is_dir($root) && glob($root . '/*') !== []) {
            throw new RuntimeException("The backup directory {$root} is not empty; refusing to mix two deployments.");
        }
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException("Could not create {$root}.");
        }

        return $root;
    }

    /** @return array{format: int, base: string, head: string, files: list<array<string, mixed>>} */
    private function manifest(string $package): array
    {
        $manifest = json_decode((string) file_get_contents($package . '/MANIFEST.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null) !== FORMAT || !is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('MANIFEST.json is not in a format this deploy.php understands.');
        }

        foreach ($manifest['files'] as $entry) {
            $this->assertSafePath($entry['path'] ?? '');
            $source = $package . '/files/' . $entry['path'];
            if (!is_file($source) || hash_file('sha256', $source) !== $entry['new_sha256']) {
                throw new RuntimeException("The package is damaged: files/{$entry['path']} is missing or does not match its checksum. Upload it again.");
            }
        }

        return $manifest;
    }

    private function assertSafePath(string $path): void
    {
        $segments = explode('/', $path);
        if ($path === '' || str_starts_with($path, '/') || in_array('..', $segments, true) || !in_array($segments[0], ALLOWED_TOP_LEVEL, true) || str_starts_with(basename($path), '.env')) {
            throw new RuntimeException("The package names a path it may not touch: '{$path}'. It may only contain files under " . implode(', ', ALLOWED_TOP_LEVEL) . '.');
        }
    }

    private function assertParses(string $path, string $content): void
    {
        if (!str_ends_with($path, '.php')) {
            return;
        }

        try {
            token_get_all($content, TOKEN_PARSE);
        } catch (ParseError $error) {
            throw new RuntimeException("{$path} would not parse ({$error->getMessage()}); nothing further was written.");
        }
    }

    private function writable(string $path): bool
    {
        if (is_file($path)) {
            return is_writable($path) && is_writable(dirname($path));
        }

        $directory = dirname($path);
        while (!is_dir($directory) && $directory !== dirname($directory)) {
            $directory = dirname($directory);
        }

        return is_writable($directory);
    }

    /** @return list<string> the directories that did not exist and were created */
    private function makeDirectories(string $directory, string $root): array
    {
        $created = [];
        $missing = [];
        for ($path = $directory; !is_dir($path) && str_starts_with($path, $root); $path = dirname($path)) {
            array_unshift($missing, $path);
        }
        foreach ($missing as $path) {
            if (!mkdir($path, 0755) && !is_dir($path)) {
                throw new RuntimeException("Could not create {$path}.");
            }
            $created[] = $path;
        }

        return $created;
    }

    private function writeAtomically(string $target, string $content, ?int $mode): void
    {
        $existing = is_file($target) ? (fileperms($target) & 0777) : null;
        $temporary = dirname($target) . '/.deploy-' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $content) === false) {
            throw new RuntimeException("Could not write {$target}.");
        }
        chmod($temporary, $existing ?? $mode ?? 0644);
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException("Could not replace {$target}.");
        }
    }

    // ── output ───────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array{ok: bool, counts: array<string, int>, files: list<array<string, string>>, problems: list<string>} $report */
    private function printReport(array $report): void
    {
        if ($this->json) {
            echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

            return;
        }

        foreach ($report['files'] as $file) {
            if (!in_array($file['state'], ['CLEAN', 'NEW', 'ALREADY_APPLIED', 'MERGE'], true)) {
                echo sprintf("  %-9s %s\n            %s\n", $file['state'], $file['path'], $file['detail']);
            }
        }
        echo 'Summary: ' . implode(', ', array_map(static fn (string $state, int $count): string => "{$count} {$state}", array_keys($report['counts']), $report['counts'])) . "\n";
        echo $report['ok'] ? "Result: every file can be applied.\n" : "Result: NOT SAFE to apply.\n";
    }

    private function say(string $kind, string $message): void
    {
        if ($this->json) {
            fwrite(STDERR, "[{$kind}] {$message}\n");

            return;
        }

        echo "[{$kind}] {$message}\n";
    }

    /** @param list<string> $arguments @return array<string, string|true> */
    private function options(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $match) === 1) {
                $options[$match[1]] = $match[2] ?? true;
            }
        }

        return $options;
    }
}
