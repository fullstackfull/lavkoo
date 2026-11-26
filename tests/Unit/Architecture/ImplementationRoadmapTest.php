<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Report 10, the implementation roadmap, keeps every row its own format requires -- the risks row included -- and its as-built appendix
 * names every phase (M3-09).
 *
 * The requirement M3-09 states is seven fields per phase: scope, files or modules, dependencies, RISKS, acceptance tests, rollback and an
 * approval gate. Seventeen of seventeen phase tables carried six of them and no risks row at all for thirteen days, while the card read
 * "each phase lists scope, files, dependencies, acceptance tests, rollback and a gate" and nobody noticed which word was missing from both
 * the document and the sentence about it. A row that was never there is invisible; a row that goes missing again would be too.
 *
 * So the FORMAT is pinned here rather than in the document:
 *
 *   - the nine labels are a closed list, in order. A phase that renames one, drops one or reorders them fails by its number;
 *   - a risks row has to say something. The floor is a length, which cannot judge quality but does catch the row that is added to pass
 *     this test;
 *   - the seventeen risks rows must be DISTINCT, because one text pasted into all of them would satisfy every other check here;
 *   - the appendix has to name all eighteen phases (0 to 17) exactly once, with a state from four words and no fifth, for the reason
 *     `GoNoGoTodayTest` fixes its five verdicts: a vocabulary that grows a word reading as progress ("almost", "mostly") says nothing;
 *   - every path, migration, test and switch the appendix cites has to exist. The appendix is the only place that maps a phase of this
 *     roadmap to the code that implements it, and the work was done under other names -- so a citation that moved is exactly the failure
 *     it exists to prevent, and the one a reader cannot see.
 *
 * What is NOT checked, and cannot be: whether a risk is the RIGHT risk, or whether a phase's state is honestly reported. No test reads a
 * server or a judgement. This reads a format.
 */
class ImplementationRoadmapTest extends TestCase
{
    private const DOCUMENT = 'docs/gcc/audits/2026-11-12-0115-discovery-01/10-implementation-roadmap.md';

    /** Section 5 gives these seventeen phases a table each; phase 0 is seven prose tracks (section 4) and has no table. */
    private const PHASES_WITH_A_TABLE = 17;

    /** Phase 0 plus the seventeen: what the appendix has to account for, including the phases nothing was built for. */
    private const PHASES_IN_THE_APPENDIX = 18;

    /**
     * The rows of a phase table, in the order the requirement asks for them (scope, files, dependencies, risks, acceptance tests,
     * rollback, gate). A phase that works in more than one repository writes the repository row in the plural, which is the only
     * variation allowed.
     */
    private const ROWS = [
        'الهدف',
        'النطاق',
        'خارج النطاق',
        'المستودع والفرع|المستودعات والفروع',
        'الاعتماديات',
        'المخاطر',
        'اختبارات القبول',
        'التراجع غير الهدّام',
        'بوابة Go/No-Go',
    ];

    /** A risks row shorter than this is a heading pretending to be a row. Measured against the shortest of the seventeen, which is longer. */
    private const SHORTEST_RISKS_ROW = 300;

    private const APPENDIX_HEADING = '## ملحق أ';

    /**
     * The only words a phase's state may use in the appendix. "Built" says the mechanism is in the tree, switched off; "partial" says part
     * of it is and names the rest; "not started" says there is no code; "blocked" says it cannot be done from the repositories at all. A
     * phase that needs a fifth word needs a conversation.
     */
    private const STATES = ['مبنيّ', 'جزئي', 'لم يبدأ', 'محجوب'];

    public function test_every_phase_table_carries_every_row_the_format_requires_in_order(): void
    {
        $wrong = [];

        foreach ($this->phaseTables() as $phase => $table) {
            $labels = $this->labelsOf($table);

            foreach (self::ROWS as $at => $required) {
                $allowed = explode('|', $required);
                $found = $labels[$at] ?? '(nothing: the table ends here)';

                if (!in_array($found, $allowed, true)) {
                    $wrong[] = "المرحلة {$phase}: row " . ($at + 1) . ' must be `| ' . implode(' |` or `| ', $allowed)
                        . " |` and is `| {$found} |`";
                }
            }

            if (count($labels) > count(self::ROWS)) {
                $wrong[] = "المرحلة {$phase}: " . (count($labels) - count(self::ROWS)) . ' row(s) beyond the nine the format defines: '
                    . implode(', ', array_slice($labels, count(self::ROWS)));
            }
        }

        $this->assertSame(
            [],
            $wrong,
            "Correct these phase tables of " . self::DOCUMENT . ":\n  " . implode("\n  ", $wrong)
            . "\n(the nine rows are: " . implode(', ', self::ROWS) . ')',
        );
    }

