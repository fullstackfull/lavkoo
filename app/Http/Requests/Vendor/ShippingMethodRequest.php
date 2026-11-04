<?php

namespace App\Http\Requests\Vendor;

use App\Models\ShippingMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShippingMethodRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'title' => 'required|max:200',
            'duration' => 'required',
            'cost' => 'numeric'
        ];
    }

    /**
     * @return array
     * Get the validation error message
     */
    public function messages(): array
    {
        return [
            'title.required' => translate('the_title_field_is_required'),
            'duration.required' => translate('the_duration_field_is_required'),
            'cost.numeric' => translate('the_cost_must_be_a_number')
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $id = $this->route('id');
                $exists = ShippingMethod::where('title', $this->input('title'))
                    ->where('creator_id', auth('seller')->id())
                    ->where('creator_type', 'seller')
                    ->when($id, function ($query) use ($id) {
                        $query->where('id', '!=', $id);
                    })
                    ->exists();

                if ($exists) {
                    $validator->errors()->add(
                        'title',
                        translate('title_already_exists')
                    );
                }
            }
        ];
    }
}
