<?php

namespace App\Services\Monitoring\Reconciliation;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\Contracts\ReportsChannelHolds;
use App\Services\Fulfillment\InventorySyncService;
use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityCheck;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyProbe;
use Illuminate\Support\Carbon;

/**
 * The provider's own count of a product against what the storefront offers of it (M2-V6).
 *
 * `provider_product_mappings.provider_stock` is what the carrier or fulfiller last said it has; `products.current_stock` is what the
 * storefront sells from. `InventorySyncService` writes the second from the first: `current_stock = max(0, provider_stock - promised)`,
 * where promised is the store orders the provider has not taken on yet plus the units open channel orders hold of the same shelf. Until
 * now that service was the only thing that knew the equation, and it is a WRITER: it sets the figure and reports no difference, so a
 * storefront whose figure drifted had nothing to notice it.
 *
 * ## Why the comparison is one-sided, and why that is not a weaker test
 *
 * A two-sided equality would be wrong, and would cry wolf on a healthy shop: a store order takes units off `current_stock` the moment it
 * is placed, so between two syncs the storefront's figure is legitimately BELOW the provider's figure less what is promised. What may
 * never happen is the other direction. `current_stock` ABOVE `provider_stock - promised` means the storefront is offering units the
 * provider does not have and that nothing has promised -- an oversell waiting for a customer, and on a shared shelf it is another market's
 * units that are sold. So:
 *
 *   - above the provider's figure is a FAILURE, with the mappings and the units;
 *   - below it is INFO, with the count, because that is the normal state between syncs and saying otherwise would make the check noise.
 *
 * ## It asks the writer rather than restating its equation
 *
 * `promised` comes from `InventorySyncService::reservedNotYetWithProvider()` and `ReportsChannelHolds::unitsHeld()`, the same two calls the
 * writer makes. Copying the equation here would have produced a reconciler that agrees with a previous version of the writer, which is the
 * one thing a reconciler must not do. `ReadOnlyRunner` makes the inspected connection the default for the duration of the run, so those
 * calls read the database being inspected and are recorded by the probe like every other read.
 *
 * ## What is skipped, and why it is counted
 *
 * A mapping whose product is assigned to a DIFFERENT provider is not written by this equation at all; a mapping with no figure yet has
 * nothing to compare; a product the storefront sells per variant cannot be compared against one whole-product figure (the writer refuses
 * that case too, `UNSUPPORTED`); a provider code this build does not know cannot be asked for its promised units. Each is counted and
 * named, so the number of mappings this proves nothing about is part of the finding rather than hidden inside a green tick.
 */
final class ProviderStockReconciler
{
    private const MAPPINGS = 'provider_product_mappings';

    private const PRODUCTS = 'products';

    private const SECTION = 'provider stock';

    /** How many mappings are read. The reconciler is run by hand on a copy; a shop with more mappings than this says so in the report. */
    private const PAGE = 2000;

    /** How many disagreeing mappings are named in the facts. The total is always exact. */
    private const NAMED = 20;

    public function __construct(
        private readonly InventorySyncService $sync,
        private readonly ReportsChannelHolds $channelHolds,
    ) {
    }

    /** @return list<CompatibilityCheck> */
    public function checks(ReadOnlyProbe $probe): array
    {
        if (!$probe->hasTable(self::MAPPINGS) || !$probe->hasTable(self::PRODUCTS)) {
            return [new CompatibilityCheck(
                section: self::SECTION,
                name: 'not compared',
                status: CompatibilityCheck::INFO,
                detail: 'this database has no ' . self::MAPPINGS . ' or no ' . self::PRODUCTS . ' table, so no provider figure was compared with anything',
                facts: ['compared' => false],
            )];
        }

        $comparison = $this->compare($probe);

        return [
            new CompatibilityCheck(
                section: self::SECTION,
                name: 'provider figures compared',
                status: CompatibilityCheck::INFO,
                detail: $comparison['compared'] . ' of ' . $comparison['mappings'] . ' mapping(s) were compared'
                    . ($comparison['truncated'] ? ' (the first ' . self::PAGE . ' by id; the rest were not read)' : '')
                    . ($comparison['skipped'] === [] ? '' : '; not compared: ' . $this->asList($comparison['skipped'])),
                facts: ['mappings' => $comparison['mappings'], 'compared' => $comparison['compared'], 'skipped' => $comparison['skipped'], 'truncated' => $comparison['truncated']],
            ),
            new CompatibilityCheck(
                section: self::SECTION,
                name: 'storefront offers more than the provider has',
                status: $comparison['above'] === [] ? CompatibilityCheck::PASS : CompatibilityCheck::FAIL,
                detail: $comparison['above'] === []
                    ? 'no product offers more units than its provider has left after what is promised'
                    : count($comparison['above']) . ' product(s) offer ' . $comparison['unitsAbove'] . ' unit(s) more than their provider has left after what is promised: an order for them cannot be fulfilled, and on a shared shelf the units belong to somebody else',
                facts: $comparison['above'] === [] ? [] : ['products' => count($comparison['above']), 'units' => $comparison['unitsAbove'], 'rows' => array_slice($comparison['above'], 0, self::NAMED)],
            ),
            new CompatibilityCheck(
                section: self::SECTION,
                name: 'storefront offers less than the provider has',
                status: CompatibilityCheck::INFO,
                detail: $comparison['below'] === 0
                    ? 'every compared product offers exactly what its provider has left after what is promised'
                    : $comparison['below'] . ' product(s) offer fewer units than their provider has left, which is the normal state between two syncs: a store order takes units off the storefront the moment it is placed',
                facts: ['products' => $comparison['below'], 'units' => $comparison['unitsBelow']],
            ),
        ];
    }

