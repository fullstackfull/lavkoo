<?php

namespace Tests\Feature\Localization;

use App\Http\Controllers\SharedController;
use App\Http\Middleware\APILocalizationMiddleware;
use App\Http\Requests\Request as StorefrontRequest;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Theme\LocalisedSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * I1-16: a visitor cannot be served a language their market does not present.
 *
 * The market's language list existed and **neither visitor path read it.** The website's switch
 * (`SharedController::changeLanguage`) and the API's `lang` header (`APILocalizationMiddleware`) both checked the requested code against
 * THE INSTALLATION'S languages -- the list in `business_settings` -- so a visitor on a storefront whose operator had listed Arabic only
 * could switch it to English and read an English page from a market that does not offer English, and an app could ask for the same in a
 * header and be answered in it. Everything that writes TO a person already asked the market (`MessageLanguage`, `OrderNotificationContext`);
 * what a person could ask the shop for did not.
 *
 * The rule is in one place, `LocalisedSettings::localeOfferedToVisitor()`, because two call sites with one rule between them is how the two
 * halves of this came to disagree in the first place.
 *
 * The two paths answer differently on purpose, and that is the design rather than an inconsistency: the website REFUSES, because a switch
 * is a deliberate act and silently giving somebody a language they did not choose is worse than telling them no; the API FALLS BACK, because
 * an installed app sends a header on every request and must be served a page whatever it asks for.
 */
class MarketPresentsItsLanguagesToVisitorsTest extends TestCase
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
        Cache::flush();
        LocalisedSettings::forget();

        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(StorefrontContext::class);
        LocalisedSettings::forget();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** The first test of the switch: a market that names no list is the shop, and both paths answer what they answer today. */
    public function test_a_market_that_names_no_language_list_is_the_shop(): void
    {
        $this->serveStorefront('KW');

        $this->assertNull(LocalisedSettings::localeOfferedToVisitor(requested: 'en'));
        $this->assertSame(200, $this->switchTo('en'));
        $this->assertSame('en', session('local'));
        $this->assertSame('sa', $this->localeOfApiRequest('sa'));
    }

    public function test_the_website_refuses_a_language_the_market_does_not_present(): void
    {
        $this->kuwaitPresents(['sa']);
        $this->serveStorefront('KW');
        session()->put('local', 'sa');

        $this->assertSame(422, $this->switchTo('en'), 'a Kuwaiti visitor switched to a language Kuwait does not present');
        $this->assertSame('sa', session('local'), 'the refused switch changed the language anyway');
    }

    public function test_the_website_still_switches_to_a_language_the_market_does_present(): void
    {
        $this->kuwaitPresents(['sa', 'en']);
        $this->serveStorefront('KW');

        $this->assertSame(200, $this->switchTo('en'));
        $this->assertSame('en', session('local'));
        $this->assertSame('ltr', session('direction'), 'the direction of the chosen language was not kept');
    }

    /** Another market's list is not this market's business: Saudi Arabia names none, so its visitor keeps the shop's answer. */
    public function test_a_markets_list_does_not_narrow_another_market(): void
    {
        $this->kuwaitPresents(['sa']);
        $this->serveStorefront('SA');

        $this->assertSame(200, $this->switchTo('en'));
    }

    public function test_the_api_answers_in_the_markets_own_language_when_the_header_asks_for_one_it_does_not_present(): void
    {
        $this->kuwaitPresents(['sa']);
        $this->serveStorefront('KW');

        $this->assertSame('sa', $this->localeOfApiRequest('en'), 'the API answered in a language Kuwait does not present');
    }

    public function test_the_api_still_answers_in_a_language_the_market_presents(): void
    {
        $this->kuwaitPresents(['sa', 'en']);
        $this->serveStorefront('KW');

        $this->assertSame('en', $this->localeOfApiRequest('en'));
        $this->assertSame('sa', $this->localeOfApiRequest('sa'));
    }

    /** A market whose list names a locale the installation does not have presents nothing, so nothing is narrowed by a typo. */
    public function test_a_list_of_locales_the_installation_does_not_have_narrows_nothing(): void
    {
        $this->kuwaitPresents(['zz']);
        $this->serveStorefront('KW');

        $this->assertNull(LocalisedSettings::localeOfferedToVisitor(requested: 'en'));
        $this->assertSame(200, $this->switchTo('en'));
    }

    /** @param list<string> $locales */
    private function kuwaitPresents(array $locales): void
    {
        config(['gcc.storefronts.language.lists_by_country.KW' => $locales]);
        LocalisedSettings::forget();
    }

    private function serveStorefront(string $country): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
    }

    private function switchTo(mixed $code): int
    {
        return app(SharedController::class)
            ->changeLanguage(StorefrontRequest::create('/change-language', 'POST', ['language_code' => $code]))
            ->status();
    }

    private function localeOfApiRequest(string $header): string
    {
        app(APILocalizationMiddleware::class)->handle(
            Request::create('/api/v1/products', 'GET', [], [], [], ['HTTP_LANG' => $header]),
            static fn () => response('ok'),
        );

        return App::getLocale();
    }
}
