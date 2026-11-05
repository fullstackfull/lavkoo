<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

/**
 * The shape of the orders table's DATA, as counts: which statuses occur, whether the delivery address can be read the way
 * the provider payloads read it, whether amounts are sane. Nothing taken from an order is printed.
 */
final class OrdersSection implements CompatibilitySection
{
    private const SECTION = 'orders';

    public function checks(ReadOnlyProbe $probe, CompatibilityOptions $options): array
    {
        if (!$probe->hasTable('orders')) {
            return [new CompatibilityCheck(self::SECTION, 'orders', CompatibilityCheck::INFO, 'not checked: the table is missing (the schema check reports it)')];
        }

        $columns = $probe->columns('orders');

        return array_values(array_filter([
            $this->volume($probe),
            isset($columns['order_status']) ? $this->statuses($probe) : null,
            isset($columns['order_type']) ? $this->types($probe) : null,
            ...$this->recentOrders($probe, $options, isset($columns['shipping_address_data'])),
            isset($columns['order_amount'], $columns['paid_amount']) ? $this->amounts($probe) : null,
        ]));
    }

    private function volume(ReadOnlyProbe $probe): CompatibilityCheck
    {
        $total = $probe->count('orders');

        return new CompatibilityCheck(self::SECTION, 'volume', CompatibilityCheck::INFO, "{$total} orders", ['orders' => $total]);
    }

