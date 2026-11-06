<?php

namespace App\Http\Controllers\Admin\OperationalCosts;

use App\Services\OperationalCosts\Reports\CsvExporter;
use Generator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A report sent to the browser as a CSV file, written as it is read so that a long report is never held in memory. */
class CsvDownload
{
    /** The most rows one export carries. */
    public const ROW_LIMIT = 50000;

    public function __construct(private readonly CsvExporter $exporter)
    {
    }

    /**
     * @param list<string> $headings
     * @param iterable<list<mixed>> $rows
     */
    public function stream(string $filename, array $headings, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows): void {
            $stream = fopen('php://output', 'wb');
            $this->exporter->write($stream, $headings, $rows);
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * At most ROW_LIMIT rows and then, if there were more, one line that says the file stops there - an export is never silently cut short.
     *
     * @param iterable<list<mixed>> $rows
     * @return Generator<int, list<mixed>>
     */
    public function limited(iterable $rows, string $notice): Generator
    {
        $count = 0;
        foreach ($rows as $row) {
            if ($count === self::ROW_LIMIT) {
                yield [$notice];

                return;
            }
            yield $row;
            $count++;
        }
    }
}
