<?php

namespace App\Services\Fulfillment\Support;

use Illuminate\Support\Arr;

/**
 * Proposes a field map from real sample records, for an administrator to review. It never decides
 * anything: its output is shown beside the raw sample and only takes effect when a human verifies it.
 */
final class FieldMapDetector
{
    private const SAMPLE_LIMIT = 25;
    private const MIN_COVERAGE = 0.8;

    /**
     * @param list<array<array-key, mixed>> $sample
     * @param array<string, list<string>> $candidates logical name => candidate dot-paths, best first
     * @return array<string, string> logical name => the first candidate present (non-empty) in most records
     */
    public function detect(array $sample, array $candidates): array
    {
        $sample = array_slice($sample, 0, self::SAMPLE_LIMIT);
        if ($sample === []) {
            return [];
        }

        $detected = [];
        foreach ($candidates as $logical => $paths) {
            foreach ($paths as $path) {
                $present = 0;
                foreach ($sample as $record) {
                    $value = Arr::get($record, $path);
                    if ($value !== null && $value !== '' && !is_array($value)) {
                        $present++;
                    }
                }

                if ($present / count($sample) >= self::MIN_COVERAGE) {
                    $detected[$logical] = $path;
                    break;
                }
            }
        }

        return $detected;
    }

    /**
     * Which required logical fields do NOT resolve to a usable value in enough of the sample?
     *
     * @param list<array<array-key, mixed>> $sample
     * @param list<string> $required
     * @return list<string>
     */
    public function unresolved(FieldMap $map, array $sample, array $required): array
    {
        $sample = array_slice($sample, 0, self::SAMPLE_LIMIT);
        $problems = [];

        foreach ($required as $logical) {
            if (!$map->has($logical)) {
                $problems[] = $logical;
                continue;
            }

            $present = 0;
            foreach ($sample as $record) {
                $value = $map->value($record, $logical);
                if ($value !== null && $value !== '' && !is_array($value)) {
                    $present++;
                }
            }

            if ($sample === [] || $present / count($sample) < self::MIN_COVERAGE) {
                $problems[] = $logical;
            }
        }

        return $problems;
    }
}
