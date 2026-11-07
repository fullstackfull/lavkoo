<?php

namespace App\Http\Requests\Admin\Address;

/**
 * The messages of the rules the address system's forms use, in the language of the panel. The store ships no validation file for
 * Arabic, so without these an Arabic administrator reads an English sentence with Arabic field names in the middle of it.
 */
trait TranslatesValidationMessages
{
    /** @return array<string, string|array<string, string>> */
    public function messages(): array
    {
        return [
            'required' => translate('address_validation_required'),
            'integer' => translate('address_validation_integer'),
            'numeric' => translate('address_validation_numeric'),
            'string' => translate('address_validation_string'),
            'boolean' => translate('address_validation_boolean'),
            'in' => translate('address_validation_invalid'),
            'exists' => translate('address_validation_invalid'),
            'accepted' => translate('address_validation_accepted'),
            'regex' => translate('address_validation_regex'),
            'between' => ['numeric' => translate('address_validation_between'), 'string' => translate('address_validation_between')],
            'min' => ['numeric' => translate('address_validation_min'), 'string' => translate('address_validation_min')],
            'max' => ['numeric' => translate('address_validation_max_number'), 'string' => translate('address_validation_max_text')],
        ];
    }
}
