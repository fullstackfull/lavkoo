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
 * Before anything is written, every file is checked against what could stop the deployment half-way: a PHP file this server's
 * PHP cannot parse, a directory or a link where a file or a directory must go, a language file the site left unreadable. If
 * `apply` is stopped anyway (a full disk), it takes back what it wrote. If it is killed, applied.json in the backup directory
 * still says what it had done, because it is written BEFORE each file is touched, and `rollback` takes that back; a new `apply`
 * is refused until an unfinished one has been rolled back.
 *
 * This script does not run migrations, clear caches or restart anything: those are separate, deliberate steps.
 */

const FORMAT = 1;
const ALLOWED_TOP_LEVEL = ['app', 'bootstrap', 'config', 'database', 'resources', 'routes'];
const LANGUAGE_FILE = '#^resources/lang/[a-z_]+/new-messages\.php$#';

exit((new Deploy())->run($argv));

final class Deploy
{
    private const TEMPORARY_PREFIX = '.deploy-';

    private const SWITCHES = ['yes', 'force', 'json', 'allow-backup-inside-app'];

    private const VALUES = ['app', 'package', 'backup-dir', 'accept-drift'];

    private const BLOCKING_STATES = ['DRIFTED', 'CONFLICT', 'MISSING', 'UNWRITABLE', 'SYMLINK', 'BLOCKED', 'UNPARSEABLE', 'UNREADABLE'];

    private const SILENT_STATES = ['CLEAN', 'NEW', 'ALREADY_APPLIED', 'MERGE'];

    private const AFTER_RESTORE = 'Now run php artisan optimize:clear, reset OPcache (reload PHP-FPM or restart the web server) and, if a queue worker runs, php artisan queue:restart. The database is untouched by this script.';

    private static bool $readingLanguageFile = false;

    private bool $json = false;

    private string $self = 'deploy.php';

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $this->self = (string) (realpath($argv[0] ?? '') ?: ($argv[0] ?? 'deploy.php'));
        $command = $argv[1] ?? '';
        // Known before the options are read, so that a mistake in them is reported the same way as every other error.
        $this->json = in_array('--json', array_slice($argv, 2), true);

