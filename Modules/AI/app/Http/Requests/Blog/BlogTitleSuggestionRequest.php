<?php

namespace Modules\AI\app\Http\Requests\Blog;

use App\Traits\ResponseHandler;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class BlogTitleSuggestionRequest extends FormRequest
{
    use   ResponseHandler;
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'keywords' => 'required|string|max:255',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(response()->json(['errors' => $this->errorProcessor($validator)]));
    }
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
}
