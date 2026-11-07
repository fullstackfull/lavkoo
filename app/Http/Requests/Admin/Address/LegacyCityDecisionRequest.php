<?php

namespace App\Http\Requests\Admin\Address;

use Illuminate\Foundation\Http\FormRequest;

/** The decision on one old city text: this text means that city. */
class LegacyCityDecisionRequest extends FormRequest
{
    use TranslatesValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:1000'],
            'city_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['text' => translate('address_legacy_typed_city'), 'city_id' => translate('address_city')];
    }
}
