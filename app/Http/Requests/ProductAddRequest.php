<?php

namespace App\Http\Requests;

use App\Traits\CalculatorTrait;
use App\Traits\ResponseHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;
use Modules\TaxModule\app\Traits\VatTaxManagement;

use App\Http\Requests\Concerns\ChecksBrandEntitlement;

class ProductAddRequest extends Request
{
    use ChecksBrandEntitlement;

    use CalculatorTrait, ResponseHandler;
    use VatTaxManagement;

    protected $stopOnFirstFailure = false;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = [
            'name' => 'required',
            'category_id' => 'required',
            'product_type' => 'required',
            'digital_product_type' => 'required_if' . ':' . 'product_type' . ',==,' . 'digital',
            // 'digital_file_ready' => 'required_if' . ':' . 'digital_product_type' . ',==,' . 'ready_product' . '|' . 'mimes' . ':jpg,jpeg,png,gif,zip,pdf',
            'unit' => 'required_if' . ':' . 'product_type' . ',==,' . 'physical',
            'unit_price' => 'required' . '|' . 'numeric' . '|' . 'gt' . ':0',
            'discount' => 'required' . '|' . 'gt' . ':-1',
            'shipping_cost' => 'required_if' . ':' . 'product_type' . ',==,' . 'physical' . '|' . 'gt' . ':-1',
       'code' => 'required|regex:/^[a-zA-Z0-9]+$/|unique:products,code',
            // The barcode comes off a box, not from us: optional, and unique so two products can
            // never answer the same scan.
            'barcode' => 'nullable|regex:/^[a-zA-Z0-9\-]+$/|max:64|unique:products,barcode',
            'minimum_order_qty' => 'required' . '|' . 'numeric' . '|' . 'min' . ':1',
            'video_url' => 'nullable|url',
        ];

