<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\Providers\M5azn\M5aznFieldMaps;
use App\Services\Fulfillment\Providers\TorodPoint\TorodFieldMaps;

/** What each provider's field-map screen offers: which maps exist, their logical fields, and which are required. */
class FieldMapDefinitions
{
    /**
     * `nullable` names the required fields that only have to be present in the sampled records, not filled in them. `strict` means that
     * a flag or a blank text is no value there (Torod Point's orders map: the identifiers it relies on); every other map is read as it always was.
     *
     * @return array<string, array{required: list<string>, fields: list<string>, nullable?: list<string>, strict?: bool}> capability => definition
     */
    public function for(ProviderCode $provider): array
    {
        return match ($provider) {
            ProviderCode::M5azn => [
                M5aznFieldMaps::CATALOG => [
                    'required' => M5aznFieldMaps::REQUIRED[M5aznFieldMaps::CATALOG],
                    'fields' => array_merge(
                        array_keys(M5aznFieldMaps::CANDIDATES[M5aznFieldMaps::CATALOG]),
                        array_map(static fn (string $field): string => 'variant_' . $field, array_keys(M5aznFieldMaps::VARIANT_CANDIDATES)),
                    ),
                ],
                M5aznFieldMaps::INVENTORY => ['required' => M5aznFieldMaps::REQUIRED[M5aznFieldMaps::INVENTORY], 'fields' => array_keys(M5aznFieldMaps::CANDIDATES[M5aznFieldMaps::INVENTORY])],
                M5aznFieldMaps::ORDERS => ['required' => M5aznFieldMaps::REQUIRED[M5aznFieldMaps::ORDERS], 'fields' => array_keys(M5aznFieldMaps::CANDIDATES[M5aznFieldMaps::ORDERS])],
            ],
            ProviderCode::TorodPoint => [
                TorodFieldMaps::PRODUCTS => ['required' => TorodFieldMaps::REQUIRED[TorodFieldMaps::PRODUCTS], 'fields' => array_keys(TorodFieldMaps::CANDIDATES[TorodFieldMaps::PRODUCTS])],
                TorodFieldMaps::ORDERS => [
                    'required' => TorodFieldMaps::REQUIRED[TorodFieldMaps::ORDERS],
                    'nullable' => TorodFieldMaps::NULLABLE[TorodFieldMaps::ORDERS],
                    'fields' => array_keys(TorodFieldMaps::CANDIDATES[TorodFieldMaps::ORDERS]),
                    'strict' => true,
                ],
            ],
        };
    }

    public function has(ProviderCode $provider, string $capability): bool
    {
        return array_key_exists($capability, $this->for($provider));
    }
}
