<?php

namespace Tests\Feature\Address;

use FilesystemIterator;
use Illuminate\Support\Facades\Blade;
use ParseError;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Every template the address administration added or touched must compile to valid PHP. A Blade slip (an unclosed @if, a stray bracket) is
 * invisible until the page is opened, and compiling needs no database.
 */
class AdminBladeCompileTest extends TestCase
{
    private const ROOTS = [
        'resources/views/admin-views/address',
        'resources/views/admin-views/business-settings/delivery-restriction.blade.php',
        'resources/views/layouts/admin/partials/v2/_side-bar.blade.php',
    ];

    /** @return array<string, array{string}> */
    public static function templates(): array
    {
        $base = dirname(__DIR__, 3) . '/';
        $cases = [];
        foreach (self::ROOTS as $root) {
            $path = $base . $root;
            $files = is_file($path) ? [$path] : [];
            if (is_dir($path)) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
                    if (str_ends_with($file->getFilename(), '.blade.php')) {
                        $files[] = $file->getPathname();
                    }
                }
            }
            foreach ($files as $file) {
                $cases[str_replace($base, '', $file)] = [$file];
            }
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

    public function test_every_address_page_is_in_the_list(): void
    {
        $names = array_keys(self::templates());

        foreach (['index', 'regions', 'cities', 'city-form', 'legacy', 'import', 'not-migrated', 'partials/_tabs', 'partials/_zip-restriction-banner'] as $page) {
            $this->assertContains("resources/views/admin-views/address/{$page}.blade.php", $names, $page);
        }
        $this->assertContains('resources/views/layouts/admin/partials/v2/_side-bar.blade.php', $names);
    }
}
