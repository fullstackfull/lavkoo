<?php

namespace Tests\Feature\Security;

use App\Services\Identity\DuplicateIdentityReport;
use Illuminate\Support\Facades\DB;

/**
 * A2-01: the report that says whether a duplicate e-mail or phone is already stored.
 *
 * `users.phone` and `users.email` carry no unique key in the installation dump, and the dump is not in this repository, so no test can
 * prove the key exists. The criterion's other branch is what this closes: a report that counts the duplicates, so a platform it reports
 * zero for is one where a key could be added and the canonical switches turned on without an account that is already there being refused.
 * Three decisions wait on its figures (A2-01's own blocker, and the one named in the comments of `canonical_phone` and `canonical_email`).
 *
 * Two questions per identity, with different answers and different remedies:
 *
 *  - the EXACT STRING twice, which a unique key on the column would refuse, compared by the ENGINE so that what is counted is what that
 *    engine's collation would have refused;
 *  - one IDENTITY across its spellings, which no key on the raw column refuses, compared by the canonical rule the gates themselves read.
 *
 * The report is not a gate and has no switch: it is read-only, it answers the same with the canonical switches on or off
 * (`test_the_report_does_not_depend_on_either_canonical_switch`), and it writes nothing
 * (`test_the_report_changes_not_one_row`).
 *
 * Nothing it returns is an identity -- a group is a count and the row ids -- which `test_no_identity_is_in_the_report` pins, because the
 * report is meant to be run on the live platform and read in a terminal.
 */
class DuplicateIdentityReportTest extends CustomerAuthTestCase
{
    /** Three spellings of ONE Saudi mobile number, and a second number that is nobody else's. */
    private const TYPED_LOCALLY = '0500000005';

    private const TYPED_WITH_PLUS = '+966500000005';

    private const TYPED_WITH_ZEROS = '00966500000005';

    private const ANOTHER_NUMBER = '0500000006';

    private const ADDRESS = 'layla.victim@example.test';

    /** The same mailbox: the trailing dot is the domain's root label, and a different string on every engine. */
    private const ADDRESS_WITH_ROOT_DOT = 'layla.victim@example.test.';

    private const ANOTHER_ADDRESS = 'sara.hassan@example.test';

    private function report(): array
    {
        return app(DuplicateIdentityReport::class)->report();
    }

