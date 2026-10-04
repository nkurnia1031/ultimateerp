<?php

namespace App\Services;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
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
                'UNIT / ASSET' => 'Nama Asset, kode unit, atau nomor polisi. Pada preview dapat dipetakan ke Asset terdaftar. Opsional.',
                'OPERATOR' => 'Nama supir/operator. Pada preview dapat dipetakan ke pengguna business ini. Opsional.',
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
                'UNIT / ASSET' => 'Nama Asset, kode unit, atau nomor polisi. Pada preview dapat dipetakan ke Asset terdaftar. Opsional.',
                'OPERATOR' => 'Nama supir/operator. Pada preview dapat dipetakan ke pengguna business ini. Opsional.',
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
                'UNIT / ASSET' => 'Nama Asset, kode unit, atau nomor polisi. Pada preview dapat dipetakan ke Asset terdaftar. Opsional.',
                'OPERATOR' => 'Nama supir/operator. Pada preview dapat dipetakan ke pengguna business ini. Opsional.',
                'CUSTOMER' => 'Gunakan referensi C{id} | Nama Customer. Opsional.',
                'LOKASI' => 'Lokasi penyerahan atau pemakaian. Opsional.',
            ],
        ],
    ];

    public function parse(string $path, array $dateOverrides = [], ?callable $progress = null): array
    {
        $emit = static function (string $stage, string $message, int $percent, ?int $current = null, ?int $total = null, array $context = []) use ($progress): void {
            if ($progress) {
                $progress($stage, $message, $percent, $current, $total, $context);
            }
        };

        $emit('reading', 'Memeriksa struktur file dan membaca nama sheet workbook.', 12);
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheetNames = $reader->listWorksheetNames($path);
        $normalizedNames = [];
        foreach ($sheetNames as $sheetName) {
            $normalizedNames[$sheetName] = $this->normalizeSheetName($sheetName);
        }

        $selectedNames = [];
        $typoCandidates = [];
        $ignoredSheets = [];
        foreach (self::DEFINITIONS as $ledger => $definition) {
            $expected = $definition['sheet'];
            $matches = array_keys(array_filter($normalizedNames, fn ($normalized) => $normalized === $expected));
            if ($matches !== []) {
                $selectedNames[$ledger] = $matches[0];
                foreach (array_slice($matches, 1) as $duplicate) {
                    $ignoredSheets[] = $duplicate;
                }
                continue;
            }

            $near = [];
            foreach ($normalizedNames as $original => $normalized) {
                if ($normalized !== '' && levenshtein($normalized, $expected) <= 2) {
                    $near[] = ['sheet' => $original, 'distance' => levenshtein($normalized, $expected)];
                }
            }
            usort($near, fn ($left, $right) => $left['distance'] <=> $right['distance']);
            if ($near !== []) {
                $typoCandidates[$ledger] = $near;
            }
        }

        $transactionWorkbook = $selectedNames !== [] || $typoCandidates !== [];
        $loadNames = array_values($selectedNames);
        if ($transactionWorkbook && $loadNames === []) {
            foreach ($typoCandidates as $candidates) {
                foreach ($candidates as $candidate) {
                    $loadNames[] = $candidate['sheet'];
                }
            }
            $loadNames = array_values(array_unique($loadNames));
        }
        if ($transactionWorkbook) {
            foreach ($sheetNames as $sheetName) {
                if (! in_array($sheetName, $loadNames, true)) {
                    $ignoredSheets[] = $sheetName;
                }
            }
            $ignoredSheets = array_values(array_unique($ignoredSheets));
            $reader->setLoadSheetsOnly($loadNames);
            $dateScanNames = array_values($selectedNames);
        } else {
            // Monthly equipment-usage workbooks deliberately keep their own parser path and read every month sheet.
            $dateScanNames = [];
        }

        $warnings = [];
        if ($transactionWorkbook && $ignoredSheets !== []) {
            $warnings[] = [
                'type' => 'ignored_sheets',
                'blocking' => false,
                'sheets' => $ignoredSheets,
                'message' => 'Workbook transaksi Solar hanya membaca satu sheet pertama untuk SOLAR, POOL, dan MESS. Sheet lainnya diabaikan: '.implode(', ', $ignoredSheets).'. Periksa ejaan nama tab sebelum melanjutkan.',
            ];
        }
        if ($transactionWorkbook) {
            foreach (self::DEFINITIONS as $ledger => $definition) {
                if (isset($selectedNames[$ledger])) {
                    continue;
                }
                $expected = $definition['sheet'];
                $near = $typoCandidates[$ledger] ?? [];
                if ($near !== []) {
                    $names = array_column($near, 'sheet');
                    $warnings[] = [
                        'type' => 'near_sheet_name',
                        'blocking' => true,
                        'expected' => $expected,
                        'found' => $names,
                        'message' => 'Sheet wajib '.$expected.' tidak ditemukan. Nama yang mirip: '.implode(', ', $names).'. Perbaiki nama tab di Excel lalu unggah ulang.',
                    ];
                } else {
                    $warnings[] = [
                        'type' => 'missing_sheet',
                        'blocking' => false,
                        'expected' => $expected,
                        'found' => [],
                        'message' => 'Sheet '.$expected.' tidak ditemukan. Ledger lain yang tersedia tetap dapat dipreview dan diimpor.',
                    ];
                }
            }
        }

        $emit('reading', $transactionWorkbook
            ? 'Membaca format tanggal pada '.count($dateScanNames).' sheet ledger yang dipilih.'
            : 'Workbook bukan format ledger; memeriksa sheet pemakaian bulanan.', 15, null, null, ['sheets' => $dateScanNames]);
        $monthFirstDateRows = $dateScanNames === [] ? [] : $this->monthFirstDateRows($path, $dateScanNames, $emit);

        $emit('reading', $transactionWorkbook
            ? 'Membuka workbook dan memuat sheet ledger terpilih: '.implode(', ', $loadNames).'.'
            : 'Membuka workbook pemakaian dan menyiapkan sheet bulanan.', 22, null, null, ['sheets' => $transactionWorkbook ? $loadNames : $sheetNames]);
        $workbook = $reader->load($path);
        $result = [
            'rows' => ['stock' => [], 'pool' => [], 'mess' => [], 'usage' => []],
            'opening' => [],
            'errors' => [],
            'warnings' => $warnings,
            'sheets' => [],
        ];

        try {
            $usageSheetNames = [];
            $selectedCount = max(1, count($selectedNames));
            $ledgerIndex = 0;
            $rowTotals = [];
            foreach (self::DEFINITIONS as $ledger => $definition) {
                $sheetName = $selectedNames[$ledger] ?? null;
                $sheet = $sheetName ? $workbook->getSheetByName($sheetName) : null;
                if (! $sheet) {
                    continue;
                }
                $ledgerIndex++;
                $result['sheets'][] = ['sheet_key' => trim($sheet->getTitle()), 'title' => trim($sheet->getTitle()), 'ledger' => $ledger];
                $headerError = $this->headerError($sheet, $ledger);
                if ($headerError !== null) {
                    $result['errors'][] = ['ledger' => $ledger, 'row' => self::HEADER_ROW, 'message' => $headerError];
                    continue;
                }
                $rowTotals[$ledger] = max(0, $sheet->getHighestDataRow() - self::DATA_ROW + 1);
            }

            if ($transactionWorkbook) {
                $totalRows = max(1, array_sum($rowTotals));
                $processedRows = 0;
                foreach (self::DEFINITIONS as $ledger => $definition) {
                    $sheetName = $selectedNames[$ledger] ?? null;
                    $sheet = $sheetName ? $workbook->getSheetByName($sheetName) : null;
                    if (! $sheet) {
                        continue;
                    }
                    $headerError = $this->headerError($sheet, $ledger);
                    if ($headerError !== null) {
                        continue;
                    }
                    $sheetRows = $rowTotals[$ledger] ?? 0;
                    $emit('reading', 'Membaca baris transaksi sheet '.$sheet->getTitle().'.', 25 + (int) floor(($processedRows / $totalRows) * 25), $processedRows, $totalRows, ['sheet' => $sheet->getTitle(), 'row' => self::DATA_ROW, 'row_total' => $sheet->getHighestDataRow(), 'issues' => count($result['errors'])]);
                    $lastSheetProcessed = 0;
                    $this->parseSheet($sheet, $ledger, $result, $dateOverrides, $monthFirstDateRows[$sheet->getTitle()] ?? [], function ($rowNumber, $sheetProcessed) use (&$processedRows, &$lastSheetProcessed, $sheetRows, $totalRows, $ledger, $sheet, &$result, $emit): void {
                        $processedRows = min($totalRows, $processedRows + max(0, $sheetProcessed - $lastSheetProcessed));
                        $lastSheetProcessed = $sheetProcessed;
                        $emit('reading', 'Membaca baris '.$rowNumber.' dari '.$sheet->getHighestDataRow().' pada sheet '.$sheet->getTitle().'; '.count($result['rows'][$ledger]).' transaksi siap, '.count($result['errors']).' baris bermasalah.', 25 + (int) floor(($processedRows / $totalRows) * 25), $processedRows, $totalRows, ['sheet' => $sheet->getTitle(), 'row' => $rowNumber, 'row_total' => $sheet->getHighestDataRow(), 'issues' => count($result['errors'])]);
                    });
                    $processedRows = min($totalRows, $processedRows + max(0, $sheetRows - $lastSheetProcessed));
                    $emit('reading', 'Sheet '.$sheet->getTitle().' selesai dibaca; '.count($result['rows'][$ledger]).' transaksi siap.', 25 + (int) floor(($processedRows / $totalRows) * 25), min($totalRows, $processedRows), $totalRows, ['sheet' => $sheet->getTitle(), 'row' => $sheet->getHighestDataRow(), 'row_total' => $sheet->getHighestDataRow(), 'issues' => count($result['errors'])]);
                }
            } else {
                foreach ($workbook->getWorksheetIterator() as $sheet) {
                    if ($this->isUsageSheet($sheet)) {
                        $usageSheetNames[] = $sheet->getTitle();
                    }
                }
                $usageSheetCount = max(1, count($usageSheetNames));
                foreach ($usageSheetNames as $index => $sheetName) {
                    $sheet = $workbook->getSheetByName($sheetName);
                    if (! $sheet) continue;
                    $result['sheets'][] = ['sheet_key' => trim($sheet->getTitle()), 'title' => trim($sheet->getTitle()), 'ledger' => 'usage'];
                    $emit('reading', 'Membaca matriks pemakaian sheet '.$sheet->getTitle().' ('.($index + 1).' dari '.count($usageSheetNames).').', 25 + (int) floor(($index / $usageSheetCount) * 25), 0, max(0, $sheet->getHighestDataRow() - 5), ['sheet' => $sheet->getTitle(), 'row' => 5, 'row_total' => $sheet->getHighestDataRow()]);
                    $this->parseUsageSheet($sheet, $result, function ($rowNumber, $rowTotal) use ($index, $usageSheetCount, $sheet, $emit, &$result): void {
                        $emit('reading', 'Membaca alat sampai baris '.$rowNumber.' pada sheet '.$sheet->getTitle().'; '.count($result['rows']['usage']).' pemakaian ditemukan.', 25 + (int) floor((($index + ($rowTotal ? $rowNumber / $rowTotal : 1)) / $usageSheetCount) * 25), $rowNumber, $rowTotal, ['sheet' => $sheet->getTitle(), 'row' => $rowNumber, 'row_total' => $rowTotal, 'issues' => count($result['errors'])]);
                    });
                }
                if ($usageSheetNames === []) {
                    $result['errors'][] = ['ledger' => 'workbook', 'row' => null, 'message' => 'Workbook tidak berisi sheet transaksi Solar atau matriks pemakaian alat yang dikenali.'];
                }
            }

            if ($transactionWorkbook) {
                foreach ($typoCandidates as $ledger => $candidates) {
                    if (! isset($selectedNames[$ledger])) {
                        $result['errors'][] = ['ledger' => $ledger, 'row' => null, 'message' => 'Sheet wajib tidak ditemukan; nama sheet mirip harus diperbaiki sebelum import.'];
                    }
                }
            }

            $selectedActualNames = array_values($selectedNames);
            if (! $transactionWorkbook) {
                $selectedActualNames = $usageSheetNames;
            }
            $result['ignored_sheets'] = $transactionWorkbook
                ? $ignoredSheets
                : array_values(array_diff($sheetNames, $selectedActualNames));
        } finally {
            $workbook->disconnectWorksheets();
        }

        return $result;
    }

    private function normalizeSheetName(string $name): string
    {
        return preg_replace('/\s+/u', '', mb_strtoupper(trim($name))) ?? mb_strtoupper(trim($name));
    }

    private function isUsageSheet(Worksheet $sheet): bool
    {
        $read = fn (string $cell) => mb_strtolower(trim((string) $sheet->getCell($cell)->getValue()));

        return $read('A4') === 'no'
            && in_array($read('B4'), ['alat berat', 'alat'], true)
            && $read('C4') === 'tanggal'
            && in_array($read('AH4'), ['jumlah', 'jumlah (gln)'], true)
            && in_array($read('AI4'), ['l', 'liter', 'liter (gln × 30)', 'liter (gln x 30)'], true);
    }

    private function parseUsageSheet(Worksheet $sheet, array &$result, ?callable $progress = null): void
    {
        $dates = [];
        for ($columnIndex = 3; $columnIndex <= 33; $columnIndex++) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
            $rawDate = $sheet->getCell("{$column}5")->getValue();
            if ($rawDate === null || trim((string) $rawDate) === '') {
                continue;
            }
            try {
                $dates[$column] = $this->parseDate($rawDate);
            } catch (InvalidArgumentException $exception) {
                $dates[$column] = null;
                $result['errors'][] = [
                    'ledger' => 'usage',
                    'source_sheet' => trim($sheet->getTitle()),
                    'row' => 5,
                    'source_column' => $column,
                    'raw_date' => $rawDate,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $highestRow = $sheet->getHighestDataRow();
        for ($rowNumber = 6; $rowNumber <= $highestRow; $rowNumber++) {
            if ($progress && (($rowNumber - 5) % 25 === 0 || $rowNumber === $highestRow)) {
                $progress($rowNumber, $highestRow);
            }
            $equipmentName = trim((string) $sheet->getCell("B{$rowNumber}")->getValue());
            if ($equipmentName === '' || preg_match('/^(total|jumlah|grand total|total pemakaian)$/i', $equipmentName)) {
                continue;
            }

            for ($columnIndex = 3; $columnIndex <= 33; $columnIndex++) {
                $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
                $rawValue = $sheet->getCell("{$column}{$rowNumber}")->getCalculatedValue();
                if ($rawValue === null || trim((string) $rawValue) === '') {
                    continue;
                }
                try {
                    $quantity = $this->number($rawValue);
                    if ($quantity === 0.0) {
                        continue;
                    }
                    if ($quantity < 0) {
                        throw new InvalidArgumentException('Pemakaian tidak boleh negatif.');
                    }
                    $usageDate = $dates[$column] ?? null;
                    if ($usageDate === null) {
                        throw new InvalidArgumentException('Header tanggal untuk kolom '.$column.' tidak valid atau kosong.');
                    }
                    $result['rows']['usage'][] = [
                        'tanggal' => $usageDate,
                        'equipment_name' => $equipmentName,
                        'asset_id' => null,
                        'quantity_gln' => $quantity,
                        'source_sheet' => trim($sheet->getTitle()),
                        'source_row' => $rowNumber,
                        'source_column' => $column,
                        'excel_total_gln' => $this->number($sheet->getCell("AH{$rowNumber}")->getCalculatedValue(), true),
                        'excel_total_liter' => $this->number($sheet->getCell("AI{$rowNumber}")->getCalculatedValue(), true),
                    ];
                } catch (InvalidArgumentException $exception) {
                    $result['errors'][] = [
                        'ledger' => 'usage',
                        'source_sheet' => trim($sheet->getTitle()),
                        'row' => $rowNumber,
                        'source_column' => $column,
                        'equipment_name' => $equipmentName,
                        'tanggal' => $dates[$column] ?? null,
                        'quantity_gln' => is_numeric($rawValue) ? (float) $rawValue : null,
                        'raw_value' => is_scalar($rawValue) ? (string) $rawValue : null,
                        'raw_date' => $sheet->getCell("{$column}5")->getValue(),
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        }
    }

    private function writeUsageFormula(Worksheet $sheet, int $row): void
    {
        $sheet->setCellValue("AH{$row}", "=SUM(C{$row}:AG{$row})");
        $sheet->setCellValue("AI{$row}", "=AH{$row}*30");
    }

    private function styleUsageSheet(Worksheet $sheet, int $lastRow): void
    {
        $sheet->getStyle('A2:AI2')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A2:AI2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F4E78');
        $sheet->getStyle('A2:AI2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A4:AI4')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A4:AI4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
        $sheet->getStyle('A4:AI5')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A6:AI{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFD9E2F3'));
        $sheet->getStyle("C6:AI{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("AH6:AI{$lastRow}")->getFont()->setBold(true);
        $sheet->freezePane('C6');
        $sheet->setAutoFilter("A4:AI{$lastRow}");
        $sheet->getColumnDimension('A')->setWidth(7);
        $sheet->getColumnDimension('B')->setWidth(24);
        for ($columnIndex = 3; $columnIndex <= 33; $columnIndex++) {
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex))->setWidth(9);
        }
        $sheet->getColumnDimension('AH')->setWidth(15);
        $sheet->getColumnDimension('AI')->setWidth(18);
    }

    public function buildUsageTemplate(string $month, array $equipmentNames = []): Spreadsheet
    {
        $date = Carbon::createFromFormat('!Y-m', $month);
        if (! $date) {
            throw new InvalidArgumentException('Bulan template tidak valid.');
        }

        $workbook = new Spreadsheet();
        $sheet = $workbook->getActiveSheet();
        $sheet->setTitle($date->locale('id')->translatedFormat('F Y'));
        $sheet->mergeCells('A2:AI2');
        $sheet->setCellValue('A2', 'DATA PEMAKAIAN SOLAR STOKPILE - '.$date->locale('id')->translatedFormat('F Y'));
        $sheet->mergeCells('C4:AG4');
        $sheet->setCellValue('A4', 'No');
        $sheet->setCellValue('B4', 'Alat Berat');
        $sheet->setCellValue('C4', 'Tanggal');
        $sheet->setCellValue('AH4', 'JUMLAH (Gln)');
        $sheet->setCellValue('AI4', 'LITER (Gln × 30)');
        $lastDay = (int) $date->copy()->endOfMonth()->format('j');
        for ($day = 1; $day <= 31; $day++) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($day + 2);
            if ($day <= $lastDay) {
                $dayDate = $date->copy()->day($day);
                $sheet->setCellValue("{$column}5", ExcelDate::PHPToExcel($dayDate));
                $sheet->getStyle("{$column}5")->getNumberFormat()->setFormatCode('d/m');
            }
        }

        $equipmentNames = array_values(array_unique(array_filter(array_map('trim', $equipmentNames))));
        $row = 6;
        foreach ($equipmentNames as $name) {
            $sheet->setCellValue("A{$row}", $row - 5);
            $sheet->setCellValueExplicit("B{$row}", $name, DataType::TYPE_STRING);
            $this->writeUsageFormula($sheet, $row);
            $row++;
        }
        if ($row === 6) {
            $sheet->setCellValue('A6', 1);
            $sheet->setCellValue('B6', 'Isi nama alat di sini');
            $this->writeUsageFormula($sheet, 6);
        }

        $this->styleUsageSheet($sheet, max(6, $row - 1));
        $sheet->getComment('AH4')->getText()->createTextRun('Jumlah seluruh pemakaian harian dalam Gln. Dihitung otomatis oleh Excel; aplikasi menghitung ulang saat import.');
        $sheet->getComment('AI4')->getText()->createTextRun('Konversi liter mengikuti referensi: jumlah Gln × 30. Aplikasi menghitung ulang saat import.');
        $workbook->setActiveSheetIndex(0);
        $this->setProperties($workbook, 'Template Pemakaian Solar Alat Berat');

        return $workbook;
    }

    public function buildUsageExport(string $month, array $rows): Spreadsheet
    {
        $equipmentNames = array_values(array_unique(array_map(fn ($row) => trim((string) $row->equipment_name), $rows)));
        $workbook = $this->buildUsageTemplate($month, $equipmentNames);
        $sheet = $workbook->getSheet(0);
        $date = Carbon::createFromFormat('!Y-m', $month);
        $byNameDate = [];
        foreach ($rows as $record) {
            $byNameDate[mb_strtolower(trim((string) $record->equipment_name))][$record->usage_date] = (float) $record->quantity_gln;
        }
        $rowNumber = 6;
        foreach ($equipmentNames as $index => $name) {
            $sheet->setCellValue("A{$rowNumber}", $index + 1);
            $sheet->setCellValueExplicit("B{$rowNumber}", $name, DataType::TYPE_STRING);
            for ($day = 1; $day <= 31; $day++) {
                if ($day > (int) $date->copy()->endOfMonth()->format('j')) {
                    continue;
                }
                $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($day + 2);
                $usageDate = $date->copy()->day($day)->format('Y-m-d');
                $value = $byNameDate[mb_strtolower($name)][$usageDate] ?? null;
                if ($value !== null) {
                    $sheet->setCellValue("{$column}{$rowNumber}", $value);
                }
            }
            $this->writeUsageFormula($sheet, $rowNumber);
            $rowNumber++;
        }
        $this->styleUsageSheet($sheet, max(6, $rowNumber - 1));
        $this->setProperties($workbook, 'Export Pemakaian Solar Alat Berat');

        return $workbook;
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

    private function parseSheet(Worksheet $sheet, string $ledger, array &$result, array $dateOverrides = [], array $monthFirstDateRows = [], ?callable $progress = null): void
    {
        $firstTransactionFound = false;
        $dayFirstTextSeen = false;
        $highestRow = $sheet->getHighestDataRow();

        for ($rowNumber = self::DATA_ROW; $rowNumber <= $highestRow; $rowNumber++) {
            $processed = $rowNumber - self::DATA_ROW + 1;
            if ($progress && ($processed % 100 === 0 || $rowNumber === $highestRow)) {
                $progress($rowNumber, $processed, max(0, $highestRow - self::DATA_ROW + 1));
            }
            $overrideKey = $ledger.':'.$rowNumber;
            $hasDateOverride = array_key_exists($overrideKey, $dateOverrides);
            $dateCell = $sheet->getCell("A{$rowNumber}");
            $rawCellDate = $dateCell->getValue();
            $hasDayFirstTextDate = $this->isUnambiguousDayFirstTextDate($rawCellDate);
            $dayFirstTextSeen = $dayFirstTextSeen || $hasDayFirstTextDate;
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
                    'source_sheet' => trim($sheet->getTitle()),
                    'raw_date' => $rawCellDate,
                    'excel' => $this->rawRowValues($sheet, $ledger, $rowNumber, $rawCellDate),
                    'message' => $exception->getMessage(),
                ];
                continue;
            }
            if ($this->isEmptyRow($values)) {
                $dayFirstTextSeen = $dayFirstTextSeen || $hasDayFirstTextDate;
                continue;
            }
            $values['source_sheet'] = trim($sheet->getTitle());
            $values['source_row'] = $rowNumber;
            $rawDate = $rawCellDate;
            if ($values['tanggal'] === null || trim((string) $values['tanggal']) === '') {
                if ($this->hasMeaningfulTransaction($ledger, $values)) {
                    $result['errors'][] = [
                        'ledger' => $ledger,
                        'source_sheet' => trim($sheet->getTitle()),
                        'row' => $rowNumber,
                        'raw_date' => $rawDate,
                        'excel' => $values,
                        'message' => 'Tanggal wajib diisi untuk baris yang memiliki transaksi atau keterangan.',
                    ];
                }
                continue;
            }

            try {
                $values['tanggal'] = $hasDateOverride
                    ? $this->parseDate($values['tanggal'])
                    : $this->parseDateCell($dateCell, $dayFirstTextSeen, $monthFirstDateRows[$rowNumber] ?? false);
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
                    $dayFirstTextSeen = $dayFirstTextSeen || $hasDayFirstTextDate;
                    continue;
                }
                $result['rows'][$ledger][] = $values;
                $dayFirstTextSeen = $dayFirstTextSeen || $hasDayFirstTextDate;
            } catch (InvalidArgumentException $exception) {
                $result['errors'][] = [
                    'ledger' => $ledger,
                    'source_sheet' => trim($sheet->getTitle()),
                    'row' => $rowNumber,
                    'raw_date' => $rawDate,
                    'excel' => $values,
                    'message' => $exception->getMessage(),
                ];
                $dayFirstTextSeen = $dayFirstTextSeen || $hasDayFirstTextDate;
            }

        }
    }

    private function parseDateCell(Cell $cell, bool $dayFirstTextSeen, bool $monthFirstDateFormat): string
    {
        $value = $cell->getValue();
        if (! is_numeric($value) || ! $dayFirstTextSeen || ! $monthFirstDateFormat) {
            return $this->parseDate($value);
        }

        // A text DD/MM/YYYY run followed by MM/DD-formatted Excel cells is a
        // common locale-mixed workbook. Interpret the serial's displayed
        // month/day components using the sheet's established D/M order.
        try {
            $excelDate = ExcelDate::excelToDateTimeObject((float) $value);
            $year = (int) $excelDate->format('Y');
            $day = (int) $excelDate->format('m');
            $month = (int) $excelDate->format('d');
            if (! checkdate($month, $day, $year)) {
                return $this->parseDate($value);
            }

            return Carbon::createFromDate($year, $month, $day)->format('Y-m-d');
        } catch (InvalidArgumentException $exception) {
            // If the displayed month/day cannot be read as D/M (for example,
            // the day is greater than 12), the Excel serial remains reliable.
            return $this->parseDate($value);
        }
    }

    private function monthFirstDateRows(string $path, array $sheetNames = [], ?callable $progress = null): array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
            return [];
        }

        $archive = new \ZipArchive();
        if ($archive->open($path) !== true) {
            return [];
        }

        try {
            $workbookXml = simplexml_load_string((string) $archive->getFromName('xl/workbook.xml'));
            $relationshipsXml = simplexml_load_string((string) $archive->getFromName('xl/_rels/workbook.xml.rels'));
            $stylesXml = simplexml_load_string((string) $archive->getFromName('xl/styles.xml'));
            if (! $workbookXml || ! $relationshipsXml || ! $stylesXml) {
                return [];
            }

            $mainNamespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $relationshipsRoot = $relationshipsXml->children('http://schemas.openxmlformats.org/package/2006/relationships');
            $stylesRoot = $stylesXml->children($mainNamespace);
            $workbookRoot = $workbookXml->children($mainNamespace);
            $relationshipTargets = [];
            foreach ($relationshipsRoot->Relationship as $relationship) {
                $attributes = $relationship->attributes();
                $relationshipTargets[(string) $attributes['Id']] = (string) $attributes['Target'];
            }

            $customFormats = [];
            foreach ($stylesRoot->numFmts->numFmt ?? [] as $format) {
                $attributes = $format->attributes();
                $customFormats[(int) $attributes['numFmtId']] = (string) $attributes['formatCode'];
            }

            $builtinFormats = [
                14 => 'm/d/yy', 15 => 'd-mmm-yy', 16 => 'd-mmm', 17 => 'mmm-yy',
                18 => 'h:mm AM/PM', 19 => 'h:mm:ss AM/PM', 20 => 'h:mm', 21 => 'h:mm:ss',
                22 => 'm/d/yy h:mm', 27 => 'yyyy/m/d', 30 => 'm/d/yy', 36 => 'yyyy/m/d',
                45 => 'mm:ss', 46 => '[h]:mm:ss', 47 => 'mmss.0', 50 => 'yyyy/m/d', 57 => 'yyyy/m/d',
            ];
            $monthFirstStyles = [];
            $styleIndex = 0;
            foreach ($stylesRoot->cellXfs->xf ?? [] as $style) {
                $attributes = $style->attributes();
                $formatId = (int) ($attributes['numFmtId'] ?? 0);
                $formatCode = $customFormats[$formatId] ?? ($builtinFormats[$formatId] ?? '');
                $monthFirstStyles[$styleIndex] = $this->isMonthFirstDateFormat($formatCode);
                $styleIndex++;
            }

            $dateRows = [];
            $relationshipNamespace = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            $selectedNames = $sheetNames === [] ? null : array_fill_keys($sheetNames, true);
            foreach ($workbookRoot->sheets->sheet ?? [] as $sheet) {
                $sheetAttributes = $sheet->attributes();
                $relationshipAttributes = $sheet->attributes($relationshipNamespace);
                $sheetName = (string) $sheetAttributes['name'];
                if ($selectedNames !== null && ! isset($selectedNames[$sheetName])) {
                    continue;
                }
                $relationshipId = (string) $relationshipAttributes['id'];
                $target = $relationshipTargets[$relationshipId] ?? '';
                if ($target === '') {
                    continue;
                }

                $sheetPath = $this->normalizeZipPath($target);
                $sheetXml = simplexml_load_string((string) $archive->getFromName($sheetPath));
                if (! $sheetXml) {
                    continue;
                }

                $sheetRoot = $sheetXml->children($mainNamespace);
                $processedRows = 0;
                $rowTotal = count($sheetRoot->sheetData->row ?? []);
                foreach ($sheetRoot->sheetData->row ?? [] as $row) {
                    foreach ($row->c ?? [] as $cell) {
                        $attributes = $cell->attributes();
                        $coordinate = (string) ($attributes['r'] ?? '');
                        if (! preg_match('/^A(\d+)$/i', $coordinate, $matches)) {
                            continue;
                        }
                        $styleIndex = (int) ($attributes['s'] ?? 0);
                        if ($monthFirstStyles[$styleIndex] ?? false) {
                            $dateRows[$sheetName][(int) $matches[1]] = true;
                        }
                    }
                    $processedRows++;
                    if ($progress && ($processedRows % 500 === 0 || $processedRows === $rowTotal)) {
                        $progress('reading', 'Memeriksa format tanggal sheet '.$sheetName.' (baris XML '.$processedRows.' dari '.$rowTotal.').', 16, $processedRows, max(1, $rowTotal), ['sheet' => $sheetName, 'row' => $processedRows, 'row_total' => $rowTotal]);
                    }
                }
            }

            return $dateRows;
        } finally {
            $archive->close();
        }
    }

    private function normalizeZipPath(string $target): string
    {
        $path = str_starts_with($target, '/')
            ? ltrim($target, '/')
            : (str_starts_with($target, 'xl/') ? $target : 'xl/'.$target);
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private function isMonthFirstDateFormat(string $format): bool
    {
        $format = strtolower($format);
        $format = preg_replace('/"[^"]*"|\\\\.|\[[^\]]*\]/', '', $format) ?? $format;
        $format = explode(';', $format, 2)[0];

        return (bool) preg_match('/(?:^|[^a-z])m{1,2}\s*\/\s*d{1,2}(?:[^a-z]|$)/', $format);
    }

    private function isUnambiguousDayFirstTextDate($value): bool
    {
        if (! is_string($value) || ! preg_match('/^(\d{1,2})[\/. -](\d{1,2})[\/. -]\d{4}$/', trim($value), $matches)) {
            return false;
        }

        return (int) $matches[1] > 12 && (int) $matches[2] >= 1 && (int) $matches[2] <= 12;
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
                'unit' => $this->optionalTextCell($sheet, "H{$row}", ['UNIT', 'UNIT / ASSET', 'ASSET / UNIT', 'UNIT ASSET', 'ASSET', 'NAMA UNIT', 'NAMA ASSET', 'UNIT / ALAT', 'NAMA ALAT']),
                'operator' => $this->optionalTextCell($sheet, "I{$row}", ['OPERATOR', 'SUPIR', 'NAMA SUPIR', 'DRIVER', 'NAMA DRIVER']),
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
                'unit' => $this->optionalTextCell($sheet, "F{$row}", ['UNIT', 'UNIT / ASSET', 'ASSET / UNIT', 'UNIT ASSET', 'ASSET', 'NAMA UNIT', 'NAMA ASSET', 'UNIT / ALAT', 'NAMA ALAT']),
                'operator' => $this->optionalTextCell($sheet, "G{$row}", ['OPERATOR', 'SUPIR', 'NAMA SUPIR', 'DRIVER', 'NAMA DRIVER']),
                'customer_ref' => $this->optionalTextCell($sheet, "H{$row}", ['CUSTOMER', 'PELANGGAN']),
            ];
        }

        return $base + [
            'nama_kapal' => $this->text($sheet->getCell("F{$row}")->getValue()),
            'unit' => $this->optionalTextCell($sheet, "G{$row}", ['UNIT', 'UNIT / ASSET', 'ASSET / UNIT', 'UNIT ASSET', 'ASSET', 'NAMA UNIT', 'NAMA ASSET', 'UNIT / ALAT', 'NAMA ALAT']),
            'operator' => $this->optionalTextCell($sheet, "H{$row}", ['OPERATOR', 'SUPIR', 'NAMA SUPIR', 'DRIVER', 'NAMA DRIVER']),
            'customer_ref' => $this->optionalTextCell($sheet, "I{$row}", ['CUSTOMER', 'PELANGGAN']),
            'lokasi' => $this->optionalTextCell($sheet, "J{$row}", ['LOKASI', 'LOCATION']),
        ];
    }

    private function rawRowValues(Worksheet $sheet, string $ledger, int $row, mixed $tanggal): array
    {
        if ($ledger === 'stock') {
            return [
                'tanggal' => $tanggal,
                'stock_in' => $sheet->getCell("B{$row}")->getValue(),
                'out_actual' => $sheet->getCell("C{$row}")->getValue(),
                'out_meteran' => $sheet->getCell("E{$row}")->getValue(),
                'keterangan' => $this->text($sheet->getCell("G{$row}")->getValue()),
                'unit' => $this->optionalTextCell($sheet, "H{$row}", ['UNIT', 'UNIT / ASSET', 'ASSET / UNIT', 'UNIT ASSET', 'ASSET', 'NAMA UNIT', 'NAMA ASSET', 'UNIT / ALAT', 'NAMA ALAT']),
                'operator' => $this->optionalTextCell($sheet, "I{$row}", ['OPERATOR', 'SUPIR', 'NAMA SUPIR', 'DRIVER', 'NAMA DRIVER']),
                'source_sheet' => trim($sheet->getTitle()),
                'source_row' => $row,
            ];
        }

        $values = [
            'tanggal' => $tanggal,
            'qty_in' => $sheet->getCell("B{$row}")->getValue(),
            'qty_out' => $sheet->getCell("C{$row}")->getValue(),
            'keterangan' => $this->text($sheet->getCell("E{$row}")->getValue()),
            'source_sheet' => trim($sheet->getTitle()),
            'source_row' => $row,
        ];
        if ($ledger === 'pool') {
            return $values + [
                'unit' => $this->optionalTextCell($sheet, "F{$row}", ['UNIT', 'UNIT / ASSET', 'ASSET / UNIT', 'UNIT ASSET', 'ASSET', 'NAMA UNIT', 'NAMA ASSET', 'UNIT / ALAT', 'NAMA ALAT']),
                'operator' => $this->optionalTextCell($sheet, "G{$row}", ['OPERATOR', 'SUPIR', 'NAMA SUPIR', 'DRIVER', 'NAMA DRIVER']),
                'customer_ref' => $this->optionalTextCell($sheet, "H{$row}", ['CUSTOMER', 'PELANGGAN']),
            ];
        }

        return $values + [
            'nama_kapal' => $this->text($sheet->getCell("F{$row}")->getValue()),
            'unit' => $this->optionalTextCell($sheet, "G{$row}", ['UNIT', 'UNIT / ASSET', 'ASSET / UNIT', 'UNIT ASSET', 'ASSET', 'NAMA UNIT', 'NAMA ASSET', 'UNIT / ALAT', 'NAMA ALAT']),
            'operator' => $this->optionalTextCell($sheet, "H{$row}", ['OPERATOR', 'SUPIR', 'NAMA SUPIR', 'DRIVER', 'NAMA DRIVER']),
            'customer_ref' => $this->optionalTextCell($sheet, "I{$row}", ['CUSTOMER', 'PELANGGAN']),
            'lokasi' => $this->optionalTextCell($sheet, "J{$row}", ['LOKASI', 'LOCATION']),
        ];
    }

    private function optionalTextCell(Worksheet $sheet, string $cellAddress, array $acceptedHeaders): ?string
    {
        $column = preg_replace('/\d+/', '', $cellAddress);
        $header = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string) $sheet->getCell($column.self::HEADER_ROW)->getValue())));
        if (! in_array($header, $acceptedHeaders, true)) {
            return null;
        }

        return $this->text($sheet->getCell($cellAddress)->getValue());
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
            $sheet->setCellValueExplicit("H{$row}", (string) ($record['unit'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("I{$row}", (string) ($record['operator'] ?? ''), DataType::TYPE_STRING);
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
