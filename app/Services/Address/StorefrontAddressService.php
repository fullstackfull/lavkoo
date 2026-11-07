<?php

namespace App\Services\Address;

use App\Enums\Address\AddressChannel;
use App\Models\Order;
use App\Models\ShippingAddress;
use App\Services\ShippingAddressService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * The website's side of the address book: what the checkout step and the account pages write, and what they offer back.
 *
 * Every address the website saves goes through ShippingAddressService::structuredAttributes() as a Web address, so a Saudi address
 * carries a region, a city of the master list and a National Address whichever page it came from. The id of an address that
 * arrives in a request is never trusted: it is looked up among the caller's own addresses (the signed-in customer's, or the
 * guest session's) and anything else is "not found". The old columns keep being written for the readers that depend on them, and
 * `zip` only ever holds what the customer typed.
 */
class StorefrontAddressService
{
    private const LEGACY_FIELDS = ['contact_person_name', 'address_type', 'address', 'city', 'country', 'phone', 'latitude', 'longitude'];

    private const STRUCTURED_FIELDS = ['region_id', 'city_id', 'district', 'national_address'];

    public function __construct(
        private readonly ShippingAddressService $addresses,
        private readonly OrderAddressService $orderAddresses,
        private readonly CheckoutAddressGuard $guard,
    ) {
    }

    /** Does an address in this country have to be entered as region, city and National Address? */
    public function requiresStructured(?string $country): bool
    {
        return $this->addresses->requiresStructured($country, AddressChannel::Web);
    }

    /**
     * An old Saudi address (a free-text city, or no valid National Address) that has to be completed before an order can ship to it.
     * Always false while the structured address is off.
     */
    public function needsCompletion(ShippingAddress $address): bool
    {
        return $this->guard->problems($address, AddressChannel::Web) !== [];
    }

