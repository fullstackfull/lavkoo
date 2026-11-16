<?php

namespace Tests\Feature\Security;

use App\Utils\RequestArea;
use FilesystemIterator;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The panels and the seller APIs are told from the storefront and the customer API by the segments of the path, whole segments only:
 * the panel words and every API version are matched exactly, so an address that merely starts with, ends with or contains them is a
 * storefront page, and an address that is not valid UTF-8 is classified like any other.
 */
class RequestAreaTest extends TestCase
{
    private const INSTALLED_IN_VENDOR_STORE = ['SCRIPT_NAME' => '/vendor-store/index.php', 'SCRIPT_FILENAME' => '/var/www/html/vendor-store/index.php', 'PHP_SELF' => '/vendor-store/index.php'];

    /** A test of the address by substring, which takes /vendor-store, /vendor-shop and /product/seller-bag for a panel. */
    private const SUBSTRING_TEST_ON_THE_ADDRESS = '/\b(?:strpos|stripos|str_contains)\s*\(\s*(?:url\(\)->current\(\)|(?:request\(\)|\$request)->url\(\))\s*,\s*(?:url\(\s*[\'"]\/[\'"]\s*\)\s*\.\s*)?[\'"]\/(?:admin|vendor|seller)/';

    private function visit(string $uri, array $server = []): void
    {
        app()->instance('request', Request::create($uri, 'GET', [], [], [], $server));
    }

    public static function panelAddresses(): array
    {
        return [
            'admin panel' => ['/admin/reviews'],
            'admin root' => ['/admin'],
            'admin root with a slash' => ['/admin/'],
            'vendor panel' => ['/vendor/products/list'],
            'vendor root' => ['/vendor'],
            'vendor login' => ['/vendor/auth/login'],
            'vendor password reset' => ['/vendor/auth/forgot-password/index'],
            'seller area' => ['/seller/dashboard'],
            'seller root' => ['/seller'],
            'seller API v2' => ['/api/v2/seller/products/list'],
            'API v2 root' => ['/api/v2'],
            'seller API v3' => ['/api/v3/seller/products/review-list/5'],
            'API v3 root' => ['/api/v3'],
            'a panel address with a query that names a storefront page' => ['/admin/reviews?back=/vendor-shop/acme'],
            'a search in the admin panel for Arabic text' => ['/admin/products/search/%D8%AF%D9%88%D8%A7%D8%A1'],
            'an admin address with a Latin-1 letter' => ['/admin/search/caf%E9'],
            'an admin address that is not valid UTF-8' => ['/admin/products/search/%FF%FE'],
            'a vendor address that is not valid UTF-8' => ['/vendor/products/list/%E9'],
            'a seller API v2 address that is not valid UTF-8' => ['/api/v2/seller/products/list/%FF'],
            'a seller API v3 address that is not valid UTF-8' => ['/api/v3/seller/products/list/%C0%AF'],
            'a panel address whose first segment is encoded' => ['/%61dmin/reviews'],
        ];
    }

    public static function storefrontAddresses(): array
    {
        return [
            'the home page' => ['/'],
            'a product page' => ['/product/gadget'],
            'the shop page of a vendor' => ['/vendor-shop/acme'],
            'the vendor list' => ['/vendors'],
            'a seller profile' => ['/seller-profile/3'],
            'an address that only looks like the admin one' => ['/administrators'],
            'a product named after a seller' => ['/product/seller-bag'],
            'a product named after the API' => ['/product/api-gadget'],
            'a panel word that is not the first segment' => ['/shop/vendor/acme'],
            'a storefront page that names a panel in its query' => ['/search?q=/admin/orders'],
            'a storefront page that is not valid UTF-8' => ['/shop/caf%E9'],
            'a vendor shop address that is not valid UTF-8' => ['/vendor-shop/%FF'],
            'the customer API' => ['/api/v1/products/reviews/acme'],
            'the customer API of a seller shop' => ['/api/v1/seller/acme/products'],
            'the API root' => ['/api'],
            'the API of an outside provider' => ['/api/myfatoorah/webhook'],
            'an address that only looks like the API' => ['/apis/v2/seller'],
            'the public vendor registration page' => ['/vendor/auth/registration/index'],
            'the public vendor registration form' => ['/vendor/auth/registration/add'],
            'the public vendor registration page without its last segment' => ['/vendor/auth/registration'],
        ];
    }

    public static function panelRoots(): array
    {
        return array_map(fn (string $root): array => [$root], RequestArea::PANEL_ROOTS);
    }

    public static function sellerApiVersions(): array
    {
        return array_map(fn (string $version): array => [$version], RequestArea::SELLER_API_VERSIONS);
    }

    #[DataProvider('panelAddresses')]
    public function test_a_panel_or_a_seller_api_is_a_panel(string $uri): void
    {
        $this->visit($uri);

        $this->assertTrue(RequestArea::isPanel());
    }

    #[DataProvider('storefrontAddresses')]
    public function test_any_other_address_is_not_a_panel(string $uri): void
    {
        $this->visit($uri);

        $this->assertFalse(RequestArea::isPanel());
    }

