<?php

namespace App\Services;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SolarWorkbookService
{
    public const HEADER_ROW = 4;

    public const DATA_ROW = 5;

    private const DEFINITIONS = [
        'stock' => [
            'title' => 'LAPORAN STOCK SOLAR',
            'sheet' => 'SOLAR',
            'headers' => [
                'TANGGAL' => 'Tanggal transaksi. Gunakan format tanggal Excel atau DD/MM/YYYY.',
                'IN' => 'Jumlah solar masuk. Isi angka 0 atau lebih.',
                'OUT ACT' => 'Jumlah keluar berdasarkan aktual.',
                'STOCK ACT' => 'Saldo aktual. Dihitung otomatis oleh aplikasi.',
                'OUT METERAN' => 'Jumlah keluar berdasarkan meteran.',
                'STOCK METERAN' => 'Saldo meteran. Dihitung otomatis oleh aplikasi.',
                'KETERANGAN' => 'Catatan transaksi, opsional.',
            ],
        ],
        'pool' => [
            'title' => 'LAPORAN SOLAR POOL',
            'sheet' => 'POOL',
            'headers' => [
                'TANGGAL' => 'Tanggal transaksi. Gunakan format tanggal Excel atau DD/MM/YYYY.',
                'IN' => 'Jumlah masuk. Isi angka 0 atau lebih.',
                'OUT' => 'Jumlah keluar. Isi angka 0 atau lebih.',
                'CURRENT' => 'Saldo berjalan. Dihitung otomatis oleh aplikasi.',
                'KETERANGAN' => 'Catatan transaksi, opsional.',
                'UNIT' => 'Satuan, misalnya liter atau gln. Opsional.',
                'OPERATOR' => 'Nama operator. Opsional.',
                'CUSTOMER' => 'Gunakan referensi C{id} | Nama Customer. Opsional.',
            ],
        ],
        'mess' => [
            'title' => 'LAPORAN SOLAR MESS',
            'sheet' => 'MESS',
            'headers' => [
                'TANGGAL' => 'Tanggal transaksi. Gunakan format tanggal Excel atau DD/MM/YYYY.',
                'IN' => 'Jumlah masuk. Isi angka 0 atau lebih.',
                'OUT' => 'Jumlah keluar. Isi angka 0 atau lebih.',
                'CURRENT' => 'Saldo berjalan. Dihitung otomatis oleh aplikasi.',
                'KETERANGAN' => 'Catatan transaksi, opsional.',
                'NAMA KAPAL' => 'Nama kapal sebagai atribut tersendiri. Opsional.',
                'UNIT' => 'Satuan, misalnya liter atau gln. Opsional.',
                'OPERATOR' => 'Nama operator. Opsional.',
                'CUSTOMER' => 'Gunakan referensi C{id} | Nama Customer. Opsional.',
                'LOKASI' => 'Lokasi penyerahan atau pemakaian. Opsional.',
            ],
        ],
    ];

    public function parse(string $path, array $dateOverrides = []): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($path);
        $result = [
            'rows' => ['stock' => [], 'pool' => [], 'mess' => []],
            'opening' => [],
            'errors' => [],
        ];

        try {
            foreach (self::DEFINITIONS as $ledger => $definition) {
                $sheet = $this->findSheet($workbook, $definition['sheet']);
                if (! $sheet) {
                    $result['errors'][] = [
                        'ledger' => $ledger,
                        'row' => null,
                        'message' => "Sheet {$definition['sheet']} tidak ditemukan.",
                    ];
                    continue;
                }

                $headerError = $this->headerError($sheet, $ledger);
                if ($headerError !== null) {
                    $result['errors'][] = [
                        'ledger' => $ledger,
                        'row' => self::HEADER_ROW,
                        'message' => $headerError,
                    ];
                    continue;
                }

                $this->parseSheet($sheet, $ledger, $result, $dateOverrides);
            }
        } finally {
            $workbook->disconnectWorksheets();
        }

