<?php

namespace Tests\Feature\PlatformAdmin;

use App\Services\PlatformAdmin\Base32;
use App\Services\PlatformAdmin\RecoveryCodes;
use App\Services\PlatformAdmin\Totp;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsPlatformAdminWorld;
use Tests\Support\CapturesLogRecords;
use Tests\Support\EnrolsPlatformAdminFactors;
use Tests\TestCase;

/**
 * The second factor is enrolled, activated, reset and looked at only by the operator, on the server: these pin each command, what it prints once,
 * what it refuses, what it ends and what it audits.
 */
class MfaCommandsTest extends TestCase
{
    use BuildsPlatformAdminWorld;
    use CapturesLogRecords;
    use EnrolsPlatformAdminFactors;

    private const NOW = '2026-11-17 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildPlatformAdminWorld();
        $this->insertAdmin(id: 7, status: 1);
        $this->insertAdmin(id: 8, status: 1);
        $this->insertAdmin(id: 9, status: 0);
        $this->grant(adminId: 7);
        $this->grant(adminId: 8);
        Carbon::setTestNow(self::NOW);
        config(['app.name' => 'Example Platform']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---- enrol --------------------------------------------------------------------------------------------------------------------------------

    public function test_enrolling_makes_a_pending_factor_and_prints_the_secret_the_address_and_ten_codes_once(): void
    {
        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:enroll', arguments: ['admin_id' => 7]);

        $this->assertSame(0, $exit);
        $shown = $this->shownBy(output: $output);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $shown['secret']);
        $this->assertCount(10, $shown['codes']);
        $this->assertSame('otpauth://totp/Example%20Platform:admin7%40example.test?secret=' . $shown['secret'] . '&issuer=Example%20Platform&algorithm=SHA1&digits=6&period=30', $shown['uri']);
        $this->assertStringContainsString('PENDING', $output);
        $this->assertStringContainsString('gcc:platform-admin:mfa:confirm 7', $output);
        $this->assertStringContainsString('only time', $output);

        $factor = DB::table('platform_admin_mfa_factors')->where('admin_id', 7)->first();
        $this->assertNotNull($factor);
        $this->assertNull($factor->confirmed_at, 'pending: it counts only once it is confirmed');
        $this->assertNull($factor->last_used_step);
        $this->assertSame((int) DB::table('platform_admin_grants')->where('admin_id', 7)->value('id'), (int) $factor->grant_id);
        $this->assertSame($this->adminFingerprint(adminId: 7), $factor->admin_fingerprint);
    }

