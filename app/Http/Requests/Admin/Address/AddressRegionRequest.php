<?php

namespace App\Http\Requests\Admin\Address;

use Illuminate\Foundation\Http\FormRequest;

/** A region can be renamed, in both languages; its code comes from the source list and is not edited. */
class AddressRegionRequest extends FormRequest
{
    use ValidatesPlaceNames;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['name_ar' => $this->placeNameRules(), 'name_en' => $this->placeNameRules()];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name_ar' => translate('address_name_ar'), 'name_en' => translate('address_name_en')];
    }
}
