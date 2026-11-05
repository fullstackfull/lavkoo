#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds the overlay package for deploy.php from two git revisions: every file the release ADDS or MODIFIES under the
 * application directories, with the checksum of its baseline version, so that the server can tell whether the file it is about
 * to replace is still the one the release was built against.
 *
 *   php scripts/deploy/build-overlay.php --base=<baseline> --head=<release> --out=build/overlay [--repo=.] [--archive]
 *
 * It refuses, rather than guesses, whenever the release does something an overlay cannot do: a deleted file, a changed
 * dependency list, or a changed file outside the application directories (public/, Modules/, composer.*, the web server
 * entry point). Those need a person's decision and are named in the error. Documentation, tests and CI files are not
 * part of what runs, and are left out.
 */

const FORMAT = 1;
const SHIPPED_TOP_LEVEL = ['app', 'bootstrap', 'config', 'database', 'resources', 'routes'];
const DEV_ONLY = ['tests/', 'docs/', '.github/', 'scripts/', '.claude/', 'CLAUDE.md', '.gitignore', '.env.example', 'phpunit.xml', 'phpunit.mysql.xml', 'README.md'];
const LANGUAGE_FILE = '#^resources/lang/[a-z_]+/new-messages\.php$#';

exit(build($argv));

/** @param list<string> $argv */
function build(array $argv): int
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $match) === 1) {
            $options[$match[1]] = $match[2] ?? true;
        }
    }

    foreach (['base', 'head', 'out'] as $required) {
        if (!isset($options[$required]) || $options[$required] === true) {
            fwrite(STDERR, "Usage: php build-overlay.php --base=REV --head=REV --out=DIR [--repo=.] [--archive]\n");

            return 2;
        }
    }

    try {
        $repo = realpath((string) ($options['repo'] ?? '.')) ?: throw new RuntimeException('--repo is not a directory.');
        $base = git($repo, ['rev-parse', '--verify', $options['base'] . '^{commit}']);
        $head = git($repo, ['rev-parse', '--verify', $options['head'] . '^{commit}']);
        $changes = changes($repo, $base, $head);
        $out = rtrim((string) $options['out'], '/');

        $manifest = ['format' => FORMAT, 'base' => $base, 'head' => $head, 'built_at' => gmdate('c'), 'files' => [], 'migrations' => []];
        foreach ($changes as [$status, $path]) {
            $content = git($repo, ['show', "{$head}:{$path}"], trim: false);
            $target = "{$out}/files/{$path}";
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
                throw new RuntimeException("Could not create the directory for {$path}.");
            }
            file_put_contents($target, $content);

            $manifest['files'][] = array_filter([
                'path' => $path,
                'status' => $status,
                'strategy' => preg_match(LANGUAGE_FILE, $path) === 1 ? 'merge_language_keys' : 'replace',
                'mode' => fileMode($repo, $head, $path),
                'base_sha256' => $status === 'M' ? hash('sha256', git($repo, ['show', "{$base}:{$path}"], trim: false)) : null,
                'new_sha256' => hash('sha256', $content),
            ], static fn (mixed $value): bool => $value !== null);

            if ($status === 'A' && str_starts_with($path, 'database/migrations/')) {
                $manifest['migrations'][] = $path;
            }
        }

        file_put_contents("{$out}/MANIFEST.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        copy(__DIR__ . '/deploy.php', "{$out}/deploy.php");
        checksums($out);
        if (isset($options['archive'])) {
            archive($out);
        }
    } catch (RuntimeException $exception) {
        fwrite(STDERR, 'build-overlay: ' . $exception->getMessage() . "\n");

        return 1;
    }

    echo count($manifest['files']) . " file(s) in {$out} (" . count(array_filter($manifest['files'], static fn (array $file): bool => $file['status'] === 'M')) . ' modified, ' . count(array_filter($manifest['files'], static fn (array $file): bool => $file['status'] === 'A')) . " added), built from {$base} to {$head}.\n";

    return 0;
}

/** @return list<array{0: string, 1: string}> [status, path] of what the overlay ships; throws on what it cannot */
function changes(string $repo, string $base, string $head): array
{
    $shipped = [];
    $problems = [];

    foreach (explode("\n", git($repo, ['diff', '--name-status', '--no-renames', $base, $head])) as $line) {
        if ($line === '') {
            continue;
        }

        [$status, $path] = explode("\t", $line, 2);
        $status = $status[0];
        $top = explode('/', $path)[0];

        if (isDevOnly($path)) {
            continue;
        }
        if (in_array($path, ['composer.json', 'composer.lock', 'package.json', 'package-lock.json'], true)) {
            $problems[] = "{$path} changes: the dependency list differs, so the release needs `composer install` (or an npm build) that an overlay does not do.";
        } elseif (!in_array($top, SHIPPED_TOP_LEVEL, true)) {
            $problems[] = "{$path} changes, and an overlay does not deploy anything outside " . implode(', ', SHIPPED_TOP_LEVEL) . '. Deploy it by hand, deliberately.';
        } elseif ($status === 'D') {
            $problems[] = "{$path} is deleted by the release: an overlay adds and modifies files, it never deletes one. Remove it by hand after the deployment, once you are sure nothing needs it.";
        } elseif (!in_array($status, ['A', 'M'], true)) {
            $problems[] = "{$path} has the change type '{$status}', which an overlay does not handle.";
        } else {
            $shipped[] = [$status, $path];
        }
    }

    if ($problems !== []) {
        throw new RuntimeException("this release cannot be deployed as an overlay:\n  - " . implode("\n  - ", $problems));
    }
    if ($shipped === []) {
        throw new RuntimeException('the release changes no file that runs on the server.');
    }

    usort($shipped, static fn (array $left, array $right): int => strcmp($left[1], $right[1]));

    return $shipped;
}

function isDevOnly(string $path): bool
{
    foreach (DEV_ONLY as $prefix) {
        if ($path === $prefix || str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return false;
}

function fileMode(string $repo, string $revision, string $path): string
{
    return str_starts_with(git($repo, ['ls-tree', $revision, '--', $path]), '100755') ? '0755' : '0644';
}

/** Writes SHA256SUMS for the package, so an upload can be checked with `sha256sum -c SHA256SUMS`. */
function checksums(string $out): void
{
    $lines = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = ltrim(substr($file->getPathname(), strlen($out)), '/');
        if ($file->isFile() && $relative !== 'SHA256SUMS') {
            $lines[$relative] = hash_file('sha256', $file->getPathname()) . '  ' . $relative;
        }
    }
    ksort($lines);
    file_put_contents("{$out}/SHA256SUMS", implode("\n", $lines) . "\n");
}

function archive(string $out): void
{
    if (!class_exists(PharData::class)) {
        throw new RuntimeException('--archive needs the phar extension; zip or tar the directory by hand.');
    }

    $tar = $out . '.tar';
    @unlink($tar);
    @unlink($tar . '.gz');
    $phar = new PharData($tar);
    $phar->buildFromDirectory($out);
    $phar->compress(Phar::GZ);
    unlink($tar);
}

/** @param list<string> $arguments */
function git(string $repo, array $arguments, bool $trim = true): string
{
    $process = proc_open(['git', '-C', $repo, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('git could not be started.');
    }

    $output = (string) stream_get_contents($pipes[1]);
    $error = (string) stream_get_contents($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('git ' . implode(' ', $arguments) . ' failed: ' . trim($error));
    }

    return $trim ? trim($output) : $output;
}
