<?php

namespace Modules\AssetManagement\Services;

use App\Contact;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\AssetManagement\Entities\HeavyEquipmentRoute;
use Modules\AssetManagement\Entities\HeavyEquipmentVendorLoading;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class HeavyEquipmentVendorLoadingImportService
{
    public function __construct(private HeavyEquipmentVendorLoadingService $loadingService)
    {
    }

    public function downloadTemplate()
    {
        $spreadsheet = $this->templateSpreadsheet();

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 'template_loading_vendor.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function preview(UploadedFile $file, int $businessId, bool $canCreateCustomer = true, ?callable $progress = null): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new \InvalidArgumentException('File Loading Vendor harus berformat XLSX, XLS, atau CSV.');
        }

        $spreadsheet = IOFactory::load($file->getRealPath());
        $totalWorksheets = $spreadsheet->getSheetCount();
        $totalSourceRows = array_sum(array_map(fn (Worksheet $sheet) => max(0, $sheet->getHighestDataRow()), $spreadsheet->getAllSheets()));
        $processedSourceRows = 0;
        if ($progress) {
            $progress(['total_sheets' => $totalWorksheets, 'processed_sheets' => 0, 'total_rows' => $totalSourceRows, 'processed_rows' => 0, 'last_message' => 'Workbook terbuka. Memeriksa struktur sheet.']);
        }
        $fileHash = @hash_file('sha256', $file->getRealPath()) ?: Str::random(64);
        $rows = [];
        $sheetPreviews = [];
        $sourceSheets = [];
        $errors = [];
        $sheets = 0;
        $ignoredSheets = 0;
        $logicalRows = [];
        $ticketRows = [];
        $supplierMatchCache = [];
        $suppliers = Contact::where('business_id', $businessId)
            ->whereIn('type', ['supplier', 'both'])
            ->where('contact_status', 'active')
            ->get();
        $existingFingerprints = HeavyEquipmentVendorLoading::whereHas('loadingDocument', fn ($query) => $query->where('business_id', $businessId))
            ->whereNotNull('source_fingerprint')
            ->pluck('source_fingerprint')
            ->flip();
        $existingTickets = HeavyEquipmentVendorLoading::whereHas('loadingDocument', fn ($query) => $query->where('business_id', $businessId))
            ->whereNotNull('ticket_no')
            ->pluck('ticket_no')
            ->mapWithKeys(fn ($ticket) => [$this->normalise($ticket) => true]);

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $sheetRows = $worksheet->toArray(null, true, true, true);
            $sourceSheets[] = [
                'title' => $worksheet->getTitle(),
                'rows' => array_map(function (array $sourceRow) {
                    return array_map(function ($value) {
                        return is_scalar($value) || $value === null ? $value : (string) $value;
                    }, $sourceRow);
                }, $sheetRows),
            ];
            $legacyHeaderRow = $this->findLegacyHeaderRow($sheetRows);
            $legacyLayout = $legacyHeaderRow !== null;
            $metadata = $this->sheetMetadata($worksheet, $sheetRows, $legacyLayout);
            $headerRow = $legacyHeaderRow ?: $this->findHeaderRow($sheetRows);
            if ($headerRow === null) {
                $ignoredSheets++;
                continue;
            }

            $sheets++;
            $sheetRowsPreview = [];
            $headers = $this->headerMap($sheetRows[$headerRow] ?? []);
            if ($legacyLayout) {
                $headers = $this->mergeSubHeaders($headers, $sheetRows[$headerRow + 1] ?? []);
            }

            $customer = $metadata['customer_label'] !== ''
                ? $this->loadingService->findCustomer($businessId, $metadata['customer_label'])
                : null;
            $supplierLabel = trim($metadata['supplier_label']) ?: ($legacyLayout ? '' : trim((string) $worksheet->getTitle()));
            $manifestWorkbook = $metadata['goods_name'] !== ''
                && $metadata['vessel_name'] !== ''
                && $metadata['destination'] !== ''
                && $metadata['supplier_label'] !== '';
            $dataStartRow = $headerRow + ($legacyLayout ? 2 : 1);

            foreach ($sheetRows as $rowNumber => $rawRow) {
                $processedSourceRows++;
                if ($progress && ($processedSourceRows % 25 === 0 || $processedSourceRows === $totalSourceRows)) {
                    $progress([
                        'total_sheets' => $totalWorksheets,
                        'processed_sheets' => $sheets + $ignoredSheets,
                        'total_rows' => $totalSourceRows,
                        'processed_rows' => $processedSourceRows,
                        'last_message' => 'Membaca sheet '.$worksheet->getTitle().' — baris '.$rowNumber.'.',
                    ]);
                }
                if ($rowNumber < $dataStartRow || $this->rowIsEmpty($rawRow) || $this->isTotalRow($rawRow)) {
                    continue;
                }

                $candidate = $this->parseRow($rawRow, $headers, $businessId, $legacyLayout);
                if ($candidate === null) {
                    continue;
                }
                if ($manifestWorkbook && empty($candidate['ticket_no'])) {
                    continue;
                }

                $candidate = array_merge($candidate, [
                    'goods_name' => $metadata['goods_name'],
                    'goods_type' => $metadata['goods_type'],
                    'vessel_name' => $metadata['vessel_name'],
                    'destination' => $metadata['destination'],
                    'customer_label' => $metadata['customer_label'],
                    'customer_id' => $customer?->id,
                    'customer_match_status' => $customer ? 'matched' : ($metadata['customer_label'] !== '' ? ($canCreateCustomer ? 'will_create' : 'permission_required') : 'not_set'),
                    'supplier_label' => $supplierLabel ?: ($candidate['supplier_label'] ?? ''),
                    'source_workbook' => $file->getClientOriginalName(),
                    'source_sheet' => $worksheet->getTitle(),
                    'source_row' => $rowNumber,
                ]);
                $candidate['source_fingerprint'] = hash('sha256', $fileHash.'|'.$worksheet->getTitle().'|'.$rowNumber.'|'.json_encode($rawRow));
                $candidate['row_key'] = $this->rowKey($candidate);
                $candidate['supplier_match_status'] = 'not_checked';
                $candidate['supplier_id'] = null;
                $candidate['row_errors'] = [];
                $candidate['row_warnings'] = [];

                $supplierKey = $this->normalise($candidate['supplier_label'] ?? '');
                if (! array_key_exists($supplierKey, $supplierMatchCache)) {
                    $supplierMatchCache[$supplierKey] = $this->matchSuppliers($suppliers, $candidate['supplier_label'] ?? '');
                }
                $supplierMatches = $supplierMatchCache[$supplierKey];
                if ($supplierMatches->count() === 1) {
                    $candidate['supplier_id'] = (int) $supplierMatches->first()->id;
                    $candidate['supplier_match_status'] = 'matched';
                } elseif ($supplierMatches->count() > 1) {
                    $candidate['supplier_match_status'] = 'ambiguous';
                    $candidate['row_errors'][] = 'Bendera/Supplier cocok ke lebih dari satu data. Pilih supplier secara manual.';
                } else {
                    $candidate['supplier_match_status'] = 'unmatched';
                    $candidate['row_errors'][] = 'Bendera/Supplier belum ditemukan. Pilih supplier atau centang buat supplier baru.';
                }

                if ($candidate['customer_match_status'] === 'permission_required') {
                    $candidate['row_errors'][] = 'Customer '.$candidate['customer_label'].' belum terdaftar. Hak customer.create diperlukan untuk membuatnya otomatis.';
                }
                if ($existingFingerprints->has($candidate['source_fingerprint'])) {
                    $candidate['row_errors'][] = 'Baris workbook ini sudah pernah diimpor berdasarkan sumbernya.';
                }
                if (! empty($candidate['ticket_no'])) {
                    if ($existingTickets->has($this->normalise($candidate['ticket_no']))) {
                        $candidate['row_errors'][] = 'Nomor tiket ini sudah digunakan pada Loading Vendor.';
                    }
                    $ticketKey = $this->normalise($candidate['ticket_no']);
                    if (isset($ticketRows[$ticketKey])) {
                        $candidate['row_errors'][] = 'Nomor tiket duplikat dalam workbook; pertama terlihat di sheet '.$ticketRows[$ticketKey]['sheet'].' baris '.$ticketRows[$ticketKey]['row'].'.';
                    } else {
                        $ticketRows[$ticketKey] = ['sheet' => $worksheet->getTitle(), 'row' => $rowNumber];
                    }
                }

                $logicalKey = $this->logicalKey($candidate);
                if (isset($logicalRows[$logicalKey])) {
                    $candidate['row_errors'][] = "Duplikat logis dari sheet {$logicalRows[$logicalKey]['sheet']} baris {$logicalRows[$logicalKey]['row']}.";
                } else {
                    $logicalRows[$logicalKey] = ['sheet' => $worksheet->getTitle(), 'row' => $rowNumber];
                }

                if (! empty($candidate['loading_date_invalid'])) {
                    $candidate['row_errors'][] = 'Tanggal loading tidak terbaca.';
                } elseif (empty($candidate['loading_date'])) {
                    $candidate['date_missing'] = true;
                }
                if (trim((string) ($candidate['vendor_unit_name'] ?? '')) === '') {
                    $candidate['row_errors'][] = 'Unit vendor belum diisi.';
                }
                if (trim((string) ($candidate['vendor_driver_name'] ?? '')) === '') {
                    $candidate['row_errors'][] = 'Sopir vendor belum diisi.';
                }
                if (($candidate['net_tons'] ?? 0) <= 0 && ($candidate['billable_tons'] ?? 0) <= 0) {
                    $candidate['row_errors'][] = 'Netto atau tonase tagihan harus lebih besar dari nol.';
                }
                if (($candidate['vendor_rate_per_ton'] ?? null) === null) {
                    $candidate['row_warnings'][] = 'Tarif vendor belum diisi; data akan masuk sebagai draft dengan nilai kosong.';
                }
                $rows[] = $candidate;
                $sheetRowsPreview[] = $candidate;
            }

            if ($sheetRowsPreview !== []) {
                $labels = collect($sheetRowsPreview)->pluck('supplier_label')->map(fn ($label) => trim((string) $label))->filter()->unique(fn ($label) => $this->normalise($label))->values();
                $resolvedSupplierLabel = trim((string) $metadata['supplier_label']) ?: (string) $labels->first();
                $supplierMatches = $this->matchSuppliers($suppliers, $resolvedSupplierLabel);
                $supplier = $supplierMatches->count() === 1 ? $supplierMatches->first() : null;
                $supplierStatus = $supplier ? 'matched' : ($supplierMatches->count() > 1 ? 'ambiguous' : 'unmatched');
                $supplierConflict = $labels->count() > 1 && trim((string) $metadata['supplier_label']) === '';
                if ($supplierConflict) {
                    foreach ($sheetRowsPreview as &$previewRow) {
                        $previewRow['row_errors'][] = 'Sheet memiliki lebih dari satu nama bendera/supplier. Pisahkan menjadi satu supplier per sheet.';
                    }
                    unset($previewRow);
                }

                $sheetKey = hash('sha256', $fileHash.'|'.$worksheet->getTitle().'|'.$sheets);
                $sheetCustomerStatus = $customer ? 'matched' : (trim((string) $metadata['customer_label']) !== '' ? ($canCreateCustomer ? 'will_create' : 'permission_required') : 'not_set');
                $routeSourceLabels = collect([$metadata['route_label']])
                    ->merge(collect($sheetRowsPreview)->pluck('route_name_snapshot'))
                    ->map(fn ($label) => trim((string) $label))
                    ->filter()
                    ->unique(fn ($label) => $this->normalise($label))
                    ->values();
                $routeConflict = $routeSourceLabels->count() > 1;
                $routeLabel = $routeConflict ? '' : (string) $routeSourceLabels->first();
                $routeId = $routeLabel !== '' ? $this->resolveRouteId($businessId, $routeLabel) : null;
                $routeStatus = $routeConflict ? 'conflict' : ($routeLabel === '' ? 'not_set' : ($routeId ? 'matched' : 'unmatched'));
                $sheetPreviews[] = [
                    'sheet_key' => $sheetKey,
                    'title' => $worksheet->getTitle(),
                    'source_workbook' => $file->getClientOriginalName(),
                    'source_sheet' => $worksheet->getTitle(),
                    'source_file_hash' => $fileHash,
                    'metadata' => [
                        'goods_name' => $metadata['goods_name'],
                        'goods_type' => $metadata['goods_type'],
                        'vessel_name' => $metadata['vessel_name'],
                        'destination' => $metadata['destination'],
                        'route_label' => $routeLabel,
                        'route_id' => $routeId,
                        'route_match_status' => $routeStatus,
                        'route_source_labels' => $routeSourceLabels->all(),
                        'supplier_label' => $resolvedSupplierLabel,
                        'supplier_id' => $supplier?->id,
                        'supplier_match_status' => $supplierConflict ? 'conflict' : $supplierStatus,
                        'customer_label' => $metadata['customer_label'],
                        'customer_id' => $customer?->id,
                        'customer_match_status' => $sheetCustomerStatus,
                    ],
                    'rows' => $sheetRowsPreview,
                    'summary' => [
                        'rows' => count($sheetRowsPreview),
                        'date_missing_rows' => count(array_filter($sheetRowsPreview, fn ($row) => ! empty($row['date_missing']))),
                        'issue_rows' => count(array_filter($sheetRowsPreview, fn ($row) => $row['row_errors'] !== [] || empty($row['loading_date']))),
                    ],
                ];
            }
            if ($progress) {
                $progress([
                    'total_sheets' => $totalWorksheets,
                    'processed_sheets' => $sheets + $ignoredSheets,
                    'total_rows' => $totalSourceRows,
                    'processed_rows' => $processedSourceRows,
                    'last_message' => 'Sheet '.$worksheet->getTitle().' selesai dianalisis.',
                ]);
            }
        }

        if ($rows === []) {
            throw new \InvalidArgumentException('Tidak ada baris Loading Vendor yang dapat dibaca dari workbook. Pastikan ada kolom Unit, Tiket, atau Supplier.');
        }

        foreach ($rows as $row) {
            foreach ($row['row_errors'] as $error) {
                $errors[] = "Sheet {$row['source_sheet']} baris {$row['source_row']}: {$error}";
            }
        }

        $spreadsheet->disconnectWorksheets();

        return [
            'rows' => $rows,
            'sheets' => $sheetPreviews,
            'source_snapshot' => ['sheets' => $sourceSheets],
            'errors' => array_values(array_unique($errors)),
            'summary' => [
                'sheets' => $sheets,
                'ignored_sheets' => $ignoredSheets,
                'source_rows' => count($rows),
                'ready_rows' => count(array_filter($rows, fn ($row) => $row['row_errors'] === [] && ! empty($row['loading_date']))),
                'error_rows' => count(array_filter($rows, fn ($row) => $row['row_errors'] !== [] || empty($row['loading_date']))),
                'date_missing_rows' => count(array_filter($rows, fn ($row) => ! empty($row['date_missing']))),
            ],
        ];
    }

    public function prepareRow(
        array $row,
        int $businessId,
        array $supplierSelections = [],
        array $newSupplierRows = [],
        ?string $missingDate = null,
        bool $canCreateCustomer = true,
        ?int $resolvedCustomerId = null
    ): array {
        $key = $this->rowKey($row);
        $selection = $supplierSelections[$key] ?? null;
        $newSupplier = ! empty($newSupplierRows[$key]);
        if ($selection) {
            $supplier = Contact::where('business_id', $businessId)
                ->whereIn('type', ['supplier', 'both'])
                ->where('contact_status', 'active')
                ->find($selection);
            if (! $supplier) {
                throw new \RuntimeException("Supplier pilihan pada baris {$row['source_row']} tidak valid untuk business aktif.");
            }
            $row['supplier_id'] = $supplier->id;
            $row['supplier_name_snapshot'] = Contact::supplierDisplayName($supplier, false);
            $row['supplier_match_status'] = 'selected';
        } elseif ($newSupplier) {
            $supplier = $this->loadingService->createMinimalSupplier((string) ($row['supplier_label'] ?? ''), $businessId, auth()->id());
            $row['supplier_id'] = $supplier->id;
            $row['supplier_name_snapshot'] = Contact::supplierDisplayName($supplier, false);
            $row['supplier_match_status'] = 'created';
        } elseif (! empty($row['supplier_id'])) {
            $supplier = Contact::where('business_id', $businessId)->whereIn('type', ['supplier', 'both'])->find($row['supplier_id']);
            if (! $supplier) {
                throw new \RuntimeException("Supplier pada baris {$row['source_row']} tidak valid.");
            }
            $row['supplier_name_snapshot'] = Contact::supplierDisplayName($supplier, false);
        } else {
            throw new \RuntimeException("Bendera/Supplier pada baris {$row['source_row']} belum dipilih.");
        }

        $row['row_errors'] = array_values(array_filter($row['row_errors'] ?? [], fn ($error) => ! str_contains(strtolower((string) $error), 'bendera/supplier')));
        if ($row['row_errors'] !== []) {
            throw new \RuntimeException(implode(' ', $row['row_errors']));
        }

        if (empty($row['loading_date'])) {
            if (trim((string) $missingDate) === '') {
                throw new \RuntimeException('Tanggal loading kosong. Isi tanggal bersama pada preview untuk melanjutkan import.');
            }
            $row['loading_date'] = Carbon::parse($missingDate)->toDateString();
            $row['load_date'] = $row['load_date'] ?: $row['loading_date'];
        }

        if (! empty($row['customer_label'])) {
            if ($resolvedCustomerId) {
                $customer = Contact::where('business_id', $businessId)
                    ->whereIn('type', ['customer', 'both'])
                    ->where('contact_status', 'active')
                    ->find($resolvedCustomerId);
            } elseif (! empty($row['customer_id'])) {
                $customer = Contact::where('business_id', $businessId)
                    ->whereIn('type', ['customer', 'both'])
                    ->where('contact_status', 'active')
                    ->find($row['customer_id']);
            } else {
                $customer = $this->loadingService->findCustomer($businessId, (string) $row['customer_label']);
            }

            if (! $customer) {
                if (! $canCreateCustomer) {
                    throw new \RuntimeException('Customer '.$row['customer_label'].' belum terdaftar. Hak customer.create diperlukan untuk membuatnya otomatis.');
                }
                $customer = $this->loadingService->createMinimalCustomer((string) $row['customer_label'], $businessId, (int) auth()->id());
            }
            $row['customer_id'] = $customer->id;
        }

        return $row;
    }

    public function rowKey(array $row): string
    {
        return hash('sha256', implode('|', [(string) ($row['source_sheet'] ?? ''), (string) ($row['source_row'] ?? ''), (string) ($row['source_fingerprint'] ?? '')]));
    }

    private function templateSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $instructions = $spreadsheet->getActiveSheet();
        $instructions->setTitle('Petunjuk');
        $instructions->mergeCells('A1:F1');
        $instructions->setCellValue('A1', 'TEMPLATE LOADING VENDOR');
        $instructions->mergeCells('A2:F2');
        $instructions->setCellValue('A2', 'Petunjuk pengisian workbook');
        $instructions->fromArray([
            ['Langkah', 'Keterangan'],
            ['1', 'Isi customer, barang, kapal, tujuan, jenis barang, dan rute dokumen pada bagian atas sheet data.'],
            ['2', 'Isi nama Supplier/Bendera pada sel kuning. Pertahankan warna kuning tersebut.'],
            ['3', 'Untuk banyak bendera, duplikasi sheet TEMPLATE_BENDERA lalu ganti nama sheet dan nama di sel kuning.'],
            ['4', 'Isi satu tiket pada setiap baris: nomor tiket, nomor polisi, tanggal, Gross, Tare, Nett, harga, total, dan nama sopir di Keterangan.'],
            ['5', 'Baris tanpa tiket diabaikan. Baris subtotal tidak perlu ditambahkan.'],
            ['6', 'Tanggal yang kosong dapat diisi bersama saat preview import. Tanggal yang sudah terisi tetap dipakai dari Excel.'],
            ['7', 'Sheet Petunjuk diabaikan oleh importer. Simpan workbook sebagai XLSX atau XLS.'],
            ['8', 'Jika Rute pada tiket dalam satu sheet berbeda, pilih satu rute dokumen atau tanpa rute saat preview.'],
        ], null, 'A4');
        $instructions->getColumnDimension('A')->setWidth(14);
        $instructions->getColumnDimension('B')->setWidth(112);
        $instructions->getStyle('A1:F1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '234A64']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $instructions->getStyle('A2:F2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '234A64']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF1F5']],
        ]);
        $instructions->getStyle('A4:B4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '46697D']],
        ]);
        $instructions->getStyle('A4:B12')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $instructions->getStyle('A4:B12')->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D5DEE3']]],
        ]);
        $instructions->getRowDimension(1)->setRowHeight(30);
        $instructions->getRowDimension(2)->setRowHeight(24);
        foreach (range(5, 12) as $row) {
            $instructions->getRowDimension($row)->setRowHeight(34);
        }

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('TEMPLATE_BENDERA');
        $sheet->mergeCells('A1:J1');
        $sheet->setCellValue('A1', 'REKAP LOADING VENDOR');
        $sheet->mergeCells('A2:C2');
        $sheet->mergeCells('D2:G2');
        $sheet->setCellValue('A2', 'CUSTOMER');
        $sheet->setCellValue('D2', 'PT. JATIM PROPERTINDO JAYA');

        foreach ([4 => 'NAMA BARANG', 5 => 'NAMA KAPAL', 6 => 'TUJUAN', 7 => 'JENIS BARANG'] as $row => $label) {
            $sheet->mergeCells("A{$row}:C{$row}");
            $sheet->mergeCells("D{$row}:G{$row}");
            $sheet->setCellValue("A{$row}", $label);
        }
        $sheet->mergeCells('A8:C8');
        $sheet->mergeCells('D8:G8');
        $sheet->setCellValue('A8', 'RUTE DOKUMEN (OPSIONAL)');
        $sheet->setCellValue('D8', null);
        $sheet->getComment('D8')->getText()->createTextRun('Isi satu rute untuk seluruh sheet. Biarkan kosong jika tidak memakai rute master.');
        $sheet->mergeCells('H5:J5');
        $sheet->setCellValue('H5', 'ISI NAMA BENDERA / SUPPLIER');
        $sheet->mergeCells('A9:A10');
        $sheet->mergeCells('B9:B10');
        $sheet->mergeCells('C9:C10');
        $sheet->mergeCells('D9:D10');
        $sheet->mergeCells('E9:G9');
        foreach (['A9' => 'NO', 'B9' => 'NO TIKET', 'C9' => 'NO.POLISI', 'D9' => 'TANGGAL', 'E9' => 'TONASE', 'H9' => 'HARGA', 'I9' => 'TOTAL', 'J9' => 'KETERANGAN'] as $cell => $label) {
            $sheet->setCellValue($cell, $label);
        }
        foreach (['E10' => 'GROSS', 'F10' => 'TARE', 'G10' => 'NETT'] as $cell => $label) {
            $sheet->setCellValue($cell, $label);
        }
        for ($row = 11; $row <= 30; $row++) {
            $sheet->setCellValue("A{$row}", $row - 10);
        }

        $sheet->getStyle('A1:J1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '234A64']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle('A2:G2')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '234A64']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF1F5']],
        ]);
        $sheet->getStyle('A4:G7')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F4F7F8']],
        ]);
        $sheet->getStyle('A8:G8')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F4F7F8']],
        ]);
        $sheet->getStyle('H5:J5')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '4F3B00']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFF00']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getStyle('A9:J10')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DCE7EC']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getStyle('A1:J30')->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D5DEE3']]],
        ]);
        $sheet->getStyle('A11:J30')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        foreach (['A' => 7, 'B' => 19, 'C' => 18, 'D' => 15, 'E' => 12, 'F' => 12, 'G' => 12, 'H' => 16, 'I' => 19, 'J' => 24] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getRowDimension(9)->setRowHeight(24);
        $sheet->getRowDimension(10)->setRowHeight(22);
        $sheet->freezePane('A11');
        $sheet->getTabColor()->setRGB('FFFF00');
        $spreadsheet->setActiveSheetIndex(1);

        return $spreadsheet;
    }

    private function sheetMetadata(Worksheet $worksheet, array $rows, bool $legacyLayout): array
    {
        $metadata = [
            'goods_name' => '',
            'goods_type' => '',
            'vessel_name' => '',
            'destination' => '',
            'route_label' => '',
            'customer_label' => '',
            'supplier_label' => '',
        ];
        $labels = [
            'namabarang' => 'goods_name',
            'barang' => 'goods_name',
            'namakapal' => 'vessel_name',
            'kapal' => 'vessel_name',
            'tujuan' => 'destination',
            'destination' => 'destination',
            'rute' => 'route_label',
            'rutedokumen' => 'route_label',
            'rutedokumenopsional' => 'route_label',
            'route' => 'route_label',
            'jenisbarang' => 'goods_type',
            'customer' => 'customer_label',
            'pelanggan' => 'customer_label',
        ];

        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber > 8) {
                break;
            }
            foreach ($row as $column => $value) {
                $key = $this->compact($value);
                if (! isset($labels[$key])) {
                    continue;
                }
                $field = $labels[$key];
                if ($metadata[$field] === '') {
                    $metadata[$field] = $this->inlineMetadataValue((string) $value);
                    if ($metadata[$field] === '') {
                        $metadata[$field] = $this->nextNonEmptyCell($row, $column);
                    }
                }
            }
        }

        // The supplied workbook has no explicit CUSTOMER label; its company is in row 2.
        if ($legacyLayout && $metadata['customer_label'] === '' && ! empty($rows[2])) {
            foreach ($rows[2] as $value) {
                $candidate = $this->cleanMetadataValue((string) $value);
                if ($candidate !== '' && $this->compact($candidate) !== 'rekaploadingcangkang') {
                    $metadata['customer_label'] = $candidate;
                    break;
                }
            }
        }

        for ($row = 1; $row <= 8; $row++) {
            for ($column = 1; $column <= 16; $column++) {
                $cell = $worksheet->getCell([$column, $row]);
                $value = trim((string) ($rows[$row][Coordinate::stringFromColumnIndex($column)] ?? ''));
                if ($value !== '' && $metadata['supplier_label'] === '' && $this->isYellowFill($cell)) {
                    $metadata['supplier_label'] = $this->cleanMetadataValue($value);
                }
            }
        }

        return $metadata;
    }

    private function isYellowFill($cell): bool
    {
        $fill = $cell->getStyle()->getFill();
        if ($fill->getFillType() === Fill::FILL_NONE) {
            return false;
        }
        $color = $fill->getStartColor();
        $argb = strtoupper(ltrim((string) $color->getARGB(), '#'));
        $rgb = strtoupper(ltrim((string) $color->getRGB(), '#'));

        return in_array($argb, ['FFFF00', 'FFFFFF00', 'FFFFC000', 'FFFFF2CC'], true)
            || in_array($rgb, ['FFFF00', 'FFC000', 'FFF2CC'], true)
            || (int) $color->getIndexed() === 6;
    }

    private function inlineMetadataValue(string $value): string
    {
        if (str_contains($value, ':')) {
            return $this->cleanMetadataValue(substr($value, strpos($value, ':') + 1));
        }

        return '';
    }

    private function nextNonEmptyCell(array $row, string $column): string
    {
        $current = Coordinate::columnIndexFromString($column);
        foreach ($row as $candidateColumn => $value) {
            if (Coordinate::columnIndexFromString((string) $candidateColumn) > $current && trim((string) $value) !== '') {
                return $this->cleanMetadataValue((string) $value);
            }
        }

        return '';
    }

    private function cleanMetadataValue(string $value): string
    {
        return trim(preg_replace('/^\s*:\s*/u', '', $value));
    }

    private function findLegacyHeaderRow(array $rows): ?int
    {
        foreach ($rows as $rowNumber => $row) {
            $keys = array_keys($this->headerMap($row));
            if (in_array('notiket', $keys, true) && in_array('nopolisi', $keys, true) && in_array('tanggal', $keys, true)) {
                return (int) $rowNumber;
            }
        }

        return null;
    }

    private function findHeaderRow(array $rows): ?int
    {
        foreach ($rows as $rowNumber => $row) {
            $keys = array_keys($this->headerMap($row));
            if (array_intersect($keys, ['unit', 'nopolisi', 'notiket', 'supplier', 'bendera', 'vendor'])) {
                return (int) $rowNumber;
            }
        }

        return null;
    }

    private function headerMap(array $row): array
    {
        $map = [];
        foreach ($row as $column => $value) {
            $key = $this->compact($value);
            if ($key !== '') {
                $map[$key][] = $column;
            }
        }

        return $map;
    }

    private function mergeSubHeaders(array $headers, array $subHeaderRow): array
    {
        foreach ($subHeaderRow as $column => $value) {
            $key = $this->compact($value);
            if (in_array($key, ['gross', 'tare', 'nett', 'netto', 'net'], true)) {
                $headers[$key][] = $column;
            }
        }

        return $headers;
    }

    private function parseRow(array $row, array $headers, int $businessId, bool $legacyLayout = false): ?array
    {
        $ticketRaw = $this->value($row, $this->findColumn($headers, ['notiket', 'tiket', 'ticket', 'nomortiket', 'nomor']));
        $ticket = preg_replace('/^\s*(?:no|nomor)\s*[:#-]?\s*/iu', '', $ticketRaw) ?: null;
        $unit = $this->value($row, $this->findColumn($headers, ['nopolisi', 'nomorpolisi', 'unit', 'kendaraan', 'truck', 'truckno', 'platnomor']));
        $supplier = $this->value($row, $this->findColumn($headers, ['bendera', 'benderan', 'supplier', 'vendor', 'pemilik', 'namavendor']));
        $driver = $this->value($row, $this->findColumn($headers, ['driver', 'pengemudi', 'operator', 'sopir', 'namasopir']));
        if ($driver === '' && $legacyLayout) {
            $driver = $this->value($row, $this->findColumn($headers, ['keterangan', 'catatan', 'notes']));
        }
        if ($driver === '') {
            $driver = $this->value($row, $this->findColumnContaining($headers, 'driver'));
        }

        if ($unit === '' && $ticket === '' && ($legacyLayout || $supplier === '')) {
            return null;
        }

        $dateRaw = $this->value($row, $this->findColumn($headers, ['tanggal', 'tgl', 'tanggalloading', 'dateloading']));
        $loadingDate = $this->dateValue($dateRaw);
        $loadDate = $this->dateValue($this->value($row, $this->findColumn($headers, ['tanggalmuat', 'tglmuat', 'loaddate']))) ?: $loadingDate;
        $unloadDate = $this->dateValue($this->value($row, $this->findColumn($headers, ['tanggalbongkar', 'tglbongkar', 'unloaddate'])));
        $gross = $this->numberValue($this->value($row, $this->findColumnContaining($headers, 'gross')));
        $tare = $this->numberValue($this->value($row, $this->findColumnContaining($headers, 'tare')));
        $net = $this->numberValue($this->value($row, $this->findColumn($headers, ['netto', 'nett', 'net'])));
        if (($net === null || $net <= 0) && $gross !== null && $tare !== null) {
            $net = round($gross - $tare, 4);
        }
        $received = $this->numberValue($this->value($row, $this->findColumn($headers, ['diterima', 'received', 'tonasediterima'])));
        $billable = $this->numberValue($this->value($row, $this->findColumn($headers, ['tontagihan', 'tonasetagihan', 'billable', 'tonasebillable']))) ?: ($received ?: $net);
        $rate = $this->numberValue($this->value($row, $this->findColumn($headers, ['tarifvendor', 'hargavendor', 'hargaton', 'harga', 'rate', 'tarif'])));
        $total = $this->numberValue($this->value($row, $this->findColumn($headers, ['total', 'jumlah', 'nilaivendor'])));
        $claim = $this->numberValue($this->value($row, $this->findColumn($headers, ['klaim', 'penyesuaian', 'claimadjustment']))) ?: 0;
        $deduction = $this->numberValue($this->value($row, $this->findColumn($headers, ['potongan', 'deduction', 'potonganlain']))) ?: 0;
        $route = $this->value($row, $this->findColumn($headers, ['rute', 'route', 'jalur']));
        $notes = $this->value($row, $this->findColumn($headers, ['keterangan', 'catatan', 'notes']));

        return [
            'loading_no' => $legacyLayout ? null : ($this->value($row, $this->findColumn($headers, ['nomorloading', 'loadingno', 'no'])) ?: null),
            'ticket_no' => $ticket,
            'loading_date' => $loadingDate,
            'loading_date_invalid' => $dateRaw !== '' && $loadingDate === null,
            'load_date' => $loadDate,
            'unload_date' => $unloadDate,
            'supplier_label' => $supplier,
            'supplier_name_snapshot' => $supplier ?: null,
            'vendor_driver_name' => $driver ?: null,
            'vendor_unit_name' => $unit ?: null,
            'route_name_snapshot' => $route ?: null,
            'route_id' => $this->resolveRouteId($businessId, $route),
            'gross_tons' => $gross,
            'tare_tons' => $tare,
            'net_tons' => $net,
            'received_tons' => $received,
            'billable_tons' => $billable,
            'vendor_rate_per_ton' => $rate,
            'vendor_total_amount' => $total,
            'claim_adjustment' => $claim,
            'other_deduction' => $deduction,
            'notes' => $legacyLayout ? null : ($notes ?: null),
        ];
    }

    private function supplierMatches(int $businessId, string $label)
    {
        return $this->matchSuppliers(Contact::where('business_id', $businessId)->whereIn('type', ['supplier', 'both'])->where('contact_status', 'active')->get(), $label);
    }

    private function matchSuppliers($suppliers, string $label)
    {
        $needle = $this->normalise($label);
        if ($needle === '') {
            return collect();
        }

        return $suppliers->filter(function (Contact $contact) use ($needle) {
            return in_array($needle, array_filter([
                $this->normalise($contact->name),
                $this->normalise($contact->supplier_business_name),
                $this->normalise($contact->contact_id),
                $this->normalise(Contact::supplierDisplayName($contact, false)),
            ]), true);
        })->values();
    }

    private function resolveRouteId(int $businessId, ?string $label): ?int
    {
        if (trim((string) $label) === '') {
            return null;
        }

        return HeavyEquipmentRoute::where('business_id', $businessId)
            ->where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($label))])
            ->value('id');
    }

    private function findColumn(array $headers, array $keys): ?string
    {
        foreach ($keys as $key) {
            $key = $this->compact($key);
            if (! empty($headers[$key])) {
                return $headers[$key][0];
            }
        }

        return null;
    }

    private function findColumnContaining(array $headers, string $needle): ?string
    {
        $needle = $this->compact($needle);
        foreach ($headers as $key => $columns) {
            if (str_contains($key, $needle)) {
                return $columns[0];
            }
        }

        return null;
    }

    private function dateValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if (is_numeric($value) && (float) $value > 1000) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            }

            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function numberValue($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $value = trim(str_ireplace(['rp', 'idr', ' '], '', (string) $value));
        $value = preg_replace('/[^0-9,.\-]/', '', $value);
        if ($value === '' || $value === '-') {
            return null;
        }
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if ($lastComma !== false && $lastDot !== false) {
            $value = $lastComma > $lastDot ? str_replace(',', '.', str_replace('.', '', $value)) : str_replace(',', '', $value);
        } elseif ($lastComma !== false) {
            $value = strlen($value) - $lastComma - 1 <= 2 ? str_replace(',', '.', $value) : str_replace(',', '', $value);
        } elseif (substr_count($value, '.') > 1) {
            $value = str_replace('.', '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function value(array $row, ?string $column): string
    {
        return trim((string) ($column === null ? '' : ($row[$column] ?? '')));
    }

    private function rowIsEmpty(array $row): bool
    {
        return ! collect($row)->contains(fn ($value) => trim((string) $value) !== '');
    }

    private function isTotalRow(array $row): bool
    {
        foreach ($row as $value) {
            if (in_array($this->compact($value), ['total', 'subtotal', 'grandtotal', 'jumlah'], true)) {
                return true;
            }
        }

        return false;
    }

    private function logicalKey(array $row): string
    {
        return hash('sha256', implode('|', [
            $this->normalise($row['ticket_no'] ?? ''),
            $this->normalise($row['supplier_label'] ?? ''),
            $this->normalise($row['vendor_unit_name'] ?? ''),
            $this->normalise($row['vendor_driver_name'] ?? ''),
            (string) ($row['loading_date'] ?? ''),
            (string) ($row['billable_tons'] ?? ''),
        ]));
    }

    private function compact($value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string) ($value ?? ''))));
    }

    private function normalise($value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', (string) ($value ?? ''))));
    }
}
