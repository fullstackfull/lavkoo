<?php

namespace Modules\AI\app\Http\Requests\ApiRequests;

use App\Traits\ResponseHandler;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class ProductTitleAutoFillRequest extends FormRequest
{
    use ResponseHandler;

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'langCode' => 'required|string|max:20',
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     */

    public function messages(): array
    {
        return [
            'name.required' => translate('product_name_is_required_to_generate_product_name'),
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json(['errors' => $this->errorProcessor($validator)], 403));
    }
}
