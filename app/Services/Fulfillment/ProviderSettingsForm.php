<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\Providers\TorodPoint\TorodStockConfirmation;
use App\Services\Fulfillment\Support\ProviderConfig;
use Closure;
use Illuminate\Http\Request;

/**
 * What an administrator may edit for each provider, how it is validated, and how it becomes stored settings.
 *
 * Secrets are write-only: the form never receives a stored secret, a blank field means "keep what is
 * stored", and each secret has its own explicit "clear" switch. Base URLs are restricted to the provider's
 * own domains so a mistyped or malicious URL cannot send stored credentials somewhere else.
 */
class ProviderSettingsForm
{
    /** @var array<string, array<string, list<string>>> */
    private const FIELDS = [
        'm5azn' => [
            'bool' => ['dispatch_enabled', 'auto_dispatch', 'dry_run', 'product_sync_enabled', 'inventory_sync_enabled', 'import_enabled', 'publish_on_import', 'update_content_on_sync', 'cod_supported', 'cod_verified'],
            'string' => ['platform_name', 'country_code', 'country_name', 'language', 'device', 'api_version', 'app_version', 'catalog_product_type', 'catalog_category_id', 'catalog_brand_id', 'order_response_reference_path', 'tracking_url_template', 'price_policy'],
            'int' => ['default_category_id', 'min_margin_percent', 'product_sync_interval_minutes', 'inventory_sync_interval_minutes'],
            'list' => ['dispatch_order_statuses'],
            'json' => ['status_map'],
            'secrets' => ['email', 'password'],
        ],
        'torod_point' => [
            'bool' => ['dispatch_enabled', 'auto_dispatch', 'dry_run', 'product_sync_enabled', 'inventory_sync_enabled', 'push_products_enabled', 'webhooks_enabled', 'cod_supported', 'cod_verified', 'verify_terminal_webhooks'],
            'string' => ['company_id', 'lastmile_base_url', 'phone_format', 'cod_collection_method', 'cost_source', 'tracking_url_template', 'webhook_ip_allowlist', 'order_response_reference_path'],
            'int' => ['product_sync_interval_minutes', 'inventory_sync_interval_minutes'],
            'number' => ['default_weight_kg'],
            'list' => ['dispatch_order_statuses'],
            'json' => ['product_defaults'],
            'secrets' => ['fulfillment_username', 'fulfillment_password', 'lastmile_email', 'lastmile_password'],
        ],
    ];

    /**
     * The switches that decide whether orders are sent. The page posts each one's value as it was when the page was opened
     * (`loaded_<switch>`) next to the value now: a switch whose two values agree was not touched, so it keeps what is stored at this
     * moment. A page left open from before an emergency stop therefore cannot switch sending back on by saving something else.
     */
    private const ARMING_SWITCHES = ['enabled', 'dispatch_enabled', 'dry_run', 'auto_dispatch'];

    /** The same rule for what lets a warehouse's figures replace the store's stock: the switch of its sync (and, below, the confirmation of its stock field). */
    private const WAREHOUSE_STOCK_SWITCH = 'inventory_sync_enabled';

    /** Hosts a provider's API may live on (the documented servers). */
    private const ALLOWED_HOSTS = [
        'm5azn' => ['m5azn.com', 'm5azn.ae', 'm5azn.om'],
        'torod_point' => ['logestechs.com'],
    ];

    /** Keys a product push may add from the operator's defaults. Quantity is deliberately absent: warehouse stock is never set by us. */
    public const PRODUCT_DEFAULT_KEYS = [
        'isExpiryBlock', 'isNoNeedRequirements', 'isFragile', 'isClothing', 'isFoldable', 'isCustomPackaging', 'isInfinite',
        'storageType', 'categoryId', 'color', 'length', 'width', 'maxCapacityPerLocation', 'height', 'weight', 'cost',
        'lessCapacity', 'customerInfo', 'areaZone', 'pickingMethod', 'linkedProductId', 'productUnit', 'productUnitQuantity',
    ];

    public function __construct(private readonly TorodStockConfirmation $stockConfirmation)
    {
    }

