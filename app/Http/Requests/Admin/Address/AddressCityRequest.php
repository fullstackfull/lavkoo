<?php

namespace App\Http\Requests\Admin\Address;

use App\Models\AddressRegion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Adding a city and editing one take the same fields. Codes are not asked for: a city is known by its id. */
class AddressCityRequest extends FormRequest
{
    use ValidatesPlaceNames;

    private const MAX_RANK = 1000000;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'region_id' => ['required', 'integer', 'min:1', Rule::exists(AddressRegion::class, 'id')],
            'name_ar' => $this->placeNameRules(),
            'name_en' => $this->placeNameRules(),
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:' . self::MAX_RANK],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'region_id' => translate('address_region'),
            'name_ar' => translate('address_name_ar'),
            'name_en' => translate('address_name_en'),
            'latitude' => translate('address_latitude'),
            'longitude' => translate('address_longitude'),
            'sort_order' => translate('address_rank'),
        ];
    }

    /** @return array{region_id: int, name_ar: string, name_en: string, latitude: float|null, longitude: float|null, sort_order: int} */
    public function city(): array
    {
        $data = $this->validated();

        return [
            'region_id' => (int) $data['region_id'],
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
