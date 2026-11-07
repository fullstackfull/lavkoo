<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Address\CityMatchStatus;
use App\Enums\Fulfillment\CitySyncVerdict;
use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\PaymentMode;
use App\Enums\Fulfillment\ProviderCode;
use App\Enums\Fulfillment\StockSource;
use App\Services\Address\AddressSchema;
use App\Services\Fulfillment\Data\CapabilityState;
use App\Services\Fulfillment\Data\CityCandidate;
use App\Services\Fulfillment\Data\CityResolution;
use App\Services\Fulfillment\FieldMapDefinitions;
use App\Services\Fulfillment\FulfillmentAggregator;
use App\Services\Fulfillment\Providers\M5azn\M5aznAdapter;
use App\Services\Fulfillment\Providers\TorodPoint\TorodPointAdapter;
use App\Services\Fulfillment\ProviderCityMappingService;
use App\Services\Fulfillment\ProviderRegistry;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Every phrase this feature shows is translated, in English and in the two Arabic locales.
 *
 * The platform's translate() helper silently WRITES a humanised English placeholder into
 * resources/lang/{locale}/new-messages.php the first time it meets an unknown key — so a missing
 * translation never fails a page, it just shows English in an Arabic store and dirties the repository.
 * This test turns that silent fallback into a failure.
 */
class TranslationsTest extends FulfillmentTestCase
{
    private const LOCALES = ['en', 'sa', 'ae'];

    private const MIXED_NOTICE_ARABIC = 'يحتوي طلبك على منتجات من مستودعات مختلفة، لذلك قد يصلك على شحنتين منفصلتين وفي أوقات مختلفة، دون الحاجة إلى إنشاء طلبين.';

    /** Directories and files that carry this feature's user-visible text. */
    private const SOURCES = [
        'resources/views/admin-views/fulfillment',
        'resources/views/admin-views/order/_fulfillments-card.blade.php',
        'resources/views/admin-views/partials/_fulfillment-provider.blade.php',
        'resources/themes/default/web-views/partials/_mixed-provider-notice.blade.php',
        'resources/themes/default/web-views/partials/_fulfillment-shipments.blade.php',
        'app/Services/Fulfillment',
        'app/Http/Controllers/Admin/Fulfillment',
        'app/Http/Controllers/RestAPI/v1/FulfillmentController.php',
        'app/Http/Controllers/Api/FulfillmentWebhookController.php',
        // the unified address: only files it created, because the pages it touched also carry older text
        'resources/views/partials/address',
        'resources/views/admin-views/address',
        'resources/views/admin-views/order/partials/_invoice-address-rows.blade.php',
        'resources/themes/default/web-views/partials/address',
        'app/Http/Controllers/Address',
        'app/Http/Controllers/Admin/Address',
        'app/Http/Requests/Admin/Address',
        'app/Services/Address',
        'app/Services/OrderAddressUpdateService.php',
    ];

