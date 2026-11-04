<?php

namespace Modules\AI\app\PromptTemplates;

use Modules\AI\app\Contracts\PromptTemplateInterface;
use Modules\AI\app\Services\ProductResourceService;

class ProductVariationSetup implements  PromptTemplateInterface
{
    protected ProductResourceService $productResource;

    public function __construct()
    {
        $this->productResource = new ProductResourceService();
    }

    public function build(?string $context = null, ?string $langCode = null, ?string $description = null, ?array $options = null): string
    {
        $langCode = strtoupper($langCode);

        $resource = $this->productResource->getVariationData();
        $selectedValues = [];
        foreach ($resource['attributes'] as $attrName => $attrId) {
            $selectedValues[] = [
                'id' => (string)$attrId,
                'name' => $attrName,
                'variation' => ''
            ];
        }

        $myColors = [];
        foreach ($resource['color'] as $color) {
            $myColors[] = [
                'color' => $color['code'],
                'text' => $color['name'],
                'name' => $color['name']
            ];
        }

        $attributesList = [];
        foreach ($selectedValues as $attr) {
            $attributesList[] = "{$attr['name']} (ID:{$attr['id']})";
        }
        $attributesString = implode(', ', $attributesList);

        $colorOptions = [];
        foreach ($myColors as $color) {
            $colorOptions[] = "{$color['name']} ({$color['color']})";
        }
        $colorsString = implode(', ', $colorOptions);
        return <<<PROMPT
            You are an expert e-commerce product specialist with deep knowledge of product variations and attributes.
            Given the following product:
            - Name: {$context}
            - Description: {$description}

            Available configuration options from the system:
            - Attributes: {$attributesString}
            - Colors: {$colorsString}

            Generate ONLY a JSON object with the following structure for product variation setup:
            {
              "colors_active": 0,
              "colors": [],
              "choice_attributes": [],
              "genereate_variation": [
                 {
                   "option": "",
                   "sku": "",
                   "price": 0,
                   "stock": 0
                 }
              ]
            }
            Rules:
            1. Use the provided attribute options when generating "choice_attributes".
            2. Select relevant options from the given attributes dynamically, based on product name and description.
            3. Do NOT invent options not present in the provided attributes.
            4. Determine product category (clothing, electronics, digital, etc.) from name/description.
            5. For clothing/fashion:
               - Set colors_active: 1
               - Select 3–5 relevant colors from the provided colors, including both code and name
               - Enable size attribute with ["S","M","L","XL"]
            6. Inside "genereate_variation", create objects for each unique combination of selected attributes and colors.
               Each object must include:
                  - "option":  attribute values (e.g.White-S])
                  - "sku": unique identifier (e.g., "SKU-RED-M")
                  - "price": numeric value for the variation
                  - "stock": integer stock quantity
            7. For electronics:
               - Only enable colors if explicitly mentioned
               - Focus on technical or type attributes
            8. For other products:
               - Only enable variations if clearly relevant
            9. **Output Format Rule:** Return ONLY the raw JSON object — no code blocks, no markdown, no explanation, no labels, no timestamps, no extra text. The response must start with "{" and end with "}".
            IMPORTANT:
               - If the Name or description is not relevant to e-commerce products or is meaningless, respond with only the word "INVALID_INPUT".
               - Do not return generic explanations, fallback messages, or apologies.

            PROMPT;

    }
    public function getType(): string
    {
        return 'variation_setup';
    }

}