    public function test_a_command_line_run_is_not_a_panel(): void
    {
        $this->assertFalse(RequestArea::isPanel());
    }

    #[DataProvider('panelRoots')]
    public function test_every_panel_root_is_matched_as_a_whole_segment(string $root): void
    {
        foreach (["/{$root}", "/{$root}/", "/{$root}/anything/below", "/{$root}/%FF"] as $panel) {
            $this->visit($panel);
            $this->assertTrue(RequestArea::isPanel(), "{$panel} is the panel");
        }

        foreach (["/{$root}s", "/{$root}-shop/acme", "/{$root}x/page", "/x{$root}", "/x{$root}/page", "/shop/{$root}", "/shop/{$root}/acme", "/{$root}s/%FF", "/shop/{$root}/%FF"] as $storefront) {
            $this->visit($storefront);
            $this->assertFalse(RequestArea::isPanel(), "{$storefront} only looks like the panel");
        }
    }

    #[DataProvider('sellerApiVersions')]
    public function test_every_seller_api_version_is_matched_as_a_whole_segment(string $version): void
    {
        foreach (["/api/{$version}", "/api/{$version}/", "/api/{$version}/seller/products/list", "/api/{$version}/%FF"] as $sellerApi) {
            $this->visit($sellerApi);
            $this->assertTrue(RequestArea::isPanel(), "{$sellerApi} is a seller API");
        }

        foreach (["/api/{$version}0/seller", "/api/{$version}-beta", "/api/{$version}x/seller", "/api/x{$version}/seller", "/api/{$version}0/%FF", "/api-{$version}/seller", "/apis/{$version}/seller", "/xapi/{$version}/seller", "/{$version}/seller", "/shop/api/{$version}/seller", "/shop/api/{$version}/%FF"] as $storefront) {
            $this->visit($storefront);
            $this->assertFalse(RequestArea::isPanel(), "{$storefront} only looks like a seller API");
        }
    }

    public function test_the_customer_api_is_not_a_seller_api(): void
    {
        $this->assertNotContains('v1', RequestArea::SELLER_API_VERSIONS);

        foreach (['v1', 'v10', 'v2.0'] as $version) {
            $this->visit("/api/{$version}/seller/products/list");
            $this->assertFalse(RequestArea::isPanel(), "/api/{$version} is not one of the versions the seller apps use");
        }
    }

    public function test_only_the_vendor_registration_page_is_a_public_page_under_the_vendor_panel(): void
    {
        foreach (['/vendor/auth', '/vendor/auth/registration-bonus', '/vendor/auth/registrations/index', '/vendor/registration/index', '/vendor/auth/login/registration', '/seller/auth/registration/index', '/admin/auth/registration/index'] as $address) {
            $this->visit($address);
            $this->assertTrue(RequestArea::isPanel(), "{$address} is still a panel address");
        }
    }

    public function test_the_decision_ignores_the_folder_the_store_is_installed_in(): void
    {
        foreach (['/vendor-store/admin/reviews', '/vendor-store/vendor/products/list', '/vendor-store/api/v2/seller/products/list', '/vendor-store/api/v3'] as $panel) {
            $this->visit("http://localhost{$panel}", self::INSTALLED_IN_VENDOR_STORE);
            $this->assertTrue(RequestArea::isPanel(), "{$panel} is the panel of a store installed in /vendor-store");
        }

        foreach (['/vendor-store/', '/vendor-store/vendor-shop/acme', '/vendor-store/api/v1/products/latest', '/vendor-store/vendor/auth/registration/index'] as $storefront) {
            $this->visit("http://localhost{$storefront}", self::INSTALLED_IN_VENDOR_STORE);
            $this->assertFalse(RequestArea::isPanel(), "{$storefront} is a storefront page of a store installed in /vendor-store");
        }
    }

    public function test_the_host_is_not_part_of_the_decision(): void
    {
        foreach (['http://vendor.example.com/', 'http://admin.example.com/product/gadget', 'http://api.example.com/', 'http://seller.example.com/vendors'] as $address) {
            $this->visit($address);
            $this->assertFalse(RequestArea::isPanel(), "{$address} is a storefront page");
        }
    }

    public function test_no_model_tells_a_panel_by_a_substring_of_the_address(): void
    {
        $offences = [];
        foreach ($this->applicationSourceFiles() as $file) {
            foreach (file($file) as $number => $line) {
                if (preg_match(self::SUBSTRING_TEST_ON_THE_ADDRESS, $line) === 1) {
                    $offences[] = str_replace(base_path() . '/', '', $file) . ':' . ($number + 1) . ' ' . trim($line);
                }
            }
        }

        $this->assertSame([], $offences, 'Use RequestArea::isPanel(): it matches whole path segments.');
    }

    /** @return list<string> */
    private function applicationSourceFiles(): array
    {
        $directories = array_merge([base_path('app')], glob(base_path('Modules/*/app'), GLOB_ONLYDIR));

        $files = [];
        foreach ($directories as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