        try {
            $options = $this->options(array_slice($argv, 2));

            return match ($command) {
                'verify' => $this->verifyCommand($options),
                'apply' => $this->applyCommand($options),
                'rollback' => $this->rollbackCommand($options),
                default => $this->usage(),
            };
        } catch (RuntimeException $exception) {
            $this->say('error', $exception->getMessage());

            return 2;
        } catch (JsonException $exception) {
            $this->say('error', "A file this tool reads is damaged ({$exception->getMessage()}). Nothing was changed.");

            return 2;
        }
    }

    private function usage(): int
    {
        fwrite(STDERR, "Usage: php deploy.php verify|apply|rollback [--app=PATH] [--package=PATH] [--backup-dir=PATH] [--accept-drift=a,b] [--yes] [--force] [--json]\n");

        return 2;
    }

    // ── verify ───────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, string|true> $options */
    private function verifyCommand(array $options): int
    {
        $app = $this->app($options);
        $report = $this->verify($app, $this->package($options), $this->acceptedDrift($options));
        $this->printReport($report);
        $this->noteOwnership($app);

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
            [$state, $detail] = $this->stateOf($app, $package, $entry, $accepted);
            if ($state !== 'ALREADY_APPLIED' && ($parseError = $this->parseError($package, $entry)) !== null) {
                [$state, $detail] = ['UNPARSEABLE', "This server's PHP " . PHP_VERSION . " cannot parse it ({$parseError}); the release needs a newer PHP."];
            }
            $files[] = ['path' => $entry['path'], 'status' => $entry['status'], 'state' => $state, 'detail' => $detail];
            if (in_array($state, self::BLOCKING_STATES, true)) {
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
    private function stateOf(string $app, string $package, array $entry, array $accepted): array
    {
        $path = $app . '/' . $entry['path'];

        if (($blocked = $this->pathProblem($app, $entry['path'])) !== null) {
            return $blocked;
        }
        if (!$this->writable($path)) {
            return ['UNWRITABLE', 'The web or deploy user cannot write here.'];
        }

        $exists = is_file($path);
        if ($entry['strategy'] === 'merge_language_keys') {
            return $exists ? $this->languageState($path, $package . '/files/' . $entry['path']) : ['NEW', 'The language file does not exist yet.'];
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

        return $this->unlessAccepted($entry['path'], $accepted, 'DRIFTED', "Differs from the baseline the release was built against (a hotfix or a runtime rewrite). Look at what changed before replacing it: diff -u {$package}/base/{$entry['path']} {$path}");
    }

    /**
     * What would stop the file being written, found before anything is: a link where the file goes, a link on the way to it, a
     * directory where the file goes, or a file where a directory must be.
     *
     * @return array{0: string, 1: string}|null
     */
    private function pathProblem(string $app, string $relative): ?array
    {
        $path = $app . '/' . $relative;
        if (is_link($path)) {
            return ['SYMLINK', 'The target is a symbolic link; the deployment will not write through it.'];
        }
        if (is_dir($path)) {
            return ['BLOCKED', 'A directory exists where the release puts a file.'];
        }

        for ($directory = dirname($path); strlen($directory) > strlen($app); $directory = dirname($directory)) {
            if (is_link($directory)) {
                return ['SYMLINK', 'A directory on the way to this file is a symbolic link; the deployment will not write through it.'];
            }
            if (file_exists($directory) && !is_dir($directory)) {
                return ['BLOCKED', 'A file exists where the release needs a directory.'];
            }
        }

        return null;
    }

    /** @return array{0: string, 1: string} */
    private function languageState(string $live, string $release): array
    {
        try {
            $merge = $this->mergedLanguage($live, $release);
        } catch (RuntimeException $problem) {
            return ['UNREADABLE', $problem->getMessage()];
        }

        return $merge['added'] === [] && $merge['replaced'] === []
            ? ['ALREADY_APPLIED', 'Every key of the release is already in the live file.']
            : ['MERGE', 'The language keys the release adds are merged into the live file; nothing the site added is lost.'];
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
        $this->noteOwnership($app);

        if (!$report['ok']) {
            $this->say('error', 'Refusing to apply: resolve the files listed above first. Nothing was changed.');

            return 1;
        }
        if (array_filter($report['files'], static fn (array $file): bool => $file['state'] !== 'ALREADY_APPLIED') === []) {
            $this->say('ok', 'Nothing to apply: every file of the release is already in place.');

            return 0;
        }
        if (!isset($options['yes'])) {
            $this->say('info', 'Dry run: nothing was changed. Add --yes to apply.');

            return 0;
        }

        $backup = $this->backupDirectory($app, $options);
        $journal = new AppliedJournal(path: $backup . '/applied.json', app: $app);

        try {
            $this->keepManifest($package, $backup);
            $this->applyFiles($app, $package, $report['files'], $backup, $journal);
            $journal->complete();
        } catch (Throwable $failure) {
            return $this->stopped($failure, $journal, $backup, $app);
        }

        $this->say('ok', $journal->fileCount() . " file(s) written. The originals are in {$backup}");
        $this->say('next', "To undo: php {$this->shellWord($this->self)} rollback {$this->shellWord('--backup-dir=' . $backup)}");

        return 0;
    }

    /** Which release this is, kept with the backup: the next one is built from the head recorded here. */
    private function keepManifest(string $package, string $backup): void
    {
        if (!copy($package . '/MANIFEST.json', $backup . '/MANIFEST.json') || !chmod($backup . '/MANIFEST.json', 0600)) {
            throw new RuntimeException("Could not keep the release's MANIFEST.json in {$backup}; nothing was written.");
        }
    }

    /** `apply` could not finish: take back whatever it had written, and say plainly where that leaves the application. */
    private function stopped(Throwable $failure, AppliedJournal $journal, string $backup, string $app): int
    {
        $this->say('error', $this->describe($failure));

        try {
            $problems = $this->undo($journal->document(), $backup, $app, force: false);
            if ($problems === []) {
                AppliedJournal::markRolledBack($backup . '/applied.json');
            }
        } catch (Throwable $undoFailure) {
            $problems = ['Taking the changes back failed too: ' . $this->describe($undoFailure)];
        }

        if ($problems === []) {
            $this->say('error', 'The deployment was stopped and every change it had made was taken back: the application is as it was before.');
            $this->say('next', self::AFTER_RESTORE);

            return 2;
        }

        array_map(fn (string $problem) => $this->say('error', $problem), $problems);
        $this->say('error', 'The deployment was stopped part-way and could not be taken back automatically: the application is half-updated. Keep it in maintenance mode.');
        $this->say('next', "To take every change back: php {$this->shellWord($this->self)} rollback {$this->shellWord('--backup-dir=' . $backup)}");

        return 2;
    }

    private function describe(Throwable $failure): string
    {
        return $failure instanceof RuntimeException ? $failure->getMessage() : 'Unexpected ' . $failure::class . ' in ' . basename($failure->getFile()) . ':' . $failure->getLine() . '.';
    }

    /** A word that can be pasted into a shell: quoted only when it has to be. */
    private function shellWord(string $value): string
    {
        return preg_match('#^[A-Za-z0-9_@%+=:,./-]+$#', $value) === 1 ? $value : escapeshellarg($value);
    }

    /** @param list<array<string, string>> $files the verify report's files */
    private function applyFiles(string $app, string $package, array $files, string $backup, AppliedJournal $journal): void
    {
        $entries = array_column($this->manifest($package)['files'], null, 'path');

        // New files first: nothing a replaced file refers to is missing, even for the moment between two writes.
        usort($files, static fn (array $left, array $right): int => [$entries[$left['path']]['status'] !== 'A', $left['path']] <=> [$entries[$right['path']]['status'] !== 'A', $right['path']]);

        foreach ($files as $file) {
            if ($file['state'] === 'ALREADY_APPLIED') {
                continue;
            }

            $entry = $entries[$file['path']];
            $target = $app . '/' . $entry['path'];
            $before = is_file($target) ? $this->backUp($target, $backup, $entry['path']) : null;
            $merge = $entry['strategy'] === 'merge_language_keys' && $before !== null ? $this->mergedLanguage($target, $package . '/files/' . $entry['path']) : null;
            $content = $merge['content'] ?? (string) file_get_contents($package . '/files/' . $entry['path']);

            // Recorded before they are made: a deployment cut off between the two leaves a record that names them.
            $missing = $this->missingDirectories(dirname($target), $app);
            $journal->directoriesCreated($missing);
            $this->createDirectories($missing);
            $journal->aboutToWrite([
                'path' => $entry['path'], 'strategy' => $entry['strategy'], 'existed' => $before !== null, 'sha256_before' => $before, 'sha256_after' => hash('sha256', $content),
                'merge' => $merge === null ? null : ['added' => $merge['added'], 'replaced' => $merge['replaced']],
            ]);
            $this->writeAtomically($target, $content, $before === null ? octdec($entry['mode']) : null);
        }
    }

    private function backUp(string $target, string $backup, string $relative): string
    {
        $copy = $backup . '/files/' . $relative;
        $this->makeDirectories(dirname($copy), $backup);
        if (!copy($target, $copy)) {
            throw new RuntimeException("Could not back up {$relative}; it was not replaced.");
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
        $this->watchForAFileThatEndsTheProcess();
        ob_start();
        self::$readingLanguageFile = true;
        try {
            $array = (static fn (string $file): mixed => include $file)($path);
            $printed = (string) ob_get_contents();
        } catch (Throwable) {
            throw new RuntimeException("{$path} cannot be read as PHP (the site may have been writing it when it was cut off); the language merge was not attempted.");
        } finally {
            self::$readingLanguageFile = false;
            ob_end_clean();
        }
        if ($printed !== '') {
            throw new RuntimeException("{$path} prints text when it is loaded (a byte-order mark, or text before <?php); the language merge was not attempted.");
        }
        if (!is_array($array)) {
            throw new RuntimeException("{$path} does not return an array; the language merge was not attempted.");
        }

        return $array;
    }

    /** A language file that calls exit would end this run with a success status and no report: say so, and fail. */
    private function watchForAFileThatEndsTheProcess(): void
    {
        static $watching = false;
        if ($watching) {
            return;
        }
        $watching = true;

        register_shutdown_function(static function (): void {
            if (self::$readingLanguageFile) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                fwrite(STDERR, "[error] A language file ended the process while it was being read. Nothing was changed by this step; look at the file and run again.\n");
                exit(2);
            }
        });
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

        $applied = $this->readJournal($appliedFile);
        if (isset($applied['rolled_back_at'])) {
            $this->say('info', 'This deployment was already rolled back: nothing to do.');

            return 0;
        }
        if (!$applied['complete']) {
            $this->say('info', 'This deployment did not finish. Everything it had written, or was about to write, is taken back.');
        }

        $app = isset($options['app']) ? $this->app($options) : $applied['app'];
        $this->assertJournalIsSafe($applied, $app, $backup);
        $problems = $this->undo($applied, $backup, $app, isset($options['force']));
        if ($problems !== []) {
            array_map(fn (string $problem) => $this->say('error', $problem), $problems);

            return 1;
        }
        AppliedJournal::markRolledBack($appliedFile);

        $this->say('ok', count($applied['files']) . ' file(s) restored to what they were before the deployment.');
        $this->say('next', self::AFTER_RESTORE);

        return 0;
    }

    /**
     * What `apply` recorded, or why it cannot be trusted: a record cut off while it was written (a power cut between the write and the
     * rename) must end in a message that says what to do by hand, not in a PHP fatal.
     *
     * @return array{app: string, complete: bool, files: list<array<string, mixed>>, created_directories: list<string>}&array<string, mixed>
     */
    private function readJournal(string $file): array
    {
        try {
            $document = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("{$file} cannot be read (it was cut off while it was being written). Nothing was changed. Take the deployment back by hand: copy the files kept under " . dirname($file) . '/files/ back over the application, and delete the files the release added (the MANIFEST.json beside it lists them).');
        }

        if (!is_array($document) || !is_string($document['app'] ?? null) || !is_bool($document['complete'] ?? null) || !is_array($document['files'] ?? null) || !is_array($document['created_directories'] ?? null)) {
            throw new RuntimeException("{$file} is not a record this deploy.php wrote. Nothing was changed.");
        }

        return $document;
    }

    /** The record names what rollback will write over and delete: every path in it must be one a release may touch, and every directory one inside the application. */
    private function assertJournalIsSafe(array $applied, string $app, string $backup): void
    {
        foreach ($applied['files'] as $file) {
            $complete = is_array($file) && is_string($file['path'] ?? null) && is_bool($file['existed'] ?? null) && array_key_exists('sha256_before', $file) && array_key_exists('merge', $file);
            if (!$complete) {
                throw new RuntimeException("The record in {$backup}/applied.json has an entry this deploy.php did not write. Nothing was changed.");
            }
            $this->assertSafePath($file['path']);
        }
        foreach ($applied['created_directories'] as $directory) {
            if (!is_string($directory) || !str_starts_with($directory, $app . '/') || str_contains($directory, '..')) {
                throw new RuntimeException("The record in {$backup}/applied.json names a directory outside the application: " . (is_string($directory) ? $directory : 'not a path') . '. Nothing was changed.');
            }
        }
    }

    /**
     * Puts every file the journal names back as it was: the saved original, or no file where the deployment created one. A file
     * is left alone, and reported, when it is neither what the deployment wrote nor what was there before: someone changed it since.
     *
     * @param array<string, mixed> $applied the journal
     * @return list<string> what stopped it, or none when everything was taken back
     */
    private function undo(array $applied, string $backup, string $app, bool $force): array
    {
        $problems = [];
        foreach ($applied['files'] as $file) {
            $target = $app . '/' . $file['path'];
            $known = array_filter([$file['sha256_before'], $file['sha256_after']]);
            if ($file['merge'] === null && is_file($target) && !in_array(hash_file('sha256', $target), $known, true) && !$force) {
                $problems[] = "{$file['path']} was changed after the deployment; its current version would be lost (use --force to restore the original anyway).";
            }
            // Not even --force writes an empty or different file over a live one: without its saved original there is nothing to put back.
            if ($file['merge'] === null && $file['existed'] && !$this->savedOriginalIsIntact($backup, $file)) {
                $problems[] = "{$file['path']}: the saved original in {$backup}/files is missing or damaged, so nothing was restored. Put this file back by hand from your own backup of the application.";
            }
        }
        if ($problems !== []) {
            return $problems;
        }

        // The order `apply` wrote in, reversed: the files the release replaced are put back before the files it added are removed,
        // so a request in flight never finds a replaced file asking for a class that is already gone.
        foreach (array_reverse($applied['files']) as $file) {
            $target = $app . '/' . $file['path'];
            try {
                if ($file['merge'] !== null) {
                    $this->undoLanguageMerge($target, $file['merge']);
                } elseif ($file['existed']) {
                    $this->writeAtomically($target, (string) file_get_contents($backup . '/files/' . $file['path']), null);
                } elseif (is_file($target)) {
                    unlink($target);
                }
                $this->removeLeftoverTemporaries(dirname($target));
            } catch (Throwable $failure) {
                $problems[] = "{$file['path']} could not be taken back: " . $this->describe($failure);
            }
        }
        foreach (array_reverse($applied['created_directories']) as $directory) {
            @rmdir($directory);
        }

        return $problems;
    }

    /** @param array<string, mixed> $file a journal entry for a file that existed before the deployment */
    private function savedOriginalIsIntact(string $backup, array $file): bool
    {
        $copy = $backup . '/files/' . $file['path'];

        return is_string($file['sha256_before'] ?? null) && is_file($copy) && hash_file('sha256', $copy) === $file['sha256_before'];
    }

    /** A write that was cut off can leave its temporary file beside the target. */
    private function removeLeftoverTemporaries(string $directory): void
    {
        foreach (is_dir($directory) ? scandir($directory) : [] as $name) {
            if (str_starts_with($name, self::TEMPORARY_PREFIX)) {
                unlink($directory . '/' . $name);
            }
        }
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
        $root = $this->canonical(isset($options['backup-dir']) ? (string) $options['backup-dir'] : dirname($app) . '/pharmacy-deploy-backups/' . gmdate('Ymd-His'));
        if (str_starts_with($root . '/', $app . '/') && !isset($options['allow-backup-inside-app'])) {
            throw new RuntimeException("The backup directory {$root} is inside the application tree, which may be served to the web. Choose a directory outside it.");
        }
        if (is_dir($root) && $this->entries($root) !== []) {
            throw new RuntimeException("The backup directory {$root} is not empty; refusing to mix two deployments.");
        }
        foreach ($this->unfinishedDeployments(dirname($root)) as $unfinished) {
            throw new RuntimeException("A deployment recorded in {$unfinished} did not finish and was not rolled back, so the application may be half-updated. Take it back first: php {$this->shellWord($this->self)} rollback {$this->shellWord('--backup-dir=' . $unfinished)}");
        }
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException("Could not create {$root}.");
        }

        return $root;
    }

    /**
     * Every entry of a directory, dot files included. Not glob(): its pattern syntax reads a `[` or a `*` in a path as a pattern, and it
     * never lists a name that starts with a dot.
     *
     * @return list<string>
     */
    private function entries(string $directory): array
    {
        $names = scandir($directory);
        if ($names === false) {
            throw new RuntimeException("Could not read {$directory}.");
        }

        return array_values(array_diff($names, ['.', '..']));
    }

    /**
     * The backup directories beside this one whose deployment was cut off and never rolled back. A record that cannot be read counts as
     * one that did not finish: nobody can say what that deployment did.
     *
     * @return list<string>
     */
    private function unfinishedDeployments(string $directory): array
    {
        $unfinished = [];
        foreach (is_dir($directory) ? $this->entries($directory) : [] as $name) {
            $journal = "{$directory}/{$name}/applied.json";
            if (!is_file($journal)) {
                continue;
            }

            $document = json_decode((string) file_get_contents($journal), true);
            if (!is_array($document) || (($document['complete'] ?? false) !== true && !isset($document['rolled_back_at']))) {
                $unfinished[] = dirname($journal);
            }
        }

        return $unfinished;
    }

    /** An absolute path with no `.` or `..` and every link on the way resolved, for a path that may not exist yet. */
    private function canonical(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_starts_with($path, '/') ? $path : getcwd() . '/' . $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        $existing = '/' . implode('/', $segments);
        $missing = [];
        while (!file_exists($existing) && $existing !== '/') {
            array_unshift($missing, basename($existing));
            $existing = dirname($existing);
        }

        return rtrim((string) realpath($existing), '/') . ($missing === [] ? '' : '/' . implode('/', $missing));
    }

    /** @return array{format: int, base: string, head: string, files: list<array<string, mixed>>} */
    private function manifest(string $package): array
    {
        $this->assertManifestIntact($package);
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

    /** The manifest is what every other check trusts, so it must be the one the package was built with. */
    private function assertManifestIntact(string $package): void
    {
        $expected = null;
        foreach (preg_split('/\R/', is_file($package . '/SHA256SUMS') ? (string) file_get_contents($package . '/SHA256SUMS') : '') ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{64})  MANIFEST\.json$/', $line, $match) === 1) {
                $expected = $match[1];
            }
        }

        if ($expected === null || $expected !== hash_file('sha256', $package . '/MANIFEST.json')) {
            throw new RuntimeException('The package is damaged: MANIFEST.json does not match SHA256SUMS. Upload it again.');
        }
    }

    private function assertSafePath(string $path): void
    {
        $segments = explode('/', $path);
        if ($path === '' || str_starts_with($path, '/') || in_array('..', $segments, true) || !in_array($segments[0], ALLOWED_TOP_LEVEL, true) || str_starts_with(basename($path), '.env')) {
            throw new RuntimeException("The package names a path it may not touch: '{$path}'. It may only contain files under " . implode(', ', ALLOWED_TOP_LEVEL) . '.');
        }
    }

    /** @param array<string, mixed> $entry @return string|null why this server's PHP cannot parse the file the release would install */
    private function parseError(string $package, array $entry): ?string
    {
        if ($entry['strategy'] !== 'replace' || !str_ends_with($entry['path'], '.php')) {
            return null;
        }

        try {
            token_get_all((string) file_get_contents($package . '/files/' . $entry['path']), TOKEN_PARSE);
        } catch (ParseError $error) {
            return "{$error->getMessage()} on line {$error->getLine()}";
        }

        return null;
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

    /** @return list<string> the directories between $root and $directory that do not exist yet, outermost first */
    private function missingDirectories(string $directory, string $root): array
    {
        $missing = [];
        for ($path = $directory; !is_dir($path) && str_starts_with($path, $root); $path = dirname($path)) {
            array_unshift($missing, $path);
        }

        return $missing;
    }

    /** @param list<string> $missing outermost first */
    private function createDirectories(array $missing): void
    {
        foreach ($missing as $path) {
            if (!mkdir($path, 0755) && !is_dir($path)) {
                throw new RuntimeException("Could not create {$path}.");
            }
            // The mode follows the directory it is made in, not the umask of whoever runs this: a directory the web server cannot enter
            // would hide every file the release puts in it.
            chmod($path, fileperms(dirname($path)) & 02777);
            $this->copyOwnership(dirname($path), $path);
        }
    }

    private function makeDirectories(string $directory, string $root): void
    {
        $this->createDirectories($this->missingDirectories($directory, $root));
    }

    private function writeAtomically(string $target, string $content, ?int $mode): void
    {
        $existing = is_file($target);
        $permissions = $existing ? (fileperms($target) & 0777) : null;
        $temporary = dirname($target) . '/' . self::TEMPORARY_PREFIX . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $content) !== strlen($content)) {
            @unlink($temporary);
            throw new RuntimeException("Could not write {$target} completely (is the disk full?); it was not replaced.");
        }
        chmod($temporary, $permissions ?? $mode ?? 0644);
        $this->copyOwnership($existing ? $target : dirname($target), $temporary);
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException("Could not replace {$target}.");
        }
    }

    /**
     * A replaced file keeps its owner and group, and a new file or directory takes its parent's: the web user has to go on being
     * able to write the language files. Only root can give a file to someone else; anyone can give it to a group they belong to.
     */
    private function copyOwnership(string $from, string $to): void
    {
        // PHP remembers the owner it saw: after the first directory of a chain was given away, the next one would still read the old owner.
        clearstatcache();
        $owner = fileowner($from);
        $group = filegroup($from);
        if ($owner !== false && $owner !== fileowner($to) && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            @chown($to, $owner);
        }
        if ($group !== false && $group !== filegroup($to)) {
            @chgrp($to, $group);
        }
    }

    private function noteOwnership(string $app): void
    {
        if (!function_exists('posix_geteuid')) {
            $this->say('warn', "PHP's posix extension is not available, so the owner and group of the files this script writes cannot be kept. Check afterwards that the language files are still writable by the web server's user.");

            return;
        }

        $owner = fileowner($app . '/artisan');
        if ($owner === false || $owner === posix_geteuid()) {
            return;
        }

        $this->say('info', posix_geteuid() === 0
            ? 'Running as root: files keep the owner and group they had, and new ones take their directory\'s.'
            : 'Running as user ' . posix_geteuid() . " but the application's files belong to user {$owner}: what this script writes will belong to you, and the web server may then be unable to write it (the language files must stay writable). Run it as that user.");
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
            if (!in_array($file['state'], self::SILENT_STATES, true)) {
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

    /**
     * Switches are written --name, values --name=value; anything else is an error rather than something quietly ignored: a
     * confirmation given as --yes=false, or a directory typed with a space, must not be taken for what it is not.
     *
     * @param list<string> $arguments @return array<string, string|true>
     */
    private function options(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $match) !== 1) {
                throw new RuntimeException("Unexpected argument '{$argument}'. Options are written --name, or --name=value.");
            }

            $name = $match[1];
            $value = $match[2] ?? null;
            if (!in_array($name, [...self::SWITCHES, ...self::VALUES], true)) {
                throw new RuntimeException("Unknown option --{$name}.");
            }
            if (in_array($name, self::SWITCHES, true) && $value !== null) {
                throw new RuntimeException("--{$name} is a switch and takes no value.");
            }
            if (in_array($name, self::VALUES, true) && ($value === null || $value === '')) {
                throw new RuntimeException("--{$name} needs a value, written --{$name}=...");
            }

            $options[$name] = $value ?? true;
        }

        return $options;
    }
}

