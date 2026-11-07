<?php

namespace App\Http\Controllers\Address;

use App\Http\Controllers\Controller;
use App\Services\Address\AddressCatalog;
use App\Services\Address\AddressSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The region and city lists for the address forms of the website, the panels and the mobile app. Public reference data, read-only,
 * rate limited by the routes; an unmigrated store answers with empty lists instead of an error.
 */
class AddressCatalogController extends Controller
{
    private const CACHE_SECONDS = 300;

    public function __construct(private readonly AddressCatalog $catalog)
    {
    }

    public function regions(): JsonResponse
    {
        $regions = AddressSchema::mastersReady()
            ? $this->catalog->regions()->map(fn ($region): array => $this->catalog->regionPayload($region))->values()
            : collect();

        return $this->cacheable(['regions' => $regions]);
    }

    public function cities(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'region_id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1'],
        ]);

        $cities = AddressSchema::mastersReady()
            ? $this->catalog
                ->searchCities(regionId: $validated['region_id'] ?? null, query: $validated['q'] ?? null, limit: $validated['limit'] ?? null)
                ->map(fn ($city): array => $this->catalog->cityPayload($city))
                ->values()
            : collect();

        return $this->cacheable(['cities' => $cities]);
    }

    /** @param array<string, mixed> $body */
    private function cacheable(array $body): JsonResponse
    {
        return response()->json($body)->setPrivate()->setMaxAge(self::CACHE_SECONDS);
    }
}
