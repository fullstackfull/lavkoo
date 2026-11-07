<?php

namespace App\Http\Requests\Admin\Address;

use Illuminate\Foundation\Http\FormRequest;

/** Switching a region or a city on or off: always an explicit state, so repeating a request changes nothing. */
class AddressActiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['active' => ['required', 'boolean']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['active' => translate('status')];
    }

    public function active(): bool
    {
        return $this->boolean('active');
    }
}
