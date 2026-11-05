<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

/**
 * Turns "how many rows hold each value" of an ENUMERATION column (a status, a type, a payment method) into something safe
 * to print. A value is printed only if it looks like a code word; anything else is counted and never shown, so that
 * free text which was typed into such a column by mistake cannot reach a report.
 */
final class Enumeration
{
    private const CODE_WORD = '/^[A-Za-z0-9_.:\-]{1,40}$/';

    /**
     * @param array<string, int> $distribution value (or ReadOnlyProbe::NULL_KEY) => rows
     * @param list<string> $known the values the integration understands
     * @return array{values: array<string, int>, unknown: list<string>, null_rows: int, other_values: int, other_rows: int, total_rows: int}
     */
    public static function describe(array $distribution, array $known): array
    {
        $values = [];
        $unknown = [];
        $nullRows = 0;
        $otherValues = 0;
        $otherRows = 0;

        foreach ($distribution as $value => $rows) {
            $value = (string) $value;
            if ($value === ReadOnlyProbe::NULL_KEY) {
                $nullRows += $rows;
            } elseif (!self::isCodeWord($value)) {
                $otherValues++;
                $otherRows += $rows;
            } elseif (count($values) < CompatibilityOptions::MAX_LISTED_VALUES) {
                $values[$value] = $rows;
                if (!in_array($value, $known, true)) {
                    $unknown[] = $value;
                }
            } else {
                $otherValues++;
                $otherRows += $rows;
            }
        }

        return [
            'values' => $values,
            'unknown' => $unknown,
            'null_rows' => $nullRows,
            'other_values' => $otherValues,
            'other_rows' => $otherRows,
            'total_rows' => array_sum($distribution),
        ];
    }

    /** A value short and plain enough to be a status or a type, and so safe to print. */
    public static function isCodeWord(string $value): bool
    {
        return preg_match(self::CODE_WORD, $value) === 1;
    }

    /** True when something in the column is not a known, printable value. */
    public static function hasSurprises(array $description): bool
    {
        return $description['unknown'] !== [] || $description['other_values'] > 0;
    }
}
