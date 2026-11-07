<?php

namespace App\Services\SalesChannels\Amazon;

use App\Services\SalesChannels\Dispatch\ChannelRecipient;
use App\Services\SalesChannels\Exceptions\RecipientUnavailable;

/**
 * Takes the recipient of an Orders v2026-01-01 order (`includedData=RECIPIENT,FULFILLMENT`) and nothing else: no buyer name or e-mail, no tax or payment data.
 * Without the Direct-to-Consumer Shipping role Amazon leaves the name, phone and street lines out: that shows up here as an incomplete recipient, never as a guess.
 */
final class AmazonRecipientMapper
{
    /** @param array<string, mixed> $order */
    public static function map(array $order): ChannelRecipient
    {
        if (($order['fulfillment']['fulfilledBy'] ?? null) !== 'MERCHANT') {
            throw new RecipientUnavailable(RecipientUnavailable::NOT_SHIPPABLE_BY_US);
        }

        $address = (array) ($order['recipient']['deliveryAddress'] ?? []);
        $lines = array_filter([$address['addressLine1'] ?? null, $address['addressLine2'] ?? null, $address['addressLine3'] ?? null], static fn (mixed $line): bool => is_string($line) && trim($line) !== '');

        return new ChannelRecipient(
            name: (string) ($address['name'] ?? ''),
            phone: (string) ($address['phone'] ?? ''),
            addressLine: implode(' ', $lines),
            city: (string) ($address['city'] ?? ''),
            state: isset($address['stateOrRegion']) ? (string) $address['stateOrRegion'] : null,
            country: (string) ($address['countryCode'] ?? ''),
        );
    }
}
