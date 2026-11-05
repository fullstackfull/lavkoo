<?php

namespace App\Services\Fulfillment\Contracts;

use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Data\NormalizedEvent;

/** For providers that call us back. The processor stays provider-neutral; the adapter knows its own payloads. */
interface HandlesWebhooks
{
    /** @param array<array-key, mixed> $payload */
    public function normalizeWebhook(string $type, array $payload): ?NormalizedEvent;

    /**
     * What of the provider's body is kept with the event, and read back by normalizeWebhook() when the event is processed: the fields
     * that method reads and nothing else. A body also carries the receiver, the courier and proof-of-delivery links, and none of
     * those is needed to apply a status.
     *
     * @param array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    public function storablePayload(array $payload): array;

    /** Find OUR fulfillment for an event — by references we hold for it, never by anything another provider could share. */
    public function locateFulfillment(NormalizedEvent $event): ?ProviderFulfillment;

    /** Store any new reference the event reveals (a package barcode we did not have yet). */
    public function learnReferences(ProviderFulfillment $fulfillment, NormalizedEvent $event): void;

    /**
     * Ask the provider whether it really holds this status, for events whose authenticity cannot be
     * checked by signature. true = confirmed, false = contradicted, null = could not be checked.
     */
    public function confirmStatus(ProviderFulfillment $fulfillment, NormalizedEvent $event): ?bool;
}
