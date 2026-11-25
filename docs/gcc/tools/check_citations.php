<?php

/**
 * Checks that every `<repo>:<path>:<line>` citation in the audit documents resolves (M3-13).
 *
 * The Discovery-01 reports say of themselves that an automated citation check was run before publication and found no defect. The script
 * that did it was never committed, so the claim could not be checked by anyone afterwards -- which is the one thing a citation check exists
 * to prevent. This is that script, in the repository, with a test.
 *
 * ## What a citation is, and what makes it resolvable
 *
 * `lavkoo:app/Utils/settings.php:26-30` names a repository, a path and a line or a range. The reports state the SHA each repository was read
 * at in their header (`lavkoo@7f42777`), so a citation is checked AT THAT COMMIT: the path has to be in the tree of that commit, and the
 * last line of the range has to be inside the file as it was then. Checking it against today's tree would be wrong in both directions -- a
 * file that moved since would read as a bad citation, and a line number that drifted would read as a good one.
 *
 * A clone that does not hold the base commit (a shallow CI checkout) cannot do that, and the honest answer is a weaker check rather than a
 * green tick: the path is then looked for in the working tree, line numbers are not checked, and the report says which mode it ran in. It
 * still fails on a path that is nowhere, which is the defect that matters -- a citation nobody can follow.
 *
 * ## It also counts the rows that carry no file at all
 *
 * A citation that resolves says nothing about a finding that cites no file. Each report's table rows are counted: how many carry both a
 * `<repo>:<path>:<line>` and a VERIFIED / INFERRED / UNKNOWN tag, and how many cite only an evidence annex (`E02 §5`). That is a measurement
 * and not a failure: the reports are historical, and the gap is recorded against the requirement that asked for it (M3-03).
 *
 * Usage: `php docs/gcc/tools/check_citations.php [--dir=docs/gcc] [--base=<sha>] [--json]`, where `--dir` may also be one .md file.
 * Exit 1 when a citation cannot be followed.
 */

const REPOSITORIES = ['lavkoo', 'lavkooapp', 'lavkooappseller'];

/** The repository this script lives in; citations of the two Flutter repositories cannot be resolved from here. */
const OWN_REPOSITORY = 'lavkoo';

/**
 * A citation of a path under these is a citation of a DOCUMENT this programme itself writes, and it is resolved against the working tree
 * rather than against the base commit. A report that cites another report, or the decisions file, is citing something that did not exist at
 * the commit the CODE was read at, and pinning it there would report 148 faults that are all the same non-fault.
 */
const DOCUMENT_PATHS = ['docs/'];

$options = getopt('', ['dir::', 'base::', 'json']) ?: [];
$root = dirname(__DIR__, 3);
$directory = $options['dir'] ?? 'docs/gcc';
$base = $options['base'] ?? defaultBase(str_starts_with($directory, '/') ? $directory : $root . '/' . $directory);

// An absolute --dir is taken as given, so the tool can be pointed at a fixture outside the repository (its own test does that).
$read = str_starts_with($directory, '/') ? $directory : $root . '/' . $directory;

$citations = citationsIn($read);
$pinned = $base !== null && commitIsHere($root, $base);
$faults = $pinned ? unresolvedAtTheCommit($root, $base, $citations) : unresolvedInTheWorkingTree($root, $citations);
$rows = rowCoverage($read);

$report = [
    'directory' => $directory,
    'mode' => $pinned ? 'pinned to ' . $base : 'against the working tree (the base commit is not in this clone)',
    'repositories' => countByRepository($citations),
    'citations' => count($citations),
    'paths' => count(array_unique(array_map(static fn (array $citation): string => $citation['repository'] . ':' . $citation['path'], $citations))),
    'unresolved' => $faults,
    'rows' => $rows,
];

if (isset($options['json'])) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
} else {
    echo "citations in {$directory}: {$report['citations']} over {$report['paths']} distinct paths, {$report['mode']}\n";
    foreach ($report['repositories'] as $repository => $total) {
        echo "  {$repository}: {$total}\n";
    }
    echo 'unresolved: ' . count($faults) . "\n";
    foreach (array_slice($faults, 0, 20) as $fault) {
        echo "  {$fault}\n";
    }
    echo "table rows of the reports: {$rows['rows']} findings, {$rows['with_a_file_and_a_tag']} with a file:line AND a tag, {$rows['with_neither']} with neither\n";
}

exit($faults === [] ? 0 : 1);

/** The SHA the documents say they were read at, from the first `<repo>@<sha>` of the directory's own README or reports. */
function defaultBase(string $read): ?string
{
    foreach (markdownFiles($read) as $file) {
        if (preg_match('/' . OWN_REPOSITORY . '@([0-9a-f]{7,40})/', (string) file_get_contents($file), $match) === 1) {
            return $match[1];
        }
    }

    return null;
}

function commitIsHere(string $root, string $sha): bool
{
    exec('git -C ' . escapeshellarg($root) . ' cat-file -e ' . escapeshellarg($sha . '^{commit}') . ' 2>/dev/null', $output, $status);

    return $status === 0;
}

