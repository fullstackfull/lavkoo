<?php

namespace Modules\AI\app\PromptTemplates\Auction;

use Modules\AI\app\Contracts\PromptTemplateInterface;
use Modules\AI\app\Services\Auction\AuctionResourceService;

class AuctionGeneralSetupTemplate implements PromptTemplateInterface
{
    public function __construct(
        private readonly AuctionResourceService $auctionResourceService = new AuctionResourceService()
    ) {
    }

    public function build(?string $context = null, ?string $langCode = null, ?string $description = null, ?array $options = null): string
    {
        $resources = $this->auctionResourceService->generalSetupData();
        $categories = implode("', '", array_keys($resources['categories']));
        $brands = implode("', '", array_keys($resources['brands']));
        $productTypes = implode("', '", $resources['product_types']);
        $itemConditions = implode("', '", $resources['item_conditions']);

        return <<<PROMPT
            Analyze this auction item:
            - Title: "{$context}"
            - Description: "{$description}"

            Generate ONLY valid JSON with these exact fields:
            {
              "product_type": "physical",
              "category_name": "",
              "brand_name": "",
              "item_condition": ""
            }

            Instructions:
            - Choose the best matching values from the provided options.
            - "category_name" and "brand_name" must be selected from the provided lists only.
            - Return the exact option text for "category_name" and "brand_name", not a synonym, variation, explanation, or invented brand.
            - "brand_name" MUST be one of the provided brands. Always choose the closest semantic or domain fit from the list. Never return an empty string for "brand_name".
            - If no brand is an obvious match by name, pick the most relevant one by product type or domain — always return a non-empty "brand_name" from the list.
            - Return ONLY a valid raw JSON object that can be decoded directly with PHP/Laravel `json_decode()`.
            - Do NOT wrap the JSON in triple backticks.
            - Do NOT add ```json, markdown, explanation, notes, labels, headings, or extra text before or after the JSON.
            - The response must start with "{" and end with "}".
            - Use double quotes for all JSON keys and string values.
            - If the input is invalid or meaningless, return only "INVALID_INPUT".

            Available options:
            - Product types: '{$productTypes}'
            - Categories: '{$categories}'
            - Brands: '{$brands}'
            - Item conditions: '{$itemConditions}'
        PROMPT;
    }

    public function getType(): string
    {
        return 'auction_general_setup';
    }
}
