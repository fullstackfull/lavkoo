<?php

namespace Tests\Feature\Address;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use ParseError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WritesTemporaryFiles;
use Tests\TestCase;

/**
 * Every storefront template the structured address touched (and the partials it added) must compile to valid PHP: a Blade slip
 * is invisible until the page is opened, and the checkout cannot be opened in CI without the installation database.
 */
class StorefrontTemplatesTest extends TestCase
{
    use WritesTemporaryFiles;

    protected function setUp(): void
    {
        parent::setUp();

        View::addLocation(resource_path('themes/default'));
    }

    private const TEMPLATES = [
        'resources/themes/default/web-views/checkout/shipping.blade.php',
        'resources/themes/default/web-views/users-profile/account-address.blade.php',
        'resources/themes/default/web-views/users-profile/account-address-edit.blade.php',
        'resources/themes/default/web-views/users-profile/account-order-details.blade.php',
        'resources/themes/default/web-views/order/invoice.blade.php',
        'resources/themes/default/web-views/partials/address/_checkout-address-prefill.blade.php',
        'resources/themes/default/web-views/partials/address/_storefront-address-guard.blade.php',
    ];

    /** @return array<string, array{string}> */
    public static function templates(): array
    {
        return array_combine(self::TEMPLATES, array_map(static fn (string $template): array => [dirname(__DIR__, 3) . '/' . $template], self::TEMPLATES));
    }

    #[DataProvider('templates')]
    public function test_template_compiles_to_valid_php(string $file): void
    {
        $compiled = Blade::compileString((string) file_get_contents($file));

        try {
            token_get_all($compiled, TOKEN_PARSE);
            $this->addToAssertionCount(1);
        } catch (ParseError $error) {
            $this->fail("{$file} compiles to invalid PHP: {$error->getMessage()} on compiled line {$error->getLine()}");
        }
    }

    public function test_the_scripts_the_partials_write_parse_as_javascript(): void
    {
        $forms = [['form' => '#address-form', 'country' => '#country', 'street' => '#address', 'phone' => '#phone', 'select' => 'shipping_method_select', 'updateBox' => '#update_address']];
        foreach (['_checkout-address-prefill', '_storefront-address-guard'] as $partial) {
            $html = view('web-views.partials.address.' . $partial, ['forms' => $forms])->render();
            preg_match('/<script>(.*)<\/script>/s', $html, $script);
            $this->assertNotEmpty($script, $partial . ' writes a script');

            $this->assertParsesAsJavascript(label: $partial, script: $script[1]);
        }
    }

    public function test_the_shared_picker_script_parses_as_javascript(): void
    {
        $html = view('partials.address._unified-address-script')->render();

        $this->assertGreaterThan(0, preg_match_all('/<script[^>]*>(.*?)<\/script>/s', $html, $scripts), 'the picker writes its script');
        foreach ($scripts[1] as $position => $script) {
            $this->assertParsesAsJavascript(label: 'picker script ' . $position, script: $script);
        }
    }

    private function assertParsesAsJavascript(string $label, string $script): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed, so the scripts cannot be syntax-checked.');
        }

        $file = $this->temporaryFile(extension: 'js', contents: $script);
        $process = proc_open([$node, '--check', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $errors = stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($process), $label . ' does not parse: ' . $errors);
    }
}