/** @return list<array{repository: string, path: string, from: int, to: int, document: string}> */
function citationsIn(string $directory): array
{
    $pattern = '/\b(' . implode('|', REPOSITORIES) . '):([A-Za-z0-9_][A-Za-z0-9_.\/-]*\.[A-Za-z0-9]+):(\d+)(?:-(\d+))?/';
    $citations = [];

    foreach (markdownFiles($directory) as $file) {
        preg_match_all($pattern, (string) file_get_contents($file), $found, PREG_SET_ORDER);
        foreach ($found as $citation) {
            $citations[] = [
                'repository' => $citation[1],
                'path' => $citation[2],
                'from' => (int) $citation[3],
                'to' => (int) ($citation[4] ?? $citation[3]),
                'document' => basename($file),
            ];
        }
    }

    return $citations;
}

/**
 * @param list<array<string, mixed>> $citations
 * @return list<string>
 */
function unresolvedAtTheCommit(string $root, string $sha, array $citations): array
{
    exec('git -C ' . escapeshellarg($root) . ' ls-tree -r --name-only ' . escapeshellarg($sha), $tree);
    $tree = array_fill_keys($tree, true);

    $lines = [];
    $faults = [];
    foreach ($citations as $citation) {
        if ($citation['repository'] !== OWN_REPOSITORY) {
            continue;
        }
        if (citesADocument($citation['path'])) {
            array_push($faults, ...unresolvedInTheWorkingTree($root, [$citation]));
            continue;
        }
        if (!isset($tree[$citation['path']])) {
            $faults[] = "{$citation['document']}: {$citation['repository']}:{$citation['path']} is not in the tree of {$sha}";
            continue;
        }

        $lines[$citation['path']] ??= linesAt($root, $sha, $citation['path']);
        if ($citation['to'] > $lines[$citation['path']]) {
            $faults[] = "{$citation['document']}: {$citation['repository']}:{$citation['path']}:{$citation['from']}-{$citation['to']} is past the end of the file at {$sha} ({$lines[$citation['path']]} lines)";
        }
    }

    return $faults;
}

/**
 * @param list<array<string, mixed>> $citations
 * @return list<string>
 */
function unresolvedInTheWorkingTree(string $root, array $citations): array
{
    $faults = [];
    foreach ($citations as $citation) {
        if ($citation['repository'] !== OWN_REPOSITORY) {
            continue;
        }
        if (!file_exists($root . '/' . $citation['path'])) {
            $faults[] = "{$citation['document']}: {$citation['repository']}:{$citation['path']} is in the working tree neither";
        }
    }

    return $faults;
}

function citesADocument(string $path): bool
{
    foreach (DOCUMENT_PATHS as $documents) {
        if (str_starts_with($path, $documents)) {
            return true;
        }
    }

    return false;
}

function linesAt(string $root, string $sha, string $path): int
{
    exec('git -C ' . escapeshellarg($root) . ' cat-file blob ' . escapeshellarg($sha . ':' . $path) . ' | wc -l', $output);

    return (int) (($output[0] ?? '0') + 1);
}

/**
 * How many table rows of the reports cite a file and a tag, and how many cite neither.
 *
 * @return array{rows: int, with_a_file_and_a_tag: int, with_a_tag_only: int, with_neither: int}
 */
function rowCoverage(string $directory): array
{
    $rows = 0;
    $both = 0;
    $tagOnly = 0;
    $neither = 0;
    $citation = '/\b(' . implode('|', REPOSITORIES) . '):[A-Za-z0-9_][A-Za-z0-9_.\/-]*:\d+/';

    foreach (markdownFiles($directory) as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $at => $line) {
            $line = trim($line);
            if (!str_starts_with($line, '|') || preg_match('/^\|[-: |]+\|$/', $line) === 1) {
                continue;
            }
            // The row above a separator is the header, not a finding.
            if (preg_match('/^\|[-: |]+\|$/', trim($lines[$at + 1] ?? '')) === 1) {
                continue;
            }

            $rows++;
            $hasCitation = preg_match($citation, $line) === 1;
            $hasTag = preg_match('/VERIFIED|INFERRED|UNKNOWN/u', $line) === 1;

            match (true) {
                $hasCitation && $hasTag => $both++,
                $hasTag => $tagOnly++,
                default => $neither++,
            };
        }
    }

    return ['rows' => $rows, 'with_a_file_and_a_tag' => $both, 'with_a_tag_only' => $tagOnly, 'with_neither' => $neither];
}

/**
 * @param list<array<string, mixed>> $citations
 * @return array<string, int>
 */
function countByRepository(array $citations): array
{
    $counts = array_fill_keys(REPOSITORIES, 0);
    foreach ($citations as $citation) {
        $counts[$citation['repository']]++;
    }

    return array_filter($counts);
}

/** @return list<string> every .md file below the directory */
function markdownFiles(string $directory): array
{
    if (is_file($directory)) {
        return str_ends_with($directory, '.md') ? [$directory] : [];
    }
    if (!is_dir($directory)) {
        return [];
    }

    $files = [];
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($walk as $file) {
        if ($file->isFile() && $file->getExtension() === 'md') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}
