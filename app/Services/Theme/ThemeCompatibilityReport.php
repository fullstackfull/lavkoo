<?php

namespace App\Services\Theme;

use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use Illuminate\Support\Carbon;

/**
 * What a version will actually look like on each client, answered BEFORE publishing.
 *
 * The delivery pipeline already decides this per request — silently, for shoppers. The merchant
 * needs the same answer out loud, at the moment they can still act on it: "two of your eleven
 * sections will not appear in the app, and here is why" is the difference between an informed
 * publish and a support ticket titled "the app is missing half my page".
 *
 * Counting is done against the CURRENT app engine's capabilities. Older installed builds may hold
 * fewer components still, but the server cannot enumerate every build in the field — what it can
 * promise is the ceiling, and the payload's own `compatibility.withheld` explains the rest per
 * request.
 */
class ThemeCompatibilityReport
{
    public function __construct(
        private readonly ComponentCapabilityRegistry $capabilities,
        private readonly SectionRegistry $registry,
    ) {
    }

    /**
     * The report for one version.
     *
     * @return array{
     *     sections: int,
     *     app_supported: int,
     *     withheld: array<int, array{type: string, label: string, count: int, reason: string}>,
     *     scheduled_waiting: int,
     *     scheduled_ended: int,
     *     platform_limited: int,
     *     audience_limited: int
     * }
     */
    public function for(ThemeVersion $version): array
    {
        $sections = ThemeSection::query()
            ->where('theme_version_id', $version->id)
            ->where('is_visible', true)
            ->get();

        $withheld = [];
        $appSupported = 0;
        $waiting = 0;
        $ended = 0;
        $platformLimited = 0;
        $audienceLimited = 0;
        $now = Carbon::now();

        foreach ($sections as $section) {
            if ($this->capabilities->isAppSafe($section->type)) {
                $appSupported++;
            } else {
                $withheld[$section->type] ??= [
                    'type'   => $section->type,
                    'label'  => $this->registry->types()[$section->type]['label'] ?? $section->type,
                    'count'  => 0,
                    'reason' => $this->capabilities->exclusionReason($section->type)
                        ?? 'no_app_renderer_exists_for_this_section',
                ];
                $withheld[$section->type]['count']++;
            }

            if ($section->starts_at !== null && $now->lessThan($section->starts_at)) {
                $waiting++;
            } elseif ($section->ends_at !== null && $now->greaterThan($section->ends_at)) {
                $ended++;
            }

            if (!empty($section->platforms)) {
                $platformLimited++;
            }
            if (!empty($section->audience)) {
                $audienceLimited++;
            }
        }

        return [
            'sections'          => $sections->count(),
            'app_supported'     => $appSupported,
            'withheld'          => array_values($withheld),
            'scheduled_waiting' => $waiting,
            'scheduled_ended'   => $ended,
            'platform_limited'  => $platformLimited,
            'audience_limited'  => $audienceLimited,
        ];
    }
}