    /** @return array<string, mixed> Laravel validation rules */
    public function rules(ProviderCode $provider): array
    {
        $fields = self::FIELDS[$provider->value];
        $rules = [
            'enabled' => ['nullable', 'boolean'],
            'base_url' => ['nullable', 'url', 'max:255', $this->providerHost($provider)],
            'clear_secrets' => ['nullable', 'array'],
            'clear_secrets.*' => ['string', 'in:' . implode(',', $fields['secrets'])],
        ];

        foreach ($fields['bool'] as $field) {
            $rules[$field] = ['nullable', 'boolean'];
        }
        foreach ($fields['string'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:255'];
        }
        foreach ($fields['int'] as $field) {
            $rules[$field] = ['nullable', 'integer', 'min:0', 'max:1000000'];
        }
        foreach ($fields['number'] ?? [] as $field) {
            $rules[$field] = ['nullable', 'numeric', 'min:0', 'max:10000'];
        }
        foreach ($fields['list'] as $field) {
            $rules[$field] = ['nullable', 'array'];
            $rules[$field . '.*'] = ['string', 'in:pending,confirmed,processing,out_for_delivery'];
        }
        foreach ($fields['secrets'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:500'];
        }

        foreach (['product_sync_interval_minutes', 'inventory_sync_interval_minutes'] as $interval) {
            $rules[$interval] = ['nullable', 'integer', 'min:' . ProviderSyncService::MIN_INTERVAL_MINUTES, 'max:1440'];
        }
        $rules['catalog_product_type'] = ['nullable', 'string', 'max:80', $this->catalogProductTypesRule()];

        $rules['company_id'] = ['nullable', 'regex:/^\d{1,12}$/'];
        $rules['lastmile_base_url'] = ['nullable', 'url', 'max:255', $this->providerHost($provider)];
        $rules['tracking_url_template'] = ['nullable', 'url', 'max:255', static function (string $attribute, mixed $value, Closure $fail): void {
            if (!str_starts_with((string) $value, 'https://') || !str_contains((string) $value, '{tracking}')) {
                $fail('The tracking URL must be an https URL containing {tracking}.');
            }
        }];
        $rules['webhook_ip_allowlist'] = ['nullable', 'string', 'max:500', $this->ipAllowListRule()];
        $rules['price_policy'] = ['nullable', 'in:store_controlled,supplier_retail'];
        $rules['phone_format'] = ['nullable', 'in:international_00,local'];
        $rules['cost_source'] = ['nullable', 'in:omit,shipping_fee,items_total'];
        $rules['status_map'] = ['nullable', 'json', $this->statusMapRule()];
        $rules['product_defaults'] = ['nullable', 'json', $this->productDefaultsRule()];

        return array_intersect_key($rules, array_flip(array_merge(
            ['enabled', 'base_url', 'clear_secrets', 'clear_secrets.*'],
            $fields['bool'], $fields['string'], $fields['int'], $fields['number'] ?? [], $fields['secrets'], $fields['json'],
            $fields['list'], array_map(static fn (string $field): string => $field . '.*', $fields['list']),
        )));
    }

    /**
     * @return array{enabled: bool, baseUrl: ?string, settings: array<string, mixed>, secrets: array<string, string>, clearSecrets: list<string>}
     */
    public function extract(ProviderCode $provider, Request $request, ProviderConfig $current): array
    {
        $fields = self::FIELDS[$provider->value];
        $settings = [];

        foreach ($fields['bool'] as $field) {
            $settings[$field] = $request->boolean($field);
        }
        foreach ($fields['string'] as $field) {
            $settings[$field] = trim((string) $request->input($field, ''));
        }
        foreach ($fields['int'] as $field) {
            $settings[$field] = $request->filled($field) ? (int) $request->input($field) : null;
        }
        foreach ($fields['number'] ?? [] as $field) {
            $settings[$field] = $request->filled($field) ? (float) $request->input($field) : null;
        }
        foreach ($fields['list'] as $field) {
            $settings[$field] = array_values((array) $request->input($field, []));
        }
        foreach ($fields['json'] as $field) {
            $decoded = $request->filled($field) ? json_decode((string) $request->input($field), true) : [];
            $settings[$field] = is_array($decoded) ? $decoded : [];
        }

        if ($provider === ProviderCode::TorodPoint && !$this->untouched($request, 'stock_field_confirmed')) {
            // An administrator attests that the product list's stock field is the AVAILABLE quantity, for the path shown beside the box;
            // the moment of that statement is recorded, un-ticking it withdraws it, and a different path is a different statement.
            $settings += $this->stockConfirmation->settingsFor(
                ticked: $request->boolean('stock_field_confirmed'),
                seenPath: $request->filled('stock_field_path') ? (string) $request->input('stock_field_path') : null,
                current: $current,
            );
        }

        $guarded = array_diff(self::ARMING_SWITCHES, ['enabled']);
        if ($provider === ProviderCode::TorodPoint) {
            $guarded[] = self::WAREHOUSE_STOCK_SWITCH;
        }
        foreach ($guarded as $switch) {
            if ($this->untouched($request, $switch)) {
                $settings[$switch] = $current->bool($switch, $switch === 'dry_run');
            }
        }

        $secrets = [];
        foreach ($fields['secrets'] as $field) {
            if ($request->filled($field)) {
                $secrets[$field] = (string) $request->input($field);
            }
        }

        return [
            'enabled' => $this->untouched($request, 'enabled') ? $current->enabled : $request->boolean('enabled'),
            // A field that was submitted blank means "use the provider's default"; a field that was not submitted means "leave it".
            'baseUrl' => $request->has('base_url') ? trim((string) $request->input('base_url')) : null,
            'settings' => $settings,
            'secrets' => $secrets,
            'clearSecrets' => array_values((array) $request->input('clear_secrets', [])),
        ];
    }

    private function untouched(Request $request, string $switch): bool
    {
        return $request->has("loaded_{$switch}") && $request->boolean($switch) === $request->boolean("loaded_{$switch}");
    }

    /** Every entry of the comma separated allow-list is an IP address or a CIDR range: a typo must not silently open or close the endpoint. */
    private function ipAllowListRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $entry) {
                [$address, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
                $isV6 = str_contains((string) $address, ':');
                $validAddress = filter_var($address, FILTER_VALIDATE_IP) !== false;
                $validPrefix = $prefix === null || (ctype_digit($prefix) && (int) $prefix <= ($isV6 ? 128 : 32));
                if (!$validAddress || !$validPrefix) {
                    $fail("'{$entry}' is not an IP address or a CIDR range.");

                    return;
                }
            }
        };
    }

