<?php

namespace Modules\AI\app\PromptTemplates\Auction;

use Modules\AI\app\Contracts\PromptTemplateInterface;
use Modules\AI\app\Services\Auction\AuctionResourceService;

class AuctionInfoTemplate implements PromptTemplateInterface
{
    public function __construct(
        private readonly AuctionResourceService $auctionResourceService = new AuctionResourceService()
    ) {
    }

    public function build(?string $context = null, ?string $langCode = null, ?string $description = null, ?array $options = null): string
    {
        $currency = getCurrencySymbol();
        $taxOptions = implode("', '", array_keys($this->auctionResourceService->taxOptions()));
        $currentDateTime = now()->format('Y-m-d H:i:s');

        return <<<PROMPT
            You are generating auction setup suggestions for this listing:
            - Title: "{$context}"
            - Description: "{$description}"

            Generate ONLY valid JSON with these exact fields:
            {
              "entry_fee": 5,
              "starting_price": 50,
              "minimum_increment_amount": 5,
              "maximum_decrement_amount": 10,
              "tax_names": ["<pick one from the available tax options below>"],
              "start_time": "",
              "end_time": "",
              "tags": ["<short search keyword>", "<short search keyword>", "<short search keyword>"]
            }

            Instructions:
            - Use realistic placeholder values in currency "{$currency}".
            - "minimum_increment_amount" MUST be greater than 0. Never return 0.
            - "maximum_decrement_amount" MUST be greater than 0. Never return 0.
            - "tax_names" MUST contain at least one name from the provided tax options. Select the most applicable tax for this type of product. Never return an empty array for "tax_names".
            - "tax_names" must contain only names that exist exactly in the provided tax options list. Do NOT invent or guess tax names.
            - The current date and time is "{$currentDateTime}".
            - "start_time" must be the current time or a future time. NEVER use a past/backdated time.
            - "end_time" must be a future time and must be later than "start_time".
            - Both "start_time" and "end_time" must be valid datetime strings in "Y-m-d H:i:s" format.
            - "tags" MUST be an array of 3 to 8 short search keywords customers would type to discover this listing (e.g. brand, type, material, era, use case). Each keyword: lowercase, 1-3 words, no punctuation, no duplicates, no hashtags.
            - Return ONLY a valid raw JSON object that can be decoded directly with PHP/Laravel `json_decode()`.
            - Do NOT wrap the JSON in triple backticks.
            - Do NOT add ```json, markdown, explanation, notes, labels, headings, or extra text before or after the JSON.
            - The response must start with "{" and end with "}".
            - Use double quotes for all JSON keys and string values.
            - If the input is invalid or meaningless, return only "INVALID_INPUT".

            Available tax options:
            - '{$taxOptions}'
        PROMPT;
    }

    public function getType(): string
    {
        return 'auction_info';
    }
}
