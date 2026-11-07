<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\MappingStatus;
use App\Models\AddressCity;
use App\Models\ProviderCity;
use App\Models\ProviderCityMapping;
use App\Models\ProviderCitySyncRun;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Data\CityResolution;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the city-matching screen shows: how complete the provider's list is, which of our cities orders are waiting on (with the
 * provider's candidates side by side), which cities have several candidates, and which mappings are approved.
 *
 * Only reads. Every change goes through ProviderCityMappingService.
 */
class ProviderCityReview
{
    private const HELD_LIMIT = 500;

    private const SHOWN_SHIPMENTS = 3;

    private const SHOWN_CONFLICTS = 50;

    private const SHOWN_APPROVED = 50;

    private const SHOWN_RUNS = 5;

    public function __construct(private readonly ProviderCityMappingService $mappings)
    {
    }

    /** @return list<int> our cities the held orders are waiting on, most recent first */
    public function heldCityIds(string $provider): array
    {
        return $this->heldShipments($provider)->pluck('city_id')->filter()->unique()->values()->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * @return array{
     *     last_run: ?ProviderCitySyncRun,
     *     runs: Collection<int, ProviderCitySyncRun>,
     *     directory: array{total: int, missing: int, selected: int},
     *     cities: list<array<string, mixed>>,
     *     typed: list<array{text: string, orders: int, shipments: list<array{id: int, reference: string}>}>,
     *     approved: list<array{mapping: ProviderCityMapping, city: ?AddressCity, provider_city: ?ProviderCity}>
     * }
     */
    public function overview(string $provider): array
    {
        $held = $this->heldShipments($provider);
        $heldByCity = $held->filter(static fn (array $shipment): bool => $shipment['city_id'] > 0)->groupBy('city_id');
        $conflictIds = $this->mappings->conflictedCityIds($provider, self::SHOWN_CONFLICTS);

        $cities = AddressCity::query()->with('region')->whereIn('id', [...$heldByCity->keys()->all(), ...$conflictIds])->get()->keyBy('id');
        $candidates = $this->mappings->candidatesForMany(provider: $provider, cities: $cities);
        $rows = ProviderCityMapping::query()->where('provider', $provider)->whereIn('address_city_id', $cities->keys()->all())->get()->groupBy('address_city_id');

        $blocks = [];
        foreach ($cities as $cityId => $city) {
            $shipments = $heldByCity->get($cityId, collect());
            $cityRows = $rows->get($cityId, collect());
            $live = $candidates[$cityId]->reject(static fn ($candidate): bool => in_array($cityRows->firstWhere('external_city_id', (string) $candidate->city->external_id)?->status, [MappingStatus::Rejected, MappingStatus::Retired], true));

            $blocks[] = [
                'city' => $city,
                'orders' => $shipments->count(),
                'shipments' => $shipments->take(self::SHOWN_SHIPMENTS)->map(static fn (array $shipment): array => ['id' => $shipment['id'], 'reference' => $shipment['reference']])->values()->all(),
                'code' => $shipments->first()['code'] ?? null,
                'conflict' => $live->count() > 1,
                'approved' => $cityRows->first(static fn (ProviderCityMapping $row): bool => $row->isApproved()),
                'candidates' => $candidates[$cityId]->map(static fn ($candidate): array => [
                    'candidate' => $candidate,
                    'status' => $cityRows->firstWhere('external_city_id', (string) $candidate->city->external_id)?->status?->value,
                ])->values()->all(),
            ];
        }
        usort($blocks, static fn (array $a, array $b): int => [$b['orders'], $b['conflict'] ? 1 : 0] <=> [$a['orders'], $a['conflict'] ? 1 : 0]);

        return [
            'last_run' => ProviderCitySyncRun::query()->where('provider', $provider)->orderByDesc('id')->first(),
            'runs' => ProviderCitySyncRun::query()->where('provider', $provider)->orderByDesc('id')->limit(self::SHOWN_RUNS)->get(),
            'directory' => $this->directory($provider),
            'cities' => $blocks,
            'typed' => $this->typedText($held),
            'approved' => $this->approved($provider),
        ];
    }

    /**
     * Held shipments, each with the detail the dispatcher stored and the city of ours the order's snapshot carries.
     *
     * @return Collection<int, array{id: int, reference: string, city_id: int, code: ?string, text: string}>
     */
    private function heldShipments(string $provider): Collection
    {
        $held = ProviderFulfillment::query()
            ->where('provider', $provider)
            ->where('status', FulfillmentStatus::Held->value)
            ->where('hold_reason', HoldReason::CITY_UNMAPPED)
            ->orderByDesc('id')
            ->limit(self::HELD_LIMIT)
            ->get(['id', 'order_id', 'reference', 'last_error_message']);
        $snapshots = DB::table('orders')->whereIn('id', $held->pluck('order_id')->all())->pluck('shipping_address_data', 'id');

        return $held->map(static function (ProviderFulfillment $fulfillment) use ($snapshots): array {
            $detail = CityResolution::parseDetail($fulfillment->last_error_message);
            $snapshot = json_decode((string) ($snapshots[$fulfillment->order_id] ?? ''), true);

            return [
                'id' => (int) $fulfillment->id,
                'reference' => (string) $fulfillment->reference,
                'city_id' => is_array($snapshot) ? (int) ($snapshot['city_id'] ?? 0) : 0,
                'code' => $detail['code'] ?? null,
                'text' => trim($detail['city'] ?? (string) $fulfillment->last_error_message),
            ];
        });
    }

    /**
     * The held orders whose address has no city of ours, only text: grouped by that text.
     *
     * @param Collection<int, array{id: int, reference: string, city_id: int, code: ?string, text: string}> $held
     * @return list<array{text: string, orders: int, shipments: list<array{id: int, reference: string}>}>
     */
    private function typedText(Collection $held): array
    {
        return $held->filter(static fn (array $shipment): bool => $shipment['city_id'] === 0)
            ->groupBy('text')
            ->map(static fn (Collection $group, string $text): array => [
                'text' => $text,
                'orders' => $group->count(),
                'shipments' => $group->take(self::SHOWN_SHIPMENTS)->map(static fn (array $shipment): array => ['id' => $shipment['id'], 'reference' => $shipment['reference']])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /** @return array{total: int, missing: int, selected: int} */
    private function directory(string $provider): array
    {
        $cities = ProviderCity::query()->where('provider', $provider);

        return [
            'total' => (clone $cities)->count(),
            'missing' => (clone $cities)->whereNotNull('missing_since')->count(),
            'selected' => (clone $cities)->where('is_selected', true)->count(),
        ];
    }

    /** @return list<array{mapping: ProviderCityMapping, city: ?AddressCity, provider_city: ?ProviderCity}> */
    private function approved(string $provider): array
    {
        $mappings = ProviderCityMapping::query()->approved()->where('provider', $provider)->orderByDesc('approved_at')->orderByDesc('id')->limit(self::SHOWN_APPROVED)->get();
        $cities = AddressCity::query()->with('region')->whereIn('id', $mappings->pluck('address_city_id')->all())->get()->keyBy('id');
        $providerCities = ProviderCity::query()->where('provider', $provider)->whereIn('external_id', $mappings->pluck('external_city_id')->all())->get()->keyBy('external_id');

        return $mappings->map(static fn (ProviderCityMapping $mapping): array => [
            'mapping' => $mapping,
            'city' => $cities->get($mapping->address_city_id),
            'provider_city' => $providerCities->get($mapping->external_city_id),
        ])->all();
    }
}
