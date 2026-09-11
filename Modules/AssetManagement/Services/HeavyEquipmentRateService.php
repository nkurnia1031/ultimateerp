<?php

namespace Modules\AssetManagement\Services;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Modules\AssetManagement\Entities\Asset;
use Modules\AssetManagement\Entities\HeavyEquipmentRateBatch;
use Modules\AssetManagement\Entities\HeavyEquipmentRateUnit;
use Modules\AssetManagement\Entities\HeavyEquipmentRoute;
use Modules\AssetManagement\Entities\HeavyEquipmentRouteRate;

class HeavyEquipmentRateService
{
    public function import(UploadedFile $file, Carbon $effectiveFrom, int $businessId, int $userId): HeavyEquipmentRateBatch
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new \InvalidArgumentException('File tarif harus berformat XLSX, XLS, atau CSV.');
        }

        $sourceHash = @hash_file('sha256', $file->getRealPath()) ?: null;
        if ($sourceHash && HeavyEquipmentRateBatch::where('business_id', $businessId)
            ->where('source_hash', $sourceHash)
            ->whereIn('status', ['draft', 'active'])
            ->exists()) {
            throw new \InvalidArgumentException('Workbook tarif identik sudah pernah di-upload dan masih aktif atau menunggu konfirmasi. Gunakan batch tersebut atau batalkan terlebih dahulu.');
        }

        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, true);
        $headerRow = $this->findHeaderRow($rows);
        if ($headerRow === null) {
            throw new \InvalidArgumentException('Header Excel tidak ditemukan. Gunakan kolom Rute, Unit, Borongan Supir/Ton, Uang Jalan, Harga/Ton, dan Keterangan.');
        }

        $headers = $this->headers($rows[$headerRow]);
        foreach (['rute', 'unit', 'borongansupirton', 'uangjalan', 'hargaton'] as $required) {
            if (! isset($headers[$required])) {
                throw new \InvalidArgumentException("Kolom {$required} tidak ditemukan pada workbook.");
            }
        }

        $sourceRows = [];
        $errors = [];
        $routeNames = [];
        $unitLabels = [];
        $sourceRowCount = 0;
        $validRowCount = 0;

        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber <= $headerRow || $this->rowIsEmpty($row)) {
                continue;
            }
            $sourceRowCount++;

            $routeName = $this->stringValue($row[$headers['rute']] ?? null);
            $unitText = $this->stringValue($row[$headers['unit']] ?? null);
            $driverRate = $this->numberValue($row[$headers['borongansupirton']] ?? null);
            $travelAllowance = $this->numberValue($row[$headers['uangjalan']] ?? null);
            $saleRate = $this->numberValue($row[$headers['hargaton']] ?? null);
            $notes = isset($headers['keterangan']) ? $this->stringValue($row[$headers['keterangan']] ?? null) : null;

            if ($routeName === '' || $unitText === '') {
                $errors[] = "Baris {$rowNumber}: Rute dan Unit wajib diisi.";
                continue;
            }
            $units = $this->splitUnits($unitText);
            if ($units === []) {
                $errors[] = "Baris {$rowNumber}: Unit tidak valid.";
                continue;
            }

            $rowValid = true;
            if ($driverRate === null || $travelAllowance === null) {
                $errors[] = "Baris {$rowNumber}: Borongan Supir/Ton dan Uang Jalan harus berupa angka.";
                $rowValid = false;
            }
            if ($driverRate < 0 || $travelAllowance < 0 || ($saleRate !== null && $saleRate < 0)) {
                $errors[] = "Baris {$rowNumber}: tarif tidak boleh negatif.";
                $rowValid = false;
            }

            if (! $rowValid) {
                continue;
            }

            $routeNames[strtolower($routeName)] = $routeName;
            foreach ($units as $unit) {
                $unitLabels[strtolower($unit)] = $unit;
            }
            $sourceRows[] = [
                'rowNumber' => $rowNumber,
                'routeName' => $routeName,
                'unitText' => $unitText,
                'units' => $units,
                'driverRate' => $driverRate ?? 0,
                'travelAllowance' => $travelAllowance ?? 0,
                'saleRate' => $saleRate,
                'notes' => $notes,
                'valid' => $rowValid,
            ];
            if ($rowValid) {
                $validRowCount++;
            }
        }

        if ($sourceRows === []) {
            throw new \InvalidArgumentException('Tidak ada baris tarif yang dapat diproses.');
        }

        $batch = DB::transaction(function () use ($file, $sourceHash, $effectiveFrom, $businessId, $userId, $sourceRows, $errors, $routeNames, $unitLabels, $sourceRowCount, $validRowCount) {
            $batch = HeavyEquipmentRateBatch::create([
                'business_id' => $businessId,
                'file_name' => $file->getClientOriginalName(),
                'source_hash' => $sourceHash,
                'effective_from' => $effectiveFrom->toDateString(),
                'status' => 'draft',
                'source_row_count' => $sourceRowCount,
                'valid_row_count' => $validRowCount,
                'unit_count' => count($unitLabels),
                'route_count' => count($routeNames),
                'error_message' => $errors === [] ? null : json_encode($errors, JSON_UNESCAPED_UNICODE),
                'uploaded_by' => $userId,
            ]);

            foreach ($sourceRows as $source) {
                $route = HeavyEquipmentRoute::firstOrCreate(
                    ['business_id' => $businessId, 'name' => $source['routeName']],
                    ['created_by' => $userId, 'is_active' => true]
                );

                $rate = HeavyEquipmentRouteRate::create([
                    'business_id' => $businessId,
                    'route_id' => $route->id,
                    'batch_id' => $batch->id,
                    'effective_from' => $effectiveFrom->toDateString(),
                    'driver_rate_per_ton' => $source['driverRate'],
                    'travel_allowance' => $source['travelAllowance'],
                    'sale_rate_per_ton' => $source['saleRate'],
                    'source_row' => $source['rowNumber'],
                    'source_unit_text' => $source['unitText'],
                    'source_status' => 'imported',
                    'notes' => $source['notes'],
                    'status' => 'draft',
                ]);

                foreach ($source['units'] as $unitLabel) {
                    HeavyEquipmentRateUnit::create([
                        'route_rate_id' => $rate->id,
                        'asset_id' => $this->resolveAssetId($businessId, $unitLabel),
                        'unit_label' => $unitLabel,
                    ]);
                }
            }

            return $batch;
        });

        return $batch->load(['rates.route', 'rates.units.asset']);
    }

    public function confirm(HeavyEquipmentRateBatch $batch): HeavyEquipmentRateBatch
    {
        $batch->load(['rates.units', 'rates.route']);
        if ($batch->status !== 'draft') {
            throw new \RuntimeException('Batch tarif ini sudah tidak berada pada status draft.');
        }

        $storedErrors = json_decode($batch->error_message ?: '[]', true) ?: [];
        $importErrors = array_values(array_filter($storedErrors, fn ($error) => ! $this->isConfirmationError((string) $error)));
        $errors = [];
        $seen = [];
        $effectiveFrom = Carbon::parse($batch->effective_from);
        $ratesToConfirm = $batch->rates->filter(fn (HeavyEquipmentRouteRate $rate) => $rate->status !== 'cancelled');

        if ($ratesToConfirm->isEmpty()) {
            throw new \RuntimeException('Batch tarif tidak memiliki baris yang dapat diaktifkan.');
        }

        foreach ($ratesToConfirm as $rate) {
            if ($rate->units->isEmpty()) {
                $errors[] = "Baris {$rate->source_row}: tidak memiliki unit.";
                continue;
            }
            foreach ($rate->units as $unit) {
                if (empty($unit->asset_id)) {
                    $errors[] = "Baris {$rate->source_row}: unit '{$unit->unit_label}' belum cocok dengan Assets.";
                    continue;
                }

                $key = $rate->route_id . ':' . $unit->asset_id;
                if (isset($seen[$key])) {
                    $errors[] = "Duplikasi pada workbook: rute '{$rate->route->name}' dan unit '{$unit->unit_label}'.";
                    continue;
                }
                $seen[$key] = true;

                $duplicate = HeavyEquipmentRouteRate::where('business_id', $batch->business_id)
                    ->where('route_id', $rate->route_id)
                    ->where('effective_from', $effectiveFrom->toDateString())
                    ->where('id', '!=', $rate->id)
                    ->whereIn('status', ['active', 'expired'])
                    ->whereHas('units', function ($query) use ($unit) {
                        $query->where('asset_id', $unit->asset_id);
                    })
                    ->exists();
                if ($duplicate) {
                    $errors[] = "Tarif sudah ada untuk rute '{$rate->route->name}' dan unit '{$unit->unit_label}' pada tanggal berlaku yang sama.";
                }
            }
        }

        if ($errors !== []) {
            $allErrors = array_values(array_unique(array_merge($importErrors, $errors)));
            $batch->update(['error_message' => json_encode($allErrors, JSON_UNESCAPED_UNICODE)]);
            throw new \RuntimeException(implode(' ', $allErrors));
        }

        DB::transaction(function () use ($batch, $effectiveFrom, $importErrors, $ratesToConfirm) {
            foreach ($ratesToConfirm as $rate) {
                foreach ($rate->units as $unit) {
                    $previousRates = HeavyEquipmentRouteRate::where('business_id', $batch->business_id)
                        ->where('route_id', $rate->route_id)
                        ->where('effective_from', '<', $effectiveFrom->toDateString())
                        ->where('status', 'active')
                        ->whereHas('units', function ($query) use ($unit) {
                            $query->where('asset_id', $unit->asset_id);
                        })
                        ->get();

                    foreach ($previousRates as $previous) {
                        $previous->update([
                            'effective_to' => $effectiveFrom->copy()->subDay()->toDateString(),
                            'status' => 'expired',
                        ]);
                    }
                }
                $rate->update(['status' => 'active']);
            }
            $batch->update([
                'status' => 'active',
                'imported_at' => now(),
                'error_message' => $importErrors === [] ? null : json_encode($importErrors, JSON_UNESCAPED_UNICODE),
            ]);
        });

        return $batch->fresh(['rates.route', 'rates.units.asset']);
    }

    private function isConfirmationError(string $error): bool
    {
        return str_contains($error, 'belum cocok dengan Assets.')
            || str_contains($error, 'Duplikasi pada workbook:')
            || str_contains($error, 'Tarif sudah ada untuk rute')
            || str_contains($error, 'tidak memiliki unit.');
    }

    public function cancel(HeavyEquipmentRateBatch $batch): void
    {
        if ($batch->status === 'cancelled') {
            return;
        }
        if ($batch->rates()->whereHas('trips')->exists()) {
            throw new \RuntimeException('Batch tidak dapat dibatalkan karena sudah dipakai oleh trip.');
        }

        DB::transaction(function () use ($batch) {
            $rates = $batch->rates()->with('units')->get();
            foreach ($rates as $rate) {
                foreach ($rate->units as $unit) {
                    $previous = HeavyEquipmentRouteRate::where('business_id', $batch->business_id)
                        ->where('route_id', $rate->route_id)
                        ->where('effective_from', '<', $rate->effective_from)
                        ->where('status', 'expired')
                        ->whereHas('units', function ($query) use ($unit) {
                            $query->where('asset_id', $unit->asset_id);
                        })
                        ->orderByDesc('effective_from')
                        ->first();
                    if ($previous) {
                        $previous->update(['effective_to' => null, 'status' => 'active']);
                    }
                }
                $rate->update(['status' => 'cancelled', 'source_status' => 'cancelled']);
            }
            $batch->update(['status' => 'cancelled']);
        });
    }

    public function findRate(int $businessId, int $routeId, int $assetId, Carbon $date): ?HeavyEquipmentRouteRate
    {
        return HeavyEquipmentRouteRate::with(['route', 'units.asset'])
            ->where('business_id', $businessId)
            ->where('route_id', $routeId)
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString());
            })
            ->whereHas('units', function ($query) use ($assetId) {
                $query->where('asset_id', $assetId);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    public function buildExport(int $businessId): Spreadsheet
    {
        $rates = HeavyEquipmentRouteRate::with(['route', 'units.asset'])
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->orderBy('route_id')
            ->orderBy('effective_from')
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Tarif Angkutan');
        $sheet->fromArray([['No', 'Rute', 'Unit', 'Borongan Supir/Ton', 'Uang Jalan', 'Harga/Ton', 'Keterangan', 'Berlaku Mulai']], null, 'A1');
        $row = 2;
        foreach ($rates as $rate) {
            $labels = $rate->units->pluck('unit_label')->implode(' ; ');
            $sheet->fromArray([[
                $rate->source_row,
                $rate->route->name,
                $labels,
                $rate->driver_rate_per_ton,
                $rate->travel_allowance,
                $rate->sale_rate_per_ton,
                $rate->notes,
                optional($rate->effective_from)->format('Y-m-d'),
            ]], null, "A{$row}");
            $row++;
        }

        return $spreadsheet;
    }

    public function writer(Spreadsheet $spreadsheet): Xlsx
    {
        return new Xlsx($spreadsheet);
    }

    private function findHeaderRow(array $rows): ?int
    {
        foreach ($rows as $rowNumber => $row) {
            $values = array_map(fn ($value) => $this->normalizeHeader($value), $row);
            if (in_array('rute', $values, true) && in_array('unit', $values, true)) {
                return $rowNumber;
            }
        }

        return null;
    }

    private function headers(array $row): array
    {
        $headers = [];
        foreach ($row as $column => $value) {
            $normalized = $this->normalizeHeader($value);
            if ($normalized !== '') {
                $headers[$normalized] = $column;
            }
        }

        return $headers;
    }

    private function normalizeHeader($value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', trim((string) $value)));
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->stringValue($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function stringValue($value): string
    {
        return trim((string) ($value ?? ''));
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
        $value = preg_replace('/[^0-9,\.\-]/', '', $value);
        if ($value === '' || $value === '-') {
            return null;
        }
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($lastComma !== false) {
            $decimals = strlen($value) - $lastComma - 1;
            $value = $decimals <= 2 ? str_replace(',', '.', $value) : str_replace(',', '', $value);
        } elseif (substr_count($value, '.') > 1) {
            $value = str_replace('.', '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function splitUnits(string $unitText): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\s*;\s*/', $unitText) ?: [])));
    }

    private function resolveAssetId(int $businessId, string $unitLabel): ?int
    {
        $asset = Asset::where('business_id', $businessId)
            ->where(function ($query) use ($unitLabel) {
                $query->whereRaw('LOWER(name) = ?', [strtolower($unitLabel)])
                    ->orWhereRaw('LOWER(asset_code) = ?', [strtolower($unitLabel)]);
            })
            ->orderByRaw('CASE WHEN LOWER(name) = ? THEN 0 ELSE 1 END', [strtolower($unitLabel)])
            ->first();

        return $asset?->id;
    }
}
