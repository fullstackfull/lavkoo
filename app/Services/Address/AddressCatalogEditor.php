<?php

namespace App\Services\Address;

use App\Models\AddressCity;
use App\Models\AddressRegion;
use App\Models\ShippingAddress;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The administrator's edits to the region and city lists.
 *
 * There is no delete, on purpose: saved addresses, provider mappings and the copies orders keep all point at a city by its id, so a
 * place that should no longer be offered is switched off (deactivated) and stays in the list. Switching a place off only stops it
 * being chosen for new addresses; nothing already saved or ordered changes.
 */
class AddressCatalogEditor
{
    public const ADMIN_SOURCE = 'admin';

    private const NAME_CHECK_ATTEMPTS = 3;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function renameRegion(AddressRegion $region, string $nameAr, string $nameEn): AddressRegion
    {
        $nameAr = $this->name($nameAr);
        $nameEn = $this->name($nameEn);
        $before = $region->only(['name_ar', 'name_en']);
        $region->fill([
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'normalized_ar' => AddressText::key($nameAr),
            'normalized_en' => AddressText::key($nameEn),
        ]);

        if ($region->isDirty()) {
            $region->save();
            $this->audit->record(action: 'address.region_updated', subject: $region, before: $before, after: $region->only(['name_ar', 'name_en']));
        }

        return $region;
    }

    public function setRegionActive(AddressRegion $region, bool $active): AddressRegion
    {
        if ($region->is_active !== $active) {
            $region->update(['is_active' => $active]);
            $this->afterAvailabilityChange(action: 'address.region_status_changed', subject: $region, active: $active);
        }

        return $region;
    }

    /**
     * @param array{region_id: int, name_ar: string, name_en: string, latitude?: float|null, longitude?: float|null, sort_order?: int|null} $data
     * @throws ValidationException when the region already has a city with this name
     */
    public function createCity(array $data): AddressCity
    {
        $data = $this->cleaned($data);

        $city = DB::transaction(function () use ($data): AddressCity {
            $this->holdRegion(regionId: (int) $data['region_id']);
            $this->assertNameIsFree(regionId: (int) $data['region_id'], nameAr: $data['name_ar'], nameEn: $data['name_en']);

            return AddressCity::create($this->attributes($data) + [
                'is_active' => true,
                'source' => self::ADMIN_SOURCE,
                'source_ref' => null,
                'source_updated_at' => now(),
            ]);
        }, attempts: self::NAME_CHECK_ATTEMPTS);
        $this->audit->record(action: 'address.city_created', subject: $city, after: $this->summary($city));
        AddressSchema::flush();

        return $city;
    }

    /**
     * A city that moves to another region takes its saved addresses with it: their region_id is the region of their city, and the checks
     * that compare the two would otherwise refuse every one of them. The copies orders keep are never rewritten.
     *
     * @param array{region_id: int, name_ar: string, name_en: string, latitude?: float|null, longitude?: float|null, sort_order?: int|null} $data
     * @throws ValidationException when the new name or region would repeat a city of that region
     */
    public function updateCity(AddressCity $city, array $data): AddressCity
    {
        $data = $this->cleaned($data);
        $before = $this->summary($city);
        $placeChanged = (int) $data['region_id'] !== $city->region_id
            || AddressText::key($data['name_ar']) !== $city->normalized_ar
            || AddressText::key($data['name_en']) !== $city->normalized_en;

        DB::transaction(function () use ($city, $data, $placeChanged): void {
            if ($placeChanged) {
                $this->holdRegion(regionId: (int) $data['region_id']);
                $this->assertNameIsFree(regionId: (int) $data['region_id'], nameAr: $data['name_ar'], nameEn: $data['name_en'], ignoring: $city->id);
            }

            $previousRegion = $city->region_id;
            $city->fill($this->attributes($data));
            if (!$city->isDirty()) {
                return;
            }

            $city->save();
            if ($previousRegion !== $city->region_id && AddressSchema::columnsReady()) {
                ShippingAddress::query()->where('city_id', $city->id)->toBase()->update(['region_id' => $city->region_id]);
            }
        }, attempts: self::NAME_CHECK_ATTEMPTS);

        if ($city->wasChanged()) {
            $this->audit->record(action: 'address.city_updated', subject: $city, before: $before, after: $this->summary($city));
        }

        return $city;
    }

    public function setCityActive(AddressCity $city, bool $active): AddressCity
    {
        if ($city->is_active !== $active) {
            $city->update(['is_active' => $active]);
            $this->afterAvailabilityChange(action: 'address.city_status_changed', subject: $city, active: $active);
        }

        return $city;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function cleaned(array $data): array
    {
        return ['name_ar' => $this->name($data['name_ar']), 'name_en' => $this->name($data['name_en'])] + $data;
    }

    private function name(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'region_id' => (int) $data['region_id'],
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'normalized_ar' => AddressText::key($data['name_ar']),
            'normalized_en' => AddressText::key($data['name_en']),
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /** Names are checked and cities are added region by region: two people adding the same city at once take turns instead of both passing the check. */
    private function holdRegion(int $regionId): void
    {
        AddressRegion::query()->whereKey($regionId)->lockForUpdate()->pluck('id');
    }

    /**
     * Two cities of one region with the same name make that name ambiguous for the matcher, so a second one is refused; the list that
     * was imported does contain such pairs, which stay as they are.
     *
     * @throws ValidationException
     */
    private function assertNameIsFree(int $regionId, string $nameAr, string $nameEn, ?int $ignoring = null): void
    {
        $keys = array_values(array_filter([AddressText::key($nameAr), AddressText::key($nameEn)], static fn (string $key): bool => $key !== ''));

        $taken = AddressCity::query()
            ->where('region_id', $regionId)
            ->when($ignoring !== null, static fn (Builder $query) => $query->whereKeyNot($ignoring))
            ->where(static function (Builder $query) use ($keys): void {
                $query->whereIn('normalized_ar', $keys)->orWhereIn('normalized_en', $keys);
            })
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name_ar' => [translate('address_city_name_taken')]]);
        }
    }

    private function afterAvailabilityChange(string $action, AddressRegion|AddressCity $subject, bool $active): void
    {
        $this->audit->record(action: $action, subject: $subject, before: ['is_active' => !$active], after: ['is_active' => $active]);
        AddressSchema::flush();
    }

    /** @return array<string, mixed> what the audit trail keeps about a city: names and numbers, nothing personal */
    private function summary(AddressCity $city): array
    {
        return $city->only(['region_id', 'name_ar', 'name_en', 'latitude', 'longitude', 'sort_order']);
    }
}
