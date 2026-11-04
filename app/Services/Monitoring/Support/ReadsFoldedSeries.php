<?php

namespace App\Services\Monitoring\Support;

use Illuminate\Support\Carbon;

/**
 * Reading an aggregate out of monitoring_series without falling into the fold seam.
 *
 * The rollup folds minute rows into hour and day parents, and the newest parent is written while
 * it is still filling. A panel that trusts it drops everything from the start of that parent to
 * now — up to fifty-six minutes on an hour window, nearly a day on a day window — which is exactly
 * how a headline figure ends up contradicting the table beneath it.
 *
 * So the coarse rows are read strictly BEFORE the newest folded parent, and the raw minutes from
 * that parent onward. Every panel that sums a series over a window needs this, and a second copy
 * of it is a second chance to get it subtly wrong.
 *
 * The using class must expose a `SeriesReader $reader`.
 */
trait ReadsFoldedSeries
{
    /**
     * The fold seam, once per resolution.
     *
     * A panel serves exactly one range in one request — which is also what makes the
     * nothing-has-been-folded fallback safe to remember, since it is that window's own start.
     *
     * @var array<string, Carbon>
     */
    private array $seams = [];

    /**
     * One aggregate read, on both sides of the seam.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @param  callable(string, Carbon, ?Carbon): \Illuminate\Database\Query\Builder  $build
     * @return array{rows: array<int, object>, truncated: bool}
     */
    private function acrossSeam(string $range, array $window, callable $build, ?int $limit = null): array
    {
        $from = $this->reader->since($range);
        $seam = $window['resolution'] === 'minute' ? null : $this->foldSeam($window['resolution'], $from);

        $rows = $build($window['resolution'], $from, $seam)->get()->all();
        $truncated = $limit !== null && count($rows) > $limit;

        if ($seam !== null) {
            $tail = $build('minute', $seam->max($from), null)->get()->all();
            $truncated = $truncated || ($limit !== null && count($tail) > $limit);
            $rows = array_merge($rows, $tail);
        }

        return ['rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * Where the folded buckets stop and the raw minutes take over.
     *
     * The newest folded parent's own start. Read across the whole table rather than one metric,
     * because the rollup folds every series in one pass: the seam is a property of when the rollup
     * last ran, not of which metric is being read. Rides monitoring_series_unique (resolution,
     * bucket_at, ...), whose leading column is the resolution.
     */
    private function foldSeam(string $resolution, Carbon $from): Carbon
    {
        if (!isset($this->seams[$resolution])) {
            $newestFolded = $this->reader->connection()->table('monitoring_series')
                ->where('resolution', $resolution)
                ->max('bucket_at');

            $this->seams[$resolution] = $newestFolded !== null ? Clock::parse($newestFolded) : $from;
        }

        return $this->seams[$resolution];
    }
}
