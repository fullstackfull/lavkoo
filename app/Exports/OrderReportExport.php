<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Maatwebsite\Excel\Events\AfterSheet;

class OrderReportExport implements
    FromView,
    WithStyles,
    WithColumnWidths,
    WithHeadings,
    WithEvents
{
    use Exportable;

    protected $data;
    protected string $lastColumn = 'M';

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function view(): View
    {
        return view('file-exports.order-report-export', [
            'data' => $this->data,
        ]);
    }

    /**
     * Column widths
     */
    public function columnWidths(): array
    {
        return [
            'A' => 8,
            'B' => 20,
            'C' => 18,
            'D' => 18,
            'E' => 18,
            'F' => 18,
            'G' => 18,
            'H' => 18,
            'I' => 18,
            'J' => 15,
            'K' => 18,
            'L' => 22,
            'M' => 15,
        ];
    }

    /**
     * Styles
     */
    public function styles(Worksheet $sheet)
    {
        $rowCount = $this->data['orders']->count() + 3;

        // Title & header fonts
        $sheet->getStyle("A1:A2")->getFont()->setBold(true);

        $sheet->getStyle("A3:{$this->lastColumn}3")
            ->getFont()
            ->setBold(true)
            ->getColor()
            ->setARGB('FFFFFF');

        // Header background
        $sheet->getStyle("A3:{$this->lastColumn}3")->getFill()->applyFromArray([
            'fillType' => 'solid',
            'color' => ['rgb' => '063C93'],
        ]);

        // Highlight incentive column
        $sheet->getStyle("L4:L{$rowCount}")->getFill()->applyFromArray([
            'fillType' => 'solid',
            'color' => ['rgb' => 'FFF9D1'],
        ]);

        // Highlight status column
        $sheet->getStyle("M4:M{$rowCount}")->getFill()->applyFromArray([
            'fillType' => 'solid',
            'color' => ['rgb' => 'E6F2FF'],
        ]);

        // Remove gridlines
        $sheet->setShowGridlines(false);

        // Borders for full table
        return [
            "A1:{$this->lastColumn}{$rowCount}" => [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['argb' => '000000'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Events
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {

                $rowCount = $this->data['orders']->count() + 3;

                // Center title
                $event->sheet->getStyle("A1:{$this->lastColumn}1")->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                // Header alignment
                $event->sheet->getStyle("A3:{$this->lastColumn}{$rowCount}")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                // Filter row alignment
                $event->sheet->getStyle("A2:{$this->lastColumn}2")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                // Merge cells
                $event->sheet->mergeCells("A1:{$this->lastColumn}1");
                $event->sheet->mergeCells("A2:B2");
                $event->sheet->mergeCells("C2:{$this->lastColumn}2");

                // Row heights
                $event->sheet->getRowDimension(2)->setRowHeight(80);
                $event->sheet->getDefaultRowDimension()->setRowHeight(30);
            },
        ];
    }

    public function headings(): array
    {
        return ['1'];
    }
}
