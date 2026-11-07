<?php

namespace App\Services\SalesChannels\Dispatch;

use App\Services\Fulfillment\Support\SaudiPhone;
use LogicException;
use SensitiveParameter;

/**
 * What a provider needs to deliver ONE order, read from the channel at the moment of dispatch: the recipient's name, a mobile number, one line of street text and the city.
 * Nothing else about the buyer is ever read for this (no buyer name, e-mail, postal code, identity or tax number, gift message).
 *
 * It lives in memory only. It cannot be serialised (so it cannot reach a queued job, the cache or a session), it prints nothing when dumped, and its arguments are hidden from
 * stack traces. The one thing that may leave it is the legacy-shaped address array a provider's payload builder reads, and only the order materializer asks for that.
 */
final readonly class ChannelRecipient
{
    public function __construct(
        #[SensitiveParameter] private string $name,
        #[SensitiveParameter] private string $phone,
        #[SensitiveParameter] private string $addressLine,
        #[SensitiveParameter] private string $city,
        #[SensitiveParameter] private ?string $state,
        private string $country,
    ) {
    }

    /**
     * What is missing or unusable, as stable codes (never the values): empty when the provider can be sent this recipient.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        if (trim($this->name) === '') {
            $problems[] = 'name_missing';
        }
        if (trim($this->phone) === '') {
            $problems[] = 'phone_missing';
        } elseif (SaudiPhone::normalize($this->phone) === null) {
            $problems[] = 'phone_not_a_saudi_mobile';
        }
        if (trim($this->addressLine) === '') {
            $problems[] = 'address_missing';
        }
        if (trim($this->city) === '') {
            $problems[] = 'city_missing';
        }
        if (strtoupper($this->country) !== 'SA') {
            $problems[] = 'country_not_supported';
        }

        return $problems;
    }

    /** @return array<string, mixed> the keys of `orders.shipping_address_data` that the Torod Point and M5AZN payload builders read (the address row's old shape) */
    public function shippingAddressData(): array
    {
        return [
            'contact_person_name' => trim($this->name),
            'phone' => SaudiPhone::normalize($this->phone) ?? '',
            'address' => trim($this->addressLine),
            'city' => trim($this->city),
            'state' => $this->state === null ? null : trim($this->state),
            'country' => strtoupper($this->country),
            'email' => '',
            'zip' => null,
        ];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['recipient' => 'not shown'];
    }

    /** @throws LogicException */
    public function __serialize(): array
    {
        throw new LogicException('A buyer recipient is never serialised.');
    }

    /** @param array<string, mixed> $data @throws LogicException */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A buyer recipient is never serialised.');
    }
}
