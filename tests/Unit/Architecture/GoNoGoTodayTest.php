<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\ReadsCitedTests;

/**
 * `docs/gcc/GO_NO_GO_TODAY.md` answers the owner's seven questions and gives each of C1 to C10 a verdict that means something (M3-12).
 *
 * The ten conditions between this programme and the G0 gate were set on 2026-11-12 and nothing restated them: the charter and the handoffs
 * say what may be DONE, not where the conditions stand. A refreshed reading is worth having only if it cannot quietly rot, and there are
 * three ways it would:
 *
 *   - a condition loses its verdict, or gains a word of its own ("mostly", "nearly") that reads as progress and means nothing. The
 *     vocabulary is therefore fixed HERE, in the test, not in the document;
 *   - a question stops being answered, and a reader cannot tell a question nobody answered from one with no answer;
 *   - a test the document cites as its evidence is renamed or deleted, and the claim stands with nothing behind it.
 *
 * The verdicts are not checked for being RIGHT: no test can read a server. They are checked for being there, for being one of five words,
 * and for citing tests that exist.
 */
class GoNoGoTodayTest extends TestCase
{
    use ReadsCitedTests;

    private const DOCUMENT = 'docs/gcc/GO_NO_GO_TODAY.md';

    private const QUESTIONS = 7;

    /** The ten conditions of report 12, section 2. */
    private const CONDITIONS = ['C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'C8', 'C9', 'C10'];

    /**
     * The only words a verdict may use. MET, PARTLY MET and NOT MET are states of the work; UNKNOWN says nobody here can see the answer;
     * BLOCKED says it waits on the owner or on access rather than on an engineer. A condition that needs another word needs a conversation,
     * not a new adjective.
     */
    private const VERDICTS = ['MET', 'PARTLY MET', 'NOT MET', 'UNKNOWN', 'BLOCKED'];

    public function test_the_seven_questions_are_each_asked_and_answered(): void
    {
        $document = $this->document();

        $asked = [];
        foreach (range(1, self::QUESTIONS) as $number) {
            if (preg_match('/^### Q' . $number . '\.\s*(.+)$/mu', $document, $match) === 1 && trim($match[1]) !== '') {
                $asked[] = 'Q' . $number;
            }
        }

        $this->assertSame(
            array_map(static fn (int $number): string => 'Q' . $number, range(1, self::QUESTIONS)),
            $asked,
            'Each of the seven questions of report 12 must be a `### Q<n>. <question>` heading of ' . self::DOCUMENT . ', in order.',
        );

        $thin = [];
        foreach ($this->answers($document) as $question => $answer) {
            if (mb_strlen(trim($answer)) < 200) {
                $thin[] = $question;
            }
        }

        $this->assertSame([], $thin, 'These questions have a heading and almost no answer: ' . implode(', ', $thin));
    }

    public function test_every_condition_carries_one_of_the_five_verdicts(): void
    {
        $document = $this->document();

        $verdicts = [];
        $wrong = [];
        foreach (self::CONDITIONS as $condition) {
            if (preg_match('/^### ' . $condition . ' — .+$/mu', $document, $heading, PREG_OFFSET_CAPTURE) !== 1) {
                $wrong[] = "{$condition}: no `### {$condition} — <what it asks>` heading";
                continue;
            }

            $body = $this->sectionAfter($document, (int) $heading[0][1]);
            if (preg_match('/\*\*(' . implode('|', array_map(static fn (string $verdict): string => preg_quote($verdict, '/'), self::VERDICTS)) . ')\.?\*\*/u', $body, $verdict) !== 1) {
                $wrong[] = "{$condition}: no verdict in bold from " . implode(', ', self::VERDICTS);
                continue;
            }

            $verdicts[$condition] = $verdict[1];
            if (mb_strlen(trim($body)) < 150) {
                $wrong[] = "{$condition}: a verdict with no evidence under it";
            }
        }

        $this->assertSame([], $wrong, "Correct these conditions of " . self::DOCUMENT . ":\n  " . implode("\n  ", $wrong));
        $this->assertCount(count(self::CONDITIONS), $verdicts);
    }

    public function test_the_gate_itself_is_not_recorded_as_met_while_a_condition_under_it_is_not(): void
    {
        $document = $this->document();

        $c9 = $this->sectionAfter($document, (int) strpos($document, '### C9 — '));
        $this->assertSame(
            1,
            preg_match('/\*\*(NOT MET|PARTLY MET|UNKNOWN|BLOCKED)\.?\*\*/u', $c9),
            'C9 is the owner\'s G0 gate over C2 to C8. While any of those is not met, C9 may not read MET: a document that closed the gate '
            . 'on its own would be the one thing this programme must never produce.',
        );
    }

    public function test_every_test_the_document_cites_as_evidence_exists(): void
    {
        $document = $this->document();
        $citations = $this->citedTests($document);
        $classes = $this->citedTestClasses($document);

        $this->assertNotEmpty($citations, 'the document cites no test method at all, so its evidence is prose only');
        $this->assertGreaterThanOrEqual(8, count($classes), 'a reading of ten conditions that cites fewer than eight test classes is citing almost nothing');

        $missing = [...$this->citationsThatAreNotInTheTree($citations), ...$this->classesThatAreNotInTheTree($classes)];

        $this->assertSame(
            [],
            $missing,
            "These tests are cited by " . self::DOCUMENT . " and are not in the tree:\n  " . implode("\n  ", $missing),
        );
    }

    /** @return array<string, string> the text under each question heading */
    private function answers(string $document): array
    {
        preg_match_all('/^### (Q\d)\.\s*.+$/mu', $document, $matches, PREG_OFFSET_CAPTURE);

        $answers = [];
        foreach ($matches[1] as $index => [$question, $_]) {
            $from = $matches[0][$index][1] + strlen($matches[0][$index][0]);
            $to = $matches[0][$index + 1][1] ?? strpos($document, "\n---", $from) ?: strlen($document);
            $answers[$question] = substr($document, $from, $to - $from);
        }

        return $answers;
    }

    /** The text from a heading to the next `### ` or `## `, whichever comes first. */
    private function sectionAfter(string $document, int $at): string
    {
        $rest = substr($document, $at + 4);
        $next = [];
        foreach (["\n### ", "\n## "] as $boundary) {
            $found = strpos($rest, $boundary);
            if ($found !== false) {
                $next[] = $found;
            }
        }

        return $next === [] ? $rest : substr($rest, 0, min($next));
    }

    private function document(): string
    {
        $path = $this->testTreeRoot() . '/' . self::DOCUMENT;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
