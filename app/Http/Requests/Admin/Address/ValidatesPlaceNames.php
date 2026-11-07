<?php

namespace App\Http\Requests\Admin\Address;

use App\Services\Address\AddressText;
use Closure;

/** The rules a region or city name has to meet: one line of text, no markup, and something the lists can compare. */
trait ValidatesPlaceNames
{
    /** @return list<mixed> */
    protected function placeNameRules(): array
    {
        return [
            'bail',
            'required',
            'string',
            'max:191',
            'regex:/^[^<>\p{C}]+$/u',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (AddressText::key(is_string($value) ? $value : null) === '') {
                    $fail(translate('address_name_needs_letters'));
                }
            },
        ];
    }
}
