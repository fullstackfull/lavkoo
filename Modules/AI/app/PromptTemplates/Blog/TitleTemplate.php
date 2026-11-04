<?php

namespace Modules\AI\app\PromptTemplates\Blog;

use Modules\AI\app\Contracts\PromptTemplateInterface;

class TitleTemplate implements PromptTemplateInterface
{

    public function build(mixed $context = null, ?string $langCode = null, ?string $description = null, ?array $options = null): string
    {
        $langCode = strtoupper($langCode);
        $topic = $context ?? 'a blog post';
        return <<<PROMPT
        You are an expert SEO content strategist and professional copywriter.

        Rewrite the blog title "{$context}", as a clean, concise, creative, engaging, and SEO-optimized blog post title.

        REQUIREMENTS:
         - The output must be 100% in language code "{$langCode}" — this is mandatory.
        - If the original title is not in language code "{$langCode}", fully translate it into language code "{$langCode}" while keeping the meaning.
        - Do not mix languages; use only language code "{$langCode}" characters and words.
        - The title must be directly relevant to the given topic.
        - It should be clear, compelling, and between **50–70 characters**.
        - Focus on readability, emotional appeal, and search intent.
        - Return the result in **plain JSON format** as shown below — no markdown, code blocks, or explanations.

        Example format:
        {
        "title": Your SEO-Optimized Blog Title Here
        }

        IMPORTANT:
        - If the input topic is unclear, meaningless, or unsuitable for a professional blog title, respond **only** with: "INVALID_INPUT".
        - Do not include any additional commentary, reasoning, or filler text.

        PROMPT;
    }

    public function getType(): string
    {
        return "blog_title";
    }
}
