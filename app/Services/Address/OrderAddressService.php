<?php

namespace App\Services\Address;

use App\Enums\Address\AddressChannel;
use App\Enums\Fulfillment\FulfillmentStatus;
use App\Models\ProviderFulfillment;
use App\Models\ShippingAddress;
use App\Services\Fulfillment\FulfillmentSchema;
use App\Services\ShippingAddressService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Which saved addresses an order may be placed with, and what the order remembers about them.
 *
 * The ids of the delivery and billing address come from the customer's device (the app sends them with the request, the website
 * keeps them in the session), so none is trusted: before an order is written each must be one of the customer's own addresses and,
 * for a customer who has not paid yet, fit to ship to. Once written, the order keeps a copy of the address as it was that day.
 */
class OrderAddressService
{
    public function __construct(
        private readonly CheckoutAddressGuard $guard,
        private readonly ShippingAddressService $addresses,
    ) {
    }

    /**
     * The saved address with this id, if the caller may order with it: one of their own, one of the guest they were before signing
     * in, or (on the website only) an address typed at checkout and not saved to the address book. Ids are whatever the device
     * sent, so anything that is not a plain positive number is simply not found.
     */
    public function owned(
        mixed $addressId,
        mixed $customerId,
        bool $isGuest,
        mixed $guestId = null,
        bool $allowUnsaved = false,
    ): ?ShippingAddress {
        $id = $this->plainId($addressId);
        $customer = $this->plainId($customerId);
        $guest = $this->plainId($guestId);
        if ($id === null || ($customer === null && $guest === null && !$allowUnsaved)) {
            return null;
        }

        return ShippingAddress::query()
            ->whereKey($id)
            ->where(static function (Builder $owner) use ($customer, $isGuest, $guest, $allowUnsaved): void {
                if ($customer !== null) {
                    $owner->orWhere(static fn (Builder $own) => $own->where('customer_id', $customer)->where('is_guest', $isGuest ? 1 : 0));
                }
                if ($guest !== null) {
                    $owner->orWhere(static fn (Builder $own) => $own->where('customer_id', $guest)->where('is_guest', 1));
                }
                if ($allowUnsaved) {
                    $owner->orWhere(static fn (Builder $own) => $own->where('customer_id', 0)->where('is_guest', 0));
                }
            })
            ->first();
    }

    /**
     * Refuses an order whose delivery or billing address is not the caller's, no longer exists, or cannot be used on this channel.
     * An empty id means the order carries no such address and is left alone.
     *
     * @throws ValidationException
     */
    public function assertOrderable(
        mixed $shippingId,
        mixed $billingId,
        mixed $customerId,
        bool $isGuest,
        AddressChannel $channel,
        mixed $guestId = null,
    ): void {
        foreach (array_filter([$shippingId, $billingId], static fn (mixed $addressId): bool => !empty($addressId)) as $addressId) {
            $address = $this->owned(
                addressId: $addressId,
                customerId: $customerId,
                isGuest: $isGuest,
                guestId: $guestId,
                allowUnsaved: $channel === AddressChannel::Web,
            );
            if ($address === null) {
                throw ValidationException::withMessages(['address' => [translate('address_not_found')]]);
            }

            $this->guard->assertUsable($address, $channel);
        }
    }

    /**
     * The copy of an address an order keeps (null when there is none). Every old key is kept as it was, ZIP code included, and once
     * the structured address is in use the names of the region and city are frozen with it. While the store still uses the old
     * address everywhere, the copy is exactly what it always was.
     *
     * @param object|array<string, mixed>|null $address a saved address, or the address array a form sent
     * @return array<string, mixed>|null
     */
    public function snapshot(object|array|null $address): ?array
    {
        if ($address === null || $address === []) {
            return null;
        }

        if (AddressSchema::enabled() || $this->addresses->isStructured($address)) {
            return AddressSnapshot::build($address);
        }

        return $address instanceof Model ? $address->toArray() : (array) $address;
    }

    /**
     * An order's address copy after an edit: what was sent replaces what the copy held, everything else stays as it was. The
     * region and city are only ever changed through the structured fields, so an edit that sends an old-style city to an address
     * that already has them is refused instead of leaving the two out of step.
     *
     * @param array<string, mixed>|null $snapshot the address as the order holds it now
     * @param array<string, mixed> $changes the old-style fields that were sent (name, phone, city, country, ZIP, e-mail, street, coordinates)
     * @param array<string, mixed> $structuredInput the region_id, city_id, district and national_address that were sent
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function revise(?array $snapshot, array $changes, array $structuredInput, AddressChannel $channel): array
    {
        $current = $snapshot ?? [];
        $locationEdited = $structuredInput !== [] || array_key_exists('city', $changes) || array_key_exists('country', $changes);
        $structured = $locationEdited
            ? $this->addresses->structuredAttributes(input: $structuredInput + Arr::only($changes, ['city', 'country', 'phone']), channel: $channel, existing: (object) $current)
            : [];
        $revised = array_merge($current, $changes, $structured);

        return $structured === [] ? $revised : AddressSnapshot::build($revised);
    }

    /**
     * Why the address of this order may no longer be edited, or null when it may. Once a provider holds the shipment (or a send is in
     * flight and its outcome is not known yet) it ships to the address it was given, so an edit of the order's copy would leave the
     * provider delivering somewhere other than the order now says. Before that an edit is safe (a held shipment is looked at again by
     * the next sweep), and an order without provider shipments is never blocked.
     */
    public function editBlockedReason(int $orderId): ?string
    {
        if (!FulfillmentSchema::ready()) {
            return null;
        }

        $handedOver = array_values(array_map(
            static fn (FulfillmentStatus $status): string => $status->value,
            array_filter(FulfillmentStatus::cases(), static fn (FulfillmentStatus $status): bool => $status->isActive() && !$status->isCancellableLocally()),
        ));

        return ProviderFulfillment::query()->where('order_id', $orderId)->whereIn('status', $handedOver)->exists()
            ? translate('order_address_cannot_be_edited_once_handed_to_the_provider')
            : null;
    }

    /** The address staff deliver to when none is chosen: the latest one of a registered customer, a delivery address before a billing one. */
    public function latestSavedAddress(mixed $customerId): ?ShippingAddress
    {
        $customer = $this->plainId($customerId);

        return $customer === null
            ? null
            : ShippingAddress::query()->where('customer_id', $customer)->where('is_guest', 0)->orderBy('is_billing')->orderByDesc('id')->first();
    }

    private function plainId(mixed $value): ?int
    {
        return (is_int($value) && $value > 0) || (is_string($value) && preg_match('/^[1-9]\d{0,17}$/', $value) === 1) ? (int) $value : null;
    }
}