    private function providerHost(ProviderCode $provider): Closure
    {
        $allowed = self::ALLOWED_HOSTS[$provider->value];

        return static function (string $attribute, mixed $value, Closure $fail) use ($allowed): void {
            $parts = parse_url((string) $value);
            $host = strtolower((string) ($parts['host'] ?? ''));
            $hostAllowed = false;
            foreach ($allowed as $domain) {
                $hostAllowed = $hostAllowed || $host === $domain || str_ends_with($host, '.' . $domain);
            }
            if (($parts['scheme'] ?? '') !== 'https' || !$hostAllowed) {
                $fail('The URL must be an https address on ' . implode(' / ', $allowed) . '.');
            }
        };
    }

    private function catalogProductTypesRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $types = array_filter(array_map('trim', explode(',', strtolower((string) $value))));
            foreach ($types as $type) {
                if (!in_array($type, ['local', 'global', 'ali_express', 'digital'], true)) {
                    $fail("'{$type}' is not an M5AZN product type (use: local, global, ali_express, digital).");

                    return;
                }
            }
        };
    }

    private function statusMapRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $map = json_decode((string) $value, true);
            foreach ((array) $map as $raw => $internal) {
                $status = FulfillmentStatus::tryFrom((string) $internal);
                if (!is_string($raw) || $status === null || $status->isPreSubmission()) {
                    $fail('Every status must map to a shipment status the provider can report.');

                    return;
                }
            }
        };
    }

    private function productDefaultsRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            foreach (array_keys((array) json_decode((string) $value, true)) as $key) {
                if (!in_array($key, self::PRODUCT_DEFAULT_KEYS, true)) {
                    $fail("'{$key}' cannot be set here (quantity and identity fields are never taken from defaults).");

                    return;
                }
            }
        };
    }
}
