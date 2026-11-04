<?php
namespace App\Http\Requests\API\v3;

use App\Traits\ResponseHandler;
use Illuminate\Foundation\Http\FormRequest;
use App\Rules\DisallowedExtension;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Modules\TaxModule\app\Traits\VatTaxManagement;

class ProductUpdateRequest extends FormRequest
{
    use ResponseHandler;
    use VatTaxManagement;
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $productId = $this->route('id');
        return [
            'name' => 'required',
            'category_id' => 'required',
            'product_type' => 'required',
            'unit' => 'required_if:product_type,==,physical',
            'discount_type' => 'required|in:percent,flat',
            'lang' => 'required',
            'thumbnail' => getRulesStringForImageValidation(
                rules: ['nullable'],
                skipMimes: ['.svg'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true
            ),
            'unit_price' => 'required|min:1',
            'discount' => 'required|gt:-1',
            'shipping_cost' => 'required_if:product_type,==,physical|gt:-1',
            'minimum_order_qty' => 'required|numeric|min:1',
            'code' => 'required|min:6|max:20|regex:/^[a-zA-Z0-9]+$/|unique:products,code,' . $productId,
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Product name is required!',
            'category_id.required' => 'category is required!',
            'unit.required_if' => 'Unit is required!',
            'code.min' => 'The code must be positive!',
            'code.digits_between' => 'The code must be minimum 6 digits!',
            'code.required' => 'Product code sku is required!',
            'minimum_order_qty.required' => 'The minimum order quantity is required!',
            'minimum_order_qty.min' => 'The minimum order quantity must be positive!',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $taxData = $this->getTaxSystemType();
            $productWiseTax = $taxData['productWiseTax'] && !$taxData['is_included'];
            if ($productWiseTax && (!isset($this->tax_ids) || empty(json_decode($this->tax_ids, true)))) {
                $validator->errors()->add('tax', translate('Please_add_your_product_tax') . '!');
            }
            // Preview file validation
            if ($this->preview_file) {
                $disallowedExtensions = ['php', 'java', 'js', 'html', 'exe', 'sh'];
                $maxFileSize = 10 * 1024 * 1024; // 10 MB in bytes
                $extension = $this->preview_file->getClientOriginalExtension();
                $fileSize = $this->preview_file->getSize();

                if ($fileSize > $maxFileSize) {
                    $validator->errors()->add('files', translate('File_size_exceeds_the_maximum_limit_of_10MB') . '!');
                } elseif (in_array($extension, $disallowedExtensions)) {
                    $validator->errors()->add('files', translate('Files_with_extensions_like') . (' .php,.java,.js,.html,.exe,.sh ') . translate('are_not_supported') . '!');
                }
            }
            // Discount validation
            $discount = $this->discount_type == 'percent' ? (($this->unit_price / 100) * $this->discount) : $this->discount;
            if ($this->unit_price <= $discount) {
                $validator->errors()->add('unit_price', translate('Discount can not be more or equal to the price!'));
            }
        });
    }
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'errors' => $this->errorProcessor($validator),
            ], 403)
        );
    }
}
