<?php

namespace App\Http\Requests\Admin\Address;

use App\Services\Address\AddressSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddressModeRequest extends FormRequest
{
    use TranslatesValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['mode' => ['required', 'string', Rule::in(AddressSchema::MODES)]];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['mode' => translate('address_mode')];
    }
}
