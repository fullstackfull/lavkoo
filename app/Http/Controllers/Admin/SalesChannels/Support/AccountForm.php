<?php

namespace App\Http\Controllers\Admin\SalesChannels\Support;

use App\Enums\SalesChannels\AccountEnvironment;
use App\Enums\SalesChannels\ExecutionPolicy;
use App\Enums\SalesChannels\SalesChannel;
use App\Models\SalesChannelAccount;
use App\Models\Seller;
use App\Services\SalesChannels\Support\ChannelSettings;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * What an administrator may type for a channel account, how it is validated, and how it becomes the arguments of ChannelAccountService.
 *
 * Secrets are write-only: the form never receives a stored one, a blank field keeps what is stored, and each secret has its own "clear" box. They travel under one
 * request key (`secrets`) so that a rejected form can be sent back without them (`Request::except(self::SECRETS_KEY)`).
 *
 * The environment and the Amazon marketplaces are chosen when an account is created and are not edited afterwards: a different environment or marketplace is a different
 * account. A changed seller id, region or storefront is allowed, and ChannelAccountService withdraws what had been proven or approved for the previous one.
 */
final class AccountForm
{
    public const SECRETS_KEY = 'secrets';

    /** The secrets typed in per channel (a subset of ChannelCredentialStore::SECRET_KEYS: the rest are written by the system or no longer used). */
    public const SECRET_FIELDS = [
        'amazon' => ['lwa_client_id', 'lwa_client_secret', 'refresh_token'],
        'trendyol' => ['api_key', 'api_secret'],
    ];

    /** The settings that say WHICH seller account a connection test was about: changing one leaves the earlier test describing something else. */
    public const IDENTITY_SETTINGS = ['amazon' => ['region'], 'trendyol' => ['storefront_code']];

    /** What an account does with an order no rule decides about. It can follow only; creating shipments needs a rule that says from where. */
    public const DEFAULT_POLICIES = [ExecutionPolicy::ManualReview, ExecutionPolicy::ExternallyManaged];

    private const SETTING_FIELDS = [
        'amazon' => ['region', 'marketplace_ids', 'sync_fba_inventory', 'request_buyer_data', 'auto_link_exact_sku', 'route_cod_orders', 'shipment_creation', 'dispatch_mode'],
        'trendyol' => ['storefront_code', 'integration_name', 'accept_language', 'auto_link_exact_barcode', 'prices_include_tax', 'route_cod_orders', 'auto_restock_accepted_returns', 'torod_reference_field', 'carrier_codes', 'shipment_creation', 'dispatch_mode'],
    ];

    public function __construct(private readonly ValidationFactory $validation)
    {
    }

