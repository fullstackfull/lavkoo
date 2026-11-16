<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Admin\Settings\LanguageController;
use App\Http\Requests\Admin\LanguageRequest;
use App\Models\BusinessSetting;
use App\Services\LanguageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A language's code is the name of a directory of resources/lang and of a file under public/json/admin/lang: the admin form created them from
 * the code it was sent, and deleted the directory from the address, so `../../public/x` created PHP files in the web root and `..` removed
 * the resources directory. The codes the form offers are two letters, optionally a script (`rs-latn`).
 */
class LanguageCodeTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
    }

    /** @var list<string> directories this test made under resources/lang */
    private array $directories = [];

    protected function tearDown(): void
    {
        array_map([File::class, 'deleteDirectory'], $this->directories);
        File::delete(glob(public_path('json/admin/lang/probe-*.json')) ?: []);
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return array<string, array{0: mixed, 1: bool}> */
    public static function codes(): array
    {
        return [
            'a country code' => ['sa', true],
            'a country code in capitals' => ['SA', true],
            'a code with a script' => ['rs-latn', true],
            'a code with an underscore' => ['pt_br', true],
            'a code with digits' => ['es419', true],
            'the longest code' => ['abcdefghijklmnop', true],
            'one more than the longest' => ['abcdefghijklmnopq', false],
            'a way up' => ['..', false],
            'a way up to the web root' => ['../../public/probe-lang', false],
            'a path' => ['a/b', false],
            'a path with backslashes' => ['a\\b', false],
            'a name with a dot' => ['x.php', false],
            'a dot' => ['.', false],
            'a leading dot' => ['.hidden', false],
            'a space' => ['a b', false],
            'a line break at the end' => ["sa\n", false],
            'a null byte' => ["sa\0", false],
            'a leading digit' => ['1a', false],
            'a leading hyphen' => ['-a', false],
            'a list' => [['sa'], false],
            'nothing' => ['', false],
        ];
    }

    #[DataProvider('codes')]
    public function test_the_form_accepts_the_codes_it_offers_and_nothing_that_names_a_path(mixed $code, bool $accepted): void
    {
        $rules = ['code' => (new LanguageRequest())->rules()['code']];

        $this->assertSame($accepted, Validator::make(['code' => $code], $rules)->passes(), var_export($code, true));
    }

    /** @return array<string, array{0: string}> */
    public static function languageRoutes(): array
    {
        return array_map(static fn (string $name): array => [$name], [
            'admin.system-setup.language.delete', 'admin.system-setup.language.translate', 'admin.system-setup.language.translate.list',
            'admin.system-setup.language.translate-submit', 'admin.system-setup.language.remove-key', 'admin.system-setup.language.auto-translate',
            'admin.system-setup.language.auto-translate-all',
        ]);
    }

    #[DataProvider('languageRoutes')]
    public function test_the_language_in_the_address_is_a_code_and_never_a_way_up(string $routeName): void
    {
        $route = Route::getRoutes()->getByName($routeName);
        $this->assertNotNull($route, $routeName);
        $method = $route->methods()[0];

        $matched = Route::getRoutes()->match(Request::create('/' . str_replace('{lang}', 'sa', $route->uri()), $method));
        $this->assertSame($routeName, $matched->getName());

        foreach (['..', '%2e%2e', '.', 'a.b', '.hidden', str_repeat('a', 17)] as $lang) {
            try {
                Route::getRoutes()->match(Request::create('/' . str_replace('{lang}', $lang, $route->uri()), $method));
                $this->fail($routeName . ' answered for the language "' . $lang . '"');
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- adding and deleting ----------------------------------------------------------------------------------------------------

    private function listLanguages(array $codes): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'language'], ['value' => json_encode(array_map(
            static fn (string $code, int $index): array => ['id' => $index + 1, 'name' => 'Language ' . $code, 'code' => $code, 'direction' => 'ltr', 'status' => 1, 'default' => $code === 'en'],
            $codes,
            array_keys($codes),
        ))]);
        Cache::flush();
    }

    private function languageDirectory(string $code): string
    {
        $directory = base_path('resources/lang/' . $code);
        File::ensureDirectoryExists($directory);
        file_put_contents($directory . '/messages.php', "<?php return ['probe_marker' => 'keep'];");
        file_put_contents($directory . '/new-messages.php', '<?php return [];');

        return $directory;
    }

    /** @param array<string, mixed> $input */
    private function addRequest(array $input): LanguageRequest
    {
        $request = LanguageRequest::create('/', 'POST', $input);
        $route = Route::getRoutes()->getByName('admin.system-setup.language.add-new');
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);

        return $request->setContainer(app())->setRedirector(app('redirect'));
    }

    /** @return array<string, array{0: string}> */
    public static function codesOfLanguagesThatExist(): array
    {
        return [
            'a listed language' => ['probe-listed'],
            'a listed language in capitals' => ['PROBE-LISTED'],
            'a directory the store does not list' => ['probe-orphan'],
        ];
    }

    #[DataProvider('codesOfLanguagesThatExist')]
    public function test_adding_a_language_that_exists_is_refused_even_when_the_form_leaves_out_its_type(string $code): void
    {
        $this->listLanguages(['en', 'probe-listed']);
        $listed = $this->languageDirectory('probe-listed');
        $orphan = $this->languageDirectory('probe-orphan');
        $this->directories = [$listed, $orphan, base_path('resources/lang/PROBE-LISTED')];

        try {
            $this->addRequest(['name' => 'Another name', 'code' => $code])->validateResolved();
            $this->fail('a language that exists was added again');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }

        $this->assertSame("<?php return ['probe_marker' => 'keep'];", file_get_contents($listed . '/messages.php'));
        $this->assertSame("<?php return ['probe_marker' => 'keep'];", file_get_contents($orphan . '/messages.php'));
        $this->assertDirectoryDoesNotExist(base_path('resources/lang/PROBE-LISTED'));
    }

    public function test_adding_a_language_that_is_new_is_still_accepted(): void
    {
        $this->listLanguages(['en']);

        $this->addRequest(['name' => 'A new language', 'code' => 'probe-brand-new', 'type' => 'add'])->validateResolved();

        $this->addToAssertionCount(1);
    }

    public function test_only_a_language_the_store_lists_can_be_deleted_and_never_the_base_language(): void
    {
        $this->listLanguages(['en', 'sa', 'probe-listed']);
        $language = BusinessSetting::where('type', 'language')->first();
        $service = app(LanguageService::class);

        $this->assertTrue($service->canBeDeleted(language: $language, code: 'probe-listed'));
        $this->assertTrue($service->canBeDeleted(language: $language, code: 'sa'));
        foreach (['en', 'EN', 'probe-orphan', 'PROBE-LISTED', '', 'resources'] as $code) {
            $this->assertFalse($service->canBeDeleted(language: $language, code: $code), $code);
        }
    }

    public function test_deleting_a_directory_the_store_does_not_list_removes_nothing(): void
    {
        $this->listLanguages(['en', 'sa']);
        $orphan = $this->languageDirectory('probe-orphan');
        $this->directories = [$orphan];
        file_put_contents(public_path('json/admin/lang/probe-orphan.json'), '{}');

        app(LanguageController::class)->delete('probe-orphan', app(LanguageService::class));

        $this->assertFileExists($orphan . '/messages.php');
        $this->assertFileExists(public_path('json/admin/lang/probe-orphan.json'));
        File::delete(public_path('json/admin/lang/probe-orphan.json'));
    }

    public function test_deleting_a_language_the_store_lists_still_removes_its_files(): void
    {
        $this->listLanguages(['en', 'probe-listed']);
        DB::table('business_settings')->insert(['type' => 'pnc_language', 'value' => json_encode(['en', 'probe-listed'])]);
        $directory = $this->languageDirectory('probe-listed');
        $this->directories = [$directory];
        file_put_contents(public_path('json/admin/lang/probe-listed.json'), '{}');

        app(LanguageController::class)->delete('probe-listed', app(LanguageService::class));

        $this->assertDirectoryDoesNotExist($directory);
        $this->assertFileDoesNotExist(public_path('json/admin/lang/probe-listed.json'));
    }
}
