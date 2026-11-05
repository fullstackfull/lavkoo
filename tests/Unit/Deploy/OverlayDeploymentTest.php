<?php

namespace Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;
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

        $this->git(['init', '-q', '-b', 'main']);
        $this->git(['config', 'user.email', 'test@example.test']);
        $this->git(['config', 'user.name', 'Test']);
        $this->commit(self::BASE, 'base');
        foreach (self::BASE as $path => $content) {
            $this->put($this->live . '/' . $path, $content);
        }
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->work]))->run();
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
        $this->assertSame(['app/Utils/OrderManager.php: DRIFTED. Differs from the baseline the release was built against (a hotfix or a runtime rewrite). Look at it before replacing it.'], $report['problems']);
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
            file_put_contents($this->overlay . '/MANIFEST.json', json_encode($manifest));

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

    public function test_applying_twice_changes_nothing_the_second_time(): void
    {
        $this->release();
        $this->deploy('apply', ['--yes', '--backup-dir=' . $this->backups]);

        $report = $this->verify();

        $this->assertSame(['ALREADY_APPLIED' => 3, 'MERGE' => 1], $report['counts']);
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

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────

    /** The release: one modified code file, one new file, one new migration, new language keys, and documentation and a test. */
    private function release(): void
    {
        $this->commit([
            'app/Utils/OrderManager.php' => "<?php\nfunction order() { return 'release'; }\n",
            'app/Services/Fulfillment/New.php' => "<?php\nclass New_ {}\n",
            'database/migrations/2026_11_05_100000_create_thing.php' => "<?php\nreturn new class {};\n",
            'resources/lang/en/new-messages.php' => "<?php\n\nreturn [\n\t'old_key' => 'Old text',\n\t'edited_key' => 'Release text',\n\t'placeholder_key' => 'Real text for the placeholder',\n\t'new_key' => 'A brand new key',\n];\n",
            'docs/guide.md' => '# guide',
            'tests/ThingTest.php' => "<?php\n",
        ], 'release');

        $process = $this->build();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
    }

    private function build(): Process
    {
        $process = new Process(['php', dirname(__DIR__, 3) . '/scripts/deploy/build-overlay.php', '--repo=' . $this->repo, '--base=HEAD~1', '--head=HEAD', '--out=' . $this->overlay]);
        $process->run();

        return $process;
    }

    /** @param list<string> $arguments */
    private function deploy(string $command, array $arguments = [], bool $withApp = true): Process
    {
        $process = new Process(['php', $this->overlay . '/deploy.php', $command, ...($withApp ? ['--app=' . $this->live] : []), '--package=' . $this->overlay, ...$arguments]);
        $process->run();

        return $process;
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
