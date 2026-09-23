<?php

namespace Modules\AssetManagement\Services;

use App\Contact;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\AssetManagement\Entities\HeavyEquipmentRoute;
use Modules\AssetManagement\Entities\HeavyEquipmentVendorLoading;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class HeavyEquipmentVendorLoadingImportService
{
    public function __construct(private HeavyEquipmentVendorLoadingService $loadingService)
    {
    }

    public function preview(UploadedFile $file, int $businessId): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new \InvalidArgumentException('File Loading Vendor harus berformat XLSX, XLS, atau CSV.');
        }

        $spreadsheet = IOFactory::load($file->getRealPath());
        $fileHash = @hash_file('sha256', $file->getRealPath()) ?: Str::random(64);
        $rows = [];
        $errors = [];
        $sheets = 0;
        $logicalRows = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $sheets++;
            $sheetRows = $worksheet->toArray(null, true, true, true);
            $headerRow = $this->findHeaderRow($sheetRows);
            if ($headerRow === null) {
                continue;
            }
            $headers = $this->headerMap($sheetRows[$headerRow] ?? []);
            foreach ($sheetRows as $rowNumber => $rawRow) {
                if ($rowNumber <= $headerRow || $this->rowIsEmpty($rawRow)) {
                    continue;
                }

                $candidate = $this->parseRow($rawRow, $headers, $businessId);
                if ($candidate === null) {
                    continue;
                }
                $candidate['source_workbook'] = $file->getClientOriginalName();
                $candidate['source_sheet'] = $worksheet->getTitle();
                $candidate['source_row'] = $rowNumber;
                $candidate['source_fingerprint'] = hash('sha256', $fileHash.'|'.$worksheet->getTitle().'|'.$rowNumber.'|'.json_encode($rawRow));
                $candidate['row_key'] = $this->rowKey($candidate);
                $candidate['supplier_match_status'] = 'not_checked';
                $candidate['supplier_id'] = null;
                $candidate['row_errors'] = [];
                $candidate['row_warnings'] = [];

                $supplierMatches = $this->supplierMatches($businessId, $candidate['supplier_label'] ?? '');
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

                if (HeavyEquipmentVendorLoading::where('business_id', $businessId)->where('source_fingerprint', $candidate['source_fingerprint'])->exists()) {
                    $candidate['row_errors'][] = 'Baris workbook ini sudah pernah diimpor berdasarkan sumbernya.';
                }
                if (! empty($candidate['ticket_no']) && HeavyEquipmentVendorLoading::where('business_id', $businessId)->where('ticket_no', $candidate['ticket_no'])->exists()) {
                    $candidate['row_errors'][] = 'Nomor tiket ini sudah digunakan pada Loading Vendor.';
                }

                $logicalKey = $this->logicalKey($candidate);
                if (isset($logicalRows[$logicalKey])) {
                    $candidate['row_errors'][] = "Duplikat logis dari sheet {$logicalRows[$logicalKey]['sheet']} baris {$logicalRows[$logicalKey]['row']}.";
                } else {
                    $logicalRows[$logicalKey] = ['sheet' => $worksheet->getTitle(), 'row' => $rowNumber];
                }

                if (empty($candidate['loading_date'])) {
                    $candidate['row_errors'][] = 'Tanggal loading tidak terbaca.';
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

        return [
            'rows' => $rows,
            'errors' => array_values(array_unique($errors)),
            'summary' => [
                'sheets' => $sheets,
                'source_rows' => count($rows),
                'ready_rows' => count(array_filter($rows, fn ($row) => $row['row_errors'] === [])),
                'error_rows' => count(array_filter($rows, fn ($row) => $row['row_errors'] !== [])),
            ],
        ];
    }

    public function prepareRow(array $row, int $businessId, array $supplierSelections = [], array $newSupplierRows = []): array
    {
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

        return $row;
    }

    public function rowKey(array $row): string
    {
        return hash('sha256', implode('|', [(string) ($row['source_sheet'] ?? ''), (string) ($row['source_row'] ?? ''), (string) ($row['source_fingerprint'] ?? '')]));
    }

    private function parseRow(array $row, array $headers, int $businessId): ?array
    {
        $ticket = $this->value($row, $this->findColumn($headers, ['notiket', 'ticket', 'nomortiket', 'nomor'])) ?: null;
        $unit = $this->value($row, $this->findColumn($headers, ['nopolisi', 'nomorpolisi', 'unit', 'kendaraan', 'truck', 'truckno', 'platnomor']));
        $supplier = $this->value($row, $this->findColumn($headers, ['bendera', 'benderan', 'supplier', 'vendor', 'pemilik', 'namavendor']));
        $driver = $this->value($row, $this->findColumn($headers, ['driver', 'pengemudi', 'operator', 'sopir', 'namasopir']));
        if ($driver === '') {
            $driver = $this->value($row, $this->findColumnContaining($headers, 'driver'));
        }
        if ($unit === '' && $ticket === '' && $supplier === '') {
            return null;
        }

        $loadingDate = $this->dateValue($this->value($row, $this->findColumn($headers, ['tanggal', 'tgl', 'tanggalloading', 'dateloading'])));
        $loadDate = $this->dateValue($this->value($row, $this->findColumn($headers, ['tanggalmuat', 'tglmuat', 'loaddate']))) ?: $loadingDate;
        $unloadDate = $this->dateValue($this->value($row, $this->findColumn($headers, ['tanggalbongkar', 'tglbongkar', 'unloaddate'])));
        $gross = $this->numberValue($this->value($row, $this->findColumnContaining($headers, 'gross')));
        $tare = $this->numberValue($this->value($row, $this->findColumnContaining($headers, 'tare')));
        $net = $this->numberValue($this->value($row, $this->findColumn($headers, ['netto', 'nett', 'net'])));
        if (($net === null || $net <= 0) && $gross !== null && $tare !== null) {
            $net = round($gross - $tare, 4);
        }
        $received = $this->numberValue($this->value($row, $this->findColumn($headers, ['diterima', 'received', 'tonasediterima'])));
        $billable = $this->numberValue($this->value($row, $this->findColumn($headers, ['tontagihan', 'tonasetagihan', 'billable', 'tonasebillable'])));
        $billable = $billable ?: ($received ?: $net);
        $rate = $this->numberValue($this->value($row, $this->findColumn($headers, ['tarifvendor', 'hargavendor', 'hargaton', 'harga', 'rate', 'tarif'])));
        $claim = $this->numberValue($this->value($row, $this->findColumn($headers, ['klaim', 'penyesuaian', 'claimadjustment']))) ?: 0;
        $deduction = $this->numberValue($this->value($row, $this->findColumn($headers, ['potongan', 'deduction', 'potonganlain']))) ?: 0;
        $route = $this->value($row, $this->findColumn($headers, ['rute', 'route', 'jalur']));
        $notes = $this->value($row, $this->findColumn($headers, ['keterangan', 'catatan', 'notes']));

        return [
            'loading_no' => $this->value($row, $this->findColumn($headers, ['nomorloading', 'loadingno', 'no']) ) ?: null,
            'ticket_no' => $ticket,
            'loading_date' => $loadingDate,
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
            'claim_adjustment' => $claim,
            'other_deduction' => $deduction,
            'notes' => $notes ?: null,
        ];
    }

    private function supplierMatches(int $businessId, string $label)
    {
        $needle = $this->normalise($label);
        if ($needle === '') {
            return collect();
        }
        return Contact::where('business_id', $businessId)->whereIn('type', ['supplier', 'both'])->where('contact_status', 'active')->get()->filter(function ($contact) use ($needle) {
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
        if ($businessId <= 0 || trim((string) $label) === '') {
            return null;
        }
        return HeavyEquipmentRoute::where('business_id', $businessId)->whereRaw('LOWER(name) = ?', [strtolower(trim($label))])->value('id');
    }

    private function findHeaderRow(array $rows): ?int
    {
        foreach ($rows as $rowNumber => $row) {
            $keys = array_keys($this->headerMap($row));
            if (array_intersect($keys, ['unit', 'nopolisi', 'notiket', 'supplier', 'bendera', 'vendor'])) {
                return $rowNumber;
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
        return preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string) $value)));
    }

    private function normalise($value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
    }
}
