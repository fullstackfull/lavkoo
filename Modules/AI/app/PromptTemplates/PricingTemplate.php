<?php

namespace Modules\AI\app\PromptTemplates;

use Modules\AI\app\Contracts\PromptTemplateInterface;

class PricingTemplate implements  PromptTemplateInterface
{
    public function build(?string $context = null, ?string $langCode = null, ?string $description = null, ?array $options = null): string
    {
        $currency = getCurrencySymbol();
        $productInfo = $description
            ? "Product name: \"{$context}\". Description: \"" . addslashes($description) . "\"."
            : "Product name: \"{$context}\".";

        return <<<PROMPT
              You are an expert pricing analyst.

              Given the following product information:

              {$productInfo}

              Using the currency symbol "{$currency}", provide ONLY a JSON object with pricing details below.
              Set realistic values based on the product info and currency.

              The JSON must contain exactly these fields:

              {
                "unit_price": 100.00,
                "minimum_order_quantity": 1,
                "current_stock": 50,
                "discount_type": "flat",        // or "percent"
                "discount_amount": 0.00,
                "shipping_cost": 0.00,
                "is_shipping_cost_multil": 0    // 0 or 1
              }

              IMPORTANT:
                - Return ONLY the pure JSON text with no markdown, no code fences, no extra text or explanation.
                - If the product name or description is not relevant to e-commerce products or is meaningless, respond with only the word "INVALID_INPUT".
                - Do not return generic explanations, fallback messages, or apologies.
              PROMPT;
    }

    public function getType(): string
    {
        return 'pricing_and_others';
    }
}