    public function test_every_phase_states_its_own_risks_and_no_two_phases_state_the_same_ones(): void
    {
        $risks = [];
        $thin = [];

        foreach ($this->phaseTables() as $phase => $table) {
            if (preg_match('/^\|\s*المخاطر\s*\|(.+)\|\s*$/mu', $table, $row) !== 1) {
                $thin[] = "المرحلة {$phase}: no `| المخاطر | ... |` row";
                continue;
            }

            $text = trim($row[1]);
            if (mb_strlen($text) < self::SHORTEST_RISKS_ROW) {
                $thin[] = "المرحلة {$phase}: a risks row of " . mb_strlen($text) . ' characters, under the floor of ' . self::SHORTEST_RISKS_ROW;
            }

            $risks[$phase] = $text;
        }

        $this->assertSame(
            [],
            $thin,
            "Every phase of " . self::DOCUMENT . " section 5 must state its risks:\n  " . implode("\n  ", $thin),
        );
        $this->assertCount(self::PHASES_WITH_A_TABLE, $risks, 'all ' . self::PHASES_WITH_A_TABLE . ' phase tables must carry a risks row');

        $repeated = array_keys(array_filter(array_count_values($risks), static fn (int $times): bool => $times > 1));
        $this->assertSame(
            [],
            $repeated,
            'These risks rows are written in more than one phase, so at least one phase is not stating its own: '
            . implode(' / ', array_map(static fn (string $text): string => mb_substr($text, 0, 60) . '…', $repeated)),
        );
    }

    public function test_the_appendix_accounts_for_every_phase_exactly_once_with_a_state_from_the_four_words(): void
    {
        $appendix = $this->appendix();

        preg_match_all('/^\|\s*(\d+)\s*—[^|]*\|\s*([^|]+?)\s*\|/mu', $appendix, $rows, PREG_SET_ORDER);

        $states = [];
        $wrong = [];
        foreach ($rows as $row) {
            $phase = (int) $row[1];
            if (isset($states[$phase])) {
                $wrong[] = "المرحلة {$phase} has more than one row in the appendix";
                continue;
            }
            $states[$phase] = trim($row[2]);
        }

        $missing = array_values(array_diff(range(0, self::PHASES_IN_THE_APPENDIX - 1), array_keys($states)));
        $this->assertSame(
            [],
            $missing,
            'These phases are in the roadmap and in no row of its as-built appendix: ' . implode(', ', $missing)
            . '. A phase nothing was built for belongs there too, saying so.',
        );

        foreach ($states as $phase => $state) {
            if (!in_array($state, self::STATES, true)) {
                $wrong[] = "المرحلة {$phase}: the state `{$state}` is not one of " . implode(' / ', self::STATES);
            }
        }

        $this->assertSame([], $wrong, "Correct the appendix of " . self::DOCUMENT . ":\n  " . implode("\n  ", $wrong));
    }

    public function test_every_file_the_appendix_cites_is_in_the_tree(): void
    {
        $appendix = $this->appendix();
        preg_match_all('/`([^`]+)`/u', $appendix, $found);

        $cited = 0;
        $missing = [];
        foreach (array_unique($found[1]) as $token) {
            $fault = $this->faultOf($token);

            if ($fault === null) {
                continue;
            }

            $cited++;
            if ($fault !== '') {
                $missing[] = $fault;
            }
        }

        $this->assertGreaterThanOrEqual(
            120,
            $cited,
            'the appendix cites ' . $cited . ' files, which is too few to be mapping eighteen phases to the code that implements them',
        );
        $this->assertSame(
            [],
            $missing,
            "These are cited by the as-built appendix of " . self::DOCUMENT . " and are not in the tree:\n  " . implode("\n  ", $missing),
        );
    }

    public function test_every_switch_the_appendix_names_is_declared_in_the_configuration(): void
    {
        $appendix = $this->appendix();
        $source = (string) file_get_contents($this->repositoryRoot() . '/config/gcc.php');

        preg_match_all('/`(GCC_[A-Z0-9_]+)`/u', $appendix, $environment);
        preg_match_all('/`gcc\.([a-z0-9_.]+)`/u', $appendix, $keys);

        $names = array_values(array_unique($environment[1]));
        $this->assertGreaterThanOrEqual(
            20,
            count($names),
            'the appendix names ' . count($names) . ' switches; the roadmap says every new behaviour is behind one, so a map of the work names many',
        );

        $missing = [];
        foreach ($names as $name) {
            if (!str_contains($source, "'" . $name . "'")) {
                $missing[] = $name . ' (named as an environment switch)';
            }
        }
        foreach (array_unique($keys[1]) as $key) {
            $leaf = (string) array_slice(explode('.', $key), -1)[0];
            if (!str_contains($source, "'" . $leaf . "'")) {
                $missing[] = 'gcc.' . $key . ' (named as a configuration key)';
            }
        }

        $this->assertSame(
            [],
            $missing,
            "These switches are named by the as-built appendix and are not in config/gcc.php:\n  " . implode("\n  ", $missing),
        );
    }

