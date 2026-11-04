<?php

namespace App\Http\Requests\Admin;

use App\Models\ShippingMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShippingMethodRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

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
                    ->where('duration', $this->input('duration'))
                    ->where('creator_id', auth('admin')->id())
                    ->where('creator_type', 'admin')
                    ->when($id, function ($query) use ($id) {
                        $query->where('id', '!=', $id);
                    })
                    ->exists();

                if ($exists) {
                    $validator->errors()->add(
                        'title',
                        translate('same_title_and_duration_already_exists')
                    );
                }
            }
        ];
    }
}