    public function validator(Request $request, SalesChannel $channel, ?SalesChannelAccount $account = null): Validator
    {
        [$input, $carrierCodesReadable] = $this->normalised($request->all(), $channel);
        $validator = $this->validation->make($input, $this->rules($channel, $account, (string) ($input['environment'] ?? '')), [], $this->attributes());

        $validator->after(function (Validator $validator) use ($input, $carrierCodesReadable, $account): void {
            if (!$carrierCodesReadable) {
                $validator->errors()->add('settings.carrier_codes', translate('carrier_codes_line_invalid'));
            }
            if ($this->typedAndCleared($input) !== []) {
                $validator->errors()->add('clear_secrets', translate('secret_typed_and_cleared'));
            }
            $sellerId = (int) ($input['seller_id'] ?? 0);
            if ($account === null && $sellerId > 0 && !Seller::whereKey($sellerId)->exists()) {
                $validator->errors()->add('seller_id', translate('seller_not_found'));
            }
        });

        return $validator;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array{data: array<string, mixed>, secrets: array<string, string>, clear: list<string>}
     */
    public function payload(array $validated, SalesChannel $channel, bool $creating): array
    {
        $settings = Arr::only((array) ($validated['settings'] ?? []), self::SETTING_FIELDS[$channel->value]);
        $data = [
            'name' => $validated['name'],
            'external_account_id' => $validated['external_account_id'],
            'currency' => $validated['currency'] ?? null,
            'execution_policy' => $validated['execution_policy'] ?? null,
            'settings' => $settings,
        ];
        if ($creating) {
            $data += ['environment' => $validated['environment'], 'seller_id' => (int) ($validated['seller_id'] ?? 0)];
        } else {
            unset($data['settings']['marketplace_ids']);
        }

        return [
            'data' => $data,
            'secrets' => array_filter(Arr::only((array) ($validated[self::SECRETS_KEY] ?? []), self::SECRET_FIELDS[$channel->value]), static fn ($value): bool => is_string($value) && $value !== ''),
            'clear' => array_values((array) ($validated['clear_secrets'] ?? [])),
        ];
    }

    /** The settings as the form shows them: the channel's defaults under what is stored. @return array<string, mixed> */
    public function settingsFor(SalesChannel $channel, ?SalesChannelAccount $account): array
    {
        return array_replace(ChannelSettings::defaults($channel->value), (array) ($account?->settings ?? []));
    }

    /** The carrier map as the text area shows it: one `carrier=CODE` per line. */
    public function carrierCodesText(mixed $codes): string
    {
        return collect((array) $codes)->map(static fn ($code, $carrier): string => "{$carrier}={$code}")->implode("\n");
    }

    /** @return array<string, mixed> */
    private function rules(SalesChannel $channel, ?SalesChannelAccount $account, string $environment): array
    {
        $creating = $account === null;
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'external_account_id' => ['required', 'string', 'max:64', $channel === SalesChannel::Trendyol ? 'regex:/^\d+$/' : 'regex:/^[A-Za-z0-9_-]+$/', $this->uniqueSellerId($channel, $account?->environment ?? $environment, $account)],
            'currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'execution_policy' => ['nullable', Rule::in(array_map(static fn (ExecutionPolicy $policy): string => $policy->value, self::DEFAULT_POLICIES))],
            'settings' => ['required', 'array'],
            self::SECRETS_KEY => ['nullable', 'array:' . implode(',', self::SECRET_FIELDS[$channel->value])],
            self::SECRETS_KEY . '.*' => ['nullable', 'string', 'max:4000'],
            'clear_secrets' => ['nullable', 'array'],
            'clear_secrets.*' => ['string', Rule::in(self::SECRET_FIELDS[$channel->value])],
        ];
        if ($creating) {
            $rules += [
                'environment' => ['required', Rule::enum(AccountEnvironment::class)],
                'seller_id' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            ];
        }

        return $rules + $this->settingRules($channel, $creating);
    }

    /** @return array<string, mixed> */
    private function settingRules(SalesChannel $channel, bool $creating): array
    {
        $flags = static fn (array $names): array => array_combine(
            array_map(static fn (string $name): string => "settings.{$name}", $names),
            array_fill(0, count($names), ['sometimes', 'boolean']),
        );

        if ($channel === SalesChannel::Amazon) {
            return $flags(['sync_fba_inventory', 'request_buyer_data', 'auto_link_exact_sku', 'route_cod_orders'])
                + ['settings.region' => ['required', Rule::in(ChannelSettings::AMAZON_REGIONS)], 'settings.shipment_creation' => ['nullable', Rule::in(['unset', 'app'])], 'settings.dispatch_mode' => ['nullable', Rule::in(ChannelSettings::DISPATCH_MODES)]]
                + ($creating ? ['settings.marketplace_ids' => ['required', 'array', 'min:1'], 'settings.marketplace_ids.*' => ['string', 'regex:/^[A-Z0-9]{8,16}$/']] : []);
        }

        return $flags(['auto_link_exact_barcode', 'prices_include_tax', 'route_cod_orders', 'auto_restock_accepted_returns']) + [
            'settings.storefront_code' => ['nullable', 'string', Rule::in(ChannelSettings::TRENDYOL_STOREFRONTS)],
            'settings.integration_name' => ['required', 'string', 'regex:/^[A-Za-z0-9]{1,30}$/'],
            'settings.accept_language' => ['required', Rule::in(['ar', 'en', 'tr'])],
            'settings.torod_reference_field' => ['required', Rule::in(['order_number', 'package_id', 'tracking_number'])],
            'settings.shipment_creation' => ['nullable', Rule::in(ChannelSettings::SHIPMENT_CREATION)],
            'settings.dispatch_mode' => ['nullable', Rule::in(ChannelSettings::DISPATCH_MODES)],
            'settings.carrier_codes' => ['nullable', 'array'],
            'settings.carrier_codes.*' => ['string', 'regex:/^[A-Z0-9_]{2,30}$/'],
        ];
    }