    /**
     * The JSON the checkout page keeps for a saved address, so choosing it fills the form. While the structured address is on it
     * also names the region and the city and says whether the address still has to be completed.
     */
    public function prefillJson(ShippingAddress $address): string
    {
        if (!AddressSchema::enabled()) {
            return $address->toJson();
        }

        return (string) json_encode(AddressSnapshot::build($address) + ['needs_completion' => $this->needsCompletion($address)], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Why the addresses chosen in this session cannot be ordered with (they are not the caller's, are gone, or an old Saudi address still
     * has to be completed), or null when the order may go ahead. Asked before a guest's account is created, so a refusal never leaves
     * an account without an order.
     */
    public function checkoutRefusal(): ?string
    {
        $customerId = auth('customer')->id();

        try {
            $this->orderAddresses->assertOrderable(
                shippingId: session('address_id'),
                billingId: session('billing_address_id'),
                customerId: $customerId ?? session('guest_id'),
                isGuest: $customerId === null,
                channel: AddressChannel::Web,
                guestId: session('guest_id'),
            );
        } catch (ValidationException $exception) {
            return $exception->validator->errors()->first();
        }

        return null;
    }

    /** @return Collection<int, ShippingAddress> the saved addresses the checkout offers: the signed-in customer's own. A guest has none, and never sees anyone else's. */
    public function checkoutAddresses(): Collection
    {
        return auth('customer')->check() ? $this->customerAddresses()->get() : new Collection();
    }

    /** @return Collection<int, ShippingAddress> the signed-in customer's address book, newest first */
    public function addressBook(): Collection
    {
        return $this->customerAddresses()->latest()->get();
    }

    public function findInAddressBook(mixed $addressId): ?ShippingAddress
    {
        return $this->orderAddresses->owned(addressId: $addressId, customerId: auth('customer')->id(), isGuest: false);
    }

    /**
     * Adds an address to the signed-in customer's address book.
     *
     * @param array<string, mixed> $attributes the old columns, as the customer typed them; `zip` only when the form had the field
     * @param array<string, mixed> $input the country, phone, region_id, city_id, district and national_address that were sent
     * @throws ValidationException
     */
    public function addToAddressBook(array $attributes, array $input): ShippingAddress
    {
        $structured = $this->structured(input: $input);

        return ShippingAddress::query()->create(
            ['customer_id' => (string) auth('customer')->id(), 'is_guest' => 0]
            + array_merge(['zip' => null], $attributes, $structured)
        );
    }

    /**
     * Changes an address of the signed-in customer's address book. A field the form did not carry (the ZIP code of a Saudi address,
     * which has no ZIP field) keeps its value.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function amendInAddressBook(ShippingAddress $address, array $attributes, array $input): void
    {
        $address->update(array_merge($attributes, $this->structured(input: $input + Arr::only($attributes, ['city']), existing: $address)));
    }

    public function removeFromAddressBook(mixed $addressId): bool
    {
        return (bool) $this->findInAddressBook($addressId)?->delete();
    }

    /**
     * Is this the phone number a guest order was placed with? The order's own copy of its addresses answers, so editing or removing
     * the saved address later does not decide who may look an order up; only an order that kept no copy falls back to the saved
     * addresses it points to. A blank number matches nothing.
     */
    public function orderWasPlacedWithPhone(Order $order, mixed $phone): bool
    {
        if (!is_scalar($phone) || trim((string) $phone) === '') {
            return false;
        }

        $copies = array_filter([$order->shippingAddressSnapshot(), $order->billingAddressSnapshot()]);
        $knownPhones = $copies !== []
            ? array_map(static fn (array $copy): mixed => AddressSnapshot::value($copy, 'phone'), $copies)
            : [$order->shippingAddress?->phone, $order->billingAddress?->phone];

        foreach ($knownPhones as $knownPhone) {
            if (filled($knownPhone) && $knownPhone == $phone) {
                return true;
            }
        }

        return false;
    }

    /** True when the posted shipping (or billing) form misses a field the checkout cannot do without. */
    public function isIncomplete(array $posted, bool $billing, bool $guest): bool
    {
        $prefix = $billing ? 'billing_' : '';
        $required = ['contact_person_name', 'address_type', 'address', 'country', 'phone'];
        if (!$this->requiresStructured($this->text($posted[$prefix . 'country'] ?? null))) {
            $required[] = 'city';
        }

        foreach ($required as $field) {
            if (!$this->filled($posted[$prefix . $field] ?? null)) {
                return true;
            }
        }

        return $guest && !$this->filled($posted[$billing ? 'billing_contact_email' : 'email'] ?? null);
    }

    /**
     * Saves the shipping (or billing) address the checkout form posted and returns the id the payment step will use.
     *
     * The form says what to do with it: save it to the address book, update the saved address it was filled from, or (neither) keep
     * it only for this order. The id of a saved address that came with the form is looked up among the caller's own addresses.
     *
     * @param array<string, mixed> $posted the parsed form
     * @throws ValidationException
     */
    public function saveFromCheckout(array $posted, bool $billing): int
    {
        $attributes = $this->checkoutAttributes(posted: $posted, billing: $billing);
        $input = $this->structuredInput(posted: $posted, attributes: $attributes, billing: $billing);

        return match (true) {
            $this->isOn(posted: $posted, flag: $billing ? 'save_address_billing' : 'save_address')
                => $this->createSaved(attributes: $attributes, structured: $this->structured(input: $input))->id,
            $this->isOn(posted: $posted, flag: $billing ? 'update_billing_address' : 'update_address')
                => $this->updateOwned(addressId: $posted[$billing ? 'billing_method_id' : 'shipping_method_id'] ?? null, attributes: $attributes, input: $input)->id,
            default => $this->reuseOrCreateUnsaved(attributes: $attributes, structured: $this->structured(input: $input)),
        };
    }

    /**
     * The region, city, district and National Address columns of an address that is being saved from the website, with the old
     * `city`, `state` and `country` text that goes with them. Empty when the structured address does not apply.
     *
     * @param array<string, mixed> $input the country, phone, region_id, city_id, district and national_address that were sent
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function structured(array $input, ?ShippingAddress $existing = null): array
    {
        return $this->addresses->structuredAttributes(input: $input, channel: AddressChannel::Web, existing: $existing);
    }

    private function createSaved(array $attributes, array $structured): ShippingAddress
    {
        return ShippingAddress::query()->create($this->owner(saved: true) + array_merge(['zip' => null], $attributes, $structured));
    }

    /** @throws ValidationException */
    private function updateOwned(mixed $addressId, array $attributes, array $input): ShippingAddress
    {
        $address = $this->ownedAddress($addressId);
        if ($address === null) {
            throw ValidationException::withMessages(['address' => [translate('address_not_found')]]);
        }

        $address->update(array_merge(Arr::only($attributes, [...self::LEGACY_FIELDS, 'zip']), $this->structured(input: $input, existing: $address)));

        return $address;
    }

    /**
     * An address typed at checkout and not saved is kept as an unsaved copy, and an identical one is reused so every proceed does not
     * add a row. "Identical" includes the region, city, district and National Address: two addresses that share the old columns but
     * not the place must never be taken for one.
     */
    private function reuseOrCreateUnsaved(array $attributes, array $structured): int
    {
        $fields = $this->owner(saved: false) + array_merge(['zip' => null], $attributes, $this->emptyStructuredColumns(), $structured);

        $query = ShippingAddress::query();
        foreach ($fields as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return (int) ($query->value('id') ?? ShippingAddress::query()->create($fields)->id);
    }

    /** The saved address with this id, if it is the signed-in customer's own or the guest session's own. */
    private function ownedAddress(mixed $addressId): ?ShippingAddress
    {
        $customerId = auth('customer')->id();
        $ownerId = $customerId ?? session('guest_id');

        return $ownerId ? $this->orderAddresses->owned(addressId: $addressId, customerId: $ownerId, isGuest: $customerId === null) : null;
    }

    /**
     * Who a row written now belongs to: the signed-in customer, the guest session, or nobody. An address typed at checkout and not saved
     * belongs to nobody when a signed-in customer typed it, so it never shows up in their address book.
     *
     * @return array{customer_id: string, is_guest: int}
     */
    private function owner(bool $saved): array
    {
        $customerId = auth('customer')->id();
        if ($customerId !== null) {
            return ['customer_id' => (string) ($saved ? $customerId : 0), 'is_guest' => 0];
        }

        $guestId = session('guest_id');

        return ['customer_id' => (string) ($guestId ?: 0), 'is_guest' => $guestId ? 1 : 0];
    }

    private function customerAddresses(): Builder
    {
        return ShippingAddress::query()->where(['customer_id' => (string) auth('customer')->id(), 'is_guest' => 0]);
    }

    /** @return array<string, null> the structured columns of an address that has none, once the store has them */
    private function emptyStructuredColumns(): array
    {
        return AddressSchema::columnsReady() ? array_fill_keys(self::STRUCTURED_FIELDS, null) : [];
    }

    /** @return array<string, mixed> the old columns of the posted shipping (or billing) form; `zip` only when the form had the field */
    private function checkoutAttributes(array $posted, bool $billing): array
    {
        $prefix = $billing ? 'billing_' : '';
        $attributes = [
            'email' => auth('customer')->check() ? null : $this->text($posted[$billing ? 'billing_contact_email' : 'email'] ?? null),
            'is_billing' => $billing ? 1 : 0,
        ];
        foreach (self::LEGACY_FIELDS as $field) {
            $attributes[$field] = $this->text($posted[$prefix . $field] ?? null);
        }
        $attributes['latitude'] ??= '';
        $attributes['longitude'] ??= '';
        if (array_key_exists($prefix . 'zip', $posted)) {
            $zip = $this->text($posted[$prefix . 'zip']);
            $attributes['zip'] = $zip === null ? null : trim($zip);
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    private function structuredInput(array $posted, array $attributes, bool $billing): array
    {
        $prefix = $billing ? 'billing_' : '';
        $input = ['country' => $attributes['country'], 'city' => $attributes['city'], 'phone' => $attributes['phone']];
        foreach (self::STRUCTURED_FIELDS as $field) {
            if (array_key_exists($prefix . $field, $posted)) {
                $input[$field] = $posted[$prefix . $field];
            }
        }

        return $input;
    }

    private function isOn(array $posted, string $flag): bool
    {
        return ($posted[$flag] ?? null) === 'on';
    }

    private function filled(mixed $value): bool
    {
        $text = $this->text($value);

        return $text !== null && $text !== '';
    }

    private function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
