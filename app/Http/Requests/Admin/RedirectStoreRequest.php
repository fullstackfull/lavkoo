<?php

namespace App\Http\Requests\Admin;

use App\Services\Seo\RedirectResolverService;
use Illuminate\Foundation\Http\FormRequest;

class RedirectStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the source path exactly as the storefront middleware will compare it, and coerce
     * the scalar fields, before validation runs.
     */
    protected function prepareForValidation(): void
    {
        $resolver = new RedirectResolverService();

        $this->merge([
            'from_path'   => is_string($this->from_path) ? $resolver->normalizePath($this->from_path) : $this->from_path,
            'to_path'     => is_string($this->to_path) ? trim($this->to_path) : $this->to_path,
            'status_code' => (int) ($this->status_code ?: 301),
            'match_type'  => in_array($this->match_type, ['exact', 'prefix'], true) ? $this->match_type : 'exact',
            'is_active'   => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        return [
            'from_path'   => ['required', 'string', 'max:191', 'different:to_path'],
            'to_path'     => ['required', 'string', 'max:2048'],
            'status_code' => ['required', 'integer', 'in:301,302'],
            'match_type'  => ['required', 'in:exact,prefix'],
            'is_active'   => ['boolean'],
            'note'        => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'from_path.required'  => translate('the_source_path_is_required') . '!',
            'to_path.required'    => translate('the_target_path_is_required') . '!',
            'from_path.different' => translate('the_source_and_target_paths_must_be_different') . '!',
            'status_code.in'      => translate('the_status_code_must_be_301_or_302') . '!',
        ];
    }
}