        if (!isset($this['existing_thumbnail'])) {
            $rules['image'] = 'required';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'image' . '.' . 'required' => translate('product_thumbnail_is_required!'),
            'category_id' . '.' . 'required' => translate('category_is_required!'),
            'unit' . '.' . 'required_if' => translate('unit_is_required!'),
            'code.max' => translate('please_ensure_your_code_does_not_exceed_20_characters'),
            'code.min' => translate('code_with_a_minimum_length_requirement_of_6_characters'),
            'minimum_order_qty' . '.' . 'required' => translate('minimum_order_quantity_is_required!'),
            'minimum_order_qty' . '.' . 'min' => translate('minimum_order_quantity_must_be_positive!'),
            // 'digital_file_ready' . '.' . 'required_if' => translate('ready_product_upload_is_required!'),
            // 'digital_file_ready' . '.' . 'mimes' => translate('ready_product_upload_must_be_a_file_of_type') . ':' . 'pdf, zip, jpg, jpeg, png, gif.',
            'digital_product_type' . '.' . 'required_if' => translate('digital_product_type_is_required!'),
            'shipping_cost' . '.' . 'required_if' => translate('shipping_cost_is_required!'),
            'video_url.url' => translate('please_provide_a_valid_video_url'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $this->validateBrandEntitlement($validator);


                $disallowedExtensions = getDisallowedExtensionsListArray();

                $description = $this->input('description');
                if (is_array($description)) {
                    $first = reset($description);
                    $cleanedDescription = is_string($first) ? trim(strip_tags($first)) : null;
                } else {
                    $cleanedDescription = is_string($description) ? trim(strip_tags($description)) : null;
                }
                if (empty($cleanedDescription)) {
                    $validator->errors()->add(
                        'description', translate('Product_description_is_required') . '!'
                    );
                }

                if (!isset($this['existing_thumbnail']) && $this->file('image')) {
                    $file = $this->file('image');
                    $extension = strtolower($file->getClientOriginalExtension());

                    if (in_array($extension, $disallowedExtensions)) {
                        $validator->errors()->add(
                            'image',
                            translate('The_uploaded_image_file_type_is_not_supported'). '!'
                        );
                    }
                }

                $taxData = $this->getTaxSystemType();
                $productWiseTax = $taxData['productWiseTax'] && !$taxData['is_included'];

                if ($productWiseTax && (!isset($this['tax_ids']) || empty($this['tax_ids']))) {
                    $validator->errors()->add(
                        'tax', translate('Please_add_your_product_tax') . '!'
                    );
                }

                if (!($this->has('colors_active') && $this->has('colors') && count($this['colors']) > 0) && !$this->file('images') && !$this->has('existing_images')) {
                    $validator->errors()->add(
                        'images', translate('product_images_is_required') . '!'
                    );
                }

                if ($this['product_type'] == 'physical' && $this['unit_price'] <= $this->getDiscountAmount(price: $this['unit_price'] ?? 0, discount: $this['discount'], discountType: $this['discount_type'])) {
                    $validator->errors()->add(
                        'unit_price', translate('discount_can_not_be_more_or_equal_to_the_price') . '!'
                    );
                }

                if (is_null($this['name'][array_search('EN', $this['lang'])])) {
                    $validator->errors()->add(
                        'name', translate('name_field_is_required') . '!'
                    );
                }

                $productImagesCount = 0;
                if ($this->has('colors_active') && $this->has('colors') && count($this['colors']) > 0) {
                    foreach ($this['colors'] as $color) {
                        $color_ = str_replace('#', '', $color);
                        $image = 'color_image_' . $color_;
                        if ($this->file($image)) {
                            $productImagesCount++;
                        } else if ($this->has($image)) {
                            $productImagesCount++;
                        }

                    }
                    if ($productImagesCount != count($this['colors'])) {
                        $validator->errors()->add(
                            'images', translate('color_images_is_required') . '!'
                        );
                    }
                }

                if ($this['product_type'] == 'physical' && ($this->has('colors') || ($this->has('choice_attributes') && count($this['choice_attributes']) > 0))) {
                    foreach ($this->all() as $requestKey => $requestValue) {
                        if (str_contains($requestKey, 'sku_')) {
                            if (empty($this[$requestKey])) {
                                $validator->errors()->add(
                                    'sku_error', translate('Variation_SKU_are_required') . '!'
                                );
                            }
                        }

                        if (str_contains($requestKey, 'choice_attributes') && !empty($this['choice_attributes']) && is_array($this['choice_attributes'])) {
                            foreach ($this['choice_attributes'] as $key => $value) {
                                if (isset($this['choice_options_'.$value]) && empty($this['choice_options_'.$value])) {
                                    $validator->errors()->add(
                                        'choice_attributes_error', translate('Attributes_data_are_required') . '!'
                                    );
                                }

                                if (isset($this['choice_options_'.$value]) && !empty($this['choice_options_'.$value])) {

                                    if (is_array($this['choice_options_'.$value])) {
                                        $choiceOptions = collect($this['choice_options_'.$value])
                                            ->filter(fn ($value) => !is_null($value))
                                            ->values()
                                            ->all();

                                        if (count($choiceOptions) <= 0) {
                                            $validator->errors()->add(
                                                'choice_attributes_error', translate('Attributes_data_are_required') . '!'
                                            );
                                        }
                                    }
                                }
                            }
                        }

                        if (str_contains($requestKey, 'price_')) {
                            if (empty($this[$requestKey]) || $this[$requestKey] < 0) {
                                $validator->errors()->add(
                                    'variation_price', translate('Variation_price_are_required') . '!'
                                );
                            } else if ($this[$requestKey] <= $this->getDiscountAmount(price: $this[$requestKey] ?? 0, discount: $this['discount'], discountType: $this['discount_type'])) {
                                $validator->errors()->add(
                                    'variation_price', translate('discount_can_not_be_more_or_equal_to_the_variation_price') . '!'
                                );
                            }
                        }
                    }
                }

                if ($this['product_type'] == 'digital') {
                    $digitalProductVariationCount = 0;
                    if ($this['extensions_type'] && count($this['extensions_type']) > 0) {
                        $options = [];
                        foreach ($this['extensions_type'] as $type) {
                            $name = 'extensions_options_' . $type;
                            $my_str = implode('|', is_array($this[$name] ?? null) ? $this[$name] : []);
                            $options[$type] = explode(',', $my_str);
                        }

                        foreach ($options as $arrayKey => $array) {
                            foreach ($array as $key => $value) {
                                if ($value) {
                                    $digitalProductVariationCount++;
                                }
                            }
                        }

                        if ($digitalProductVariationCount == 0) {
                            $validator->errors()->add(
                                'variation_error', translate('Digital_Product_variations_are_required') . '!'
                            );
                        }

                        if ($this['digital_product_type'] == 'ready_product' && empty($this['digital_files'])) {
                            $validator->errors()->add(
                                'files', translate('Digital_files_are_required') . '!'
                            );
                        }

                        if ($this['digital_files'] && $digitalProductVariationCount != count($this['digital_files'])) {
                            $validator->errors()->add(
                                'files', translate('Digital_files_are_required') . '!'
                            );
                        }

                        if (
                            $this['product_type'] === 'digital' &&
                            $this->hasFile('digital_files')
                        ) {
                            $maxFileSize = 10 * 1024 * 1024; // 10 MB

                            foreach ($this->file('digital_files') as $index => $file) {
                                if (!$file) {
                                    continue;
                                }

                                $extension = strtolower($file->getClientOriginalExtension());
                                $fileSize = $file->getSize();

                                if ($fileSize > $maxFileSize) {
                                    $validator->errors()->add(
                                        "digital_files.$index",
                                        translate('File_size_exceeds_the_maximum_limit_of_10MB') . '!'
                                    );
                                }

                                if (in_array($extension, $disallowedExtensions)) {
                                    $validator->errors()->add("digital_files.$index", $extension. translate('_file_type_is_not_supported')  . '!');
                                }
                            }
                        }


                        if ($this->has('digital_product_sku') && empty($this['digital_product_sku'])) {
                            $validator->errors()->add(
                                'sku_error', translate('Digital_SKU_are_required') . '!'
                            );
                        } elseif ($this->has('digital_product_sku') && !empty($this['digital_product_sku'])) {
                            foreach ($this['digital_product_sku'] as $digitalSKU) {
                                if (empty($digitalSKU)) {
                                    $validator->errors()->add(
                                        'sku_error', translate('Digital_SKU_are_required') . '!'
                                    );
                                }
                            }
                        }

                    } else {
                        if ($this['digital_product_type'] == 'ready_product' && empty($this['digital_file_ready'])) {
                            $validator->errors()->add(
                                'files', translate('Digital_files_are_required') . '!'
                            );
                        }
                    }

                    if ($this->has('digital_product_price') && !empty($this['digital_product_price'])) {
                        foreach ($this['digital_product_price'] as $digitalPrice) {
                            if (empty($digitalPrice) || $digitalPrice < 0) {
                                $validator->errors()->add(
                                    'variation_price', translate('Digital_variation_price_are_required') . '!'
                                );
                            } else if ($digitalPrice <= $this->getDiscountAmount(price: $digitalPrice, discount: $this['discount'], discountType: $this['discount_type'])) {
                                $validator->errors()->add(
                                    'variation_price', translate('discount_can_not_be_more_or_equal_to_the_digital_variation_price') . '!'
                                );
                            }
                        }
                    }
                }

                if ($this['product_type'] === 'digital' && $this['digital_product_type'] === 'ready_product' && $this->hasFile('digital_file_ready')) {

                    $maxFileSize = getFileUploadMaxSize(type:'file', unit: 'kb');
                    $file = $this->file('digital_file_ready');
                    $fileSize = $file->getSize() / 1024;
                    if ($fileSize > $maxFileSize) {
                        $validator->errors()->add(
                            'digital_file_ready',
                            translate('File_size_exceeds_the_maximum_limit_of_').getFileUploadMaxSize(type:'file') . '!'
                        );
                    }
                    $extension = strtolower($file->getClientOriginalExtension());


                    if ($fileSize > $maxFileSize) {
                        $validator->errors()->add(
                            'digital_file_ready',
                            translate('File_size_exceeds_the_maximum_limit_of_10MB') . '!'
                        );
                    }

                    if (in_array($extension, $disallowedExtensions)) {
                        $validator->errors()->add(
                            'digital_file_ready',
                            translate('The_uploaded_file_type_is_not_supported'). '!'
                        );
                    }
                }

                if ($this['preview_file']) {
                    $maxFileSize = 10 * 1024 * 1024; // 10 MB in bytes
                    $extension = $this['preview_file']->getClientOriginalExtension();
                    $fileSize = $this['preview_file']->getSize();

                    if ($fileSize > $maxFileSize) {
                        $validator->errors()->add(
                            'files', translate('File_size_exceeds_the_maximum_limit_of_10MB') . '!'
                        );
                    } elseif (in_array($extension, $disallowedExtensions)) {
                        $validator->errors()->add(
                            'files', translate('Files_with_extensions_like') . (' .php,.java,.js,.html,.exe,.sh ') . translate('are_not_supported') . '!'
                        );
                    }
                }

                if ($this['video_url']) {
                    if (!str_contains($this['video_url'], 'youtube.com/embed/')) {
                        $validator->errors()->add(
                            'video_url',
                            translate('Please_provide_valid_youtube_embed_link')
                        );
                    }
                }
            }
        ];
    }

    /**
     * Handle a passed validation attempt.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(response()->json(['errors' => $this->errorProcessor($validator)]));
    }
}