    private function statuses(ReadOnlyProbe $probe): CompatibilityCheck
    {
        $description = Enumeration::describe($probe->distribution('orders', 'order_status', CompatibilityOptions::MAX_LISTED_VALUES * 2), CompatibilitySpec::ORDER_STATUSES);
        $dispatchable = array_map('strval', (array) config('fulfillment.dispatch.order_statuses', ['confirmed', 'processing']));
        $dispatchableRows = array_sum(array_map(static fn (string $status): int => $description['values'][$status] ?? 0, $dispatchable));

        $problems = [];
        if (Enumeration::hasSurprises($description)) {
            $problems[] = $this->surprises($description, 'order status');
        }
        if ($description['total_rows'] > 0 && $dispatchableRows === 0) {
            $problems[] = 'no order is in a dispatchable status (' . implode(', ', $dispatchable) . '): nothing would be sent to a provider';
        }

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'order status values',
            status: $problems === [] ? CompatibilityCheck::PASS : CompatibilityCheck::WARN,
            detail: $problems === [] ? count($description['values']) . ' distinct statuses, all known to the integration' : implode('; ', $problems),
            facts: $description + ['dispatchable_statuses' => $dispatchable, 'orders_in_dispatchable_statuses' => $dispatchableRows],
        );
    }

    private function types(ReadOnlyProbe $probe): CompatibilityCheck
    {
        $description = Enumeration::describe($probe->distribution('orders', 'order_type', CompatibilityOptions::MAX_LISTED_VALUES * 2), CompatibilitySpec::ORDER_TYPES);

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'order type values',
            status: Enumeration::hasSurprises($description) ? CompatibilityCheck::WARN : CompatibilityCheck::PASS,
            detail: Enumeration::hasSurprises($description) ? $this->surprises($description, 'order type') . ' (only "POS" orders are excluded from provider fulfillment)' : count($description['values']) . ' distinct order types, all known',
            facts: $description,
        );
    }

    /** @return list<CompatibilityCheck> the address-shape check (when the column exists) and the lines check */
    private function recentOrders(ReadOnlyProbe $probe, CompatibilityOptions $options, bool $hasAddress): array
    {
        $ids = [];
        $address = ['sampled' => 0, 'empty' => 0, 'invalid_json' => 0, 'not_an_object' => 0, 'double_encoded' => 0, 'with_name' => 0, 'with_phone' => 0, 'with_address' => 0, 'with_city' => 0];

        $probe->eachRecent('orders', $hasAddress ? ['shipping_address_data'] : [], $options->sample, function (object $row) use (&$ids, &$address, $hasAddress): void {
            $ids[] = $row->id;
            if ($hasAddress) {
                $this->tally($address, $row->shipping_address_data ?? null);
            }
        });

        return array_values(array_filter([
            $hasAddress ? $this->addressCheck($address) : null,
            $probe->hasTable('order_details') ? $this->linesCheck($probe, $ids) : null,
        ]));
    }

    /** @param list<mixed> $ids the ids of the sampled orders */
    private function linesCheck(ReadOnlyProbe $probe, array $ids): CompatibilityCheck
    {
        $withLines = array_flip($probe->existing('order_details', 'order_id', $ids));
        $withoutLines = count(array_filter($ids, static fn (mixed $id): bool => !isset($withLines[(string) $id])));
        $sampled = count($ids);

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'orders without lines',
            status: $withoutLines > 0 ? CompatibilityCheck::WARN : CompatibilityCheck::PASS,
            detail: $withoutLines > 0 ? "{$withoutLines} of the {$sampled} newest orders have no order_details row" : "every one of the newest {$sampled} orders has lines",
            facts: ['sampled' => $sampled, 'without_lines' => $withoutLines],
        );
    }

    /** @param array<string, int> $address */
    private function tally(array &$address, mixed $raw): void
    {
        $address['sampled']++;
        $decoded = JsonShape::decode($raw);

        if (!$decoded['valid']) {
            $address[is_string($raw) && trim($raw) !== '' ? 'invalid_json' : 'empty']++;

            return;
        }
        if (!is_array($decoded['value'])) {
            $address['not_an_object']++;

            return;
        }

        $address['double_encoded'] += $decoded['double_encoded'] ? 1 : 0;
        foreach (['with_name' => 'contact_person_name', 'with_phone' => 'phone', 'with_address' => 'address', 'with_city' => 'city'] as $counter => $key) {
            $address[$counter] += JsonShape::text($decoded['value'], $key) !== null ? 1 : 0;
        }
    }

    /** @param array<string, int> $address */
    private function addressCheck(array $address): CompatibilityCheck
    {
        $unreadable = $address['invalid_json'] + $address['not_an_object'];
        $readable = $address['sampled'] - $unreadable - $address['empty'];
        $detail = "{$readable} of {$address['sampled']} newest orders have a readable address object; "
            . ($address['sampled'] - $address['with_city']) . ' lack a city and ' . ($address['sampled'] - $address['with_phone']) . ' lack a phone (the provider shipment would be held for them, never guessed)';

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'shipping address shape',
            status: $unreadable > 0 ? CompatibilityCheck::WARN : CompatibilityCheck::PASS,
            detail: $unreadable > 0 ? "{$unreadable} of {$address['sampled']} newest orders hold an address that is not valid JSON or not an object. {$detail}" : $detail,
            facts: $address,
        );
    }

    private function amounts(ReadOnlyProbe $probe): CompatibilityCheck
    {
        $nullTotal = $probe->count('orders', static fn ($query) => $query->whereNull('order_amount'));
        $negativeTotal = $probe->count('orders', static fn ($query) => $query->where('order_amount', '<', 0));
        $overpaid = $probe->count('orders', static fn ($query) => $query->whereColumn('paid_amount', '>', 'order_amount'));
        $problems = $nullTotal + $negativeTotal;

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'order amounts',
            status: $problems > 0 ? CompatibilityCheck::WARN : CompatibilityCheck::PASS,
            detail: $problems > 0 ? "{$nullTotal} order(s) have no total and {$negativeTotal} a negative total: the integration holds such orders rather than sending them" : 'no order has a missing or negative total',
            facts: ['null_total' => $nullTotal, 'negative_total' => $negativeTotal, 'paid_more_than_total' => $overpaid],
        );
    }

    /** @param array{unknown: list<string>, other_values: int} $description */
    private function surprises(array $description, string $what): string
    {
        $parts = [];
        if ($description['unknown'] !== []) {
            $parts[] = "{$what} value(s) the integration does not know: " . implode(', ', $description['unknown']);
        }
        if ($description['other_values'] > 0) {
            $parts[] = $description['other_values'] . " further {$what} value(s) that are not a code word (not shown)";
        }

        return implode('; ', $parts);
    }
}
