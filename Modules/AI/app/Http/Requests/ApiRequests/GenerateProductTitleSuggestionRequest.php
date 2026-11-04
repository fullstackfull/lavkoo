<?php

namespace Modules\AI\app\Http\Requests\ApiRequests;

use App\Traits\ResponseHandler;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class GenerateProductTitleSuggestionRequest extends FormRequest
{
    use ResponseHandler;

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'keywords' => 'required|string|max:255',
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