    private function customers(array ...$accounts): void
    {
        foreach ($accounts as $position => $account) {
            DB::table('users')->insert($account + [
                'name' => 'Customer ' . $position,
                'f_name' => 'Customer',
                'l_name' => (string) $position,
                'email' => 'customer' . $position . '@example.test',
                'phone' => '05000001' . str_pad((string) $position, 2, '0', STR_PAD_LEFT),
                'password' => 'not-a-real-hash',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // ---- a clean platform ----------------------------------------------------------------------------------------

    public function test_a_platform_with_no_duplicate_is_reported_clean(): void
    {
        $this->customers(
            ['phone' => self::TYPED_LOCALLY, 'email' => self::ADDRESS],
            ['phone' => self::ANOTHER_NUMBER, 'email' => self::ANOTHER_ADDRESS],
        );

        $report = $this->report();

        $this->assertTrue($report['clean'], 'a key could be added and the switches turned on');
        $this->assertSame(2, $report['accounts']);
        $this->assertSame(0, $report['phone']['exact']['groups']);
        $this->assertSame(0, $report['phone']['canonical']['groups']);
        $this->assertSame(0, $report['email']['exact']['groups']);
        $this->assertSame(0, $report['email']['canonical']['groups']);
    }

    public function test_the_command_exits_zero_on_a_clean_platform_and_says_a_key_could_be_added(): void
    {
        $this->customers(['phone' => self::TYPED_LOCALLY, 'email' => self::ADDRESS]);

        $this->artisan('gcc:identity:duplicate-report')
            ->expectsOutputToContain('No duplicate of either kind')
            ->assertExitCode(0);
    }

    // ---- the exact string, which a unique key would refuse -------------------------------------------------------

    public function test_one_number_typed_the_same_way_twice_is_what_a_unique_key_would_refuse(): void
    {
        $this->customers(
            ['phone' => self::TYPED_LOCALLY, 'email' => self::ADDRESS],
            ['phone' => self::TYPED_LOCALLY, 'email' => self::ANOTHER_ADDRESS],
        );

        $phone = $this->report()['phone'];

        $this->assertSame(1, $phone['exact']['groups']);
        $this->assertSame(2, $phone['exact']['accounts']);
        $this->assertSame([[1, 2]], $phone['exact']['listed'], 'the ids a merge is done by');
        $this->assertNull($phone['unique_key'], 'the installation dump carries none, which is why the report exists');
    }

    public function test_one_address_typed_the_same_way_twice_is_reported(): void
    {
        $this->customers(
            ['phone' => self::TYPED_LOCALLY, 'email' => self::ADDRESS],
            ['phone' => self::ANOTHER_NUMBER, 'email' => self::ADDRESS],
        );

        $email = $this->report()['email'];

        $this->assertSame(1, $email['exact']['groups']);
        $this->assertSame([[1, 2]], $email['exact']['listed']);
    }

    // ---- one identity across spellings, which no key on the raw column refuses ------------------------------------

    public function test_three_spellings_of_one_number_are_one_identity_and_no_exact_duplicate(): void
    {
        $this->customers(
            ['phone' => self::TYPED_LOCALLY, 'email' => self::ADDRESS],
            ['phone' => self::TYPED_WITH_PLUS, 'email' => self::ANOTHER_ADDRESS],
            ['phone' => self::TYPED_WITH_ZEROS, 'email' => 'third@example.test'],
        );

        $phone = $this->report()['phone'];

        $this->assertSame(0, $phone['exact']['groups'], 'three different strings: a unique key on the column would accept all three');
        $this->assertSame(1, $phone['canonical']['groups'], 'and one number: the canonical switch would refuse two of them');
        $this->assertSame(3, $phone['canonical']['accounts']);
        $this->assertSame([[1, 2, 3]], $phone['canonical']['listed']);
        $this->assertFalse($this->report()['clean']);
    }

    public function test_a_trailing_dot_on_the_domain_is_the_same_mailbox(): void
    {
        $this->customers(
            ['phone' => self::TYPED_LOCALLY, 'email' => self::ADDRESS],
            ['phone' => self::ANOTHER_NUMBER, 'email' => self::ADDRESS_WITH_ROOT_DOT],
        );

        $email = $this->report()['email'];

        $this->assertSame(0, $email['exact']['groups']);
        $this->assertSame(1, $email['canonical']['groups']);
        $this->assertSame([[1, 2]], $email['canonical']['listed']);
    }

    /**
     * A value the rule cannot reduce to an identity is REPORTED, not counted as unique: nothing proves two of them are not the same, and
     * a unique key would compare their raw strings, which the exact count already covers.
     */
    public function test_two_values_the_rule_cannot_judge_are_two_unjudged_values_and_not_one_identity(): void
    {
        $this->customers(
            ['phone' => '+11234567890', 'email' => 'not-an-address'],
            ['phone' => '+12125550123', 'email' => 'nor-is-this-one'],
            ['phone' => self::TYPED_LOCALLY, 'email' => self::ADDRESS],
        );

        $report = $this->report();

        $this->assertSame(2, $report['phone']['canonical']['unjudged'], 'the platform has no numbering plan for that country');
        $this->assertSame(2, $report['email']['canonical']['unjudged'], 'a value with no @ is not one address');
        $this->assertSame(0, $report['phone']['canonical']['groups'], 'two values nobody can judge are not a duplicate OF EACH OTHER');
        $this->assertSame(0, $report['email']['canonical']['groups']);
        $this->assertTrue($report['clean'], 'an unjudged value is not a duplicate');
    }

    public function test_the_command_exits_one_when_a_duplicate_exists_and_says_what_has_to_happen_first(): void
    {
        $this->customers(
            ['phone' => self::TYPED_LOCALLY, 'email' => self::ADDRESS],
            ['phone' => self::TYPED_WITH_PLUS, 'email' => self::ANOTHER_ADDRESS],
        );

        $this->artisan('gcc:identity:duplicate-report')
            ->expectsOutputToContain('Duplicates exist')
            ->assertExitCode(1);
    }

    // ---- what the report is NOT ----------------------------------------------------------------------------------

    /** The report is run on the live platform and read in a terminal: a group is a count and the row ids, never the number itself. */
    public function test_no_identity_is_in_the_report(): void
    {
        $this->customers(
            ['phone' => self::TYPED_LOCALLY, 'email' => self::ADDRESS],
            ['phone' => self::TYPED_WITH_PLUS, 'email' => self::ADDRESS_WITH_ROOT_DOT],
        );

        $printed = json_encode($this->report());

        foreach ([self::TYPED_LOCALLY, self::TYPED_WITH_PLUS, self::TYPED_WITH_ZEROS, self::ADDRESS, self::ADDRESS_WITH_ROOT_DOT] as $identity) {
            $this->assertStringNotContainsString($identity, (string) $printed, 'the report must not carry ' . $identity);
        }
    }

    public function test_the_command_prints_no_identity_either(): void
    {
        $this->customers(
            ['phone' => self::TYPED_LOCALLY, 'email' => self::ADDRESS],
            ['phone' => self::TYPED_WITH_PLUS, 'email' => self::ADDRESS_WITH_ROOT_DOT],
        );

        $command = $this->artisan('gcc:identity:duplicate-report');
        foreach ([self::TYPED_LOCALLY, self::TYPED_WITH_PLUS, self::ADDRESS, self::ADDRESS_WITH_ROOT_DOT] as $identity) {
            $command->doesntExpectOutputToContain($identity);
        }
        $command->expectsOutputToContain('accounts 1, 2')->assertExitCode(1);
    }

    /** It is a report and not a gate: it answers the same whether or not the switches that would refuse a duplicate are on. */
    public function test_the_report_does_not_depend_on_either_canonical_switch(): void
    {
        $this->customers(
            ['phone' => self::TYPED_LOCALLY, 'email' => self::ADDRESS],
            ['phone' => self::TYPED_WITH_PLUS, 'email' => self::ADDRESS_WITH_ROOT_DOT],
        );

        $withTheSwitchesOff = $this->report();
        config(['gcc.identity.canonical_phone.checked' => true, 'gcc.identity.canonical_email.checked' => true]);

        $this->assertSame($withTheSwitchesOff, $this->report());
        $this->assertSame(1, $withTheSwitchesOff['phone']['canonical']['groups']);
    }

    public function test_the_report_changes_not_one_row(): void
    {
        $this->customers(
            ['phone' => self::TYPED_LOCALLY, 'email' => self::ADDRESS],
            ['phone' => self::TYPED_WITH_PLUS, 'email' => self::ADDRESS_WITH_ROOT_DOT],
        );
        $before = DB::table('users')->orderBy('id')->get()->toJson();

        $this->report();
        $this->artisan('gcc:identity:duplicate-report')->run();

        $this->assertSame($before, DB::table('users')->orderBy('id')->get()->toJson());
    }

    /** More groups than are listed are counted and said to be there, so a big merge job is not read as a small one. */
    public function test_more_groups_than_are_listed_are_still_counted(): void
    {
        $accounts = [];
        for ($group = 0; $group < DuplicateIdentityReport::GROUPS_LISTED + 3; $group++) {
            $shared = 'shared' . $group . '@example.test';
            $accounts[] = ['email' => $shared, 'phone' => '05000002' . str_pad((string) $group, 2, '0', STR_PAD_LEFT)];
            $accounts[] = ['email' => $shared, 'phone' => '05000003' . str_pad((string) $group, 2, '0', STR_PAD_LEFT)];
        }
        $this->customers(...$accounts);

        $email = $this->report()['email'];

        $this->assertSame(DuplicateIdentityReport::GROUPS_LISTED + 3, $email['exact']['groups']);
        $this->assertCount(DuplicateIdentityReport::GROUPS_LISTED, $email['exact']['listed'], 'the list is bounded, the count is not');
    }
}
