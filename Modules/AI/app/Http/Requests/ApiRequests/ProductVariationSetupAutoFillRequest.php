<?php

namespace Modules\AI\app\Http\Requests\ApiRequests;

use App\Traits\ResponseHandler;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ProductVariationSetupAutoFillRequest extends FormRequest
{
    use ResponseHandler;

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'required',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => translate('product_name_is_required_to_generate_product_variation'),
            'description.required' => translate('product_description_is_required_to_generate_product_variation'),
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json(['errors' => $this->errorProcessor($validator)], 403));
    }
}
