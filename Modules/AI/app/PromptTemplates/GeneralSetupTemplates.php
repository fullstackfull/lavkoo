<?php

namespace Modules\AI\app\PromptTemplates;

use Modules\AI\app\Contracts\PromptTemplateInterface;
use Modules\AI\app\Services\ProductResourceService;

class GeneralSetupTemplates implements  PromptTemplateInterface
{
    protected ProductResourceService $productResource;

    public function __construct()
    {
        $this->productResource = new ProductResourceService();
    }

    public function build(?string $context = null, ?string $langCode = null, ?string $description = null, ?array $options = null): string
    {
        $resource = $this->productResource->productGeneralSetupData();
        $categories      = $resource['categories'];
        $subCategories   = $resource['sub_categories'];
        $subSubCategories = $resource['sub_sub_categories'];
        $brands          = $resource['brands'];
        $units           = $resource['units'];
        $productTypes    = $resource['product_types'];
        $deliveryType    = implode("', '",$resource['delivery_types']);
        $categories = implode("', '", array_keys($categories));
        $subCategories = implode("', '", array_keys($subCategories));
        $subSubCategories = implode("', '", array_keys($subSubCategories));
        $brands = implode("', '", array_keys($brands));
        $units = implode("', '", $units);
        $productTypes = implode("', '", $productTypes);
        return <<<PROMPT
            Analyze the product with these details:
            - Name: '{$context}'
            - Description: '{$description}'

            Generate ONLY valid JSON with these exact fields:

            {
              "category_name": "Category name",
              "sub_category_name": "Sub-category name",
              "sub_sub_category_name": "Sub-sub-category name",
              "brand_name": "Brand name",
              "unit_name": "Unit name",
              "product_type": "Product type",
              "delivery_type" "Delivery Type"
              "search_tags": ["tag1", "tag2"]
            }

            === INSTRUCTIONS ===
            1. SELECT the best matching category, sub-category, brand, unit, and product type from the provided options.
            2. IF multiple options are possible, choose the most specific.
            3. Extract 3-5 relevant search tags from the name and description.
            4. sub_sub_category_name is optional; include if applicable.
            5. DO NOT include comments, explanations, or any text outside the JSON.
            6. JSON must be valid for json_decode in PHP.

            === AVAILABLE OPTIONS ===
            [MAIN CATEGORIES] '{$categories}'
            [SUB CATEGORIES] '{$subCategories}'
            [SUB-SUB CATEGORIES] '{$subSubCategories}'
            [BRANDS] '{$brands}'
            [DELIVERY_TYPE] '{$deliveryType}'
            [UNITS] '{$units}'
            [PRODUCT TYPES] '{$productTypes}'

            === OUTPUT FORMAT RULE ===
             - Return ONLY the raw JSON object — no code blocks, no markdown, no explanation, no labels, no timestamps, no extra text,(do not include ```json```).
             - The response must start with "{" and end with "}".
             - Only respond with "INVALID_INPUT" if the name or description is completely irrelevant, nonsensical, or empty.
             - Do not return generic explanations, fallback messages, or apologies.
        PROMPT;


    }

    public function getType(): string
    {
        return 'general_setup';
    }

}
