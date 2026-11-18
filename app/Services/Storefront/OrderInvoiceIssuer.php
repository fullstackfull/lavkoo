<?php

namespace App\Services\Storefront;

/**
 * Who an invoice says issued it (GCC-13, D1-03): the company frozen on the order when it was placed (see OrderLegalEntitySnapshot), never the shop's
 * own settings as they are today. Before this, every invoice printed the shop's address and the business identity of the invoice settings, so an
 * invoice of a Kuwaiti order carried the Saudi company's tax number.
 *
 * An order with no usable record keeps printing the shop's settings, exactly as it always did: every order placed before the registry, every order of a
 * shop that never switched it on, a record that cannot be read, and every order while legal entities are off (switching them off is the way back, and it
 * restores the old invoice even for an order that was stamped; the record stays on the order).
 */
final class OrderInvoiceIssuer
{
    /** The facts of the frozen company that an invoice prints, in print order: key of the record, translation key of its label (none for a plain line). */
    private const PRINTED_FACTS = [
        'legal_name' => null,
        'address' => null,
        'tax_registration_number' => 'tax_registration_number',
        'commercial_registration_number' => 'commercial_registration_number',
    ];

    /**
     * The lines of the issuer block of an invoice, or null when the invoice prints the shop's own settings.
     *
     * @param mixed $order a model, an array or an object that may carry the frozen copy as `entity_snapshot` (JSON text or already decoded)
     * @return list<array{label: ?string, value: string}>|null label is a translation key, null for a line with no label
     */
    public static function lines(mixed $order): ?array
    {
        if (!app(LegalEntityRegistry::class)->isEnabled()) {
            return null;
        }

        $record = self::frozenRecord($order);

        if ($record === null) {
            return null;
        }

        $lines = [];

        foreach (self::PRINTED_FACTS as $fact => $label) {
            $value = self::printable($record[$fact] ?? null);

            if ($value !== null) {
                $lines[] = ['label' => $label, 'value' => $value];
            }
        }

        return $lines === [] ? null : $lines;
    }

    /** @return array<string, mixed>|null */
    private static function frozenRecord(mixed $order): ?array
    {
        $record = data_get($order, 'entity_snapshot');

        if (is_string($record)) {
            $record = json_decode($record, associative: true);
        }

        return is_array($record) ? $record : null;
    }

    private static function printable(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
