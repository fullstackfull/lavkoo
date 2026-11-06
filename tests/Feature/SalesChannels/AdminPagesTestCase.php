<?php

namespace Tests\Feature\SalesChannels;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/**
 * Base for the tests of the sales-channel and operational-cost admin pages: the real controllers and templates, a signed-in master admin, the project's heavy request
 * middleware switched off, and a stand-in admin shell (the real layout needs the installation database).
 *
 * Every test ends by checking that rendering added no key to the language files: translate() writes an unknown key into resources/lang/{locale}/new-messages.php, so a
 * page that shows an untranslated phrase would otherwise pass silently and leave the repository dirty.
 */
abstract class AdminPagesTestCase extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    /** @var array<string, string> */
    private array $languageFileHashes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        View::addLocation(resource_path('themes/default'));
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => false]);
        Http::preventStrayRequests();
        Cache::flush();

        $this->withoutMiddleware([
            AdminMiddleware::class, ActivationCheckMiddleware::class, ModulePermissionMiddleware::class,
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        $this->actingAs(Admin::findOrFail(1), 'admin');

        foreach (['en', 'sa', 'ae'] as $locale) {
            $this->languageFileHashes[$locale] = (string) md5_file(base_path("resources/lang/{$locale}/new-messages.php"));
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->languageFileHashes as $locale => $hash) {
            $this->assertSame($hash, md5_file(base_path("resources/lang/{$locale}/new-messages.php")), "rendering added an untranslated key to resources/lang/{$locale}/new-messages.php");
        }

        parent::tearDown();
    }
}
