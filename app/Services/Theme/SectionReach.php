<?php

namespace App\Services\Theme;

use App\Services\Analytics\Reporting\AnalyticsReporting;
use App\Services\Analytics\Reporting\Window;
use Illuminate\Support\Facades\Cache;

/**
 * How many shoppers actually reached each section.
 *
 * The builder answers "what did I arrange". Nothing answered "did anyone get that far" — and that
 * is the question an arrangement exists to settle. A rail nobody scrolls to looks identical, in
 * every screen there was, to one at the top that everybody sees, and only one of those is fixed by
 * moving it up.
 *
 * Read from the analytics rollup rather than the raw events: the rollup has already excluded bots
 * and staff, which is the difference between "shoppers saw this" and "something requested the
 * page". A shop with analytics not installed, or not yet rolled up, gets an empty map and the
 * builder simply says nothing — an absent number is honest, an invented one is not.
 */
class SectionReach
{
    /** Long enough that opening the builder repeatedly costs one query, short enough to feel live. */
    private const CACHE_TTL = 300;

    /**
     * The window a merchant is actually deciding over.
     *
     * A month, because it is one of the ranges the analytics screens already offer: a number in
     * the builder that covers a different period from the number on the report it links to is
     * worse than no number at all.
     */
    private const RANGE = '30d';

    public function __construct(private readonly AnalyticsReporting $reporting)
    {
    }

    /**
     * Visitors per section id, for the sections of one page.
     *
     * @return array<int, int>
     */
    public function visitors(): array
    {
        return Cache::remember('theme_section_reach', self::CACHE_TTL, function () {
            $breakdown = $this->reporting->breakdown(Window::make(self::RANGE), 'theme_section', 200);

            $reach = [];

            foreach ($breakdown['rows'] ?? [] as $row) {
                $key = (string) ($row['key'] ?? '');

                // Visitors, not events: the same person scrolling past a rail on three visits
                // is one shopper who has seen it, and "how many people got here" is the
                // question being asked. Stored sections report a numeric id; campaign overlay
                // sections report campaign-{id} — casting everything to int silently threw the
                // campaign rows away.
                if (preg_match('/^(?:[1-9]\d*|campaign-[1-9]\d*)$/', $key)) {
                    $reach[$key] = (int) ($row['visitors'] ?? 0);
                }
            }

            return $reach;
        });
    }

    /** Whether there is anything to show at all — nothing measured yet is not zero reach. */
    /** How many shoppers a campaign's overlay sections reached in the window, or null unmeasured. */
    public function campaignVisitors(int $campaignId): ?int
    {
        $visitors = $this->visitors()['campaign-' . $campaignId] ?? null;

        return $visitors === null ? null : (int) $visitors;
    }

    public function measured(): bool
    {
        return $this->visitors() !== [];
    }
}
