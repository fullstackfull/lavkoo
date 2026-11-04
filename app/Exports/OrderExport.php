<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class OrderExport implements FromView, ShouldAutoSize, WithStyles, WithColumnWidths, WithHeadings, WithEvents
{
    use Exportable;

    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function view(): View
    {
        return view('file-exports.order-export', [
            'data' => $this->data,
        ]);
    }

    public function columnWidths(): array
    {
        return [
            'A' => 15,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Determine the last column based on order_status and data source
        $hasOrderStatusColumn = ($this->data['order_status'] == 'all');
        $hasStoreColumn = (isset($this->data['data-from']) && $this->data['data-from'] == 'admin');


        if ($hasStoreColumn && $hasOrderStatusColumn) {
            $lastColumn = 'R';
        } elseif ($hasStoreColumn || $hasOrderStatusColumn) {
            $lastColumn = 'Q';
        } else {
            $lastColumn = 'P';
        }

        $sheet->getStyle('A1')->getFont()->setBold(true);
        $sheet->getStyle('A4:' . $lastColumn . '4')->getFont()->setBold(true)->getColor()
            ->setARGB('FFFFFF');

        $sheet->getStyle('A4:' . $lastColumn . '4')->getFill()->applyFromArray([
            'fillType' => 'solid',
            'rotation' => 0,
            'color' => ['rgb' => '063C93'],
        ]);

        // Apply yellow background to specific columns if needed
        // Adjust these column references based on your actual layout
        if ($hasStoreColumn && $hasOrderStatusColumn) {
            // Order Status column
            $sheet->getStyle('Q5:Q' . ($this->data['orders']->count() + 4))->getFill()->applyFromArray([
                'fillType' => 'solid',
                'rotation' => 0,
                'color' => ['rgb' => 'D6BC00'],
            ]);
            // Total Amount column
            $sheet->getStyle('O5:O' . ($this->data['orders']->count() + 4))->getFill()->applyFromArray([
                'fillType' => 'solid',
                'rotation' => 0,
                'color' => ['rgb' => 'FFF9D1'],
            ]);
        } elseif ($hasOrderStatusColumn) {
            // Order Status column
            $sheet->getStyle('P5:P' . ($this->data['orders']->count() + 4))->getFill()->applyFromArray([
                'fillType' => 'solid',
                'rotation' => 0,
                'color' => ['rgb' => 'D6BC00'],
            ]);
            // Total Amount column
            $sheet->getStyle('N5:N' . ($this->data['orders']->count() + 4))->getFill()->applyFromArray([
                'fillType' => 'solid',
                'rotation' => 0,
                'color' => ['rgb' => 'FFF9D1'],
            ]);
        } elseif ($hasStoreColumn) {
            // Total Amount column
            $sheet->getStyle('O5:O' . ($this->data['orders']->count() + 4))->getFill()->applyFromArray([
                'fillType' => 'solid',
                'rotation' => 0,
                'color' => ['rgb' => 'FFF9D1'],
            ]);
        } else {
            // Total Amount column
            $sheet->getStyle('N5:N' . ($this->data['orders']->count() + 4))->getFill()->applyFromArray([
                'fillType' => 'solid',
                'rotation' => 0,
                'color' => ['rgb' => 'FFF9D1'],
            ]);
        }

        $sheet->setShowGridlines(false);

        return [
            // Define the style for cells with data
            'A1:' . $lastColumn . ($this->data['orders']->count() + 4) => [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['argb' => '000000'],
                    ],
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                // Determine the last column based on order_status and data source
                $hasOrderStatusColumn = ($this->data['order_status'] == 'all');
                $hasStoreColumn = (isset($this->data['data-from']) && $this->data['data-from'] == 'admin');

                if ($hasStoreColumn && $hasOrderStatusColumn) {
                    $lastColumn = 'R';
                    $filterColumn = 'R';
                } elseif ($hasStoreColumn || $hasOrderStatusColumn) {
                    $lastColumn = 'Q';
                    $filterColumn = 'Q';
                } else {
                    $lastColumn = 'P';
                    $filterColumn = 'P';
                }

                // Center alignment for title row
                $event->sheet->getStyle('A1:' . $lastColumn . '1')
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                // Center alignment for data rows (from row 4 onwards)
                $event->sheet->getStyle('A4:' . $lastColumn . ($this->data['orders']->count() + 4))
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                // Left alignment for filter criteria
                $event->sheet->getStyle('A2:' . $filterColumn . '3')
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                // Merge title row
                $event->sheet->mergeCells('A1:' . $lastColumn . '1');

                // Merge filter criteria rows
                $event->sheet->mergeCells('A2:B2');
                $event->sheet->mergeCells('C2:' . $filterColumn . '2');
                $event->sheet->mergeCells('A3:B3');
                $event->sheet->mergeCells('C3:' . $filterColumn . '3');

                // Set row heights
                $event->sheet->getRowDimension(2)->setRowHeight(110);
                $event->sheet->getDefaultRowDimension()->setRowHeight(30);
            },
        ];
    }

    public function headings(): array
    {
        return [
            '1'
        ];
    }
}
