<?php

namespace Tests\Unit\Deploy;

use LeakGuard\Scanner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 3) . '/scripts/ci/leak-guard.php';

/**
 * The check that keeps a secret, a key, a dump or a real person's contact details out of the repository. Every secret used here
 * is assembled at run time from pieces: this file must not itself contain anything the guard would refuse.
 */
class LeakGuardTest extends TestCase
{
    private string $repo;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        if ((new Process(['git', '--version']))->run() !== 0) {
            $this->markTestSkipped('git is not available.');
        }

        $this->repo = sys_get_temp_dir() . '/leak-guard-test-' . bin2hex(random_bytes(4));
        mkdir($this->repo, 0755, true);
        $this->git(['init', '-q', '-b', 'main']);
        $this->git(['config', 'user.email', 'test@example.test']);
        $this->git(['config', 'user.name', 'Test']);
        $this->commit(['README.md' => "# Project\n", 'app/Existing.php' => "<?php\n// untouched\n"], 'base');
        $this->base = $this->revision();
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->repo]))->run();
        parent::tearDown();
    }

    /** @return list<string> "path:line rule" for everything the guard found in a change that adds these files */
    private function findingsFor(array $files): array
    {
        $this->commit($files, 'change');

        return array_map(
            static fn (array $finding): string => $finding['path'] . ':' . $finding['line'] . ' ' . $finding['rule'],
            (new Scanner($this->repo))->scan($this->base),
        );
    }

    private function jwt(): string
    {
        $encode = static fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256']) . '.' . $encode(['sub' => '1']) . '.' . str_repeat('s', 20);
    }

    public function test_an_ordinary_change_passes(): void
    {
        $findings = $this->findingsFor([
            'app/Services/Thing.php' => "<?php\n\$timeout = 30;\n\$password = \$request->input('password');\n\$url = env('M5AZN_URL', 'https://m5azn.com');\n",
            '.env.example' => "APP_KEY=\nDB_PASSWORD=\nMAIL_FROM_ADDRESS=hello@example.com\n",
            'docs/guide.md' => "Contact: noreply@anthropic.com\n",
        ]);

        $this->assertSame([], $findings);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function secrets(): array
    {
        return [
            'private key' => ['-----BEGIN ' . 'RSA PRIVATE KEY-----', 'private-key'],
            'aws access key' => ['key = AKIA' . 'IOSFODNN7EXAMPLE', 'api-token'],
            'github token' => ['token ghp_' . 'abcdefghijklmnopqrstuvwxyz0123456789', 'api-token'],
            'google api key' => ['AIza' . 'SyA1234567890abcdefghijklmnopqrstuv', 'api-token'],
            'slack token' => ['xoxb-' . '123456789012-abcdefghij', 'api-token'],
            'stripe live key' => ['sk_live_' . 'abcdefghijklmnop1234', 'api-token'],
            'credential assigned in php' => ["\$config['db_password'] = 'Xk29-realistic-value-77';", 'credential-literal'],
            'credential in an array' => ["'api_key' => 'live-8f3a9c1d2b7e4f60',", 'credential-literal'],
            'credential in env style' => ['MAIL_PASSWORD="Zq81-mailbox-pass-4417"', 'credential-literal'],
            'saudi mobile number' => ['call 05' . '51234567', 'saudi-mobile-number'],
            'international saudi number' => ['+9665' . '51234567', 'saudi-mobile-number'],
            'personal e-mail address' => ['owner: someone' . '@gmail.com', 'email-address'],
        ];
    }

    /** @dataProvider secrets */
    public function test_a_secret_or_personal_detail_is_found_where_it_was_added_and_never_quoted(string $line, string $rule): void
    {
        $findings = $this->findingsFor(['app/Services/Leaky.php' => "<?php\n// fine\n{$line}\n// fine again\n"]);

        $this->assertSame(["app/Services/Leaky.php:3 {$rule}"], $findings);
    }

    public function test_a_jwt_is_found(): void
    {
        $this->assertSame(['app/Token.php:2 jwt'], $this->findingsFor(['app/Token.php' => "<?php\n\$t = '" . $this->jwt() . "';\n"]));
    }

    public function test_the_report_never_contains_what_it_matched(): void
    {
        $secret = 'Xk29-realistic-value-77';
        $this->commit(['app/Leaky.php' => "<?php\n\$password = '{$secret}';\n"], 'change');

        $process = new Process(['php', dirname(__DIR__, 3) . '/scripts/ci/leak-guard.php', '--root=' . $this->repo, '--base=' . $this->base]);
        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('app/Leaky.php:2  credential-literal', $process->getOutput());
        $this->assertStringNotContainsString($secret, $process->getOutput() . $process->getErrorOutput());
    }

    public function test_placeholders_and_code_that_only_reads_a_secret_are_not_credentials(): void
    {
        $findings = $this->findingsFor([
            'app/Services/Safe.php' => "<?php\n"
                . "\$a = ['password' => 'changeme-please-now'];\n"
                . "\$b = ['api_key' => 'your-api-key-goes-here'];\n"
                . "\$c = \$config->secret('webhook_secret');\n"
                . "\$d = ['secret' => \$value];\n"
                . "\$e = ['token' => 'short'];\n"
                . "\$f = ['password' => '••••••••••••'];\n",
        ]);

        $this->assertSame([], $findings);
    }

    public function test_a_choice_between_two_words_is_not_a_key_with_a_value(): void
    {
        $findings = $this->findingsFor([
            'resources/views/page.blade.php' => "{{ translate(\$urls === [] ? 'generate_webhook_secret' : 'rotate_webhook_secret') }}\n",
            'app/Services/Choice.php' => "<?php\n\$label = \$empty ? \"new_password_label\" : \"change_password_label\";\n",
        ]);

        $this->assertSame([], $findings);
        $this->assertSame(['app/Services/Still.php:2 credential-literal'], $this->findingsFor(['app/Services/Still.php' => "<?php\n\$a = \$ok ? ['password' => 'Xk29-realistic-value-77'] : [];\n"]));
    }

    public function test_fixtures_and_language_files_may_hold_fake_people_and_words(): void
    {
        $findings = $this->findingsFor([
            'tests/Feature/CheckoutTest.php' => "<?php\n\$phone = '0551234567';\n\$email = 'sara@gmail.com';\n\$password = 'fixture-password-1234';\n",
            'resources/lang/en/new-messages.php' => "<?php\nreturn ['phone_help' => 'Example: 0551234567', 'password_hint' => 'password: Choose-a-long-passphrase'];\n",
            'docs/example.md' => "Use password = 'something-long-enough' in the form\n",
        ]);

        $this->assertSame([], $findings);
    }

    public function test_real_secrets_are_refused_even_in_fixtures(): void
    {
        $findings = $this->findingsFor(['tests/Feature/LeakyFixtureTest.php' => "<?php\n// -----BEGIN " . "PRIVATE KEY-----\n"]);

        $this->assertSame(['tests/Feature/LeakyFixtureTest.php:2 private-key'], $findings);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function forbiddenFiles(): array
    {
        return [
            'environment file' => ['.env', 'environment-file'],
            'environment file for production' => ['.env.production', 'environment-file'],
            'environment file in a folder' => ['deploy/.env.pilot', 'environment-file'],
            'private key' => ['keys/server.pem', 'key-material'],
            'apple key' => ['public/assets/AuthKey_ABC123.p8', 'key-material'],
            'oauth key' => ['storage/oauth-private.key', 'key-material'],
            'database dump' => ['backup/production.sql', 'database-dump'],
            'compressed dump' => ['db/prod.sql.gz', 'database-dump'],
            'editor backup' => ['resources/lang/en/new-messages.php.bak', 'database-dump'],
            'verification evidence' => ['storage/app/fulfillment-evidence/m5azn/m5azn_20261105_100000_abcd1234.json', 'runtime-output'],
            'the overlay build' => ['build/overlay/manifest.json', 'runtime-output'],
            'application log' => ['storage/logs/laravel.log', 'runtime-output'],
        ];
    }

    /** @dataProvider forbiddenFiles */
    public function test_a_file_that_must_never_be_committed_is_refused_by_name(string $path, string $rule): void
    {
        $this->assertSame(["{$path}:0 {$rule}"], $this->findingsFor([$path => "harmless text\n"]));
    }

    public function test_the_example_environment_file_is_the_one_environment_file_allowed(): void
    {
        $this->assertSame([], $this->findingsFor(['.env.example' => "APP_NAME=Store\n", 'deploy/.env.example' => "A=1\n"]));
    }

    public function test_only_the_lines_a_change_adds_are_judged(): void
    {
        $this->commit(['app/Old.php' => "<?php\n\$old = '" . 'AKIA' . "IOSFODNN7EXAMPLE';\n// line 3\n"], 'an old mistake');
        $this->base = $this->revision();

        $findings = $this->findingsFor(['app/Old.php' => "<?php\n\$old = '" . 'AKIA' . "IOSFODNN7EXAMPLE';\n// line 3\n\$new = 1;\n"]);

        $this->assertSame([], $findings, 'what was already there is not this change\'s doing');

        $this->base = $this->revision();
        $this->assertSame(['app/Old.php:5 private-key'], $this->findingsFor(['app/Old.php' => "<?php\n\$old = '" . 'AKIA' . "IOSFODNN7EXAMPLE';\n// line 3\n\$new = 1;\n// -----BEGIN " . "PRIVATE KEY-----\n"]));
    }

    public function test_a_reasoned_exception_silences_a_false_alarm_and_an_unreasoned_one_does_not(): void
    {
        $this->commit(['docs/addresses.md' => "Write to help" . "@gmail.com\n"], 'change');

        file_put_contents($this->repo . '/allow.json', json_encode(['allow' => [['rule' => 'email-address', 'path' => 'docs/*.md']]]));
        $this->assertCount(1, (new Scanner($this->repo, $this->repo . '/allow.json'))->scan($this->base), 'no reason, no exception');

        file_put_contents($this->repo . '/allow.json', json_encode(['allow' => [['rule' => 'email-address', 'path' => 'docs/*.md', 'reason' => 'the shared support mailbox, not a person']]]));
        $this->assertSame([], (new Scanner($this->repo, $this->repo . '/allow.json'))->scan($this->base));
    }

    public function test_it_says_so_when_it_cannot_run(): void
    {
        $process = new Process(['php', dirname(__DIR__, 3) . '/scripts/ci/leak-guard.php', '--root=' . $this->repo, '--base=no-such-revision']);
        $process->run();

        $this->assertSame(2, $process->getExitCode());
    }

    public function test_a_first_push_has_no_previous_revision_to_compare_with_so_it_compares_with_the_one_before(): void
    {
        $this->commit(['app/Leaky.php' => "<?php\n// -----BEGIN " . "PRIVATE KEY-----\n"], 'change');

        $process = new Process(['php', dirname(__DIR__, 3) . '/scripts/ci/leak-guard.php', '--root=' . $this->repo, '--base=' . str_repeat('0', 40)]);
        $process->run();

        $this->assertSame(1, $process->getExitCode());
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, string> $files */
    private function commit(array $files, string $message): void
    {
        foreach ($files as $path => $content) {
            if (!is_dir(dirname($this->repo . '/' . $path))) {
                mkdir(dirname($this->repo . '/' . $path), 0755, true);
            }
            file_put_contents($this->repo . '/' . $path, $content);
        }
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '-m', $message]);
    }

    private function revision(): string
    {
        $process = new Process(['git', '-C', $this->repo, 'rev-parse', 'HEAD']);
        $process->mustRun();

        return trim($process->getOutput());
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): void
    {
        (new Process(['git', '-C', $this->repo, ...$arguments]))->mustRun();
    }
}
