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
        $result = $this->ledgerService->reconciliation($businessId, $parsed);
        $progress && $progress('saving', 'Menyimpan hasil preview.', 90);
        $this->replaceRows($businessId, $batchId, $result);

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

        return [
            'match' => (int) ($counts['match'] ?? 0),
            'only_excel' => (int) DB::table('solar_import_preview_rows')
                ->where('batch_id', $batchId)
                ->where('business_id', $businessId)
                ->where('is_importable', true)
                ->count(),
            'only_app' => (int) ($counts['only_app'] ?? 0),
            'different' => (int) ($counts['different'] ?? 0),
            'error' => (int) ($counts['error'] ?? 0),
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
            'stock' => [], 'pool' => [], 'mess' => [],
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
                    && (($item['ledger'] ?? '') === 'stock'
                        ? array_key_exists('stock_in', $excel)
                        : array_key_exists('qty_in', $excel));
                $payloads[] = [
                    'batch_id' => $batchId,
                    'business_id' => $businessId,
                    'ledger' => $item['ledger'] ?? '-',
                    'source_row' => $item['row'] ?? null,
                    'raw_date' => is_scalar($rawDate) ? (string) $rawDate : null,
                    'normalized_date' => $item['date'] ?: null,
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

        return $this->ledgerService->decorateSourceKeys($parsed);
    }
}
