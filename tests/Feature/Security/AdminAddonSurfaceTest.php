<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Admin\Settings\AddonActivationController;
use App\Http\Controllers\Admin\Settings\AddonController;
use App\Http\Requests\AddonPurchaseCodeRequest;
use App\Services\AddonService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use ZipArchive;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * An add-on is PHP that the admin installs, runs and deletes. The legacy copy of its screens (/admin/addon/*) sat outside the
 * `themes_and_addons` permission, so an employee whose role held no module at all could upload an add-on, make the platform include a
 * file by path and delete any directory the web user may write, and the add-on's path came from the request.
 *
 * Every test that deletes or includes through the service aims at a directory this test made itself. A path such as `storage`, `app` or an
 * empty string is only given to the function that decides what an add-on is (it asks the file system one question and changes nothing), so a
 * regression of that function cannot take the checkout with it.
 */
class AdminAddonSurfaceTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const INFO = "<?php return ['name' => 'Probe', 'is_published' => 0, 'purchase_code' => null, 'username' => null];";

    /** @var list<string> */
    private array $created = [];

    /** @var list<string> */
    private array $links = [];

    /** @var list<string> */
    private array $files = [];

    /** @var list<array{file: string, directory: string|null}> */
    private array $plantedInfoFiles = [];

    private string $publicModulesBackup;

    private string $licenceConfigurationBefore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        $this->licenceConfigurationBefore = (string) file_get_contents(base_path('config/system-addons.php'));

        // Deleting an add-on re-syncs public/Modules with Modules/, which drops the public copy of every module this checkout does not hold.
        $this->publicModulesBackup = storage_path('framework/testing/public-modules-' . uniqid());
        File::copyDirectory(public_path('Modules'), $this->publicModulesBackup);
    }

    protected function tearDown(): void
    {
        // A link goes first: deleting a directory through its link empties what the link points at and leaves the link.
        foreach ($this->links as $link) {
            if (is_link($link)) {
                unlink($link);
            }
        }
        foreach ($this->plantedInfoFiles as $planted) {
            File::delete($planted['file']);
            if ($planted['directory'] !== null) {
                @rmdir($planted['directory']);
            }
        }
        foreach ($this->created as $directory) {
            File::deleteDirectory($directory);
        }
        array_map('unlink', array_filter($this->files, 'file_exists'));
        File::deleteDirectory(public_path('Modules'));
        File::copyDirectory($this->publicModulesBackup, public_path('Modules'));
        File::deleteDirectory($this->publicModulesBackup);
        file_put_contents(base_path('config/system-addons.php'), $this->licenceConfigurationBefore);
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @param array<string, string> $files */
    private function makeDirectory(string $relativePath, array $files = []): string
    {
        $directory = base_path($relativePath);
        File::ensureDirectoryExists($directory);
        foreach ($files as $name => $content) {
            File::ensureDirectoryExists(dirname($directory . '/' . $name));
            file_put_contents($directory . '/' . $name, $content);
        }
        $this->created[] = $directory;

        return $directory;
    }

    /** The info file a module that ships with the platform does not hold, so that the only thing which keeps it from being an add-on is its name. */
    private function plantInfoFileIn(string $module): void
    {
        $file = base_path("Modules/{$module}/Addon/info.php");
        if (is_file($file)) {
            return;
        }
        $directory = dirname($file);
        $createdDirectory = !is_dir($directory);
        File::ensureDirectoryExists($directory);
        file_put_contents($file, self::INFO);
        $this->plantedInfoFiles[] = ['file' => $file, 'directory' => $createdDirectory ? $directory : null];
    }

    /** @return array{target: string, link: string} */
    private function linkedAddon(): array
    {
        $target = $this->makeDirectory('storage/probe-link-target', ['keep.txt' => 'keep', 'Http/Thing.php' => '<?php', 'Addon/info.php' => self::INFO]);
        $link = base_path('Modules/ProbeLinkAddon');
        symlink($target, $link);
        $this->links[] = $link;

        return ['target' => $target, 'link' => $link];
    }

    public function test_every_addon_screen_sits_behind_the_themes_and_addons_permission_under_both_prefixes(): void
    {
        $names = [];
        foreach (['admin.addon.', 'admin.system-setup.addon.'] as $prefix) {
            foreach (['index', 'publish', 'activation', 'upload', 'delete'] as $action) {
                $names[] = $prefix . $action;
            }
        }

        $routes = array_filter(Route::getRoutes()->getRoutes(), static fn ($route): bool => in_array($route->getName(), $names, true));

        $this->assertCount(count($names), $routes);
        foreach ($routes as $route) {
            $this->assertContains('module:themes_and_addons', $route->middleware(), $route->getName());
            $this->assertContains('admin', $route->middleware(), $route->getName());
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function pathsThatAreNotAnInstalledAddon(): array
    {
        return [
            'the storage directory' => ['storage'],
            'the application' => ['app'],
            'the project root' => ['.'],
            'a way up' => ['../other-site'],
            'a way up behind the modules directory' => ['Modules/../storage'],
            'a way up behind an add-on' => ['Modules/ProbeAddon/../..'],
            'a folder of an add-on' => ['Modules/ProbeAddon/Http'],
            'a trailing slash' => ['Modules/ProbeAddon/'],
            'a trailing line break' => ["Modules/ProbeAddon\n"],
            'a module that ships with the platform' => ['Modules/Blog'],
            'another module that ships with the platform' => ['Modules/AI'],
            'a third module that ships with the platform' => ['Modules/TaxModule'],
            'an add-on that is not installed' => ['Modules/NoSuchAddon'],
            'an empty path' => [''],
            'nothing' => [null],
            'a list' => [['Modules/ProbeAddon']],
            'a path that starts with the add-on directory in lower case' => ['modules/ProbeAddon'],
            'a path with a null byte' => ["Modules/ProbeAddon\0"],
            'an absolute path' => [__DIR__],
        ];
    }

    #[DataProvider('pathsThatAreNotAnInstalledAddon')]
    public function test_only_the_path_the_addon_list_offers_is_an_addon(mixed $path): void
    {
        $this->makeDirectory('Modules/ProbeAddon', ['Addon/info.php' => self::INFO]);

        $this->assertNull(app(AddonService::class)->installedAddonPath($path), var_export($path, true));
        $this->assertSame('Modules/ProbeAddon', app(AddonService::class)->installedAddonPath('Modules/ProbeAddon'));
    }

    public function test_a_planted_info_file_outside_the_modules_directory_is_never_included(): void
    {
        $directory = $this->makeDirectory('storage/probe-include', ['Addon/info.php' => "<?php file_put_contents(__DIR__ . '/../marker.txt', 'ran'); return ['name' => 'x', 'is_published' => 0];"]);

        $result = app(AddonService::class)->getPublishData(Request::create('/', 'POST', ['path' => 'storage/probe-include']));

        $this->assertSame('error', $result['status']);
        $this->assertFileDoesNotExist($directory . '/marker.txt');
    }

    /** @return array<string, array{0: string}> */
    public static function pathsThatLeadToADirectoryTheTestMade(): array
    {
        return [
            'a directory outside the modules directory' => ['storage/probe-sacrificial'],
            'a way up behind the modules directory' => ['Modules/../storage/probe-sacrificial'],
            'a way up behind an add-on' => ['Modules/ProbeAddon/../../storage/probe-sacrificial'],
            'a folder of an add-on' => ['Modules/ProbeAddon/Http'],
            'an add-on with a trailing slash' => ['Modules/ProbeAddon/'],
            'an add-on in lower case' => ['modules/ProbeAddon'],
        ];
    }

    #[DataProvider('pathsThatLeadToADirectoryTheTestMade')]
    public function test_nothing_is_deleted_or_activated_through_a_path_that_is_not_an_addon(string $path): void
    {
        $this->makeDirectory('Modules/ProbeAddon', ['Addon/info.php' => self::INFO, 'Http/Thing.php' => '<?php']);
        $sacrificial = $this->makeDirectory('storage/probe-sacrificial', ['keep.txt' => 'keep', 'Addon/info.php' => self::INFO]);

        $deleted = app(AddonService::class)->deleteAddon(Request::create('/', 'POST', ['path' => $path]));
        $published = app(AddonService::class)->getPublishData(Request::create('/', 'POST', ['path' => $path]));
        $activation = app(AddonService::class)->getActivationData(Request::create('/', 'POST', ['path' => $path, 'username' => 'u', 'purchase_code' => 'c']));

        $this->assertSame('error', $deleted['status']);
        $this->assertSame('error', $published['status']);
        $this->assertSame(0, $activation['status']);
        $this->assertFileExists($sacrificial . '/keep.txt');
        $this->assertFileExists(base_path('Modules/ProbeAddon/Http/Thing.php'));
        $this->assertSame(self::INFO, file_get_contents($sacrificial . '/Addon/info.php'), 'the file of a path that is no add-on is not rewritten');
        $this->assertSame(self::INFO, file_get_contents(base_path('Modules/ProbeAddon/Addon/info.php')));
    }

    /** @return array<string, array{0: string}> */
    public static function modulesThatShipWithThePlatform(): array
    {
        return ['the blog' => ['Blog'], 'the tax module' => ['TaxModule'], 'the AI module' => ['AI']];
    }

    #[DataProvider('modulesThatShipWithThePlatform')]
    public function test_a_module_that_ships_with_the_platform_is_not_an_addon_even_when_it_holds_an_info_file(string $module): void
    {
        $this->plantInfoFileIn($module);

        $service = app(AddonService::class);
        $this->assertNull($service->installedAddonPath("Modules/{$module}"));
        $this->assertSame('error', $service->deleteAddon(Request::create('/', 'POST', ['path' => "Modules/{$module}"]))['status']);
        $this->assertDirectoryExists(base_path("Modules/{$module}"));
    }

    public function test_a_directory_whose_name_ends_in_a_line_break_is_not_the_addon_of_the_name_before_it(): void
    {
        $this->makeDirectory("Modules/ProbeBreak\n", ['Addon/info.php' => self::INFO]);

        $this->assertNull(app(AddonService::class)->installedAddonPath("Modules/ProbeBreak\n"));
    }

    public function test_a_link_in_the_modules_directory_is_not_an_addon(): void
    {
        $this->linkedAddon();

        $this->assertNull(app(AddonService::class)->installedAddonPath('Modules/ProbeLinkAddon'));
    }

    public function test_deleting_a_link_in_the_modules_directory_deletes_nothing_behind_it(): void
    {
        ['target' => $target, 'link' => $link] = $this->linkedAddon();

        $result = app(AddonService::class)->deleteAddon(Request::create('/', 'POST', ['path' => 'Modules/ProbeLinkAddon']));

        $this->assertSame('error', $result['status']);
        $this->assertFileExists($target . '/keep.txt');
        $this->assertFileExists($target . '/Http/Thing.php');
        $this->assertFileExists($target . '/Addon/info.php');
        $this->assertTrue(is_link($link));
    }

    public function test_publishing_a_link_in_the_modules_directory_changes_nothing_behind_it(): void
    {
        ['target' => $target] = $this->linkedAddon();
        $published = "<?php return ['name' => 'Probe', 'module_name' => 'ProbeLinkAddon', 'is_published' => 0, 'purchase_code' => 'code', 'username' => 'user'];";
        file_put_contents($target . '/Addon/info.php', $published);

        $result = app(AddonService::class)->getPublishData(Request::create('/', 'POST', ['path' => 'Modules/ProbeLinkAddon']));

        $this->assertSame('error', $result['status']);
        $this->assertSame($published, file_get_contents($target . '/Addon/info.php'));
    }

    // ---- a link inside the add-on -----------------------------------------------------------------------------------------------

    private const ACTIVATED_INFO = "<?php return ['name' => 'Probe', 'module_name' => 'ProbeLinkedInfo', 'is_published' => 0, 'purchase_code' => 'code', 'username' => 'user'];";

    /** @return array<string, array{0: string}> where the link that leads the info file of an add-on to another place is put */
    public static function placesForALinkThatLeavesTheAddon(): array
    {
        return [
            'the info file is a link' => ['info'],
            'the Addon directory is a link' => ['addon'],
            'the Addon directory is a link to the directory of an add-on whose name starts with this one\'s' => ['sibling'],
        ];
    }

    #[DataProvider('placesForALinkThatLeavesTheAddon')]
    public function test_an_addon_whose_info_file_is_reached_through_a_link_to_another_place_is_not_an_addon(string $place): void
    {
        $elsewhere = $this->makeDirectory($place === 'sibling' ? 'Modules/ProbeLinkedInfoSibling' : 'storage/probe-linked-info', ['info.php' => self::ACTIVATED_INFO, 'Addon/info.php' => self::ACTIVATED_INFO]);
        $module = $this->makeDirectory('Modules/ProbeLinkedInfo', ['Http/Thing.php' => '<?php']);
        if ($place === 'info') {
            File::ensureDirectoryExists($module . '/Addon');
            symlink($elsewhere . '/info.php', $this->links[] = $module . '/Addon/info.php');
        } else {
            symlink($elsewhere . '/Addon', $this->links[] = $module . '/Addon');
        }
        Http::fake(['*' => Http::response(['active' => base64_encode('1')])]);
        $request = static fn (array $input = []): Request => Request::create('/', 'POST', ['path' => 'Modules/ProbeLinkedInfo'] + $input);
        $service = app(AddonService::class);

        $this->assertNull($service->installedAddonPath('Modules/ProbeLinkedInfo'));
        $this->assertSame('error', $service->getPublishData($request())['status']);
        $this->assertSame(0, $service->getActivationData($request(['username' => 'u', 'purchase_code' => 'c']))['status']);
        $this->assertSame('error', $service->deleteAddon($request())['status']);

        Http::assertNothingSent();
        $this->assertSame(self::ACTIVATED_INFO, file_get_contents($elsewhere . '/info.php'), 'the file behind the link was rewritten');
        $this->assertSame(self::ACTIVATED_INFO, file_get_contents($elsewhere . '/Addon/info.php'));
        $this->assertFileExists($module . '/Http/Thing.php');
    }

    public function test_a_directory_in_place_of_the_info_file_is_not_an_addon(): void
    {
        $this->makeDirectory('Modules/ProbeLinkedInfo', ['Addon/info.php/placeholder.txt' => 'x']);

        $this->assertNull(app(AddonService::class)->installedAddonPath('Modules/ProbeLinkedInfo'));
    }

    public function test_the_addon_list_offers_the_addons_the_service_manages_and_not_a_link_that_leads_out_of_one(): void
    {
        $this->makeDirectory('Modules/ProbeAddon', ['Addon/info.php' => self::INFO]);
        $elsewhere = $this->makeDirectory('storage/probe-linked-info', ['Addon/info.php' => self::ACTIVATED_INFO]);
        $this->linkedAddon();
        $linkedInfo = $this->makeDirectory('Modules/ProbeLinkedInfo');
        symlink($elsewhere . '/Addon', $this->links[] = $linkedInfo . '/Addon');
        $linkedFile = $this->makeDirectory('Modules/ProbeLinkedFile', ['Addon/placeholder.txt' => 'x']);
        symlink($elsewhere . '/Addon/info.php', $this->links[] = $linkedFile . '/Addon/info.php');

        $addons = app(AddonController::class)->index(null)->getData()['addons'];

        $this->assertContains('Modules/ProbeAddon', $addons);
        $this->assertSame([], array_values(array_intersect($addons, ['Modules/ProbeLinkAddon', 'Modules/ProbeLinkedInfo', 'Modules/ProbeLinkedFile', 'Modules/Blog', 'Modules/TaxModule', 'Modules/AI'])));
    }

    public function test_a_link_that_stays_inside_the_addon_is_still_an_addon(): void
    {
        $module = $this->makeDirectory('Modules/ProbeLinkedInfo', ['Addon/real-info.php' => self::ACTIVATED_INFO]);
        symlink('real-info.php', $this->links[] = $module . '/Addon/info.php');

        $this->assertSame('Modules/ProbeLinkedInfo', app(AddonService::class)->installedAddonPath('Modules/ProbeLinkedInfo'));
        $this->assertSame('success', app(AddonService::class)->getPublishData(Request::create('/', 'POST', ['path' => 'Modules/ProbeLinkedInfo']))['status']);
        $this->assertSame(1, (include $module . '/Addon/real-info.php')['is_published']);
    }

    public function test_an_addon_is_still_managed_when_the_modules_directory_itself_is_a_link(): void
    {
        $shared = sys_get_temp_dir() . '/addon-shared-' . uniqid();
        $release = sys_get_temp_dir() . '/addon-release-' . uniqid();
        File::ensureDirectoryExists($shared . '/Modules/ProbeShared/Addon');
        file_put_contents($shared . '/Modules/ProbeShared/Addon/info.php', self::INFO);
        File::ensureDirectoryExists($release);
        symlink($shared . '/Modules', $this->links[] = $release . '/Modules');
        $this->created[] = $shared;
        $this->created[] = $release;
        $basePath = $this->app->basePath();

        $this->app->setBasePath($release);
        try {
            $this->assertSame('Modules/ProbeShared', app(AddonService::class)->installedAddonPath('Modules/ProbeShared'));
            $this->assertSame(['Modules/ProbeShared'], app(AddonController::class)->index(null)->getData()['addons']);
        } finally {
            $this->app->setBasePath($basePath);
        }
    }

    public function test_the_deletion_of_a_real_addon_still_works(): void
    {
        $this->makeDirectory('Modules/ProbeAddon', ['Addon/info.php' => self::INFO, 'Http/Thing.php' => '<?php']);

        $result = app(AddonService::class)->deleteAddon(Request::create('/', 'POST', ['path' => 'Modules/ProbeAddon']));

        $this->assertSame('success', $result['status']);
        $this->assertDirectoryDoesNotExist(base_path('Modules/ProbeAddon'));
    }

    public function test_publishing_a_real_addon_still_flips_its_flag(): void
    {
        $activated = "<?php return ['name' => 'Probe', 'module_name' => 'ProbeAddon', 'is_published' => 0, 'purchase_code' => 'code', 'username' => 'user'];";
        $this->makeDirectory('Modules/ProbeAddon', ['Addon/info.php' => $activated]);

        $result = app(AddonService::class)->getPublishData(Request::create('/', 'POST', ['path' => 'Modules/ProbeAddon']));

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, (include base_path('Modules/ProbeAddon/Addon/info.php'))['is_published']);
    }

    // ---- the info file is replaced in one step ----------------------------------------------------------------------------------
    //
    // Every request that lists the add-ons includes the info files, so a write that truncates one first gives such a request an empty file.

    private function activatedAddon(): string
    {
        $activated = "<?php return ['name' => 'Probe', 'module_name' => 'ProbeAddon', 'software_id' => 'probe', 'is_published' => 0, 'purchase_code' => 'code', 'username' => 'user'];";
        $this->makeDirectory('Modules/ProbeAddon', ['Addon/info.php' => $activated]);

        return base_path('Modules/ProbeAddon/Addon/info.php');
    }

    private function assertInfoFileWasReplaced(string $info, int $inodeBefore): void
    {
        clearstatcache();
        $this->assertNotSame($inodeBefore, fileinode($info), 'the info file was rewritten in place');
        $this->assertSame(['info.php'], array_values(array_diff(scandir(dirname($info)), ['.', '..'])), 'a temporary file stayed');
        $this->assertSame(1, (include $info)['is_published']);
    }

    public function test_publishing_an_addon_replaces_its_info_file_in_one_step(): void
    {
        $info = $this->activatedAddon();
        $inodeBefore = fileinode($info);

        $result = app(AddonService::class)->getPublishData(Request::create('/', 'POST', ['path' => 'Modules/ProbeAddon']));

        $this->assertSame('success', $result['status']);
        $this->assertInfoFileWasReplaced($info, $inodeBefore);
    }

    public function test_activating_an_addon_replaces_its_info_file_in_one_step(): void
    {
        $info = $this->activatedAddon();
        $inodeBefore = fileinode($info);
        Http::fake(['*' => Http::response(['active' => base64_encode('1')])]);

        $result = app(AddonService::class)->getActivationData(Request::create('/', 'POST', ['path' => 'Modules/ProbeAddon', 'username' => 'u2', 'purchase_code' => 'c2']));

        $this->assertSame(1, $result['status']);
        $this->assertInfoFileWasReplaced($info, $inodeBefore);
        $this->assertSame(['u2', 'c2'], [(include $info)['username'], (include $info)['purchase_code']]);
    }

    public function test_activating_from_the_list_replaces_the_info_file_in_one_step(): void
    {
        $info = $this->activatedAddon();
        $inodeBefore = fileinode($info);
        $request = AddonPurchaseCodeRequest::create('/', 'POST', ['path' => 'Modules/ProbeAddon', 'addon_name' => 'ProbeAddon', 'name' => 'n', 'email' => 'a@b.test', 'username' => 'u2', 'purchase_key' => 'c2'], server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        $response = app(AddonActivationController::class)->activateFromList($request);

        $this->assertSame(1, $response->getData(true)['status']);
        $this->assertInfoFileWasReplaced($info, $inodeBefore);
        $this->assertSame(['u2', 'c2'], [(include $info)['username'], (include $info)['purchase_code']]);
    }

    // ---- the archive an add-on is installed from ---------------------------------------------------------------------------------

    /** @param array<string, string> $entries */
    private function uploadOf(array $entries): Request
    {
        $path = tempnam(sys_get_temp_dir(), 'addon');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        if ($entries === []) {
            file_put_contents($path, "PK\x05\x06" . str_repeat("\0", 18)); // ZipArchive writes no file for an empty archive: this is the one that has no entry
        }
        $this->files[] = $path;
        $this->created[] = base_path('Modules/ProbeZipAddon');

        return Request::create('/', 'POST', [], [], ['file_upload' => new UploadedFile($path, 'ProbeZipAddon.zip', 'application/zip', null, true)]);
    }

    public function test_an_archive_installs_the_folder_it_names_and_nothing_else(): void
    {
        $result = app(AddonService::class)->getUploadData($this->uploadOf([
            'ProbeZipAddon/Addon/info.php' => self::INFO,
            'ProbeZipAddon/Http/Thing.php' => '<?php',
            '__MACOSX/ProbeZipAddon/._info.php' => 'metadata',
        ]));

        $this->assertSame('success', $result['status']);
        $this->assertFileExists(base_path('Modules/ProbeZipAddon/Addon/info.php'));
        $this->assertFileExists(base_path('Modules/ProbeZipAddon/Http/Thing.php'));
        $this->assertDirectoryDoesNotExist(base_path('Modules/__MACOSX'));
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function archivesThatAreNotAnAddonOfOneFolder(): array
    {
        $info = self::INFO;

        return [
            'an entry in a core module' => [['ProbeZipAddon/Addon/info.php' => $info, 'Blog/Probe.txt' => 'overwrites a core module']],
            'an entry in another folder' => [['ProbeZipAddon/Addon/info.php' => $info, 'OtherFolder/Probe.txt' => 'x']],
            'a way up' => [['ProbeZipAddon/Addon/info.php' => $info, 'ProbeZipAddon/../../probe-escape.txt' => 'x']],
            'a way up first' => [['../probe-escape.txt' => 'x', 'ProbeZipAddon/Addon/info.php' => $info]],
            'an absolute path' => [['ProbeZipAddon/Addon/info.php' => $info, '/tmp/probe-escape.txt' => 'x']],
            'a backslash' => [['ProbeZipAddon/Addon/info.php' => $info, 'ProbeZipAddon\\..\\..\\probe-escape.txt' => 'x']],
            'a file beside the folder' => [['ProbeZipAddon/Addon/info.php' => $info, 'README.txt' => 'x']],
            'a core module as the folder' => [['Blog/Addon/info.php' => $info]],
            'the AI module as the folder' => [['AI/Addon/info.php' => $info]],
            'a folder name with a dot' => [['ProbeZipAddon.v2/Addon/info.php' => $info]],
            'a folder name with a space' => [['Probe Zip/Addon/info.php' => $info]],
            'a folder without an info file' => [['ProbeZipAddon/Http/Thing.php' => '<?php']],
            'an empty archive' => [[]],
        ];
    }

    /** @param array<string, string> $entries */
    #[DataProvider('archivesThatAreNotAnAddonOfOneFolder')]
    public function test_an_archive_that_is_not_an_addon_of_one_folder_extracts_nothing(array $entries): void
    {
        $this->files[] = base_path('Modules/Blog/Probe.txt');
        $this->files[] = base_path('Modules/OtherFolder/Probe.txt');
        $this->created[] = base_path('Modules/OtherFolder');
        $escape = dirname(base_path()) . '/probe-escape.txt';
        $this->files[] = $escape;

        $result = app(AddonService::class)->getUploadData($this->uploadOf($entries));

        $this->assertSame('error', $result['status']);
        $this->assertFileDoesNotExist(base_path('Modules/Blog/Probe.txt'));
        $this->assertDirectoryDoesNotExist(base_path('Modules/OtherFolder'));
        $this->assertDirectoryDoesNotExist(base_path('Modules/ProbeZipAddon'));
        $this->assertFileDoesNotExist($escape);
    }

    public function test_the_activation_from_the_list_includes_and_rewrites_only_an_installed_addon(): void
    {
        $marker = storage_path('probe-activation-marker.txt');
        $this->files[] = $marker;
        $directory = $this->makeDirectory('storage/probe-activate', ['Addon/info.php' => "<?php file_put_contents(" . var_export($marker, true) . ", 'ran'); return ['name' => 'x', 'module_name' => 'x', 'is_published' => 0];"]);
        $request = AddonPurchaseCodeRequest::create('/', 'POST', ['path' => 'storage/probe-activate', 'name' => 'n', 'email' => 'a@b.test', 'username' => 'u', 'purchase_key' => 'k'], server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        $response = app(AddonActivationController::class)->activateFromList($request);

        $this->assertSame(0, $response->getData(true)['status']);
        $this->assertFileDoesNotExist($marker, 'a file outside the Modules directory was included');
        $this->assertStringNotContainsString("'username'", file_get_contents($directory . '/Addon/info.php'), 'a file outside the Modules directory was rewritten');
    }
}
