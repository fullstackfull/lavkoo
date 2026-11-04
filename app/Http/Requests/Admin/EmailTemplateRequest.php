<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class EmailTemplateRequest extends FormRequest
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
            [
                'logo' => 'sometimes|mimes:jpg,jpeg,png|max:1024',
                'icon' => 'required|mimes:jpg,jpeg,png|max:1024',
            ]
        ];

    }

    public function after(): array
    {
        return [
            function (Validator $validator) {

                $bodyEn = $this->body['en'] ?? null;
                $cleaned = trim(strip_tags($bodyEn));
                if (!array_key_exists('en', $this->title) || blank($this->title['en'])) {
                    $validator->errors()->add('title', translate('title_field_is_required') . '!');
                }
                if ($cleaned === '') {
                    $validator->errors()->add('body', translate('Mail_Body_is_required') . '!');
                }
            }
        ];
    }


    public function messages(): array
    {
        return [
            'logo.mimes' => translate('logo_image_type_must_be') . ' jpg, jpeg, png',
            'logo.max' => translate('logo_image_max_size_is_1_MB'),
            'icon.mimes' => translate('icon_image_type_must_be') . ' jpg, jpeg, png',
            'icon.max' => translate('icon_image_max_size_is_1_MB'),
        ];
    }
}
