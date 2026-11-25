<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * `StaffCountryScope::resolve()` asks the scope table TWO questions, and they stay two (G1-14).
 *
 * The two questions are "does this employee hold any row at all" and "which of their rows are still in force". They are not the same
 * question, and the platform's answer to the first one is what makes the second one safe to ask: an employee with NO row is UNRESTRICTED
 * here, because turning the switch on must change nothing until somebody is actually assigned a country. So an employee whose every row has
 * EXPIRED has to come back restricted to nothing -- an empty list -- and not as an employee who holds no row.
 *
 * Fold the two into one "ignore the expired rows" query and that distinction is gone: the expired employee holds no country, which reads as
 * no row, which reads as unrestricted. A temporary employee would be handed every market the hour their grant ended. It is the worst shape
 * of security defect, one that appears on a timer with nothing changing in the code.
 *
 * The behaviour is pinned by `StaffScopeGrantTrailTest::test_an_employee_whose_every_grant_has_expired_is_restricted_to_nothing_and_never_widened`,
 * which travels past the expiry and asserts `[]` rather than `null`. This guard is here because that test can only fail AFTER someone has
 * made the change, and the change looks exactly like a performance repair: the second read is a real second query, and twice today a
 * perfectly correct expectation of two reads arrived looking like a regression inviting that repair (`StaffLayoutTest` in `173b39d3`,
 * `StaffSearchHitsTest` in `211ccded`, both pinned at two). What the guard adds is that the shape itself is now spelled out: the question
 * "does this employee hold a row" may not learn about expiry, and `resolve()` may not answer "unrestricted" from the rows that are in
 * force.
 *
 * It reads the source rather than running the service, because what it is protecting is the arrangement and not an output. The number of
 * reads on any one path is a property of how many resolvers run there and not of a cache: `AdvanceSearch` memoizes per INSTANCE, and
 * `countriesOfAdmin()` is deliberately kept nowhere so the platform-admin API reads the rows as they are now. A memo on `resolve()` is not
 * forbidden here, but it has to say what it does to the expiry, since a scope cached for the length of a request outlives an expiry inside
 * that request.
 */
class StaffScopeAsksTwoQuestionsTest extends TestCase
{
    private const ANY_ROW = 'storedCountriesOf';

    private const STILL_IN_FORCE = 'countriesStillInForceFor';

    /** The repository root, resolved the way the other architecture tests do: these run without a booted application. */
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function source(): string
    {
        return (string) file_get_contents($this->root() . '/app/Services/Staff/StaffCountryScope.php');
    }

    /** The body of one method of the file, from its signature to the closing brace at its own indentation. */
    private function bodyOf(string $method): string
    {
        $source = $this->source();
        $start = strpos($source, 'function ' . $method . '(');
        $this->assertNotFalse($start, 'StaffCountryScope::' . $method . '() is gone; the scope cannot be read without it');

        $end = strpos($source, "\n    }", $start);
        $this->assertNotFalse($end, 'StaffCountryScope::' . $method . '() has no closing brace at the indentation of a method');

        return substr($source, $start, $end - $start);
    }

    public function test_resolve_asks_both_questions(): void
    {
        $body = $this->bodyOf('resolve');

        $this->assertStringContainsString(self::ANY_ROW . '(', $body, sprintf(
            'resolve() must ask %s() whether the employee holds ANY row: an employee with none is unrestricted, and that is the only '
            . 'question that can tell them from an employee whose every row has expired.',
            self::ANY_ROW,
        ));
        $this->assertStringContainsString(self::STILL_IN_FORCE . '(', $body, sprintf(
            'resolve() must ask %s() which rows are in force: a row with an expiry in the past grants nothing.',
            self::STILL_IN_FORCE,
        ));
    }

    /**
     * The order is the security property, not a detail: "unrestricted" may only ever be answered from the question about rows EXISTING. A
     * `return null` that can be reached from the in-force rows is the privilege escalation.
     */
    public function test_only_the_question_about_having_rows_can_answer_unrestricted(): void
    {
        $body = $this->bodyOf('resolve');

        $anyRow = strpos($body, self::ANY_ROW . '(');
        $stillInForce = strpos($body, self::STILL_IN_FORCE . '(');
        $unrestricted = strrpos($body, 'return null');

        $this->assertIsInt($unrestricted, 'resolve() must still answer null (unrestricted) somewhere: the master admin and an employee with no row');
        $this->assertLessThan($unrestricted, $anyRow, self::ANY_ROW . '() must be read BEFORE resolve() answers unrestricted');
        $this->assertGreaterThan($unrestricted, $stillInForce, sprintf(
            'resolve() answers unrestricted after reading %s(). An employee whose every grant has expired would then be read as an '
            . 'employee who was never assigned a country -- unrestricted -- which is a privilege escalation on a timer (G1-14).',
            self::STILL_IN_FORCE,
        ));
    }

    /**
     * The question "does this employee hold a row" must not know what an expiry is. This is the half a reader would change first, because
     * adding the expiry condition here looks like removing a duplicate condition rather than like deleting a distinction.
     */
    public function test_the_question_about_having_rows_does_not_know_about_expiry(): void
    {
        $body = $this->bodyOf(self::ANY_ROW);

        foreach (['EXPIRES_AT', 'expires_at'] as $expiry) {
            $this->assertStringNotContainsString($expiry, $body, sprintf(
                '%s() reads the expiry. It is the question of whether the employee holds ANY row, damaged and expired ones included; an '
                . 'employee it answers nothing for is read as unrestricted, so an expiry filtered here hands every market to whoever '
                . 'has just run out of grant.',
                self::ANY_ROW,
            ));
        }
    }

    /** And the other half must: a row that is in force is one whose expiry has not passed. */
    public function test_the_question_about_being_in_force_reads_the_expiry(): void
    {
        $body = $this->bodyOf(self::STILL_IN_FORCE);

        $this->assertStringContainsString('EXPIRES_AT', $body, self::STILL_IN_FORCE . '() must judge a row by its expiry column');
        $this->assertStringContainsString('now()', $body, self::STILL_IN_FORCE . '() must compare that expiry with the time now');
    }

    /** The behaviour this arrangement exists for is tested, and the guard names where, so neither is deleted as the other's duplicate. */
    public function test_the_behaviour_is_pinned_by_a_test_that_travels_past_the_expiry(): void
    {
        $test = (string) file_get_contents($this->root() . '/tests/Feature/StaffScope/StaffScopeGrantTrailTest.php');

        $this->assertStringContainsString('test_an_employee_whose_every_grant_has_expired_is_restricted_to_nothing_and_never_widened', $test);
        $this->assertStringContainsString('travelTo', $test, 'the expired case has to be reached by moving the clock, not by writing a past expiry a grant refuses');
    }
}
