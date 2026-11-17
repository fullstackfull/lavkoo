<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\SharedController;
use App\Http\Middleware\APILocalizationMiddleware;
use App\Http\Requests\Request as StorefrontRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
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
        Cache::flush(); // the application booted before this table was built
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

    private function bindApiRequest(string $header): void
    {
        $request = Request::create('/api/v1/products', 'GET', [], [], [], ['HTTP_LANG' => $header]);
        $this->app->instance('request', $request);
        app(APILocalizationMiddleware::class)->handle($request, static fn () => response('ok'));
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
            'a boolean, which a loose comparison finds in every store' => [true],
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

    public function test_a_language_that_the_store_gave_no_direction_is_switched_to_left_to_right(): void
    {
        $this->theStoreLists([self::language('English', 'en', default: true), ['id' => 3, 'name' => 'French', 'code' => 'fr']]);
        session()->put('direction', 'rtl');

        $this->assertSame(200, $this->switchTo('fr'));

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

    // ---- translate() and the locale of the request -----------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function headersThatNameArabic(): array
    {
        return ['the language' => ['ar'], 'the language of Saudi Arabia' => ['ar-SA'], 'the language of the Emirates' => ['ar_AE'], 'the store code' => ['sa']];
    }

    #[DataProvider('headersThatNameArabic')]
    public function test_translate_leaves_the_locale_the_api_middleware_chose_for_the_whole_request(string $header): void
    {
        $spanishFile = base_path('resources/lang/es/new-messages.php');
        $spanishBefore = (string) file_get_contents($spanishFile);
        $this->bindApiRequest($header);

        try {
            $locales = [];
            foreach (['probe_api_locale_one', 'probe_api_locale_two', 'probe_api_locale_three'] as $key) {
                translate($key);
                $locales[] = App::getLocale();
            }

            $this->assertSame(['sa', 'sa', 'sa'], $locales, 'the locale of the request drifted from the store language to the language code and on');
            $this->assertSame($spanishBefore, file_get_contents($spanishFile), 'a key of the Arabic request was stored in the Spanish file');
            $this->assertArrayHasKey('probe_api_locale_three', include base_path('resources/lang/sa/new-messages.php'));
        } finally {
            file_put_contents($spanishFile, $spanishBefore);
        }
    }

    public function test_translate_still_sets_the_language_code_as_the_locale_of_a_page(): void
    {
        session()->put('local', 'sa');

        $this->assertNotNull(translate('probe_page_locale'));

        $this->assertSame('ar', App::getLocale());
    }

    // ---- which language a header names ------------------------------------------------------------------------------------------

    /** @return array{id: int, name: string, code: string, direction: string, default: bool} */
    private static function language(string $name, string $code, bool $default = false): array
    {
        return ['id' => 1, 'name' => $name, 'code' => $code, 'direction' => 'ltr', 'default' => $default];
    }

    /** @param list<array<string, mixed>> $languages */
    private function theStoreLists(array $languages): void
    {
        DB::table('business_settings')->where('type', 'language')->delete();
        DB::table('business_settings')->insert(['type' => 'language', 'value' => json_encode($languages)]);
        Cache::flush();
    }

    /** @return array<string, array{0: list<array<string, mixed>>, 1: string, 2: string}> the languages of the store, the header, and the locale the API answers in */
    public static function headersThatNameAListedLanguage(): array
    {
        $arabicDefault = [self::language('Arabic', 'sa', default: true), self::language('English', 'en')];
        $englishDefault = [self::language('English', 'en', default: true), self::language('Arabic', 'sa')];
        $arabicUnderAnotherCode = [self::language('English', 'en', default: true), self::language('Arabic', 'sy')];
        $regionalCodes = [self::language('English', 'en', default: true), self::language('Portuguese (Brazil)', 'pt-br'), self::language('Portuguese', 'pt')];

        return [
            'English of the United States, the store answering in Arabic by default' => [$arabicDefault, 'en-US', 'en'],
            'English of the United States written with an underscore' => [$arabicDefault, 'en_US', 'en'],
            'English of Britain' => [$arabicDefault, 'en-GB', 'en'],
            'the same in capitals' => [$arabicDefault, 'EN-GB', 'en'],
            'the name of the language' => [$arabicDefault, 'English', 'en'],
            'Arabic of Saudi Arabia, the store answering in English by default' => [$englishDefault, 'ar-SA', 'sa'],
            'Arabic of Saudi Arabia written with an underscore' => [$englishDefault, 'ar_SA', 'sa'],
            'Arabic of the Emirates' => [$englishDefault, 'ar-AE', 'sa'],
            'the name of the language, Arabic' => [$englishDefault, 'Arabic', 'sa'],
            'the name of the language with a region' => [$arabicDefault, 'English-US', 'en'],
            'the name of the language with a region, written with an underscore' => [$englishDefault, 'Arabic_SA', 'sa'],
            'English of the United States, where English is the default' => [$englishDefault, 'en-US', 'en'],
            'Arabic filed under another code' => [$arabicUnderAnotherCode, 'ar-SA', 'sy'],
            'a regional code that the store lists as it is' => [$regionalCodes, 'pt-BR', 'pt-br'],
            'a regional code that the store lists for the language only' => [$regionalCodes, 'pt-PT', 'pt'],
            'a language the store does not list, with a region' => [$englishDefault, 'es-419', 'en'],
            'English with a region, where the store lists no English' => [[self::language('Arabic', 'sa', default: true)], 'en-US', 'sa'],
        ];
    }

    /** @param list<array<string, mixed>> $languages */
    #[DataProvider('headersThatNameAListedLanguage')]
    public function test_the_api_answers_in_the_language_a_header_names_whatever_its_region(array $languages, string $header, string $locale): void
    {
        $this->theStoreLists($languages);

        $this->assertSame($locale, $this->localeOfApiRequest($header));
    }

    public function test_a_code_the_store_does_not_list_is_answered_in_the_language_the_store_marked_as_its_default_and_not_in_the_first(): void
    {
        $this->theStoreLists([self::language('Arabic', 'sa'), self::language('English', 'en', default: true)]);

        $this->assertSame('en', $this->localeOfApiRequest('zz'));
    }

    public function test_a_store_that_marked_no_default_answers_in_its_first_language(): void
    {
        $this->theStoreLists([self::language('Arabic', 'sa'), self::language('English', 'en')]);

        $this->assertSame('sa', $this->localeOfApiRequest('zz'));
    }

    public function test_the_default_language_is_answered_under_its_code_in_lower_case(): void
    {
        $this->theStoreLists([self::language('English', 'EN', default: true), self::language('Arabic', 'AR')]);

        $this->assertSame('en', $this->localeOfApiRequest('zz'));
    }

    public function test_a_store_that_lists_no_language_takes_a_header_that_is_a_code_as_it_is(): void
    {
        $this->theStoreLists([]);

        $this->assertSame('zz', $this->localeOfApiRequest('zz'));
    }

    /** @return array<string, array{0: string}> */
    public static function headersThatAreNoCode(): array
    {
        return [
            'a way up' => ['../../x'],
            'a list of languages with weights' => ['en,ar;q=0.9'],
            'a wildcard' => ['*'],
            'a space' => ['a b'],
            'a null byte' => ["en\0"],
            'a code that is too long' => ['abcdefghijklmnopq'],
            'a number' => ['12'],
        ];
    }

    #[DataProvider('headersThatAreNoCode')]
    public function test_a_header_that_is_no_code_is_never_a_locale_even_where_the_store_lists_no_language(string $header): void
    {
        $this->theStoreLists([]);

        $this->assertSame('en', $this->localeOfApiRequest($header));
    }
}
