<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The cookie banner is built as an HTML string in a JavaScript template literal, and the staff write its text. `{{ }}` escapes for HTML and not
 * for script: a backtick ends the literal, `${...}` runs inside it, so the text `;alert(document.domain);` of a role that may only edit the
 * business settings ran in the session of every visitor, an administrator on the storefront included. The text is printed as a JSON string
 * and put into the banner as text.
 */
class CookieBannerScriptTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const VIEWS = [
        'the storefront layout' => 'resources/themes/default/layouts/front-end/app.blade.php',
        'the blog layout' => 'resources/themes/default/web-views/blogs/blog-layouts.blade.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        DB::table('business_settings')->insert(['type' => 'language', 'value' => json_encode([['id' => 1, 'name' => 'English', 'code' => 'en', 'direction' => 'ltr', 'default' => true]])]);
        Cache::flush(); // the application booted before this table was built, and may hold the settings of the test before
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function viewsAndTexts(): array
    {
        $texts = [
            'a backtick that ends the literal' => '`;alert(document.domain);`',
            'a template expression' => '${alert(document.domain)}',
            'a closing script tag' => '</script><script>alert(document.domain)</script>',
            'a backslash and a quote' => 'C:\\path "quoted" \'single\'',
            'a line break' => "first line\nsecond line",
            'an html tag that is only text' => '<b>bold</b> & more',
            'an Arabic sentence' => 'نستخدم ملفات تعريف الارتباط',
            'an ordinary sentence' => 'We use cookies to improve your experience.',
            'an empty text' => '',
        ];

        $cases = [];
        foreach (self::VIEWS as $viewName => $view) {
            foreach ($texts as $textName => $text) {
                $cases["{$viewName}: {$textName}"] = [$view, $text];
            }
        }

        return $cases;
    }

    private function cookieBlock(string $view): string
    {
        $this->assertSame(1, preg_match('/^[ \t]*@php\(\$cookie\b.*?^[ \t]*`;[ \t]*$/ms', (string) file_get_contents(base_path($view)), $match), "{$view}: the cookie banner was not found");

        return $match[0];
    }

    private function render(string $view, string $text): string
    {
        return Blade::render($this->cookieBlock($view), ['web_config' => ['cookie_setting' => ['value' => json_encode(['cookie_text' => $text])]]]);
    }

    #[DataProvider('viewsAndTexts')]
    public function test_the_text_reaches_the_script_as_a_json_string_and_nowhere_else(string $view, string $text): void
    {
        $rendered = $this->render($view, $text);

        $this->assertSame(1, preg_match("/^\s*let cookie_text = '(.*)';$/m", $rendered, $printed), 'the text is printed as one single-quoted JavaScript string');
        $this->assertSame($text, json_decode('"' . $printed[1] . '"'), 'and it reads back exactly: every quote, tag and ampersand is a \\u escape');

        $banner = substr($rendered, strpos($rendered, 'let cookie_content'));
        foreach (array_filter(['alert', '${', '</script', 'second line', 'Arabic'], fn (string $word): bool => str_contains($text, $word)) as $word) {
            $this->assertStringNotContainsString($word, $banner, 'the banner markup holds none of the text');
        }
        $this->assertStringNotContainsString('</script', $rendered, 'and no tag of the page is closed from inside the script');
    }

    public function test_the_banner_markup_holds_an_empty_element_that_the_text_is_put_into_as_text(): void
    {
        foreach (self::VIEWS as $view) {
            $block = $this->cookieBlock($view);

            $this->assertStringContainsString('<div class="cookie-text"></div>', $block, $view);
            $this->assertStringNotContainsString("['cookie_text'] : '' }}", $block, "{$view}: the text is no longer echoed into the markup");
        }
    }

    public function test_the_text_is_put_into_the_banner_with_text_and_not_html(): void
    {
        foreach (self::VIEWS as $view) {
            $this->assertStringContainsString(".find('.cookie-text').text(cookie_text)", (string) file_get_contents(base_path($view)), $view);
        }
    }
}
