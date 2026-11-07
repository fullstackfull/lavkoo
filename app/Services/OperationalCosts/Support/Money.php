<?php

namespace App\Services\OperationalCosts\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * An amount of money without a currency, held as an exact decimal.
 *
 * Every figure in the cost and profit modules passes through this class so that nothing is ever added, split or
 * multiplied as a binary float. Rounding happens at exactly two places: when a value is stored (`toStorage()`, six
 * decimals, half-up) and when it is shown (`toDisplay()`, two decimals). A value that does not exist is `null` at the
 * call site, never `Money::zero()`: a missing cost is not a free one.
 *
 * Amounts in different currencies are never added to each other; the caller keeps them apart (see ProfitLine) or converts
 * them with an explicit, recorded rate first.
 */
final readonly class Money
{
    public const STORAGE_SCALE = 6;
    public const DISPLAY_SCALE = 2;

    private function __construct(private BigDecimal $amount)
    {
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero());
    }

    public static function of(self|BigDecimal|int|float|string $amount): self
    {
        return match (true) {
            $amount instanceof self => $amount,
            $amount instanceof BigDecimal => new self($amount),
            is_float($amount) => new self(BigDecimal::of(self::floatToString($amount))),
            is_string($amount) => new self(self::parse($amount)),
            default => new self(BigDecimal::of($amount)),
        };
    }

    public static function ofNullable(self|BigDecimal|int|float|string|null $amount): ?self
    {
        return $amount === null || $amount === '' ? null : self::of($amount);
    }

    public function plus(self|int|float|string $other): self
    {
        return new self($this->amount->plus(self::of($other)->amount));
    }

    public function minus(self|int|float|string $other): self
    {
        return new self($this->amount->minus(self::of($other)->amount));
    }

    /** Multiplication keeps every digit; the result is rounded only when stored. */
    public function times(self|int|float|string $factor): self
    {
        return new self($this->amount->multipliedBy(self::of($factor)->amount));
    }

    public function dividedBy(self|int|float|string $divisor, int $scale = self::STORAGE_SCALE): self
    {
        $divisor = self::of($divisor);
        if ($divisor->isZero()) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return new self($this->amount->dividedBy($divisor->amount, $scale, RoundingMode::HALF_UP));
    }

    /** `percent` is a percentage as people write it: 15 means 15 %. */
    public function percent(self|int|float|string $percent): self
    {
        return $this->times($percent)->dividedBy(100, self::STORAGE_SCALE + 4);
    }

    public function negated(): self
    {
        return new self($this->amount->negated());
    }

    public function abs(): self
    {
        return new self($this->amount->abs());
    }

    public function max(self $other): self
    {
        return $this->compareTo($other) >= 0 ? $this : $other;
    }

    public function min(self $other): self
    {
        return $this->compareTo($other) <= 0 ? $this : $other;
    }

    public function compareTo(self|int|float|string $other): int
    {
        return $this->amount->compareTo(self::of($other)->amount);
    }

    public function equals(self|int|float|string $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function round(int $scale = self::STORAGE_SCALE): self
    {
        return new self($this->amount->toScale($scale, RoundingMode::HALF_UP));
    }

    /** The exact string a DECIMAL(20,6) column receives. */
    public function toStorage(): string
    {
        return (string) $this->amount->toScale(self::STORAGE_SCALE, RoundingMode::HALF_UP);
    }

    /** A fixed number of decimals, for the columns that are not money (exchange rates keep ten). */
    public function toScale(int $scale): string
    {
        return (string) $this->amount->toScale($scale, RoundingMode::HALF_UP);
    }

    public function toDisplay(): string
    {
        return (string) $this->amount->toScale(self::DISPLAY_SCALE, RoundingMode::HALF_UP);
    }

    public function toFloat(): float
    {
        return $this->amount->toFloat();
    }

    public function __toString(): string
    {
        return $this->toStorage();
    }

    /** @param iterable<self|int|float|string|null> $amounts null entries are skipped; the caller decides whether a null means "incomplete" */
    public static function sum(iterable $amounts): self
    {
        $total = self::zero();
        foreach ($amounts as $amount) {
            if ($amount !== null) {
                $total = $total->plus($amount);
            }
        }

        return $total;
    }

    /**
     * Splits this amount into parts proportional to the given weights so that the parts add up to EXACTLY this amount
     * (largest-remainder method at storage precision). An all-zero weight list splits evenly. Keys are preserved.
     *
     * @param array<array-key, self|int|float|string> $weights
     * @return array<array-key, self>
     */
    public function allocate(array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        $normalised = array_map(static fn ($weight): BigDecimal => self::of($weight)->abs()->amount, $weights);
        $total = BigDecimal::sum(0, ...array_values($normalised));
        if ($total->isZero()) {
            $normalised = array_map(static fn (): BigDecimal => BigDecimal::one(), $normalised);
            $total = BigDecimal::of(count($normalised));
        }

        $unit = BigDecimal::of('0.000001');
        $target = $this->amount->toScale(self::STORAGE_SCALE, RoundingMode::HALF_UP);
        $parts = [];
        $remainders = [];
        $allocated = BigDecimal::zero();
        foreach ($normalised as $key => $weight) {
            $exact = $target->multipliedBy($weight)->dividedBy($total, self::STORAGE_SCALE + 12, RoundingMode::DOWN);
            $floor = $exact->toScale(self::STORAGE_SCALE, RoundingMode::DOWN);
            $parts[$key] = $floor;
            $remainders[$key] = $exact->minus($floor);
            $allocated = $allocated->plus($floor);
        }

        $leftover = $target->minus($allocated);
        // Hand out the missing units, largest remainder first; ties go to the earliest key so the result is deterministic.
        $order = array_keys($remainders);
        usort($order, static fn ($a, $b): int => $remainders[$b]->compareTo($remainders[$a]) ?: array_search($a, array_keys($remainders), true) <=> array_search($b, array_keys($remainders), true));
        $step = $leftover->isNegative() ? $unit->negated() : $unit;
        for ($index = 0; $leftover->abs()->isGreaterThanOrEqualTo($unit); $index = ($index + 1) % count($order)) {
            $key = $order[$index];
            $parts[$key] = $parts[$key]->plus($step);
            $leftover = $leftover->minus($step);
        }

        return array_map(static fn (BigDecimal $part): self => new self($part), $parts);
    }

    private static function parse(string $value): BigDecimal
    {
        $clean = trim(str_replace([',', ' '], '', $value));
        if ($clean === '' || preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/', $clean) !== 1) {
            throw new InvalidArgumentException('Not a decimal amount.');
        }

        return BigDecimal::of($clean);
    }

    private static function floatToString(float $value): string
    {
        if (!is_finite($value)) {
            throw new InvalidArgumentException('Not a finite amount.');
        }

        // A binary float carries noise past about 15 significant digits; six decimals is the precision the system stores.
        return number_format(round($value, self::STORAGE_SCALE), self::STORAGE_SCALE, '.', '');
    }
}
