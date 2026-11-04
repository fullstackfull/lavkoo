<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ChatwootService
{
    protected string $baseUrl;
    protected string $token;
    protected int $accountId;
    protected int $inboxId;

    public function __construct()
    {
        $integration = IntegrationSetting::where('provider', 'chatwoot')
            ->where('enabled', true)
            ->first();

        if (!$integration) {
            throw new RuntimeException('Chatwoot integration is not enabled.');
        }

        $this->baseUrl = rtrim($integration->base_url, '/');

        $credentials = json_decode(
            Crypt::decryptString($integration->credentials),
            true
        );

        $this->token = $credentials['api_access_token'] ?? '';

        $this->accountId = (int) (
            $integration->settings['account_id'] ?? 0
        );

        $this->inboxId = (int) (
            $integration->settings['inbox_id'] ?? 0
        );

        if (!$this->token || !$this->accountId || !$this->inboxId) {
            throw new RuntimeException(
                'Chatwoot integration configuration is incomplete.'
            );
        }
    }

    protected function client()
    {
        return Http::withHeaders([
            'api_access_token' => $this->token,
            'Accept' => 'application/json',
        ])->asJson();
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
        $phone = $this->normalizePhone($phone);

        $contact = $this->findContactByPhone($phone);

        if (!$contact) {
            $contact = $this->createContact(
                phone: $phone,
                name: $name
            );
        }

        $contactId = (int) $contact['id'];

        $sourceId = $this->getSourceIdForInbox($contact);

        if (!$sourceId) {
            $sourceId = $this->attachContactToInbox(
                contactId: $contactId,
                phone: $phone
            );
        }

        $conversationId = $this->createConversation(
            contactId: $contactId,
            sourceId: $sourceId
        );

        return $this->sendTemplateMessage(
            conversationId: $conversationId,
            content: $content,
            templateName: $templateName,
            language: $language,
            category: $category,
            bodyParams: $bodyParams
        );
    }

    protected function findContactByPhone(string $phone): ?array
    {
        $response = $this->client()->get(
            "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts/search",
            [
                'q' => $phone,
            ]
        );

        if ($response->failed()) {
            throw new RuntimeException(
                'Chatwoot contact search failed: ' . $response->body()
            );
        }

        $contacts = $response->json('payload', []);

        foreach ($contacts as $contact) {
            if (($contact['phone_number'] ?? null) === $phone) {
                return $contact;
            }
        }

        return null;
    }

    protected function createContact(
        string $phone,
        ?string $name = null
    ): array {
        $response = $this->client()->post(
            "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts",
            [
                'inbox_id' => $this->inboxId,
                'name' => $name ?: $phone,
                'phone_number' => $phone,
                'identifier' => $phone,
            ]
        );

        if ($response->failed()) {
            throw new RuntimeException(
                'Chatwoot contact creation failed: ' . $response->body()
            );
        }

        $payload = $response->json('payload', []);

        if (!empty($payload[0])) {
            return $payload[0];
        }

        /*
         * بعض إصدارات Chatwoot قد ترجع id خارج payload.
         * نبحث مرة أخرى لضمان الحصول على contact كامل.
         */
        $contact = $this->findContactByPhone($phone);

        if (!$contact) {
            throw new RuntimeException(
                'Chatwoot contact was created but could not be retrieved.'
            );
        }

        return $contact;
    }

    protected function getSourceIdForInbox(array $contact): ?string
    {
        foreach ($contact['contact_inboxes'] ?? [] as $contactInbox) {
            $inboxId = (int) (
                $contactInbox['inbox']['id'] ?? 0
            );

            if ($inboxId === $this->inboxId) {
                return $contactInbox['source_id'] ?? null;
            }
        }

        return null;
    }

    protected function attachContactToInbox(
        int $contactId,
        string $phone
    ): string {
        /*
         * للـWhatsApp الرقم نفسه مناسب كـsource_id
         * بصيغة E.164.
         */
        $sourceId = $phone;

        $response = $this->client()->post(
            "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts/{$contactId}/contact_inboxes",
            [
                'inbox_id' => $this->inboxId,
                'source_id' => $sourceId,
            ]
        );

        if ($response->failed()) {
            throw new RuntimeException(
                'Chatwoot contact inbox creation failed: '
                . $response->body()
            );
        }

        return $response->json('source_id') ?: $sourceId;
    }

    protected function createConversation(
        int $contactId,
        string $sourceId
    ): int {
        $response = $this->client()->post(
            "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations",
            [
                'source_id' => $sourceId,
                'inbox_id' => $this->inboxId,
                'contact_id' => $contactId,
                'status' => 'open',
            ]
        );

        if ($response->failed()) {
            throw new RuntimeException(
                'Chatwoot conversation creation failed: '
                . $response->body()
            );
        }

        $conversationId = (int) $response->json('id');

        if (!$conversationId) {
            throw new RuntimeException(
                'Chatwoot did not return a conversation ID.'
            );
        }

        return $conversationId;
    }

    protected function sendTemplateMessage(
        int $conversationId,
        string $content,
        string $templateName,
        string $language,
        string $category,
        array $bodyParams
    ): array {
        $response = $this->client()->post(
            "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations/{$conversationId}/messages",
            [
                'content' => $content,
                'message_type' => 'outgoing',
                'private' => false,

                'template_params' => [
                    'name' => $templateName,
                    'category' => $category,
                    'language' => $language,

                    'processed_params' => [
                        'body' => $bodyParams,
                    ],
                ],
            ]
        );

        if ($response->failed()) {
            throw new RuntimeException(
                'Chatwoot template send failed: '
                . $response->body()
            );
        }

        return $response->json();
    }

    protected function normalizePhone(string $phone): string
    {
        $phone = trim($phone);

        $phone = preg_replace('/[^\d+]/', '', $phone);

        if (!str_starts_with($phone, '+')) {
            throw new RuntimeException(
                'Phone number must include country code, e.g. +491234567890.'
            );
        }

        return $phone;
    }







public function getWhatsAppTemplates(bool $approvedOnly = true): array
{
    $inbox = $this->getInbox();

    $templates = $inbox['message_templates'] ?? [];

    if ($approvedOnly) {
        $templates = array_filter(
            $templates,
            fn (array $template) =>
                ($template['status'] ?? null) === 'APPROVED'
        );
    }

    return array_values($templates);
}


public function getWhatsAppTemplate(
    string $templateName,
    ?string $language = null
): ?array {
    $templates = $this->getWhatsAppTemplates();

    foreach ($templates as $template) {
        if (($template['name'] ?? null) !== $templateName) {
            continue;
        }

        if (
            $language !== null &&
            ($template['language'] ?? null) !== $language
        ) {
            continue;
        }

        return $template;
    }

    return null;
}

public function getTemplateVariables(array $template): array
{
    $variables = [];

    foreach ($template['components'] ?? [] as $component) {
        if (($component['type'] ?? null) !== 'BODY') {
            continue;
        }

        $text = $component['text'] ?? '';

        preg_match_all('/\{\{(\d+)\}\}/', $text, $matches);

        $variables = $matches[1] ?? [];
    }

    return array_values(array_unique($variables));
}
    public function getInbox(): array
{
    $response = $this->client()->get(
        "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/inboxes/{$this->inboxId}"
    );

    if ($response->failed()) {
        throw new \RuntimeException(
            'Chatwoot inbox fetch failed: ' . $response->body()
        );
    }

    return $response->json();
}





}