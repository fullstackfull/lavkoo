<?php

namespace Modules\AI\app\PromptTemplates;

use Modules\AI\app\Contracts\PromptTemplateInterface;

class SeoSectionTemplate implements PromptTemplateInterface
{

    public function build(?string $context = null, ?string $langCode = null, ?string $description = null, ?array $options = null): string
    {
        $productInfo = $description
            ? "Product name: \"{$context}\". Description: \"" . addslashes($description) . "\"."
            : "Product name: \"{$context}\".";

        return <<<PROMPT
                You are an expert SEO content writer and technical SEO specialist.

                Given the following product information:

                {$productInfo}

                Generate ONLY a JSON object with the following SEO meta fields:

                {
                  "meta_title": "",                  // Concise SEO title (max 100 chars)
                  "meta_description": "",            // Compelling meta description (max 160 chars)

                  "meta_index": "index",             // Either "index" or "noindex"
                  "meta_no_follow": 0,               // 0 or 1 (boolean)
                  "meta_no_image_index": 0,          // 0 or 1
                  "meta_no_archive": 0,              // 0 or 1
                  "meta_no_snippet": 0,              // 0 or 1

                  "meta_max_snippet": 0,             // 0 or 1
                  "meta_max_snippet_value": -1,      // Number, -1 means no limit

                  "meta_max_video_preview": 0,       // 0 or 1
                  "meta_max_video_preview_value": -1,// Number, -1 means no limit

                  "meta_max_image_preview": 0,       // 0 or 1
                  "meta_max_image_preview_value": "large"  // One of "large", "medium", or "small"
                }

                Instructions:
                - Use natural, clear language optimized for search engines.
                - Choose values for index/noindex and booleans based on product info.
                - Keep character limits for title and description.
                - Return ONLY the pure JSON text without markdown, code fences, or explanations.

                IMPORTANT:
                    - If the Name or description is not relevant to e-commerce products or is meaningless, respond with only the word "INVALID_INPUT".
                    - Do not return generic explanations, fallback messages, or apologies.
                PROMPT;
    }

    public function getType(): string
    {
        return  "seo_section";
    }
}
