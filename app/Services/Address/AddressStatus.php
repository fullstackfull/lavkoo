<?php

namespace App\Services\Address;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\AddressCity;
use App\Models\AddressCityAlias;
use App\Models\AddressRegion;
use App\Models\ProviderCityMapping;
use App\Models\ProviderCitySyncRun;
use Illuminate\Support\Carbon;

/**
 * Where the unified address stands on this store: what is migrated, which mode is in force, how big the lists are, how many saved
 * addresses are still old, and how far each shipping provider's cities are mapped.
 *
 * Counts and dates only. Nothing here reads a name, a phone number or an address out of an address, so the same report can be shown on
 * the admin overview and printed by `address:status`, and pasted into a ticket.
 */
class AddressStatus
{
    public function __construct(
        private readonly AddressModeGate $gate,
        private readonly SavedSaudiAddresses $addresses,
        private readonly LegacyBackfill $backfill,
    ) {
    }

    /**
     * @param int|null $legacyTextLimit read what the old city texts mean only when there are at most this many distinct ones (a page must
     *                                  not read thousands of them on every visit); null reads them all
     * @return array{
     *     migrations: array<string, bool>, ready: bool, mappings_ready: bool,
     *     mode: array{configured: string, in_force: string}, zip_restriction: bool,
     *     catalog: array<string, int>|null,
     *     last_import: array{source: string|null, cities: int, snapshot_at: Carbon|null, loaded_at: Carbon|null}|null,
     *     addresses: array<string, mixed>|null,
     *     providers: list<array<string, mixed>>
     * }
     */
    public function report(?int $legacyTextLimit = null): array
    {
        $migrations = AddressMigrations::status();
        $ready = AddressSchema::ready();

        $report = [
            'migrations' => $migrations,
            'ready' => $ready,
            'mappings_ready' => AddressSchema::mappingsReady(),
            'mode' => ['configured' => AddressSchema::configuredMode(), 'in_force' => AddressSchema::mode()],
            'zip_restriction' => $this->gate->zipRestrictionOn(),
            'catalog' => null,
            'last_import' => null,
            'addresses' => null,
            'providers' => [],
        ];
        if (!$ready) {
            return $report;
        }

        $report['catalog'] = $this->catalog();
        $report['last_import'] = $this->lastImport();
        $report['addresses'] = $this->addressCounts($legacyTextLimit);
        $report['providers'] = $report['mappings_ready'] ? $this->providers() : [];

        return $report;
    }

    /** @return array<string, int> */
    private function catalog(): array
    {
        return [
            'regions' => AddressRegion::query()->count(),
            'regions_active' => AddressRegion::query()->where('is_active', true)->count(),
            'cities' => AddressCity::query()->count(),
            'cities_active' => AddressCity::query()->where('is_active', true)->count(),
            'aliases' => AddressCityAlias::query()->count(),
        ];
    }

    /**
     * The data file the cities came from, read from the cities themselves: its name, how many of them it gave, the date of the snapshot it
     * was made from and when it was loaded here. Cities an administrator added by hand are not an import.
     *
     * @return array{source: string|null, cities: int, snapshot_at: Carbon|null, loaded_at: Carbon|null}|null
     */
    private function lastImport(): ?array
    {
        $row = AddressCity::query()
            ->selectRaw('source, COUNT(*) as city_rows, MAX(source_updated_at) as snapshot_at, MAX(created_at) as loaded_at')
            ->where(static fn ($query) => $query->whereNull('source')->orWhere('source', '!=', AddressCatalogEditor::ADMIN_SOURCE))
            ->groupBy('source')
            ->orderByDesc('loaded_at')
            ->first();

        return $row === null ? null : [
            'source' => $row->source,
            'cities' => (int) $row->city_rows,
            'snapshot_at' => $row->snapshot_at === null ? null : Carbon::parse($row->snapshot_at),
            'loaded_at' => $row->loaded_at === null ? null : Carbon::parse($row->loaded_at),
        ];
    }

    /** @return array<string, mixed> */
    private function addressCounts(?int $legacyTextLimit): array
    {
        $saudi = $this->addresses->saudi()->count();
        $withoutCity = $this->addresses->withoutCity()->count();
        $read = $this->backfill->plan($legacyTextLimit);

        return [
            'saudi' => $saudi,
            'unified' => $saudi - $withoutCity,
            'legacy' => $withoutCity,
            'legacy_matched' => $read['rows']['matched'] ?? null,
            'legacy_unmatched' => $read === null ? null : $withoutCity - $read['rows']['matched'],
            'legacy_by_outcome' => $read['rows'] ?? null,
            'needing_completion' => $this->addresses->needingCompletion()->count(),
            'without_zip' => $this->addresses->withoutZip()->count(),
        ];
    }

    /** @return list<array<string, mixed>> one entry per provider that has a mapping or a city synchronisation on record */
    private function providers(): array
    {
        $providers = ProviderCityMapping::query()->distinct()->pluck('provider')
            ->merge(ProviderCitySyncRun::query()->distinct()->pluck('provider'))
            ->unique()
            ->sort()
            ->values();

        return $providers->map(function (string $provider): array {
            $byStatus = ProviderCityMapping::query()->toBase()->where('provider', $provider)->selectRaw('status, COUNT(*) as mappings')->groupBy('status')->pluck('mappings', 'status');
            $approved = ProviderCityMapping::query()->approved()->where('provider', $provider)->select('address_city_id');
            $lastRun = ProviderCitySyncRun::query()->where('provider', $provider)->latest('id')->first();

            return [
                'provider' => $provider,
                'label' => ProviderCode::tryFrom($provider)?->label() ?? $provider,
                'approved' => (int) ($byStatus['approved'] ?? 0),
                'pending' => (int) ($byStatus['pending'] ?? 0),
                'rejected' => (int) ($byStatus['rejected'] ?? 0),
                'retired' => (int) ($byStatus['retired'] ?? 0),
                'cities_without_approved' => AddressCity::query()->where('is_active', true)->whereNotIn('id', $approved)->count(),
                'last_sync' => $lastRun === null ? null : ['verdict' => $lastRun->verdict, 'at' => $lastRun->finished_at ?? $lastRun->created_at],
            ];
        })->all();
    }
}
