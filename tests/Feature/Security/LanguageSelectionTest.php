<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\SharedController;
use App\Http\Middleware\APILocalizationMiddleware;
use App\Http\Requests\Request as StorefrontRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The language a visitor asks for is a directory of resources/lang for translate(), which includes its files and writes the keys it did not
 * know. The language switch of the storefront stored whatever code the request sent in the session, and the API took the `lang` header as the
 * locale when the store did not list it, so an anonymous visitor made every translate() of the request fail and log, and a code with a slash
 * made it a server error. A code the store does not list is never a locale: the switch refuses it and the API answers in the store's default.
 */
class LanguageSelectionTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        DB::table('business_settings')->insert(['type' => 'language', 'value' => json_encode([
            ['id' => 1, 'name' => 'English', 'code' => 'en', 'direction' => 'ltr', 'default' => true],
            ['id' => 2, 'name' => 'Arabic', 'code' => 'sa', 'direction' => 'rtl', 'default' => false],
        ])]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function switchTo(mixed $code): int
    {
        return app(SharedController::class)->changeLanguage(StorefrontRequest::create('/change-language', 'POST', ['language_code' => $code]))->status();
    }

    private function localeOfApiRequest(?string $header): string
    {
        $server = $header === null ? [] : ['HTTP_LANG' => $header];
        app(APILocalizationMiddleware::class)->handle(Request::create('/api/v1/products', 'GET', [], [], [], $server), static fn () => response('ok'));

        return App::getLocale();
    }

    /** @return array<string, array{0: mixed}> */
    public static function codesTheStoreDoesNotList(): array
    {
        return [
            'a code nobody lists' => ['zz'],
            'a code in capitals nobody lists' => ['ZZ'],
            'a way up' => ['../../x'],
            'a way up behind a language' => ['en/../ae'],
            'a dot' => ['.'],
            'a null byte' => ["en\0"],
            'a list' => [['en']],
            'an empty code' => [''],
            'nothing' => [null],
        ];
    }

    #[DataProvider('codesTheStoreDoesNotList')]
    public function test_the_switch_refuses_a_code_the_store_does_not_list_and_keeps_the_language_of_the_session(mixed $code): void
    {
        session()->put('local', 'sa');

        $this->assertSame(422, $this->switchTo($code));
        $this->assertSame('sa', session('local'));
    }

    public function test_the_switch_still_changes_to_a_language_the_store_lists(): void
    {
        $this->assertSame(200, $this->switchTo('sa'));
        $this->assertSame('sa', session('local'));
        $this->assertSame('rtl', session('direction'));

        $this->assertSame(200, $this->switchTo('en'));
        $this->assertSame('en', session('local'));
        $this->assertSame('ltr', session('direction'));
    }

    #[DataProvider('codesTheStoreDoesNotList')]
    public function test_the_api_answers_in_the_default_language_when_the_header_names_a_code_the_store_does_not_list(mixed $header): void
    {
        $this->assertSame('en', $this->localeOfApiRequest(is_string($header) ? $header : null));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function headersTheApiResolves(): array
    {
        return [
            'a language of the store' => ['sa', 'sa'],
            'the same in capitals' => ['SA', 'sa'],
            'the language family the store files under another code' => ['ar', 'sa'],
            'the default language' => ['en', 'en'],
            'the country code a field build sent' => ['us', 'en'],
        ];
    }

    #[DataProvider('headersTheApiResolves')]
    public function test_the_api_still_resolves_what_the_apps_send(string $header, string $locale): void
    {
        $this->assertSame($locale, $this->localeOfApiRequest($header));
    }
}
