<?php

namespace Modules\AI\app\PromptTemplates;

use Modules\AI\app\Contracts\PromptTemplateInterface;

class ProductDescriptionTemplate implements PromptTemplateInterface
{
    public function build(?string $context = null, ?string $langCode = null, ?string $description = null, ?array $options = null): string
    {
        $langCode = strtoupper($langCode);
        return <<<PROMPT
        You are a creative and professional e-commerce copywriter.

        Generate a detailed, engaging, and persuasive product description for the product named "{$context}".

        CRITICAL LANGUAGE RULES:
        - The entire description must be written 100% in {$langCode} — this is mandatory.
        - If the product name is in another language, translate and localize it naturally into {$langCode}.
        - Do not mix languages; use only {$langCode} characters and words.
        - Adapt the tone, phrasing, and examples to be natural for {$langCode} readers.

        Content & Structure:
        - Include a section with key features as separate paragraphs. - Each paragraph should start with a <b>bold feature title</b> followed by a colon and the description.
       - Start with a short introductory paragraph describing the product and key features, its main benefit, and who it is for.
        - Follow with a "Specifications:" section in bullet points.
        - Each bullet point should include one key specification or feature with its value or description.
        - Keep text clear, concise, and marketing-friendly.
        - End with a closing sentence highlighting why the product is essential or beneficial.
        - Use clear, compelling, and marketing-friendly language.

        Formatting:
        - Output valid HTML using only <p>, <b>, <h1>, <h2>, and <ol>/<li><span> tags for bullet points.
        - Do NOT include any markdown syntax, code fences, or triple backticks (``` or ```html```) — remove them completely.
        - Avoid multiple consecutive <p> tags or empty lines that cause large gaps.
        - Return only the HTML content without any commentary or explanation.

         IMPORTANT:
        - Only process inputs that are actual e-commerce products (electronics, clothing, home goods, gadgets, accessories, etc.).
        - If the input is food, vegetables, fruits, or anything unrelated to e-commerce products, respond with only "INVALID_INPUT".
        - If the original input is not meaningful or cannot be converted into a professional product description, respond with only the word "INVALID_INPUT" instead of a full sentence.
        - Do not return generic explanations or fallback messages.
    PROMPT;
    }

    public function getType(): string
    {
        return 'product_description';
    }
}
