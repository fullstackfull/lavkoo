<?php

namespace Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

/**
 * The tool that changes the live server: building the overlay from two revisions, checking it against a tree that may have
 * drifted, applying it with a backup outside the tree, merging (never overwriting) the language files the application rewrites
 * itself, and taking all of it back. Exercised end to end on a small synthetic repository and a synthetic "live" tree.
 */
class OverlayDeploymentTest extends TestCase
{
    private string $work;

    private string $repo;

    private string $live;

    private string $overlay;

    private string $backups;

    /** @var array<string, string|false> */
    private array $environment = [];

    /** @var array<string, string> the baseline files */
    private const BASE = [
        'artisan' => "<?php\n// artisan\n",
        'bootstrap/app.php' => "<?php\nreturn 'base app';\n",
        'app/Utils/OrderManager.php' => "<?php\nfunction order() { return 'base'; }\n",
        'app/Services/Unchanged.php' => "<?php\nclass Unchanged {}\n",
        'config/queue.php' => "<?php\nreturn ['retry_after' => 90];\n",
        'resources/lang/en/new-messages.php' => "<?php\n\nreturn [\n\t'old_key' => 'Old text',\n\t'edited_key' => 'Edited key',\n\t'placeholder_key' => 'Placeholder key',\n];\n",
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if ((new Process(['git', '--version']))->run() !== 0) {
            $this->markTestSkipped('git is not available.');
        }

        $this->work = sys_get_temp_dir() . '/overlay-test-' . bin2hex(random_bytes(4));
        $this->repo = $this->work . '/repo';
        $this->live = $this->work . '/live/app';
        $this->overlay = $this->work . '/overlay';
        $this->backups = $this->work . '/live/backups';
        mkdir($this->repo, 0755, true);
        mkdir($this->live, 0755, true);

        // The developer's own git configuration (signing, hooks) must not decide whether these tests pass.
        foreach (['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'] as $name => $value) {
            $this->environment[$name] = getenv($name);
            putenv("{$name}={$value}");
        }
        $this->git(['init', '-q', '-b', 'main']);
        foreach ([['user.email', 'test@example.test'], ['user.name', 'Test'], ['commit.gpgsign', 'false'], ['core.hooksPath', '/dev/null']] as [$key, $value]) {
            $this->git(['config', $key, $value]);
        }
        $this->commit(self::BASE, 'base');
        foreach (self::BASE as $path => $content) {
            $this->put($this->live . '/' . $path, $content);
        }
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->work]))->run();
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        parent::tearDown();
    }

    // ── building ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_overlay_holds_exactly_the_added_and_modified_files_that_run_on_the_server(): void
    {
        $this->release();

        $manifest = $this->manifest();
        $byPath = array_column($manifest['files'], null, 'path');

        $this->assertSame(['app/Services/Fulfillment/New.php', 'app/Utils/OrderManager.php', 'database/migrations/2026_11_05_100000_create_thing.php', 'resources/lang/en/new-messages.php'], array_keys($byPath));
        $this->assertSame('M', $byPath['app/Utils/OrderManager.php']['status']);
        $this->assertSame(hash('sha256', self::BASE['app/Utils/OrderManager.php']), $byPath['app/Utils/OrderManager.php']['base_sha256'], 'the baseline checksum is what the live file is compared with');
        $this->assertSame('A', $byPath['app/Services/Fulfillment/New.php']['status']);
        $this->assertArrayNotHasKey('base_sha256', $byPath['app/Services/Fulfillment/New.php']);
        $this->assertSame('merge_language_keys', $byPath['resources/lang/en/new-messages.php']['strategy']);
        $this->assertSame('replace', $byPath['app/Utils/OrderManager.php']['strategy']);
        $this->assertSame(['database/migrations/2026_11_05_100000_create_thing.php'], $manifest['migrations']);
        $this->assertFileDoesNotExist($this->overlay . '/files/docs/guide.md', 'documentation is not deployed');
        $this->assertFileDoesNotExist($this->overlay . '/files/tests/ThingTest.php', 'tests are not deployed');
        $this->assertFileExists($this->overlay . '/deploy.php');
        $this->assertFileExists($this->overlay . '/SHA256SUMS');
    }

    public function test_the_package_keeps_each_modified_files_baseline_and_the_backup_tools_apart_from_what_is_installed(): void
    {
        $this->release();

        $this->assertSame(self::BASE['app/Utils/OrderManager.php'], file_get_contents($this->overlay . '/base/app/Utils/OrderManager.php'), 'what the server changed can be seen against this');
        $this->assertFileDoesNotExist($this->overlay . '/base/app/Services/Fulfillment/New.php', 'a file the release adds has no baseline');
        $sums = (string) file_get_contents($this->overlay . '/SHA256SUMS');
        foreach (['backup.sh', 'verify-backup.sh', 'mysql-defaults.php'] as $tool) {
            $this->assertFileEquals(dirname(__DIR__, 3) . '/scripts/deploy/' . $tool, $this->overlay . '/tools/' . $tool);
            $this->assertStringContainsString('  tools/' . $tool, $sums);
        }
        $this->assertStringContainsString('  base/app/Utils/OrderManager.php', $sums);
        $installed = array_column($this->manifest()['files'], 'path');
        $this->assertSame([], array_values(array_filter($installed, static fn (string $path): bool => str_starts_with($path, 'tools/') || str_starts_with($path, 'base/'))), 'neither is ever installed');
    }

    public function test_the_difference_a_hotfix_made_can_be_read_against_the_packaged_baseline(): void
    {
        $this->release();
        $this->put($this->live . '/app/Utils/OrderManager.php', "<?php\nfunction order() { return 'hotfixed on the server'; }\n");

        $diff = new Process(['diff', '-u', $this->overlay . '/base/app/Utils/OrderManager.php', $this->live . '/app/Utils/OrderManager.php']);
        $diff->run();

        $this->assertSame(1, $diff->getExitCode());
        $this->assertStringContainsString("-function order() { return 'base'; }", $diff->getOutput());
        $this->assertStringContainsString("+function order() { return 'hotfixed on the server'; }", $diff->getOutput(), 'only what the server changed, not the release\'s own changes');
    }

    public function test_a_build_into_a_directory_that_already_holds_files_is_refused(): void
    {
        $this->release();
        $sums = (string) file_get_contents($this->overlay . '/SHA256SUMS');
        file_put_contents($this->overlay . '/files/app/Stale.php', "<?php\n");

        $again = $this->build();

        $this->assertSame(1, $again->getExitCode());
        $this->assertStringContainsString('already holds files', $again->getErrorOutput());
        $this->assertSame($sums, file_get_contents($this->overlay . '/SHA256SUMS'), 'nothing of the earlier package was touched either');
    }

    public function test_a_symbolic_link_or_a_change_of_file_mode_alone_is_refused_rather_than_installed_wrongly(): void
    {
        symlink('../../etc/passwd', $this->repo . '/app/Link.php');
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '-m', 'a link']);
        $link = $this->build();

        $this->assertSame(1, $link->getExitCode());
        $this->assertStringContainsString('app/Link.php is a symbolic link', $link->getErrorOutput());

        $this->git(['reset', '-q', '--hard', 'HEAD~1']);
        chmod($this->repo . '/app/Utils/OrderManager.php', 0755);
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '-m', 'a mode']);
        $mode = $this->build();

        $this->assertSame(1, $mode->getExitCode());
        $this->assertStringContainsString('app/Utils/OrderManager.php changes only its file mode', $mode->getErrorOutput());
    }

    public function test_a_file_that_changes_its_mode_and_its_content_is_refused_because_the_server_would_keep_the_old_mode(): void
    {
        file_put_contents($this->repo . '/app/Utils/OrderManager.php', "<?php\nfunction order() { return 'changed and made executable'; }\n");
        chmod($this->repo . '/app/Utils/OrderManager.php', 0755);
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '-m', 'content and mode']);

        $build = $this->build();

        $this->assertSame(1, $build->getExitCode());
        $this->assertStringContainsString('app/Utils/OrderManager.php changes its file mode as well as its content', $build->getErrorOutput());
        $this->assertFileDoesNotExist($this->overlay . '/MANIFEST.json');
    }

    public function test_a_file_name_a_checksum_list_cannot_carry_is_refused(): void
    {
        $this->commit(["app/odd\nname.php" => "<?php\n", 'app/back\\slash.php' => "<?php\n"], 'odd names');

        $build = $this->build();

        $this->assertSame(1, $build->getExitCode());
        $this->assertStringContainsString('has a control character or a backslash in its name', $build->getErrorOutput());
        $this->assertSame(2, substr_count($build->getErrorOutput(), 'has a control character or a backslash'), 'both names are reported');
    }

    public function test_a_package_never_carries_scripts_that_differ_from_the_ones_in_the_release(): void
    {
        $scripts = dirname(__DIR__, 3) . '/scripts/deploy';
        $this->commit(['scripts/deploy/deploy.php' => "<?php\n// a different deploy.php, committed with the release\n", 'app/Utils/OrderManager.php' => "<?php\nfunction order() { return 'release'; }\n"], 'release with its own deploy.php');

        $different = $this->build();

        $this->assertSame(1, $different->getExitCode());
        $this->assertStringContainsString('would carry deploy.php from the checkout this builder runs from', $different->getErrorOutput());
        $this->assertFileDoesNotExist($this->overlay . '/deploy.php');

        $this->commit(['scripts/deploy/deploy.php' => (string) file_get_contents($scripts . '/deploy.php'), 'app/Utils/OrderManager.php' => "<?php\nfunction order() { return 'second release'; }\n"], 'the same deploy.php');
        $same = $this->build();

        $this->assertSame(0, $same->getExitCode(), $same->getErrorOutput());
    }

    public function test_the_archive_comes_with_the_checksum_to_check_the_download_against(): void
    {
        if (!class_exists(\PharData::class)) {
            $this->markTestSkipped('The phar extension is not available.');
        }
        $this->release(archive: true);

        $check = new Process(['sha256sum', '-c', 'overlay.tar.gz.sha256'], $this->work);
        $check->run();

        $this->assertSame(0, $check->getExitCode(), $check->getOutput() . $check->getErrorOutput());
        $this->assertStringContainsString('overlay.tar.gz: OK', $check->getOutput());
    }

    /** @return array<string, array{array<string, string|null>, string}> */
    public static function releasesAnOverlayCannotDeploy(): array
    {
        return [
            'a deleted file' => [['app/Services/Unchanged.php' => null], 'is deleted by the release'],
            'a changed dependency list' => [['composer.json' => '{"require": {}}'], 'needs `composer install`'],
            'a changed public asset' => [['public/js/app.js' => 'x'], 'outside app, bootstrap, config, database, resources, routes'],
            'a changed module' => [['Modules/Gateways/x.php' => '<?php'], 'Deploy it by hand'],
        ];
    }

    /** @dataProvider releasesAnOverlayCannotDeploy */
    #[\PHPUnit\Framework\Attributes\DataProvider('releasesAnOverlayCannotDeploy')]
    public function test_a_release_an_overlay_cannot_deploy_is_refused_by_name(array $changes, string $reason): void
    {
        $this->commit($changes, 'release');

        $process = $this->build();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString($reason, $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->overlay . '/MANIFEST.json', 'nothing is produced for a release that cannot be deployed');
    }

    // ── verifying ────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_tree_identical_to_the_baseline_can_be_deployed(): void
    {
        $this->release();

        $report = $this->verify();

        $this->assertTrue($report['ok']);
        $this->assertSame(['CLEAN' => 1, 'MERGE' => 1, 'NEW' => 2], $report['counts']);
    }

    public function test_a_file_that_drifted_from_the_baseline_stops_the_deployment_and_changes_nothing(): void
    {
        $this->release();
        $this->put($this->live . '/app/Utils/OrderManager.php', "<?php\nfunction order() { return 'hotfixed on the server'; }\n");
        $before = $this->treeHash($this->live);

        $verify = $this->deploy('verify', ['--json']);
        $apply = $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);

        $report = json_decode($verify->getOutput(), true);
        $this->assertSame(1, $verify->getExitCode());
        $this->assertFalse($report['ok']);
        $this->assertCount(1, $report['problems']);
        $this->assertStringStartsWith('app/Utils/OrderManager.php: DRIFTED. Differs from the baseline the release was built against (a hotfix or a runtime rewrite).', $report['problems'][0]);
        $this->assertStringContainsString('diff -u ' . $this->overlay . '/base/app/Utils/OrderManager.php ' . $this->live . '/app/Utils/OrderManager.php', $report['problems'][0], 'the person is told how to see what the server changed');
        $this->assertSame(1, $apply->getExitCode());
        $this->assertStringContainsString('Refusing to apply', $apply->getOutput());
        $this->assertSame($before, $this->treeHash($this->live), 'a refused deployment writes nothing');
        $this->assertDirectoryDoesNotExist($this->backups);
    }

    public function test_a_person_can_accept_a_drifted_file_by_name_and_the_live_version_is_backed_up(): void
    {
        $this->release();
        $hotfix = "<?php\nfunction order() { return 'hotfixed on the server'; }\n";
        $this->put($this->live . '/app/Utils/OrderManager.php', $hotfix);

        $apply = $this->deploy('apply', ['--yes', '--accept-drift=app/Utils/OrderManager.php', '--backup-dir=' . $this->backups]);

        $this->assertSame(0, $apply->getExitCode(), $apply->getOutput());
        $this->assertStringContainsString("'release'", (string) file_get_contents($this->live . '/app/Utils/OrderManager.php'));
        $this->assertSame($hotfix, file_get_contents($this->backups . '/files/app/Utils/OrderManager.php'), 'what was on the server is kept');
    }

    public function test_a_file_missing_from_the_server_means_this_is_not_the_code_the_release_was_built_against(): void
    {
        $this->release();
        unlink($this->live . '/app/Utils/OrderManager.php');

        $report = $this->verify(expect: 1);

        $this->assertStringContainsString('app/Utils/OrderManager.php: MISSING', $report['problems'][0]);
    }

    public function test_a_new_file_that_already_exists_with_other_content_is_a_conflict(): void
    {
        $this->release();
        $this->put($this->live . '/app/Services/Fulfillment/New.php', "<?php\n// something else\n");

        $report = $this->verify(expect: 1);

        $this->assertStringContainsString('app/Services/Fulfillment/New.php: CONFLICT', $report['problems'][0]);
    }

    public function test_a_damaged_upload_is_detected_before_anything_is_looked_at(): void
    {
        $this->release();
        file_put_contents($this->overlay . '/files/app/Services/Fulfillment/New.php', "<?php\n// truncated");

        $process = $this->deploy('verify');

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('The package is damaged', $process->getOutput());
    }

    public function test_a_package_cannot_name_a_path_it_may_not_touch(): void
    {
        $this->release();
        $genuine = $this->manifest();
        foreach (['../outside.php', '.env', 'storage/logs/laravel.log', 'vendor/autoload.php', '/etc/passwd', 'public/index.php', 'app/../.env'] as $path) {
            $manifest = $genuine;
            $manifest['files'][] = ['path' => $path, 'status' => 'A', 'strategy' => 'replace', 'mode' => '0644', 'new_sha256' => str_repeat('0', 64)];
            $this->writeManifest($manifest);

            $process = $this->deploy('verify');

            $this->assertSame(2, $process->getExitCode(), $path);
            $this->assertStringContainsString("may not touch: '{$path}'", $process->getOutput(), $path);
        }
    }

    public function test_it_will_not_write_through_a_symbolic_link(): void
    {
        $this->release();
        unlink($this->live . '/app/Utils/OrderManager.php');
        file_put_contents($this->work . '/elsewhere.php', "<?php\n");
        symlink($this->work . '/elsewhere.php', $this->live . '/app/Utils/OrderManager.php');

        $report = $this->verify(expect: 1);

        $this->assertStringContainsString('SYMLINK', $report['problems'][0]);
    }

    // ── applying ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_plain_apply_is_a_dry_run(): void
    {
        $this->release();
        $before = $this->treeHash($this->live);

        $process = $this->deploy('apply');

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringContainsString('Dry run: nothing was changed', $process->getOutput());
        $this->assertSame($before, $this->treeHash($this->live));
    }

    public function test_applying_replaces_adds_and_merges_and_backs_up_outside_the_tree(): void
    {
        $this->release();

        $apply = $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);

        $this->assertSame(0, $apply->getExitCode(), $apply->getOutput());
        $this->assertStringContainsString("'release'", (string) file_get_contents($this->live . '/app/Utils/OrderManager.php'));
        $this->assertFileExists($this->live . '/app/Services/Fulfillment/New.php');
        $this->assertFileExists($this->live . '/database/migrations/2026_11_05_100000_create_thing.php');
        $this->assertSame(self::BASE['app/Utils/OrderManager.php'], file_get_contents($this->backups . '/files/app/Utils/OrderManager.php'));
        $this->assertFileExists($this->backups . '/applied.json');
        $this->assertSame(0700, fileperms($this->backups) & 0777);
        $this->assertSame(self::BASE['app/Services/Unchanged.php'], file_get_contents($this->live . '/app/Services/Unchanged.php'), 'a file the release does not change is not touched');
        $this->assertSame(0, (new Process(['php', '-l', $this->live . '/resources/lang/en/new-messages.php']))->run());
    }

    public function test_language_files_are_merged_never_overwritten(): void
    {
        $this->release();
        // What the live site did since the baseline: asked for a key nobody had translated (a placeholder), and an admin edited another.
        $this->put($this->live . '/resources/lang/en/new-messages.php', "<?php\n\nreturn [\n\t'old_key' => 'Old text',\n\t'edited_key' => 'Edited by the owner',\n\t'placeholder_key' => 'Placeholder key',\n\t'live_runtime_key' => 'Added by translate() on the live site',\n];\n");

        $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);
        $messages = include $this->live . '/resources/lang/en/new-messages.php';

        $this->assertSame('Old text', $messages['old_key']);
        $this->assertSame('Added by translate() on the live site', $messages['live_runtime_key'], 'nothing the live site accumulated is lost');
        $this->assertSame('Edited by the owner', $messages['edited_key'], 'the live value wins over the release for a key the owner edited');
        $this->assertSame('Real text for the placeholder', $messages['placeholder_key'], 'a placeholder translate() made is replaced by the real text');
        $this->assertSame('A brand new key', $messages['new_key']);
        $this->assertSame(['old_key', 'edited_key', 'placeholder_key', 'live_runtime_key', 'new_key'], array_keys($messages), 'live order kept, new keys appended');
    }

    public function test_the_backup_may_not_be_inside_the_application_tree_or_reuse_another_deployments_folder(): void
    {
        $this->release();

        $inside = $this->deploy('apply', ['--yes', '--backup-dir=' . $this->live . '/storage/backup']);
        $this->assertSame(2, $inside->getExitCode());
        $this->assertStringContainsString('inside the application tree', $inside->getOutput());

        mkdir($this->backups, 0700, true);
        file_put_contents($this->backups . '/something', 'x');
        $reused = $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);
        $this->assertSame(2, $reused->getExitCode());
        $this->assertStringContainsString('not empty', $reused->getOutput());
    }

    public function test_a_file_this_servers_php_cannot_parse_is_refused_before_anything_is_written(): void
    {
        $this->release();
        $this->replaceInPackage('app/Services/Fulfillment/New.php', "<?php\nclass New_ { public function broken( }\n");
        $before = $this->treeHash($this->live);

        $report = $this->verify(expect: 1);
        $apply = $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);

        $this->assertStringContainsString('app/Services/Fulfillment/New.php: UNPARSEABLE', $report['problems'][0]);
        $this->assertStringContainsString('cannot parse it', $report['problems'][0]);
        $this->assertSame(1, $apply->getExitCode());
        $this->assertSame($before, $this->treeHash($this->live), 'not even the files that were fine are written');
        $this->assertDirectoryDoesNotExist($this->backups);
    }

    public function test_a_deployment_cut_off_part_way_can_still_be_taken_back(): void
    {
        $this->requireFileSizeLimit();
        $this->release(['app/Utils/OrderManager.php' => "<?php\n// " . str_repeat('x', 300_000) . "\nfunction order() { return 'release'; }\n"]);
        $before = $this->treeHash($this->live);

        $cutOff = $this->applyWithFileSizeLimit(kilobytes: 100);

        $journal = (array) json_decode((string) file_get_contents($this->backups . '/applied.json'), true);
        $this->assertTrue($cutOff->hasBeenSignaled(), 'the process was killed while writing the large file');
        $this->assertNotSame($before, $this->treeHash($this->live), 'the tree really was left half-updated');
        $this->assertFalse($journal['complete'], 'the record says the deployment never finished');
        $this->assertContains('app/Services/Fulfillment/New.php', array_column($journal['files'], 'path'));
        $this->assertContains('app/Utils/OrderManager.php', array_column($journal['files'], 'path'), 'the file being written when it stopped is recorded before it is touched');

        $retry = $this->deploy('apply', ['--yes', '--backup-dir=' . $this->work . '/live/retry-backups']);
        $this->assertSame(2, $retry->getExitCode(), 'a second apply must not pile onto a half-updated tree: its own journal would not know what the first one did');
        $this->assertStringContainsString('did not finish and was not rolled back', $retry->getOutput());
        $this->assertStringContainsString('rollback --backup-dir=' . $this->backups, $retry->getOutput());

        $rollback = $this->deploy('rollback', ['--backup-dir=' . $this->backups], withApp: false);

        $this->assertSame(0, $rollback->getExitCode(), $rollback->getOutput());
        $this->assertStringContainsString('did not finish', $rollback->getOutput());
        $this->assertSame($before, $this->treeHash($this->live), 'every file, every directory and the half-written temporary file are back as they were');

        $again = $this->deploy('rollback', ['--backup-dir=' . $this->backups], withApp: false);
        $this->assertSame(0, $again->getExitCode());
        $this->assertStringContainsString('already rolled back', $again->getOutput());

        $afterwards = $this->deploy('apply', ['--yes', '--backup-dir=' . $this->work . '/live/retry-backups']);
        $this->assertSame(0, $afterwards->getExitCode(), 'once the unfinished deployment is rolled back, a new one may start: ' . $afterwards->getOutput());
    }

    public function test_a_full_disk_never_installs_a_truncated_file_and_the_deployment_takes_itself_back(): void
    {
        $this->requireFileSizeLimit();
        $this->release(['app/Utils/OrderManager.php' => "<?php\n// " . str_repeat('x', 300_000) . "\nfunction order() { return 'release'; }\n"]);
        $before = $this->treeHash($this->live);

        $full = $this->applyWithFileSizeLimit(kilobytes: 100, survive: true);

        $this->assertSame(2, $full->getExitCode(), $full->getOutput());
        $this->assertStringContainsString('is the disk full', $full->getOutput());
        $this->assertStringContainsString('every change it had made was taken back', $full->getOutput());
        $this->assertStringContainsString('optimize:clear', $full->getOutput(), 'and it says what to do next, as a rollback does');
        $this->assertSame($before, $this->treeHash($this->live), 'the tree is exactly as it was: no truncated file, no half-written temporary file, no stray directory');
        $this->assertArrayHasKey('rolled_back_at', (array) json_decode((string) file_get_contents($this->backups . '/applied.json'), true), 'the record says it was taken back, so it blocks nothing');
    }

    /** @return array<string, array{string}> */
    public static function languageFilesTheSiteLeftUnreadable(): array
    {
        return [
            'cut off while the site was writing it' => ["<?php\nreturn [\n\t'old_key' => 'SECRET-SENTINEL-DO-NOT-PRINT\n"],
            'empty' => [''],
            'not an array' => ["<?php\nreturn 'SECRET-SENTINEL-DO-NOT-PRINT';\n"],
        ];
    }

    /** @dataProvider languageFilesTheSiteLeftUnreadable */
    #[\PHPUnit\Framework\Attributes\DataProvider('languageFilesTheSiteLeftUnreadable')]
    public function test_a_language_file_the_site_left_unreadable_is_found_before_anything_is_written(string $contents): void
    {
        $this->release();
        $this->put($this->live . '/resources/lang/en/new-messages.php', $contents);
        $before = $this->treeHash($this->live);

        $report = $this->verify(expect: 1);
        $apply = $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);

        $this->assertStringContainsString('resources/lang/en/new-messages.php: UNREADABLE', $report['problems'][0]);
        $this->assertSame(1, $apply->getExitCode());
        $this->assertStringNotContainsString('SECRET-SENTINEL', $apply->getOutput() . $apply->getErrorOutput(), 'what is in the live file is never printed');
        $this->assertSame($before, $this->treeHash($this->live));
        $this->assertDirectoryDoesNotExist($this->backups);
    }

    /** @return array<string, array{string, string}> */
    public static function obstaclesOnTheServer(): array
    {
        return [
            'a directory where a file goes' => ['app/Services/Fulfillment/New.php', 'BLOCKED. A directory exists where the release puts a file.'],
            'a file where a directory must be' => ['app/Services/Fulfillment', 'BLOCKED. A file exists where the release needs a directory.'],
        ];
    }

    /** @dataProvider obstaclesOnTheServer */
    #[\PHPUnit\Framework\Attributes\DataProvider('obstaclesOnTheServer')]
    public function test_an_obstacle_on_the_server_is_found_before_anything_is_written(string $obstacle, string $expected): void
    {
        $this->release();
        $path = $this->live . '/' . $obstacle;
        str_ends_with($obstacle, '.php') ? mkdir($path, 0755, true) : $this->put($path, 'a file');
        $before = $this->treeHash($this->live);

        $report = $this->verify(expect: 1);
        $apply = $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);

        $this->assertStringContainsString($expected, implode("\n", $report['problems']));
        $this->assertSame(1, $apply->getExitCode());
        $this->assertSame($before, $this->treeHash($this->live), 'nothing is written, not even the files that were fine');
    }

    public function test_it_will_not_write_through_a_symbolic_link_to_a_directory(): void
    {
        $this->release();
        mkdir($this->work . '/elsewhere/Utils', 0755, true);
        rename($this->live . '/app/Utils/OrderManager.php', $this->work . '/elsewhere/Utils/OrderManager.php');
        rmdir($this->live . '/app/Utils');
        symlink($this->work . '/elsewhere/Utils', $this->live . '/app/Utils');

        $report = $this->verify(expect: 1);

        $this->assertStringContainsString('app/Utils/OrderManager.php: SYMLINK. A directory on the way to this file is a symbolic link', $report['problems'][0]);
    }

    public function test_an_option_that_is_misspelt_or_misused_is_an_error_not_something_quietly_ignored(): void
    {
        $this->release();
        $before = $this->treeHash($this->live);

        foreach ([
            ['apply', '--yes=false', "--yes is a switch and takes no value"],
            ['apply', '--backup-dir', "--backup-dir needs a value"],
            ['apply', '--accept-drifts=a.php', 'Unknown option --accept-drifts'],
            ['apply', '--yes', 'Unexpected argument'],
        ] as [$command, $argument, $message]) {
            $arguments = $argument === '--yes' ? ['--yes', '/elsewhere'] : [$argument];
            $process = $this->deploy($command, $arguments);

            $this->assertSame(2, $process->getExitCode(), $argument);
            $this->assertStringContainsString($message, $process->getOutput(), $argument);
        }
        $this->assertSame($before, $this->treeHash($this->live));
    }

    public function test_a_relative_backup_directory_inside_the_tree_is_refused_like_an_absolute_one(): void
    {
        $this->release();

        $inside = $this->deploy('apply', ['--yes', '--backup-dir=./backup-in-the-app'], cwd: $this->live);
        $through = $this->deploy('apply', ['--yes', '--backup-dir=../app/../app/storage/backup'], cwd: $this->live);

        foreach ([$inside, $through] as $process) {
            $this->assertSame(2, $process->getExitCode(), $process->getOutput());
            $this->assertStringContainsString('inside the application tree', $process->getOutput());
        }
        $this->assertDirectoryDoesNotExist($this->live . '/backup-in-the-app');
    }

    public function test_the_release_that_was_deployed_is_kept_with_its_backup(): void
    {
        $this->release();

        $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);

        $this->assertFileEquals($this->overlay . '/MANIFEST.json', $this->backups . '/MANIFEST.json');
        $this->assertSame($this->manifest()['head'], json_decode((string) file_get_contents($this->backups . '/MANIFEST.json'), true)['head'], 'the next release is built from this head');
    }

    public function test_new_files_are_written_before_the_files_they_may_be_used_by(): void
    {
        $this->release();

        $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);

        $journal = (array) json_decode((string) file_get_contents($this->backups . '/applied.json'), true);
        $this->assertSame(['app/Services/Fulfillment/New.php', 'database/migrations/2026_11_05_100000_create_thing.php', 'app/Utils/OrderManager.php', 'resources/lang/en/new-messages.php'], array_column($journal['files'], 'path'));
        $this->assertTrue($journal['complete']);
    }

    public function test_a_package_whose_manifest_was_edited_after_it_was_built_is_refused(): void
    {
        $this->release();
        $manifest = $this->manifest();
        $manifest['files'][0]['base_sha256'] = str_repeat('a', 64);
        file_put_contents($this->overlay . '/MANIFEST.json', json_encode($manifest));

        $process = $this->deploy('verify');

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('MANIFEST.json does not match SHA256SUMS', $process->getOutput());
    }

    public function test_the_ownership_of_a_replaced_file_survives_a_deployment_run_as_root(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('Only root can give files to another user.');
        }
        $this->release();
        foreach (['/app/Utils/OrderManager.php', '/app/Utils', '/app/Services', '/resources/lang/en/new-messages.php'] as $path) {
            chown($this->live . $path, 33);
            chgrp($this->live . $path, 33);
        }
        chown($this->live . '/app', 33);
        chgrp($this->live . '/app', 33);

        $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);

        clearstatcache();
        $this->assertSame(33, fileowner($this->live . '/app/Utils/OrderManager.php'), 'a replaced file stays the web user\'s');
        $this->assertSame(33, filegroup($this->live . '/app/Utils/OrderManager.php'));
        $this->assertSame(33, fileowner($this->live . '/resources/lang/en/new-messages.php'), 'the language files must stay writable by the site');
        $this->assertSame(33, fileowner($this->live . '/app/Services/Fulfillment'), 'a directory the deployment creates takes its parent\'s owner');
        $this->assertSame(33, fileowner($this->live . '/app/Services/Fulfillment/New.php'), 'and so does a new file');
    }

    public function test_applying_twice_changes_nothing_the_second_time(): void
    {
        $this->release();
        $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);

        $report = $this->verify();

        $this->assertSame(['ALREADY_APPLIED' => 4], $report['counts'], 'a language file that already holds every key of the release has nothing left to merge');

        $again = $this->deploy('apply', ['--yes', '--backup-dir=' . $this->work . '/second-backup']);

        $this->assertSame(0, $again->getExitCode(), $again->getOutput());
        $this->assertStringContainsString('Nothing to apply', $again->getOutput());
        $this->assertDirectoryDoesNotExist($this->work . '/second-backup', 'and no backup, journal or "To undo" line is made for a run that changed nothing');
    }

    // ── rolling back ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_rollback_puts_every_file_back_exactly_as_it_was(): void
    {
        $this->release();
        $before = $this->treeHash($this->live);
        $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);
        $this->assertNotSame($before, $this->treeHash($this->live));

        $rollback = $this->deploy('rollback', ['--backup-dir=' . $this->backups], withApp: false);

        $this->assertSame(0, $rollback->getExitCode(), $rollback->getOutput());
        $this->assertSame($before, $this->treeHash($this->live));
        $this->assertFileDoesNotExist($this->live . '/app/Services/Fulfillment/New.php');
        $this->assertDirectoryDoesNotExist($this->live . '/app/Services/Fulfillment', 'a directory the deployment created is removed with it');
        $this->assertDirectoryDoesNotExist($this->live . '/database', 'and so is every directory it created');
    }

    public function test_a_second_rollback_changes_nothing(): void
    {
        $this->release();
        $before = $this->treeHash($this->live);
        $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);
        $this->deploy('rollback', ['--backup-dir=' . $this->backups], withApp: false);

        $again = $this->deploy('rollback', ['--backup-dir=' . $this->backups], withApp: false);

        $this->assertSame(0, $again->getExitCode(), $again->getOutput());
        $this->assertSame($before, $this->treeHash($this->live));
    }

    public function test_a_rollback_removes_only_the_language_keys_the_deployment_added_and_keeps_what_the_site_wrote_since(): void
    {
        $this->release();
        $this->put($this->live . '/resources/lang/en/new-messages.php', "<?php\n\nreturn [\n\t'old_key' => 'Old text',\n\t'edited_key' => 'Edited key',\n\t'placeholder_key' => 'Placeholder key',\n];\n");
        $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);
        $messages = include $this->live . '/resources/lang/en/new-messages.php';
        $messages['written_after_the_deployment'] = 'Written after the deployment';
        $contents = "<?php\n\nreturn [\n";
        foreach ($messages as $key => $value) {
            $contents .= "\t" . var_export($key, true) . ' => ' . var_export($value, true) . ",\n";
        }
        $this->put($this->live . '/resources/lang/en/new-messages.php', $contents . "];\n");

        $rollback = $this->deploy('rollback', ['--backup-dir=' . $this->backups], withApp: false);
        $after = include $this->live . '/resources/lang/en/new-messages.php';

        $this->assertSame(0, $rollback->getExitCode(), $rollback->getOutput());
        $this->assertArrayNotHasKey('new_key', $after);
        $this->assertSame('Placeholder key', $after['placeholder_key'], 'the placeholder is back');
        $this->assertSame('Written after the deployment', $after['written_after_the_deployment'], 'what the site wrote since is not erased');
    }

    public function test_a_rollback_refuses_to_destroy_a_file_that_was_changed_after_the_deployment(): void
    {
        $this->release();
        $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);
        $this->put($this->live . '/app/Utils/OrderManager.php', "<?php\n// edited after the deployment\n");

        $refused = $this->deploy('rollback', ['--backup-dir=' . $this->backups], withApp: false);
        $this->assertSame(1, $refused->getExitCode());
        $this->assertStringContainsString('was changed after the deployment', $refused->getOutput());
        $this->assertStringContainsString('edited after the deployment', (string) file_get_contents($this->live . '/app/Utils/OrderManager.php'));

        $forced = $this->deploy('rollback', ['--backup-dir=' . $this->backups, '--force'], withApp: false);
        $this->assertSame(0, $forced->getExitCode());
        $this->assertSame(self::BASE['app/Utils/OrderManager.php'], file_get_contents($this->live . '/app/Utils/OrderManager.php'));
    }

    public function test_the_tool_never_prints_the_contents_of_a_file(): void
    {
        $this->release();
        $this->put($this->live . '/app/Utils/OrderManager.php', "<?php\n// SECRET-SENTINEL-DO-NOT-PRINT\n");

        $output = $this->deploy('verify')->getOutput() . $this->deploy('apply', ['--yes', '--accept-drift=app/Utils/OrderManager.php', '--backup-dir=' . $this->backups])->getOutput();

        $this->assertStringNotContainsString('SECRET-SENTINEL-DO-NOT-PRINT', $output);
    }


    // ── a rollback is only as good as what it restores from ──────────────────────────────────────────────────

    public function test_a_rollback_whose_saved_originals_are_gone_restores_nothing_and_says_so_even_with_force(): void
    {
        $this->release();
        $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);
        $deployed = $this->treeHash($this->live);
        (new Process(['rm', '-rf', $this->backups . '/files']))->run();

        foreach ([[], ['--force']] as $extra) {
            $rollback = $this->deploy('rollback', ['--backup-dir=' . $this->backups, ...$extra], withApp: false);

            $this->assertSame(1, $rollback->getExitCode(), $rollback->getOutput());
            $this->assertStringContainsString('the saved original', $rollback->getOutput());
            $this->assertStringNotContainsString('restored to what they were', $rollback->getOutput());
            $this->assertSame($deployed, $this->treeHash($this->live), 'no live file was replaced by an empty one');
        }
        $this->assertArrayNotHasKey('rolled_back_at', (array) json_decode((string) file_get_contents($this->backups . '/applied.json'), true), 'and the record still says it can be taken back');
    }

    public function test_a_rollback_will_not_write_a_saved_original_that_no_longer_matches_what_was_saved(): void
    {
        $this->release();
        $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);
        file_put_contents($this->backups . '/files/app/Utils/OrderManager.php', "<?php\n// truncated by a partial copy back from off-server storage\n");
        $deployed = $this->treeHash($this->live);

        $rollback = $this->deploy('rollback', ['--backup-dir=' . $this->backups], withApp: false);

        $this->assertSame(1, $rollback->getExitCode());
        $this->assertStringContainsString('app/Utils/OrderManager.php: the saved original', $rollback->getOutput());
        $this->assertSame($deployed, $this->treeHash($this->live));
    }

    public function test_a_deployment_record_cut_off_while_it_was_written_ends_in_a_message_not_a_php_fatal(): void
    {
        $this->release();
        $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);
        $deployed = $this->treeHash($this->live);

        foreach (['{"complete": tru' => 'cannot be read', '{}' => 'is not a record this deploy.php wrote'] as $contents => $message) {
            file_put_contents($this->backups . '/applied.json', $contents);

            $rollback = $this->deploy('rollback', ['--backup-dir=' . $this->backups], withApp: false);

            $this->assertSame(2, $rollback->getExitCode(), $contents);
            $this->assertStringContainsString($message, $rollback->getOutput(), $contents);
            $this->assertStringNotContainsString('Fatal error', $rollback->getOutput() . $rollback->getErrorOutput(), $contents);
            $this->assertStringNotContainsString('Uncaught', $rollback->getOutput() . $rollback->getErrorOutput(), $contents);
            $this->assertSame($deployed, $this->treeHash($this->live));
        }
    }

    /** @return array<string, array{string, mixed}> */
    public static function recordsThatNameWhatTheyShouldNot(): array
    {
        return [
            'a directory outside the application' => ['created_directories', ['/tmp/outside-the-application']],
            'a directory that climbs out' => ['created_directories', ['__APP__/../elsewhere']],
            'the environment file' => ['files', [['path' => '.env', 'existed' => false, 'sha256_before' => null, 'sha256_after' => 'x', 'merge' => null, 'strategy' => 'replace']]],
            'a path that climbs out' => ['files', [['path' => 'app/../../etc/passwd', 'existed' => false, 'sha256_before' => null, 'sha256_after' => 'x', 'merge' => null, 'strategy' => 'replace']]],
            'an entry that is not one' => ['files', ['not an entry']],
        ];
    }

    /** @dataProvider recordsThatNameWhatTheyShouldNot */
    #[\PHPUnit\Framework\Attributes\DataProvider('recordsThatNameWhatTheyShouldNot')]
    public function test_a_rollback_acts_only_on_a_record_that_names_paths_a_release_may_touch(string $field, mixed $value): void
    {
        $this->release();
        $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);
        $outside = $this->work . '/outside-the-application';
        mkdir($outside);
        $journal = (array) json_decode((string) file_get_contents($this->backups . '/applied.json'), true);
        $journal[$field] = json_decode(str_replace('__APP__', $this->live, (string) json_encode($value)), true);
        if ($field === 'created_directories') {
            $journal[$field][] = $outside;
        }
        file_put_contents($this->backups . '/applied.json', json_encode($journal));
        $deployed = $this->treeHash($this->live);

        $rollback = $this->deploy('rollback', ['--backup-dir=' . $this->backups], withApp: false);

        $this->assertSame(2, $rollback->getExitCode(), $rollback->getOutput());
        $this->assertSame($deployed, $this->treeHash($this->live));
        $this->assertDirectoryExists($outside, 'a directory outside the application is never removed');
    }

    public function test_when_the_automatic_undo_fails_too_the_operator_is_told_the_tree_is_half_updated_and_how_to_take_it_back(): void
    {
        $this->release();
        mkdir($this->backups, 0700, true);
        $deploy = $this->deployLibrary();
        $journal = new \AppliedJournal(path: $this->backups . '/applied.json', app: $this->live);
        (new \ReflectionProperty($journal, 'files'))->setValue($journal, ['an entry that cannot be undone']);

        ob_start();
        $code = (new \ReflectionMethod($deploy, 'stopped'))->invoke($deploy, new \RuntimeException('disk full'), $journal, $this->backups, $this->live);
        $output = (string) ob_get_clean();

        $this->assertSame(2, $code);
        $this->assertStringContainsString('disk full', $output);
        $this->assertStringContainsString('Taking the changes back failed too', $output);
        $this->assertStringContainsString('half-updated', $output);
        $this->assertStringContainsString('rollback --backup-dir=' . $this->backups, $output, 'the command that takes it back is printed');
        $this->assertStringNotContainsString('as it was before', $output);
    }

    // ── the guards that stop a second deployment landing on a half-updated tree ────────────────────────────

    public function test_an_unfinished_deployment_blocks_the_next_one_whatever_characters_the_backup_path_holds(): void
    {
        $this->requireFileSizeLimit();
        $this->release(['app/Utils/OrderManager.php' => "<?php\n// " . str_repeat('x', 300_000) . "\nfunction order() { return 'release'; }\n"]);
        $this->backups = $this->work . '/live/bk [2026] *?/first';
        $this->applyWithFileSizeLimit(kilobytes: 100);

        $second = $this->deploy('apply', ['--yes', '--backup-dir=' . $this->work . '/live/bk [2026] *?/second']);
        $same = $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);

        $this->assertSame(2, $second->getExitCode(), $second->getOutput());
        $this->assertStringContainsString('did not finish and was not rolled back', $second->getOutput());
        $this->assertStringContainsString("rollback '--backup-dir=" . $this->backups . "'", $second->getOutput(), 'the command can be pasted: a path with spaces is quoted');
        $this->assertSame(2, $same->getExitCode(), 'the same, non-empty directory is refused too');
        $this->assertStringContainsString('is not empty', $same->getOutput());
    }

    public function test_a_backup_directory_that_holds_only_a_dot_file_is_not_empty(): void
    {
        $this->release();
        mkdir($this->backups, 0700, true);
        file_put_contents($this->backups . '/.hidden', 'x');

        $apply = $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);

        $this->assertSame(2, $apply->getExitCode());
        $this->assertStringContainsString('is not empty', $apply->getOutput());
    }

    public function test_a_record_that_cannot_be_read_counts_as_a_deployment_that_did_not_finish(): void
    {
        $this->release();
        mkdir($this->work . '/live/old', 0700, true);
        file_put_contents($this->work . '/live/old/applied.json', '{"complete": tru');

        $apply = $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);

        $this->assertSame(2, $apply->getExitCode(), $apply->getOutput());
        $this->assertStringContainsString('A deployment recorded in ' . $this->work . '/live/old did not finish', $apply->getOutput());
    }

    // ── what the deployment creates ────────────────────────────────────────────────────────────────────────

    public function test_directories_the_deployment_creates_do_not_depend_on_the_umask_of_whoever_runs_it(): void
    {
        $this->release();

        $apply = new Process(['bash', '-c', 'umask 077; php "$@"', 'deploy', $this->overlay . '/deploy.php', 'apply', '--app=' . $this->live, '--package=' . $this->overlay, '--yes', '--backup-dir=' . $this->backups]);
        $apply->run();

        $this->assertSame(0, $apply->getExitCode(), $apply->getOutput());
        $parent = fileperms($this->live . '/app/Services') & 0777;
        foreach (['/app/Services/Fulfillment', '/database', '/database/migrations'] as $created) {
            $this->assertSame($parent, fileperms($this->live . $created) & 0777, "{$created} is as readable as the directory it was made in, not 0700");
        }
    }

    public function test_a_chain_of_new_directories_all_take_the_owner_of_the_directory_they_were_made_in(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('Only root can give files to another user.');
        }
        $this->release(['app/Services/Fulfillment/Sub/Deep/Thing.php' => "<?php\nclass Thing {}\n"]);
        foreach (['/app', '/app/Services', '/database'] as $path) {
            @mkdir($this->live . $path, 0755, true);
            chown($this->live . $path, 33);
            chgrp($this->live . $path, 33);
        }

        $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);

        clearstatcache();
        foreach (['/app/Services/Fulfillment', '/app/Services/Fulfillment/Sub', '/app/Services/Fulfillment/Sub/Deep', '/app/Services/Fulfillment/Sub/Deep/Thing.php', '/database/migrations', '/database/migrations/2026_11_05_100000_create_thing.php'] as $created) {
            $this->assertSame(33, fileowner($this->live . $created), "{$created} belongs to the web user, however deep the chain");
        }
    }

    // ── a rollback puts the replaced files back before it removes the ones the release added ──────────────

    public function test_a_rollback_restores_what_it_replaced_before_it_deletes_what_it_added(): void
    {
        if ((new Process(['strace', '-V']))->run() !== 0) {
            $this->markTestSkipped('strace is not available.');
        }
        $this->release();
        $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);
        $trace = $this->work . '/trace.txt';

        $process = new Process(['strace', '-f', '-qq', '-e', 'trace=unlink,unlinkat,rename,renameat,renameat2', '-o', $trace, 'php', $this->overlay . '/deploy.php', 'rollback', '--backup-dir=' . $this->backups]);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $lines = file($trace, FILE_IGNORE_NEW_LINES);
        $firstRestore = $firstDelete = null;
        foreach ($lines as $number => $line) {
            if ($firstRestore === null && preg_match('/rename(at2?)?\(.*"[^"]*\/app\/Utils\/OrderManager\.php"/', $line) === 1) {
                $firstRestore = $number;
            }
            if ($firstDelete === null && preg_match('/unlink(at)?\(.*"[^"]*\/app\/Services\/Fulfillment\/New\.php"/', $line) === 1) {
                $firstDelete = $number;
            }
        }
        $this->assertNotNull($firstRestore, 'the replaced file was put back');
        $this->assertNotNull($firstDelete, 'the added file was removed');
        $this->assertLessThan($firstDelete, $firstRestore, 'a request in flight never meets a replaced file whose new class is already gone');
    }

    // ── a language file is data: whatever it does when it is loaded stays out of the report ───────────────

    /** @return array<string, array{string, string}> */
    public static function languageFilesThatDoMoreThanReturnAnArray(): array
    {
        return [
            'one that ends the process' => ["<?php\nexit;\n", 'ended the process'],
            'one that exits with a status' => ["<?php\nexit(3);\n", 'ended the process'],
            'one that prints before it returns' => ["<?php\necho 'SECRET-SENTINEL-ECHO';\nreturn ['a' => 'b'];\n", 'prints text'],
            'one with text before the php tag' => ["SECRET-SENTINEL-PRINT\n<?php\nreturn ['a' => 'b'];\n", 'prints text'],
            'one with a byte-order mark' => ["\xEF\xBB\xBF<?php\nreturn ['a' => 'b'];\n", 'prints text'],
        ];
    }

    /** @dataProvider languageFilesThatDoMoreThanReturnAnArray */
    #[\PHPUnit\Framework\Attributes\DataProvider('languageFilesThatDoMoreThanReturnAnArray')]
    public function test_a_language_file_that_prints_or_exits_when_loaded_cannot_hide_a_failure_or_leak_into_the_report(string $contents, string $message): void
    {
        $this->release();
        $this->put($this->live . '/resources/lang/en/new-messages.php', $contents);
        $before = $this->treeHash($this->live);

        $verify = $this->deploy('verify', ['--json']);
        $apply = $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);

        foreach ([$verify, $apply] as $process) {
            $this->assertNotSame(0, $process->getExitCode(), 'a file that ends the run must not look like success: ' . $process->getOutput());
            $this->assertStringNotContainsString('SECRET-SENTINEL', $process->getOutput() . $process->getErrorOutput());
            $this->assertStringContainsString($message, $process->getOutput() . $process->getErrorOutput());
        }
        $this->assertSame($before, $this->treeHash($this->live));
        $this->assertDirectoryDoesNotExist($this->backups);
    }

    // ── the output contract ──────────────────────────────────────────────────────────────────────────────────

    public function test_with_json_an_error_in_the_options_goes_where_every_other_error_goes(): void
    {
        $this->release();

        $process = $this->deploy('verify', ['--json', '--bogus']);

        $this->assertSame(2, $process->getExitCode());
        $this->assertSame('', $process->getOutput(), 'stdout holds only JSON');
        $this->assertStringContainsString('Unknown option --bogus', $process->getErrorOutput());
    }

    public function test_without_the_posix_extension_it_says_that_owners_cannot_be_kept(): void
    {
        $this->release();

        $process = new Process(['php', '-d', 'disable_functions=posix_geteuid', $this->overlay . '/deploy.php', 'verify', '--app=' . $this->live, '--package=' . $this->overlay]);
        $process->run();

        $this->assertStringContainsString('[warn]', $process->getOutput());
        $this->assertStringContainsString('posix', $process->getOutput());
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The release: one modified code file, one new file, one new migration, new language keys, and documentation and a test.
     *
     * @param array<string, string> $overrides different content for any of those files
     */
    private function release(array $overrides = [], bool $archive = false): void
    {
        $this->commit($overrides + [
            'app/Utils/OrderManager.php' => "<?php\nfunction order() { return 'release'; }\n",
            'app/Services/Fulfillment/New.php' => "<?php\nclass New_ {}\n",
            'database/migrations/2026_11_05_100000_create_thing.php' => "<?php\nreturn new class {};\n",
            'resources/lang/en/new-messages.php' => "<?php\n\nreturn [\n\t'old_key' => 'Old text',\n\t'edited_key' => 'Release text',\n\t'placeholder_key' => 'Real text for the placeholder',\n\t'new_key' => 'A brand new key',\n];\n",
            'docs/guide.md' => '# guide',
            'tests/ThingTest.php' => "<?php\n",
        ], 'release');

        $process = $this->build($archive ? ['--archive'] : []);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
    }

    /** deploy.php as a library: its classes without the line that runs it (the tool is a script, and tests reach inside it only here). */
    private function deployLibrary(): object
    {
        if (!class_exists('Deploy', false)) {
            $source = (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/deploy/deploy.php');
            $source = preg_replace(['/^#!.*\n/', '/^exit\(\(new Deploy\(\)\)->run\(\$argv\)\);\n/m'], '', $source, -1, $replaced);
            $this->assertSame(2, $replaced, 'the shebang and the line that runs the tool were found');
            $file = sys_get_temp_dir() . '/deploy-library-' . bin2hex(random_bytes(4)) . '.php';
            file_put_contents($file, $source);
            require $file;
            unlink($file);
        }

        return new \Deploy();
    }

    /** @param list<string> $extra */
    private function build(array $extra = []): Process
    {
        $process = new Process(['php', dirname(__DIR__, 3) . '/scripts/deploy/build-overlay.php', '--repo=' . $this->repo, '--base=HEAD~1', '--head=HEAD', '--out=' . $this->overlay, ...$extra]);
        $process->run();

        return $process;
    }

    /** @param list<string> $arguments */
    private function deploy(string $command, array $arguments = [], bool $withApp = true, ?string $cwd = null): Process
    {
        $process = new Process(['php', $this->overlay . '/deploy.php', $command, ...($withApp ? ['--app=' . $this->live] : []), '--package=' . $this->overlay, ...$arguments], $cwd);
        $process->run();

        return $process;
    }

    /**
     * Runs `apply` the way a failing disk or a dropped connection would stop it: once a file it writes grows past the limit the
     * process is killed, or, when it survives, the write is cut short (as on a full disk).
     */
    private function applyWithFileSizeLimit(int $kilobytes, bool $survive = false): Process
    {
        $process = new Process(['bash', '-c', ($survive ? 'trap "" XFSZ; ' : '') . 'ulimit -f "$0" && php "$@"', (string) $kilobytes, $this->overlay . '/deploy.php', 'apply', '--app=' . $this->live, '--package=' . $this->overlay, '--yes', '--backup-dir=' . $this->backups]);
        try {
            $process->run();
        } catch (ProcessSignaledException) {
            // Killed on purpose by the limit; the process object still describes how it ended.
        }

        return $process;
    }

    private function requireFileSizeLimit(): void
    {
        if ((new Process(['bash', '-c', 'ulimit -f 100']))->run() !== 0) {
            $this->markTestSkipped('This system cannot limit the size of a written file.');
        }
    }

    /** What a package damaged on purpose, with its checksum made to match, would look like to the server. */
    private function replaceInPackage(string $path, string $content): void
    {
        file_put_contents($this->overlay . '/files/' . $path, $content);
        $manifest = $this->manifest();
        foreach ($manifest['files'] as &$entry) {
            if ($entry['path'] === $path) {
                $entry['new_sha256'] = hash('sha256', $content);
            }
        }
        $this->writeManifest($manifest);
        $this->reseal('files/' . $path);
    }

    /** @param array<string, mixed> $manifest written as the package's manifest, and recorded in SHA256SUMS as if the builder had made it */
    private function writeManifest(array $manifest): void
    {
        file_put_contents($this->overlay . '/MANIFEST.json', json_encode($manifest));
        $this->reseal('MANIFEST.json');
    }

    private function reseal(string $relative): void
    {
        $sums = $this->overlay . '/SHA256SUMS';
        $lines = array_map(
            fn (string $line): string => str_ends_with($line, '  ' . $relative) ? hash_file('sha256', $this->overlay . '/' . $relative) . '  ' . $relative : $line,
            explode("\n", rtrim((string) file_get_contents($sums))),
        );
        file_put_contents($sums, implode("\n", $lines) . "\n");
    }

    /** @return array<string, mixed> */
    private function verify(int $expect = 0): array
    {
        $process = $this->deploy('verify', ['--json']);
        $this->assertSame($expect, $process->getExitCode(), $process->getOutput());

        return (array) json_decode($process->getOutput(), true);
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return (array) json_decode((string) file_get_contents($this->overlay . '/MANIFEST.json'), true);
    }

    /** @param array<string, string|null> $files a null deletes the file */
    private function commit(array $files, string $message): void
    {
        foreach ($files as $path => $content) {
            if ($content === null) {
                unlink($this->repo . '/' . $path);
            } else {
                $this->put($this->repo . '/' . $path, $content);
            }
        }
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '-m', $message]);
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): void
    {
        $process = new Process(['git', '-C', $this->repo, ...$arguments]);
        $process->mustRun();
    }

    private function put(string $path, string $content): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $content);
    }

    private function treeHash(string $directory): string
    {
        $hashes = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $hashes[] = substr($file->getPathname(), strlen($directory)) . ($file->isFile() ? ':' . hash_file('sha256', $file->getPathname()) : '/');
        }
        sort($hashes);

        return hash('sha256', implode("\n", $hashes));
    }
}
