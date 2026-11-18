<?php

namespace Tests\Feature\Security;

use App\Utils\RequestArea;
use FilesystemIterator;
use Illuminate\Http\Request;
use PhpParser\Error as PhpParserError;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\AddressSubstringGuard;
use Tests\TestCase;

/**
 * The panels and the seller APIs are told from the storefront and the customer API by the segments of the path, whole segments only:
 * the panel words and every API version are matched exactly, so an address that merely starts with, ends with or contains them is a
 * storefront page, and an address that is not valid UTF-8 is classified like any other. The same holds for the API itself: only an address
 * whose first segment is "api" is an API request.
 */
class RequestAreaTest extends TestCase
{
    /** Compiles to PHP that does not parse (translate(\'remove_image\') inside {{ }}), so it cannot be rendered, and no controller renders it. */
    private const VIEWS_THAT_COMPILE_TO_INVALID_PHP = ['resources/views/admin-views/product/edit.blade.php'];

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
            'strpos on the current url' => ["if (strpos(url()->current(), '/api')) { }"],
            'str_contains on the url of the request' => ["if (str_contains(request()->url(), '/admin')) { }"],
            'Str::contains on the current url' => ["if (Str::contains(url()->current(), '/api')) { }"],
            'str_contains on the full url' => ["if (str_contains(url()->full(), '/vendor')) { }"],
            'str_contains on the full url of the request' => ["if (str_contains(request()->fullUrl(), '/seller')) { }"],
            'strpos on the URL facade' => ["if (strpos(\\Illuminate\\Support\\Facades\\URL::current(), '/api')) { }"],
            'strpos on the path of the request' => ["if (strpos(request()->path(), 'api') !== false) { }"],
            'strpos on a variable that holds the url' => ["if (strpos(\$currentUrl, '/api')) { }"],
            'preg_match on the current url' => ["if (preg_match('#/admin#', url()->current())) { }"],
            'str_contains with a panel word that has no slash' => ["if (str_contains(url()->current(), 'api')) { }"],
            'str_contains against the url of the store with the panel word added' => ["if (str_contains(url()->current(), url('/') . '/admin')) { }"],
            'str_starts_with on the path of the request' => ["if (str_starts_with(\$request->path(), 'vendor')) { }"],
            'Str::of on the current url' => ["if (Str::of(url()->current())->contains('/api')) { }"],
            'a call that runs over several lines' => ["\$isSeller = str_contains(\n    request()->fullUrl(),\n    '/seller'\n);"],
            'several needles in an array' => ["if (Str::contains(url()->current(), ['/admin', '/vendor', '/seller'])) { }"],
            'a haystack with something added' => ["if (str_contains(url()->current() . '/', '/admin/')) { }"],
            'a haystack with the store url taken off' => ["if (strpos(str_replace(url('/'), '', url()->current()), '/admin') === 0) { }"],
            'a needle made by url()' => ["if (strpos(url()->current(), url('admin'))) { }"],
            'the request in a variable called req' => ["if (str_contains(\$req->url(), '/admin')) { }"],
            'the request held by the object' => ["if (str_contains(\$this->request->path(), 'admin')) { }"],
            'the request uri read from the server bag' => ["if (str_contains(request()->server('REQUEST_URI'), '/api')) { }"],
            'the request uri read from the server bag of a variable' => ["if (str_contains(\$request->server->get('REQUEST_URI'), '/vendor')) { }"],
            'the request uri of the server array' => ["if (strpos(\$_SERVER['REQUEST_URI'], '/api') !== false) { }"],
            'the start of the path compared with a panel word' => ["if (substr(request()->path(), 0, 5) === 'admin') { }"],
            'strncmp on the path' => ["if (strncmp(request()->path(), 'admin', 5) === 0) { }"],
            'explode on the panel word' => ["if (count(explode('/admin', url()->current())) > 1) { }"],
            'a pattern without anchors' => ["if (preg_match('/admin/', request()->url())) { }"],
            'a pattern with alternatives' => ["if (preg_match('~/(admin|vendor)~', url()->current()) === 1) { }"],
            'a wildcard on both sides of the request' => ["if (request()->is('*admin*')) { }"],
            'a wildcard of the facade' => ["if (Request::is('*/vendor/*')) { }"],
            'a wildcard of the full url' => ["if (request()->fullUrlIs('*/api/*')) { }"],
            'a wildcard of the string helper' => ["if (Str::is('*/admin/*', url()->current())) { }"],
            'a wildcard of fnmatch' => ["if (fnmatch('*/admin/*', request()->path())) { }"],
            'the request uri of the request' => ["if (str_contains(request()->getRequestUri(), '/api/v3')) { }"],
            'the path info of the request' => ["if (str_contains(request()->getPathInfo(), 'seller')) { }"],
            'the uri of a request variable' => ["if (str_contains(\$request->getUri(), '/admin')) { }"],
            'the full url of the facade' => ["if (str_contains(\\Illuminate\\Support\\Facades\\Request::fullUrl(), '/admin')) { }"],
            'the store url plus the panel word' => ["if (strpos(url()->current(), url('/') . '/vendor')) { }"],
            'a haystack held in a variable assigned from the url' => ["\$url = url()->current(); if (str_contains(\$url, '/admin')) { }"],
            'a haystack held in a variable assigned from the request uri' => ["\$uri = request()->getRequestUri(); if (strpos(\$uri, '/api') !== false) { }"],
            'a haystack held in a variable assigned from the path' => ["\$path = request()->path(); if (strpos(\$path, 'admin') !== false) { }"],
            'a haystack assigned in a method' => ["class Gate { public function isPanel() { \$address = url()->current(); return str_contains(\$address, '/vendor'); } }"],
            'a search in a ternary' => ["\$isApi = strpos(Request::url(), '/api') ? true : false;"],
            'a haystack with the slash trimmed' => ["if (str_starts_with(ltrim(request()->path(), '/'), 'admin')) { }"],
            'a haystack in lower case' => ["if (str_contains(strtolower(request()->url()), '/admin')) { }"],
            'a needle in a variable' => ["\$needle = '/admin'; if (str_contains(url()->current(), \$needle)) { }"],
            'a needle in a constant' => ["if (str_contains(url()->current(), self::ADMIN_PREFIX)) { }"],
            'named arguments of Str::contains' => ["if (Str::contains(haystack: url()->current(), needles: '/admin')) { }"],
            'named arguments of str_contains' => ["if (str_contains(haystack: request()->url(), needle: '/admin')) { }"],
            'named arguments in the other order' => ["if (str_contains(needle: '/admin', haystack: request()->url())) { }"],
            'a string with calls in between' => ["if (Str::of(request()->url())->lower()->contains('/admin')) { }"],
            'the str helper' => ["if (str(url()->current())->startsWith('/api')) { }"],
            'a match arm' => ["\$area = match (true) { str_contains(url()->current(), '/admin') => 'admin', default => 'shop' };"],
            'a helper that returns the current url' => ["if (str_contains(Helpers::currentUrl(), '/admin')) { }"],
            'an offset' => ["if (strpos(request()->url(), '/api', 8) !== false) { }"],
            'the full url with a slash after the panel word' => ["if (str_contains(url()->full(), '/api/')) { }"],
        ];
    }

    public static function searchesThatAreNotAboutTheAddressOfTheRequest(): array
    {
        return [
            'a prefix test on a path that is already a path' => ["if (str_starts_with(\$path, 'api/')) { }"],
            'a test of the previous url for a page that is no panel' => ["if (str_contains(url()->previous(), 'checkout-complete')) { }"],
            'a test of the address for a page that is no panel' => ["if (str_contains(request()->url(), 'checkout-payment')) { }"],
            'a test of the uri of a route' => ["if (Str::startsWith(\$route->uri(), 'admin')) { }"],
            'a test of a file path' => ["if (str_contains(\$relativePath, 'admin-views/')) { }"],
            'a test of a url that is not the one of the request' => ["if (str_contains(\$url, '/vendor-shop')) { }"],
            'the words in a message' => ["if (str_contains(\$message, 'admin')) { }"],
            'the whole segment test' => ['if (RequestArea::isPanel()) { }'],
            'the anchored test of the framework' => ["if (request()->is('vendor/*')) { }"],
            'the anchored test of the string helper' => ["if (Str::is('admin/*', request()->path())) { }"],
            'a whole segment in a list' => ["if (in_array(request()->segment(1), ['admin', 'vendor'], true)) { }"],
            'a whole segment compared' => ["if (request()->segment(1) === 'admin') { }"],
            'a route name pattern' => ["if (request()->routeIs('admin.*')) { }"],
            'a variable of another method' => ["class Gate { public function a() { \$url = url()->current(); } public function b(\$url) { return str_contains(\$url, '/admin'); } }"],
            'a variable that holds something else by then' => ["\$url = url()->current(); \$url = \$route->uri(); if (str_contains(\$url, '/admin')) { }"],
            'a word that merely contains a panel word' => ["if (str_contains(url()->current(), 'capital')) { }"],
        ];
    }

    public static function bladeTestsOnTheAddress(): array
    {
        return [
            'a condition on the current url' => ["@if(str_contains(url()->current(), '/vendor'))\n    <b>panel</b>\n@endif"],
            'a condition on the url of the facade' => ["@if(strpos(Request::url(), 'admin'))\n    <b>panel</b>\n@endif"],
            'a value in an echo' => ["{{ str_contains(request()->url(), '/api') ? 'api' : 'web' }}"],
            'a wildcard of the request' => ["@if(request()->is('*/admin/*'))\n    <b>panel</b>\n@endif"],
            'a variable set in a php block' => ["@php(\$here = url()->current())\n@if(str_contains(\$here, '/seller'))\n    <b>panel</b>\n@endif"],
        ];
    }

    public static function bladeThatIsNotAboutTheAddressOfTheRequest(): array
    {
        return [
            'the anchored test of the framework' => ["@if(request()->is('vendor/*'))\n    <b>panel</b>\n@endif"],
            'the address printed' => ['<a href="{{ request()->url() }}">{{ url()->current() }}</a>'],
            'the whole segment test' => ["@if(\\App\\Utils\\RequestArea::isPanel())\n    <b>panel</b>\n@endif"],
        ];
    }

    #[DataProvider('substringTestsOnTheAddress')]
    public function test_the_guard_flags_a_substring_test_on_the_address(string $code): void
    {
        $this->assertNotSame([], AddressSubstringGuard::offencesInPhp("<?php {$code}"), "the guard does not flag: {$code}");
    }

    #[DataProvider('searchesThatAreNotAboutTheAddressOfTheRequest')]
    public function test_the_guard_leaves_a_test_that_is_not_about_the_address_of_the_request_alone(string $code): void
    {
        $this->assertSame([], AddressSubstringGuard::offencesInPhp("<?php {$code}"), "the guard flags: {$code}");
    }

    #[DataProvider('bladeTestsOnTheAddress')]
    public function test_the_guard_flags_a_substring_test_on_the_address_in_a_view(string $view): void
    {
        $this->assertNotSame([], AddressSubstringGuard::offencesInBlade($view), "the guard does not flag: {$view}");
    }

    #[DataProvider('bladeThatIsNotAboutTheAddressOfTheRequest')]
    public function test_the_guard_leaves_a_view_that_is_not_about_the_address_of_the_request_alone(string $view): void
    {
        $this->assertSame([], AddressSubstringGuard::offencesInBlade($view), "the guard flags: {$view}");
    }

    public function test_the_guard_reports_the_line_and_the_code_of_the_offence(): void
    {
        $offences = AddressSubstringGuard::offencesInPhp("<?php\n\n\$isApi = str_contains(\n    request()->url(),\n    '/api'\n);");

        $this->assertSame([['line' => 3, 'code' => "str_contains(request()->url(), '/api')"]], $offences);
    }

    public function test_no_code_of_the_application_tells_the_panel_or_the_api_by_a_substring_of_the_address(): void
    {
        $offences = [];
        $unreadable = [];
        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);
            $name = str_replace(base_path() . '/', '', $file);

            try {
                $found = str_ends_with($file, '.blade.php') ? AddressSubstringGuard::offencesInBlade($source) : AddressSubstringGuard::offencesInPhp($source);
            } catch (PhpParserError $error) {
                if (!in_array($name, self::VIEWS_THAT_COMPILE_TO_INVALID_PHP, true)) {
                    $unreadable[] = "{$name}: {$error->getMessage()}";
                }
                continue;
            }

            foreach ($found as ['line' => $line, 'code' => $code]) {
                $offences[] = "{$name}:{$line} {$code}";
            }
        }

        $this->assertSame([], $unreadable, 'the guard could not read these files, so it would pass on them');
        $this->assertSame([], $offences, 'Use RequestArea::isPanel() or RequestArea::isApi(): they match whole path segments.');
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];
        foreach ([base_path('app'), base_path('Modules'), base_path('routes'), base_path('resources')] as $directory) {
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
