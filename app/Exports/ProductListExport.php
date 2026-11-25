<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductListExport implements FromView, ShouldAutoSize, WithStyles, WithColumnWidths, WithHeadings, WithEvents
{
    use Exportable;

    /** The columns every sheet has, SL to status. */
    private const BASE_COLUMNS = 18;

    /**
     * The narrowest range this sheet has ever been styled over. Kept as a floor so that a sheet with no optional column is bordered
     * exactly as it is today -- the arithmetic says R, the sheet has always said S, and widening a border range by one empty column is
     * not worth a visible change to every existing export.
     */
    private const MINIMUM_LAST_COLUMN = 'S';

    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * The optional columns follow the last column of the sheet, so every column that was there keeps its letter. There are three of
     * them now -- tax, country and execution source -- so this counts rather than enumerating the combinations: the old two-way test
     * said T only when BOTH tax and country were on, and would have styled a sheet one column short as soon as a third was added.
     */
    private function lastColumn(): string
    {
        $optional = count(array_filter([
            $this->data['productWiseTax'] ?? false,
            $this->data['countryColumn'] ?? false,
            $this->data['sourceColumn'] ?? false,
        ]));

        $last = Coordinate::stringFromColumnIndex(self::BASE_COLUMNS + $optional);

        return strlen($last) === 1 && $last < self::MINIMUM_LAST_COLUMN ? self::MINIMUM_LAST_COLUMN : $last;
    }

    public function view(): View
    {
        return view('file-exports.product-list', [
            'data' => $this->data,
        ]);
    }

    public function columnWidths(): array
    {
        return [
            'A' => 15,
            'C' => 25,
            'D' => 25,
            'F' => 50,
            'G' => 20,
            'H' => 20,
            'I' => 20,
            'J' => 20,
            'R' => 25,
            'S' => 25,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $lastColumn = $this->lastColumn();
        $sheet->getStyle('A1:A3')->getFont()->setBold(true);
        $sheet->getStyle("A3:{$lastColumn}3")->getFont()->setBold(true)->getColor()
            ->setARGB('FFFFFF');

        $sheet->getStyle("A3:{$lastColumn}3")->getFill()->applyFromArray([
            'fillType' => 'solid',
            'rotation' => 0,
            'color' => ['rgb' => '063C93'],
        ]);

        $sheet->setShowGridlines(false);
        return [
            // Define the style for cells with data
            "A1:{$lastColumn}" . ($this->data['products']->count() + 3) => [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['argb' => '000000'], // Specify the color of the border (optional)
                    ],
                ],
                'alignment' => [
                    'wrapText' => true,
                ],
            ],
        ];
    }

    public function setImage($workSheet)
    {
        $this->data['products']->each(function ($item, $index) use ($workSheet) {
            $tempImagePath = null;
            $filePath = 'product/thumbnail/' . $item->thumbnail_full_url['key'];
            $fileCheck = fileCheck(disk: 'public', path: $filePath);
            if ($item->thumbnail_full_url['path'] && !$fileCheck) {
                $tempImagePath = getTemporaryImageForExport($item->thumbnail_full_url['path']);
                $imagePath = getImageForExport($item->thumbnail_full_url['path']);
                $drawing = new MemoryDrawing();
                $drawing->setImageResource($imagePath);
            } else {
                $drawing = new Drawing();
                $drawing->setPath(is_file(storage_path('app/public/' . $filePath)) ? storage_path('app/public/' . $filePath) : public_path('assets/back-end/img/products.png'));
            }
            $drawing->setName($item->name);
            $drawing->setDescription($item->name);
            $drawing->setHeight(50);
            $drawing->setOffsetX(45);
            $drawing->setOffsetY(70);
            $drawing->setResizeProportional(true);
            $index += 4;
            $drawing->setCoordinates("B$index");
            $drawing->setWorksheet($workSheet);
            if ($tempImagePath) {
                imagedestroy($tempImagePath);
            }
        });
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $lastColumn = $this->lastColumn();
                $event->sheet->getStyle("A1:{$lastColumn}1") // Adjust the range as per your needs
                ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);
                $event->sheet->getStyle("A3:{$lastColumn}" . ($this->data['products']->count() + 3)) // Adjust the range as per your needs
                ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);
                $event->sheet->getStyle("A2:{$lastColumn}2") // Adjust the range as per your needs
                ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                $event->sheet->mergeCells("A1:{$lastColumn}1");
                $event->sheet->mergeCells('A2:B2');
                $event->sheet->mergeCells("C2:{$lastColumn}2");
                $event->sheet->mergeCells("D2:{$lastColumn}2");
                if ($this->data['type'] != 'seller') {
                    $event->sheet->mergeCells('F3:G3');
                    $this->data['products']->each(function ($item, $index) use ($event) {
                        $index += 4;
                        $event->sheet->mergeCells("F$index:G$index");
                    });
                }
                $event->sheet->getRowDimension(2)->setRowHeight(100);
                $event->sheet->getRowDimension(1)->setRowHeight(30);
                $event->sheet->getRowDimension(3)->setRowHeight(30);
                $event->sheet->getDefaultRowDimension()->setRowHeight(150);

                $workSheet = $event->sheet->getDelegate();
                $this->setImage($workSheet);
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
