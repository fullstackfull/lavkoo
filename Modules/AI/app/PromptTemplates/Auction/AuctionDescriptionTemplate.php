<?php

namespace Modules\AI\app\PromptTemplates\Auction;

use Modules\AI\app\Contracts\PromptTemplateInterface;

class AuctionDescriptionTemplate implements PromptTemplateInterface
{
    public function build(?string $context = null, ?string $langCode = null, ?string $description = null, ?array $options = null): string
    {
        $langCode = strtoupper($langCode ?? 'en');

        return <<<PROMPT
            You are a professional auction marketplace copywriter.

            Generate a clear and persuasive auction listing description for "{$context}".

            CRITICAL LANGUAGE RULES:
            - The entire response must be written 100% in {$langCode}.
            - Do not mix languages.

            CONTENT RULES:
            - Start with a short paragraph summarizing the item.
            - Add a few clear feature or condition-focused paragraphs.
            - Mention likely buyer value, use cases, and visible condition carefully without inventing certification claims.
            - Keep the tone professional and marketplace-ready.

            FORMATTING:
            - Return valid HTML using only <p>, <b>, <h1>, <h2>, <ol>, <li>, and <span>.
            - Do NOT include markdown, code fences, triple backticks, or wrappers like ```html``` or ```HTML```.
            - Return only the raw HTML content, with no markdown and no explanation.

            IMPORTANT:
            - If the input is meaningless or unrelated to a valid auction product, respond with only "INVALID_INPUT".
        PROMPT;
    }

    public function getType(): string
    {
        return 'auction_description';
    }
}
