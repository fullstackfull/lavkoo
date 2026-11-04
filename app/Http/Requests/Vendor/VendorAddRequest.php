<?php

namespace App\Http\Requests\Vendor;

use App\Traits\CalculatorTrait;
use App\Traits\ResponseHandler;
use Illuminate\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Exceptions\HttpResponseException;

class VendorAddRequest extends FormRequest
{
    use CalculatorTrait, ResponseHandler;

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
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'f_name' => 'required',
            'l_name' => 'required',
            'phone' => 'required|unique:sellers|max:20',
            'email' => 'required|unique:sellers',
            'image' => 'required|mimes:jpg,jpeg,png,webp,gif,bmp,tif,tiff',
            'password' => 'required|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*\W)(?!.*\s).{8,}$/|same:confirm_password',
            'shop_name' => 'required',
            'shop_address' => 'required',
            'logo' => 'required|mimes: jpg,jpeg,png,webp,gif,bmp,tif,tiff',
            'banner' => 'required|mimes: jpg,jpeg,png,webp,gif,bmp,tif,tiff',
            'bottom_banner' => 'mimes: jpg,jpeg,png,webp,gif,bmp,tif,tiff',
            'tax_identification_number' => 'nullable|string',
            'tin_expire_date' => 'nullable|date|after_or_equal:today',
            'tin_certificate' => 'nullable|mimes:pdf,doc,docx,jpg|max:5120',
        ];
    }

    public function messages(): array
    {
        return [
            'f_name.required' => translate('The_first_name_field_is_required'),
            'l_name.required' => translate('The_last_name_field_is_required'),
            'phone.required' => translate('The_phone_field_is_required'),
            'phone.unique' => translate('The_phone_number_has_already_been_taken'),
            'phone.max' => translate('please_ensure_your_phone_number_is_valid_and_does_not_exceed_20_characters'),
            'email.required' => translate('The_email_field_is_required'),
            'email.unique' => translate('The_email_has_already_been_taken'),
            'image.required' => translate('The_image_field_is_required'),
            'image.mimes' => translate('The_image_type_must_be') . '.jpg, .png, .jpeg, .gif, .bmp, .tif, .tiff,.webp',
            'password.required' => translate('The_password_field_is_required'),
            'password.same' => translate('The_password_and_confirm_password_must_match'),
            'password.regex' => translate('The_password_must_be_at_least_8_characters_long_and_contain_at_least_one_uppercase_letter') . ',' . translate('_one_lowercase_letter') . ',' . translate('_one_digit_') . ',' . translate('_one_special_character') . ',' . translate('_and_no_spaces') . '.',
            'shop_name.required' => translate('The_shop_name_field_is_required'),
            'shop_address.required' => translate('The_shop_address_field_is_required'),
            'logo.mimes' => translate('The_logo_type_must_be') . '.jpg, .png, .jpeg, .gif, .bmp, .tif, .tiff,.webp',
            'banner.mimes' => translate('The_banner_type_must_be') . '.jpg, .png, .jpeg, .gif, .bmp, .tif, .tiff,.webp',
            'bottom_banner.mimes' => translate('The_bottom_banner_type_must_be') . '.jpg, .png, .jpeg, .gif, .bmp, .tif, .tiff,.webp',
            'tax_identification_number.string' => translate('The_tin_identification_number_must_be_string'),
            'tin_expire_date.date' => translate('The_tin_expire_date_must_be_a_valid_date_format'),
            'tin_expire_date.after_or_equal' => translate('The_tin_expire_date_must_be_a_future_date'),
            'tin_certificate.mimes' => translate('The_tin_certificate_must_be_a_file_of_type_pdf_doc_docx_jpg'),
            'tin_certificate.max' => translate('The_tin_certificate_must_not_exceed_5MB'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {

                $numericPhoneValue = preg_replace('/[^0-9]/', '', $this['phone']);
                $numericLength = strlen($numericPhoneValue);
                if ($numericLength < 4) {
                    $validator->errors()->add(
                        'phone.min', translate('The_phone_number_must_be_at_least_4_characters')
                    );
                }

                if ($numericLength > 20) {
                    $validator->errors()->add(
                        'phone.max', translate('The_phone_number_may_not_be_greater_than_20_characters')
                    );
                }
            }
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(response()->json(['errors' => $this->errorProcessor($validator)]));
    }
}
