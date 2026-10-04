<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SolarImportService
{
    public function __construct(
        private SolarLedgerService $ledgerService,
        private SolarImportPreviewService $previewService,
        private SolarImportProcessService $processService,
        private SolarWorkbookService $workbookService
    ) {
    }

    public function run(string $batchId, int $businessId): array
    {
        $batch = DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->where('status', 'preview')
            ->where('operation', 'import')
            ->where('process_state', 'queued')
            ->lockForUpdate()
            ->first();
        if (! $batch) {
            throw new RuntimeException('Batch preview tidak siap atau sudah diproses.');
        }

        if (! $this->processService->start($batchId, $businessId, 'file_check', 'Memeriksa workbook dan batch import.', 3)) {
            throw new RuntimeException('Import sudah berjalan atau dibatalkan.');
        }

        $path = $batch->file_path;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('File preview sudah tidak tersedia.');
        }
        $rows = $this->previewService->importableRows($batchId, $businessId);
        $total = count($rows);
        $this->processService->update($batchId, $businessId, 'reading', 'Memuat baris yang sudah disetujui dari preview.', 8, 0, $total, ['stage_detail' => 'load_preview_rows']);

        $inserted = DB::transaction(function () use ($batch, $businessId, $batchId, $rows, $total, $path) {
            $this->processService->update($batchId, $businessId, 'saving', 'Menyimpan transaksi baru ke ledger.', 32, 0, $total, ['stage_detail' => 'save_start']);
            $inserted = ['stock' => 0, 'pool' => 0, 'mess' => 0, 'usage' => 0];
            $snapshot = $this->ledgerService->openingSnapshot($businessId);
            DB::table('solar_import_batches')->where('id', $batchId)->update([
                'opening_snapshot' => json_encode($snapshot),
                'updated_at' => now(),
            ]);

            $overrides = json_decode((string) ($batch->date_overrides ?? '[]'), true) ?: [];
            $parsed = $this->workbookService->parse(Storage::disk('local')->path($path), $overrides, function ($stage, $message, $percent, $current = null, $rowTotal = null, $context = []) use ($batchId, $businessId): void {
                $this->processService->update($batchId, $businessId, $stage, 'Membaca workbook untuk saldo awal: '.$message, min(30, max(12, $percent)), $current, $rowTotal, $context + ['stage_detail' => 'opening_balance_read']);
            });
            foreach (SolarLedgerService::LEDGERS as $ledger) {
                $this->guardCancellation($batchId, $businessId);
                $table = $this->ledgerService->table($ledger);
                $opening = $parsed['opening'][$ledger] ?? null;
                if (DB::table($table)->where('business_id', $businessId)->doesntExist() && $opening) {
                    $this->ledgerService->setOpeningBalance(
                        $businessId,
                        $ledger,
                        $opening,
                        $opening['date'],
                        $batchId
                    );
                }
            }

            foreach (array_chunk($rows, 250) as $chunkIndex => $chunk) {
                $this->guardCancellation($batchId, $businessId);
                foreach (collect($chunk)->groupBy(fn ($row) => $row['ledger'] ?? 'stock') as $ledger => $ledgerRows) {
                    $payloads = $ledgerRows->map(function ($row) use ($businessId, $batchId, $ledger) {
                        return $this->payload($ledger, $row) + [
                            'business_id' => $businessId,
                            'source' => 'excel',
                            'source_sheet' => $row['source_sheet'] ?? null,
                            'source_row' => $row['source_row'] ?? null,
                            'source_hash' => $this->ledgerService->rowHash($ledger, $row),
                            'source_key' => $row['source_key'] ?? null,
                            'import_batch_id' => $batchId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    })->all();
                    foreach (array_chunk($payloads, 100) as $payloadChunk) {
                        $table = $ledger === 'usage' ? 'solar_equipment_usages' : $this->ledgerService->table($ledger);
                        $inserted[$ledger] += DB::table($table)->insertOrIgnore($payloadChunk);
                    }
                }
                $current = min($total, ($chunkIndex + 1) * 250);
                $percent = $total > 0 ? 35 + (int) floor(($current / $total) * 50) : 85;
                $this->processService->update($batchId, $businessId, 'saving', 'Menyimpan transaksi baru: '.$current.' dari '.$total.' baris.', $percent, $current, $total, ['stage_detail' => 'save_rows', 'chunk' => $chunkIndex + 1, 'ledger_counts' => $inserted]);
            }

            $ledgerIndex = 0;
            foreach (SolarLedgerService::LEDGERS as $ledger) {
                $ledgerIndex++;
                $this->processService->update($batchId, $businessId, 'recalculating', 'Menghitung ulang saldo ledger '.$ledger.'.', 88 + (int) floor((($ledgerIndex - 1) / count(SolarLedgerService::LEDGERS)) * 10), $ledgerIndex - 1, count(SolarLedgerService::LEDGERS), ['ledger' => $ledger, 'stage_detail' => 'recalculate_start']);
                $this->ledgerService->recalculate($businessId, $ledger);
                $this->processService->update($batchId, $businessId, 'recalculating', 'Saldo ledger '.$ledger.' selesai dihitung ulang.', 88 + (int) floor(($ledgerIndex / count(SolarLedgerService::LEDGERS)) * 10), $ledgerIndex, count(SolarLedgerService::LEDGERS), ['ledger' => $ledger, 'stage_detail' => 'recalculate_complete']);
            }

            DB::table('solar_import_batches')->where('id', $batchId)->update([
                'status' => 'imported',
                'operation' => 'import',
                'counts' => json_encode($inserted),
                'confirmed_at' => now(),
                'updated_at' => now(),
            ]);

            return $inserted;
        });

        Storage::disk('local')->delete($path);
        return $inserted;
    }

    private function guardCancellation(string $batchId, int $businessId): void
    {
        if ($this->processService->isCancelled($batchId, $businessId)) {
            throw new RuntimeException('solar_import_cancelled');
        }
    }

    private function payload(string $ledger, array $row): array
    {
        if ($ledger === 'usage') {
            return [
                'usage_date' => $row['tanggal'],
                'asset_id' => $row['asset_id'] ?? null,
                'equipment_name' => $row['equipment_name'],
                'quantity_gln' => (float) $row['quantity_gln'],
            ];
        }
        if ($ledger === 'stock') {
            return [
                'tanggal' => $row['tanggal'],
                'asset_id' => $row['asset_id'] ?? null,
                'driver_id' => $row['driver_id'] ?? null,
                'unit' => $row['unit'] ?? null,
                'operator' => $row['operator'] ?? null,
                'stock_in' => $row['stock_in'],
                'out_actual' => $row['out_actual'],
                'stock_actual' => 0,
                'out_meteran' => $row['out_meteran'],
                'stock_meteran' => 0,
                'keterangan' => $row['keterangan'] ?? null,
            ];
        }

        $payload = [
            'tanggal' => $row['tanggal'],
            'asset_id' => $row['asset_id'] ?? null,
            'driver_id' => $row['driver_id'] ?? null,
            'qty_in' => $row['qty_in'],
            'qty_out' => $row['qty_out'],
            'current_stock' => 0,
            'keterangan' => $row['keterangan'] ?? null,
            'unit' => $row['unit'] ?? null,
            'operator' => $row['operator'] ?? null,
            'customer_id' => $row['customer_id'] ?? null,
        ];
        if ($ledger === 'mess') {
            $payload['nama_kapal'] = $row['nama_kapal'] ?? null;
            $payload['lokasi'] = $row['lokasi'] ?? null;
        }

        return $payload;
    }
}
