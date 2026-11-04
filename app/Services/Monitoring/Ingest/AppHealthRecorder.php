<?php

namespace App\Services\Monitoring\Ingest;

use App\Services\Monitoring\Support\Clock;

/**
 * The one thing about a mobile app the server cannot see: whether it stayed running.
 *
 * Everything else on the Android and iOS sections is measured here, from traffic the apps send —
 * how much they talk, how fast they are answered, which versions are in the wild. A crash produces
 * no request at all. It is, by definition, the absence of traffic, and absence is exactly what a
 * server-side monitor cannot tell apart from a quiet evening.
 *
 * So crash-free sessions are SELF-REPORTED, and the panels say so on the card rather than
 * presenting them as measured. The app posts three counters — sessions started, crashes, ANRs —
 * and the percentage is derived from them. If the app is not sending them the sections say
 * `not_configured` and name this endpoint, which is the honest answer; a crash-free figure of 100%
 * from an app that never reported anything would be a lie with a reassuring shape.
 *
 * WHAT IS DELIBERATELY NOT ACCEPTED. No stack traces, no device identifiers, no user id, no
 * message bodies, no breadcrumbs. Counts, a platform and a version string — nothing that could
 * carry a customer's data into a monitoring table, and nothing that needs redacting because
 * nothing free-form is stored in the first place. A crash reporter is a different product; this
 * exists so the shop knows the apps are alive.
 */
class AppHealthRecorder
{
    /** The counters an app may report, and the series each becomes. */
    public const KINDS = ['sessions', 'crashes', 'anrs'];

    public const PLATFORMS = ['android', 'ios'];

    /** The series prefix every reading here shares. */
    public const SERIES = 'app.health.';

    /**
     * A ceiling on one report, so a single call cannot claim a million sessions.
     *
     * This endpoint is public — a crash happens when nobody is logged in — so its numbers are as
     * trustworthy as the caller. The rate limit bounds how often, this bounds how much, and the
     * panel says the figure is self-reported. That is the whole defence, and it is proportionate:
     * the worst an abuser achieves is a wrong number on an internal dashboard.
     */
    public const MAX_PER_COUNTER = 10000;

    public function __construct(private readonly BucketWriter $writer)
    {
    }

    /**
     * @param  array<string, mixed>  $counters  kind => count, straight off the wire
     * @return int  how many counters were accepted
     */
    public function record(string $platform, ?string $version, array $counters): int
    {
        $platform = strtolower(trim($platform));

        if (!in_array($platform, self::PLATFORMS, true)) {
            return 0;
        }

        $label = $platform . ':' . ($this->cleanVersion($version) ?? 'unknown');
        $minute = intdiv(Clock::now()->getTimestamp(), 60) * 60;
        $points = [];
        $accepted = 0;

        foreach (self::KINDS as $kind) {
            if (!isset($counters[$kind]) || !is_numeric($counters[$kind])) {
                continue;
            }

            $count = (int) $counters[$kind];

            if ($count <= 0 || $count > self::MAX_PER_COUNTER) {
                continue;
            }

            $points[BucketWriter::SERIES_PREFIX . self::SERIES . $kind . '|' . $label] = [
                'n' => $count, 'sum' => $count,
            ];
            $accepted++;
        }

        if ($points === []) {
            return 0;
        }

        try {
            $this->writer->apply([$minute => $points]);
        } catch (\Throwable) {
            // Monitoring never fails the caller. A lost report is a gap in a chart.
            return 0;
        }

        return $accepted;
    }

    /**
     * The same shape the request middleware already accepts for X-App-Version, applied here so one
     * app cannot appear under two spellings depending on which path reported it.
     */
    private function cleanVersion(?string $version): ?string
    {
        $version = trim((string) $version);

        return preg_match('/^[0-9A-Za-z\.\-\+]{1,32}$/', $version) === 1 ? $version : null;
    }
}
