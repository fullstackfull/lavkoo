<?php

namespace Modules\AI\app\PromptTemplates;

use Modules\AI\app\Contracts\PromptTemplateInterface;

class ProductNameTemplate implements  PromptTemplateInterface
{
    public function build(?string $context = null, ?string $langCode = null, ?string $description = null, ?array $options = null): string
    {
        $langCode = strtoupper($langCode);

        return <<<PROMPT
          You are a professional e-commerce copywriter.

          Rewrite the product name "{$context}" as a clean, concise, and professional product title for online stores.

          CRITICAL INSTRUCTION:
          - The output must be 100% in language code "{$langCode}" — this is mandatory.
          - If the original name is not in language code "{$langCode}", fully translate it into language code "{$langCode}" while keeping the meaning.
          - Do not mix languages; use only language code "{$langCode}" characters and words.
          - Keep it short (35–70 characters), plain, and ready for listings.
          - No extra words, slogans, or punctuation.
          - Return only the translated title as plain text in language code "{$langCode}".

      IMPORTANT:
        - Only process inputs that are actual e-commerce products (electronics, clothing, home goods, gadgets, accessories, etc.).
        - If the input is food, vegetables, fruits, or anything unrelated to e-commerce products, respond with only "INVALID_INPUT".
        - If the original input is not meaningful or cannot be converted into a professional product title, respond with only "INVALID_INPUT".
        - Do not return generic explanations, fallback messages, or translations for unrelated items.


      PROMPT;
    }

    public function getType(): string
    {
        return 'product_name';
    }
}
