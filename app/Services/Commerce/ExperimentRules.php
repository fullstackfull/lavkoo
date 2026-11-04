<?php

namespace App\Services\Commerce;

use App\Models\ThemeSection;
use App\Services\Platform\Policy;
use App\Services\Theme\SectionRegistry;

/**
 * What an experiment may vary, and by how much (Phase 3.5).
 *
 * A variant is a partial settings patch over one published section: only the keys the admin
 * actually typed survive, each coerced through the section type's own schema — so a variant can
 * change a title or a layout and can never smuggle a key the type does not declare. Weights are
 * percentages of traffic; whatever they leave uncovered is control.
 */
class ExperimentRules
{
    public function __construct(
        private readonly SectionRegistry $registry,
        private readonly Policy $policy,
    ) {
    }

    /**
     * @return array{variants: array<int, array{key: string, weight: int, settings: array<string, mixed>}>,
     *               errors: array<int, string>}
     */
    public function validateVariants(mixed $rows, string $sectionType): array
    {
        if (!is_array($rows)) {
            return ['variants' => [], 'errors' => ['variants:not_a_list']];
        }

        $clean = [];
        $errors = [];
        $totalWeight = 0;
        $keysSeen = [];

        // Refused, not truncated. Slicing the tail off meant an admin who went one over the limit
        // saw the rest saved and was never told what had been dropped.
        $limit = $this->policy->int('commerce_max_variants');
        if (count($rows) > $limit) {
            return ['variants' => [], 'errors' => ['variants:at_most_' . $limit]];
        }

        foreach (array_values($rows) as $index => $row) {
            $label = 'variant_' . ($index + 1);

            if (!is_array($row)) {
                $errors[] = $label . ':not_a_variant';
                continue;
            }

            $key = is_string($row['key'] ?? null) && trim($row['key']) !== ''
                ? strtolower(trim($row['key']))
                : chr(ord('b') + $index);

            if (in_array($key, $keysSeen, true) || $key === 'control') {
                $errors[] = $label . ':key_taken';
                continue;
            }

            $weight = $row['weight'] ?? null;
            if (!is_numeric($weight) || (int) $weight < 1 || (int) $weight > 99) {
                $errors[] = $label . ':weight_must_be_1_to_99';
                continue;
            }

            $patch = is_array($row['settings'] ?? null) ? $row['settings'] : [];
            // Only the keys the admin provided, each coerced by the section's own schema. An
            // empty surviving patch is a variant identical to control — a measurement of nothing.
            $patch = array_intersect_key(
                $this->registry->normalizeSettings($sectionType, $patch),
                $patch,
            );
            if ($patch === []) {
                $errors[] = $label . ':changes_nothing_the_section_type_understands';
                continue;
            }

            $totalWeight += (int) $weight;
            $keysSeen[] = $key;
            $clean[] = ['key' => $key, 'weight' => (int) $weight, 'settings' => $patch];
        }

        if ($totalWeight > 100) {
            $errors[] = 'variants:weights_exceed_100_percent';
            $clean = [];
        }

        return ['variants' => $clean, 'errors' => $errors];
    }

    /**
     * The section an experiment targets, looked up on the LIVE published arrangement — because
     * that is what shoppers see and what control means.
     */
    public function publishedSection(string $uuid, ?int $publishedVersionId): ?ThemeSection
    {
        if ($publishedVersionId === null || trim($uuid) === '') {
            return null;
        }

        try {
            return ThemeSection::query()
                ->where('theme_version_id', $publishedVersionId)
                ->where('uuid', $uuid)
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }
}