    public function test_what_is_stored_is_the_secret_encrypted_and_the_codes_as_hmacs_and_what_is_shown_is_what_is_stored(): void
    {
        [, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:enroll', arguments: ['admin_id' => 7]);
        $shown = $this->shownBy(output: $output);
        $factor = DB::table('platform_admin_mfa_factors')->where('admin_id', 7)->first();

        $this->assertNotSame($shown['secret'], $factor->secret);
        $this->assertSame($shown['secret'], Crypt::decryptString($factor->secret), 'the encrypted secret is the one that was shown');
        $this->assertSame(20, strlen((string) Base32::decode(text: $shown['secret'])), '160 bits');

        $stored = json_decode($factor->recovery_codes, true);
        $this->assertCount(10, $stored);
        $this->assertSame(array_fill(0, 10, null), array_column($stored, 'used_at'));
        $expected = array_map(static fn (string $code): string => RecoveryCodes::hash(normalized: (string) RecoveryCodes::normalized(typed: $code)), $shown['codes']);
        $this->assertEqualsCanonicalizing($expected, array_column($stored, 'hash'));
        $this->assertCount(10, array_unique(array_column($stored, 'hash')));
    }

    public function test_nothing_that_was_shown_is_stored_in_a_form_that_shows_it_again_or_written_to_the_log_or_the_audit_trail(): void
    {
        $log = $this->captureLog();

        [, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:enroll', arguments: ['admin_id' => 7]);
        $shown = $this->shownBy(output: $output);

        $everything = json_encode(array_map(static fn (string $table): array => DB::table($table)->get()->all(), Schema::getTableListing(schemaQualified: false)));
        $everything .= json_encode(array_map(static fn (LogRecord $record): array => [$record->message, $record->context], $log->getRecords()));
        foreach (array_merge([$shown['secret'], $shown['uri']], $shown['codes'], array_map(static fn (string $code): string => str_replace('-', '', $code), $shown['codes'])) as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
        $this->assertStringNotContainsString(bin2hex((string) Base32::decode(text: $shown['secret'])), $everything);
    }

    public function test_enrolling_is_audited_with_the_operator_and_without_a_secret(): void
    {
        $this->runCommand(command: 'gcc:platform-admin:mfa:enroll', arguments: ['admin_id' => 7]);

        $lines = $this->auditLines();
        $this->assertCount(1, $lines);
        $this->assertSame('mfa.enrolled', $lines[0]->action);
        $this->assertSame(7, (int) $lines[0]->admin_id);
        $this->assertNull($lines[0]->token_id);
        $this->assertSame(
            $this->sortedByKey(['source' => 'console', 'operator' => ['user' => $this->operatorUser(), 'host' => (string) gethostname()], 'replaced' => false]),
            $this->contextOf($lines[0]),
        );
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function enrolRefusals(): array
    {
        return [
            'an admin that does not exist' => [['admin_id' => 99], 'does not exist'],
            'an admin id that is not a number' => [['admin_id' => 'seven'], 'positive whole number'],
            'an inactive admin' => [['admin_id' => 9], 'not active'],
        ];
    }

    #[DataProvider('enrolRefusals')]
    public function test_an_enrolment_that_cannot_be_made_is_refused_and_nothing_is_written(array $arguments, string $reason): void
    {
        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:enroll', arguments: $arguments);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString($reason, $output);
        $this->assertSame(0, DB::table('platform_admin_mfa_factors')->count());
        $this->assertSame(0, DB::table('platform_admin_audit_logs')->count());
    }

    public function test_an_admin_without_a_grant_cannot_be_enrolled(): void
    {
        $this->insertAdmin(id: 10, status: 1);

        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:enroll', arguments: ['admin_id' => 10]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no active grant', $output);
        $this->assertStringContainsString('gcc:platform-admin:grant 10', $output);
        $this->assertSame(0, DB::table('platform_admin_mfa_factors')->count());
    }

    public function test_an_admin_whose_grant_is_revoked_or_was_made_for_another_row_cannot_be_enrolled(): void
    {
        DB::table('platform_admin_grants')->where('admin_id', 7)->update(['revoked_at' => now()]);
        DB::table('platform_admin_grants')->where('admin_id', 8)->update(['admin_fingerprint' => hash('sha256', 'another row')]);

        foreach ([7, 8] as $adminId) {
            [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:enroll', arguments: ['admin_id' => $adminId]);

            $this->assertSame(1, $exit, "admin {$adminId}");
            $this->assertStringContainsString('no active grant', $output);
        }
        $this->assertSame(0, DB::table('platform_admin_mfa_factors')->count());
        $this->assertSame(0, DB::table('platform_admin_audit_logs')->count());
    }

    public function test_an_admin_who_already_has_a_factor_pending_or_active_is_refused_unless_replacing_and_nothing_changes(): void
    {
        foreach ([false, true] as $confirmed) {
            DB::table('platform_admin_mfa_factors')->delete();
            $factor = $this->enrolFactor(adminId: 7, confirmed: $confirmed);
            $before = (array) DB::table('platform_admin_mfa_factors')->first();

            [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:enroll', arguments: ['admin_id' => 7]);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString('already has', $output);
            $this->assertStringContainsString('--replace', $output);
            $this->assertStringNotContainsString($factor['base32'], $output);
            $this->assertSame($before, (array) DB::table('platform_admin_mfa_factors')->first());
        }
        $this->assertSame(0, DB::table('platform_admin_audit_logs')->count());
    }

    public function test_a_factor_that_was_made_for_another_row_with_the_same_id_still_counts_as_there_and_is_replaced_only_on_request(): void
    {
        $this->enrolFactor(adminId: 7, overrides: ['admin_fingerprint' => hash('sha256', 'another row')]);

        [$exit] = $this->runCommand(command: 'gcc:platform-admin:mfa:enroll', arguments: ['admin_id' => 7]);
        $this->assertSame(1, $exit);

        [$exit] = $this->runCommand(command: 'gcc:platform-admin:mfa:enroll', arguments: ['admin_id' => 7, '--replace' => true]);
        $this->assertSame(0, $exit);
        $this->assertSame($this->adminFingerprint(adminId: 7), DB::table('platform_admin_mfa_factors')->where('admin_id', 7)->value('admin_fingerprint'));
    }

    public function test_replacing_removes_the_old_factor_ends_every_session_and_challenge_of_that_admin_only_and_is_audited(): void
    {
        $old = $this->enrolFactor(adminId: 7);
        $otherFactor = $this->enrolFactor(adminId: 8);
        $this->insertToken(adminId: 7, hash: str_repeat('a', 64));
        $this->insertToken(adminId: 7, hash: str_repeat('b', 64), revokedAt: '2026-11-01 08:00:00');
        $this->insertToken(adminId: 8, hash: str_repeat('c', 64));
        $this->insertChallenge(adminId: 7, hash: str_repeat('d', 64));
        $this->insertChallenge(adminId: 7, hash: str_repeat('e', 64), consumedAt: '2026-11-17 09:00:00');
        $this->insertChallenge(adminId: 8, hash: str_repeat('f', 64));

        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:enroll', arguments: ['admin_id' => 7, '--replace' => true]);

        $this->assertSame(0, $exit);
        $shown = $this->shownBy(output: $output);
        $this->assertNotSame($old['base32'], $shown['secret']);
        $this->assertSame($shown['secret'], Crypt::decryptString((string) DB::table('platform_admin_mfa_factors')->where('admin_id', 7)->value('secret')));
        $this->assertNull(DB::table('platform_admin_mfa_factors')->where('admin_id', 7)->value('confirmed_at'), 'the new factor is pending, so the admin cannot sign in with a second factor until it is confirmed');
        $this->assertSame(1, DB::table('platform_admin_mfa_factors')->where('admin_id', 7)->count());
        $this->assertSame($otherFactor['id'], (int) DB::table('platform_admin_mfa_factors')->where('admin_id', 8)->value('id'), 'another admin is not touched');

        $this->assertSame('2026-11-17 10:00:00', DB::table('platform_admin_tokens')->where('token_hash', str_repeat('a', 64))->value('revoked_at'));
        $this->assertSame('2026-11-01 08:00:00', DB::table('platform_admin_tokens')->where('token_hash', str_repeat('b', 64))->value('revoked_at'), 'an earlier time of ending is kept');
        $this->assertNull(DB::table('platform_admin_tokens')->where('token_hash', str_repeat('c', 64))->value('revoked_at'));
        $this->assertSame('2026-11-17 10:00:00', DB::table('platform_admin_mfa_challenges')->where('token_hash', str_repeat('d', 64))->value('consumed_at'));
        $this->assertSame('2026-11-17 09:00:00', DB::table('platform_admin_mfa_challenges')->where('token_hash', str_repeat('e', 64))->value('consumed_at'), 'an earlier time of consumption is kept');
        $this->assertNull(DB::table('platform_admin_mfa_challenges')->where('token_hash', str_repeat('f', 64))->value('consumed_at'));

        $line = $this->auditLines()->last();
        $this->assertSame('mfa.enrolled', $line->action);
        $this->assertSame(
            $this->sortedByKey(['source' => 'console', 'operator' => ['user' => $this->operatorUser(), 'host' => (string) gethostname()], 'replaced' => true, 'tokens_revoked' => 1, 'challenges_ended' => 1]),
            $this->contextOf($line),
        );
    }

    public function test_enrolling_works_with_the_api_switched_off_and_the_second_factor_not_required(): void
    {
        config(['gcc.platform_admin.enabled' => false, 'gcc.platform_admin.mfa.required' => false]);

        [$exit] = $this->runCommand(command: 'gcc:platform-admin:mfa:enroll', arguments: ['admin_id' => 7]);

        $this->assertSame(0, $exit);
    }

    // ---- confirm ------------------------------------------------------------------------------------------------------------------------------

    public function test_the_code_the_app_shows_activates_the_factor_and_marks_its_step_as_used(): void
    {
        $factor = $this->enrolFactor(adminId: 7, confirmed: false);
        $code = $this->totpFor($factor);

        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:confirm', arguments: ['admin_id' => 7, 'code' => $code]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('active', $output);
        $row = DB::table('platform_admin_mfa_factors')->where('admin_id', 7)->first();
        $this->assertSame(self::NOW, $row->confirmed_at);
        $this->assertSame(Totp::stepAt(timestamp: Carbon::parse(self::NOW)->timestamp), (int) $row->last_used_step, 'the code that confirmed it cannot be used again to sign in');

        $line = $this->auditLines()->last();
        $this->assertSame('mfa.confirmed', $line->action);
        $this->assertSame(7, (int) $line->admin_id);
        $this->assertSame($this->sortedByKey(['source' => 'console', 'operator' => ['user' => $this->operatorUser(), 'host' => (string) gethostname()]]), $this->contextOf($line));
        $this->assertStringNotContainsString($code, (string) $line->context);
    }

    public function test_a_code_of_the_step_before_or_after_is_accepted_and_spaces_in_it_do_not_matter(): void
    {
        foreach ([-1, 1] as $steps) {
            DB::table('platform_admin_mfa_factors')->delete();
            $factor = $this->enrolFactor(adminId: 7, confirmed: false);
            $code = $this->totpFor($factor, steps: $steps);

            [$exit] = $this->runCommand(command: 'gcc:platform-admin:mfa:confirm', arguments: ['admin_id' => 7, 'code' => substr($code, 0, 3) . ' ' . substr($code, 3)]);

            $this->assertSame(0, $exit, "step {$steps}");
            $this->assertSame(Totp::stepAt(timestamp: Carbon::parse(self::NOW)->timestamp) + $steps, (int) DB::table('platform_admin_mfa_factors')->value('last_used_step'));
        }
    }

    public function test_a_code_two_steps_away_is_not_accepted(): void
    {
        $factor = $this->enrolFactor(adminId: 7, confirmed: false);

        [$exit] = $this->runCommand(command: 'gcc:platform-admin:mfa:confirm', arguments: ['admin_id' => 7, 'code' => $this->totpFor($factor, steps: 2)]);

        $this->assertSame(1, $exit);
        $this->assertNull(DB::table('platform_admin_mfa_factors')->value('confirmed_at'));
    }

    /** @return array<string, array{string}> */
    public static function codesThatAreNotRight(): array
    {
        return [
            'a wrong code' => ['000000'],
            'five digits' => ['12345'],
            'seven digits' => ['1234567'],
            'letters' => ['abcdef'],
            'nothing' => [''],
        ];
    }

    #[DataProvider('codesThatAreNotRight')]
    public function test_a_wrong_code_changes_nothing_and_is_audited_without_the_code(string $code): void
    {
        $factor = $this->enrolFactor(adminId: 7, confirmed: false);
        if ($code === '000000' && $this->totpFor($factor) === '000000') {
            $this->markTestSkipped('The one code in a million that is also the right one.');
        }
        $before = (array) DB::table('platform_admin_mfa_factors')->first();

        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:confirm', arguments: ['admin_id' => 7, 'code' => $code]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not the code', $output);
        $this->assertSame($before, (array) DB::table('platform_admin_mfa_factors')->first());
        $line = $this->auditLines()->last();
        $this->assertSame('mfa.confirm_failed', $line->action);
        $this->assertSame(7, (int) $line->admin_id);
        $this->assertSame($this->sortedByKey(['source' => 'console', 'operator' => ['user' => $this->operatorUser(), 'host' => (string) gethostname()], 'reason' => 'wrong_code']), $this->contextOf($line));
    }

    public function test_confirming_needs_a_pending_factor_of_that_admin_row(): void
    {
        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:confirm', arguments: ['admin_id' => 7, 'code' => '123456']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no second factor', $output);

        $factor = $this->enrolFactor(adminId: 7);
        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:confirm', arguments: ['admin_id' => 7, 'code' => $this->totpFor($factor)]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('already active', $output);
        $this->assertNull(DB::table('platform_admin_mfa_factors')->value('last_used_step'), 'a factor that was active is not touched by a second confirmation');

        DB::table('platform_admin_mfa_factors')->delete();
        $foreign = $this->enrolFactor(adminId: 7, confirmed: false, overrides: ['admin_fingerprint' => hash('sha256', 'another row')]);
        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:confirm', arguments: ['admin_id' => 7, 'code' => $this->totpFor($foreign)]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('another admin row', $output);
        $this->assertNull(DB::table('platform_admin_mfa_factors')->value('confirmed_at'));

        $this->assertSame(0, DB::table('platform_admin_audit_logs')->count(), 'a command that has nothing to confirm leaves no line');
    }

    public function test_a_secret_that_the_current_key_cannot_read_is_refused_and_says_so(): void
    {
        $this->enrolFactor(adminId: 7, confirmed: false, overrides: ['secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP')]);
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstances();

        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:confirm', arguments: ['admin_id' => 7, 'code' => '123456']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('cannot be read', $output);
        $this->assertStringContainsString('--replace', $output);
        $this->assertSame('mfa.confirm_failed', $this->auditLines()->last()->action);
        $this->assertSame('unreadable_secret', $this->contextOf($this->auditLines()->last())['reason']);
    }

    // ---- reset --------------------------------------------------------------------------------------------------------------------------------

    public function test_resetting_removes_the_factor_ends_every_session_and_challenge_of_that_admin_only_and_is_audited(): void
    {
        $this->enrolFactor(adminId: 7);
        $other = $this->enrolFactor(adminId: 8);
        $this->insertToken(adminId: 7, hash: str_repeat('a', 64));
        $this->insertToken(adminId: 7, hash: str_repeat('b', 64));
        $this->insertToken(adminId: 8, hash: str_repeat('c', 64));
        $this->insertChallenge(adminId: 7, hash: str_repeat('d', 64));
        $this->insertChallenge(adminId: 8, hash: str_repeat('e', 64));

        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:reset', arguments: ['admin_id' => 7]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('2 session(s)', $output);
        $this->assertSame(0, DB::table('platform_admin_mfa_factors')->where('admin_id', 7)->count());
        $this->assertSame($other['id'], (int) DB::table('platform_admin_mfa_factors')->where('admin_id', 8)->value('id'));
        $this->assertSame(2, DB::table('platform_admin_tokens')->where('admin_id', 7)->whereNotNull('revoked_at')->count());
        $this->assertNull(DB::table('platform_admin_tokens')->where('admin_id', 8)->value('revoked_at'));
        $this->assertNotNull(DB::table('platform_admin_mfa_challenges')->where('admin_id', 7)->value('consumed_at'));
        $this->assertNull(DB::table('platform_admin_mfa_challenges')->where('admin_id', 8)->value('consumed_at'));
        $this->assertNotNull(DB::table('platform_admin_grants')->where('admin_id', 7)->value('id'), 'the grant stays: the admin is enrolled again, not made a stranger');
        $this->assertNull(DB::table('platform_admin_grants')->where('admin_id', 7)->value('revoked_at'));

        $line = $this->auditLines()->last();
        $this->assertSame('mfa.reset', $line->action);
        $this->assertSame(7, (int) $line->admin_id);
        $this->assertSame(
            $this->sortedByKey(['source' => 'console', 'operator' => ['user' => $this->operatorUser(), 'host' => (string) gethostname()], 'was' => 'confirmed', 'tokens_revoked' => 2, 'challenges_ended' => 1]),
            $this->contextOf($line),
        );
    }

    public function test_a_pending_factor_can_be_reset_too_and_says_so_in_the_trail(): void
    {
        $this->enrolFactor(adminId: 7, confirmed: false);

        [$exit] = $this->runCommand(command: 'gcc:platform-admin:mfa:reset', arguments: ['admin_id' => 7]);

        $this->assertSame(0, $exit);
        $this->assertSame('pending', $this->contextOf($this->auditLines()->last())['was']);
    }

    public function test_an_admin_can_be_enrolled_again_after_a_reset(): void
    {
        $this->enrolFactor(adminId: 7);
        $this->runCommand(command: 'gcc:platform-admin:mfa:reset', arguments: ['admin_id' => 7]);

        [$exit] = $this->runCommand(command: 'gcc:platform-admin:mfa:enroll', arguments: ['admin_id' => 7]);

        $this->assertSame(0, $exit);
        $this->assertSame(1, DB::table('platform_admin_mfa_factors')->where('admin_id', 7)->count());
    }

    public function test_resetting_an_admin_who_has_no_factor_is_refused_and_ends_nothing(): void
    {
        $this->insertToken(adminId: 7, hash: str_repeat('a', 64));

        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:reset', arguments: ['admin_id' => 7]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no second factor', $output);
        $this->assertNull(DB::table('platform_admin_tokens')->value('revoked_at'));
        $this->assertSame(0, DB::table('platform_admin_audit_logs')->count());
    }

    public function test_a_factor_of_another_row_can_be_reset_by_id(): void
    {
        $this->enrolFactor(adminId: 7, overrides: ['admin_fingerprint' => hash('sha256', 'another row')]);

        [$exit] = $this->runCommand(command: 'gcc:platform-admin:mfa:reset', arguments: ['admin_id' => 7]);

        $this->assertSame(0, $exit);
        $this->assertSame(0, DB::table('platform_admin_mfa_factors')->count(), 'the secret of a deleted admin can be got rid of');
    }

    // ---- status -------------------------------------------------------------------------------------------------------------------------------

    public function test_the_status_of_every_grant_says_the_state_of_the_factor_the_codes_left_and_the_sessions_with_and_without_it(): void
    {
        $this->insertAdmin(id: 10, status: 1);
        $this->grant(adminId: 10);
        $this->enrolFactor(adminId: 7);
        $this->enrolFactor(adminId: 8, confirmed: false);
        $this->insertToken(adminId: 7, hash: str_repeat('a', 64), mfaMethod: 'totp');
        $this->insertToken(adminId: 7, hash: str_repeat('b', 64), mfaMethod: 'recovery_code');
        $this->insertToken(adminId: 7, hash: str_repeat('c', 64));
        $this->insertToken(adminId: 7, hash: str_repeat('d', 64), revokedAt: '2026-11-01 08:00:00', mfaMethod: 'totp');
        $factor = DB::table('platform_admin_mfa_factors')->where('admin_id', 7)->first();
        $codes = json_decode($factor->recovery_codes, true);
        $codes[0]['used_at'] = '2026-11-16T10:00:00Z';
        $codes[1]['used_at'] = '2026-11-16T11:00:00Z';
        DB::table('platform_admin_mfa_factors')->where('admin_id', 7)->update(['recovery_codes' => json_encode($codes)]);

        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:status');

        $this->assertSame(0, $exit);
        $rows = $this->statusRows(output: $output);
        $this->assertSame(['7', 'active', '2026-11-17 10:00:00', '8', 'readable', '2', '1'], [$rows[7]['admin'], $rows[7]['factor'], $rows[7]['since'], $rows[7]['codes'], $rows[7]['secret'], $rows[7]['with_mfa'], $rows[7]['without_mfa']]);
        $this->assertSame(['pending', '10', '0', '0'], [$rows[8]['factor'], $rows[8]['codes'], $rows[8]['with_mfa'], $rows[8]['without_mfa']]);
        $this->assertSame(['none', '-', '-'], [$rows[10]['factor'], $rows[10]['codes'], $rows[10]['secret']]);
        $this->assertStringContainsString('The second factor is required.', $output);
        $this->assertStringNotContainsString('NOT required', $output);
    }

    public function test_the_status_names_a_secret_the_current_key_cannot_read_and_never_prints_a_secret_or_a_hash(): void
    {
        $factor = $this->enrolFactor(adminId: 7);
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstances();

        [, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:status');

        $this->assertSame('unreadable', $this->statusRows(output: $output)[7]['secret']);
        $this->assertStringNotContainsString($factor['base32'], $output);
        foreach (json_decode((string) DB::table('platform_admin_mfa_factors')->value('recovery_codes'), true) as $code) {
            $this->assertStringNotContainsString($code['hash'], $output);
        }
        $this->assertStringNotContainsString((string) DB::table('platform_admin_mfa_factors')->value('secret'), $output);
    }

    public function test_the_status_of_one_admin_shows_only_that_admin_and_a_stranger_is_refused(): void
    {
        $this->enrolFactor(adminId: 7);
        $this->enrolFactor(adminId: 8);

        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:status', arguments: ['admin_id' => 8]);

        $this->assertSame(0, $exit);
        $this->assertSame([8], array_keys($this->statusRows(output: $output)));

        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:status', arguments: ['admin_id' => 99]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no grant', $output);
    }

    public function test_the_status_says_when_the_second_factor_is_not_required(): void
    {
        config(['gcc.platform_admin.mfa.required' => false]);

        [, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:status');

        $this->assertStringContainsString('NOT required', $output);
    }

    public function test_looking_is_audited_too_with_the_operator(): void
    {
        $this->runCommand(command: 'gcc:platform-admin:mfa:status');
        $this->runCommand(command: 'gcc:platform-admin:mfa:status', arguments: ['admin_id' => 7]);

        $lines = $this->auditLines();
        $this->assertSame(['mfa.status', 'mfa.status'], $lines->pluck('action')->all());
        $this->assertSame([null, 7], $lines->pluck('admin_id')->map(fn ($id) => $id === null ? null : (int) $id)->all());
        $this->assertSame($this->sortedByKey(['source' => 'console', 'operator' => ['user' => $this->operatorUser(), 'host' => (string) gethostname()]]), $this->contextOf($lines[0]));
    }

    // ---- all of them --------------------------------------------------------------------------------------------------------------------------

    public function test_every_command_tells_the_operator_when_the_migrations_have_not_run(): void
    {
        $this->rollBackPlatformAdminMigrations();

        foreach ([['gcc:platform-admin:mfa:enroll', ['admin_id' => 7]], ['gcc:platform-admin:mfa:confirm', ['admin_id' => 7, 'code' => '123456']], ['gcc:platform-admin:mfa:reset', ['admin_id' => 7]], ['gcc:platform-admin:mfa:status', []]] as [$command, $arguments]) {
            [$exit, $output] = $this->runCommand(command: $command, arguments: $arguments);

            $this->assertSame(1, $exit, $command);
            $this->assertStringContainsString('migrations', $output, $command);
        }
    }

    public function test_the_migrations_of_the_second_factor_alone_missing_are_told_to_the_operator_too(): void
    {
        Schema::drop('platform_admin_mfa_challenges');
        Schema::drop('platform_admin_mfa_factors');

        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:status');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('platform_admin_mfa_factors', $output);
    }

    public function test_the_audit_lines_of_a_whole_enrolment_hold_no_secret_whatever_the_command(): void
    {
        [, $output] = $this->runCommand(command: 'gcc:platform-admin:mfa:enroll', arguments: ['admin_id' => 7]);
        $shown = $this->shownBy(output: $output);
        $secret = (string) Base32::decode(text: $shown['secret']);
        $code = Totp::codeAt(secret: $secret, step: Totp::stepAt(timestamp: Carbon::parse(self::NOW)->timestamp));
        $this->runCommand(command: 'gcc:platform-admin:mfa:confirm', arguments: ['admin_id' => 7, 'code' => $code]);
        $this->runCommand(command: 'gcc:platform-admin:mfa:status');
        $this->runCommand(command: 'gcc:platform-admin:mfa:reset', arguments: ['admin_id' => 7]);

        $trail = json_encode(DB::table('platform_admin_audit_logs')->get());
        $this->assertSame(['mfa.enrolled', 'mfa.confirmed', 'mfa.status', 'mfa.reset'], $this->auditLines()->pluck('action')->all());
        foreach (array_merge([$shown['secret'], bin2hex($secret), $code], $shown['codes']) as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $trail);
        }
    }

    // ---- the grant command --------------------------------------------------------------------------------------------------------------------

    public function test_the_grant_command_says_that_an_admin_without_a_confirmed_factor_cannot_sign_in_yet(): void
    {
        $this->insertAdmin(id: 10, status: 1);

        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:grant', arguments: ['admin_id' => 10]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no confirmed second factor', $output);
        $this->assertStringContainsString('gcc:platform-admin:mfa:enroll 10', $output);
        $this->assertStringNotContainsString('NOT required', $output);
    }

