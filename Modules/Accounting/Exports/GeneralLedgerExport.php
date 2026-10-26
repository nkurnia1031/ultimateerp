<?php

namespace Modules\Accounting\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class GeneralLedgerExport implements FromView, WithEvents
{
    private $reportView;

    public function __construct(View $reportView)
    {
        $this->reportView = $reportView;
    }

    public function view(): View
    {
        return $this->reportView;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = max(5, $sheet->getHighestRow());
                $lastColumn = 'I';

                $sheet->setTitle('Buku Besar');
                $sheet->setShowGridlines(false);
                $sheet->getParent()->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
                $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getAlignment()
                    ->setVertical(Alignment::VERTICAL_TOP)
                    ->setWrapText(true);
                $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()->setARGB('FFD9E2F3');

                foreach (['A1:I1', 'A2:I2', 'A3:I3'] as $range) {
                    if (! in_array($range, $sheet->getMergeCells(), true)) {
                        $sheet->mergeCells($range);
                    }
                }

                $sheet->getStyle('A1:I1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 15, 'color' => ['argb' => 'FF17365D']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEAF1F8']],
                    'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF5B7898']]],
                ]);
                $sheet->getStyle('A2:I2')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FF17365D']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEAF1F8']],
                ]);
                $sheet->getStyle('A3:I3')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF40556B']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF4F7FA']],
                    'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF5B7898']]],
                ]);

                $sheet->getStyle('A4:I5')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF294965']],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFB7C8D8']]],
                ]);

                $sheet->getStyle("G6:I{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle("A6:F{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                $sheet->getRowDimension(1)->setRowHeight(25);
                $sheet->getRowDimension(2)->setRowHeight(23);
                $sheet->getRowDimension(3)->setRowHeight(20);
                $sheet->getRowDimension(4)->setRowHeight(25);
                $sheet->getRowDimension(5)->setRowHeight(25);

                $detailIndex = 0;
                for ($row = 6; $row <= $lastRow; $row++) {
                    $firstCell = trim((string) $sheet->getCell("A{$row}")->getValue());
                    $description = trim((string) $sheet->getCell("B{$row}")->getValue());

                    if (str_starts_with($firstCell, 'Akun:')) {
                        $sheet->getStyle("A{$row}:I{$row}")->applyFromArray([
                            'font' => ['bold' => true, 'color' => ['argb' => 'FF17365D']],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFDCE8F2']],
                            'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF9EB4C9']]],
                        ]);
                        $sheet->getRowDimension($row)->setRowHeight(20);
                        continue;
                    }

                    $detailIndex++;
                    if ($description === 'Saldo Awal') {
                        $sheet->getStyle("A{$row}:I{$row}")->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()->setARGB('FFFFF4D6');
                        $sheet->getStyle("A{$row}:I{$row}")->getFont()->setBold(true);
                    } elseif ($detailIndex % 2 === 0) {
                        $sheet->getStyle("A{$row}:I{$row}")->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()->setARGB('FFF7F9FC');
                    }
                }

                foreach (['A' => 13, 'B' => 30, 'C' => 34, 'D' => 32, 'E' => 28, 'F' => 18, 'G' => 18, 'H' => 18, 'I' => 19] as $column => $width) {
                    $sheet->getColumnDimension($column)->setWidth($width);
                }

                $sheet->freezePane('A6');
                $sheet->getPageSetup()
                    ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
                    ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
                    ->setFitToWidth(1)
                    ->setFitToHeight(0);
                $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 5);
                $sheet->getPageMargins()->setTop(0.35)->setRight(0.3)->setBottom(0.35)->setLeft(0.3);
                $sheet->getHeaderFooter()->setOddFooter('&L Buku Besar&C Halaman &P dari &N&R Dicetak: &D');
            },
        ];
    }
}
