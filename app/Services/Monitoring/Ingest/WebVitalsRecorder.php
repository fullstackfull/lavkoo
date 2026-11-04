<?php

namespace App\Services\Monitoring\Ingest;

use App\Services\Monitoring\Support\Clock;
use Illuminate\Http\Request;

/**
 * What the shop actually felt like, measured in the shopper's own browser.
 *
 * Every other speed figure in this system is measured on the server: how long PHP took to answer.
 * That number can be excellent while the page is unusable — an image that reflows the layout after
 * two seconds, a font that blocks the first paint, a script that ignores the first three taps. Only
 * the browser can see those, so only the browser can report them.
 *
 * Stored as ordinary series, so the existing rollup, retention, charting and alerting apply
 * unchanged: `web.vitals.<metric>_ms` for the timing, and three counters per metric for the bands
 * Google's own thresholds define.
 *
 * WHY BANDS AND NOT A p75. The published way to read a vital is "the 75th percentile of visits",
 * and this store keeps aggregates per minute, not a sample per visit — a p75 computed from a mean
 * is not a p75. What it can compute exactly is how many visits fell in each band, and "84% of
 * visits had a good LCP" is both true and the figure a merchant can act on. The panel says which
 * of the two it is showing rather than labelling one as the other.
 *
 * Nothing here trusts the browser with anything but a number: names are checked against a fixed
 * list, values are clamped to a sane range, and the label is the normalised path pattern — never
 * the URL the client sent.
 */
class WebVitalsRecorder
{
    /**
     * The metrics accepted, with the two boundaries that split good / needs-improvement / poor.
     *
     * Thresholds are the published Core Web Vitals ones. CLS is unitless and stored ×1000 so it
     * can share one integer-friendly pipeline with the timings; the panel divides it back.
     */
    public const METRICS = [
        'lcp' => ['good' => 2500, 'poor' => 4000, 'unit' => 'ms', 'max' => 120000],
        'inp' => ['good' => 200, 'poor' => 500, 'unit' => 'ms', 'max' => 60000],
        'cls' => ['good' => 100, 'poor' => 250, 'unit' => 'score_x1000', 'max' => 10000],
        'ttfb' => ['good' => 800, 'poor' => 1800, 'unit' => 'ms', 'max' => 120000],
        'fcp' => ['good' => 1800, 'poor' => 3000, 'unit' => 'ms', 'max' => 120000],
    ];

    /** How many readings one request may report. A page has five vitals, not fifty. */
    public const MAX_PER_REQUEST = 12;

    public function __construct(private readonly BucketWriter $writer)
    {
    }

    /**
     * @param  array<int, mixed>  $readings  [{name, value, path?}, ...] straight off the wire
     * @return int  how many were accepted
     */
    public function record(array $readings, Request $request, ?string $path = null): int
    {
        $minute = intdiv(Clock::now()->getTimestamp(), 60) * 60;
        $points = [];
        $accepted = 0;

        foreach (array_slice($readings, 0, self::MAX_PER_REQUEST) as $reading) {
            if (!is_array($reading)) {
                continue;
            }

            $name = strtolower(trim((string) ($reading['name'] ?? '')));
            $rules = self::METRICS[$name] ?? null;

            if ($rules === null || !is_numeric($reading['value'] ?? null)) {
                continue;
            }

            $value = (float) $reading['value'];

            // A negative timing is not a slow page, it is a broken clock; a value past the ceiling
            // is a tab that sat in the background for an hour. Neither is a measurement of this
            // shop, and averaging either in would poison the window it lands in.
            if ($value < 0 || $value > $rules['max']) {
                continue;
            }

            $label = mb_substr((string) ($path ?? '/'), 0, 96);
            $band = $value <= $rules['good'] ? 'good' : ($value <= $rules['poor'] ? 'needs_improvement' : 'poor');

            $points[BucketWriter::SERIES_PREFIX . 'web.vitals.' . $name . '|' . $label] = [
                'n' => 1, 'sum' => $value, 'v:min' => $value, 'v:max' => $value, 'last' => $value,
            ];
            $points[BucketWriter::SERIES_PREFIX . 'web.vitals.' . $name . '.' . $band . '|' . $label] = [
                'n' => 1, 'sum' => 1, 'v:min' => 1, 'v:max' => 1, 'last' => 1,
            ];

            $accepted++;
        }

        if ($points !== []) {
            try {
                $this->writer->apply([$minute => $points]);
            } catch (\Throwable) {
                // A lost vital is a gap in a chart. It is never worth an error on a shopper's page.
                return 0;
            }
        }

        return $accepted;
    }
}