    public function test_the_grant_command_is_as_quiet_as_before_for_an_admin_whose_factor_is_confirmed(): void
    {
        $this->enrolFactor(adminId: 7);

        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:grant', arguments: ['admin_id' => 7]);

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('second factor', $output);
        $this->assertStringNotContainsString('NOT required', $output);
    }

    public function test_the_grant_command_still_asks_for_a_pending_factor_to_be_confirmed(): void
    {
        $this->enrolFactor(adminId: 7, confirmed: false);

        [, $output] = $this->runCommand(command: 'gcc:platform-admin:grant', arguments: ['admin_id' => 7]);

        $this->assertStringContainsString('no confirmed second factor', $output);
    }

    public function test_the_grant_command_warns_every_time_while_the_second_factor_is_not_required(): void
    {
        config(['gcc.platform_admin.mfa.required' => false]);
        $this->enrolFactor(adminId: 7);

        foreach ([7, 8, 7] as $adminId) {
            [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:grant', arguments: ['admin_id' => $adminId]);

            $this->assertSame(0, $exit);
            $this->assertStringContainsString('NOT required', $output);
            $this->assertStringContainsString('staging', $output);
            $this->assertStringNotContainsString('no confirmed second factor', $output, 'nothing is asked of an admin while nothing is required');
        }
    }

    public function test_a_refused_grant_prints_no_second_factor_advice(): void
    {
        [$exit, $output] = $this->runCommand(command: 'gcc:platform-admin:grant', arguments: ['admin_id' => 99]);

        $this->assertSame(1, $exit);
        $this->assertStringNotContainsString('second factor', $output);
        $this->assertStringNotContainsString('NOT required', $output);
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{0: int, 1: string} the exit code and what the command printed
     */
    private function runCommand(string $command, array $arguments = []): array
    {
        $exit = Artisan::call($command, $arguments);

        return [$exit, Artisan::output()];
    }

    /** @return array{secret: string, uri: string, codes: list<string>} what the enrolment printed */
    private function shownBy(string $output): array
    {
        $this->assertSame(1, preg_match('/^Secret \(base32\): (\S+)$/m', $output, $secret), 'the secret is on a line of its own');
        $this->assertSame(1, preg_match('/^otpauth URI: (\S+)$/m', $output, $uri), 'the address is on a line of its own');
        preg_match_all('/^  ([a-z2-9]{5}-[a-z2-9]{5})$/m', $output, $codes);

        return ['secret' => $secret[1], 'uri' => $uri[1], 'codes' => $codes[1]];
    }

    /** @return array<int, array<string, string>> the rows of the table the status printed, by admin */
    private function statusRows(string $output): array
    {
        $rows = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\|\s*(\d+)\s*\|(.*)\|$/', $line, $match) !== 1) {
                continue;
            }
            $cells = array_map('trim', explode('|', $match[1] . '|' . $match[2]));
            $rows[(int) $cells[0]] = ['admin' => $cells[0], 'factor' => $cells[1], 'since' => $cells[2], 'codes' => $cells[3], 'secret' => $cells[4], 'with_mfa' => $cells[5], 'without_mfa' => $cells[6]];
        }
        ksort($rows);

        return $rows;
    }

