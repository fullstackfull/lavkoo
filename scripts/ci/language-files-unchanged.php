<?php

/**
 * Refuses a test job that left the tracked language files changed (M2).
 *
 * `translate()` appends a humanised placeholder to `resources/lang/<locale>/new-messages.php` for any key the files do not already know --
 * validator messages, a refusal rendered in a locale, an invoice line -- and the files are tracked. So a test that renders such a page used
 * to write a key into the repository.
 *
 * The cost that matters is not the dirty tree, which somebody notices and reverts. It is that a key a REAL FEATURE added becomes
 * indistinguishable from an artifact of a test run: in the diff of a pull request, in a merge, and in the admin's own translation screen,
 * where it appears as a phrase the shop is supposed to translate. A guard is the only thing that keeps the two apart, and no job had one:
 * before this script there was no `git status --porcelain` and no `git diff --exit-code` anywhere in the workflow.
 *
 * `Tests\TestCase` puts the files back after every test, so a green run leaves nothing behind. This is the gate that says so, and it is
 * deliberately placed AFTER each `phpunit` step rather than once at the end of the workflow: the job that dirtied the tree is the job whose
 * tests have to be looked at, and a job that reports somebody else's write tells nobody anything.
 *
 * It scans the WORKING TREE, not a diff against a base, so it catches a key written by a test and a language file left modified for any
 * other reason alike -- including an untracked file somebody's test wrote beside the ones git knows.
 *
 * Usage: `php scripts/ci/language-files-unchanged.php [<path>]`, where the path defaults to `resources/lang`.
 * It exits 0 when nothing under the path changed and 1 when something did, printing what.
 */

const DEFAULT_PATH = 'resources/lang';

/** @return array{status: int, output: string} */
function run(string $command): array
{
    $output = [];
    $status = 0;
    exec($command . ' 2>&1', $output, $status);

    return ['status' => $status, 'output' => implode("\n", $output)];
}

$path = $argv[1] ?? DEFAULT_PATH;

$inside = run('git rev-parse --is-inside-work-tree');
if ($inside['status'] !== 0) {
    // Not a checkout: the guard cannot answer, and a guard that cannot answer must say so rather than pass.
    fwrite(STDERR, "language-files-unchanged: this is not a git checkout, so nothing can be compared.\n");
    exit(1);
}

$changed = run('git status --porcelain --untracked-files=all -- ' . escapeshellarg($path));
if ($changed['status'] !== 0) {
    fwrite(STDERR, "language-files-unchanged: the working tree could not be read:\n" . $changed['output'] . "\n");
    exit(1);
}

if (trim($changed['output']) === '') {
    echo "language-files-unchanged: the run left {$path} exactly as it was.\n";
    exit(0);
}

fwrite(STDERR, "language-files-unchanged: this run CHANGED {$path}.\n\n" . $changed['output'] . "\n\n");
fwrite(STDERR, run('git diff --stat -- ' . escapeshellarg($path))['output'] . "\n");
fwrite(STDERR, run('git diff --unified=0 -- ' . escapeshellarg($path))['output'] . "\n");
fwrite(STDERR, <<<WHY

    A test let translate() append a key, which makes a key a real feature added indistinguishable from an artifact of a test run.

    To find WHICH test, run the same paths again with the key write turned into a failure:

        GCC_TEST_LANGUAGE_FILE_WRITES_ARE_FAILURES=1 vendor/bin/phpunit <the paths this job runs>

    The test that fails names the file and the keys. Then either give the key a text in the language file, because the feature needs it,
    or stop the test rendering it. Do not commit the written file: Tests\\TestCase puts the files back, so a key that survived a run is a
    key something wrote outside it.

    WHY);

exit(1);
