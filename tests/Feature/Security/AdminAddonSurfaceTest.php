<?php

namespace Tests\Feature\Security;

use App\Services\AddonService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private string $publicModulesBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();

        // Deleting an add-on re-syncs public/Modules with Modules/, which drops the public copy of every module this checkout does not hold.
        $this->publicModulesBackup = storage_path('framework/testing/public-modules-' . uniqid());
        File::copyDirectory(public_path('Modules'), $this->publicModulesBackup);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $directory) {
            File::deleteDirectory($directory);
        }
        File::deleteDirectory(public_path('Modules'));
        File::copyDirectory($this->publicModulesBackup, public_path('Modules'));
        File::deleteDirectory($this->publicModulesBackup);
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
}
