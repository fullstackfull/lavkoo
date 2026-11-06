<?php

namespace App\Services\Fulfillment\Support;

use Illuminate\Support\Arr;

/**
 * Proposes a field map from real sample records, for an administrator to review. It never decides
 * anything: its output is shown beside the raw sample and only takes effect when a human verifies it.
 *
 * A logical field is either strict (the default: most records must carry a value there) or nullable
 * (most records must carry the KEY, and the value may be null or empty). A nullable field is for an
 * identifier the provider only fills in for some records — a record that answers "no value" is not the
 * same as a record that has no such field, and the second is what a wrong path looks like.
 */
final class FieldMapDetector
{
    private const SAMPLE_LIMIT = 25;
    private const MIN_COVERAGE = 0.8;

    /**
     * @param list<array<array-key, mixed>> $sample
     * @param array<string, list<string>> $candidates logical name => candidate dot-paths, best first
     * @param list<string> $nullable logical names that only have to be present, not filled
     * @return array<string, string> logical name => the first candidate present in most records
     */
    public function detect(array $sample, array $candidates, array $nullable = []): array
    {
        $sample = $this->sampled($sample);
        if ($sample === []) {
            return [];
        }

        $detected = [];
        foreach ($candidates as $logical => $paths) {
            foreach ($paths as $path) {
                if ($this->answers(sample: $sample, path: $path, valueRequired: !in_array($logical, $nullable, true))) {
                    $detected[$logical] = $path;
                    break;
                }
            }
        }

        return $detected;
    }

    /**
     * Which required logical fields do NOT resolve in enough of the sample? A strict field needs a usable value in
     * most records; a nullable one needs its key in most records (and whatever value it has must be a scalar).
     *
     * @param list<array<array-key, mixed>> $sample
     * @param list<string> $required
     * @param list<string> $nullable the required fields that only have to be present, not filled
     * @return list<string>
     */
    public function unresolved(FieldMap $map, array $sample, array $required, array $nullable = []): array
    {
        $sample = $this->sampled($sample);
        $problems = [];

        foreach ($required as $logical) {
            $path = $map->path($logical);
            if ($path === null || !$this->answers(sample: $sample, path: $path, valueRequired: !in_array($logical, $nullable, true))) {
                $problems[] = $logical;
            }
        }

        return $problems;
    }

    /**
     * How many of the sampled records really carry a value for each logical field. A nullable field can pass verification
     * with none, so the number is kept next to the verification instead of being lost.
     *
     * @param list<array<array-key, mixed>> $sample
     * @param list<string> $logicals
     * @return array{sampled: int, non_empty: array<string, int>}
     */
    public function coverage(FieldMap $map, array $sample, array $logicals): array
    {
        $sample = $this->sampled($sample);
        $nonEmpty = [];
        foreach ($logicals as $logical) {
            $path = $map->path($logical);
            $nonEmpty[$logical] = $path === null ? 0 : count(array_filter($sample, fn (array $record): bool => $this->hasValue(record: $record, path: $path)));
        }

        return ['sampled' => count($sample), 'non_empty' => $nonEmpty];
    }

    /**
     * @param list<array<array-key, mixed>> $sample
     * @return list<array<array-key, mixed>>
     */
    private function sampled(array $sample): array
    {
        return array_slice($sample, 0, self::SAMPLE_LIMIT);
    }

    /** @param list<array<array-key, mixed>> $sample */
    private function answers(array $sample, string $path, bool $valueRequired): bool
    {
        if ($sample === []) {
            return false;
        }

        $answered = count(array_filter($sample, fn (array $record): bool => $this->answered(record: $record, path: $path, valueRequired: $valueRequired)));

        return $answered / count($sample) >= self::MIN_COVERAGE;
    }

    /** @param array<array-key, mixed> $record */
    private function answered(array $record, string $path, bool $valueRequired): bool
    {
        if ($this->hasValue(record: $record, path: $path)) {
            return true;
        }

        // Present with no value: a nullable field's way of saying "not filled in for this record". A list or an object is not that.
        return !$valueRequired && Arr::has($record, $path) && !is_array(Arr::get($record, $path));
    }

    /**
     * A usable value: text with something in it, or a number (0 is a number). Not null, not blank, not a flag, not a list.
     *
     * @param array<array-key, mixed> $record
     */
    private function hasValue(array $record, string $path): bool
    {
        $value = Arr::get($record, $path);

        return match (true) {
            is_string($value) => trim($value) !== '',
            is_int($value), is_float($value) => true,
            default => false,
        };
    }
}
