<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SolarImportPreviewService
{
    public function __construct(
        private SolarWorkbookService $workbookService,
        private SolarLedgerService $ledgerService
    ) {
    }

    public function refreshReferenceSuggestions(int $businessId, string $ledger, array $row): array
    {
        if (! in_array($ledger, SolarLedgerService::LEDGERS, true)) {
            return $row;
        }

        $assets = DB::table('assets')
            ->where('business_id', $businessId)
            ->select('id', 'name', 'asset_code')
            ->get();
        $drivers = DB::table('users')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->select('id', DB::raw("TRIM(CONCAT(COALESCE(surname, ''), ' ', COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) as full_name"))
            ->get()
            ->filter(fn ($driver) => trim((string) $driver->full_name) !== '')
            ->values();

        $this->extractReferenceNames($row, $assets, $drivers);
        $row['asset_suggestions'] = ! empty($row['asset_id']) || $this->matchAsset($assets, $row['unit'] ?? null)->count() === 1
            ? []
            : $this->referenceSuggestions($assets, $row['unit'] ?? null, $row['keterangan'] ?? '', 'asset');
        $row['driver_suggestions'] = ! empty($row['driver_id']) || $this->matchDriver($drivers, $row['operator'] ?? null)->count() === 1
            ? []
            : $this->referenceSuggestions($drivers, $row['operator'] ?? null, $row['keterangan'] ?? '', 'driver');

        return $row;
    }

    public function rebuild(int $businessId, string $batchId, string $path, array $dateOverrides = [], ?callable $progress = null): array
    {
        $progress && $progress('reading', 'Membaca informasi antrean dan menyiapkan workbook.', 10, null, null, ['stage_detail' => 'batch_lookup']);
        $referenceMappings = json_decode((string) DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->value('reference_mappings'), true) ?: [];
        $parsed = $this->workbookService->parse($path, $dateOverrides, function ($stage, $message, $percent, $current = null, $total = null, $context = []) use ($progress): void {
            $progress && $progress($stage, $message, $percent, $current, $total, $context);
        });
        $progress && $progress('matching', 'Mencocokkan baris dengan Asset, supir, Customer, dan ledger yang sudah ada.', 52, null, null, ['stage_detail' => 'matching_start']);
        $parsed = $this->prepareParsedRows(
            $businessId,
            $parsed,
            $referenceMappings,
            $progress
        );
        $progress && $progress('matching', 'Pencocokan selesai; menyusun hasil preview per sheet.', 68, null, null, [
            'rows' => array_sum(array_map('count', $parsed['rows'] ?? [])),
            'issues' => count($parsed['errors'] ?? []),
            'stage_detail' => 'matching_complete',
        ]);
        $usageRows = $parsed['rows']['usage'] ?? [];
        $usageErrors = array_values(array_filter($parsed['errors'] ?? [], fn ($error) => ($error['ledger'] ?? '') === 'usage'));
        $standardParsed = $parsed;
        unset($standardParsed['rows']['usage']);
        $standardParsed['errors'] = array_values(array_filter($parsed['errors'] ?? [], fn ($error) => ($error['ledger'] ?? '') !== 'usage'));
        $result = $this->ledgerService->reconciliation($businessId, $standardParsed);
        $usageResult = $this->ledgerService->usageReconciliation($businessId, $usageRows, $usageErrors);
        foreach ($usageResult['summary'] as $key => $count) {
            $result['summary'][$key] = ($result['summary'][$key] ?? 0) + $count;
        }
        $result['items'] = array_merge($result['items'], $usageResult['items']);
        $result['onlyExcel']['usage'] = $usageResult['onlyExcel']['usage'] ?? [];
        $result['sheets'] = $parsed['sheets'] ?? [];
        $result['warnings'] = $parsed['warnings'] ?? [];
        $progress && $progress('saving', 'Menyimpan hasil preview secara bertahap.', 72, 0, count($result['items'] ?? []), ['stage_detail' => 'preview_rows_start']);
        $this->replaceRows($businessId, $batchId, $result, $progress);
        DB::table('solar_import_batches')->where('id', $batchId)->where('business_id', $businessId)->update([
            'source_sheets' => json_encode($result['sheets'], JSON_UNESCAPED_UNICODE),
            'preview_warnings' => json_encode($result['warnings'] ?? [], JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        return $result;
    }

    public function referenceMappings(int $businessId, string $batchId, ?string $sheet = null): array
    {
        $rows = DB::table('solar_import_preview_rows')
            ->where('batch_id', $batchId)
            ->where('business_id', $businessId)
            ->when($sheet !== null && $sheet !== '', fn ($query) => $query->where('source_sheet', $sheet))
            ->orderBy('source_sheet')
            ->orderBy('source_row')
            ->get(['ledger', 'source_sheet', 'excel_payload']);

        $groups = ['asset' => [], 'driver' => []];
        foreach ($rows as $previewRow) {
            $payload = json_decode((string) $previewRow->excel_payload, true) ?: [];
            foreach (['asset' => $this->assetSourceValue($payload, $previewRow->ledger), 'driver' => $payload['operator'] ?? null] as $type => $value) {
                $value = $this->cleanReferenceValue($value);
                if ($value === '') {
                    continue;
                }
                $key = $this->normalizeReference($value);
                if (! isset($groups[$type][$key])) {
                    $groups[$type][$key] = [
                        'type' => $type,
                        'source_value' => $value,
                        'normalized_value' => $key,
                        'target_ids' => [],
                        'suggestions' => [],
                        'row_count' => 0,
                        'sheets' => [],
                    ];
                }
                $targetField = $type === 'asset' ? 'asset_id' : 'driver_id';
                $targetId = $payload[$targetField] ?? null;
                if ($targetId !== null && $targetId !== '') {
                    $groups[$type][$key]['target_ids'][(string) $targetId] = (int) $targetId;
                }
                foreach ($payload[$type === 'asset' ? 'asset_suggestions' : 'driver_suggestions'] ?? [] as $suggestion) {
                    if (! is_array($suggestion) || empty($suggestion['id']) || empty($suggestion['label'])) {
                        continue;
                    }
                    $suggestionId = (string) $suggestion['id'];
                    if (! isset($groups[$type][$key]['suggestions'][$suggestionId])) {
                        $groups[$type][$key]['suggestions'][$suggestionId] = [
                            'id' => (int) $suggestion['id'],
                            'label' => (string) $suggestion['label'],
                            'score' => (float) ($suggestion['score'] ?? 0),
                            'row_count' => 0,
                        ];
                    }
                    $groups[$type][$key]['suggestions'][$suggestionId]['row_count']++;
                }
                $groups[$type][$key]['row_count']++;
                $sheetName = trim((string) ($previewRow->source_sheet ?: strtoupper((string) $previewRow->ledger)));
                $groups[$type][$key]['sheets'][$sheetName] = ($groups[$type][$key]['sheets'][$sheetName] ?? 0) + 1;
            }
        }

        foreach ($groups as $type => $items) {
            $groups[$type] = array_values(array_map(function ($group) {
                $targetIds = array_values($group['target_ids']);
                $suggestions = array_values($group['suggestions']);
                usort($suggestions, fn ($left, $right) => $right['row_count'] <=> $left['row_count'] ?: $right['score'] <=> $left['score']);
                return [
                    'type' => $group['type'],
                    'source_value' => $group['source_value'],
                    'normalized_value' => $group['normalized_value'],
                    'target_id' => count($targetIds) === 1 ? $targetIds[0] : null,
                    'has_conflict' => count($targetIds) > 1,
                    'suggestions' => array_slice($suggestions, 0, 3),
                    'row_count' => $group['row_count'],
                    'sheets' => $group['sheets'],
                ];
            }, $items));
            usort($groups[$type], fn ($left, $right) => strcasecmp($left['source_value'], $right['source_value']));
        }

        return $groups;
    }

    public function saveReferenceMapping(
        int $businessId,
        string $batchId,
        string $type,
        string $sourceValue,
        ?int $targetId
    ): void {
        $sourceValue = $this->cleanReferenceValue($sourceValue);
        $key = $this->normalizeReference($sourceValue);

        DB::transaction(function () use ($businessId, $batchId, $type, $sourceValue, $key, $targetId) {
            $batch = DB::table('solar_import_batches')
                ->where('id', $batchId)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();
            if (! $batch) {
                abort(404, 'Batch import tidak ditemukan.');
            }

            $mappings = json_decode((string) ($batch->reference_mappings ?? '[]'), true) ?: [];
            $mappings[$type][$key] = [
                'source_value' => $sourceValue,
                'target_id' => $targetId,
            ];
            DB::table('solar_import_batches')->where('id', $batchId)->where('business_id', $businessId)->update([
                'reference_mappings' => json_encode($mappings, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

            $previewRows = DB::table('solar_import_preview_rows')
                ->where('batch_id', $batchId)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->get(['id', 'ledger', 'excel_payload']);
            $updates = [];
            foreach ($previewRows as $previewRow) {
                $payload = json_decode((string) $previewRow->excel_payload, true) ?: [];
                $rowValue = $type === 'asset'
                    ? $this->assetSourceValue($payload, $previewRow->ledger)
                    : ($payload['operator'] ?? null);
                if ($rowValue === null || $this->normalizeReference($rowValue) !== $key) {
                    continue;
                }

                $payload[$type === 'asset' ? 'asset_id' : 'driver_id'] = $targetId;
                $payload[$type === 'asset' ? 'asset_match_status' : 'driver_match_status'] = $targetId === null ? 'unmatched' : 'matched';
                $updates[] = [
                    'id' => $previewRow->id,
                    'excel_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ];
            }
            foreach (array_chunk($updates, 250) as $chunk) {
                DB::table('solar_import_preview_rows')->upsert($chunk, ['id'], ['excel_payload', 'updated_at']);
            }
        });
    }

    public function summary(string $batchId, int $businessId): array
    {
        $counts = DB::table('solar_import_preview_rows')
            ->where('batch_id', $batchId)
            ->where('business_id', $businessId)
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $grouped = DB::table('solar_import_preview_rows')
            ->where('batch_id', $batchId)
            ->where('business_id', $businessId)
            ->select('ledger', 'source_sheet', 'status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('ledger', 'source_sheet', 'status')
            ->get()
            ->groupBy(fn ($row) => ($row->source_sheet ?: strtoupper($row->ledger)));
        $batch = DB::table('solar_import_batches')->where('id', $batchId)->where('business_id', $businessId)->first();
        $definitions = json_decode((string) ($batch->source_sheets ?? '[]'), true) ?: [];
        $knownNames = array_column($definitions, 'sheet_key');
        foreach ($grouped as $name => $items) {
            if (! in_array((string) $name, $knownNames, true)) {
                $definitions[] = ['sheet_key' => (string) $name, 'title' => (string) $name, 'ledger' => $items->first()->ledger];
            }
        }
        $sheets = collect($definitions)->map(function ($definition) use ($grouped, $batchId, $businessId) {
            $sheet = (string) ($definition['sheet_key'] ?? $definition['title'] ?? '');
            $items = $grouped->get($sheet, collect());
            $counts = $items->groupBy('status')->map(fn ($rows) => (int) $rows->sum('aggregate'));
            $importable = DB::table('solar_import_preview_rows')
                ->where('batch_id', $batchId)
                ->where('business_id', $businessId)
                ->where('source_sheet', $sheet)
                ->where('is_importable', true)
                ->count();
            return [
                'sheet_key' => $sheet,
                'title' => (string) ($definition['title'] ?? $sheet),
                'ledger' => (string) ($definition['ledger'] ?? $items->first()->ledger ?? '-'),
                'match' => (int) ($counts['match'] ?? 0),
                'only_excel' => (int) ($counts['only_excel'] ?? 0),
                'only_app' => (int) ($counts['only_app'] ?? 0),
                'different' => (int) ($counts['different'] ?? 0),
                'error' => (int) ($counts['error'] ?? 0),
                'importable' => (int) $importable,
                'total' => (int) $items->sum('aggregate'),
                'needs_check' => (int) ($counts['only_app'] ?? 0) + (int) ($counts['different'] ?? 0) + (int) ($counts['error'] ?? 0),
            ];
        })->values()->all();

        $total = array_sum(array_column($sheets, 'total'));
        $needsCheck = array_sum(array_column($sheets, 'needs_check'));
        $importable = array_sum(array_column($sheets, 'importable'));

        return [
            'match' => (int) ($counts['match'] ?? 0),
            'only_excel' => (int) $importable,
            'only_app' => (int) ($counts['only_app'] ?? 0),
            'different' => (int) ($counts['different'] ?? 0),
            'error' => (int) ($counts['error'] ?? 0),
            'total' => (int) $total,
            'importable' => (int) $importable,
            'needs_check' => (int) $needsCheck,
            'sheets' => $sheets,
            'warnings' => json_decode((string) ($batch->preview_warnings ?? '[]'), true) ?: [],
        ];
    }

    public function importableRows(string $batchId, int $businessId): array
    {
        return DB::table('solar_import_preview_rows')
            ->where('batch_id', $batchId)
            ->where('business_id', $businessId)
            ->where('is_importable', true)
            ->orderBy('ledger')
            ->orderBy('source_row')
            ->get()
            ->map(function ($row) {
                $payload = json_decode((string) $row->excel_payload, true) ?: [];
                $payload['ledger'] = $row->ledger;
                $payload['source_sheet'] = $row->source_sheet ?: ($payload['source_sheet'] ?? null);
                $payload['source_row'] = $row->source_row ?: ($payload['source_row'] ?? null);
                return $payload;
            })
            ->all();
    }

    public function result(string $batchId, int $businessId): array
    {
        $items = DB::table('solar_import_preview_rows')
            ->where('batch_id', $batchId)
            ->where('business_id', $businessId)
            ->orderBy('ledger')
            ->orderBy('source_row')
            ->get()
            ->map(function ($row) {
                return [
                    'ledger' => $row->ledger,
                    'source_sheet' => $row->source_sheet,
                    'row' => $row->source_row,
                    'date' => $row->normalized_date,
                    'status' => $row->status,
                    'differences' => json_decode((string) $row->differences, true) ?: [],
                    'excel' => json_decode((string) $row->excel_payload, true),
                    'app' => json_decode((string) $row->app_payload, true),
                    'matched_by' => $row->matched_by,
                    'balance_only' => (bool) $row->balance_only,
                    'raw_date' => $row->raw_date,
                ];
            })
            ->all();

        $summary = $this->summary($batchId, $businessId);
        return ['summary' => $summary, 'items' => $items, 'onlyExcel' => [
            'stock' => [], 'pool' => [], 'mess' => [], 'usage' => [],
        ]];
    }

    public function replaceRows(int $businessId, string $batchId, array $result, ?callable $progress = null): void
    {
        DB::transaction(function () use ($businessId, $batchId, $result, $progress) {
            DB::table('solar_import_preview_rows')
                ->where('batch_id', $batchId)
                ->where('business_id', $businessId)
                ->delete();

            $payloads = [];
            foreach ($result['items'] ?? [] as $item) {
                $status = (string) ($item['status'] ?? 'error');
                $excel = $item['excel'] ?? null;
                $rawDate = $excel['tanggal'] ?? null;
                if ($status === 'error') {
                    $rawDate = $item['raw_date'] ?? $rawDate;
                }
                $isImportable = $status === 'only_excel'
                    && is_array($excel)
                    && match (($item['ledger'] ?? '')) {
                        'stock' => array_key_exists('stock_in', $excel),
                        'pool', 'mess' => array_key_exists('qty_in', $excel),
                        'usage' => array_key_exists('quantity_gln', $excel),
                        default => false,
                    };
                $payloads[] = [
                    'batch_id' => $batchId,
                    'business_id' => $businessId,
                    'ledger' => $item['ledger'] ?? '-',
                    'source_sheet' => $item['source_sheet'] ?? ($excel['source_sheet'] ?? null),
                    'source_row' => $item['row'] ?? ($excel['source_row'] ?? null),
                    'source_column' => $excel['source_column'] ?? ($item['source_column'] ?? ''),
                    'raw_date' => is_scalar($rawDate) ? (string) $rawDate : null,
                    'normalized_date' => $item['date'] ?: ($excel['tanggal'] ?? null),
                    'status' => $status,
                    'matched_by' => $item['matched_by'] ?? null,
                    'balance_only' => (bool) ($item['balance_only'] ?? false),
                    'differences' => json_encode(array_values($item['differences'] ?? []), JSON_UNESCAPED_UNICODE),
                    'excel_payload' => json_encode($excel, JSON_UNESCAPED_UNICODE),
                    'app_payload' => json_encode($item['app'] ?? null, JSON_UNESCAPED_UNICODE),
                    'is_importable' => $isImportable,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            $total = count($payloads);
            $saved = 0;
            foreach (array_chunk($payloads, 500) as $chunk) {
                DB::table('solar_import_preview_rows')->insert($chunk);
                $saved += count($chunk);
                $progress && $progress('saving', 'Menyimpan hasil preview: '.$saved.' dari '.$total.' baris.', 72 + (int) floor(($saved / max(1, $total)) * 20), $saved, $total, ['stage_detail' => 'preview_rows', 'rows_saved' => $saved, 'rows_total' => $total]);
            }
        });
    }

    private function prepareParsedRows(int $businessId, array $parsed, array $referenceMappings = [], ?callable $progress = null): array
    {
        $assets = DB::table('assets')
            ->where('business_id', $businessId)
            ->select('id', 'name', 'asset_code')
            ->get();
        $drivers = DB::table('users')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->select('id', DB::raw("TRIM(CONCAT(COALESCE(surname, ''), ' ', COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) as full_name"))
            ->get()
            ->filter(fn ($driver) => trim((string) $driver->full_name) !== '')
            ->values();

        $rowTotal = array_sum(array_map('count', $parsed['rows'] ?? []));
        $this->prepareReferenceNames($parsed, $assets, $drivers, function ($current, $total) use ($progress): void {
            $progress && $progress('matching', 'Mencari nama Asset dan supir dari kolom Keterangan ('.$current.' dari '.$total.').', 54, $current, $total, ['stage_detail' => 'extract_references']);
        });

        $customers = DB::table('contacts')
            ->where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->select('id', 'name', 'supplier_business_name', 'contact_id', 'mobile', 'email')
            ->get();
        $byId = $customers->keyBy('id');
        $byName = [];
        foreach ($customers as $customer) {
            foreach ([$customer->name, $customer->supplier_business_name, $customer->contact_id, $customer->mobile, $customer->email] as $name) {
                $key = $this->normalizeReference($name);
                if ($key !== '') {
                    $byName[$key] = (int) $customer->id;
                }
            }
        }

        $customerProcessed = 0;
        $customerTotal = count($parsed['rows']['pool'] ?? []) + count($parsed['rows']['mess'] ?? []);
        foreach (['pool', 'mess'] as $ledger) {
            foreach ($parsed['rows'][$ledger] as $index => $row) {
                $customerProcessed++;
                if ($customerProcessed % 100 === 0 || $customerProcessed === $customerTotal) {
                    $progress && $progress('matching', 'Mencocokkan Customer: '.$customerProcessed.' dari '.$customerTotal.' baris.', 58, $customerProcessed, $customerTotal, ['ledger' => $ledger, 'row' => $row['source_row'] ?? null, 'stage_detail' => 'customer_matching']);
                }
                $reference = trim((string) ($row['customer_ref'] ?? ''));
                $customerId = null;
                if ($reference !== '') {
                    if (preg_match('/^C(\d+)\b/i', $reference, $matches) && $byId->has((int) $matches[1])) {
                        $customerId = (int) $matches[1];
                    } elseif (isset($byName[$this->normalizeReference($reference)])) {
                        $customerId = $byName[$this->normalizeReference($reference)];
                    } else {
                        $parsed['errors'][] = [
                            'ledger' => $ledger,
                            'row' => $row['source_row'],
                            'raw_date' => $row['tanggal'] ?? null,
                            'source_sheet' => $row['source_sheet'] ?? strtoupper($ledger),
                            'excel' => $row,
                            'message' => "Customer \"{$reference}\" tidak ditemukan pada business ini.",
                        ];
                        unset($parsed['rows'][$ledger][$index]);
                        continue;
                    }
                }
                $parsed['rows'][$ledger][$index]['customer_id'] = $customerId;
                unset($parsed['rows'][$ledger][$index]['customer_ref']);
            }
            $parsed['rows'][$ledger] = array_values($parsed['rows'][$ledger]);
        }

        $matchedProcessed = 0;
        $matchedTotal = array_sum(array_map('count', $parsed['rows'] ?? []));
        foreach (array_merge(SolarLedgerService::LEDGERS, ['usage']) as $ledger) {
            foreach ($parsed['rows'][$ledger] ?? [] as $index => $row) {
                $matchedProcessed++;
                if ($matchedProcessed % 100 === 0 || $matchedProcessed === $matchedTotal) {
                    $progress && $progress('matching', 'Mencocokkan Asset dan supir: '.$matchedProcessed.' dari '.$matchedTotal.' baris.', 62, $matchedProcessed, $matchedTotal, ['ledger' => $ledger, 'row' => $row['source_row'] ?? null, 'stage_detail' => 'asset_driver_matching']);
                }
                $assetValue = $this->assetSourceValue($row, $ledger);
                $assetMatches = $this->matchAsset($assets, $assetValue);
                $parsed['rows'][$ledger][$index]['asset_id'] = $assetMatches->count() === 1 ? (int) $assetMatches->first()->id : null;
                $parsed['rows'][$ledger][$index]['asset_match_status'] = $assetMatches->count() === 1
                    ? 'matched'
                    : ($assetMatches->count() > 1 ? 'ambiguous' : 'unmatched');
                $parsed['rows'][$ledger][$index]['asset_suggestions'] = $assetMatches->count() === 1
                    ? []
                    : $this->referenceSuggestions($assets, $assetValue, $row['keterangan'] ?? '', 'asset');

                if ($ledger !== 'usage') {
                    $driverMatches = $this->matchDriver($drivers, $row['operator'] ?? null);
                    $parsed['rows'][$ledger][$index]['driver_id'] = $driverMatches->count() === 1 ? (int) $driverMatches->first()->id : null;
                    $parsed['rows'][$ledger][$index]['driver_match_status'] = $driverMatches->count() === 1
                        ? 'matched'
                        : ($driverMatches->count() > 1 ? 'ambiguous' : 'unmatched');
                    $parsed['rows'][$ledger][$index]['driver_suggestions'] = $driverMatches->count() === 1
                        ? []
                        : $this->referenceSuggestions($drivers, $row['operator'] ?? null, $row['keterangan'] ?? '', 'driver');
                }
            }
        }

        foreach ($parsed['errors'] as $index => $error) {
            $ledger = (string) ($error['ledger'] ?? '');
            if (! in_array($ledger, SolarLedgerService::LEDGERS, true) || ! is_array($error['excel'] ?? null)) {
                continue;
            }
            $this->extractReferenceNames($error['excel'], $assets, $drivers);
            $assetMatches = $this->matchAsset($assets, $this->assetSourceValue($error['excel'], $ledger));
            $parsed['errors'][$index]['excel']['asset_id'] = $assetMatches->count() === 1 ? (int) $assetMatches->first()->id : null;
            $parsed['errors'][$index]['excel']['asset_match_status'] = $assetMatches->count() === 1
                ? 'matched'
                : ($assetMatches->count() > 1 ? 'ambiguous' : 'unmatched');
            $parsed['errors'][$index]['excel']['asset_suggestions'] = $assetMatches->count() === 1
                ? []
                : $this->referenceSuggestions($assets, $this->assetSourceValue($error['excel'], $ledger), $error['excel']['keterangan'] ?? '', 'asset');
            $driverMatches = $this->matchDriver($drivers, $error['excel']['operator'] ?? null);
            $parsed['errors'][$index]['excel']['driver_id'] = $driverMatches->count() === 1 ? (int) $driverMatches->first()->id : null;
            $parsed['errors'][$index]['excel']['driver_match_status'] = $driverMatches->count() === 1
                ? 'matched'
                : ($driverMatches->count() > 1 ? 'ambiguous' : 'unmatched');
            $parsed['errors'][$index]['excel']['driver_suggestions'] = $driverMatches->count() === 1
                ? []
                : $this->referenceSuggestions($drivers, $error['excel']['operator'] ?? null, $error['excel']['keterangan'] ?? '', 'driver');
        }

        $this->applyReferenceMappings($parsed, $referenceMappings);

        return $this->ledgerService->decorateSourceKeys($parsed);
    }

    private function prepareReferenceNames(array &$parsed, $assets, $drivers, ?callable $progress = null): void
    {
        $total = array_sum(array_map('count', $parsed['rows'] ?? []));
        $current = 0;
        foreach (SolarLedgerService::LEDGERS as $ledger) {
            foreach ($parsed['rows'][$ledger] ?? [] as $index => $row) {
                $current++;
                $this->extractReferenceNames($parsed['rows'][$ledger][$index], $assets, $drivers);
                if ($progress && ($current % 100 === 0 || $current === $total)) {
                    $progress($current, max(1, $total));
                }
            }
        }
        foreach ($parsed['errors'] ?? [] as $index => $error) {
            if (is_array($error['excel'] ?? null)) {
                $this->extractReferenceNames($parsed['errors'][$index]['excel'], $assets, $drivers);
            }
        }
    }

    private function extractReferenceNames(array &$row, $assets, $drivers): void
    {
        $description = trim((string) ($row['keterangan'] ?? ''));
        if ($description === '') {
            return;
        }

        $assetMentions = $this->exactMentions($assets, $description, 'asset');
        $driverMentions = $this->exactMentions($drivers, $description, 'driver');
        [$unitFromNote, $driverFromNote] = $this->splitReferenceDescription($description);

        if (trim((string) ($row['unit'] ?? '')) === '') {
            if (count($assetMentions) === 1) {
                $row['unit'] = $assetMentions[0]['matched_value'];
            } elseif ($unitFromNote !== '') {
                $row['unit'] = $unitFromNote;
            }
        }

        if (trim((string) ($row['operator'] ?? '')) === '') {
            if (count($driverMentions) === 1) {
                $row['operator'] = $driverMentions[0]['matched_value'];
            } elseif ($driverFromNote !== '') {
                $row['operator'] = $driverFromNote;
            }
        }
    }

    private function splitReferenceDescription(string $description): array
    {
        $parts = preg_split('/\s+(?:-|–|—|\||;)\s+/u', $description);
        if (count($parts) !== 2) {
            return ['', ''];
        }

        $unit = trim((string) ($parts[0] ?? ''));
        $operator = trim((string) ($parts[1] ?? ''));
        if (preg_match('/^(.+?)\s+\b(?:operasional|ayak|bongkar)\b.*$/iu', $unit, $matches)) {
            $unit = trim((string) $matches[1]);
        }
        $unit = preg_replace('/^(?:unit|asset|alat)\s*:\s*/iu', '', $unit) ?? $unit;
        $operator = preg_replace('/\s*\+\s*[\d.,]+.*$/u', '', $operator) ?? $operator;
        $operator = preg_replace('/\s+\b(?:WCI|BI)\b.*$/iu', '', $operator) ?? $operator;
        $unit = trim($unit, " \t\n\r\0\x0B.,;:");
        $operator = trim($operator, " \t\n\r\0\x0B.,;:");

        if ($operator !== '' && ! preg_match('/^\pL[\pL .\'-]*$/u', $operator)) {
            $operator = '';
        }

        return [$unit, $operator];
    }

    private function exactMentions($references, string $text, string $type): array
    {
        $needle = $this->comparableReference($text);
        if (strlen($needle) < 4) {
            return [];
        }

        $matches = [];
        foreach ($references as $reference) {
            $fields = $type === 'asset' ? ['name', 'asset_code'] : ['full_name'];
            foreach ($fields as $field) {
                $value = trim((string) ($reference->{$field} ?? ''));
                $alias = $this->comparableReference($value);
                if (strlen($alias) < 4 || ! $this->containsReferencePhrase($text, $value)) {
                    continue;
                }
                $matches[] = [
                    'id' => (int) $reference->id,
                    'matched_value' => $value,
                    'alias_length' => strlen($alias),
                ];
            }
        }

        usort($matches, fn ($left, $right) => $right['alias_length'] <=> $left['alias_length']);
        $byId = [];
        foreach ($matches as $match) {
            if (! isset($byId[$match['id']])) {
                $byId[$match['id']] = $match;
            }
        }

        return array_values($byId);
    }

    private function containsReferencePhrase(string $text, string $reference): bool
    {
        $textTokens = preg_split('/[^\pL\pN]+/u', mb_strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $referenceTokens = preg_split('/[^\pL\pN]+/u', mb_strtolower(trim($reference)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($referenceTokens === [] || count($referenceTokens) > count($textTokens)) {
            return false;
        }

        $lastStart = count($textTokens) - count($referenceTokens);
        for ($start = 0; $start <= $lastStart; $start++) {
            if (array_slice($textTokens, $start, count($referenceTokens)) === $referenceTokens) {
                return true;
            }
        }

        return false;
    }

    private function referenceSuggestions($references, $sourceValue, $description, string $type): array
    {
        $source = trim((string) ($sourceValue ?? ''));
        $description = trim((string) ($description ?? ''));
        if ($source === '' && $description === '') {
            return [];
        }

        $ranked = [];
        foreach ($references as $reference) {
            $fields = $type === 'asset' ? ['name', 'asset_code'] : ['full_name'];
            $score = 0.0;
            foreach ($fields as $field) {
                $candidate = trim((string) ($reference->{$field} ?? ''));
                if (strlen($this->comparableReference($candidate)) < 4) {
                    continue;
                }
                $score = max(
                    $score,
                    $this->referenceSimilarity($candidate, $source),
                    $this->tokenCoverage($candidate, $description)
                );
            }
            if ($score < 0.58) {
                continue;
            }

            $label = $type === 'asset'
                ? trim(implode(' · ', array_filter([(string) ($reference->asset_code ?? ''), (string) ($reference->name ?? '')])))
                : trim((string) ($reference->full_name ?? ''));
            $ranked[] = ['id' => (int) $reference->id, 'label' => $label, 'score' => round($score, 3)];
        }

        usort($ranked, fn ($left, $right) => $right['score'] <=> $left['score'] ?: strcasecmp($left['label'], $right['label']));

        return array_slice($ranked, 0, 3);
    }

    private function referenceSimilarity(string $candidate, string $source): float
    {
        if ($source === '') {
            return 0.0;
        }
        $left = $this->comparableReference($candidate);
        $right = $this->comparableReference($source);
        if ($left === '' || $right === '') {
            return 0.0;
        }
        if ($left === $right) {
            return 1.0;
        }

        similar_text($left, $right, $percent);
        $score = $percent / 100;
        $distance = levenshtein($left, $right);
        if (max(strlen($left), strlen($right)) >= 5 && $distance <= 2) {
            $score = max($score, 1 - ($distance / max(strlen($left), strlen($right))));
        }

        return $score;
    }

    private function tokenCoverage(string $candidate, string $text): float
    {
        if ($text === '') {
            return 0.0;
        }
        $candidateTokens = preg_split('/[^\pL\pN]+/u', mb_strtolower($candidate), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $textTokens = preg_split('/[^\pL\pN]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($candidateTokens === [] || $textTokens === []) {
            return 0.0;
        }

        $matched = 0;
        foreach ($candidateTokens as $token) {
            $found = false;
            foreach ($textTokens as $textToken) {
                if ($token === $textToken || (mb_strlen($token) >= 5 && levenshtein($token, $textToken) <= 1)) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                $matched++;
            }
        }

        return $matched / count($candidateTokens);
    }

    private function comparableReference($value): string
    {
        return preg_replace('/[^\pL\pN]+/u', '', mb_strtolower(trim((string) ($value ?? '')))) ?? '';
    }

    private function matchAsset($assets, $value)
    {
        $needle = $this->comparableReference($value);
        if ($needle === '') {
            return collect();
        }

        return $assets->filter(fn ($asset) => in_array($needle, [
            $this->comparableReference($asset->name),
            $this->comparableReference($asset->asset_code),
        ], true))->unique('id')->values();
    }

    private function matchDriver($drivers, $value)
    {
        $needle = $this->comparableReference($value);
        if ($needle === '') {
            return collect();
        }

        return $drivers->filter(fn ($driver) => $this->comparableReference($driver->full_name) === $needle)->values();
    }

    private function applyReferenceMappings(array &$parsed, array $referenceMappings): void
    {
        foreach (array_merge(SolarLedgerService::LEDGERS, ['usage']) as $ledger) {
            foreach ($parsed['rows'][$ledger] ?? [] as $index => $row) {
                $this->applyMappingsToRow($parsed['rows'][$ledger][$index], $ledger, $referenceMappings);
            }
        }
        foreach ($parsed['errors'] as $index => $error) {
            $ledger = (string) ($error['ledger'] ?? '');
            if (is_array($error['excel'] ?? null)) {
                $this->applyMappingsToRow($parsed['errors'][$index]['excel'], $ledger, $referenceMappings);
            }
        }
    }

    private function applyMappingsToRow(array &$row, string $ledger, array $referenceMappings): void
    {
        foreach (['asset', 'driver'] as $type) {
            if ($type === 'driver' && $ledger === 'usage') {
                continue;
            }
            $value = $type === 'asset' ? $this->assetSourceValue($row, $ledger) : ($row['operator'] ?? null);
            $key = $this->normalizeReference($value);
            if ($key === '' || ! array_key_exists($key, $referenceMappings[$type] ?? [])) {
                continue;
            }
            $mapping = $referenceMappings[$type][$key];
            $targetId = is_array($mapping) ? ($mapping['target_id'] ?? null) : $mapping;
            $field = $type === 'asset' ? 'asset_id' : 'driver_id';
            $row[$field] = $targetId === null || $targetId === '' ? null : (int) $targetId;
            $row[$type.'_match_status'] = $row[$field] === null ? 'unmatched' : 'matched';
            if ($row[$field] !== null) {
                $row[$type.'_suggestions'] = [];
            }
        }
    }

    private function assetSourceValue(array $row, string $ledger)
    {
        return $ledger === 'usage' ? ($row['equipment_name'] ?? null) : ($row['unit'] ?? null);
    }

    private function cleanReferenceValue($value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) ($value ?? '')) ?? '');
    }

    private function normalizeReference($value): string
    {
        return mb_strtolower($this->cleanReferenceValue($value));
    }
}
