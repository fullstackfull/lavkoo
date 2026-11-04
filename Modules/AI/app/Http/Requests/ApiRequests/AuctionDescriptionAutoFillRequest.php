<?php

namespace Modules\AI\app\Http\Requests\ApiRequests;

use App\Traits\ResponseHandler;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AuctionDescriptionAutoFillRequest extends FormRequest
{
    use ResponseHandler;

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'langCode' => 'required|string|max:20',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => translate('auction_product_name_is_required_to_generate_description'),
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
