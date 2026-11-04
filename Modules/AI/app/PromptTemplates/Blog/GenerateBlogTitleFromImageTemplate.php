<?php

namespace Modules\AI\app\PromptTemplates\Blog;

use Modules\AI\app\Contracts\PromptTemplateInterface;

class GenerateBlogTitleFromImageTemplate implements PromptTemplateInterface
{

    public function build(?string $context = null, ?string $langCode = null, ?string $description = null, ?array $options = null): string
    {
        $langCode ??= 'en';
        $langCode = strtoupper($langCode);

        $descriptionInstruction = !empty($description)
            ? "Additionally, consider the user's description: \"{$description}\" and incorporate it only if it adds clarity or relevance."
            : "Ignore user description if irrelevant or missing.";

            return <<<PROMPT
            You are an advanced SEO content strategist, copywriter, and image recognition analyst.
            Analyze the uploaded blog image provided by the user.
            {$descriptionInstruction}
            Your task:
            Generate a clean, concise, and professional blog title closely related to the product or topic shown in the image — adjusted to match any meaningful user-provided context.
            CRITICAL INSTRUCTION:
            - The output must be 100% in {$langCode}.
            - Do not include subjective phrases like “high quality”, “best”, or overly emotional language.
            - Keep it short (35–70 characters), simple, and optimized for online listings.
            - Only return the title (plain text, no quotes).
            IMPORTANT:
            - If the image is irrelevant, unidentifiable, or meaningless → return only: INVALID_INPUT
            - Do NOT apologize or explain anything in the response.
            PROMPT;
    }

    public function getType(): string
    {
        return 'generate_blog_title_from_image';
    }
}
