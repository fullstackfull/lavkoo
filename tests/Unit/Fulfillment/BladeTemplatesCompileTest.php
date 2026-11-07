<?php

namespace Tests\Unit\Fulfillment;

use Illuminate\Support\Facades\Blade;
use ParseError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every template this feature added or touched must compile to valid PHP.
 *
 * A Blade syntax slip (an unclosed @if, a stray bracket) is invisible until the page is opened, and the
 * admin and storefront pages cannot all be rendered in CI without the proprietary installation database.
 * Compiling needs no database and catches that whole class of mistake.
 */
class BladeTemplatesCompileTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function templates(): array
    {
        $roots = [
            'resources/views/admin-views/fulfillment',
            'resources/views/admin-views/order/_fulfillments-card.blade.php',
            'resources/views/admin-views/partials/_fulfillment-provider.blade.php',
            'resources/themes/default/web-views/partials/_mixed-provider-notice.blade.php',
            'resources/themes/default/web-views/partials/_fulfillment-shipments.blade.php',
            'resources/views/partials/address',
            'resources/views/admin-views/address',
            'resources/views/admin-views/order/partials/_invoice-address-rows.blade.php',
            'resources/themes/default/web-views/partials/address',
        ];

        $files = [];
        foreach ($roots as $root) {
            $path = dirname(__DIR__, 3) . '/' . $root;
            if (is_file($path)) {
                $files[] = $path;
            } elseif (is_dir($path)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if (str_ends_with($file->getFilename(), '.blade.php')) {
                        $files[] = $file->getPathname();
                    }
                }
            }
        }

        $cases = [];
        foreach ($files as $file) {
            $cases[str_replace(dirname(__DIR__, 3) . '/', '', $file)] = [$file];
        }

        return $cases;
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

    public function test_template_list_is_not_empty(): void
    {
        $this->assertNotEmpty(self::templates());
    }
}
