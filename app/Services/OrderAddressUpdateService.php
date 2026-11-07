<?php

namespace App\Services;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Enums\Address\AddressChannel;
use App\Models\Order;
use App\Services\Address\AddressSnapshot;
use App\Services\Address\OrderAddressService;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Staff correcting the address an order was placed with, from the order page of the admin panel or the vendor panel.
 *
 * Only the order's own copy changes, never the customer's saved address. A Saudi address is always entered with the region, city and
 * National Address once the structured address is in use, so editing an old order is also how it moves to the new form; the change is
 * recorded (who, which order, which fields, never what they said). What the form did not send stays as the order holds it: a ZIP code
 * is written only when the form carried one. An order whose shipment a provider already holds is not edited here (see
 * OrderAddressService::editBlockedReason).
 */
class OrderAddressUpdateService
{
    private const ADDRESS_TYPES = ['shipping', 'billing'];

    /** The order page's field names and the key each one has in the order's copy. */
    private const FIELDS = [
        'name' => 'contact_person_name',
        'phone_number' => 'phone',
        'country' => 'country',
        'city' => 'city',
        'zip' => 'zip',
        'address' => 'address',
        'latitude' => 'latitude',
        'longitude' => 'longitude',
    ];

    private const MAX_LENGTH = [
        'contact_person_name' => 191,
        'phone' => 40,
        'country' => 100,
        'city' => 191,
        'zip' => 32,
        'address' => 1000,
        'latitude' => 32,
        'longitude' => 32,
    ];

    private const STRUCTURED_FIELDS = ['region_id', 'city_id', 'district', 'national_address'];

    public function __construct(
        private readonly OrderAddressService $addresses,
        private readonly OrderRepositoryInterface $orders,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input what the order page's form sent
     * @throws ValidationException
     */
    public function update(Order $order, mixed $addressType, array $input): void
    {
        if (!in_array($addressType, self::ADDRESS_TYPES, true)) {
            return;
        }

        if ($blocked = $this->addresses->editBlockedReason(orderId: (int) $order->id)) {
            throw ValidationException::withMessages(['order' => [$blocked]]);
        }

        $current = $addressType === 'shipping' ? $order->shippingAddressSnapshot() : $order->billingAddressSnapshot();
        $revised = $this->addresses->revise(
            snapshot: $current,
            changes: $this->changes(input: $input) + ['updated_at' => now()],
            structuredInput: Arr::only($input, self::STRUCTURED_FIELDS),
            channel: AddressChannel::Panel,
        );

        $this->orders->update(id: (string) $order->id, data: [$addressType . '_address_data' => json_encode($revised, JSON_THROW_ON_ERROR)]);

        $this->audit->record(
            action: 'order.address_updated',
            subject: ['type' => 'order', 'id' => $order->id],
            before: $this->summary(snapshot: $current),
            after: $this->summary(snapshot: $revised),
            context: ['address_type' => $addressType, 'changed' => $this->changedKeys(before: $current ?? [], after: $revised)],
        );
    }

    /**
     * The old-style fields the form sent, cleaned. A field the form did not send is not here, so it keeps what the order holds.
     *
     * @param array<string, mixed> $input
     * @return array<string, string|null>
     * @throws ValidationException
     */
    private function changes(array $input): array
    {
        $changes = [];
        foreach (self::FIELDS as $inputName => $key) {
            if (array_key_exists($inputName, $input)) {
                $changes[$key] = $this->text(inputName: $inputName, key: $key, value: $input[$inputName]);
            }
        }

        return $changes;
    }

    /** @throws ValidationException */
    private function text(string $inputName, string $key, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = is_scalar($value) && !is_bool($value) ? trim((string) $value) : null;
        if ($text === null || mb_strlen($text) > self::MAX_LENGTH[$key] || !mb_check_encoding($text, 'UTF-8')) {
            throw ValidationException::withMessages([$inputName => [translate('address_field_invalid')]]);
        }

        return $text === '' ? null : $text;
    }

    /**
     * Enough to follow what happened to an address without keeping any of it.
     *
     * @param array<string, mixed>|null $snapshot
     * @return array{schema: string, region_id: int|null, city_id: int|null}
     */
    private function summary(?array $snapshot): array
    {
        if ($snapshot === null) {
            return ['schema' => 'none', 'region_id' => null, 'city_id' => null];
        }

        $regionId = AddressSnapshot::value($snapshot, 'region_id');
        $cityId = AddressSnapshot::value($snapshot, 'city_id');

        return [
            'schema' => AddressSnapshot::isUnified($snapshot) ? AddressSnapshot::SCHEMA_UNIFIED : AddressSnapshot::SCHEMA_LEGACY,
            'region_id' => $regionId === null ? null : (int) $regionId,
            'city_id' => $cityId === null ? null : (int) $cityId,
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<string> the names (never the values) of the keys that differ
     */
    private function changedKeys(array $before, array $after): array
    {
        $changed = [];
        foreach ($after as $key => $value) {
            if ($key !== 'updated_at' && ($before[$key] ?? null) != $value) {
                $changed[] = (string) $key;
            }
        }

        return $changed;
    }
}
