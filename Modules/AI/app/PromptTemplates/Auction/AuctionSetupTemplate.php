<?php

namespace Modules\AI\app\PromptTemplates\Auction;

use Modules\AI\app\Contracts\PromptTemplateInterface;

class AuctionSetupTemplate implements PromptTemplateInterface
{
    public function build(?string $context = null, ?string $langCode = null, ?string $description = null, ?array $options = null): string
    {
        return <<<PROMPT
            You are assisting with auction listing setup for this item:
            - Title: "{$context}"
            - Description: "{$description}"

            Generate ONLY valid JSON with these exact fields:
            {
              "item_condition": "GOOD",
              "starting_price": 50,
              "minimum_increment_amount": 5,
              "maximum_decrement_amount": 10,
              "shipping_fee": 5,
              "return_policy": ""
            }

            Instructions:
            - Suggest realistic placeholder setup values based on the item context.
            - "minimum_increment_amount" MUST be greater than 0. Never return 0.
            - "maximum_decrement_amount" MUST be greater than 0. Never return 0.
            - Numeric fields must be numbers.
            - Return only raw JSON with no extra text.
            - If the input is invalid or meaningless, respond with only "INVALID_INPUT".
        PROMPT;
    }

    public function getType(): string
    {
        return 'auction_setup';
    }
}
