<?php

namespace Tests\Feature\Security;

use App\Http\Requests\Admin\LanguageRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
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

    protected function tearDown(): void
    {
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
}
