<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DeliveryZipCodeAddRequest extends FormRequest
{
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `string`, not just `required`: the controller splits this on commas, and explode()
            // rejects an array outright — so `?zipcode[]=x` used to 500 instead of being refused here.
            'zipcode' => 'required|string'
        ];
    }

    public function messages(): array
    {
        return [
            'zipcode.required' => translate('the_zipcode_field_is_required'),
            'zipcode.string' => translate('the_zipcode_field_is_required'),
        ];
    }

}
