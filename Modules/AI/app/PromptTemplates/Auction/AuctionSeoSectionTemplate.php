<?php

namespace Modules\AI\app\PromptTemplates\Auction;

use Modules\AI\app\Contracts\PromptTemplateInterface;

class AuctionSeoSectionTemplate implements PromptTemplateInterface
{
    public function build(?string $context = null, ?string $langCode = null, ?string $description = null, ?array $options = null): string
    {
        $auctionInfo = $description
            ? "Auction item title: \"{$context}\". Description: \"" . addslashes($description) . "\"."
            : "Auction item title: \"{$context}\".";

        return <<<PROMPT
            You are an expert SEO writer for auction marketplace listings.

            Given the following auction item:
            {$auctionInfo}

            Generate ONLY a JSON object in this exact shape:

            {
              "meta_title": "",
              "meta_description": "",
              "meta_index": "index",
              "meta_no_follow": 0,
              "meta_no_image_index": 0,
              "meta_no_archive": 0,
              "meta_no_snippet": 0,
              "meta_max_snippet": 0,
              "meta_max_snippet_value": -1,
              "meta_max_video_preview": 0,
              "meta_max_video_preview_value": -1,
              "meta_max_image_preview": 0,
              "meta_max_image_preview_value": "large"
            }

            Instructions:
            - Keep title concise and meta description compelling.
            - Return ONLY a valid raw JSON object that can be decoded directly with PHP/Laravel `json_decode()`.
            - Do NOT wrap the JSON in triple backticks.
            - Do NOT add ```json, markdown, explanation, notes, labels, headings, or extra text before or after the JSON.
            - The response must start with "{" and end with "}".
            - Use double quotes for all JSON keys and string values.
            - If the input is invalid or meaningless, respond with only "INVALID_INPUT".
        PROMPT;
    }

    public function getType(): string
    {
        return 'auction_seo_section';
    }
}
