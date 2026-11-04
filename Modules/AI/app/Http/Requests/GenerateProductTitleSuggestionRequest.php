<?php

namespace Modules\AI\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateProductTitleSuggestionRequest extends FormRequest
{
    /**
     * Normalize keywords to an array before validation.
     * Accepts an array, or a single/comma-separated string (backward compatible).
     */
    protected function prepareForValidation(): void
    {
        $keywords = $this->input('keywords');
        if (is_string($keywords)) {
            $keywords = array_values(array_filter(
                array_map('trim', explode(',', $keywords)),
                fn ($keyword) => $keyword !== ''
            ));
        }
        $this->merge(['keywords' => $keywords]);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'keywords' => 'required|array|min:1|max:20',
            'keywords.*' => 'required|string|max:255',
        ];
    }


    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
}
