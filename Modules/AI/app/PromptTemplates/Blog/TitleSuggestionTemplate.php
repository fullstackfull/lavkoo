<?php

namespace Modules\AI\app\PromptTemplates\Blog;

use Modules\AI\app\Contracts\PromptTemplateInterface;

class TitleSuggestionTemplate implements PromptTemplateInterface
{

    public function build(?string $context = null, ?string $langCode = null, ?string $description = null, ?array $options = null): string
    {
        $langCode = strtoupper($langCode);
        $keywordsText = $context;
        if (is_array($context)) {
            $keywordsText = implode(' ', $context);
        }
        return <<<PROMPT
                You are an expert SEO content strategist and professional copywriter.

               Using the keywords "{$keywordsText}", generate 4 professional, clean, and concise blog titles for online stores.

               CRITICAL INSTRUCTIONS:
               - The output must be 100% in {$langCode}.
               - Titles must use the keywords naturally.
               - Keep them short (35–70 characters), clear, and ready for listings.
               - Return exactly 4 titles in **plain JSON** format as shown below (do not include ```json``` or any extra markdown):

               {
                 "titles": [
                   "Title 1",
                   "Title 2",
                   "Title 3",
                   "Title 4"
                 ]
               }

               Do not include any extra explanation, only return the JSON.

                IMPORTANT:
                - If the keywords are not relevant or is meaningless, respond with only the word "INVALID_INPUT".
                - Do not return generic explanations, fallback messages, or apologies.
               PROMPT;
    }

    public function getType(): string
    {
        return 'blog_title_suggestion';
    }
}
