<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShopVacationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize():bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules():array
    {
        return [
           'vacation_duration_type' => 'required',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this['vacation_duration_type'] !== 'until_change' && empty($this['vacation_start_date'])) {
                    $validator->errors()->add(
                        'vacation_start_date', translate('the_vacation_start_date_is_required')
                    );
                }

                if ($this['vacation_duration_type'] !== 'until_change' && empty($this['vacation_end_date'])) {
                    $validator->errors()->add(
                        'vacation_end_date', translate('the_vacation_end_date_is_required')
                    );
                }
            }
        ];
    }
}
