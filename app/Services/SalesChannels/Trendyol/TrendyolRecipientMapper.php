<?php

namespace App\Services\SalesChannels\Trendyol;

use App\Services\SalesChannels\Dispatch\ChannelRecipient;
use App\Services\SalesChannels\Exceptions\RecipientUnavailable;

/**
 * Takes the shipping address of a Trendyol package and nothing else: no customer e-mail, identity or tax number, invoice address or district codes.
 * A package Trendyol itself ships (4P, 3pByTrendyol, micro) is never ours to ship.
 */
final class TrendyolRecipientMapper
{
    /** @param array<string, mixed> $package */
    public static function map(array $package): ChannelRecipient
    {
        foreach (['is4P', '3pByTrendyol', 'micro'] as $flag) {
            if (!empty($package[$flag])) {
                throw new RecipientUnavailable(RecipientUnavailable::NOT_SHIPPABLE_BY_US);
            }
        }

        $address = (array) ($package['shipmentAddress'] ?? []);
        $name = trim((string) ($address['fullName'] ?? ''));
        if ($name === '') {
            $name = trim(($address['firstName'] ?? '') . ' ' . ($address['lastName'] ?? ''));
        }
        $street = trim(($address['address1'] ?? '') . ' ' . ($address['address2'] ?? ''));
        if ($street === '') {
            $street = trim((string) ($address['fullAddress'] ?? ''));
        }

        return new ChannelRecipient(
            name: $name,
            phone: (string) ($address['phone'] ?? ''),
            addressLine: $street,
            city: (string) ($address['city'] ?? ''),
            state: isset($address['stateName']) ? (string) $address['stateName'] : null,
            country: (string) ($address['countryCode'] ?? $package['orderCountryCode'] ?? ''),
        );
    }
}
