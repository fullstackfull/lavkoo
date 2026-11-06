<?php

namespace App\Services\Fulfillment\Providers\TorodPoint;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\Support\ProviderConfig;

/**
 * An administrator's statement that the field the products map reads as the stock is the quantity the storefront may sell from.
 *
 * The warehouse reports several quantities for a product (on hand, sellable, committed, allocated, ...) and which of them means
 * "available to sell, already excluding the orders the warehouse has accepted" is for the provider to say. The statement is therefore
 * bound to the path it was made for: a different path is a different statement, and the confirmation lapses until it is made again.
 */
final class TorodStockConfirmation
{
    public function __construct(private readonly FieldMapStore $fieldMaps)
    {
    }

    /** The path the products map reads the stock from now. */
    public function path(): ?string
    {
        return $this->fieldMaps->get(ProviderCode::TorodPoint, TorodFieldMaps::PRODUCTS)->map()->path('stock');
    }

    /** Was a confirmation made for the path that is set now? */
    public function isStated(ProviderConfig $config): bool
    {
        $path = $this->path();

        return $path !== null
            && $config->get('stock_field_confirmed_at') !== null
            && $config->string('stock_field_confirmed_path') === $path;
    }

    /** Stock may be taken from the product list only for a verified products map and a confirmation of its current stock path. */
    public function isConfirmed(ProviderConfig $config): bool
    {
        return $this->fieldMaps->get(ProviderCode::TorodPoint, TorodFieldMaps::PRODUCTS)->verified && $this->isStated($config);
    }

    /**
     * The settings the form's tick box stands for. `$seenPath` is the path shown beside the box: ticking confirms THAT path, and only
     * while it is still the one the map reads, so a page left open across a change of the path cannot confirm a field nobody looked at.
     *
     * @return array{stock_field_confirmed_at: ?string, stock_field_confirmed_path: ?string}
     */
    public function settingsFor(bool $ticked, ?string $seenPath, ProviderConfig $current): array
    {
        $path = $this->path();
        if (!$ticked || $path === null || $seenPath !== $path) {
            return ['stock_field_confirmed_at' => null, 'stock_field_confirmed_path' => null];
        }

        $keptSince = $this->isStated($current) ? $current->get('stock_field_confirmed_at') : null;

        return ['stock_field_confirmed_at' => $keptSince ?? now()->toDateTimeString(), 'stock_field_confirmed_path' => $path];
    }
}
