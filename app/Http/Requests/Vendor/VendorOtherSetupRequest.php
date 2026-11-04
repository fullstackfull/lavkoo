<?php

namespace App\Http\Requests\Vendor;

use App\Models\Shop;
use App\Traits\ResponseHandler;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VendorOtherSetupRequest extends FormRequest
{
    use ResponseHandler;

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
        $data = [
            'tax_identification_number' => 'nullable|string',
            'tin_certificate' => 'nullable|mimes:pdf,doc,docx,jpg|max:5120',
        ];

        $shop = Shop::where(['seller_id' => auth('seller')->id()])->first();
        if ($shop && !empty($shop['tin_expire_date']) && Carbon::parse($this['tin_expire_date']) != Carbon::parse($shop['tin_expire_date'])) {
            $data += [
                'tin_expire_date' => 'nullable|date|after_or_equal:today',
            ];
        }

        return $data;
    }

    public function messages(): array
    {
        return [
            'tax_identification_number.string' => translate('The_tin_identification_number_must_be_string'),
            'tin_expire_date.date' => translate('The_tin_expire_date_must_be_a_valid_date_format'),
            'tin_expire_date.after_or_equal' => translate('The_tin_expire_date_must_be_a_future_date'),
            'tin_certificate.mimes' => translate('The_tin_certificate_must_be_a_file_of_type_pdf_doc_docx_jpg'),
            'tin_certificate.max' => translate('The_tin_certificate_must_not_exceed_5MB'),
        ];
    }
}
