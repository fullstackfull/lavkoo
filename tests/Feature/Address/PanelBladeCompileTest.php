<?php

namespace Tests\Feature\Address;

use Illuminate\Support\Facades\Blade;
use ParseError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every panel template the unified address touched must compile to valid PHP. A Blade slip (an unclosed @if, a stray bracket) is invisible
 * until the page is opened, and compiling needs no database.
 */
class PanelBladeCompileTest extends TestCase
{
    private const TEMPLATES = [
        'resources/views/admin-views/order/order-details.blade.php',
        'resources/views/admin-views/order/invoice.blade.php',
        'resources/views/admin-views/order/partials/_invoice-address-rows.blade.php',
        'resources/views/vendor-views/order/order-details.blade.php',
        'resources/views/vendor-views/order/invoice.blade.php',
        'resources/views/admin-views/pos/order/order-details.blade.php',
        'resources/views/vendor-views/pos/order/order-details.blade.php',
        'resources/views/admin-views/customer/customer-view.blade.php',
        'resources/views/admin-views/pos/partials/modals/_add-customer.blade.php',
        'resources/views/admin-views/pos/partials/offcanvas/_add-new-customer-offcanvas.blade.php',
        'resources/views/vendor-views/pos/partials/modals/_add-customer.blade.php',
        'resources/views/vendor-views/pos/partials/offcanvas/_add-new-customer-offcanvas.blade.php',
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
}