    /**
     * @return array{mappings: int, compared: int, truncated: bool, skipped: array<string, int>, above: list<array<string, mixed>>, unitsAbove: int, below: int, unitsBelow: int}
     */
    private function compare(ReadOnlyProbe $probe): array
    {
        $rows = $probe->select(
            'select m.id, m.provider, m.product_id, m.variant_key, m.provider_stock, m.provider_stock_synced_at,'
            . ' p.current_stock, p.fulfillment_provider, p.variation'
            . ' from ' . self::MAPPINGS . ' as m join ' . self::PRODUCTS . ' as p on p.id = m.product_id'
            . ' where m.provider_stock is not null order by m.id limit ' . self::PAGE,
        );

        $skipped = [];
        $above = [];
        $unitsAbove = 0;
        $below = 0;
        $unitsBelow = 0;
        $compared = 0;

        foreach ($rows as $row) {
            $provider = ProviderCode::tryFrom((string) $row['provider']);
            if ($provider === null) {
                $skipped['provider this build does not know'] = ($skipped['provider this build does not know'] ?? 0) + 1;
                continue;
            }
            if ((string) ($row['fulfillment_provider'] ?? '') !== $provider->value) {
                $skipped['the product is not assigned to this provider'] = ($skipped['the product is not assigned to this provider'] ?? 0) + 1;
                continue;
            }
            if ((string) $row['variant_key'] === '' && $this->sellsPerVariant($row['variation'] ?? null)) {
                $skipped['the storefront sells it per variant and the figure is for the whole product'] = ($skipped['the storefront sells it per variant and the figure is for the whole product'] ?? 0) + 1;
                continue;
            }

            $compared++;
            $expected = max(0, (int) $row['provider_stock'] - $this->promised($provider, $row));
            $offered = $this->offered($row);

            if ($offered > $expected) {
                $above[] = [
                    'mapping' => (int) $row['id'],
                    'product_id' => (int) $row['product_id'],
                    'provider' => $provider->value,
                    'variant_key' => (string) $row['variant_key'],
                    'provider_stock' => (int) $row['provider_stock'],
                    'promised' => (int) $row['provider_stock'] - $expected,
                    'offered' => $offered,
                    'over_by' => $offered - $expected,
                    'figure_read_at' => $row['provider_stock_synced_at'] === null ? null : (string) $row['provider_stock_synced_at'],
                ];
                $unitsAbove += $offered - $expected;
            } elseif ($offered < $expected) {
                $below++;
                $unitsBelow += $expected - $offered;
            }
        }

        return [
            'mappings' => $probe->count(self::MAPPINGS, static fn ($query) => $query->whereNotNull('provider_stock')),
            'compared' => $compared,
            'truncated' => count($rows) === self::PAGE,
            'skipped' => $skipped,
            'above' => $above,
            'unitsAbove' => $unitsAbove,
            'below' => $below,
            'unitsBelow' => $unitsBelow,
        ];
    }

    /** @param array<string, mixed> $row */
    private function promised(ProviderCode $provider, array $row): int
    {
        $readAt = $row['provider_stock_synced_at'] === null ? null : Carbon::parse((string) $row['provider_stock_synced_at']);

        return $this->sync->reservedNotYetWithProvider($provider, (int) $row['product_id'], (string) $row['variant_key'], $readAt)
            + $this->channelHolds->unitsHeld(provider: $provider, productId: (int) $row['product_id'], variantKey: (string) $row['variant_key'], figureReadAt: $readAt);
    }

    /**
     * What the storefront offers of the thing this mapping is for: the variant's own quantity when the mapping names one, and the
     * product's stock when it does not.
     *
     * @param array<string, mixed> $row
     */
    private function offered(array $row): int
    {
        if ((string) $row['variant_key'] === '') {
            return (int) $row['current_stock'];
        }

        foreach ($this->variations($row['variation'] ?? null) as $variation) {
            if (($variation['type'] ?? null) === (string) $row['variant_key']) {
                return (int) ($variation['qty'] ?? 0);
            }
        }

        return 0;
    }

    private function sellsPerVariant(mixed $variation): bool
    {
        return $this->variations($variation) !== [];
    }

    /** @return list<array<string, mixed>> */
    private function variations(mixed $variation): array
    {
        $decoded = json_decode((string) $variation, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** @param array<string, int> $counts */
    private function asList(array $counts): string
    {
        return implode(', ', array_map(static fn (string $reason, int $rows): string => "{$rows} because {$reason}", array_keys($counts), $counts));
    }
}
