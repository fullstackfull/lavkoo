<?php

namespace App\Services\Commerce;

use App\Models\ExperienceExperiment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Which variant of which section this viewer sees (Phase 3.5).
 *
 * The whole feature is a settings patch applied to sections whose uuid a running experiment
 * names — control means no patch, which is also the answer for stopped experiments, broken
 * experiments, unidentifiable viewers, and the engine being off (§48). Assignment is pure
 * arithmetic on (experiment key, subject), so it is stable across requests, devices holding the
 * same identity, and deploys — with nothing stored anywhere.
 */
class ExperimentResolver
{
    private const LIST_TTL = 60;

    /**
     * variant assignments for one page and subject: experiment key => variant row.
     *
     * @return array<string, array<string, mixed>>
     */
    public function assignmentsFor(string $page, ?string $subject): array
    {
        if (!$this->serving()) {
            return [];
        }

        try {
            $assignments = [];

            foreach ($this->running($page) as $experiment) {
                $variant = $experiment->variantFor($subject);

                if ($variant !== null) {
                    $assignments[$experiment->key] = [
                        'section_uuid' => $experiment->section_uuid,
                        'variant'      => (string) ($variant['key'] ?? 'b'),
                        'settings'     => is_array($variant['settings'] ?? null) ? $variant['settings'] : [],
                    ];
                }
            }

            return $assignments;
        } catch (\Throwable) {
            return [];
        }
    }


    /**
     * Patch one section's settings if an assignment names its uuid, and say which experiment did.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, array<string, mixed>>  $assignments
     * @return array{0: array<string, mixed>, 1: ?array{key: string, variant: string}}
     */
    public function patch(?string $uuid, array $settings, array $assignments): array
    {
        if ($uuid === null || $assignments === []) {
            return [$settings, null];
        }

        foreach ($assignments as $key => $assignment) {
            if ($assignment['section_uuid'] === $uuid) {
                return [
                    array_merge($settings, $assignment['settings']),
                    ['key' => $key, 'variant' => $assignment['variant']],
                ];
            }
        }

        return [$settings, null];
    }

    /** @return \Illuminate\Support\Collection<int, ExperienceExperiment> */
    private function running(string $page)
    {
        return Cache::remember('commerce_experiments_' . $page, self::LIST_TTL, fn () => ExperienceExperiment::query()
            ->where('status', ExperienceExperiment::STATUS_RUNNING)
            ->where('page', $page)
            ->orderBy('key')
            ->get());
    }

    private function serving(): bool
    {
        if (!config('commerce.enabled', true)) {
            return false;
        }

        try {
            return Schema::hasTable('experience_experiments');
        } catch (\Throwable) {
            return false;
        }
    }
}
