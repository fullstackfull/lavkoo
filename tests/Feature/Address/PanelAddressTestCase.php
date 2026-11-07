<?php

namespace Tests\Feature\Address;

use App\Events\CustomerRegistrationEvent;
use App\Events\OrderStatusEvent;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Http\Middleware\SellerMiddleware;
use App\Http\Middleware\SellerStaffAccessMiddleware;
use App\Models\Admin;
use App\Models\Seller;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsPanelAddressSchema;

/**
 * Base for the admin and vendor panel address tests: the address book with its real migrations, the catalog of seedAddressCatalog(), the
 * few store tables the panel pages read, the panels' heavy request middleware switched off, and no way out to a real provider or mailbox.
 *
 * translate() appends a key it does not know to the tracked language files; the files are put back after every test so a run leaves the
 * working tree as it found it.
 */
abstract class PanelAddressTestCase extends AddressTestCase
{
    use BuildsPanelAddressSchema;

    /** @var array<string, int> */
    protected array $ids;

    /** @var array<string, string> */
    private array $languageFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['en', 'sa', 'ae'] as $locale) {
            $path = base_path("resources/lang/{$locale}/new-messages.php");
            $this->languageFiles[$path] = (string) file_get_contents($path);
        }

        Http::preventStrayRequests();
        Event::fake([OrderStatusEvent::class, CustomerRegistrationEvent::class]);
        View::getFinder()->prependLocation(base_path('tests/Support/panel-views'));
        $this->withoutMiddleware([
            AdminMiddleware::class, SellerMiddleware::class, SellerStaffAccessMiddleware::class, ActivationCheckMiddleware::class,
            MaintenanceModeMiddleware::class, ModulePermissionMiddleware::class, Localization::class, DetectMobile::class,
            ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);

        $this->createPanelStoreTables();
        $this->ids = $this->seedAddressCatalog();
    }

    protected function tearDown(): void
    {
        foreach ($this->languageFiles as $path => $contents) {
            file_put_contents($path, $contents);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($path, true);
            }
        }

        parent::tearDown();
    }

    protected function actingAsAdmin(int $id = 1): static
    {
        return $this->actingAs((new Admin())->forceFill(['id' => $id, 'name' => 'Master Admin']), 'admin');
    }

    protected function actingAsVendor(int $sellerId): static
    {
        return $this->actingAs((new Seller())->forceFill(['id' => $sellerId, 'f_name' => 'Vendor']), 'seller');
    }

    /** @return list<string> the kinds of toast (success, error...) the last response asked the next page to show */
    protected function toastTypes(): array
    {
        return array_column(session('laravel-toaster-magic::messages', []), 'type');
    }
}
