<?php

namespace Modules\AI\app\PromptTemplates\Blog;

use Modules\AI\app\Contracts\PromptTemplateInterface;

class BlogSeoSectionTemplate implements PromptTemplateInterface
{

    public function build(?string $context = null, ?string $langCode = null, ?string $description = null, ?array $options = null): string
    {
        $blogInfo = $description
            ? "Blog Title: \"{$context}\". Description: \"" . addslashes($description) . "\"."
            : "Blog Title: \"{$context}\".";

        return <<<PROMPT
                You are an expert SEO content writer and technical SEO specialist.

                Given the following blog content:
                {$blogInfo}
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
                - Optimize meta_title and meta_description for blog content.
                - Keep character limits.
                - Return ONLY the pure JSON text.
                - Do NOT include markdown, code fences, or triple backticks or ```html ``` or ```json ```.
                - If the input text is meaningless or empty, respond only with "INVALID_INPUT"
                - Do not return generic explanations, fallback messages, or apologies.
                PROMPT;
    }

    public function getType(): string
    {
        return "blog_seo_section";
    }
}
