<?php

/**
 * Says whether a set of changed paths is documentation ONLY, so that the heavy MySQL and MariaDB groups can be skipped (M1-16).
 *
 * Four of those groups take between fifty minutes and the whole 110-minute ceiling, on two engines, and a pull request that only
 * rewrites a Markdown file under `docs/` pays all of it. Skipping them is worth real runner-minutes -- and getting it wrong is worth
 * more than it saves, because a code change that the filter calls documentation runs on no engine at all and nothing says so. "A test
 * that never runs is worse than a test that fails."
 *
 * So the rule is the conservative one: the answer is `true` only when EVERY path given matches the documentation list below. Anything
 * else -- one unrecognised path, an empty list, a path this script cannot read -- answers `false` and the whole suite runs. There is no
 * list of paths that FORCE a run, because that is the shape that fails open: a new top-level directory nobody updated the list for would
 * be silently skippable.
 *
 * `.github/` is deliberately NOT documentation. A change to the workflow that decides what runs must be proved by running it.
 *
 * Usage: `php scripts/ci/documentation-only-change.php <path> [<path>...]`, or the paths on stdin, one per line.
 * It prints `true` or `false` and always exits 0: the answer is the output, so a non-zero exit would be a fault of the script itself.
 */

/** Paths whose change cannot alter what any test does. A directory entry matches everything beneath it. */
const DOCUMENTATION_PATHS = [
    'docs/',
    'README.md',
    'LICENSE',
    'CHANGELOG.md',
    'CONTRIBUTING.md',
    'SECURITY.md',
    'CLAUDE.md',
];

/**
 * @param list<string> $paths
 */
function changeIsDocumentationOnly(array $paths): bool
{
    $paths = array_values(array_filter(array_map('trim', $paths), static fn (string $path): bool => $path !== ''));

    if ($paths === []) {
        return false;
    }

    foreach ($paths as $path) {
        if (!pathIsDocumentation($path)) {
            return false;
        }
    }

    return true;
}

function pathIsDocumentation(string $path): bool
{
    // A path that climbs out of the repository, or that is not a plain relative path, is not something this script will vouch for.
    if ($path !== ltrim($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
        return false;
    }

    foreach (DOCUMENTATION_PATHS as $documentation) {
        if (str_ends_with($documentation, '/') ? str_starts_with($path, $documentation) : $path === $documentation) {
            return true;
        }
    }

    return false;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $paths = array_slice($_SERVER['argv'], 1);
    if ($paths === []) {
        $paths = explode("\n", (string) stream_get_contents(STDIN));
    }

    echo changeIsDocumentationOnly($paths) ? "true\n" : "false\n";
}