    /** @var array<string, array<string, string>> */
    private array $catalogue = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::LOCALES as $locale) {
            $this->catalogue[$locale] = array_merge(
                include base_path("resources/lang/{$locale}/messages.php"),
                include base_path("resources/lang/{$locale}/new-messages.php"),
            );
        }
    }

    /** @return list<string> */
    private function literalKeysUsedByTheFeature(): array
    {
        $keys = [];
        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);
            $offset = 0;
            while (($position = strpos($source, 'translate(', $offset)) !== false) {
                $start = $position + strlen('translate');
                $arguments = $this->balancedArguments($source, $start);
                $offset = $start + 1;

                if (preg_match_all('/(?<![\[\w])([\'"])([A-Za-z][A-Za-z0-9_\/]*)\1(?!\s*\])/', $arguments, $matches)) {
                    foreach ($matches[2] as $key) {
                        if (!str_ends_with($key, '_')) {
                            $keys[$key] = true;
                        }
                    }
                }
            }
        }

        return array_keys($keys);
    }

    private function balancedArguments(string $source, int $openParen): string
    {
        $depth = 0;
        for ($index = $openParen, $length = strlen($source); $index < $length; $index++) {
            if ($source[$index] === '(') {
                $depth++;
            } elseif ($source[$index] === ')' && --$depth === 0) {
                return substr($source, $openParen + 1, $index - $openParen - 1);
            }
        }

        return '';
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];
        foreach (self::SOURCES as $relative) {
            $path = base_path($relative);
            if (is_file($path)) {
                $files[] = $path;
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (preg_match('/\.php$/', $file->getFilename())) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /** @param list<string> $keys */
    private function assertTranslated(array $keys, string $what): void
    {
        $this->assertNotEmpty($keys, "no keys found for {$what}");

        foreach (self::LOCALES as $locale) {
            $missing = array_values(array_filter($keys, fn (string $key): bool => !array_key_exists($key, $this->catalogue[$locale])));
            $this->assertSame([], $missing, "{$what}: keys missing from resources/lang/{$locale}: " . implode(', ', $missing));
        }
    }

    public function test_every_literal_key_in_the_feature_exists_in_every_locale(): void
    {
        $this->assertTranslated($this->literalKeysUsedByTheFeature(), 'literal translate() keys');
    }

    public function test_every_address_system_label_built_from_a_value_exists(): void
    {
        $keys = ['address_region', 'address_city', 'address_district', 'address_national_address', 'zip_code', 'address_match_inactive'];
        foreach (AddressSchema::MODES as $mode) {
            array_push($keys, "address_mode_{$mode}", "address_mode_{$mode}_help");
        }
        foreach (CitySyncVerdict::cases() as $verdict) {
            array_push($keys, "address_sync_{$verdict->value}", "city_sync_{$verdict->value}");
        }
        foreach (CityMatchStatus::cases() as $status) {
            $keys[] = "address_match_{$status->value}";
        }
        foreach ([CityResolution::NO_MAPPING, CityResolution::PENDING_REVIEW, CityResolution::AMBIGUOUS, CityResolution::PROVIDER_CITY_MISSING] as $code) {
            $keys[] = "city_hold_code_{$code}";
        }
        foreach ([CityCandidate::REGION_CONFIRMED, CityCandidate::REGION_UNKNOWN, CityCandidate::REGION_UNPLACEABLE] as $check) {
            $keys[] = "city_region_{$check}";
        }
        foreach ([ProviderCityMappingService::METHOD_MANUAL, ProviderCityMappingService::METHOD_AUTO_UNIQUE] as $method) {
            $keys[] = "city_method_{$method}";
        }
        foreach (['regions', 'cities', 'aliases'] as $kind) {
            $keys[] = "address_{$kind}";
        }
        foreach (['created', 'updated', 'unchanged', 'skipped', 'not_in_file'] as $column) {
            $keys[] = "address_import_{$column}";
        }
        foreach (['not_migrated', 'no_cities', 'zip_restriction'] as $problem) {
            $keys[] = "address_mode_blocked_{$problem}";
        }
        foreach (['conflict', 'blocks_mode', 'info'] as $banner) {
            $keys[] = "address_zip_banner_{$banner}";
        }

        $this->assertTranslated($keys, 'address system labels built from a value');
    }

    public function test_every_status_hold_reason_and_summary_label_exists(): void
    {
        $keys = array_map(static fn (FulfillmentStatus $status): string => $status->labelKey(), FulfillmentStatus::cases());
        $keys = array_merge($keys, array_values((new ReflectionClass(HoldReason::class))->getConstants()));

        $summaries = (new ReflectionClass(FulfillmentAggregator::class))->getConstants();
        foreach ($summaries as $name => $value) {
            if (is_string($value) && !str_starts_with($name, 'NEEDS') && !str_starts_with($name, 'ON_')) {
                $keys[] = 'fulfillment_summary_' . $value;
            }
        }
        foreach (['delivered', 'returned', 'on_the_way', 'delayed', 'preparing', 'confirmed'] as $customerStatus) {
            $keys[] = 'fulfillment_customer_status_' . $customerStatus;
        }
        foreach (['submitted', 'shipped', 'delivered', 'cancelled', 'returned', 'failed'] as $moment) {
            $keys[] = "fulfillment_{$moment}_at";
        }
        foreach (MappingStatus::cases() as $status) {
            $keys[] = 'mapping_status_' . $status->value;
        }
        foreach (StockSource::cases() as $source) {
            $keys[] = 'stock_source_' . $source->value;
        }
        foreach (PaymentMode::cases() as $mode) {
            $keys[] = 'payment_mode_' . $mode->value;
        }
        foreach ((new ReflectionClass(CapabilityState::class))->getConstants() as $state) {
            $keys[] = 'capability_state_' . $state;
        }

        $this->assertTranslated($keys, 'enum-driven labels');
    }

    public function test_every_capability_and_reason_the_adapters_can_report_exists(): void
    {
        config([
            'fulfillment.providers.m5azn.adapter' => M5aznAdapter::class,
            'fulfillment.providers.torod_point.adapter' => TorodPointAdapter::class,
        ]);

        $keys = [];
        $collect = function () use (&$keys): void {
            foreach (ProviderCode::cases() as $provider) {
                $report = app(ProviderRegistry::class)->adapter($provider)->readiness();
                foreach ($report->capabilities as $capability) {
                    $keys[] = 'capability_' . $capability->key;
                    $capability->reason === null || $keys[] = $capability->reason;
                }
                foreach ($report->missing as $setting) {
                    $keys[] = 'setting_' . $setting;
                }
            }
        };

        $collect();
        $this->enableProvider(ProviderCode::M5azn, ['import_enabled' => true, 'default_category_id' => 1, 'product_sync_enabled' => true, 'inventory_sync_enabled' => true, 'status_map' => ['shipped' => 'in_transit']], ['email' => 'a@b.test', 'password' => 'x']);
        $this->enableProvider(ProviderCode::TorodPoint, ['company_id' => '714', 'product_sync_enabled' => true, 'push_products_enabled' => true, 'inventory_sync_enabled' => true, 'webhooks_enabled' => true], [
            'fulfillment_username' => 'u', 'fulfillment_password' => 'p', 'lastmile_email' => 'e@f.test', 'lastmile_password' => 'q', 'webhook_secret' => 's',
        ]);
        $collect();

        $this->assertTranslated(array_values(array_unique($keys)), 'capabilities and reasons');
    }

    public function test_every_documentation_gap_and_field_map_label_exists(): void
    {
        $keys = [];
        foreach ((array) config('fulfillment.providers') as $provider) {
            foreach ((array) $provider['documented'] as $flag => $documented) {
                $documented === false && $keys[] = 'doc_gap_' . $flag;
            }
            $keys[] = 'provider_role_' . $provider['role'];
        }

        $definitions = new FieldMapDefinitions();
        foreach (ProviderCode::cases() as $provider) {
            foreach ($definitions->for($provider) as $capability => $definition) {
                $keys[] = 'field_map_' . $capability;
                foreach ($definition['fields'] as $field) {
                    $keys[] = 'map_field_' . $field;
                }
            }
        }

        $this->assertTranslated($keys, 'documentation gaps and field maps');
    }

    public function test_the_mixed_shipment_notice_is_the_required_arabic_text_in_both_arabic_locales(): void
    {
        foreach (['sa', 'ae'] as $locale) {
            $this->assertSame(self::MIXED_NOTICE_ARABIC, $this->catalogue[$locale]['mixed_provider_order_notice'], $locale);
        }
        $this->assertStringContainsString('different warehouses', $this->catalogue['en']['mixed_provider_order_notice']);
    }

    public function test_what_a_customer_reads_is_arabic_in_the_arabic_locales(): void
    {
        $customerFacing = [
            'your_shipments', 'shipment', 'tracking_number', 'mixed_provider_order_notice',
            'fulfillment_customer_status_confirmed', 'fulfillment_customer_status_preparing', 'fulfillment_customer_status_on_the_way',
            'fulfillment_customer_status_delayed', 'fulfillment_customer_status_delivered', 'fulfillment_customer_status_returned',
            'fulfillment_summary_not_started', 'fulfillment_summary_processing', 'fulfillment_summary_partially_shipped', 'fulfillment_summary_shipped',
            'fulfillment_summary_partially_delivered', 'fulfillment_summary_delivered', 'fulfillment_summary_attention_required',
            'fulfillment_summary_cancelled', 'fulfillment_summary_returned', 'fulfillment_summary_partially_returned',
        ];

        foreach (['sa', 'ae'] as $locale) {
            foreach ($customerFacing as $key) {
                $this->assertMatchesRegularExpression('/\p{Arabic}/u', $this->catalogue[$locale][$key], "{$locale}: {$key} must be Arabic");
            }
        }
    }

    public function test_every_address_text_is_arabic_in_the_arabic_locales(): void
    {
        $addressKeys = array_values(array_filter(
            $this->literalKeysUsedByTheFeature(),
            static fn (string $key): bool => str_starts_with($key, 'address_') || str_starts_with($key, 'city_'),
        ));
        $this->assertNotEmpty($addressKeys);

        foreach (['sa', 'ae'] as $locale) {
            $notArabic = array_values(array_filter(
                $addressKeys,
                fn (string $key): bool => preg_match('/\p{Arabic}/u', $this->catalogue[$locale][$key] ?? '') !== 1,
            ));
            $this->assertSame([], $notArabic, "{$locale}: address texts that are not Arabic: " . implode(', ', $notArabic));
        }
    }

    public function test_customer_wording_never_names_a_provider(): void
    {
        $providerNames = ['M5AZN', 'Torod', 'تورود', 'LogesTechs'];
        $customerFacing = ['your_shipments', 'shipment', 'mixed_provider_order_notice', 'fulfillment_summary_shipped', 'fulfillment_customer_status_on_the_way', 'fulfillment_customer_status_delayed'];

        foreach (self::LOCALES as $locale) {
            foreach ($customerFacing as $key) {
                foreach ($providerNames as $name) {
                    $this->assertStringNotContainsString($name, $this->catalogue[$locale][$key], "{$locale}: {$key}");
                }
            }
        }
    }
}
