<?php

namespace Modules\AssetManagement\Services;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AssetManagement\Entities\Asset;
use Modules\AssetManagement\Entities\HeavyEquipmentRoute;
use Modules\AssetManagement\Entities\HeavyEquipmentTrip;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class HeavyEquipmentSpbImportService
{
    public function preview(UploadedFile $file, int $businessId): array
    {
        return $this->previewPath($file->getRealPath(), $file->getClientOriginalName(), $businessId);
    }

    public function previewPath(string $path, string $originalName, int $businessId, ?callable $progress = null): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new \InvalidArgumentException('File SPB harus berformat XLSX, XLS, atau CSV.');
        }

        $spreadsheet = IOFactory::load($path);
        $fileHash = @hash_file('sha256', $path) ?: Str::random(64);
        $rows = [];
        $sheets = 0;
        $sourceSheets = [];
        $totalWorkbookRows = 0;
        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $totalWorkbookRows += (int) $worksheet->getHighestRow();
        }
        $processedWorkbookRows = 0;

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $sheets++;
            $sheetRows = $worksheet->toArray(null, true, true, true);
            $sourceSheets[] = [
                'title' => $worksheet->getTitle(),
                'rows' => $worksheet->toArray(null, false, false, true),
            ];
            $headerRow = $this->findHeaderRow($sheetRows);
            if ($headerRow === null) {
                foreach ($sheetRows as $rowNumber => $_rawRow) {
                    $processedWorkbookRows++;
                    if ($progress && ($processedWorkbookRows % 25 === 0 || $processedWorkbookRows >= $totalWorkbookRows)) {
                        $progress($processedWorkbookRows, $totalWorkbookRows, $worksheet->getTitle(), $rowNumber);
                    }
                }
                continue;
            }

            $headers = $this->headerMap($sheetRows[$headerRow] ?? []);
            if (isset($sheetRows[$headerRow + 1])) {
                foreach ($this->headerMap($sheetRows[$headerRow + 1]) as $key => $columns) {
                    $headers[$key] = array_values(array_unique(array_merge($headers[$key] ?? [], $columns)));
                }
            }
            $isReconciliation = isset($headers['nopolisi']) && (isset($headers['pks']) || isset($headers['pabrik']) || isset($headers['oa']));
            foreach ($sheetRows as $rowNumber => $rawRow) {
                $processedWorkbookRows++;
                if ($progress && ($processedWorkbookRows % 25 === 0 || $processedWorkbookRows >= $totalWorkbookRows)) {
                    $progress($processedWorkbookRows, $totalWorkbookRows, $worksheet->getTitle(), $rowNumber);
                }
                if ($rowNumber <= $headerRow || $this->rowIsEmpty($rawRow)) {
                    continue;
                }

                $candidate = $isReconciliation
                    ? $this->parseReconciliationRow($rawRow, $headers)
                    : $this->parseLoadingRow($rawRow, $headers);
                if ($candidate === null) {
                    continue;
                }
                if ($isReconciliation) {
                    $candidate['claim_rate_per_kg'] = $this->claimRateFromFormula($worksheet, $rowNumber) ?? $candidate['claim_rate_per_kg'];
                }

                $candidate['source_workbook'] = $originalName;
                $candidate['source_sheet'] = $worksheet->getTitle();
                $candidate['source_row'] = $rowNumber;
                $candidate['source_fingerprint'] = hash('sha256', $fileHash.'|'.$worksheet->getTitle().'|'.$rowNumber.'|'.json_encode($rawRow));
                $candidate['asset_id'] = $this->resolveAssetId($businessId, $candidate['asset_label'] ?? '');
                $candidate['driver_id'] = $this->resolveDriverId($businessId, $candidate['driver_label'] ?? '');
                $candidate['route_id'] = $this->resolveRouteId($businessId, $candidate['route_name'] ?? '');
                $candidate['source_asset_label'] = $candidate['asset_label'] ?? '';
                $candidate['source_driver_label'] = $candidate['driver_label'] ?? '';
                $candidate['row_errors'] = [];
                $candidate['row_error_fields'] = [];
                $rows[] = $candidate;
            }
        }

        $preview = $this->refreshPreview($rows, $businessId, $sheets);
        $preview['analysis_total_rows'] = $totalWorkbookRows;
        if ($rows === []) {
            $preview['fatal_error'] = 'Tidak ada baris SPB yang dapat dibaca dari workbook.';
        }
        $preview['source_snapshot'] = [
            'sheets' => $sourceSheets,
            'captured_at' => now()->toIso8601String(),
        ];

        return $preview;
    }

    public function updatePreviewRow(array $rows, int $rowIndex, array $changes, int $businessId, int $sheetCount = 0): array
    {
        if (! array_key_exists($rowIndex, $rows)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'row' => 'Baris preview tidak ditemukan. Muat ulang workbook dan coba lagi.',
            ]);
        }

        foreach ($rows as &$row) {
            $row['source_asset_label'] = $row['source_asset_label'] ?? ($row['asset_label'] ?? '');
            $row['source_driver_label'] = $row['source_driver_label'] ?? ($row['driver_label'] ?? '');
        }
        unset($row);

        $target = $rows[$rowIndex];
        $assetGroup = $this->normalizeGroupLabel($target['source_asset_label'] ?? '');
        $driverGroup = $this->canonicalDriverName($target['source_driver_label'] ?? '');
        $editableFields = [
            'ticket_no', 'trip_date', 'load_date', 'unload_date', 'gross_tons', 'tare_tons',
            'actual_tons', 'received_tons', 'billable_tons', 'sale_rate_per_ton', 'origin',
            'destination', 'route_name', 'variance_kg', 'tolerance_kg', 'claim_rate_per_kg',
            'other_deduction_total', 'rate_override_reason',
        ];
        foreach ($editableFields as $field) {
            if (array_key_exists($field, $changes)) {
                $rows[$rowIndex][$field] = $changes[$field];
            }
        }

        $assetId = $changes['asset_id'] ?? $this->resolveAssetId($businessId, (string) ($changes['asset_label'] ?? ''));
        $driverId = $changes['driver_id'] ?? $this->resolveDriverId($businessId, (string) ($changes['driver_label'] ?? ''));
        $assetLabel = trim((string) ($changes['asset_label'] ?? ''));
        $driverLabel = trim((string) ($changes['driver_label'] ?? ''));

        foreach ($rows as $index => &$row) {
            $sameAssetSource = $assetGroup !== '' && $this->normalizeGroupLabel($row['source_asset_label'] ?? '') === $assetGroup;
            $sameDriverSource = $driverGroup !== '' && $this->canonicalDriverName($row['source_driver_label'] ?? '') === $driverGroup;

            if ($index === $rowIndex || $sameAssetSource) {
                $row['asset_id'] = $assetId ? (int) $assetId : null;
                $row['asset_label'] = $assetLabel;
            }
            if ($index === $rowIndex || $sameDriverSource) {
                $row['driver_id'] = $driverId ? (int) $driverId : null;
                $row['driver_label'] = $driverLabel;
            }
        }
        unset($row);

        return $this->refreshPreview($rows, $businessId, $sheetCount);
    }

    private function refreshPreview(array $rows, int $businessId, int $sheetCount = 0): array
    {
        $errors = [];
        $logicalRows = [];
        foreach ($rows as $index => &$row) {
            $row['row_errors'] = [];
            $row['row_error_fields'] = [];
            $row['source_asset_label'] = $row['source_asset_label'] ?? ($row['asset_label'] ?? '');
            $row['source_driver_label'] = $row['source_driver_label'] ?? ($row['driver_label'] ?? '');

            if (HeavyEquipmentTrip::where('business_id', $businessId)
                ->where('source_fingerprint', $row['source_fingerprint'] ?? null)
                ->exists()) {
                $this->addRowError($row, 'row', 'Baris workbook ini sudah pernah diimpor berdasarkan fingerprint sumber.');
            }
            if (! empty($row['ticket_no']) && HeavyEquipmentTrip::where('business_id', $businessId)
                ->where('ticket_no', $row['ticket_no'])
                ->exists()) {
                $this->addRowError($row, 'ticket_no', 'Nomor tiket ini sudah pernah digunakan pada SPB.');
            }

            if (empty($row['asset_id']) || ! Asset::where('business_id', $businessId)->whereKey($row['asset_id'])->exists()) {
                $row['asset_id'] = null;
                $this->addRowError($row, 'asset_id', 'Kendaraan/unit belum cocok dengan Assets. Pilih unit yang tersedia atau tambahkan unit baru.');
            }
            $validDriver = ! empty($row['driver_id']) && DB::table('users')->where('business_id', $businessId)
                ->where('user_type', 'user')->whereNull('deleted_at')->where('id', $row['driver_id'])->exists();
            if (! $validDriver) {
                $row['driver_id'] = null;
            }
            $row['driver_match'] = $this->driverMatch($businessId, (string) ($row['source_driver_label'] ?? ''), $validDriver ? $row['driver_id'] : null);
            if (! $validDriver) {
                $this->addRowError($row, 'driver_id', $this->driverSelectionError($row['driver_match'], (string) ($row['source_driver_label'] ?? '')));
            }
            if (empty($row['trip_date'])) {
                $this->addRowError($row, 'trip_date', 'Tanggal trip tidak terbaca.');
            }
            if (($row['actual_tons'] ?? 0) <= 0) {
                $this->addRowError($row, 'actual_tons', 'Tonase aktual tidak valid.');
            }

            $logicalKey = $this->logicalKey($row);
            if (isset($logicalRows[$logicalKey]) && $logicalRows[$logicalKey]['sheet'] !== ($row['source_sheet'] ?? '')) {
                $this->addRowError($row, 'row', "Duplikat logis dari sheet {$logicalRows[$logicalKey]['sheet']} baris {$logicalRows[$logicalKey]['row']} tidak akan diimpor dua kali.");
            } else {
                $logicalRows[$logicalKey] = [
                    'sheet' => $row['source_sheet'] ?? '',
                    'row' => $row['source_row'] ?? ($index + 1),
                ];
            }

            foreach ($row['row_errors'] as $error) {
                $errors[] = "Sheet {$row['source_sheet']} baris {$row['source_row']}: {$error}";
            }
        }
        unset($row);

        return [
            'rows' => array_values($rows),
            'errors' => array_values(array_unique($errors)),
            'summary' => [
                'sheets' => $sheetCount,
                'source_rows' => count($rows),
                'ready_rows' => count(array_filter($rows, fn ($row) => $row['row_errors'] === [])),
                'error_rows' => count(array_filter($rows, fn ($row) => $row['row_errors'] !== [])),
                'route_required_rows' => count(array_filter($rows, fn ($row) => empty($row['route_id']) && trim((string) ($row['route_name'] ?? '')) === '')),
            ],
        ];
    }

    private function addRowError(array &$row, string $field, string $message): void
    {
        $row['row_errors'][] = $message;
        $row['row_error_fields'][$field][] = $message;
    }

    private function normalizeGroupLabel(?string $label): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $label)));
    }

    public function canonicalDriverName(?string $label): string
    {
        $normalized = mb_strtolower(trim((string) $label), 'UTF-8');
        $normalized = preg_replace('/[^\pL\pN]+/u', ' ', $normalized) ?? '';
        $tokens = array_values(array_filter(preg_split('/\s+/u', trim($normalized)) ?: []));
        while ($tokens !== [] && in_array($tokens[0], ['mr', 'mrs', 'miss', 'ms'], true)) {
            array_shift($tokens);
        }

        return implode(' ', $tokens);
    }

    public function prepareForImport(array $row, int $businessId, ?int $defaultRouteId = null): array
    {
        if (empty($row['route_id']) && $defaultRouteId) {
            $row['route_id'] = $defaultRouteId;
        }
        if (empty($row['route_id']) && ! empty($row['route_name'])) {
            $route = HeavyEquipmentRoute::firstOrCreate(
                ['business_id' => $businessId, 'name' => $row['route_name']],
                [
                    'origin' => $row['origin'] ?? null,
                    'destination' => $row['destination'] ?? null,
                    'is_active' => true,
                ]
            );
            $row['route_id'] = $route->id;
            if ($route->wasRecentlyCreated) {
                $row['_created_route_id'] = (int) $route->id;
            }
        }

        return $row;
    }

    private function parseLoadingRow(array $row, array $headers): ?array
    {
        $ticket = $this->value($row, $this->findColumn($headers, ['notiket', 'ticket', 'nomortiket']));
        $plate = $this->value($row, $this->findColumn($headers, ['nopolisi', 'nomorpolisi', 'unit']));
        $date = $this->dateValue($this->value($row, $this->findColumn($headers, ['tanggal', 'tgl'])));
        $gross = $this->numberValue($this->value($row, $this->findColumnContaining($headers, 'gross')));
        $tare = $this->numberValue($this->value($row, $this->findColumnContaining($headers, 'tare')));
        $net = $this->numberValue($this->value($row, $this->findColumnContaining($headers, 'nett')));
        $rate = $this->numberValue($this->value($row, $this->findColumn($headers, ['harga', 'hargaton', 'rate'])));
        $driver = $this->value($row, $this->findColumn($headers, ['keterangan', 'driver', 'pengemudi', 'operator', 'sopir']));

        if ($plate === '') {
            return null;
        }

        $actual = $net;
        if (($actual === null || $actual <= 0) && $gross !== null && $tare !== null) {
            $actual = round($gross - $tare, 4);
        }

        return [
            'ticket_no' => $ticket ?: null,
            'asset_label' => $plate,
            'driver_label' => $driver,
            'trip_date' => $date,
            'load_date' => $date,
            'unload_date' => null,
            'gross_tons' => $gross,
            'tare_tons' => $tare,
            'actual_tons' => $actual,
            'received_tons' => $actual,
            'billable_tons' => $actual,
            'sale_rate_per_ton' => $rate,
            'origin' => null,
            'destination' => null,
            'route_name' => null,
            'variance_kg' => 0,
            'tolerance_kg' => 0,
            'claim_rate_per_kg' => 0,
            'other_deduction_total' => 0,
            'rate_override_reason' => $rate !== null ? 'Tarif dari workbook SPB.' : null,
        ];
    }

    private function parseReconciliationRow(array $row, array $headers): ?array
    {
        $plate = $this->value($row, $this->findColumn($headers, ['nopolisi', 'nomorpolisi', 'unit']));
        $origin = $this->value($row, $this->findColumn($headers, ['pks', 'asal', 'muatdari']));
        $destination = $this->value($row, $this->findColumn($headers, ['pabrik', 'tujuan', 'bongkarke']));
        $received = $this->numberValue($this->value($row, $this->findColumn($headers, ['diterima', 'received', 'nett', 'netto'])));
        $rate = $this->numberValue($this->value($row, $this->findColumn($headers, ['oa', 'hargaton', 'harga', 'rate'])));
        $driver = $this->value($row, $this->findColumn($headers, ['driver', 'pengemudi', 'operator', 'sopir']));
        if ($driver === '') {
            $driver = $this->trailingText($row);
        }
        $dates = [];
        foreach ($this->findColumnsContaining($headers, ['tgl', 'tanggal', 'muat', 'bongkar']) as $column) {
            $parsed = $this->dateValue($this->value($row, $column));
            if ($parsed !== null) {
                $dates[] = $parsed;
            }
        }
        $dates = array_values(array_unique($dates));
        $variance = $this->numberValue($this->value($row, $this->findColumn($headers, ['selisih', 'plusminus', 'minus'])))
            ?? $this->numberValue($row['M'] ?? null) ?? 0;
        $tolerance = $this->numberValue($this->value($row, $this->findColumn($headers, ['toleransi', 'tolerance'])))
            ?? $this->numberValue($row['N'] ?? null) ?? 0;
        $otherDeduction = $this->numberValue($this->value($row, $this->findColumn($headers, ['potongan', 'deduction'])))
            ?? $this->numberValue($row['R'] ?? null) ?? 0;
        $ticket = $this->value($row, $this->findColumn($headers, ['notiket', 'ticket', 'nomor']));

        if ($plate === '') {
            return null;
        }

        $routeName = trim($origin.' - '.$destination, ' -');
        return [
            'ticket_no' => $ticket ?: null,
            'asset_label' => $plate,
            'driver_label' => $driver,
            'trip_date' => $dates[0] ?? null,
            'load_date' => $dates[0] ?? null,
            'unload_date' => $dates[1] ?? null,
            'gross_tons' => null,
            'tare_tons' => null,
            'actual_tons' => $received,
            'received_tons' => $received,
            'billable_tons' => $received,
            'sale_rate_per_ton' => $rate,
            'origin' => $origin ?: null,
            'destination' => $destination ?: null,
            'route_name' => $routeName ?: null,
            'variance_kg' => $variance,
            'tolerance_kg' => $tolerance,
            'claim_rate_per_kg' => 0,
            'other_deduction_total' => $otherDeduction,
            'rate_override_reason' => $rate !== null ? 'Tarif OA dari workbook SPB.' : null,
        ];
    }

    private function findHeaderRow(array $rows): ?int
    {
        foreach ($rows as $rowNumber => $row) {
            $headers = array_keys($this->headerMap($row));
            if (in_array('nopolisi', $headers, true) || in_array('notiket', $headers, true)) {
                return $rowNumber;
            }
        }

        return null;
    }

    private function headerMap(array $row): array
    {
        $map = [];
        foreach ($row as $column => $value) {
            $key = $this->normalize($value, true);
            if ($key !== '') {
                $map[$key][] = $column;
            }
        }

        return $map;
    }

    private function findColumn(array $headers, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! empty($headers[$key])) {
                return $headers[$key][0];
            }
        }

        return null;
    }

    private function findColumnContaining(array $headers, string $needle): ?string
    {
        foreach ($headers as $key => $columns) {
            if (str_contains($key, $needle)) {
                return $columns[0];
            }
        }

        return null;
    }

    private function findColumnsContaining(array $headers, array $needles): array
    {
        $columns = [];
        foreach ($headers as $key => $values) {
            foreach ($needles as $needle) {
                if (str_contains($key, $needle)) {
                    $columns = array_merge($columns, $values);
                    break;
                }
            }
        }

        return array_values(array_unique($columns));
    }

    private function resolveAssetId(int $businessId, string $label): ?int
    {
        $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', $label)));
        if ($normalized === '') {
            return null;
        }

        return Asset::where('business_id', $businessId)->get()->filter(function ($asset) use ($normalized) {
            $name = strtolower(trim((string) preg_replace('/\s+/', ' ', $asset->name)));
            $code = strtolower(trim((string) preg_replace('/\s+/', ' ', $asset->asset_code)));
            return $normalized === $name || $normalized === $code;
        })->value('id');
    }

    private function resolveDriverId(int $businessId, string $label): ?int
    {
        $matches = $this->driverCandidates($businessId, $label);
        $exactMatches = array_values(array_filter($matches, fn (array $candidate) => $candidate['exact']));

        if (count($exactMatches) !== 1) {
            return null;
        }

        return (int) $exactMatches[0]['id'];
    }

    private function driverMatch(int $businessId, string $sourceLabel, $selectedDriverId = null): array
    {
        $candidates = $this->driverCandidates($businessId, $sourceLabel);
        $top = $candidates[0] ?? null;
        $selectedId = $selectedDriverId === null ? null : (int) $selectedDriverId;
        $exactCandidates = array_values(array_filter($candidates, fn (array $candidate) => $candidate['exact']));

        foreach ($candidates as &$candidate) {
            $candidate['selected'] = $selectedId !== null && (int) $candidate['id'] === $selectedId;
        }
        unset($candidate);
        $visibleCandidates = count($exactCandidates) > 5 ? $exactCandidates : array_slice($candidates, 0, 5);

        return [
            'source_label' => $sourceLabel,
            'confidence_percent' => $top['confidence_percent'] ?? 0,
            'requires_review' => $selectedId === null && $top !== null && $top['confidence_percent'] < 60,
            'selected_driver_id' => $selectedId,
            'exact_candidate_count' => count($exactCandidates),
            'candidates' => $visibleCandidates,
        ];
    }

    private function driverCandidates(int $businessId, string $label): array
    {
        $needle = $this->canonicalDriverName($label);
        if ($needle === '') {
            return [];
        }

        $users = DB::table('users')->where('business_id', $businessId)
            ->where('user_type', 'user')
            ->whereNull('deleted_at')
            ->select('id', 'surname', 'first_name', 'last_name', 'username')->get();
        $candidates = $users->map(function ($user) use ($needle) {
            $name = trim(implode(' ', array_filter([$user->surname, $user->first_name, $user->last_name])));
            $canonicalName = $this->canonicalDriverName($name);
            $matchName = $canonicalName !== '' ? $canonicalName : $this->canonicalDriverName((string) ($user->username ?? ''));
            $displayName = $name !== '' ? $name : (string) ($user->username ?? 'User #'.$user->id);
            $confidence = $this->nameSimilarity($needle, $matchName);

            return [
                'id' => (int) $user->id,
                'name' => $displayName,
                'username' => (string) ($user->username ?? ''),
                'canonical_name' => $matchName,
                'confidence_percent' => $confidence,
                'exact' => $matchName !== '' && $matchName === $needle,
            ];
        })->sort(function (array $left, array $right) {
            return [$right['confidence_percent'], $left['id']] <=> [$left['confidence_percent'], $right['id']];
        })->values()->all();

        $duplicateNames = array_count_values(array_filter(array_column($candidates, 'canonical_name')));
        foreach ($candidates as &$candidate) {
            $candidate['label'] = $candidate['name'];
            if (! empty($candidate['username']) && $this->canonicalDriverName($candidate['name']) !== $this->canonicalDriverName($candidate['username'])) {
                $candidate['label'] .= ' ('.$candidate['username'].')';
            } elseif (($duplicateNames[$candidate['canonical_name']] ?? 0) > 1) {
                $candidate['label'] .= ' · User #'.$candidate['id'];
            }
            unset($candidate['canonical_name'], $candidate['username']);
        }
        unset($candidate);

        return $candidates;
    }

    private function nameSimilarity(string $needle, string $candidate): int
    {
        if ($candidate === '') {
            return 0;
        }
        if ($needle === $candidate) {
            return 100;
        }

        similar_text($needle, $candidate, $characterSimilarity);
        $needleTokens = array_values(array_unique(explode(' ', $needle)));
        $candidateTokens = array_values(array_unique(explode(' ', $candidate)));
        $commonTokens = count(array_intersect($needleTokens, $candidateTokens));
        $tokenSimilarity = ($needleTokens === [] || $candidateTokens === [])
            ? 0
            : (200 * $commonTokens / (count($needleTokens) + count($candidateTokens)));

        return (int) round(max($characterSimilarity, $tokenSimilarity));
    }

    private function driverSelectionError(array $match, string $sourceLabel): string
    {
        $candidates = $match['candidates'] ?? [];
        if ($candidates === []) {
            return 'Nama sopir "'.$sourceLabel.'" belum cocok dengan Users pada bisnis ini. Pilih sopir yang tersedia atau tambahkan sopir baru.';
        }

        $exactCandidates = array_values(array_filter($candidates, fn (array $candidate) => ! empty($candidate['exact'])));
        $exactCount = (int) ($match['exact_candidate_count'] ?? count($exactCandidates));
        if ($exactCount > 1) {

            return 'Ditemukan '.$exactCount.' User dengan nama kanonis sama: '
                .implode(', ', array_column($exactCandidates, 'label')).'. Pilih User yang benar sebelum impor.';
        }

        $top = $candidates[0];
        $confidence = (int) ($top['confidence_percent'] ?? 0);
        $review = $confidence < 60 ? ' Perlu pemeriksaan manual karena kemiripan di bawah 60%.' : '';

        return 'Kandidat terdekat: '.$top['label'].' (kemiripan '.$confidence.'%). Pilih dan setujui kandidat sopir sebelum impor.'.$review;
    }

    private function resolveRouteId(int $businessId, string $label): ?int
    {
        if (trim($label) === '') {
            return null;
        }

        return HeavyEquipmentRoute::where('business_id', $businessId)
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($label))])->value('id');
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
            $value = $lastComma > $lastDot
                ? str_replace(',', '.', str_replace('.', '', $value))
                : str_replace(',', '', $value);
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

    private function trailingText(array $row): string
    {
        foreach ($row as $column => $value) {
            if (Coordinate::columnIndexFromString($column) < 20) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '' && preg_match('/[A-Za-z]/', $value) && ! $this->dateValue($value)) {
                return $value;
            }
        }

        return '';
    }

    private function logicalKey(array $row): string
    {
        return hash('sha256', implode('|', [
            strtolower(trim((string) ($row['ticket_no'] ?? ''))),
            strtolower(trim((string) ($row['asset_label'] ?? ''))),
            (string) ($row['trip_date'] ?? ''),
            strtolower(trim((string) ($row['origin'] ?? ''))),
            strtolower(trim((string) ($row['destination'] ?? ''))),
            (string) ($row['actual_tons'] ?? ''),
            (string) ($row['sale_rate_per_ton'] ?? ''),
            strtolower(trim((string) ($row['driver_label'] ?? ''))),
        ]));
    }

    private function claimRateFromFormula($worksheet, int $rowNumber): ?float
    {
        $highestColumn = Coordinate::columnIndexFromString($worksheet->getHighestColumn());
        for ($column = 1; $column <= $highestColumn; $column++) {
            $formula = $worksheet->getCellByColumnAndRow($column, $rowNumber)->getValue();
            if (! is_string($formula) || ! str_starts_with($formula, '=')) {
                continue;
            }
            if (! preg_match('/\*\s*([0-9]+(?:\.[0-9]+)?)/', $formula, $match)) {
                continue;
            }
            $rate = (float) $match[1];
            if (preg_match('/[NO]\d+\s*\*/i', $formula)) {
                return $rate;
            }
        }

        return null;
    }

    private function normalize($value, bool $compact = false): string
    {
        $value = strtolower(trim((string) $value));
        return $compact ? preg_replace('/[^a-z0-9]+/', '', $value) : preg_replace('/\s+/', ' ', $value);
    }

}