    private function operatorUser(): string
    {
        return function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? (string) posix_getpwuid(posix_geteuid())['name'] : (string) (getenv('USER') ?: getenv('USERNAME') ?: 'unknown');
    }

    private function insertAdmin(int $id, int $status): void
    {
        DB::table('admins')->insert(['id' => $id, 'name' => "Admin {$id}", 'email' => "admin{$id}@example.test", 'password' => 'x', 'status' => $status, 'created_at' => '2026-01-05 09:00:00']);
    }

    private function grant(int $adminId): int
    {
        return (int) DB::table('platform_admin_grants')->insertGetId(['admin_id' => $adminId, 'admin_fingerprint' => $this->adminFingerprint(adminId: $adminId), 'level' => 1, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertToken(int $adminId, string $hash, ?string $revokedAt = null, ?string $mfaMethod = null): void
    {
        DB::table('platform_admin_tokens')->insert([
            'admin_id' => $adminId, 'grant_id' => (int) DB::table('platform_admin_grants')->where('admin_id', $adminId)->value('id'), 'token_hash' => $hash, 'password_fingerprint' => str_repeat('c', 64),
            'device_name' => 'Test phone', 'mfa_method' => $mfaMethod, 'expires_at' => now()->addHour(), 'revoked_at' => $revokedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertChallenge(int $adminId, string $hash, ?string $consumedAt = null): void
    {
        DB::table('platform_admin_mfa_challenges')->insert([
            'token_hash' => $hash, 'admin_id' => $adminId, 'grant_id' => (int) DB::table('platform_admin_grants')->where('admin_id', $adminId)->value('id'), 'password_fingerprint' => str_repeat('c', 64),
            'device_name' => 'Test phone', 'expires_at' => now()->addMinutes(5), 'consumed_at' => $consumedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function auditLines(): Collection
    {
        return DB::table('platform_admin_audit_logs')->orderBy('id')->get();
    }
}
