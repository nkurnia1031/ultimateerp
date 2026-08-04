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

        if (! $this->processService->start($batchId, $businessId, 'reading', 'Menyiapkan data import.', 5)) {
            throw new RuntimeException('Import sudah berjalan atau dibatalkan.');
        }

        $rows = $this->previewService->importableRows($batchId, $businessId);
        $total = count($rows);
        $path = $batch->file_path;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('File preview sudah tidak tersedia.');
        }

        $inserted = DB::transaction(function () use ($batch, $businessId, $batchId, $rows, $total) {
            $this->processService->update($batchId, $businessId, 'saving', 'Menyimpan transaksi baru.', 35, 0, $total);
            $inserted = ['stock' => 0, 'pool' => 0, 'mess' => 0];
            $snapshot = $this->ledgerService->openingSnapshot($businessId);
            DB::table('solar_import_batches')->where('id', $batchId)->update([
                'opening_snapshot' => json_encode($snapshot),
                'updated_at' => now(),
            ]);

            $overrides = json_decode((string) ($batch->date_overrides ?? '[]'), true) ?: [];
            $parsed = $this->workbookService->parse(Storage::disk('local')->path($batch->file_path), $overrides);
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
                        $inserted[$ledger] += DB::table($this->ledgerService->table($ledger))->insertOrIgnore($payloadChunk);
                    }
                }
                $current = min($total, ($chunkIndex + 1) * 250);
                $percent = $total > 0 ? 35 + (int) floor(($current / $total) * 50) : 85;
                $this->processService->update($batchId, $businessId, 'saving', 'Menyimpan transaksi baru.', $percent, $current, $total);
            }

            $this->processService->update($batchId, $businessId, 'recalculating', 'Menghitung ulang saldo ledger.', 90, $total, $total);
            foreach (SolarLedgerService::LEDGERS as $ledger) {
                $this->ledgerService->recalculate($businessId, $ledger);
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
        if ($ledger === 'stock') {
            return [
                'tanggal' => $row['tanggal'],
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
