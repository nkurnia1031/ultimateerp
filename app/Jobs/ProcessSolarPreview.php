<?php

namespace App\Jobs;

use App\Services\SolarImportPreviewService;
use App\Services\SolarImportProcessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
        $path = $batch->file_path;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            throw new \RuntimeException('File preview sudah tidak tersedia.');
        }
        $processService->update($this->batchId, $this->businessId, 'file_check', 'File tersedia. Menyiapkan pembacaan workbook.', 8, null, null, ['file_bytes' => Storage::disk('local')->size($path)]);

        $overrides = json_decode((string) ($batch->date_overrides ?? '[]'), true) ?: [];
        $result = $previewService->rebuild(
            $this->businessId,
            $this->batchId,
            Storage::disk('local')->path($path),
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
        DB::table('solar_import_batches')->where('id', $this->batchId)->update([
            'counts' => json_encode($previewService->summary($this->batchId, $this->businessId)),
            'updated_at' => now(),
        ]);
        $processService->complete($this->batchId, $this->businessId, 'Preview selesai.');
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Solar preview job failed.', [
            'batch_id' => $this->batchId,
            'business_id' => $this->businessId,
            'exception' => $exception,
        ]);

        app(SolarImportProcessService::class)->fail(
            $this->batchId,
            $this->businessId,
            'Preview gagal karena kesalahan sistem. Coba proses ulang. Jika masih gagal, hubungi administrator dengan ID batch.'
        );
    }
}
