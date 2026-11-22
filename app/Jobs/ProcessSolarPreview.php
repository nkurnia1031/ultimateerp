<?php

namespace App\Jobs;

use App\Services\SolarImportPreviewService;
use App\Services\SolarImportProcessService;
use App\Services\SolarImportHistoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSolarPreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(public string $batchId, public int $businessId)
    {
        $this->onQueue('solar');
    }

    public function handle(SolarImportPreviewService $previewService, SolarImportProcessService $processService): void
    {
        $batch = DB::table('solar_import_batches')
            ->where('id', $this->batchId)
            ->where('business_id', $this->businessId)
            ->where('operation', 'preview')
            ->where('process_state', 'queued')
            ->first();
        if (! $batch || $processService->isCancelled($this->batchId, $this->businessId)) {
            return;
        }

        if (! $processService->start($this->batchId, $this->businessId, 'file_check', 'Memeriksa file upload dan akses penyimpanan.', 5)) {
            return;
        }
        $historyService = app(SolarImportHistoryService::class);
        [$workbookPath, $temporary] = $historyService->sourceWorkbookPath($this->batchId, $this->businessId);
        try {
            if (! $temporary) {
                $historyService->captureSourceSnapshot($this->batchId, $this->businessId, $workbookPath);
                $fileBytes = is_file($workbookPath) ? filesize($workbookPath) : 0;
            } else {
                $fileBytes = is_file($workbookPath) ? filesize($workbookPath) : 0;
            }
            $processService->update($this->batchId, $this->businessId, 'file_check', 'Workbook tersedia. Menyiapkan pembacaan.', 8, null, null, ['file_bytes' => $fileBytes]);

            $overrides = json_decode((string) ($batch->date_overrides ?? '[]'), true) ?: [];
            $result = $previewService->rebuild(
                $this->businessId,
                $this->batchId,
                $workbookPath,
                $overrides,
                function (string $stage, string $message, int $percent, ?int $current = null, ?int $total = null, array $context = []) use ($processService): void {
                    $processService->update(
                        $this->batchId,
                        $this->businessId,
                        $stage,
                        $message,
                        $percent,
                        $current,
                        $total,
                        $context
                    );
                }
            );
            $total = count($result['items'] ?? []);
            $processService->update($this->batchId, $this->businessId, 'saving', 'Menyimpan ringkasan preview dan peringatan sheet.', 96, $total, $total, ['stage_detail' => 'preview_summary', 'rows' => $total]);
            DB::table('solar_import_batches')->where('id', $this->batchId)->where('business_id', $this->businessId)->update([
                'counts' => json_encode($previewService->summary($this->batchId, $this->businessId)),
                'updated_at' => now(),
            ]);
            $stagingRemoved = $historyService->deleteStaging($this->batchId, $this->businessId, $batch->file_path);
            $processService->complete(
                $this->batchId,
                $this->businessId,
                $stagingRemoved
                    ? 'Preview selesai.'
                    : 'Preview selesai, tetapi file sementara belum berhasil dibersihkan. Workbook pemulihan tersedia di riwayat.'
            );
        } finally {
            $historyService->cleanupTemporaryWorkbook($workbookPath, $temporary);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Solar preview job failed.', [
            'batch_id' => $this->batchId,
            'business_id' => $this->businessId,
            'exception' => $exception,
        ]);

        $historyService = app(SolarImportHistoryService::class);
        $sourceAvailable = false;
        $stagingRemoved = false;
        try {
            [$workbookPath, $temporary] = $historyService->sourceWorkbookPath($this->batchId, $this->businessId);
            try {
                if (! $temporary) {
                    $historyService->captureSourceSnapshot($this->batchId, $this->businessId, $workbookPath);
                }
                $sourceAvailable = $historyService->hasSourceSnapshot($this->batchId, $this->businessId);
            } finally {
                $historyService->cleanupTemporaryWorkbook($workbookPath, $temporary);
            }
        } catch (Throwable $snapshotException) {
            Log::warning('Solar preview source could not be retained as a logical workbook snapshot.', [
                'batch_id' => $this->batchId,
                'business_id' => $this->businessId,
                'exception' => $snapshotException,
            ]);
        } finally {
            $stagingRemoved = $historyService->deleteStaging($this->batchId, $this->businessId);
        }

        app(SolarImportProcessService::class)->fail(
            $this->batchId,
            $this->businessId,
            $sourceAvailable && $stagingRemoved
                ? 'Preview gagal karena kesalahan sistem. Workbook sumber tersimpan di riwayat; coba proses ulang. Jika masih gagal, hubungi administrator dengan ID batch.'
                : ($sourceAvailable
                    ? 'Preview gagal. Workbook pemulihan tersimpan, tetapi file sementara belum dapat dibersihkan. Hubungi administrator dengan ID batch.'
                    : ($stagingRemoved
                        ? 'Preview gagal dan workbook sumber tidak dapat dipulihkan. File sementara telah dibersihkan; unggah ulang workbook untuk memulai batch baru.'
                        : 'Preview gagal; workbook sumber tidak dapat dipulihkan dan pembersihan file sementara belum terverifikasi. Unggah ulang workbook dan hubungi administrator dengan ID batch.'))
        );
    }
}