    /**
     * The two Flutter repositories are half of phases 15 and 16, and their files cannot be resolved from here. They are cited WITH a
     * repository prefix for that reason, and this asserts the appendix still names them: an appendix that quietly became backend-only
     * would pass every other check in this file.
     */
    public function test_the_appendix_names_the_two_application_repositories_it_cannot_resolve(): void
    {
        $appendix = $this->appendix();

        foreach (['lavkooapp', 'lavkooappseller'] as $repository) {
            $this->assertMatchesRegularExpression(
                '/`' . $repository . ':lib\/[A-Za-z0-9_\/]+\.dart`/u',
                $appendix,
                'the appendix must cite at least one file of ' . $repository . ', prefixed with its repository because this tree cannot resolve it',
            );
        }
    }

    /**
     * What the token is, and whether it is there: null when the token is not a file citation at all, '' when it is and resolves, and the
     * fault otherwise.
     */
    private function faultOf(string $token): ?string
    {
        $root = $this->repositoryRoot();

        // Another repository's file, cited with its prefix: it cannot be resolved from this tree and is covered by its own test.
        if (str_contains($token, ':') || str_ends_with($token, '.dart')) {
            return null;
        }

        if (str_ends_with($token, '/')) {
            return is_dir($root . '/' . $token) ? '' : $token . ' is cited as a directory and is not one';
        }

        if (str_contains($token, '/')) {
            if (preg_match('/\.(php|md|json|py|sh|yml|yaml)$/', $token) !== 1) {
                return null;
            }

            return file_exists($root . '/' . $token) ? '' : $token;
        }

        if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.php$/', $token) === 1) {
            return file_exists($root . '/database/migrations/' . $token) ? '' : $token . ' is in no migration of database/migrations/';
        }

        if (preg_match('/^[A-Z][A-Za-z0-9]*\.php$/', $token) === 1) {
            return $this->fileNamed($token) === null ? $token . ' is in no file under app/ or tests/' : '';
        }

        return null;
    }

    private function fileNamed(string $basename): ?string
    {
        foreach (['app', 'tests'] as $tree) {
            $found = glob($this->repositoryRoot() . '/' . $tree . '/{,*/,*/*/,*/*/*/,*/*/*/*/}' . $basename, GLOB_BRACE) ?: [];
            if ($found !== []) {
                return $found[0];
            }
        }

        return null;
    }

    /** @return array<int, string> the text of each `### المرحلة <n>` table of section 5, keyed by the phase number */
    private function phaseTables(): array
    {
        $document = $this->document();
        preg_match_all('/^### المرحلة (\d+) .*$/mu', $document, $headings, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $this->assertCount(
            self::PHASES_WITH_A_TABLE,
            $headings,
            'section 5 of ' . self::DOCUMENT . ' must still have ' . self::PHASES_WITH_A_TABLE . ' `### المرحلة <n>` headings',
        );

        $tables = [];
        foreach ($headings as $at => $heading) {
            $from = (int) $heading[0][1];
            $to = isset($headings[$at + 1]) ? (int) $headings[$at + 1][0][1] : (int) strpos($document, "\n## ", $from);
            $tables[(int) $heading[1][0]] = substr($document, $from, $to - $from);
        }

        $this->assertSame(range(1, self::PHASES_WITH_A_TABLE), array_keys($tables), 'the phases of section 5 must be numbered 1 to ' . self::PHASES_WITH_A_TABLE . ', in order');

        return $tables;
    }

    /** @return list<string> the first cell of every row of a phase table, the header and the separator left out */
    private function labelsOf(string $table): array
    {
        preg_match_all('/^\|\s*([^|]+?)\s*\|/mu', $table, $cells);

        return array_values(array_filter(
            $cells[1],
            static fn (string $label): bool => $label !== 'البند' && preg_match('/^[-: ]+$/', $label) !== 1,
        ));
    }

    private function appendix(): string
    {
        $document = $this->document();
        $at = strpos($document, self::APPENDIX_HEADING);

        $this->assertNotFalse(
            $at,
            self::DOCUMENT . ' must carry the as-built appendix under a `' . self::APPENDIX_HEADING
            . '` heading: it is the only thing that maps a phase of this roadmap to the code that implements it.',
        );

        return substr($document, (int) $at);
    }

    private function document(): string
    {
        $path = $this->repositoryRoot() . '/' . self::DOCUMENT;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