        return $result;
    }

    public function buildTemplate(array $customers = []): Spreadsheet
    {
        $workbook = new Spreadsheet();
        $workbook->removeSheetByIndex(0);

        foreach (array_keys(self::DEFINITIONS) as $ledger) {
            $sheet = new Worksheet($workbook, self::DEFINITIONS[$ledger]['sheet']);
            $workbook->addSheet($sheet);
            $this->prepareSheet($sheet, $ledger, $customers);
            $this->writeFormula($sheet, $ledger, self::DATA_ROW + 1);
        }

        $workbook->setActiveSheetIndex(0);
        $this->setProperties($workbook, 'Template Import Stok Solar');

        return $workbook;
    }

    public function buildExport(array $rows, array $opening, array $customers = []): Spreadsheet
    {
        $workbook = $this->buildTemplate($customers);

        foreach (array_keys(self::DEFINITIONS) as $ledger) {
            $sheet = $workbook->getSheetByName(self::DEFINITIONS[$ledger]['sheet']);
            $rowNumber = self::DATA_ROW;
            $ledgerOpening = $opening[$ledger] ?? [];
            $firstDate = $rows[$ledger][0]->tanggal ?? now()->format('Y-m-d');
            $this->writeOpeningRow($sheet, $ledger, $rowNumber++, (string) $firstDate, $ledgerOpening);

            foreach ($rows[$ledger] ?? [] as $record) {
                $this->writeDataRow($sheet, $ledger, $rowNumber, (array) $record);
                $rowNumber++;
            }

            $lastColumn = $sheet->getHighestDataColumn();
            $lastRow = max(self::DATA_ROW, $rowNumber - 1);
            $sheet->getStyle("A".self::DATA_ROW.":{$lastColumn}{$lastRow}")
                ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFD9E2F3'));
        }

        $this->setProperties($workbook, 'Export Stok Solar');

        return $workbook;
    }

    public function buildReconciliation(array $result): Spreadsheet
    {
        $workbook = new Spreadsheet();
        $summary = $workbook->getActiveSheet();
        $summary->setTitle('RINGKASAN');
        $summary->fromArray([
            ['HASIL CROSS-CHECK STOK SOLAR'],
            [],
            ['STATUS', 'JUMLAH'],
            ['Cocok', $result['summary']['match'] ?? 0],
            ['Hanya di Excel', $result['summary']['only_excel'] ?? 0],
            ['Hanya di Aplikasi', $result['summary']['only_app'] ?? 0],
            ['Berbeda', $result['summary']['different'] ?? 0],
            ['Error', $result['summary']['error'] ?? 0],
        ]);
        $summary->mergeCells('A1:B1');
        $summary->getStyle('A1:B1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
        $summary->getStyle('A1:B1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F4E78');
        $summary->getStyle('A3:B8')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $summary->getColumnDimension('A')->setWidth(24);
        $summary->getColumnDimension('B')->setWidth(14);

        foreach (SolarLedgerService::LEDGERS as $ledger) {
            $sheet = new Worksheet($workbook, strtoupper($ledger));
            $workbook->addSheet($sheet);
            $sheet->fromArray([['STATUS', 'BARIS EXCEL', 'TANGGAL', 'METODE', 'PERBEDAAN']], null, 'A1');
            $rowNumber = 2;
            foreach ($result['items'] ?? [] as $item) {
                if (($item['ledger'] ?? '') !== $ledger) {
                    continue;
                }
                $sheet->fromArray([[
                    $this->statusLabel($item['status']),
                    $item['row'],
                    $item['date'],
                    $item['balance_only'] ?? false ? 'Baris cocok, saldo berbeda' : ($item['matched_by'] ?? ''),
                    implode('; ', $item['differences'] ?? []),
                ]], null, "A{$rowNumber}");
                $rowNumber++;
            }
            $sheet->getStyle('A1:E1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle('A1:E1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
            $sheet->setAutoFilter('A1:E'.max(2, $rowNumber - 1));
            $sheet->freezePane('A2');
            $sheet->getColumnDimension('A')->setWidth(22);
            $sheet->getColumnDimension('B')->setWidth(14);
            $sheet->getColumnDimension('C')->setWidth(15);
            $sheet->getColumnDimension('D')->setWidth(24);
            $sheet->getColumnDimension('E')->setWidth(80);
            $sheet->getStyle('E2:E'.max(2, $rowNumber - 1))->getAlignment()->setWrapText(true);
        }

        $workbook->setActiveSheetIndex(0);
        $this->setProperties($workbook, 'Hasil Cross-check Stok Solar');

        return $workbook;
    }

    private function parseSheet(Worksheet $sheet, string $ledger, array &$result, array $dateOverrides = []): void
    {
        $firstTransactionFound = false;
        $highestRow = $sheet->getHighestDataRow();

        for ($rowNumber = self::DATA_ROW; $rowNumber <= $highestRow; $rowNumber++) {
            $overrideKey = $ledger.':'.$rowNumber;
            $hasDateOverride = array_key_exists($overrideKey, $dateOverrides);
            $rawCellDate = $sheet->getCell("A{$rowNumber}")->getValue();
            try {
                $values = $this->rowValues(
                    $sheet,
                    $ledger,
                    $rowNumber,
                    $hasDateOverride ? $dateOverrides[$overrideKey] : null
                );
            } catch (InvalidArgumentException $exception) {
                $result['errors'][] = [
                    'ledger' => $ledger,
                    'row' => $rowNumber,
                    'raw_date' => $rawCellDate,
                    'message' => $exception->getMessage(),
                ];
                continue;
            }
            if ($this->isEmptyRow($values)) {
                continue;
            }
            $rawDate = $rawCellDate;
            if ($values['tanggal'] === null || trim((string) $values['tanggal']) === '') {
                if ($this->hasMeaningfulTransaction($ledger, $values)) {
                    $result['errors'][] = [
                        'ledger' => $ledger,
                        'row' => $rowNumber,
                        'raw_date' => $rawDate,
                        'message' => 'Tanggal wajib diisi untuk baris yang memiliki transaksi atau keterangan.',
                    ];
                }
                continue;
            }

            try {
                $values['tanggal'] = $this->parseDate($values['tanggal']);
                $values['source_sheet'] = trim($sheet->getTitle());
                $values['source_row'] = $rowNumber;
                $this->validateNumbers($ledger, $values);

                if (! $firstTransactionFound && $this->isOpeningRow($ledger, $values)) {
                    $result['opening'][$ledger] = $ledger === 'stock'
                        ? [
                            'date' => $values['tanggal'],
                            'actual' => (float) $values['excel_stock_actual'],
                            'meter' => (float) $values['excel_stock_meteran'],
                            'source_row' => $rowNumber,
                        ]
                        : [
                            'date' => $values['tanggal'],
                            'quantity' => (float) $values['excel_current_stock'],
                            'source_row' => $rowNumber,
                        ];
                    $firstTransactionFound = true;
                    continue;
                }

                $firstTransactionFound = true;
                if (! $this->hasMeaningfulTransaction($ledger, $values)) {
                    continue;
                }
                $result['rows'][$ledger][] = $values;
            } catch (InvalidArgumentException $exception) {
                $result['errors'][] = [
                    'ledger' => $ledger,
                    'row' => $rowNumber,
                    'raw_date' => $rawDate,
                    'message' => $exception->getMessage(),
                ];
            }
        }
    }

    private function rowValues(Worksheet $sheet, string $ledger, int $row, mixed $dateOverride = null): array
    {
        $tanggal = $dateOverride !== null
            ? $dateOverride
            : $sheet->getCell("A{$row}")->getValue();
        if ($ledger === 'stock') {
            return [
                'tanggal' => $tanggal,
                'stock_in' => $this->number($sheet->getCell("B{$row}")->getCalculatedValue()),
                'out_actual' => $this->number($sheet->getCell("C{$row}")->getCalculatedValue()),
                'excel_stock_actual' => $this->number($sheet->getCell("D{$row}")->getCalculatedValue(), true),
                'out_meteran' => $this->number($sheet->getCell("E{$row}")->getCalculatedValue()),
                'excel_stock_meteran' => $this->number($sheet->getCell("F{$row}")->getCalculatedValue(), true),
                'keterangan' => $this->text($sheet->getCell("G{$row}")->getValue()),
            ];
        }

        $base = [
            'tanggal' => $tanggal,
            'qty_in' => $this->number($sheet->getCell("B{$row}")->getCalculatedValue()),
            'qty_out' => $this->number($sheet->getCell("C{$row}")->getCalculatedValue()),
            'excel_current_stock' => $this->number($sheet->getCell("D{$row}")->getCalculatedValue(), true),
            'keterangan' => $this->text($sheet->getCell("E{$row}")->getValue()),
        ];

        if ($ledger === 'pool') {
            return $base + [
                'unit' => $this->text($sheet->getCell("F{$row}")->getValue()),
                'operator' => $this->text($sheet->getCell("G{$row}")->getValue()),
                'customer_ref' => $this->text($sheet->getCell("H{$row}")->getValue()),
            ];
        }

        return $base + [
            'nama_kapal' => $this->text($sheet->getCell("F{$row}")->getValue()),
            'unit' => $this->text($sheet->getCell("G{$row}")->getValue()),
            'operator' => $this->text($sheet->getCell("H{$row}")->getValue()),
            'customer_ref' => $this->text($sheet->getCell("I{$row}")->getValue()),
            'lokasi' => $this->text($sheet->getCell("J{$row}")->getValue()),
        ];
    }

    private function prepareSheet(Worksheet $sheet, string $ledger, array $customers): void
    {
        $definition = self::DEFINITIONS[$ledger];
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($definition['headers']));
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->setCellValue('A2', $definition['title']);
        $sheet->getStyle("A2:{$lastColumn}2")->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A2:{$lastColumn}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F4E78');
        $sheet->getStyle("A2:{$lastColumn}2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach (array_values($definition['headers']) as $index => $description) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $header = array_keys($definition['headers'])[$index];
            $sheet->setCellValue("{$column}".self::HEADER_ROW, $header);
            $sheet->getComment("{$column}".self::HEADER_ROW)->getText()
                ->createTextRun($description."\nKolom kosong diperbolehkan bila ditandai opsional.");
        }

        $sheet->getStyle("A4:{$lastColumn}4")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A4:{$lastColumn}4")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
        $sheet->getStyle("A4:{$lastColumn}4")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A4:{$lastColumn}4")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A4:{$lastColumn}4");
        $sheet->getColumnDimension('A')->setWidth(15);
        foreach (range('B', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setWidth($column === $lastColumn ? 32 : 18);
        }
        $sheet->getStyle('A5:A1000')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->getStyle('B5:F1000')->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('A5:'.$lastColumn.'1000')->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        if ($customers !== [] && $ledger !== 'stock') {
            $sheet->getComment($ledger === 'mess' ? 'I4' : 'H4')
                ->getText()->createTextRun("\nReferensi tersedia: ".implode(', ', array_slice($customers, 0, 25)));
        }
    }

    private function writeOpeningRow(Worksheet $sheet, string $ledger, int $row, string $date, array $opening): void
    {
        $sheet->setCellValue("A{$row}", ExcelDate::PHPToExcel(Carbon::parse($date)));
        if ($ledger === 'stock') {
            $sheet->setCellValue("D{$row}", (float) ($opening['actual'] ?? 0));
            $sheet->setCellValue("F{$row}", (float) ($opening['meter'] ?? 0));
            $sheet->setCellValue("G{$row}", 'SALDO AWAL');
        } else {
            $sheet->setCellValue("D{$row}", (float) ($opening['quantity'] ?? 0));
            $sheet->setCellValue("E{$row}", 'SALDO AWAL');
        }
    }

    private function writeDataRow(Worksheet $sheet, string $ledger, int $row, array $record): void
    {
        $sheet->setCellValue("A{$row}", ExcelDate::PHPToExcel(Carbon::parse($record['tanggal'])));
        if ($ledger === 'stock') {
            $sheet->setCellValue("B{$row}", (float) ($record['stock_in'] ?? 0));
            $sheet->setCellValue("C{$row}", (float) ($record['out_actual'] ?? 0));
            $sheet->setCellValue("E{$row}", (float) ($record['out_meteran'] ?? 0));
            $sheet->setCellValueExplicit("G{$row}", (string) ($record['keterangan'] ?? ''), DataType::TYPE_STRING);
            $this->writeFormula($sheet, $ledger, $row);

            return;
        }

        $sheet->setCellValue("B{$row}", (float) ($record['qty_in'] ?? 0));
        $sheet->setCellValue("C{$row}", (float) ($record['qty_out'] ?? 0));
        $sheet->setCellValueExplicit("E{$row}", (string) ($record['keterangan'] ?? ''), DataType::TYPE_STRING);
        $this->writeFormula($sheet, $ledger, $row);
        $customer = $record['customer_id'] ?? null;
        $customerName = $record['customer_name'] ?? '';
        $customerRef = $customer ? "C{$customer} | {$customerName}" : '';

        if ($ledger === 'pool') {
            $sheet->setCellValueExplicit("F{$row}", (string) ($record['unit'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("G{$row}", (string) ($record['operator'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("H{$row}", $customerRef, DataType::TYPE_STRING);

            return;
        }

        $sheet->setCellValueExplicit("F{$row}", (string) ($record['nama_kapal'] ?? ''), DataType::TYPE_STRING);
        $sheet->setCellValueExplicit("G{$row}", (string) ($record['unit'] ?? ''), DataType::TYPE_STRING);
        $sheet->setCellValueExplicit("H{$row}", (string) ($record['operator'] ?? ''), DataType::TYPE_STRING);
        $sheet->setCellValueExplicit("I{$row}", $customerRef, DataType::TYPE_STRING);
        $sheet->setCellValueExplicit("J{$row}", (string) ($record['lokasi'] ?? ''), DataType::TYPE_STRING);
    }

    private function writeFormula(Worksheet $sheet, string $ledger, int $row): void
    {
        if ($row <= self::DATA_ROW) {
            return;
        }
        $previous = $row - 1;
        if ($ledger === 'stock') {
            $sheet->setCellValue("D{$row}", "=D{$previous}+B{$row}-C{$row}");
            $sheet->setCellValue("F{$row}", "=F{$previous}+B{$row}-E{$row}");
        } else {
            $sheet->setCellValue("D{$row}", "=D{$previous}+B{$row}-C{$row}");
        }
    }

    private function parseDate($value): string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->format('Y-m-d');
        }
        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->format('Y-m-d');
            } catch (\Throwable $exception) {
                throw new InvalidArgumentException('Tanggal serial Excel tidak valid.');
            }
        }

        $value = trim((string) $value);
        $candidateFormats = [];
        if (preg_match('/^\d{1,2}[\/.-]\d{1,2}[\/.-]\d{4}$/', $value)) {
            $separator = str_contains($value, '/') ? '/' : (str_contains($value, '.') ? '.' : '-');
            $candidateFormats = ["j{$separator}n{$separator}Y"];
        } elseif (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $value)) {
            $candidateFormats = ['Y-n-j'];
        } elseif (preg_match('/^\d{1,2}-[A-Za-z]{3,9}-(?:\d{2}|\d{4})$/', $value)) {
            $candidateFormats = ['j-M-y', 'j-M-Y', 'j-F-y', 'j-F-Y'];
        }

        foreach ($candidateFormats as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
                $errors = \DateTime::getLastErrors();
                if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    return $date->format('Y-m-d');
                }
            } catch (\Throwable $exception) {
                // Try the next explicit format.
            }
        }

        throw new InvalidArgumentException("Tanggal \"{$value}\" tidak valid. Gunakan DD/MM/YYYY.");
    }

    private function number($value, bool $nullable = false): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return $nullable ? null : 0.0;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = str_replace(' ', '', trim((string) $value));
        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
        }
        $normalized = str_replace(',', '.', $normalized);
        if (! is_numeric($normalized)) {
            throw new InvalidArgumentException("Nilai angka \"{$value}\" tidak valid.");
        }

        return (float) $normalized;
    }

    private function validateNumbers(string $ledger, array $values): void
    {
        $fields = $ledger === 'stock'
            ? ['stock_in', 'out_actual', 'out_meteran']
            : ['qty_in', 'qty_out'];
        foreach ($fields as $field) {
            if ((float) ($values[$field] ?? 0) < 0) {
                throw new InvalidArgumentException("Kolom {$field} tidak boleh negatif.");
            }
        }
    }

    private function hasMeaningfulTransaction(string $ledger, array $values): bool
    {
        $hasMovement = $ledger === 'stock'
            ? (float) $values['stock_in'] !== 0.0
                || (float) $values['out_actual'] !== 0.0
                || (float) $values['out_meteran'] !== 0.0
            : (float) $values['qty_in'] !== 0.0 || (float) $values['qty_out'] !== 0.0;
        if ($hasMovement) {
            return true;
        }

        $descriptiveFields = $ledger === 'stock'
            ? ['keterangan']
            : ['keterangan', 'unit', 'operator', 'customer_ref', 'nama_kapal', 'lokasi'];
        foreach ($descriptiveFields as $field) {
            if (trim((string) ($values[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function headerError(Worksheet $sheet, string $ledger): ?string
    {
        $expected = $ledger === 'stock'
            ? ['TANGGAL', 'IN', 'OUT ACT', 'STOCK ACT', 'OUT METERAN', 'STOCK METERAN', 'KETERANGAN']
            : ($ledger === 'pool'
                ? ['TANGGAL', 'IN', 'OUT', 'CURRENT', 'KETERANGAN']
                : ['TANGGAL', 'IN', 'OUT', 'CURRENT', 'KETERANGAN', 'NAMA KAPAL']);

        foreach ($expected as $index => $header) {
            $actual = strtoupper(trim(preg_replace(
                '/\s+/',
                ' ',
                (string) $sheet->getCellByColumnAndRow($index + 1, self::HEADER_ROW)->getValue()
            )));
            if ($actual !== $header) {
                $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);

                return "Header {$column}4 harus \"{$header}\", ditemukan \"{$actual}\".";
            }
        }

        return null;
    }

    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_numeric($value) || (float) $value !== 0.0) {
                return false;
            }
        }

        return true;
    }

    private function isOpeningRow(string $ledger, array $values): bool
    {
        if ($ledger === 'stock') {
            return (float) $values['stock_in'] === 0.0
                && (float) $values['out_actual'] === 0.0
                && (float) $values['out_meteran'] === 0.0
                && ($values['excel_stock_actual'] !== null || $values['excel_stock_meteran'] !== null);
        }

        return (float) $values['qty_in'] === 0.0
            && (float) $values['qty_out'] === 0.0
            && $values['excel_current_stock'] !== null;
    }

    private function findSheet(Spreadsheet $workbook, string $expected): ?Worksheet
    {
        foreach ($workbook->getWorksheetIterator() as $sheet) {
            if (Str::upper(trim($sheet->getTitle())) === Str::upper(trim($expected))) {
                return $sheet;
            }
        }

        return null;
    }

    private function text($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function setProperties(Spreadsheet $workbook, string $title): void
    {
        $creator = \Illuminate\Container\Container::getInstance()->bound('config')
            ? config('app.name', 'Ultimate ERP')
            : 'Ultimate ERP';
        $workbook->getProperties()
            ->setCreator($creator)
            ->setTitle($title)
            ->setDescription('Workbook Solar yang kompatibel untuk import, export, dan cross-check.');
    }

    private function statusLabel(string $status): string
    {
        return [
            'match' => 'Cocok',
            'only_excel' => 'Hanya di Excel',
            'only_app' => 'Hanya di Aplikasi',
            'different' => 'Berbeda',
            'error' => 'Error',
        ][$status] ?? $status;
    }
}
