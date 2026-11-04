<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceSettingRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'terms_and_condition' => 'required|string',
            'image' => 'nullable|mimes:jpg,jpeg,png,gif|max:1024',
        ];
    }
    public function messages(): array
    {
        return [
            'image.mimes' => translate('the_image_must_be_a_file_of_type_jpg_jpeg_png_gif'),
            'image.max' => translate('the_image_may_not_be_greater_than_1_MB'),
        ];
    }
}
