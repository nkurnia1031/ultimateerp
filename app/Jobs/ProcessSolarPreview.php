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

        $path = $batch->file_path;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            throw new \RuntimeException('File preview sudah tidak tersedia.');
        }
        if (! $processService->start($this->batchId, $this->businessId, 'reading', 'Membaca workbook Excel.', 10)) {
            return;
        }

        $overrides = json_decode((string) ($batch->date_overrides ?? '[]'), true) ?: [];
        $result = $previewService->rebuild(
            $this->businessId,
            $this->batchId,
            Storage::disk('local')->path($path),
            $overrides,
            fn (string $stage, string $message, int $percent) => $processService->update(
                $this->batchId,
                $this->businessId,
                $stage,
                $message,
                $percent
            )
        );
        $total = count($result['items'] ?? []);
        $processService->update($this->batchId, $this->businessId, 'saving', 'Menyimpan hasil preview.', 90, $total, $total);
        DB::table('solar_import_batches')->where('id', $this->batchId)->update([
            'counts' => json_encode($previewService->summary($this->batchId, $this->businessId)),
            'updated_at' => now(),
        ]);
        $processService->complete($this->batchId, $this->businessId, 'Preview selesai.');
    }

    public function failed(Throwable $exception): void
    {
        app(SolarImportProcessService::class)->fail($this->batchId, $this->businessId, $exception->getMessage());
    }
}
