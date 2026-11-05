<?php

namespace Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The scripts that take the backup before a deployment, run against a small synthetic application with stand-ins for `mysql` and
 * `mysqldump`: what matters here is where the backup may be written, what a failure leaves behind and that the database
 * password is never shown, not MySQL.
 */
class BackupScriptsTest extends TestCase
{
    private const PASSWORD = 'PW-SENTINEL-do-not-print-7391';

    private string $work;

    private string $app;

    private string $stubs;

    protected function setUp(): void
    {
        parent::setUp();

        if ((new Process(['bash', '--version']))->run() !== 0) {
            $this->markTestSkipped('bash is not available.');
        }

        $this->work = sys_get_temp_dir() . '/backup-test-' . bin2hex(random_bytes(4));
        $this->app = $this->work . '/site/app';
        $this->stubs = $this->work . '/stubs';
        mkdir($this->app . '/storage', 0755, true);
        mkdir($this->stubs, 0755, true);
        file_put_contents($this->app . '/artisan', "<?php\n");
        file_put_contents($this->app . '/storage/upload.txt', 'a customer file');
        file_put_contents($this->app . '/.env', "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=pharmacy\nDB_USERNAME=shop\nDB_PASSWORD=" . self::PASSWORD . "\n");

        $this->stub('mysql', <<<'BASH'
            case "$*" in
              *"SELECT 1"*) [ -n "${STUB_DB_DOWN:-}" ] && exit 1; echo 1 ;;
              *"SUM(data_length"*) echo 100 ;;
              *"COUNT("*) echo 7 ;;
            esac
            BASH);
        $this->stub('mysqldump', <<<'BASH'
            if [ "${1:-}" = "--help" ]; then echo "--no-tablespaces --column-statistics --set-gtid-purged"; exit 0; fi
            printf -- '-- MySQL dump\n-- Dump completed on 2026-11-05 10:00:00\n'
            BASH);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->work]))->run();
        parent::tearDown();
    }

    public function test_a_full_backup_holds_the_database_and_the_files_and_never_shows_the_password(): void
    {
        $backup = $this->backup(['--to=' . $this->work . '/backups']);

        $this->assertSame(0, $backup->getExitCode(), $backup->getOutput() . $backup->getErrorOutput());
        $directory = $this->theBackupDirectory($this->work . '/backups');
        foreach (['db.sql.gz', 'files.tar.gz', 'row-counts.txt', 'SHA256SUMS', 'RESTORE.txt'] as $name) {
            $this->assertFileExists($directory . '/' . $name);
        }
        $this->assertStringNotContainsString(self::PASSWORD, $backup->getOutput() . $backup->getErrorOutput());
        $this->assertStringNotContainsString(self::PASSWORD, (string) file_get_contents($directory . '/RESTORE.txt'));
        $this->assertSame(0700, fileperms($directory) & 0777);
    }

    /** @return array<string, array{string, list<string>, list<string>}> */
    public static function partialBackups(): array
    {
        return [
            'only the database' => ['--db-only', ['db.sql.gz', 'row-counts.txt', 'SHA256SUMS'], ['files.tar.gz']],
            'only the files' => ['--files-only', ['files.tar.gz', 'SHA256SUMS'], ['db.sql.gz']],
        ];
    }

    /**
     * @dataProvider partialBackups
     *
     * @param list<string> $made
     * @param list<string> $notMade
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('partialBackups')]
    public function test_a_partial_backup_succeeds_and_says_so(string $flag, array $made, array $notMade): void
    {
        $backup = $this->backup(['--to=' . $this->work . '/backups', $flag]);

        $this->assertSame(0, $backup->getExitCode(), 'the runbook takes a database-only backup before it migrates: ' . $backup->getOutput() . $backup->getErrorOutput());
        $this->assertStringContainsString('Backup complete', $backup->getOutput());
        $this->assertStringContainsString('Keep it off GitHub', $backup->getOutput(), 'the closing reminder is printed');
        $directory = $this->theBackupDirectory($this->work . '/backups');
        array_map(fn (string $name) => $this->assertFileExists($directory . '/' . $name), $made);
        array_map(fn (string $name) => $this->assertFileDoesNotExist($directory . '/' . $name), $notMade);
    }

    public function test_asking_for_neither_the_database_nor_the_files_is_a_mistake_not_an_empty_backup(): void
    {
        $backup = $this->backup(['--to=' . $this->work . '/backups', '--db-only', '--files-only']);

        $this->assertSame(2, $backup->getExitCode());
        $this->assertStringContainsString('would back up nothing', $backup->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->work . '/backups');
    }

    /** @return array<string, array{string}> */
    public static function placesInsideTheApplication(): array
    {
        return [
            'an absolute path' => ['ABSOLUTE'],
            'a relative path' => ['./backups-in-the-app'],
            'a relative path that climbs out and back in' => ['../app/./storage/../storage/backup'],
        ];
    }

    /** @dataProvider placesInsideTheApplication */
    #[\PHPUnit\Framework\Attributes\DataProvider('placesInsideTheApplication')]
    public function test_a_backup_is_refused_inside_the_application_however_the_path_is_written(string $to): void
    {
        $to = $to === 'ABSOLUTE' ? $this->app . '/backups' : $to;

        $backup = $this->backup(['--to=' . $to, '--db-only'], cwd: $this->app);

        $this->assertSame(1, $backup->getExitCode(), $backup->getOutput());
        $this->assertStringContainsString('inside the application tree', $backup->getErrorOutput());
        $this->assertSame([], glob($this->app . '/*backup*') ?: [], 'nothing was written into the application');
        $this->assertDirectoryDoesNotExist($this->app . '/storage/backup');
    }

    public function test_a_backup_is_refused_through_a_link_that_points_into_the_application(): void
    {
        symlink($this->app, $this->work . '/shortcut');

        $backup = $this->backup(['--to=' . $this->work . '/shortcut/backups', '--db-only']);

        $this->assertSame(1, $backup->getExitCode());
        $this->assertStringContainsString('inside the application tree', $backup->getErrorOutput());
    }

    public function test_when_the_database_does_not_answer_no_backup_directory_is_left_behind(): void
    {
        $backup = $this->backup(['--to=' . $this->work . '/backups'], environment: ['STUB_DB_DOWN' => '1']);

        $this->assertSame(1, $backup->getExitCode());
        $this->assertStringContainsString('cannot connect to database', $backup->getErrorOutput());
        $this->assertSame([], glob($this->work . '/backups/*') ?: [], 'an empty, dated directory would look like a backup');
    }

    public function test_a_damaged_backup_is_detected_by_its_checksum(): void
    {
        $this->backup(['--to=' . $this->work . '/backups']);
        $directory = $this->theBackupDirectory($this->work . '/backups');
        file_put_contents($directory . '/db.sql.gz', 'damaged');

        $verify = $this->execute(['bash', dirname(__DIR__, 3) . '/scripts/deploy/verify-backup.sh', '--backup=' . $directory, '--app=' . $this->app]);

        $this->assertSame(1, $verify->getExitCode());
        $this->assertStringContainsString('the backup is damaged', $verify->getErrorOutput());
    }

    public function test_the_help_texts_stop_before_the_first_command(): void
    {
        foreach (['backup.sh', 'verify-backup.sh'] as $script) {
            $help = $this->execute(['bash', dirname(__DIR__, 3) . '/scripts/deploy/' . $script, '--help']);

            $this->assertSame(0, $help->getExitCode());
            $this->assertStringNotContainsString('set -euo pipefail', $help->getOutput(), $script);
            $this->assertStringContainsString('bash ', $help->getOutput(), "{$script} is run with bash from the package");
        }
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     */
    private function backup(array $arguments, ?string $cwd = null, array $environment = []): Process
    {
        return $this->execute(['bash', dirname(__DIR__, 3) . '/scripts/deploy/backup.sh', '--app=' . $this->app, ...$arguments], $cwd, $environment);
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     */
    private function execute(array $command, ?string $cwd = null, array $environment = []): Process
    {
        $process = new Process($command, $cwd, ['PATH' => $this->stubs . ':' . getenv('PATH')] + $environment);
        $process->run();

        return $process;
    }

    private function stub(string $name, string $body): void
    {
        file_put_contents($this->stubs . '/' . $name, "#!/usr/bin/env bash\n" . $body . "\n");
        chmod($this->stubs . '/' . $name, 0755);
    }

    private function theBackupDirectory(string $root): string
    {
        $directories = glob($root . '/*', GLOB_ONLYDIR) ?: [];
        $this->assertCount(1, $directories, 'exactly one dated backup directory');

        return $directories[0];
    }
}
