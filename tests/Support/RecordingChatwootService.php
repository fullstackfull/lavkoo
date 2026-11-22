<?php

namespace Tests\Support;

use App\Services\ChatwootService;

/**
 * A ChatwootService that writes down what it was asked to send instead of sending it.
 *
 * It extends the real class and overrides only the two methods the cart-marketing send calls, so the controller is
 * handed the same type it resolves in production. Its constructor is replaced, because the real one refuses to exist
 * without an enabled Chatwoot integration and a decryptable credential, neither of which a consent test is about.
 */
class RecordingChatwootService extends ChatwootService
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    public function __construct()
    {
        // Deliberately does not call parent::__construct(): no integration row, no credentials, no HTTP client.
    }

    public function getWhatsAppTemplate(string $templateName, ?string $language = null): ?array
    {
        return [
            'name' => $templateName,
            'language' => $language ?? 'en',
            'components' => [['type' => 'BODY', 'text' => 'Your cart is waiting, {{1}}.']],
        ];
    }

    public function sendWhatsAppTemplate(
        string $phone,
        string $templateName,
        string $content,
        array $bodyParams = [],
        string $language = 'en',
        string $category = 'UTILITY',
        ?string $name = null
    ): array {
        $this->sent[] = compact('phone', 'templateName', 'content', 'bodyParams', 'language', 'category', 'name');

        return ['id' => count($this->sent)];
    }

    /** @return array<int, string> the phone number of every message this fake was asked to send */
    public function phonesSentTo(): array
    {
        return array_column($this->sent, 'phone');
    }
}