    /** One account per seller id, channel and environment: the database says so too, but a rejected form explains it. */
    private function uniqueSellerId(SalesChannel $channel, string $environment, ?SalesChannelAccount $account): Unique
    {
        return Rule::unique('sales_channel_accounts', 'external_account_id')->where('channel', $channel->value)->where('environment', $environment)->ignore($account?->id);
    }

    /** @param array<string, mixed> $input @return list<string> the secrets that were both typed and ticked for clearing */
    private function typedAndCleared(array $input): array
    {
        $typed = array_keys(array_filter((array) ($input[self::SECRETS_KEY] ?? []), static fn ($value): bool => is_string($value) && trim($value) !== ''));

        return array_values(array_intersect($typed, (array) ($input['clear_secrets'] ?? [])));
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'name' => translate('account_name'),
            'external_account_id' => translate('seller_id_at_the_channel'),
            'currency' => translate('currency'),
            'execution_policy' => translate('when_no_rule_matches'),
            'environment' => translate('environment'),
            'seller_id' => translate('seller_id_platform_is_zero'),
            'settings.region' => translate('amazon_region'),
            'settings.marketplace_ids' => translate('marketplace_ids'),
            'settings.storefront_code' => translate('storefront_code'),
            'settings.integration_name' => translate('integration_name'),
            'settings.accept_language' => translate('response_language'),
            'settings.torod_reference_field' => translate('torod_reference_field'),
            'settings.shipment_creation' => translate('shipment_creation_setting'),
            'settings.dispatch_mode' => translate('dispatch_mode_setting'),
            'settings.carrier_codes' => translate('carrier_codes'),
            'settings.carrier_codes.*' => translate('carrier_codes'),
            'clear_secrets' => translate('clear_stored_value'),
        ];
    }

    /**
     * The request as the rules want it: the marketplace ids as a list and the carrier codes as a map.
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: bool} the input, and whether every line of the carrier codes could be read
     */
    private function normalised(array $input, SalesChannel $channel): array
    {
        $settings = (array) ($input['settings'] ?? []);
        $readable = true;

        if ($channel === SalesChannel::Amazon && is_string($settings['marketplace_ids'] ?? null)) {
            $settings['marketplace_ids'] = array_values(array_unique(array_filter(array_map('strtoupper', preg_split('/[\s,;]+/', $settings['marketplace_ids']) ?: []))));
        }
        if ($channel === SalesChannel::Trendyol && is_string($settings['carrier_codes'] ?? null)) {
            [$settings['carrier_codes'], $readable] = $this->carrierCodes($settings['carrier_codes']);
        }
        $input['settings'] = $settings;

        return [$input, $readable];
    }

    /** @return array{0: array<string, string>, 1: bool} the carrier => Trendyol code map, and whether every line could be read */
    private function carrierCodes(string $text): array
    {
        $map = [];
        $readable = true;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^([^=:]{1,60})[=:]\s*([A-Za-z0-9_]{2,30})$/', $line, $matches) !== 1) {
                $readable = false;
                continue;
            }
            $map[strtolower(trim($matches[1]))] = strtoupper($matches[2]);
        }

        return [$map, $readable];
    }
}
