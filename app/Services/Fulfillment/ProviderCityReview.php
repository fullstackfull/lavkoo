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
use App\Services\Address\AddressSchema;
use App\Services\Address\AddressSnapshot;
use App\Services\Address\CityMatch;
use App\Services\Address\LegacyCityMatcher;
use App\Services\Fulfillment\Data\CityResolution;
use App\Services\Storefront\StorefrontMarketRows;
use Illuminate\Database\Eloquent\Builder;
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

    public function __construct(
        private readonly ProviderCityMappingService $mappings,
        private readonly LegacyCityMatcher $matcher,
        private readonly ProviderStorefrontCountry $providerCountry,
        private readonly StorefrontMarketRows $marketRows,
    ) {
    }

    /** @return list<int> our cities the held orders of this connection's country are waiting on, most recent first */
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
     *     approved: list<array{mapping: ProviderCityMapping, city: ?AddressCity, provider_city: ?ProviderCity}>,
     *     country: ?string
     * }
     */
    public function overview(string $provider): array
    {
        $country = $this->providerCountry->of($provider);
        $held = $this->heldShipments($provider);
        $heldByCity = $held->filter(static fn (array $shipment): bool => $shipment['city_id'] > 0)->groupBy('city_id');
        $conflictIds = $this->mappings->conflictedCityIds($provider, self::SHOWN_CONFLICTS, $country);

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
            'country' => $country,
        ];
    }

    /**
     * Held shipments, each with the detail the dispatcher stored and the city of ours the order's address means: the one its copy
     * carries, or, for an order placed before the structured address, the one its typed text certainly is (the matcher the dispatcher used).
     *
     * @return Collection<int, array{id: int, reference: string, city_id: int, code: ?string, text: string}>
     */
    private function heldShipments(string $provider): Collection
    {
        $shipments = ProviderFulfillment::query()
            ->where('provider', $provider)
            ->where('status', FulfillmentStatus::Held->value)
            ->where('hold_reason', HoldReason::CITY_UNMAPPED);
        $this->ofThisCountry($shipments, $provider);

        $held = $shipments->orderByDesc('id')->limit(self::HELD_LIMIT)->get(['id', 'order_id', 'reference', 'last_error_message']);
        $snapshots = DB::table('orders')->whereIn('id', $held->pluck('order_id')->all())->pluck('shipping_address_data', 'id');

        $readings = [];

        return $held->map(function (ProviderFulfillment $fulfillment) use ($snapshots, &$readings): array {
            $detail = CityResolution::parseDetail($fulfillment->last_error_message);
            $snapshot = json_decode((string) ($snapshots[$fulfillment->order_id] ?? ''), true);

            return [
                'id' => (int) $fulfillment->id,
                'reference' => (string) $fulfillment->reference,
                'city_id' => is_array($snapshot) ? $this->cityOf(snapshot: $snapshot, readings: $readings) : 0,
                'code' => $detail['code'] ?? null,
                'text' => trim($detail['city'] ?? (string) $fulfillment->last_error_message),
            ];
        });
    }

    /**
     * @param array<array-key, mixed> $snapshot the order's address copy
     * @param array<string, CityMatch> $readings what the matcher said about each typed city and state already, so a text typed by many orders is read once
     */
    private function cityOf(array $snapshot, array &$readings): int
    {
        $cityId = (int) ($snapshot['city_id'] ?? 0);
        if ($cityId > 0 || !AddressSchema::hasCities()) {
            return $cityId;
        }

        $city = AddressSnapshot::plain($snapshot, 'city');
        $state = AddressSnapshot::plain($snapshot, 'state');
        $match = $readings[$city . '|' . $state] ??= $this->matcher->match(cityText: $city, regionText: $state === '' ? null : $state);

        return $match->matched() ? (int) $match->city->id : 0;
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
    /**
     * Narrows held shipments to the country the connection serves, by the market their own LINES name (H1-10).
     *
     * `provider_fulfillments` carries no market, and it does not need one: every line of a provider-owned shipment is an immutable
     * ownership snapshot that already names the market it was sold in (C-04, `2027_01_02_100000`), so the shipment's country is a read of
     * that rather than a second record of the same fact -- and two records of one fact are two records that can disagree. The rule for an
     * unmarked line is {@see StorefrontMarketRows}'s and is not restated: the default storefront takes the lines that name no market,
     * because every such line was sold in the only market this platform had.
     *
     * `whereHas` and not `whereDoesntHave`: a shipment with a line of this market is shown here even if it has lines of another, because a
     * held order hidden from the only screen that can release it is worse than one that appears on two countries' screens.
     *
     * Dormant twice over: while the country-scoped codes switch is off `ProviderStorefrontCountry::of()` answers null, and before the
     * snapshot migration has run `StorefrontMarketRows::applies()` is false -- in both cases nothing is narrowed and the screen is the one
     * this platform has always shown.
     *
     * @param Builder<ProviderFulfillment> $shipments
     */
    private function ofThisCountry(Builder $shipments, string $provider): void
    {
        $country = $this->providerCountry->of($provider);

        if ($country === null || !$this->marketRows->applies(OwnershipHistory::TABLE)) {
            return;
        }

        $shipments->whereHas('items', fn ($items) => $this->marketRows->narrow($items, OwnershipHistory::TABLE, $country));
    }

    private function directory(string $provider): array
    {
        $cities = ProviderCity::query()->where('provider', $provider);

        return [
            'total' => (clone $cities)->count(),
            'missing' => (clone $cities)->whereNotNull('missing_since')->count(),
            'selected' => (clone $cities)->where('is_selected', true)->count(),
        ];
    }

    /**
     * The approved matches this screen lists, narrowed to the country the connection serves (H1-10).
     *
     * `provider_city_mappings.country_code` has carried the country of OUR CITY on every write since H1-10, and until this nothing READ
     * it on a screen: one carrier code serving two countries with separate credentials (H1-11) would have listed both countries' approved
     * matches in one list, with nothing in it saying which country a row was about -- and two cities of the same name in two countries read
     * as one row repeated.
     *
     * The country is {@see ProviderStorefrontCountry::of()}, which is the same answer the product search on this very screen is narrowed
     * by, so the products it offers and the cities it lists belong to one country. It answers NULL while the country-scoped codes switch is
     * off, and {@see ProviderCityMappingService::ofCountry()} then narrows nothing -- so this ships dormant. When it is on, the default
     * storefront also takes the rows that name no country, because a mapping made before countries were stated is a mapping of the one
     * country the platform served.
     *
     * The waiting and the conflicting blocks are narrowed too, each by its own rule: {@see ofThisCountry()} reads the market the
     * shipment's own LINES name, and {@see ProviderCityMappingService::conflictedCityIds()} takes the country here. A held shipment is
     * never hidden by it -- one line of this market is enough to list it -- because a held order kept off the only screen that can release
     * it would be worse than a mixed list.
     *
     * @return list<array{mapping: ProviderCityMapping, city: ?AddressCity, provider_city: ?ProviderCity}>
     */
    private function approved(string $provider): array
    {
        $mappings = ProviderCityMappingService::ofCountry(
            ProviderCityMapping::query()->approved()->where('provider', $provider),
            $provider,
            $this->providerCountry->of($provider),
        )->orderByDesc('approved_at')->orderByDesc('id')->limit(self::SHOWN_APPROVED)->get();
        $cities = AddressCity::query()->with('region')->whereIn('id', $mappings->pluck('address_city_id')->all())->get()->keyBy('id');
        $providerCities = ProviderCity::query()->where('provider', $provider)->whereIn('external_id', $mappings->pluck('external_city_id')->all())->get()->keyBy('external_id');

        return $mappings->map(static fn (ProviderCityMapping $mapping): array => [
            'mapping' => $mapping,
            'city' => $cities->get($mapping->address_city_id),
            'provider_city' => $providerCities->get($mapping->external_city_id),
        ])->all();
    }
}
