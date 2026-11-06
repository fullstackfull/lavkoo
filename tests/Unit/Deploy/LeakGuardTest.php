<?php

namespace Tests\Unit\Deploy;

use LeakGuard\Scanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 3) . '/scripts/ci/leak-guard.php';

/**
 * The check that keeps a secret, a key, a dump or a real person's contact details out of the repository. Every secret used here is
 * assembled at run time from pieces: this file must not itself contain anything the guard would refuse.
 */
class LeakGuardTest extends TestCase
{
    private const GUARD = __DIR__ . '/../../../scripts/ci/leak-guard.php';

    /** @var array<string, string|false> */
    private array $environment = [];

    private string $repo;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        if ((new Process(['git', '--version']))->run() !== 0) {
            $this->markTestSkipped('git is not available.');
        }

        // The developer's own git configuration (signing, hooks, diff prefixes) must not decide whether these tests pass.
        foreach (['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'] as $name => $value) {
            $this->environment[$name] = getenv($name);
            putenv("{$name}={$value}");
        }

        $this->repo = sys_get_temp_dir() . '/leak-guard-test-' . bin2hex(random_bytes(4));
        mkdir($this->repo, 0755, true);
        $this->git(['init', '-q', '-b', 'main']);
        foreach ([['user.email', 'test@example.test'], ['user.name', 'Test'], ['commit.gpgsign', 'false'], ['core.hooksPath', '/dev/null']] as [$key, $value]) {
            $this->git(['config', $key, $value]);
        }
        $this->commit(['README.md' => "# Project\n", 'app/Existing.php' => "<?php\n// untouched\n"], 'base');
        $this->base = $this->revision();
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->repo]))->run();
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        parent::tearDown();
    }

    /** @return list<string> "path:line rule" for everything the guard found in a change that adds these files */
    private function findingsFor(array $files): array
    {
        $this->commit($files, 'change');

        return $this->findings();
    }

    /** @return list<string> */
    private function findings(): array
    {
        return array_map(
            static fn (array $finding): string => (isset($finding['commit']) ? '@' : '') . $finding['path'] . ':' . $finding['line'] . ' ' . $finding['rule'],
            (new Scanner($this->repo))->scan($this->base),
        );
    }

    private function jwt(): string
    {
        $encode = static fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256']) . '.' . $encode(['sub' => '1']) . '.' . str_repeat('s', 20);
    }

    private function runGuard(array $arguments, array $environment = []): Process
    {
        $process = new Process(['php', self::GUARD, '--root=' . $this->repo, ...$arguments], null, $environment);
        $process->run();

        return $process;
    }

    // ── what one line can be ─────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: string, 2: string}> path, line, the rule it must break */
    public static function secretLines(): array
    {
        return [
            'private key' => ['app/Leaky.php', '-----BEGIN ' . 'RSA PRIVATE KEY-----', 'private-key'],
            'aws access key' => ['app/Leaky.php', 'key = AKIA' . 'IOSFODNN7EXAMPLE', 'api-token'],
            'github token' => ['app/Leaky.php', 'token ghp_' . 'abcdefghijklmnopqrstuvwxyz0123456789', 'api-token'],
            'google api key' => ['app/Leaky.php', 'AIza' . 'SyA1234567890abcdefghijklmnopqrstuv', 'api-token'],
            'slack token' => ['app/Leaky.php', 'xoxb-' . '123456789012-abcdefghij', 'api-token'],
            'stripe live key' => ['app/Leaky.php', 'sk_live_' . 'abcdefghijklmnop1234', 'api-token'],
            'npm token' => ['deploy/.npmrc', '//registry.npmjs.org/:_auth' . 'Token=npm_' . 'abcdefghijklmnopqrstuvwxyz0123456789', 'api-token'],
            'gitlab token' => ['app/Leaky.php', 'glpat-' . 'abcdefghijklmnopqrstuv', 'api-token'],
            'sendgrid key' => ['app/Leaky.php', 'SG.' . 'abcdefghijklmnopqrstuv.abcdefghijklmnopqrstuv', 'api-token'],
            'webhook signing secret' => ['app/Leaky.php', 'whsec_' . 'abcdefghijklmnopqrstuv', 'api-token'],
            'credential assigned in php' => ['app/Leaky.php', "\$config['db_password'] = 'Xk29-realistic-value-77';", 'credential-literal'],
            'credential in an array' => ['app/Leaky.php', "'api_key' => 'live-8f3a9c1d2b7e4f60',", 'credential-literal'],
            'a short but mixed password' => ['app/Leaky.php', "['password' => 'Pa55w0rd!']", 'credential-literal'],
            'a password with a dollar sign' => ['app/Leaky.php', "['password' => 'Zq81\$mailbox\$4417']", 'credential-literal'],
            'a camel case key' => ['app/Leaky.php', "['dbPassword' => 'Zq81-mailbox-pass-4417']", 'credential-literal'],
            'define of a constant' => ['config/db.php', "define('DB_PASSWORD', 'Zq81-mailbox-pass-4417');", 'credential-literal'],
            'a setter' => ['app/Leaky.php', "\$client->setPassword('Zq81-mailbox-pass-4417');", 'credential-literal'],
            'a bearer value' => ['app/Leaky.php', "'Authorization' => 'Bearer abcdefghijklmnopqrstuvwxyz0123456789',", 'credential-literal'],
            'dotenv style, quoted' => ['deploy/app.conf', 'MAIL_PASSWORD="Zq81-mailbox-pass-4417"', 'credential-literal'],
            'dotenv style, bare' => ['deploy/.env.production', 'MAIL_PASSWORD=Zq81mailboxpass4417', 'credential-literal'],
            'yaml, bare' => ['docker-compose.yml', '      MYSQL_ROOT_PASSWORD: Zq81mailboxpass4417', 'credential-literal'],
            'shell export' => ['deploy/run.sh', 'export DB_PASSWORD=Zq81mailboxpass4417', 'credential-literal'],
            'ini' => ['deploy/php.ini', 'smtp_password = Zq81mailboxpass4417', 'credential-literal'],
            'netrc' => ['.netrc', 'machine example.org login me password Zq81mailbox4417', 'credential-literal'],
            'credentials in a url' => ['app/Leaky.php', "\$dsn = 'mysql://root:" . "Zx81secret4417@localhost/shop';", 'url-credentials'],
            'a saudi mobile number' => ['app/Leaky.php', 'call 05' . '51234567', 'saudi-mobile-number'],
            'an international number' => ['app/Leaky.php', '+9665' . '51234567', 'saudi-mobile-number'],
            'a number with spaces' => ['app/Leaky.php', 'call +966 55 123 ' . '4567', 'saudi-mobile-number'],
            'a number with dashes' => ['app/Leaky.php', 'call 00966-55-123-' . '4567', 'saudi-mobile-number'],
            'a number in arabic digits' => ['app/Leaky.php', 'call ٠٥٥١٢٣' . '٤٥٦٧', 'saudi-mobile-number'],
            'a personal e-mail address' => ['app/Leaky.php', 'owner: someone' . '@gmail.com', 'email-address'],
            'a work e-mail address' => ['docs/team.md', 'write to omar.alharbi' . '@pharmacy.sa', 'email-address'],
            'a number in a json fixture' => ['tests/Fixtures/order.json', '{"phone": "05' . '51234567"}', 'saudi-mobile-number'],
            'an address in a vendored file is fine but a key there is not' => ['public/assets/lib.min.js', '-----BEGIN ' . 'PRIVATE KEY-----', 'private-key'],
        ];
    }

    #[DataProvider('secretLines')]
    public function test_a_secret_or_personal_detail_is_recognised_in_the_form_it_takes(string $path, string $line, string $rule): void
    {
        $this->assertContains($rule, (new Scanner($this->repo))->rulesBrokenBy($path, $line), "{$path}: {$line}");
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function harmlessLines(): array
    {
        return [
            'a validation rule named password' => ['app/Http/Requests/Login.php', "'password' => 'required|min:8|confirmed',"],
            'another rule' => ['app/Http/Requests/Login.php', "'current_password' => 'required|string',"],
            'an api key rule' => ['app/Http/Requests/Setting.php', "'api_key' => 'required|string|max:255',"],
            'a constant that names a route' => ['app/Http/Controllers/Auth.php', "const FORGET_PASSWORD = 'forgot-password';"],
            'a constant that names a key' => ['app/Services/Hooks.php', "const SECRET_KEY_NAME = 'webhook_secret_key';"],
            'reading a secret' => ['app/Services/Thing.php', "\$password = \$config->secret('webhook_secret');"],
            'a variable as the value' => ['app/Services/Thing.php', "['secret' => \$value]"],
            'a short value' => ['app/Services/Thing.php', "['token' => 'short']"],
            'a placeholder' => ['app/Services/Thing.php', "['password' => 'changeme-please-now']"],
            'a mask' => ['app/Services/Thing.php', "['password' => '••••••••••••']"],
            'an environment lookup' => ['config/mail.php', "'password' => env('MAIL_PASSWORD', 'secret-default-1234'),"],
            'an empty example value' => ['.env.example', 'MAIL_PASSWORD='],
            'a reference in yaml' => ['docker-compose.yml', '      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}'],
            'an image name' => ['resources/css/app.css', 'background: url(logo@2x.png)'],
            'an ssh remote' => ['docs/deploy.md', 'git clone git@github.com:owner/repo.git'],
            'a package version' => ['docs/deploy.md', '/node_modules/lodash@4.17.21: ok'],
            'a scoped package' => ['docs/deploy.md', '@babel/core@7.12.3'],
            'a method reference' => ['app/Services/Thing.php', "Route::get('/x', 'Controller@method');"],
            'a docblock' => ['app/Services/Thing.php', '     * @param string $email'],
            'a short number' => ['app/Services/Thing.php', 'const LIMIT = 12345;'],
            'a url with a placeholder password' => ['docs/deploy.md', 'mysql://root:pass@localhost/shop'],
            'a url with a short password' => ['app/Services/Thing.php', "'https://user:pw@example.com/a.png'"],
            'a made-up person in a php fixture' => ['tests/Feature/CheckoutTest.php', "\$phone = '0551234567'; \$email = 'sara@gmail.com'; \$password = 'fixture-password-1234';"],
            'words in a language file' => ['resources/lang/en/new-messages.php', "'phone_help' => 'Example: 0551234567', 'password_hint' => 'password: Choose-a-long-passphrase',"],
            'an example in the documentation' => ['docs/guide.md', "Use password = 'something-long-enough-1' in the form"],
            'an address in a lock file' => ['composer.lock', '"email": "someone@gmail.com"'],
            'a number in a vendored script' => ['public/assets/lib.min.js', 'var example = "0551234567";'],
        ];
    }

    #[DataProvider('harmlessLines')]
    public function test_ordinary_code_is_left_alone(string $path, string $line): void
    {
        $this->assertSame([], (new Scanner($this->repo))->rulesBrokenBy($path, $line), "{$path}: {$line}");
    }

    public function test_a_jwt_is_recognised(): void
    {
        $this->assertSame(['jwt'], (new Scanner($this->repo))->rulesBrokenBy('app/Token.php', "\$t = '" . $this->jwt() . "';"));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function forbiddenFiles(): array
    {
        return [
            'environment file' => ['.env', 'environment-file'],
            'environment file for production' => ['.env.production', 'environment-file'],
            'environment file in a folder' => ['deploy/.env.pilot', 'environment-file'],
            'a file named after its environment' => ['deploy/production.env', 'environment-file'],
            'the same, the other way round' => ['deploy/env.production', 'environment-file'],
            'direnv' => ['deploy/.envrc', 'environment-file'],
            'private key' => ['keys/server.pem', 'key-material'],
            'apple key' => ['public/assets/AuthKey_ABC123.p8', 'key-material'],
            'oauth key' => ['storage/oauth-private.key', 'key-material'],
            'ssh key' => ['keys/id_rsa', 'key-material'],
            'putty key' => ['keys/deploy.ppk', 'key-material'],
            'password vault' => ['vault.kdbx', 'key-material'],
            'vpn profile' => ['client.ovpn', 'key-material'],
            'gpg keyring' => ['secring.gpg', 'key-material'],
            'database dump' => ['backup/production.sql', 'database-dump'],
            'compressed dump' => ['db/prod.sql.gz', 'database-dump'],
            'zipped dump' => ['db/prod.sql.zip', 'database-dump'],
            'editor backup' => ['resources/lang/en/new-messages.php.bak', 'database-dump'],
            'sqlite file' => ['storage/shop.sqlite3', 'database-dump'],
            'database file' => ['data/shop.db', 'database-dump'],
            'zip' => ['site.zip', 'archive'],
            'tarball' => ['files.tgz', 'archive'],
            'compressed tarball' => ['files.tar.gz', 'archive'],
            'web server password file' => ['public/.htpasswd', 'credential-file'],
            'npm credentials' => ['deploy/.npmrc', 'credential-file'],
            'netrc' => ['.netrc', 'credential-file'],
            'composer credentials' => ['auth.json', 'credential-file'],
            'a cloud credentials file' => ['config/credentials.json', 'credential-file'],
            'verification evidence' => ['storage/app/fulfillment-evidence/m5azn/m5azn_20261105_100000_abcd1234.json', 'runtime-output'],
            'the overlay build' => ['build/overlay/MANIFEST.json', 'runtime-output'],
            'application log' => ['storage/logs/laravel.log', 'runtime-output'],
        ];
    }

    #[DataProvider('forbiddenFiles')]
    public function test_a_file_that_must_never_be_committed_is_refused_by_name_wherever_it_is(string $path, string $rule): void
    {
        $this->assertContains($rule, (new Scanner($this->repo))->rulesBrokenByName($path));
        $this->assertContains("{$path}:0 {$rule}", $this->findingsFor([$path => "harmless text\n"]));
    }

    public function test_the_example_environment_files_are_the_environment_files_allowed(): void
    {
        $this->assertSame([], $this->findingsFor(['.env.example' => "APP_NAME=Store\n", 'deploy/.env.example' => "A=1\n", 'deploy/.env.sample' => "A=1\n", 'config/environment.php' => "<?php\n"]));
    }

    public function test_every_name_rule_has_a_matching_gitignore_entry_so_the_file_never_reaches_a_commit_in_the_first_place(): void
    {
        $samples = ['.env.production', 'deploy/production.env', 'deploy/env.production', 'deploy/.envrc', 'keys/server.pem', 'keys/id_rsa', 'keys/deploy.ppk', 'vault.kdbx', 'client.ovpn', 'secring.gpg', 'backup/production.sql', 'db/prod.sql.gz', 'db/prod.sql.zip', 'storage/shop.sqlite3', 'data/shop.db', 'resources/lang/en/new-messages.php.bak', 'site.zip', 'files.tgz', 'files.tar.gz', 'public/.htpasswd', 'deploy/.npmrc', '.netrc', 'auth.json', 'config/credentials.json', 'storage/app/fulfillment-evidence/m5azn/run.json', 'build/overlay/MANIFEST.json', 'storage/logs/laravel.log', 'public/assets/AuthKey_ABC123.p8'];
        $ruleOfSample = [];
        foreach ($samples as $sample) {
            $ruleOfSample[$sample] = (new Scanner($this->repo))->rulesBrokenByName($sample);
            $this->assertNotSame([], $ruleOfSample[$sample], "{$sample} is a sample of a file the guard refuses");
        }
        $matched = array_values(array_unique(array_merge(...array_values($ruleOfSample))));
        $expected = array_keys(Scanner::fileRules());
        sort($matched);
        sort($expected);
        $this->assertSame($expected, $matched, 'every name rule is represented by at least one sample');

        $project = dirname(__DIR__, 3);
        foreach ($samples as $sample) {
            $ignored = new Process(['git', '-C', $project, 'check-ignore', '--no-index', '-q', '--', $sample]);
            $ignored->run();
            $this->assertSame(0, $ignored->getExitCode(), "{$sample} is refused by the guard but not ignored by .gitignore");
        }
    }

    // ── a change ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_an_ordinary_change_passes(): void
    {
        $findings = $this->findingsFor([
            'app/Services/Thing.php' => "<?php\n\$timeout = 30;\n\$password = \$request->input('password');\n\$url = env('M5AZN_URL', 'https://m5azn.com');\n",
            '.env.example' => "APP_KEY=\nDB_PASSWORD=\nMAIL_FROM_ADDRESS=hello@example.com\n",
            'docs/guide.md' => "Contact: noreply@anthropic.com\n",
        ]);

        $this->assertSame([], $findings);
    }

    public function test_a_secret_is_found_where_it_was_added_and_never_quoted(): void
    {
        $secret = 'Xk29-realistic-value-77';
        $this->commit(['app/Leaky.php' => "<?php\n// fine\n\$password = '{$secret}';\n// fine again\n"], 'change');

        $process = $this->runGuard(['--base=' . $this->base]);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('app/Leaky.php:3  credential-literal', $process->getOutput());
        $this->assertStringNotContainsString($secret, $process->getOutput() . $process->getErrorOutput());
    }

    public function test_real_secrets_are_refused_even_in_fixtures(): void
    {
        $findings = $this->findingsFor(['tests/Feature/LeakyFixtureTest.php' => "<?php\n// -----BEGIN " . "PRIVATE KEY-----\n"]);

        $this->assertSame(['tests/Feature/LeakyFixtureTest.php:2 private-key'], $findings);
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

    public function test_a_secret_added_and_removed_again_in_the_same_change_is_still_in_the_history_and_is_reported(): void
    {
        $this->commit(['app/Oops.php' => "<?php\n// -----BEGIN " . "PRIVATE KEY-----\n", '.env' => "A=1\n"], 'add it');
        $this->commit(['app/Oops.php' => "<?php\n// fixed\n"], 'remove the key');
        unlink($this->repo . '/.env');
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '-m', 'remove the file']);

        $findings = $this->findings();

        $this->assertContains('@app/Oops.php:2 private-key', $findings);
        $this->assertContains('@.env:0 environment-file', $findings);
        $this->assertNotContains('app/Oops.php:2 private-key', $findings, 'the net difference is clean; the history is not');
    }

    public function test_names_that_git_would_quote_do_not_hide_a_file_or_move_its_findings_to_another(): void
    {
        $arabic = 'مفاتيح';
        $findings = $this->findingsFor([
            'tests/AnyTest.php' => "<?php\n// fine\n",
            "{$arabic}/server.pem" => "harmless text\n",
            "{$arabic}/.env" => "A=1\n",
            "{$arabic}/Customer.php" => "<?php\n// -----BEGIN " . "PRIVATE KEY-----\n",
            'say "hi".php' => "<?php\n// -----BEGIN " . "PRIVATE KEY-----\n",
            'tab' . "\t" . 'name.pem' => "x\n",
        ]);

        $this->assertContains("{$arabic}/server.pem:0 key-material", $findings);
        $this->assertContains("{$arabic}/.env:0 environment-file", $findings);
        $this->assertContains("{$arabic}/Customer.php:2 private-key", $findings);
        $this->assertContains('say "hi".php:2 private-key', $findings);
        $this->assertContains('tab' . "\t" . 'name.pem:0 key-material', $findings);
        $this->assertSame([], preg_grep('#^tests/AnyTest\.php#', $findings), 'and nothing is blamed on the file that merely sorts first');
    }

    public function test_lines_that_start_with_plus_signs_keep_their_numbers_and_are_still_read(): void
    {
        $key = '-----BEGIN ' . 'PRIVATE KEY-----';
        $findings = $this->findingsFor([
            'app/Counter.php' => "<?php\n++\$a;\n++\$b;\n// {$key}\n",
            'notes.txt' => "++ write to omar" . "@pharmacy.sa\n",
        ]);

        $this->assertContains('app/Counter.php:4 private-key', $findings);
        $this->assertContains('notes.txt:1 email-address', $findings);

        $this->base = $this->revision();
        $this->assertSame(['app/Counter.php:6 private-key'], $this->findingsFor(['app/Counter.php' => "<?php\n++\$a;\n++\$b;\n// {$key}\n++\$c;\n// {$key}\n"]));
    }

    public function test_a_text_file_saved_as_utf16_is_read_and_an_unreadable_binary_is_reported(): void
    {
        $utf16 = "\xFF\xFE" . mb_convert_encoding("\$password = 'Xk29-realistic-value-77'; -----BEGIN " . 'PRIVATE KEY-----', 'UTF-16LE', 'UTF-8');
        $findings = $this->findingsFor([
            'config/notes.php' => $utf16,
            'data/blob.dat' => "\x00\x01\x02binary\x00",
            'public/images/logo.png' => "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR",
        ]);

        $this->assertContains('config/notes.php:1 private-key', $findings);
        $this->assertContains('data/blob.dat:0 binary-not-scanned', $findings);
        $this->assertSame([], preg_grep('#logo\.png#', $findings), 'an image needs no reading');
    }

    public function test_a_file_too_big_to_read_is_reported_instead_of_passed(): void
    {
        $this->commit(['data/huge.txt' => str_repeat("line of nothing\n", 600_000)], 'big');

        $this->assertSame(['data/huge.txt:0 oversized-not-scanned'], $this->findings());
    }

    public function test_the_guard_does_not_depend_on_the_users_git_configuration(): void
    {
        $this->commit(['app/Leaky.php' => "<?php\n// -----BEGIN " . "PRIVATE KEY-----\n"], 'change');

        $process = $this->runGuard(['--base=' . $this->base], ['GIT_CONFIG_COUNT' => '2', 'GIT_CONFIG_KEY_0' => 'diff.noprefix', 'GIT_CONFIG_VALUE_0' => 'true', 'GIT_CONFIG_KEY_1' => 'core.quotepath', 'GIT_CONFIG_VALUE_1' => 'true']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('app/Leaky.php:2  private-key', $process->getOutput());
    }

    public function test_a_merge_commit_as_head_is_scanned_like_the_pull_request_ref_github_checks_out(): void
    {
        $this->git(['checkout', '-q', '-b', 'feature']);
        $this->commit(['app/Feature.php' => "<?php\n// -----BEGIN " . "PRIVATE KEY-----\n"], 'feature work');
        $this->git(['checkout', '-q', 'main']);
        $this->commit(['app/Other.php' => "<?php\n// main moved on\n"], 'main moves');
        $this->git(['merge', '-q', '--no-ff', '-m', 'merge', 'feature']);

        $this->assertContains('app/Feature.php:2 private-key', $this->findings());
        $this->assertSame(1, $this->runGuard(['--base=' . $this->base])->getExitCode());
    }

    public function test_a_reasoned_exception_silences_a_false_alarm_and_an_unreasoned_one_does_not(): void
    {
        $this->commit(['docs/addresses.md' => "x\nWrite to help" . "@gmail.com\n"], 'change');

        file_put_contents($this->repo . '/allow.json', json_encode(['allow' => [['rule' => 'email-address', 'path' => 'docs/*.md']]]));
        $this->assertCount(1, (new Scanner($this->repo, $this->repo . '/allow.json'))->scan($this->base), 'no reason, no exception');

        file_put_contents($this->repo . '/allow.json', json_encode(['allow' => [['rule' => 'email-address', 'path' => 'docs/*.md', 'line' => 1, 'reason' => 'a different line']]]));
        $this->assertCount(1, (new Scanner($this->repo, $this->repo . '/allow.json'))->scan($this->base), 'an exception for one line does not silence another');

        file_put_contents($this->repo . '/allow.json', json_encode(['allow' => [['rule' => 'email-address', 'path' => 'docs/*.md', 'line' => 2, 'reason' => 'the shared support mailbox, not a person']]]));
        $this->assertSame([], (new Scanner($this->repo, $this->repo . '/allow.json'))->scan($this->base));
    }

    public function test_a_push_that_creates_a_branch_has_no_base_so_everything_reachable_is_read(): void
    {
        $this->commit(['app/Leaky.php' => "<?php\n// -----BEGIN " . "PRIVATE KEY-----\n"], 'one');
        $this->commit(['app/Other.php' => "<?php\n"], 'two');
        $this->commit(['app/Another.php' => "<?php\n"], 'three');

        $process = $this->runGuard(['--base=' . str_repeat('0', 40)]);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('app/Leaky.php:2  private-key', $process->getOutput());
    }

    public function test_a_clean_history_from_the_first_commit_passes_even_when_head_is_the_root_commit(): void
    {
        $root = new Process(['git', '-C', $this->repo, 'rev-list', '--max-parents=0', 'HEAD']);
        $root->mustRun();
        $this->git(['checkout', '-q', trim($root->getOutput())]);

        $this->assertSame(0, $this->runGuard(['--base=' . str_repeat('0', 40)])->getExitCode());
    }

    public function test_it_says_so_when_it_cannot_run_and_never_lets_a_revision_become_an_option(): void
    {
        $this->commit(['app/Leaky.php' => "<?php\n// -----BEGIN " . "PRIVATE KEY-----\n"], 'change');

        $this->assertSame(2, $this->runGuard(['--base=no-such-revision'])->getExitCode());
        $this->assertSame(2, $this->runGuard([])->getExitCode(), 'a base is required');

        $written = $this->repo . '/diff-written-by-git';
        $process = $this->runGuard(['--base=--output=' . $written]);
        $this->assertSame(2, $process->getExitCode());
        $this->assertFileDoesNotExist($written);
        $this->assertSame(2, $this->runGuard(['--base=' . $this->base, '--head=--stat'])->getExitCode());
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
