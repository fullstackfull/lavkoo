<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\PaymentMode;
use App\Enums\Fulfillment\ProviderCode;
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
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\Providers\M5azn\M5aznAdapter;
use App\Services\Fulfillment\Providers\TorodPoint\TorodPointAdapter;
use Illuminate\Support\Facades\View;

/**
 * Base for the admin-page tests: real adapters, both providers configured with distinctive secrets, a
 * signed-in master admin, and the project's heavy request middleware switched off.
 *
 * The admin SHELL (layouts.admin.app) needs the installation database, so a stand-in shell is used; the
 * pages under test are the real templates and the real controllers.
 */
abstract class AdminPagesTestCase extends FulfillmentTestCase
{
    protected const SECRETS = [
        'm5azn_email' => 'm5-seller@secret.test',
        'm5azn_password' => 'm5-pass-SECRET-1',
        'torod_username' => 'wh-user-SECRET',
        'torod_password' => 'wh-pass-SECRET-9',
        'lastmile_email' => 'lm-user@secret.test',
        'lastmile_password' => 'lm-pass-SECRET-9',
        'webhook_secret' => 'hook-secret-abcdef-1234',
    ];

    /** @var array<string, string> */
    private array $languageFileHashes = [];

    protected function setUp(): void
    {
        parent::setUp();

        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        View::addLocation(resource_path('themes/default'));

        config([
            'fulfillment.providers.m5azn.adapter' => M5aznAdapter::class,
            'fulfillment.providers.torod_point.adapter' => TorodPointAdapter::class,
        ]);

        $this->withoutMiddleware([
            AdminMiddleware::class, ActivationCheckMiddleware::class, ModulePermissionMiddleware::class,
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        $this->actingAs(Admin::findOrFail(1), 'admin');

        $this->enableProvider(ProviderCode::M5azn, [], [
            'email' => self::SECRETS['m5azn_email'], 'password' => self::SECRETS['m5azn_password'],
        ]);
        $this->enableProvider(ProviderCode::TorodPoint, ['company_id' => '714'], [
            'fulfillment_username' => self::SECRETS['torod_username'], 'fulfillment_password' => self::SECRETS['torod_password'],
            'lastmile_email' => self::SECRETS['lastmile_email'], 'lastmile_password' => self::SECRETS['lastmile_password'],
            'webhook_secret' => self::SECRETS['webhook_secret'],
        ]);

        foreach (['en', 'sa', 'ae'] as $locale) {
            $this->languageFileHashes[$locale] = (string) md5_file(base_path("resources/lang/{$locale}/new-messages.php"));
        }
    }

    protected function tearDown(): void
    {
        // translate() writes an unknown key into the language file. A page that shows an untranslated
        // phrase would otherwise pass silently and leave the repository dirty.
        foreach ($this->languageFileHashes as $locale => $hash) {
            $this->assertSame($hash, md5_file(base_path("resources/lang/{$locale}/new-messages.php")), "rendering added an untranslated key to resources/lang/{$locale}/new-messages.php");
        }

        parent::tearDown();
    }

    /** A provider shipment with one line, linked, in the given state. */
    protected function shipment(int $orderId, ProviderCode $provider, FulfillmentStatus $status, array $overrides = [], int $productId = 1): ProviderFulfillment
    {
        \Illuminate\Support\Facades\DB::table('products')->where('id', $productId)->exists() || $this->makeProduct($productId, $provider);
        \Illuminate\Support\Facades\DB::table('orders')->where('id', $orderId)->exists() || $this->makeOrder($orderId);
        $this->addLine($orderId, $productId, 2, 60, $provider);

        $fulfillment = ProviderFulfillment::create($overrides + [
            'reference' => $orderId . '-' . $provider->referenceCode() . '1',
            'order_id' => $orderId, 'provider' => $provider->value, 'generation' => 1,
            'idempotency_key' => hash('sha256', $orderId . $provider->value . microtime()),
            'status' => $status, 'payment_mode' => PaymentMode::Prepaid,
        ]);
        ProviderFulfillmentItem::where('order_id', $orderId)->where('provider', $provider->value)
            ->update(['provider_fulfillment_id' => $fulfillment->id, 'status' => ProviderFulfillmentItem::STATUS_ROUTED]);

        return $fulfillment;
    }
}
