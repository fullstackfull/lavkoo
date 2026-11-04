<?php

namespace App\Services\Commerce;

use App\Models\ExperienceCampaign;
use App\Services\Platform\Policy;
use App\Services\Theme\SectionRegistry;

/**
 * What a campaign may say, and when two campaigns cannot both be right (Phase 3.3).
 *
 * Overrides are admin input that will be rendered to every shopper, so they pass through the same
 * settings normalisation the builder's own sections do — an override can only be a section type
 * this server renders, with settings coerced to their declared shapes. Conflict detection (§38)
 * runs at save and at activation: two campaigns contesting one slot with equal priority in
 * overlapping windows is a coin flip waiting to be served, and §36 forbids coin flips.
 */
class CampaignRules
{
    /** Where an override may land on the page — positions, because a page is an ordered list. */
    public const SLOTS = ['hero', 'top', 'middle', 'bottom'];

    /**
     * The section types a campaign override may compose. Deliberately the campaign-shaped subset
     * of the registry — every one renders on the web and in the app today.
     */
    public const OVERRIDE_TYPES = [
        'hero_banner', 'promotional_banner', 'banner_strip', 'product_slider', 'flash_deal', 'spacer',
    ];

    public function __construct(
        private readonly SectionRegistry $registry,
        private readonly Policy $policy,
    ) {
    }

    /**
     * @return array{overrides: array<int, array{slot: string, section: array{type: string, settings: array<string, mixed>}}>,
     *               errors: array<int, string>}
     */
    public function validateOverrides(mixed $rows): array
    {
        if (!is_array($rows)) {
            return ['overrides' => [], 'errors' => $rows === null ? [] : ['overrides:not_a_list']];
        }

        $clean = [];
        $errors = [];
        $slotsSeen = [];

        // Refused, not truncated. Slicing the tail off meant an admin who went one over the limit
        // saw the rest saved and was never told what had been dropped.
        $limit = $this->policy->int('commerce_max_campaign_overrides');
        if (count($rows) > $limit) {
            return ['overrides' => [], 'errors' => ['overrides:at_most_' . $limit]];
        }

        foreach (array_values($rows) as $index => $row) {
            $label = 'override_' . ($index + 1);

            $slot = is_array($row) && is_string($row['slot'] ?? null) ? $row['slot'] : '';
            $type = is_array($row) && is_string($row['section']['type'] ?? null) ? $row['section']['type'] : '';

            if (!in_array($slot, self::SLOTS, true)) {
                $errors[] = $label . ':unknown_slot';
                continue;
            }
            if (in_array($slot, $slotsSeen, true)) {
                $errors[] = $label . ':slot_used_twice_in_one_campaign';
                continue;
            }
            if (!in_array($type, self::OVERRIDE_TYPES, true)) {
                $errors[] = $label . ':section_type_not_allowed_in_a_campaign';
                continue;
            }

            $settings = is_array($row['section']['settings'] ?? null) ? $row['section']['settings'] : [];

            $section = [
                'type' => $type,
                // The same coercion the builder applies — an override reaches a shopper
                // exactly as sanitised as a composed section does.
                'settings' => $this->registry->normalizeSettings($type, $settings),
            ];

            // Banner-backed types draw their content from blocks, not from section settings; a
            // hero override with a title and no block would render as an empty band. The one
            // block a campaign hero needs is synthesised from the same fields the admin typed,
            // through the block's own normalisation.
            $blockType = $this->registry->defaultBlockType($type);
            if ($blockType !== null) {
                $section['blocks'] = [[
                    'type'     => $blockType,
                    'settings' => $this->registry->normalizeBlockSettings($blockType, $settings),
                ]];
            }

            $slotsSeen[] = $slot;
            $clean[] = ['slot' => $slot, 'section' => $section];
        }

        return ['overrides' => $clean, 'errors' => $errors];
    }

    /**
     * The §38 check: campaigns that would contest a slot with nothing to break the tie.
     *
     * @return array<int, string> conflict descriptions; empty when the campaign may go live
     */
    public function conflictsFor(ExperienceCampaign $campaign): array
    {
        $slots = array_column($campaign->overrideRows(), 'slot');
        if ($slots === []) {
            return [];
        }

        try {
            $rivals = ExperienceCampaign::query()
                ->whereKeyNot($campaign->id)
                ->where('page', $campaign->page)
                ->whereIn('status', ExperienceCampaign::SERVABLE_STATUSES)
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $conflicts = [];

        foreach ($rivals as $rival) {
            if (!$this->windowsOverlap($campaign, $rival)) {
                continue;
            }

            $contested = array_intersect($slots, array_column($rival->overrideRows(), 'slot'));

            if ($contested !== [] && (int) $rival->priority === (int) $campaign->priority) {
                $conflicts[] = $rival->name . ' (' . implode(', ', $contested) . ')';
            }
        }

        return $conflicts;
    }

    private function windowsOverlap(ExperienceCampaign $first, ExperienceCampaign $second): bool
    {
        $firstStart = $first->starts_at?->timestamp ?? PHP_INT_MIN;
        $firstEnd = $first->ends_at?->timestamp ?? PHP_INT_MAX;
        $secondStart = $second->starts_at?->timestamp ?? PHP_INT_MIN;
        $secondEnd = $second->ends_at?->timestamp ?? PHP_INT_MAX;

        return $firstStart < $secondEnd && $secondStart < $firstEnd;
    }
}
