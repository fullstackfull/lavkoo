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
 * storefront page, and an address that is not valid UTF-8 is classified like any other. The same holds for the API itself: only an address
 * whose first segment is "api" is an API request.
 */
class RequestAreaTest extends TestCase
{
    private const INSTALLED_IN_VENDOR_STORE = ['SCRIPT_NAME' => '/vendor-store/index.php', 'SCRIPT_FILENAME' => '/var/www/html/vendor-store/index.php', 'PHP_SELF' => '/vendor-store/index.php'];

    private function visit(string $uri, array $server = []): void
    {
        app()->instance('request', Request::create($uri, 'GET', [], [], [], $server));
    }

    public static function webPanelAddresses(): array
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
            'a panel address with a query that names a storefront page' => ['/admin/reviews?back=/vendor-shop/acme'],
            'a search in the admin panel for Arabic text' => ['/admin/products/search/%D8%AF%D9%88%D8%A7%D8%A1'],
            'an admin address with a Latin-1 letter' => ['/admin/search/caf%E9'],
            'an admin address that is not valid UTF-8' => ['/admin/products/search/%FF%FE'],
            'a vendor address that is not valid UTF-8' => ['/vendor/products/list/%E9'],
            'a panel address whose first segment is encoded' => ['/%61dmin/reviews'],
        ];
    }

    public static function sellerApiAddresses(): array
    {
        return [
            'seller API v2' => ['/api/v2/seller/products/list'],
            'API v2 root' => ['/api/v2'],
            'seller API v3' => ['/api/v3/seller/products/review-list/5'],
            'API v3 root' => ['/api/v3'],
            'a seller API v2 address that is not valid UTF-8' => ['/api/v2/seller/products/list/%FF'],
            'a seller API v3 address that is not valid UTF-8' => ['/api/v3/seller/products/list/%C0%AF'],
        ];
    }

    public static function panelAddresses(): array
    {
        return array_merge(self::webPanelAddresses(), self::sellerApiAddresses());
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
        $this->assertFalse(RequestArea::isWebPanel());
    }

    #[DataProvider('webPanelAddresses')]
    public function test_a_panel_opened_in_a_browser_is_a_web_panel(string $uri): void
    {
        $this->visit($uri);

        $this->assertTrue(RequestArea::isWebPanel());
    }

    #[DataProvider('sellerApiAddresses')]
    #[DataProvider('storefrontAddresses')]
    public function test_a_seller_api_and_any_other_address_are_not_a_web_panel(string $uri): void
    {
        $this->visit($uri);

        $this->assertFalse(RequestArea::isWebPanel());
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
            $this->assertFalse(RequestArea::isApi(), "{$address} is not an API request");
        }
    }

    public static function apiRequests(): array
    {
        return [
            'the customer API' => ['/api/v1/products/latest'],
            'the customer API of a seller shop' => ['/api/v1/seller/acme/products'],
            'the seller API v2' => ['/api/v2/seller/products/list'],
            'the seller API v3' => ['/api/v3/seller/products/list'],
            'the API root' => ['/api'],
            'an API of an outside provider' => ['/api/myfatoorah/webhook'],
            'an API address that is not valid UTF-8' => ['/api/v1/products/%FF'],
        ];
    }

    public static function pagesThatAreNotTheApi(): array
    {
        return [
            'the home page' => ['/'],
            'a product named after the API' => ['/product/api-gadget'],
            'a store installed in /api-shop' => ['/api-shop/'],
            'an address that only looks like the API' => ['/apis/v1/products'],
            'an address with the API in the middle' => ['/shop/api/v1/products'],
            'an address that names the API in its query' => ['/search?next=/api/v1/products'],
            'the admin panel' => ['/admin/reviews'],
            'the vendor panel' => ['/vendor/products/list'],
        ];
    }

    #[DataProvider('apiRequests')]
    public function test_everything_under_api_is_an_api_request(string $uri): void
    {
        $this->visit($uri);

        $this->assertTrue(RequestArea::isApi());
    }

    #[DataProvider('pagesThatAreNotTheApi')]
    public function test_any_other_address_is_not_an_api_request(string $uri): void
    {
        $this->visit($uri);

        $this->assertFalse(RequestArea::isApi());
    }

    public function test_an_api_request_below_the_folder_a_store_is_installed_in_is_still_an_api_request(): void
    {
        $this->visit('http://localhost/vendor-store/api/v1/products/latest', self::INSTALLED_IN_VENDOR_STORE);
        $this->assertTrue(RequestArea::isApi());

        $this->visit('http://localhost/vendor-store/', self::INSTALLED_IN_VENDOR_STORE);
        $this->assertFalse(RequestArea::isApi());
    }

    public static function substringTestsOnTheAddress(): array
    {
        return [
            'strpos on the current url' => ["if (strpos(url()->current(), '/api')) {"],
            'str_contains on the url of the request' => ["if (str_contains(request()->url(), '/admin')) {"],
            'Str::contains on the current url' => ["if (Str::contains(url()->current(), '/api')) {"],
            'str_contains on the full url' => ["if (str_contains(url()->full(), '/vendor')) {"],
            'str_contains on the full url of the request' => ["if (str_contains(request()->fullUrl(), '/seller')) {"],
            'strpos on the URL facade' => ["if (strpos(\\Illuminate\\Support\\Facades\\URL::current(), '/api')) {"],
            'strpos on the path of the request' => ["if (strpos(request()->path(), 'api') !== false) {"],
            'strpos on a variable that holds the url' => ["if (strpos(\$currentUrl, '/api')) {"],
            'preg_match on the current url' => ["if (preg_match('#/admin#', url()->current())) {"],
            'str_contains with a panel word that has no slash' => ["if (str_contains(url()->current(), 'api')) {"],
            'str_contains against the url of the store with the panel word added' => ["if (str_contains(url()->current(), url('/') . '/admin')) {"],
            'str_starts_with on the path of the request' => ["if (str_starts_with(\$request->path(), 'vendor')) {"],
            'Str::of on the current url' => ["if (Str::of(url()->current())->contains('/api')) {"],
            'a call that runs over several lines' => ["\$isSeller = str_contains(\n    request()->fullUrl(),\n    '/seller'\n);"],
        ];
    }

    public static function searchesThatAreNotAboutTheAddressOfTheRequest(): array
    {
        return [
            'a prefix test on a path that is already a path' => ["if (str_starts_with(\$path, 'api/')) {"],
            'a test of the previous url for a page that is no panel' => ["if (str_contains(url()->previous(), 'checkout-complete')) {"],
            'a test of the address for a page that is no panel' => ["if (str_contains(request()->url(), 'checkout-payment')) {"],
            'a test of the uri of a route' => ["if (Str::startsWith(\$route->uri(), 'admin')) {"],
            'a test of a file path' => ["if (str_contains(\$relativePath, 'admin-views/')) {"],
            'a test of a url that is not the one of the request' => ["if (str_contains(\$url, '/vendor-shop')) {"],
            'the whole segment test' => ['if (RequestArea::isPanel()) {'],
            'the anchored test of the framework' => ["if (request()->is('vendor/*')) {"],
        ];
    }

    #[DataProvider('substringTestsOnTheAddress')]
    public function test_the_guard_flags_a_substring_test_on_the_address(string $code): void
    {
        $this->assertSame(1, preg_match(self::substringTestOnTheAddress(), $code), "the guard does not flag: {$code}");
    }

    #[DataProvider('searchesThatAreNotAboutTheAddressOfTheRequest')]
    public function test_the_guard_leaves_a_test_that_is_not_about_the_address_of_the_request_alone(string $code): void
    {
        $this->assertSame(0, preg_match(self::substringTestOnTheAddress(), $code), "the guard flags: {$code}");
    }

    public function test_no_model_helper_or_middleware_tells_the_panel_or_the_api_by_a_substring_of_the_address(): void
    {
        $guard = self::substringTestOnTheAddress();
        $offences = [];
        foreach ($this->applicationSourceFiles() as $file) {
            $source = (string) file_get_contents($file);
            $this->assertNotFalse(preg_match_all($guard, $source, $found, PREG_OFFSET_CAPTURE), "the guard cannot read {$file}: " . preg_last_error_msg());

            foreach ($found[0] as [$code, $offset]) {
                $offences[] = str_replace(base_path() . '/', '', $file) . ':' . (substr_count($source, "\n", 0, $offset) + 1) . ' ' . preg_replace('/\s+/', ' ', $code);
            }
        }

        $this->assertSame([], $offences, 'Use RequestArea::isPanel() or RequestArea::isApi(): they match whole path segments.');
    }

    /**
     * A search of the address of the request for a panel word or "api", which takes /vendor-store, api.example.com and /product/api-gadget for the
     * panel or the API. It looks for the usual spellings: the address as the framework gives it or in a variable named after it, searched with a
     * string function, a pattern or a Stringable, for a needle that starts with a panel word (with or without its slash). It is a guard, not a
     * parser: a spelling it does not know is added here when one is found.
     */
    private static function substringTestOnTheAddress(): string
    {
        $address = <<<'REGEX'
            (?:
                url\(\)->(?:current|full)\(\)
              | \\?(?:Illuminate\\Support\\Facades\\)?(?:URL|Request)::(?:current|full|url|fullUrl|path|decodedPath)\(\)
              | (?:request\(\)|\$(?:this->)?request)->(?:url|fullUrl|path|decodedPath|getPathInfo|getRequestUri|getUri)\(\)
              | \$_SERVER\[\s*['"](?:REQUEST_URI|PATH_INFO)['"]\s*\]
              | \$(?:current|full|request)_?(?:url|uri|path)\b
            )
            REGEX;
        $needle = <<<'REGEX'
            (?:url\(\s*['"]/['"]\s*\)\s*\.\s*)?
            ['"]/?(?:api|admin|vendor|seller)
            REGEX;
        $pattern = <<<'REGEX'
            ['"][^'"]*(?:api|admin|vendor|seller)[^'"]*['"]
            REGEX;
        $searchFunction = <<<'REGEX'
            (?<![\w$>:])\\?
            (?:strpos|stripos|strrpos|strripos|strstr|stristr|str_contains|str_starts_with|str_ends_with|mb_strpos|mb_stripos|substr_count
              |(?:Illuminate\\Support\\)?Str::(?:contains|containsAll|startsWith|endsWith))
            REGEX;
        $patternFunction = <<<'REGEX'
            (?<![\w$>:])\\?(?:preg_match|preg_match_all|(?:Illuminate\\Support\\)?Str::is)
            REGEX;
        $stringable = <<<'REGEX'
            (?<![\w$>:])\\?(?:(?:Illuminate\\Support\\)?Str::of|str)
            REGEX;
        $stringableSearch = '(?:contains|containsAll|startsWith|endsWith|is|test|match)';

        return '~(?:'
            . "{$searchFunction}\\s*\\(\\s*{$address}\\s*,\\s*{$needle}"
            . "|{$patternFunction}\\s*\\(\\s*{$pattern}\\s*,\\s*{$address}"
            . "|{$stringable}\\(\\s*{$address}\\s*\\)\\s*->\\s*{$stringableSearch}\\s*\\(\\s*{$needle}"
            . ')~xi';
    }

    /** @return list<string> */
    private function applicationSourceFiles(): array
    {
        $files = [];
        foreach ([base_path('app'), base_path('Modules'), base_path('routes')] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $this->assertNotSame([], $files, 'no source file was found: the scan would pass on nothing');

        return $files;
    }
}
