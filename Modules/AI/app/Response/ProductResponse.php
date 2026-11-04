<?php

namespace Modules\AI\app\Response;

use http\Exception\RuntimeException;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Modules\AI\app\Exceptions\ValidationException;
use Modules\AI\app\Services\ProductResourceService;
use Modules\TaxModule\app\Traits\VatTaxManagement;

class ProductResponse
{
    use VatTaxManagement;

    protected ProductResourceService $productResource;

    public function __construct()
    {
        $this->productResource = new ProductResourceService();
    }

    public function productGeneralSetupAutoFillFormat(string $result): array
    {
        $resource = $this->productResource->productGeneralSetupData();
        $data = json_decode($result, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
        }

        if (empty($data['category_name']) || !is_string($data['category_name'])) {
            throw new InvalidArgumentException('The "category_name" field is required and must be a non-empty string.');
        }
        if (empty($data['unit_name']) || !is_string($data['unit_name'])) {
            throw new InvalidArgumentException('The "unit_name" field is required and must be a non-empty string.');
        }
        if (empty($data['unit_name']) || !is_string($data['product_type'])) {
            throw new InvalidArgumentException('The "product_type" field is required and must be a non-empty string.');
        }

        $processedData = $this->productGeneralSetConvertNamesToIds($data, $resource);
        if (!$processedData['success']) {
            return $processedData;
        }
        $data = $processedData['data'];

        $fields = [
            'sub_category_name',
            'sub_sub_category_name',
            'brand_name',
            'unit_name',
            'product_type',
            'search_tags'
        ];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                $data[$field] = null;
            }
        }

        return $data;

    }

    public function productPriceAndOthersAutoFill($result): array|JsonResponse
    {
        $taxData = $this->getTaxSystemType();
        $productWiseTax = $taxData['productWiseTax'] && !$taxData['is_included'];
        $taxVats = $taxData['taxVats'];
        $data = json_decode($result, true);

        if ($productWiseTax) {
            $taxVats = $taxData['taxVats']->map(function ($v) {
                return [
                    'id' => $v['id'],
                    'name' => $v['name'],
                    'tax_rate' => $v['tax_rate'],
                ];
            })->values()->toArray();
        }
        $data['vatTax'] = $taxVats;
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
        }
        $fields = [
            'unit_price',
            'minimum_order_quantity',
            'current_stock',
            'discount_type',
            'discount_amount',
            'shipping_cost',
        ];

        $errors = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                $errors[$field] = "$field is required.";
            }
        }

        if (!empty($errors)) {
            return response()->json(
                $this->formatAIGenerationValidationErrors($errors),
                422
            );
        }
        $data['unit_price'] = round($data['unit_price']);
        return $data;
    }

    public function productSeoAutoFill($result): array
    {
        $data = json_decode($result, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
        }

        $fields = [
            'meta_title',
            'meta_description',
            'meta_index',
            'meta_no_follow',
            'meta_no_image_index',
            'meta_no_archive',
            'meta_no_snippet',
            'meta_max_snippet',
            'meta_max_snippet_value',
            'meta_max_video_preview',
            'meta_max_video_preview_value',
            'meta_max_image_preview',
            'meta_max_image_preview_value',
        ];

        $errors = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                $errors[$field] = "$field is required.";
            }
        }

        if (!empty($errors)) {
            throw new RuntimeException($this->formatAIGenerationValidationErrors($errors));
        }

        return $data;
    }

    private function formatAIGenerationValidationErrors(array $errors): string
    {
        $messages = [];

        foreach ($errors as $message) {
            $messages[] = $message;
        }

        return 'AI couldn’t generate product ' . implode(' ', $messages);
    }

    /**
     * @throws ValidationException
     */
    public function variationSetupAutoFill(string $result): array
    {
        $data = json_decode($result, true);
        $errors = [];

        if (empty($data['choice_attributes']) || !is_array($data['choice_attributes'])) {
            $errors['choice_attributes'] = 'choice attributes .Please provide a more specific product name and a clear description';
        }

        if (isset($data['colors_active']) && $data['colors_active'] == 1) {
            if (empty($data['colors']) || !is_array($data['colors'])) {
                $errors['colors'] = 'Color variation. Please provide a more specific product name and a clear description';
            }
        }

        if (isset($data['genereate_variation']) && is_array($data['genereate_variation'])) {
            foreach ($data['genereate_variation'] as &$variation) {
                $variation['price'] = isset($variation['price']) ? round($variation['price']) : 0;
            }
        }
        $response = [
            'data' => $data,
        ];

        if (!empty($errors)) {
            throw new ValidationException($this->formatAIGenerationValidationErrors($errors));
        }

        $response['status'] = 'success';
        return $response;
    }

    public function generateTitleSuggestions(string $result)
    {
        return json_decode($result, true);

    }

    public function productGeneralSetConvertNamesToIds(array $data, array $resources): array
    {
        if (isset($data['category_name'])) {
            $categoryName = strtolower(trim($data['category_name']));
            if (isset($resources['categories'][$categoryName])) {
                $data['category_id'] = $resources['categories'][$categoryName];
            } else {
                $errors[] = "Invalid category name: {$data['category_name']}";
            }
        }

        if (isset($data['sub_category_name'])) {
            $subCategoryName = strtolower(trim($data['sub_category_name']));
            if (isset($resources['sub_categories'][$subCategoryName])) {
                $data['sub_category_id'] = $resources['sub_categories'][$subCategoryName];
            }
        }
        if (isset($data['sub_sub_category_name'])) {
            $subSubCategoryName = strtolower(trim($data['sub_sub_category_name']));
            if (isset($resources['sub_sub_categories'][$subSubCategoryName])) {
                $data['sub_sub_category_id'] = $resources['sub_sub_categories'][$subSubCategoryName];
            }
        }
        if (isset($data['brand_name'])) {
            $brandName = strtolower(trim($data['brand_name']));
            if (isset($resources['brands'][$brandName])) {
                $data['brand_id'] = $resources['brands'][$brandName];
            }
        }

        if (!empty($errors)) {
            throw new \RuntimeException($this->formatAIGenerationValidationErrors($errors));
        }

        return [
            'success' => true,
            'data' => $data
        ];
    }
}
