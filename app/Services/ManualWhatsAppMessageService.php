<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ManualWhatsAppMessageService
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
            throw new RuntimeException(
                'Lynomia Chat integration is not enabled.'
            );
        }

        $this->baseUrl = rtrim(
            $integration->base_url,
            '/'
        );

        $credentials = json_decode(
            Crypt::decryptString($integration->credentials),
            true
        );

        $this->token =
            $credentials['api_access_token'] ?? '';

        $this->accountId = (int) (
            $integration->settings['account_id'] ?? 0
        );

        $this->inboxId = (int) (
            $integration->settings['inbox_id'] ?? 0
        );

        if (
            !$this->token ||
            !$this->accountId ||
            !$this->inboxId
        ) {
            throw new RuntimeException(
                'Lynomia Chat configuration is incomplete.'
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

    protected function normalizePhone(string $phone): string
    {
        $phone = trim($phone);

        $phone = preg_replace(
            '/[^\d+]/',
            '',
            $phone
        );

        if (!str_starts_with($phone, '+')) {
            throw new RuntimeException(
                'Phone number must include country code.'
            );
        }

        return $phone;
    }

    public function checkEligibility(string $phone): array
{
    $phone = $this->normalizePhone($phone);

    $contact = $this->findContactByPhone($phone);

    if (!$contact) {
        return [
            'eligible' => false,
            'reason' => 'No Lynomia Chat contact was found for this phone number.',
            'phone' => $phone,
            'contact' => null,
            'conversation' => null,
            'conversation_id' => null,
            'last_inbound_at' => null,
        ];
    }

    $conversation = $this->findConversationForContact(
        contactId: (int) $contact['id']
    );

    if (!$conversation) {
        return [
            'eligible' => false,
            'reason' => 'No WhatsApp conversation was found for this contact.',
            'phone' => $phone,
            'contact' => $contact,
            'conversation' => null,
            'conversation_id' => null,
            'last_inbound_at' => null,
        ];
    }

    $conversationId = (int) ($conversation['id'] ?? 0);

    if (!$conversationId) {
        return [
            'eligible' => false,
            'reason' => 'Conversation ID could not be resolved.',
            'phone' => $phone,
            'contact' => $contact,
            'conversation' => $conversation,
            'conversation_id' => null,
            'last_inbound_at' => null,
        ];
    }

    $messages = $this->getConversationMessages(
        $conversationId
    );

    $lastInbound = collect($messages)
        ->filter(function ($message) {
            $type = $message['message_type'] ?? null;

            return $type === 0 || $type === 'incoming';
        })
        ->sortByDesc('created_at')
        ->first();

    if (!$lastInbound) {
        return [
            'eligible' => false,
            'reason' => 'No incoming WhatsApp message was found from this customer.',
            'phone' => $phone,
            'contact' => $contact,
            'conversation' => $conversation,
            'conversation_id' => $conversationId,
            'last_inbound_at' => null,
        ];
    }

    $createdAt = $lastInbound['created_at'] ?? null;

    if (!$createdAt) {
        return [
            'eligible' => false,
            'reason' => 'Last incoming message timestamp is missing.',
            'phone' => $phone,
            'contact' => $contact,
            'conversation' => $conversation,
            'conversation_id' => $conversationId,
            'last_inbound_at' => null,
        ];
    }

    $lastInboundAt = is_numeric($createdAt)
        ? Carbon::createFromTimestamp((int) $createdAt)
        : Carbon::parse($createdAt);

    $eligible = $lastInboundAt->greaterThanOrEqualTo(
        now()->subHours(24)
    );

    return [
        'eligible' => $eligible,
        'reason' => $eligible
            ? 'Customer is inside the 24-hour messaging window.'
            : 'The 24-hour messaging window has expired.',
        'phone' => $phone,
        'contact' => $contact,
        'conversation' => $conversation,
        'conversation_id' => $conversationId,
        'last_inbound_at' => $lastInboundAt,
        'hours_ago' => round(
            $lastInboundAt->diffInMinutes(now()) / 60,
            1
        ),
    ];
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
            'Lynomia Chat contact search failed: '
            . $response->body()
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


protected function getConversationMessages(
    int $conversationId
): array {
    $response = $this->client()->get(
        "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations/{$conversationId}/messages"
    );

    if ($response->failed()) {
        throw new RuntimeException(
            'Lynomia Chat messages fetch failed: '
            . $response->body()
        );
    }

    return $response->json('payload', []);
}



protected function findConversationForContact(
    int $contactId
): ?array {
    $page = 1;

    do {
        $response = $this->client()->get(
            "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations",
            [
                'status' => 'all',
                'inbox_id' => $this->inboxId,
                'page' => $page,
            ]
        );

        if ($response->failed()) {
            throw new RuntimeException(
                'Lynomia Chat conversation lookup failed: '
                . $response->body()
            );
        }

        $conversations = $response->json(
            'data.payload',
            []
        );

        if (empty($conversations)) {
            break;
        }

        foreach ($conversations as $conversation) {

            $conversationContactId = (int) (
                data_get($conversation, 'meta.sender.id')
                ?? data_get($conversation, 'contact.id')
                ?? 0
            );

            $inboxId = (int) (
                $conversation['inbox_id']
                ?? data_get($conversation, 'inbox.id')
                ?? 0
            );

            if (
                $conversationContactId === $contactId &&
                $inboxId === $this->inboxId
            ) {
                return $conversation;
            }
        }

        $page++;

    } while ($page <= 20);

    return null;
}



public function debugAccountConversations(): array
{
    $response = $this->client()->get(
        "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations",
        [
            'status' => 'all',
            'inbox_id' => $this->inboxId,
            'page' => 1,
        ]
    );

    return [
        'status' => $response->status(),
        'successful' => $response->successful(),
        'json' => $response->json(),
    ];
}

public function debugEligibilitySuccess(
    string $phone = '+16693334444',
    int $conversationId = 999
): array {
    return [
        'eligible' => true,
        'reason' => 'DEBUG: Customer is inside the 24-hour messaging window.',
        'phone' => $phone,
        'contact' => [
            'id' => 999,
            'name' => 'Test Customer',
            'phone_number' => $phone,
        ],
        'conversation' => [
            'id' => $conversationId,
            'inbox_id' => $this->inboxId,
        ],
        'conversation_id' => $conversationId,
        'last_inbound_at' => now()->subMinutes(10),
        'hours_ago' => 0.2,
        'debug' => true,
    ];
}
}