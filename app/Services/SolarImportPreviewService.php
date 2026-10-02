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

    public function rebuild(int $businessId, string $batchId, string $path, array $dateOverrides = [], ?callable $progress = null): array
    {
        $progress && $progress('reading', 'Membaca workbook Excel.', 15);
        $parsed = $this->prepareParsedRows(
            $businessId,
            $this->workbookService->parse($path, $dateOverrides)
        );
        $progress && $progress('matching', 'Mencocokkan transaksi dengan aplikasi.', 55);
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
        $progress && $progress('saving', 'Menyimpan hasil preview.', 90);
        $this->replaceRows($businessId, $batchId, $result);
        DB::table('solar_import_batches')->where('id', $batchId)->where('business_id', $businessId)->update([
            'source_sheets' => json_encode($result['sheets'], JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        return $result;
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

    public function replaceRows(int $businessId, string $batchId, array $result): void
    {
        DB::transaction(function () use ($businessId, $batchId, $result) {
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
            foreach (array_chunk($payloads, 500) as $chunk) {
                DB::table('solar_import_preview_rows')->insert($chunk);
            }
        });
    }

    private function prepareParsedRows(int $businessId, array $parsed): array
    {
        $customers = DB::table('contacts')
            ->where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->select('id', 'name', 'supplier_business_name')
            ->get();
        $byId = $customers->keyBy('id');
        $byName = [];
        foreach ($customers as $customer) {
            foreach ([$customer->name, $customer->supplier_business_name] as $name) {
                if ($name) {
                    $byName[mb_strtolower(trim($name))] = (int) $customer->id;
                }
            }
        }

        foreach (['pool', 'mess'] as $ledger) {
            foreach ($parsed['rows'][$ledger] as $index => $row) {
                $reference = trim((string) ($row['customer_ref'] ?? ''));
                $customerId = null;
                if ($reference !== '') {
                    if (preg_match('/^C(\d+)\b/i', $reference, $matches) && $byId->has((int) $matches[1])) {
                        $customerId = (int) $matches[1];
                    } elseif (isset($byName[mb_strtolower($reference)])) {
                        $customerId = $byName[mb_strtolower($reference)];
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

        $assets = DB::table('assets')
            ->where('business_id', $businessId)
            ->select('id', 'name', 'asset_code')
            ->get();
        foreach ($parsed['rows']['usage'] ?? [] as $index => $row) {
            $needle = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) ($row['equipment_name'] ?? ''))));
            $matches = $assets->filter(function ($asset) use ($needle) {
                return in_array($needle, array_map(
                    fn ($value) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $value))),
                    [$asset->name, $asset->asset_code]
                ), true);
            })->values();

            $parsed['rows']['usage'][$index]['asset_id'] = $matches->count() === 1 ? (int) $matches->first()->id : null;
            $parsed['rows']['usage'][$index]['asset_match_status'] = $matches->count() === 1
                ? 'matched'
                : ($matches->count() > 1 ? 'ambiguous' : 'unmatched');
        }

        return $this->ledgerService->decorateSourceKeys($parsed);
    }
}
