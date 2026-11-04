<?php

namespace Modules\AI\app\PromptTemplates\Auction;

use Modules\AI\app\Contracts\PromptTemplateInterface;

class AuctionShippingPolicyTemplate implements PromptTemplateInterface
{
    public function build(?string $context = null, ?string $langCode = null, ?string $description = null, ?array $options = null): string
    {
        $currency = getCurrencySymbol();

        return <<<PROMPT
            You are preparing shipping and return suggestions for this auction item:
            - Title: "{$context}"
            - Description: "{$description}"

            Generate ONLY valid JSON with these exact fields:
            {
              "shipping_fee": 0,
              "return_policy": ""
            }

            Instructions:
            - Use realistic placeholder values in currency "{$currency}".
            - Keep return_policy concise and marketplace-appropriate.
            - Return ONLY a valid raw JSON object that can be decoded directly with PHP/Laravel `json_decode()`.
            - Do NOT wrap the JSON in triple backticks.
            - Do NOT add ```json, markdown, explanation, notes, labels, headings, or extra text before or after the JSON.
            - The response must start with "{" and end with "}".
            - Use double quotes for all JSON keys and string values.
            - If the input is invalid or meaningless, return only "INVALID_INPUT".
        PROMPT;
    }

    public function getType(): string
    {
        return 'auction_shipping_policy';
    }
}
