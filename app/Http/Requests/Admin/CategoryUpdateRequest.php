<?php

namespace App\Http\Requests\Admin;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\TaxModule\app\Traits\VatTaxManagement;

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
class CategoryUpdateRequest extends FormRequest
{
    use VatTaxManagement;

    protected $stopOnFirstFailure = false;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|array',
            'name.0' => 'required',
            'image' => getRulesStringForImageValidation(
                rules: ['nullable', 'image'],
                skipMimes: ['.svg'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true
            ),
            'mobile_image' => getRulesStringForImageValidation(
                rules: ['nullable', 'image'],
                skipMimes: ['.svg', '.gif'],
            ),
            'remove_mobile_image' => 'nullable|boolean',
            'page_banner' => getRulesStringForImageValidation(
                rules: ['nullable', 'image'],
                skipMimes: ['.svg', '.gif'],
            ),
            'priority' => 'required'
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => translate('category_name_is_required'),
            'name.0.required' => translate('category_name_is_required'),
            'image.mimes' => translate('category_image_must_be_'). getFileUploadFormats(skip: '.svg', asMessage: 'true'),
            'image.max' => translate('category_image_must_not_exceed_'). getFileUploadMaxSize(). "MB",
            'priority.required' => translate('category_priority_is_required'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (
                    isset($this['name'][0]) &&
                    Category::where(['name' => $this['name'][0], 'position' => $this['position']])->where('id', '!=', $this['id'])
                        ->when(isset($this['parent_id']) && !empty($this['parent_id']), function ($query) {
                            return $query->where('parent_id', $this['parent_id']);
                        })
                        ->first()
                ) {
                    $validator->errors()->add(
                        'name.unique', translate('The_category_has_already_been_taken') . '!'
                    );
                }

                $taxData = $this->getTaxSystemType();
                $categoryWiseTax = $taxData['categoryWiseTax'];

                if ($categoryWiseTax && (!isset($this['tax_ids']) || empty($this['tax_ids']))) {
                    $validator->errors()->add(
                        'tax', translate('Please_add_your_category_tax') . '!'
                    );
                }
            }
        ];
    }

}
