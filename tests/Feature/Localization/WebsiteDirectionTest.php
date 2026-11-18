<?php

namespace Tests\Feature\Localization;

use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use FilesystemIterator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Feature\Address\ApiAddressTestCase;

/**
 * Arabic is shown right-to-left and English left-to-right on the website. The direction is not worked out from the language code: it is the
 * `direction` of the entry of the language list (the `language` business setting), copied into the session when the visitor switches language
 * (POST /change-language) or, with no choice made, when the default language of the list is read (getDefaultLanguage()); and every page of the
 * website opens with an <html> tag whose dir is that session value.
 *
 * Rendered here: the maintenance page, through its real route and its real template, because it is the one page of the website that is a whole
 * page and needs nothing of the store. The other layouts (the shop's, the auctions', the blog's, the invoice) are not rendered: a scan checks
 * that each of them takes its dir from the same session value, which proves what their first line says and not that the page renders.
 * Not covered: what makes getDefaultLanguage() run before a page on a real request (the web boot of AppServiceProvider, which does not run
 * in a test); the test calls it first, as that boot does.
 */
class WebsiteDirectionTest extends ApiAddressTestCase
{
    /** What the first line of each template of the website may say about the direction: the session value, or the variable of that session value. */
    private const ACCEPTED_DIR_EXPRESSIONS = ["session()->get('direction') ?? 'ltr'", "session('direction')", '$direction'];

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('VIEW_FILE_NAMES')) {
            define('VIEW_FILE_NAMES', include resource_path('themes/default/file_names.php'));
        }
        View::addLocation(resource_path('themes/default'));
        View::share('web_config', ['fav_icon' => ['path' => '/favicon.png']]);
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        DB::table('business_settings')->insert([
            ['type' => 'maintenance_message_setup', 'value' => json_encode(['business_number' => 0, 'business_email' => 0, 'maintenance_message' => 'Back soon', 'message_body' => 'We are updating the store'])],
            ['type' => 'maintenance_duration_setup', 'value' => json_encode(['maintenance_duration' => 'until_change'])],
        ]);
    }

    /** @param array<string, array{direction?: string, default?: bool}> $languages the entries of the language list by code */
    private function setLanguageList(array $languages): void
    {
        $list = [];
        foreach ($languages as $code => $entry) {
            $list[] = ['id' => count($list) + 1, 'name' => strtoupper($code), 'code' => $code, 'status' => 1, 'default' => false, ...$entry];
        }
        DB::table('business_settings')->updateOrInsert(['type' => 'language'], ['value' => json_encode($list)]);
        Cache::flush();
    }

    /** The website is in maintenance. Set after a language switch, which clears the cache. */
    private function putTheWebsiteInMaintenance(): void
    {
        Cache::put('system_maintenance_mode', [
            'status' => 1, 'selectedSystems' => ['user_website' => 1, 'vendor_panel' => 0], 'maintenance_duration' => ['maintenance_duration' => 'until_change'],
        ], now()->addHour());
    }

    private function directionOfThePage(): string
    {
        $this->putTheWebsiteInMaintenance();
        $page = $this->get('/maintenance-mode')->assertOk()->getContent();

        preg_match('/<html\b[^>]*\sdir="(?<direction>[^"]*)"/', $page, $match);

        return $match['direction'] ?? 'no dir attribute';
    }

    /** @return array<string, array{0: array<string, array{direction: string}>, 1: array<string, string>}> the language list and the direction each language must give */
    public static function languageLists(): array
    {
        return [
            'Arabic is right-to-left and English left-to-right' => [['en' => ['direction' => 'ltr'], 'sa' => ['direction' => 'rtl']], ['sa' => 'rtl', 'en' => 'ltr']],
            'a list that says the opposite is believed, not the language code' => [['en' => ['direction' => 'rtl'], 'sa' => ['direction' => 'ltr']], ['sa' => 'ltr', 'en' => 'rtl']],
        ];
    }

    /**
     * @param array<string, array{direction: string}> $list
     * @param array<string, string> $expectedDirections
     */
    #[DataProvider('languageLists')]
    public function test_a_visitor_who_switches_language_gets_the_direction_of_that_language_in_the_session_and_on_the_page(array $list, array $expectedDirections): void
    {
        $this->setLanguageList($list);

        foreach ($expectedDirections as $code => $expectedDirection) {
            $this->postJson('/change-language', ['language_code' => $code])->assertOk();

            $this->assertSame($expectedDirection, session('direction'), "after the switch to {$code}");
            $this->assertSame($expectedDirection, $this->directionOfThePage(), "the page after the switch to {$code}");
        }
    }

    /** @return array<string, array{0: string, 1: string}> the default language of the list and its direction */
    public static function defaultLanguages(): array
    {
        return [
            'English is the default' => ['en', 'ltr'],
            'Arabic is the default' => ['sa', 'rtl'],
        ];
    }

    #[DataProvider('defaultLanguages')]
    public function test_a_visitor_who_chose_no_language_gets_the_direction_of_the_default_language(string $defaultCode, string $expectedDirection): void
    {
        $this->setLanguageList(['en' => ['direction' => 'ltr', 'default' => $defaultCode === 'en'], 'sa' => ['direction' => 'rtl', 'default' => $defaultCode === 'sa']]);

        $this->assertSame($defaultCode, getDefaultLanguage());

        $this->assertSame($expectedDirection, session('direction'));
        $this->assertSame($expectedDirection, $this->directionOfThePage());
    }

    public function test_a_language_whose_entry_names_no_direction_is_left_to_right(): void
    {
        $this->setLanguageList(['en' => ['direction' => 'ltr'], 'sa' => []]);

        $this->postJson('/change-language', ['language_code' => 'sa'])->assertOk();

        $this->assertSame('ltr', session('direction'));
        $this->assertSame('ltr', $this->directionOfThePage());
    }

    public function test_every_template_of_the_website_that_opens_a_page_takes_its_direction_from_the_session(): void
    {
        $templates = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('themes'), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php') && str_contains((string) file_get_contents($file->getPathname()), '<html')) {
                $templates[substr($file->getPathname(), strlen(base_path()) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($templates);

        $this->assertSame([
            'resources/themes/default/auction/layouts/auction-app.blade.php',
            'resources/themes/default/layouts/front-end/app.blade.php',
            'resources/themes/default/web-views/blogs/blog-layouts.blade.php',
            'resources/themes/default/web-views/maintenance-mode.blade.php',
            'resources/themes/default/web-views/order/invoice.blade.php',
        ], array_keys($templates), 'a template of the website opens a page that this list does not know');

        foreach ($templates as $file => $source) {
            $this->assertSame(1, preg_match('/<html\b[^\n]*?\sdir="\{\{\s*(?<expression>.+?)\s*\}\}"/', $source, $match), "{$file} has no dir written from the session");
            $this->assertContains($match['expression'], self::ACCEPTED_DIR_EXPRESSIONS, "{$file} decides its direction by something else than the session value");
            if ($match['expression'] === '$direction') {
                $this->assertStringContainsString("\$direction = Session::get('direction');", $source, "{$file} takes its direction from something else than the session value");
            }
        }
    }
}
