<?php

namespace App\Http\Requests\Admin;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $icon
 * @property int $parent_id
 * @property int $position
 * @property int $home_status
 * @property int $priority
 */
class SubCategoryAddRequest extends FormRequest
{
    protected $stopOnFirstFailure = false;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required',
            'priority' => 'required',
            'parent_id' => 'required',
            // A sub-category may carry its own icon. Optional — the storefront falls back to a
            // letter chip — but when one IS uploaded it goes through the same format and size
            // gate as a main category's, or an unvalidated file reaches the disk.
            'image' => getRulesStringForImageValidation(
                rules: ['nullable', 'image'],
                skipMimes: ['.svg'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true,
            ),
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => translate('category_name_is_required'),
            'priority.required' => translate('category_priority_is_required'),
            'parent_id.required' => translate('Main_Category_is_required'),
            'image.mimes' => translate('The_image_must_be_a_file_of_type_jpeg_jpg_png_gif'),
            'image.max' => translate('The_image_may_not_be_greater_than_2_MB'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (
                    isset($this['name'][0]) &&
                    Category::where(['name' => $this['name'][0], 'position' => $this['position']])
                        ->when(isset($this['parent_id']) && !empty($this['parent_id']), function ($query) {
                            return $query->where('parent_id', $this['parent_id']);
                        })
                        ->first()
                ) {
                    $validator->errors()->add(
                        'name.unique', translate('The_category_has_already_been_taken') . '!'
                    );
                }
            }
        ];
    }
}
