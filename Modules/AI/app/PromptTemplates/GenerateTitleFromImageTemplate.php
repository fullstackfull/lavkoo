<?php

namespace Modules\AI\app\PromptTemplates;

use Modules\AI\app\Contracts\PromptTemplateInterface;

class GenerateTitleFromImageTemplate implements PromptTemplateInterface
{

    public function build(?string $context = null, ?string $langCode = null, ?string $description = null, ?array $options = null): string
    {
        $langCode ??= 'en';
        $langCode = strtoupper($langCode);

        return <<<PROMPT
        You are an advanced e-commerce product analyst with strong skills in image recognition.

        Analyze the uploaded product image provided by the user.
        Your task is to generate a clean, concise, and professional product title for online stores.

        CRITICAL INSTRUCTION:
        - The output must be 100% in {$langCode} — this is mandatory.
        - Identify the main product in the image and name it clearly.
        - Do not add extra descriptions like "high quality" or "best".
        - Keep it short (35–70 characters), plain, and ready for listings.
        - Return only the translated product title as plain text in {$langCode}.

        IMPORTANT:
        - If the image is not relevant to e-commerce products (e.g., food items, vegetables, random objects, or meaningless images), respond with only the word "INVALID_INPUT".
        - Do not return generic explanations, fallback messages, or apologies.

    PROMPT;
    }

    public function getType(): string
    {
       return 'generate_title_from_image';
    }
}
