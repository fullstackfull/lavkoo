<?php

namespace Tests\Support;

use Illuminate\Routing\Route as RoutingRoute;

/**
 * Which routes of the admin panel hand a FILE to the employee -- a spreadsheet, a PDF, a stream -- read from the real route table and the
 * source of each action (GCC-14c, G-10).
 *
 * An export is the one surface where a narrowing that is almost right is worth nothing: the rows leave the panel, and nobody looks at a
 * downloaded file again to ask which markets it held. So the question this scanner answers is not "is this screen scoped" but "does this
 * route produce a file at all", and the guard that reads it (`StaffExportRouteInventoryTest`) then demands that somebody has decided, for
 * each one, whether the file holds one market's rows or every market's.
 *
 * The markers are the ways this repository writes a download: Maatwebsite Excel, FastExcel, the two PDF facades, a streamed response and a
 * spreadsheet or CSV content type set by hand. It is a pattern check over source text and so has the blind spot every scanner here has -- an
 * export produced inside a service the markers do not name is invisible -- which is why the guard keeps a hand table beside the scan.
 *
 * **Which of the two readings is actually carrying this scan, measured rather than assumed.** Blinding the Maatwebsite marker changes
 * nothing at all: every spreadsheet of this panel is also reached by an action called `export...` or a path containing `/export`. Blinding
 * the NAME and PATH reading loses four controllers, and two of them are real exports -- the operational-cost profit export and the cost
 * reports, which hand back a `StreamedResponse` built inside `ReportExports`, so no marker appears in the controller at all. So the name
 * reading is what finds today's exports, and the source markers are here for the one that will be called something else.
 */
final class StaffExportRouteScanner extends StaffRouteScanner
{
    /** What in the source of an action means that it answers with a file. */
    private const SOURCE_MARKERS = [
        'excel' => '/\bExcel::download\s*\(|\bExcel::raw\s*\(/',
        'fast excel' => '/\b(?:FastExcel|fastexcel)\b/',
        'pdf' => '/\b(?:PDF|Pdf)::(?:loadView|loadHTML)\s*\(|->(?:stream|download)\s*\(\s*[\'"][^\'"]+\.pdf/',
        'streamed download' => '/->streamDownload\s*\(|streamDownload\s*\(/',
        'file response' => '/->(?:download|file)\s*\(\s*(?:storage_path|public_path|base_path|\$)/',
        'spreadsheet content type' => '#application/vnd\.(?:ms-excel|openxmlformats)|text/csv#',
    ];

    /** @return array<string, array{file: string, routes: list<string>, exportRoutes: list<string>, markers: list<string>}> */
    public function controllers(): array
    {
        return $this->scan(readingKey: 'exportRoutes');
    }

    /** @return list<string> why a route hands over a file: empty when it does not */
    public function routeExports(RoutingRoute $route): array
    {
        return $this->routeReasons(route: $route);
    }

    protected function sourceMarkers(): array
    {
        return self::SOURCE_MARKERS;
    }

    /**
     * The path and the action name are read as well as the source, because the oldest exports of this panel are named rather than written
     * recognisably: `exportList`, `export`, `/download`, `-pdf`. A route that says it exports and whose source shows no marker is still an
     * export as far as the guard is concerned -- it has to be classified -- and the reason says which reading found it.
     */
    protected function nameReasons(RoutingRoute $route): array
    {
        $reasons = [];
        $action = (string) $route->getActionMethod();

        if (preg_match('/^(?:export|download)|(?:Export|Download|Pdf|PDF|Excel|Csv|CSV)/', $action) === 1) {
            $reasons[] = 'action ' . $action;
        }
        if (preg_match('#(^|/|-)(?:export|download|pdf|excel|csv)([/-]|$)#i', $route->uri()) === 1) {
            $reasons[] = 'path';
        }

        return $reasons;
    }
}
