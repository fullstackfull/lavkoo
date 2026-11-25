<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\ReadsCitedTests;

/**
 * `docs/gcc/THREAT_MODEL.md` names a real control for each of the nine classes, and every test it cites exists (M3-08).
 *
 * A threat model is the document most likely to be true when it is written and false six months later: a control is renamed, a test is
 * deleted with the pull request that replaced it, a class is added to the requirement and nobody adds the section. The usual result is a
 * document a reviewer trusts and should not, which is worse than no document, because it closes the question.
 *
 * So the file is read rather than admired. Four ways to fail:
 *
 *   - one of the nine classes the requirement names has no section;
 *   - a section leaves out one of the seven fields, or leaves one empty;
 *   - a test the document cites under **Pinned by** is not in the tree, by class or by method name;
 *   - the section that lists the classes with no control is missing, so a class whose control is only an intention could be written as
 *     though it had one.
 *
 * The nine slugs are pinned HERE and not read from the document, because the requirement is nine named classes: a document that renamed
 * one of them to something it had a test for would otherwise pass.
 */
class ThreatModelTest extends TestCase
{
    use ReadsCitedTests;

    private const DOCUMENT = 'docs/gcc/THREAT_MODEL.md';

    /** The nine classes of the requirement (plan lines 625-637), as the headings must spell them. */
    private const CLASSES = [
        'country-to-country-data-leaks',
        'unauthorised-staff-access',
        'mixed-money-and-balances',
        'leaked-integration-keys',
        'currency-context-errors',
        'overlapping-stock-or-reservations',
        'theme-or-cache-leaks-between-countries',
        'notifications-to-the-wrong-audience',
        'mixed-webhooks-and-historical-orders',
    ];

    /** What each class must say. `Pinned by` may read `none`, and then the gap has to name it. */
    private const FIELDS = ['Asset', 'Boundary', 'Threat', 'Control', 'Pinned by', 'Gap', 'Owner'];

    public function test_every_one_of_the_nine_classes_has_a_section(): void
    {
        $sections = $this->sections();

        $this->assertSame(
            self::CLASSES,
            array_keys($sections),
            'The nine classes of the requirement, in order, must each be a `## <n>. <slug>` heading of ' . self::DOCUMENT
            . '. A class the requirement names and the document does not is the gap this guard exists for.',
        );
    }

    public function test_every_section_answers_all_seven_questions(): void
    {
        $incomplete = [];
        foreach ($this->sections() as $slug => $body) {
            foreach (self::FIELDS as $field) {
                if (preg_match('/^- \*\*' . preg_quote($field, '/') . '\.?\*\*\s*(.+)/mu', $body, $match) !== 1) {
                    $incomplete[] = "{$slug}: no **{$field}**";
                    continue;
                }
                if (mb_strlen(trim($match[1])) < 20) {
                    $incomplete[] = "{$slug}: **{$field}** says almost nothing";
                }
            }
        }

        $this->assertSame([], $incomplete, "Correct these sections of " . self::DOCUMENT . ":\n  " . implode("\n  ", $incomplete));
    }

    /**
     * The one that makes the document worth reading: a cited test has to be in the tree. A control named in prose and pinned by a test
     * that was deleted reads exactly like a control that is still there.
     */
    public function test_every_test_the_document_cites_exists_by_class_and_by_method(): void
    {
        $citations = $this->citations();
        $this->assertNotEmpty($citations, 'the document cites no test at all, so this guard proved nothing');
        $this->assertGreaterThanOrEqual(
            count(self::CLASSES),
            count($citations),
            'each class must cite at least one test, so there cannot be fewer citations than classes',
        );

        $missing = [];
        foreach ($citations as $citation => $slug) {
            foreach ($this->citationsThatAreNotInTheTree([$citation => $slug]) as $fault) {
                $missing[] = "{$slug}: {$fault}";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "These tests are cited by " . self::DOCUMENT . " and are not in the tree:\n  " . implode("\n  ", $missing)
            . "\n\nA control is pinned by a test that runs, or it is a gap. Cite the test that replaced it, or move the line to the Gap.",
        );
    }

    public function test_the_document_states_which_classes_have_no_control_pinned_to_them(): void
    {
        $document = $this->document();

        $this->assertStringContainsString(
            '## The classes no control is pinned to',
            $document,
            self::DOCUMENT . ' must carry that section even when the answer is none: the requirement asks for the classes with no control,'
            . ' and a document that simply omits them reads as though every class were covered.',
        );

        $withoutAControl = [];
        foreach ($this->sections() as $slug => $body) {
            if (preg_match('/^- \*\*Pinned by\.?\*\*\s*none\b/mui', $body) === 1) {
                $withoutAControl[] = $slug;
            }
        }

        $closing = substr($document, (int) strpos($document, '## The classes no control is pinned to'));
        foreach ($withoutAControl as $slug) {
            $this->assertStringContainsString(
                $slug,
                $closing,
                "{$slug} says `Pinned by: none`, so the closing section has to name it rather than leave a reader to find it.",
            );
        }

        if ($withoutAControl === []) {
            $this->assertStringContainsString(
                'None.',
                $closing,
                'every class cites a test, so the closing section must say so in as many words rather than being silent',
            );
        }
    }

    /** @return array<string, string> the body of each class section, by the slug of its heading, in the order they appear */
    private function sections(): array
    {
        $document = $this->document();
        $sections = [];

        if (preg_match_all('/^## \d+\.\s*([a-z0-9-]+)\s*$/mu', $document, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        foreach ($matches[1] as $index => [$slug, $_]) {
            $from = $matches[0][$index][1];
            $to = $matches[0][$index + 1][1] ?? strlen($document);
            $sections[$slug] = substr($document, $from, $to - $from);
        }

        return $sections;
    }

    /** @return array<string, string> every `Class::method` the Pinned by lines name, with the slug that names it */
    private function citations(): array
    {
        $citations = [];
        foreach ($this->sections() as $slug => $body) {
            if (preg_match('/^- \*\*Pinned by\.?\*\*(.+?)(?=^- \*\*)/mus', $body, $match) !== 1) {
                continue;
            }

            preg_match_all('/`([A-Z][A-Za-z0-9]*Test)::(test_[a-z0-9_]+)`/u', $match[1], $found, PREG_SET_ORDER);
            foreach ($found as $citation) {
                $citations[$citation[1] . '::' . $citation[2]] = $slug;
            }
        }

        return $citations;
    }

    private function document(): string
    {
        $path = $this->repositoryRoot() . '/' . self::DOCUMENT;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function repositoryRoot(): string
    {
        return $this->testTreeRoot();
    }
}
