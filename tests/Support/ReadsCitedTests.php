<?php

namespace Tests\Support;

/**
 * Finds the `Class::method` citations of a document and says which of them are not in the test tree.
 *
 * Shared by the guards over the documents that cite tests as their evidence (`THREAT_MODEL.md`, `GO_NO_GO_TODAY.md`). A document whose
 * citations nobody checks is the document a reviewer trusts and should not: a control named in prose and pinned by a test that was renamed
 * with the pull request that replaced it reads exactly like a control that is still there. Of the first 36 citations written into the threat
 * model, four named a plausible method of a real test file that does not exist.
 */
trait ReadsCitedTests
{
    /**
     * @param string $text the document, or the part of it to read
     * @return array<string, string> every `Class::method` named in backticks, in the order it appears, with the method as the value
     */
    protected function citedTests(string $text): array
    {
        preg_match_all('/`([A-Z][A-Za-z0-9]*Test)::(test_[a-z0-9_]+)`/u', $text, $found, PREG_SET_ORDER);

        $citations = [];
        foreach ($found as $citation) {
            $citations[$citation[1] . '::' . $citation[2]] = $citation[2];
        }

        return $citations;
    }

    /**
     * @param array<string, mixed> $citations `Class::method` as the key, as `citedTests()` returns
     * @return list<string> the citations that name no file under tests/, or a file with no such method
     */
    protected function citationsThatAreNotInTheTree(array $citations): array
    {
        $missing = [];
        foreach (array_keys($citations) as $citation) {
            [$class, $method] = explode('::', (string) $citation);

            $file = $this->fileOfTestClass($class);
            if ($file === null) {
                $missing[] = "{$class} is in no file under tests/ (cited as {$citation})";
                continue;
            }
            if (!str_contains((string) file_get_contents($file), 'function ' . $method . '(')) {
                $missing[] = "{$class} has no method {$method}()";
            }
        }

        return $missing;
    }

    /**
     * @param string $text the document, or the part of it to read
     * @return list<string> every test class named in backticks WITHOUT a method, which is a weaker citation and still has to exist
     */
    protected function citedTestClasses(string $text): array
    {
        preg_match_all('/`([A-Z][A-Za-z0-9]*Test)`/u', $text, $found);

        return array_values(array_unique($found[1]));
    }

    /**
     * @param list<string> $classes
     * @return list<string> the class names that name no file under tests/
     */
    protected function classesThatAreNotInTheTree(array $classes): array
    {
        return array_values(array_filter($classes, fn (string $class): bool => $this->fileOfTestClass($class) === null));
    }

    protected function fileOfTestClass(string $class): ?string
    {
        $found = glob($this->testTreeRoot() . '/tests/{,*/,*/*/,*/*/*/}' . $class . '.php', GLOB_BRACE) ?: [];

        return $found[0] ?? null;
    }

    protected function testTreeRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