/**
 * What `apply` has done, on disk BEFORE each file is written: a deployment that stops part-way (a full disk, a dropped
 * connection) leaves a record `rollback` can act on, instead of a half-updated tree nobody can take back.
 */
final class AppliedJournal
{
    /** @var list<array<string, mixed>> */
    private array $files = [];

    /** @var list<string> */
    private array $directories = [];

    private ?string $completedAt = null;

    public function __construct(private readonly string $path, private readonly string $app)
    {
        $this->save();
    }

    public static function markRolledBack(string $path): void
    {
        $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $document['rolled_back_at'] = gmdate('c');
        self::write($path, $document);
    }

    /** @param list<string> $created */
    public function directoriesCreated(array $created): void
    {
        if ($created !== []) {
            array_push($this->directories, ...$created);
            $this->save();
        }
    }

    /** @param array<string, mixed> $entry what is about to be written to one file */
    public function aboutToWrite(array $entry): void
    {
        $this->files[] = $entry;
        $this->save();
    }

    public function complete(): void
    {
        $this->completedAt = gmdate('c');
        $this->save();
    }

    public function fileCount(): int
    {
        return count($this->files);
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return [
            'app' => $this->app,
            'complete' => $this->completedAt !== null,
            'applied_at' => $this->completedAt,
            'files' => $this->files,
            'created_directories' => $this->directories,
        ];
    }

    private function save(): void
    {
        self::write($this->path, $this->document());
    }

    /** @param array<string, mixed> $document */
    private static function write(string $path, array $document): void
    {
        $contents = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $temporary = $path . '.tmp';

        if (file_put_contents($temporary, $contents) !== strlen($contents) || !chmod($temporary, 0600) || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException("Could not record the deployment in {$path}; nothing was written for this step.");
        }
    }
}
