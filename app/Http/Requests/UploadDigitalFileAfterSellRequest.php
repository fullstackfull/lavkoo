<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UploadDigitalFileAfterSellRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     */
    public function rules(): array
    {
        return [
            'digital_file_after_sell' => 'required'
        ];
    }

    public function messages(): array
    {
        return [
            'digital_file_after_sell.required' => 'Digital file upload after sell is required',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {

                $disallowedExtensions = getDisallowedExtensionsListArray();

                if ($this->hasFile('digital_file_after_sell')) {
                    $file = $this->file('digital_file_after_sell');
                    $extension = strtolower($file->getClientOriginalExtension());
                    if (in_array($extension, $disallowedExtensions)) {
                        $validator->errors()->add(
                            'digital_file_after_sell',
                            translate('The_uploaded_file_type_is_not_supported'). '!'
                        );
                    }
                }
            }
        ];
    }

}
