<?php

/**
 * Every number in this audit's README, printed from the tracker files it is counted from.
 *
 * `php docs/gcc/audits/2026-11-24-post-merge-assessment/count.php`
 *
 * The report used to carry figures typed by hand beside figures counted by a script, and they drifted apart within one file: a headline of
 * 911 met criteria over a paragraph that still said 893, and 259 open decisions in prose against 261 in the table. A number nobody can
 * recompute is a number nobody can check, so this script is the only place any of them come from, and the README says so beside each one.
 *
 * The definitions are the report's own, in one place:
 *
 *  - a REQUIREMENT is a `#### <id>.` block of `docs/gcc/tracker/axis-*.md` that holds at least one criterion;
 *  - a CRITERION is a line beginning `  - [MET]`, `  - [CODED]` or `  - [MISSING]`;
 *  - a requirement is PROVEN when no criterion of it is missing and none is merely coded, CODED when nothing is missing but something is
 *    unproven, PARTIAL when something is missing and something is done, NOT STARTED when nothing is done;
 *  - it WAITS ON A DECISION when its `Open owner decisions` line is neither empty nor opens with None, No open, Nothing or Settled;
 *  - it WAITS ON A REVIEW when its State line's `reviews:` field names anything at all;
 *  - DEVELOPMENT is (met + coded) / criteria, and PROVEN is met / criteria.
 */
$axes = glob(dirname(__DIR__, 2) . '/tracker/axis-*.md');
sort($axes);

$letterOf = static fn (string $path): string => strtoupper(substr(basename($path), strlen('axis-'), 1));
$totals = array_fill_keys(['requirements', 'criteria', 'met', 'coded', 'missing', 'proven', 'codedReqs', 'partial', 'notStarted', 'decisions', 'reviews', 'launchReady'], 0);
$rows = [];

foreach ($axes as $axis) {
    $blocks = preg_split('/^#### /m', (string) file_get_contents($axis));
    array_shift($blocks);
    $row = array_fill_keys(array_keys($totals), 0);

    foreach ($blocks as $block) {
        $met = preg_match_all('/^  - \[MET\]/m', $block);
        $coded = preg_match_all('/^  - \[CODED\]/m', $block);
        $missing = preg_match_all('/^  - \[MISSING\]/m', $block);

        if ($met + $coded + $missing === 0) {
            continue;
        }

        $row['requirements']++;
        $row['criteria'] += $met + $coded + $missing;
        $row['met'] += $met;
        $row['coded'] += $coded;
        $row['missing'] += $missing;

        if ($missing === 0 && $coded === 0) {
            $row['proven']++;
        } elseif ($missing === 0) {
            $row['codedReqs']++;
        } elseif ($met + $coded > 0) {
            $row['partial']++;
        } else {
            $row['notStarted']++;
        }

        if (preg_match('/^- \*\*Open owner decisions?\.\*\*\s*(.*)$/m', $block, $decision) === 1
            && preg_match('/^(None|No open|Nothing|Settled)\b/i', trim($decision[1])) !== 1) {
            $row['decisions']++;
        }

        $waitsOnAReview = preg_match('/^- \*\*State:.*reviews:\s*(\S.*)$/m', $block, $review) === 1
            && !in_array(strtolower(trim($review[1])), ['none', ''], true);

        if ($waitsOnAReview) {
            $row['reviews']++;
        }

        // LAUNCH-READY is the strictest reading of the three: every criterion proved by a test, nothing waiting on the owner, nothing
        // waiting on a reviewer. It is what "done" means for a release, and it is why the development percentage and the launch
        // percentage are not the same number and must never be printed as though they were.
        if ($missing === 0 && $coded === 0 && !$waitsOnAReview
            && (preg_match('/^- \*\*Open owner decisions?\.\*\*\s*(.*)$/m', $block, $open) !== 1
                || preg_match('/^(None|No open|Nothing|Settled)\b/i', trim($open[1])) === 1)) {
            $row['launchReady']++;
        }
    }

    $rows[$letterOf($axis)] = $row;

    foreach ($totals as $key => $_) {
        $totals[$key] += $row[$key];
    }
}

$percent = static fn (int $part, int $whole): string => $whole === 0 ? '-' : round(100 * $part / $whole) . '%';

printf("%-3s %5s %6s %5s %6s %8s %6s %7s %7s %6s %8s %8s %8s %8s %6s\n",
    'ax', 'reqs', 'crit', 'met', 'coded', 'missing', 'dev', 'proven', 'provenR', 'codedR', 'partial', 'notStart', 'decision', 'review', 'launch');
// one more column than the header names, deliberately printed last so the launch figure is read beside the decisions it waits on

foreach ($rows as $letter => $row) {
    printf("%-3s %5d %6d %5d %6d %8d %6s %7s %7d %6d %8d %8d %8d %8d %6d\n",
        $letter, $row['requirements'], $row['criteria'], $row['met'], $row['coded'], $row['missing'],
        $percent($row['met'] + $row['coded'], $row['criteria']), $percent($row['met'], $row['criteria']),
        $row['proven'], $row['codedReqs'], $row['partial'], $row['notStarted'], $row['decisions'], $row['reviews'], $row['launchReady']);
}

printf("%-3s %5d %6d %5d %6d %8d %6s %7s %7d %6d %8d %8d %8d %8d %6d\n",
    'ALL', $totals['requirements'], $totals['criteria'], $totals['met'], $totals['coded'], $totals['missing'],
    $percent($totals['met'] + $totals['coded'], $totals['criteria']), $percent($totals['met'], $totals['criteria']),
    $totals['proven'], $totals['codedReqs'], $totals['partial'], $totals['notStarted'], $totals['decisions'], $totals['reviews'], $totals['launchReady']);

// The evidence a met or coded criterion rests on, which is the other figure the report used to state by hand: a line that names `main`,
// a CI run of the 600s or this branch was read after the merge; one that names a draft pull request or an integration run of the 200s or
// 300s was read on a head that no longer exists. A line naming neither is counted as unattributed rather than quietly as either.
$evidence = ['post' => 0, 'pre' => 0, 'none' => 0];
$testFiles = [];

foreach ($axes as $axis) {
    foreach (preg_split('/\r?\n/', (string) file_get_contents($axis)) as $line) {
        if (preg_match('/^  - \[(MET|CODED)\]/', $line) !== 1) {
            continue;
        }

        $post = preg_match('/\bmain\b|run 6\d\d|gcc\/development|github\.com\/[^ ]*actions\/runs/i', $line) === 1;
        $pre = preg_match('/\b(PR|pull request) \d+|\bdraft\b|run [23]\d\d\b/i', $line) === 1;
        $evidence[$post ? 'post' : ($pre ? 'pre' : 'none')]++;

        if (preg_match_all('#\b(?:tests|test)/[A-Za-z0-9_/.-]+\.(?:php|dart)#', $line, $cited) > 0) {
            foreach ($cited[0] as $file) {
                $testFiles[$file] = true;
            }
        }
    }
}

printf("\nevidence of a met or coded criterion: %d read after the merge, %d on a draft head, %d naming neither\n",
    $evidence['post'], $evidence['pre'], $evidence['none']);
printf("distinct test files cited by a met or coded criterion: %d\n", count($testFiles));

printf("\nin code: %d of %d (%s) | proven: %d of %d (%s) | the three states sum to %d\n",
    $totals['met'] + $totals['coded'], $totals['criteria'], $percent($totals['met'] + $totals['coded'], $totals['criteria']),
    $totals['met'], $totals['criteria'], $percent($totals['met'], $totals['criteria']),
    $totals['met'] + $totals['coded'] + $totals['missing']);
