<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\Route;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * B-07, the screen half: the copy control of the admin product gallery.
 *
 * It is the whole of the copy "form", and what it does NOT hold is the point — there is no stock field and no price field, so neither can be
 * carried across unflagged; the control says what the copy starts without. While the markets are off it renders nothing at all, so the gallery
 * card is byte for byte the card of today.
 */
class ProductCopyControlTest extends TestCase
{
    use BuildsCodeScopeCatalog, RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCodeScopeProducts();
        $this->buildCodeScopeRelationTables();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    public function test_the_control_draws_nothing_while_the_markets_are_off(): void
    {
        $this->assertSame('', $this->control(), 'not one byte: the gallery card must be the card of today');
    }

    public function test_the_control_draws_nothing_before_the_country_column_exists(): void
    {
        $this->trackCountries();
        $this->dropStorefrontCountryColumn();

        $this->assertSame('', $this->control(), 'not one byte');
    }

    public function test_the_control_offers_every_country_but_the_products_own(): void
    {
        $this->trackCountries();

        $control = $this->control(country: 'SA');

        $this->assertStringContainsString('value="KW"', $control);
        $this->assertStringNotContainsString('value="SA"', $control, 'a copy into the source country is not a copy');
    }

    public function test_the_control_posts_to_the_copy_action_for_that_product(): void
    {
        $this->trackCountries();

        $control = $this->control();

        $this->assertStringContainsString(route('admin.products.copy-to-country', ['id' => 1]), $control);
        $this->assertStringContainsString('method="post"', $control);
        $this->assertStringContainsString('name="storefront_country"', $control);
    }

    public function test_the_control_has_no_stock_field_and_no_price_field(): void
    {
        $this->trackCountries();

        $control = $this->control();

        foreach (['current_stock', 'unit_price', 'purchase_price', 'discount'] as $field) {
            $this->assertStringNotContainsString($field, $control, 'the copy form must not carry the source\'s ' . $field);
        }
    }

    public function test_the_control_says_what_the_copy_starts_without(): void
    {
        $this->trackCountries();

        $this->assertStringContainsString(
            translate('the_copy_starts_with_no_stock_and_no_price_and_is_not_published_until_you_set_them'),
            $this->control(),
        );
    }

    public function test_the_copy_action_is_a_post_route_of_the_admin_product_controller(): void
    {
        $route = Route::getRoutes()->getByName('admin.products.copy-to-country');

        $this->assertNotNull($route);
        $this->assertContains('POST', $route->methods());
        $this->assertStringEndsWith('ProductController@copyToCountry', $route->getActionName());
    }

    public function test_the_copy_action_is_behind_the_same_guards_as_the_rest_of_the_product_screens(): void
    {
        $middleware = Route::getRoutes()->getByName('admin.products.copy-to-country')->gatherMiddleware();

        foreach (['admin', 'actch:admin_panel', 'module:catalog'] as $guard) {
            $this->assertContains($guard, $middleware, 'a copy makes a product row: it is for an admin of the catalogue module');
        }
    }

    public function test_every_message_the_control_and_the_action_show_exists_in_every_language_file(): void
    {
        $keys = [
            'copy_to_country',
            'copy_this_product_to_another_country',
            'the_copy_starts_with_no_stock_and_no_price_and_is_not_published_until_you_set_them',
            'the_copy_was_made_without_the_stock_or_the_price_of_the_original_set_them_here',
            'choose_the_country_to_copy_this_product_into',
            'this_product_is_already_in_that_country',
            'copying_a_product_to_another_country_is_not_available_yet',
            'a_variation_sku_the_target_country_already_uses_was_cleared_on_the_copy',
            'an_image_file_could_not_be_read_and_was_not_copied',
        ];

        foreach (['en', 'sa', 'ae'] as $locale) {
            $messages = require base_path("resources/lang/{$locale}/new-messages.php");
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $messages, "{$key} is missing from {$locale}");
            }
        }
    }

    private function trackCountries(): void
    {
        $this->scopeCodesByCountry();
        config(['gcc.storefronts.enabled' => true]);
        StorefrontCatalog::flush();
    }

    private function control(string $country = 'SA'): string
    {
        $this->insertCodedProduct(code: 'SKU100', country: $country, extra: ['id' => 1]);
        $product = Product::query()->findOrFail(1);

        return view('admin-views.product.partials._copy-to-country', ['product' => $product])->render();
    }
}
