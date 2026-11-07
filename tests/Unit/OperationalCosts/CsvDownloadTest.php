<?php

namespace Tests\Unit\OperationalCosts;

use App\Http\Controllers\Admin\OperationalCosts\CsvDownload;
use App\Services\OperationalCosts\Reports\CsvExporter;
use PHPUnit\Framework\TestCase;

class CsvDownloadTest extends TestCase
{
    private function download(): CsvDownload
    {
        return new CsvDownload(new CsvExporter());
    }

    public function test_an_export_that_has_more_rows_than_its_limit_ends_with_a_line_that_says_so(): void
    {
        $rows = iterator_to_array($this->download()->limited([['a'], ['b'], ['c'], ['d']], 'stops here', limit: 3), false);

        $this->assertSame([['a'], ['b'], ['c'], ['stops here']], $rows);
    }

    public function test_an_export_within_its_limit_is_left_whole_and_says_nothing(): void
    {
        $this->assertSame([['a'], ['b'], ['c']], iterator_to_array($this->download()->limited([['a'], ['b'], ['c']], 'stops here', limit: 3), false));
        $this->assertSame([], iterator_to_array($this->download()->limited([], 'stops here', limit: 3), false));
    }

    public function test_the_limit_is_read_lazily_so_a_huge_report_is_never_held_in_memory(): void
    {
        $read = 0;
        $rows = (function () use (&$read) {
            for ($index = 1; $index <= 1000; $index++) {
                $read++;
                yield [$index];
            }
        })();

        $limited = iterator_to_array($this->download()->limited($rows, 'stops here', limit: 5), false);

        $this->assertCount(6, $limited);
        $this->assertSame(6, $read, 'It stopped reading at the first row past the limit.');
    }

    public function test_the_default_limit_is_a_number_a_spreadsheet_can_open(): void
    {
        $this->assertSame(50000, CsvDownload::ROW_LIMIT);
        $this->assertLessThan(1048576, CsvDownload::ROW_LIMIT, 'Excel holds 1,048,576 rows.');
    }
}
