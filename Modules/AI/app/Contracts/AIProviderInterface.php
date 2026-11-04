<?php

namespace Modules\AI\app\Contracts;

interface AIProviderInterface
{
    public function generate(string $prompt, ?string $imageUrl = null, array $options = []): string;

    public function getName(): string;
}
