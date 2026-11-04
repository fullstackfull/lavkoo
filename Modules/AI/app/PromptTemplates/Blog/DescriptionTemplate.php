<?php

namespace Modules\AI\app\PromptTemplates\Blog;

use Modules\AI\app\Contracts\PromptTemplateInterface;

class DescriptionTemplate implements PromptTemplateInterface
{

    public function build(?string $context = null, ?string $langCode = null, ?string $description = null, ?array $options = null): string
    {
        $langCode = $langCode ? strtoupper($langCode) : 'EN';
        $title = $context && trim($context) !== '' ? $context : 'a blog post title';

        return <<<PROMPT
        You are an expert SEO content strategist and professional copywriter.

        Your task: Using the blog title "{$title}", generate a **detailed, SEO-optimized HTML blog introduction section** that could appear at the top of the article.

        CRITICAL LANGUAGE RULES:
        - The output must be 100% in language code "{$langCode}" — this is mandatory.
        - If the original title is not in language code "{$langCode}", fully translate it into language code "{$langCode}" while keeping the meaning.
        - Do not mix languages; use only language code "{$langCode}" characters and words.
        - Adapt tone and examples to be natural for {$langCode} readers.

        CONTENT REQUIREMENTS:
        - Include an <h1> main title and at least one <h2> subheading.
        - Write a minimum of 250–400 words of SEO-focused, engaging, and informative content.
        - Begin with a strong introduction paragraph summarizing the importance of the topic.
        - Add 2–3 follow-up paragraphs expanding on key ideas or benefits.
        - Highlight important SEO keywords or phrases using <b> tags.
        - Include an ordered or unordered list (<ol>/<ul>) with <li><span> elements summarizing main takeaways, strategies, or benefits.
        - The tone should be authoritative, helpful, and motivating.
        FORMATTING RULES:
        - You MUST output raw HTML only.
        - NEVER include markdown syntax, backticks, or ```html fences.
        - The response must begin directly with an <h1> tag.
        - Avoid empty <p> tags or blank lines.
        - Return ONLY the HTML content — no comments, code blocks, or explanations.
        IMPORTANT:
        - If the input title is meaningless or empty, respond only with "INVALID_INPUT".
        - Otherwise, generate the HTML directly.

        PROMPT;
    }


    public function getType(): string
    {
        return "blog_description";
    }
}
