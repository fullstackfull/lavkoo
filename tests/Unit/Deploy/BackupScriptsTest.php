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
            [ -n "${STUB_LOG:-}" ] && echo "mysql $*" >> "$STUB_LOG"
            # A real client reads all of a dump piped to it; one that exits early would make the writer fail with a broken pipe.
            case "$*" in *" -e "*) ;; *) cat >/dev/null ;; esac
            case "$*" in
              *"SELECT 1"*) [ -n "${STUB_DB_DOWN:-}" ] && exit 1; echo 1 ;;
              *"SUM(data_length"*) echo 100 ;;
              *"@@datadir"*) echo "${STUB_DATADIR:-/nonexistent}" ;;
              *"information_schema.events"*) echo "${STUB_EVENTS:-0}" ;;
              *"COUNT("*) echo 7 ;;
            esac
            BASH);
        $this->stub('mysqldump', <<<'BASH'
            if [ "${1:-}" = "--help" ]; then echo "--no-tablespaces --column-statistics --set-gtid-purged"; exit 0; fi
            case "${STUB_DUMP:-}" in
              fails) printf -- '-- MySQL dump\n-- a partial table\n'; exit 2 ;;
              truncated) printf -- '-- MySQL dump\n-- a partial table\n'; exit 0 ;;
            esac
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

    public function test_a_backup_directory_that_was_already_there_keeps_its_mode_and_only_the_new_backup_is_made_private(): void
    {
        mkdir($this->work . '/shared-backups', 0755);

        $backup = $this->backup(['--to=' . $this->work . '/shared-backups', '--db-only']);

        $this->assertSame(0, $backup->getExitCode(), $backup->getOutput() . $backup->getErrorOutput());
        clearstatcache();
        $this->assertSame(0755, fileperms($this->work . '/shared-backups') & 0777, 'a shared directory is not ours to lock');
        $this->assertSame(0700, fileperms($this->theBackupDirectory($this->work . '/shared-backups')) & 0777);
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

    /** @return array<string, array{string}> */
    public static function dumpsThatGoWrong(): array
    {
        return ['a dump that fails half-way' => ['fails'], 'a dump that is cut short without an error' => ['truncated']];
    }

    /** @dataProvider dumpsThatGoWrong */
    #[\PHPUnit\Framework\Attributes\DataProvider('dumpsThatGoWrong')]
    public function test_a_backup_that_does_not_finish_leaves_nothing_partial_beside_a_real_one(string $failure): void
    {
        $backup = $this->backup(['--to=' . $this->work . '/backups', '--db-only'], environment: ['STUB_DUMP' => $failure]);

        $this->assertSame(1, $backup->getExitCode(), $backup->getOutput() . $backup->getErrorOutput());
        $this->assertSame([], glob($this->work . '/backups/*') ?: [], 'a truncated db.sql.gz next to a real backup name would be mistaken for a backup');
        $this->assertStringContainsString('was removed', $backup->getErrorOutput());
    }

    public function test_a_second_backup_in_the_same_second_never_removes_the_first(): void
    {
        $first = $this->backup(['--to=' . $this->work . '/backups', '--db-only']);
        $directory = $this->theBackupDirectory($this->work . '/backups');

        $clash = $this->execute(['bash', '-c', 'date() { echo ' . basename($directory) . '; }; export -f date; bash "$@"', 'backup', dirname(__DIR__, 3) . '/scripts/deploy/backup.sh', '--app=' . $this->app, '--to=' . $this->work . '/backups', '--db-only']);

        $this->assertSame(0, $first->getExitCode());
        $this->assertSame(1, $clash->getExitCode(), $clash->getOutput() . $clash->getErrorOutput());
        $this->assertStringContainsString('already exists', $clash->getErrorOutput());
        $this->assertFileExists($directory . '/db.sql.gz', 'the backup that was already there is untouched');
        $this->assertStringNotContainsString('was removed', $clash->getErrorOutput());
    }

    public function test_a_tree_du_cannot_fully_read_does_not_stop_the_backup_silently(): void
    {
        $real = trim((string) shell_exec('command -v du'));
        $this->stub('du', 'case "$1" in -sk) exit 1 ;; *) exec ' . escapeshellarg($real) . ' "$@" ;; esac');

        $backup = $this->backup(['--to=' . $this->work . '/backups']);

        $this->assertSame(0, $backup->getExitCode(), 'the size is only an estimate: ' . $backup->getOutput() . $backup->getErrorOutput());
        $this->assertStringContainsString('Backup complete', $backup->getOutput());
    }

    public function test_a_failure_nobody_planned_for_still_says_where_it_happened(): void
    {
        $this->stub('tar', 'exit 7');

        $backup = $this->backup(['--to=' . $this->work . '/backups', '--files-only']);

        $this->assertSame(1, $backup->getExitCode());
        $this->assertNotSame('', trim($backup->getErrorOutput()), 'a script that stops must say why');
    }

    public function test_without_gnu_realpath_a_destination_that_climbs_back_into_the_application_is_refused(): void
    {
        $this->stub('realpath', 'exit 1');

        $climbing = $this->backup(['--to=' . $this->app . '/../zz/../app/x', '--db-only']);
        $honest = $this->backup(['--to=' . $this->work . '/backups', '--db-only']);

        $this->assertSame(2, $climbing->getExitCode(), $climbing->getOutput() . $climbing->getErrorOutput());
        $this->assertStringContainsString("cannot resolve '..'", $climbing->getErrorOutput());
        $this->assertStringNotContainsString('stopped unexpectedly', $climbing->getErrorOutput(), 'a refusal that was meant is not also reported as a crash');
        $this->assertDirectoryDoesNotExist($this->app . '/x', 'nothing was written into the application');
        $this->assertSame(0, $honest->getExitCode(), 'a plain destination still works without GNU realpath: ' . $honest->getOutput() . $honest->getErrorOutput());
    }

    public function test_a_destination_that_cannot_be_written_is_not_reported_as_a_backup_that_already_exists(): void
    {
        $real = trim((string) shell_exec('command -v mkdir'));
        $this->stub('mkdir', 'if [ "${1:-}" = "-p" ]; then exec ' . escapeshellarg($real) . ' "$@"; fi; echo "mkdir: cannot create directory: Read-only file system" >&2; exit 1');

        $backup = $this->backup(['--to=' . $this->work . '/backups']);

        $this->assertSame(1, $backup->getExitCode(), $backup->getOutput() . $backup->getErrorOutput());
        $this->assertStringContainsString('could not create', $backup->getErrorOutput());
        $this->assertStringNotContainsString('already exists', $backup->getErrorOutput());
    }

    public function test_a_step_nobody_planned_for_is_reported_with_the_line_it_stopped_at(): void
    {
        $this->stub('mktemp', 'exit 1');

        $backup = $this->backup(['--to=' . $this->work . '/backups']);

        $this->assertSame(1, $backup->getExitCode());
        $this->assertStringContainsString('stopped unexpectedly at line', $backup->getErrorOutput());
    }

    public function test_a_disk_whose_free_space_cannot_be_read_stops_the_backup_unless_the_check_is_skipped_on_purpose(): void
    {
        $this->stub('df', 'printf "Filesystem 1024-blocks Used Available Capacity Mounted\n"');

        $refused = $this->backup(['--to=' . $this->work . '/backups']);
        $skipped = $this->backup(['--to=' . $this->work . '/backups', '--force-low-disk']);

        $this->assertSame(1, $refused->getExitCode(), $refused->getOutput() . $refused->getErrorOutput());
        $this->assertStringContainsString('cannot read the free space', $refused->getErrorOutput());
        $this->assertSame(0, $skipped->getExitCode(), $skipped->getOutput() . $skipped->getErrorOutput());
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

    public function test_the_default_check_of_a_backup_only_reads_it_and_says_that_it_is_not_a_restore_test(): void
    {
        $this->backup(['--to=' . $this->work . '/backups']);
        $directory = $this->theBackupDirectory($this->work . '/backups');
        $log = $this->work . '/mysql.log';

        $verify = $this->execute(['bash', dirname(__DIR__, 3) . '/scripts/deploy/verify-backup.sh', '--backup=' . $directory, '--app=' . $this->app], environment: ['STUB_LOG' => $log]);

        $this->assertSame(0, $verify->getExitCode(), $verify->getErrorOutput());
        $this->assertStringContainsString('NOT a restore test', $verify->getOutput());
        $this->assertFileDoesNotExist($log, 'the live database server was not asked anything');
    }

    public function test_the_default_check_finds_a_dump_that_was_cut_short(): void
    {
        $this->backup(['--to=' . $this->work . '/backups', '--db-only']);
        $directory = $this->theBackupDirectory($this->work . '/backups');
        $this->execute(['bash', '-c', 'printf "%s\n" "-- MySQL dump" "-- but no completion line" | gzip > "$0/db.sql.gz" && cd "$0" && sha256sum db.sql.gz row-counts.txt > SHA256SUMS', $directory]);

        $verify = $this->execute(['bash', dirname(__DIR__, 3) . '/scripts/deploy/verify-backup.sh', '--backup=' . $directory, '--app=' . $this->app]);

        $this->assertSame(1, $verify->getExitCode(), $verify->getOutput());
        $this->assertStringContainsString('truncated', $verify->getErrorOutput());
    }

    public function test_a_restore_test_refuses_when_the_datas_disk_cannot_hold_a_second_copy_and_when_events_would_run_in_the_scratch_database(): void
    {
        $this->backup(['--to=' . $this->work . '/backups']);
        $directory = $this->theBackupDirectory($this->work . '/backups');
        mkdir($this->work . '/datadir');
        $this->stub('df', 'printf "Filesystem 1024-blocks Used Available Capacity Mounted\n/dev/x 10 9 1 90%% /\n"');
        $verify = fn (array $extra, array $environment = []): Process => $this->execute(['bash', dirname(__DIR__, 3) . '/scripts/deploy/verify-backup.sh', '--backup=' . $directory, '--app=' . $this->app, '--restore-test', ...$extra], environment: ['STUB_DATADIR' => $this->work . '/datadir'] + $environment);

        $noRoom = $verify([]);
        $this->assertSame(1, $noRoom->getExitCode(), $noRoom->getOutput() . $noRoom->getErrorOutput());
        $this->assertStringContainsString("free in MySQL's data directory", $noRoom->getErrorOutput());
        $this->assertStringNotContainsString('Loading the dump', $noRoom->getOutput(), 'nothing was loaded into the live server');

        $events = $verify(['--force-low-disk'], ['STUB_EVENTS' => '2']);
        $this->assertSame(1, $events->getExitCode(), $events->getOutput() . $events->getErrorOutput());
        $this->assertStringContainsString('scheduled event', $events->getErrorOutput());
        $this->assertStringNotContainsString('Loading the dump', $events->getOutput());

        $allowed = $verify(['--force-low-disk', '--allow-events'], ['STUB_EVENTS' => '2']);
        $this->assertSame(0, $allowed->getExitCode(), $allowed->getOutput() . $allowed->getErrorOutput());
        $this->assertStringContainsString('The backup restores, and its row counts match.', $allowed->getOutput());
    }

    public function test_a_restore_test_that_cannot_read_the_free_space_refuses_unless_the_check_is_skipped_on_purpose(): void
    {
        $this->backup(['--to=' . $this->work . '/backups']);
        $directory = $this->theBackupDirectory($this->work . '/backups');
        mkdir($this->work . '/datadir');
        $this->stub('df', 'printf "Filesystem 1024-blocks Used Available Capacity Mounted\n"');
        $verify = fn (array $extra): Process => $this->execute(['bash', dirname(__DIR__, 3) . '/scripts/deploy/verify-backup.sh', '--backup=' . $directory, '--app=' . $this->app, '--restore-test', ...$extra], environment: ['STUB_DATADIR' => $this->work . '/datadir']);

        $refused = $verify([]);
        $skipped = $verify(['--force-low-disk']);

        $this->assertSame(1, $refused->getExitCode(), $refused->getOutput() . $refused->getErrorOutput());
        $this->assertStringContainsString('cannot read the free space', $refused->getErrorOutput());
        $this->assertStringNotContainsString('Loading the dump', $refused->getOutput(), 'nothing was loaded into the live server');
        $this->assertSame(0, $skipped->getExitCode(), $skipped->getOutput() . $skipped->getErrorOutput());
    }

    public function test_a_restore_test_does_not_measure_this_machines_disk_for_a_database_that_runs_on_another_host(): void
    {
        file_put_contents($this->app . '/.env', str_replace('DB_HOST=127.0.0.1', 'DB_HOST=db.internal.test', (string) file_get_contents($this->app . '/.env')));
        $this->backup(['--to=' . $this->work . '/backups']);
        $directory = $this->theBackupDirectory($this->work . '/backups');
        mkdir($this->work . '/datadir');
        $this->stub('df', 'printf "Filesystem 1024-blocks Used Available Capacity Mounted\n/dev/x 10 9 1 90%% /\n"');

        $verify = $this->execute(['bash', dirname(__DIR__, 3) . '/scripts/deploy/verify-backup.sh', '--backup=' . $directory, '--app=' . $this->app, '--restore-test'], environment: ['STUB_DATADIR' => $this->work . '/datadir']);

        $this->assertSame(0, $verify->getExitCode(), $verify->getOutput() . $verify->getErrorOutput());
        $this->assertStringContainsString('MySQL runs on db.internal.test, not on this machine', $verify->getOutput());
        $this->assertStringNotContainsString("free in MySQL's data directory", $verify->getErrorOutput());
    }

    public function test_the_help_texts_stop_before_the_first_command(): void
    {
        foreach (['backup.sh', 'verify-backup.sh'] as $script) {
            $help = $this->execute(['bash', dirname(__DIR__, 3) . '/scripts/deploy/' . $script, '--help']);

            $this->assertSame(0, $help->getExitCode());
            $this->assertStringNotContainsString('set -', $help->getOutput(), $script);
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
