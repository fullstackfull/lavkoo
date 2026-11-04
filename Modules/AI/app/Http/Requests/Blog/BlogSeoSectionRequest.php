<?php

namespace Modules\AI\app\Http\Requests\Blog;

use App\Traits\ResponseHandler;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class BlogSeoSectionRequest extends FormRequest
{
    use ResponseHandler;
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ];
    }
    public  function messages(): array{
        return [
            'name.required' => translate('blog_title_is_required_to_generate_seo_information'),
            'description.required' => translate('blog_description_is_required_to_generate_seo_information'),
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(response()->json(['errors' => $this->errorProcessor($validator)]));
    }
}
