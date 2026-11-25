<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use App\Services\Notification\NotificationProfiles;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ChatwootService
{
    protected string $baseUrl;
    protected string $token;
    protected int $accountId;
    protected int $inboxId;

    /**
     * The WhatsApp account this message leaves through: the market's own where it has one (I1-02), and otherwise the one shared Chatwoot
     * integration, read exactly as it always was.
     *
     * A template send is approved per SENDER NUMBER at the provider, so a market with its own number has its own inbox and its own approved
     * template names, and sending a Kuwaiti message through the Saudi inbox is both wrong and, for a template the Saudi number has not had
     * approved, impossible. The market is the one in force, so a message about an order takes the market the order was sold in.
     *
     * @param string|null $storefront the market whose account to use, or null for the market in force
     */
    public function __construct(?string $storefront = null)
    {
        $market = app(NotificationProfiles::class)->for(channel: NotificationProfiles::WHATSAPP, country: $storefront);

        if ($market !== null) {
            $this->baseUrl = rtrim((string) $market->setting('base_url'), '/');
            $this->token = (string) $market->credential('api_access_token', '');
            $this->accountId = (int) $market->setting('account_id', 0);
            $this->inboxId = (int) $market->setting('inbox_id', 0);
            $this->refuseAnIncompleteAccount();

            return;
        }

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

        $this->refuseAnIncompleteAccount();
    }

    /** An account that cannot address a conversation refuses rather than sending through whichever account can. */
    private function refuseAnIncompleteAccount(): void
    {
        if (!$this->baseUrl || !$this->token || !$this->accountId || !$this->inboxId) {
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