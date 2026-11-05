<?php

namespace App\Services\Fulfillment\Verification;

use App\Services\Fulfillment\Support\PiiRedactor;

/**
 * What one field looked like across everything a response held: its types, how often it was present, the range of its numbers, a
 * few examples, the code words it took. This is the "real response schema" the field maps are finalised from.
 *
 * Nothing that identifies a person is ever kept: an example is recorded only if PiiRedactor lets the value through for its key,
 * otherwise the field is marked `redacted` and only its type and presence are known. Numbers under personal keys are not summed.
 */
final class FieldProfile
{
    private const MAX_DEPTH = 6;
    private const MAX_KEYS = 80;
    private const SAMPLE_ITEMS = 25;
    private const MAX_EXAMPLES = 3;
    private const EXAMPLE_LENGTH = 40;
    private const MAX_DISTINCT = 200;
    private const MAX_VALUES = 12;
    private const CODE_WORD = '/^[A-Za-z0-9_.:\-]{1,40}$/';

    private int $present = 0;

    /** @var array<string, int> */
    private array $types = [];

    /** @var list<string|int|float> */
    private array $examples = [];

    private bool $redacted = false;

    private ?float $min = null;

    private ?float $max = null;

    private float $sum = 0.0;

    private int $nonZero = 0;

    /** @var array<string, int> */
    private array $values = [];

    private bool $valuesTruncated = false;

    /** @var array<string, true> */
    private array $distinct = [];

    private ?int $shortest = null;

    private ?int $longest = null;

    private ?int $listMin = null;

    private ?int $listMax = null;

    private ?FieldProfile $items = null;

    /** @var array<string, FieldProfile> */
    private array $keys = [];

    public function add(mixed $value, string $key, bool $strict, int $depth = 0): void
    {
        $this->present++;
        $type = self::typeOf($value);
        $this->types[$type] = ($this->types[$type] ?? 0) + 1;

        match ($type) {
            'integer', 'number' => $this->addNumber($value, $key, $strict),
            'string' => $this->addString($value, $key, $strict),
            'boolean' => $this->addCodeWord($value ? 'true' : 'false'),
            'array' => $this->addList($value, $key, $strict, $depth),
            'object' => $this->addObject($value, $strict, $depth),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $profile = ['present' => $this->present, 'types' => count($this->types) === 1 ? (string) array_key_first($this->types) : $this->types];

        foreach ([
            'examples' => $this->examples,
            'redacted' => $this->redacted ?: null,
            'numeric' => $this->min === null ? null : ['min' => $this->min, 'max' => $this->max, 'sum' => $this->sum, 'non_zero' => $this->nonZero],
            'values' => $this->values === [] ? null : $this->values,
            'values_truncated' => $this->valuesTruncated ?: null,
            'distinct' => $this->distinct === [] ? null : count($this->distinct),
            'distinct_capped' => count($this->distinct) >= self::MAX_DISTINCT ? true : null,
            'length' => $this->shortest === null ? null : ['min' => $this->shortest, 'max' => $this->longest],
            'count' => $this->listMin === null ? null : ['min' => $this->listMin, 'max' => $this->listMax],
            'items' => $this->items?->toArray(),
            'keys' => $this->keys === [] ? null : array_map(static fn (FieldProfile $profile): array => $profile->toArray(), $this->keys),
        ] as $name => $value) {
            if ($value !== null && $value !== []) {
                $profile[$name] = $value;
            }
        }

        return $profile;
    }

    public static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => array_is_list($value) ? 'array' : 'object',
            default => 'other',
        };
    }

    private function addNumber(int|float $value, string $key, bool $strict): void
    {
        if ($this->masked($value, $key, $strict)) {
            return;
        }

        $number = (float) $value;
        $this->min = $this->min === null ? $number : min($this->min, $number);
        $this->max = $this->max === null ? $number : max($this->max, $number);
        $this->sum += $number;
        $this->nonZero += $number !== 0.0 ? 1 : 0;
        $this->remember($value);
    }

    private function addString(string $value, string $key, bool $strict): void
    {
        if ($this->masked($value, $key, $strict)) {
            return;
        }

        $length = mb_strlen($value);
        $this->shortest = $this->shortest === null ? $length : min($this->shortest, $length);
        $this->longest = $this->longest === null ? $length : max($this->longest, $length);
        if (preg_match(self::CODE_WORD, $value) === 1) {
            $this->addCodeWord($value);
        }
        $this->remember(mb_substr($value, 0, self::EXAMPLE_LENGTH));
    }

    private function addCodeWord(string $word): void
    {
        if (isset($this->values[$word]) || count($this->values) < self::MAX_VALUES) {
            $this->values[$word] = ($this->values[$word] ?? 0) + 1;
        } else {
            $this->valuesTruncated = true;
        }
    }

    /** @param array<array-key, mixed> $list */
    private function addList(array $list, string $key, bool $strict, int $depth): void
    {
        $count = count($list);
        $this->listMin = $this->listMin === null ? $count : min($this->listMin, $count);
        $this->listMax = $this->listMax === null ? $count : max($this->listMax, $count);
        if ($depth >= self::MAX_DEPTH) {
            return;
        }

        foreach (array_slice($list, 0, self::SAMPLE_ITEMS) as $element) {
            $this->items ??= new self();
            $this->items->add($element, $key, $strict, $depth + 1);
        }
    }

    /** @param array<array-key, mixed> $object */
    private function addObject(array $object, bool $strict, int $depth): void
    {
        if ($depth >= self::MAX_DEPTH) {
            return;
        }

        foreach (array_slice($object, 0, self::MAX_KEYS, true) as $name => $child) {
            $this->keys[(string) $name] ??= new self();
            $this->keys[(string) $name]->add($child, (string) $name, $strict, $depth + 1);
        }
    }

    /** True when the value is personal data for its key: the field is then known only by type and presence. */
    private function masked(mixed $value, string $key, bool $strict): bool
    {
        $kept = PiiRedactor::redact([$key => $value], $strict)[$key] ?? null;
        if ($kept === PiiRedactor::MASK) {
            $this->redacted = true;

            return true;
        }

        return false;
    }

    private function remember(string|int|float $value): void
    {
        if (count($this->distinct) < self::MAX_DISTINCT) {
            $this->distinct[(string) $value] = true;
        }
        if (count($this->examples) < self::MAX_EXAMPLES && !in_array($value, $this->examples, true)) {
            $this->examples[] = $value;
        }
    }
}
