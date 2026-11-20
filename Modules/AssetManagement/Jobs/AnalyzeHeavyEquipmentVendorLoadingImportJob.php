<?php

namespace Modules\AssetManagement\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\AssetManagement\Services\HeavyEquipmentVendorLoadingImportService;
use Modules\AssetManagement\Services\HeavyEquipmentVendorLoadingImportHistoryService;

class AnalyzeHeavyEquipmentVendorLoadingImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 1800;

    public function __construct(private int $batchId)
    {
        $this->onQueue('imports');
    }

    public function handle(
        HeavyEquipmentVendorLoadingImportService $importService,
        HeavyEquipmentVendorLoadingImportHistoryService $historyService
    ): void {
        $batch = DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $this->batchId)->first();
        if (! $batch || $batch->status !== 'queued_preview' || ! $batch->file_path) {
            return;
        }

        DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batch->id)->update([
            'status' => 'analyzing',
            'stage' => 'reading_workbook',
            'last_message' => 'Membaca workbook dan memeriksa setiap sheet.',
            'started_at' => now(),
            'last_heartbeat_at' => now(),
            'updated_at' => now(),
        ]);
        $historyService->syncFromDomainBatch((int) $batch->id);

        $disk = Storage::disk('local');
        try {
            $fullPath = $disk->path($batch->file_path);
            if (! is_file($fullPath)) {
                throw new \RuntimeException('File upload tidak ditemukan di penyimpanan privat. Silakan upload ulang.');
            }

            $file = new UploadedFile($fullPath, $batch->file_name, null, UPLOAD_ERR_OK, true);
            $preview = $importService->preview(
                $file,
                (int) $batch->business_id,
                $this->canCreateCustomer((int) $batch->created_by),
                function (array $progress) use ($batch, $historyService): void {
                    DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batch->id)->update(array_merge($progress, [
                        'stage' => 'reading_workbook',
                        'last_heartbeat_at' => now(),
                        'updated_at' => now(),
                    ]));
                    $historyService->syncFromDomainBatch((int) $batch->id);
                }
            );
            $sourceSnapshot = $preview['source_snapshot'] ?? ['sheets' => []];
            unset($preview['source_snapshot']);
            $historyService->storePayloadForDomainBatch((int) $batch->id, 'source_snapshot', $sourceSnapshot);
            $historyService->storePayloadForDomainBatch((int) $batch->id, 'preview', $preview);

            $previewPath = dirname($batch->file_path).'/'.$batch->id.'.preview.json';
            $disk->put($previewPath, json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            $summary = $preview['summary'] ?? [];
            DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batch->id)->update([
                'preview_path' => $previewPath,
                'status' => 'preview_ready',
                'stage' => 'preview_ready',
                'total_sheets' => (int) ($summary['sheets'] ?? count($preview['sheets'] ?? [])),
                'processed_sheets' => (int) ($summary['sheets'] ?? count($preview['sheets'] ?? [])),
                'total_rows' => (int) ($summary['source_rows'] ?? 0),
                'processed_rows' => (int) ($summary['source_rows'] ?? 0),
                'ready_rows' => (int) ($summary['ready_rows'] ?? 0),
                'issue_rows' => (int) ($summary['error_rows'] ?? 0),
                'missing_date_rows' => (int) ($summary['date_missing_rows'] ?? 0),
                'last_message' => 'Analisis selesai. Periksa tiap sheet sebelum konfirmasi.',
                'errors_json' => json_encode($preview['errors'] ?? [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'last_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
            $historyService->syncFromDomainBatch((int) $batch->id);
        } catch (\Throwable $e) {
            report($e);
            $this->markFailed($batch->id, $e->getMessage());
        } finally {
            $this->clearStagingFile((int) $batch->id);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->markFailed($this->batchId, $exception->getMessage());
        $this->clearStagingFile($this->batchId);
    }

    private function canCreateCustomer(int $userId): bool
    {
        $user = \App\User::find($userId);
        return $user && ($user->can('superadmin') || $user->can('customer.create'));
    }

    private function markFailed(int $id, string $message): void
    {
        DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $id)->whereIn('status', ['queued_preview', 'analyzing'])->update([
            'status' => 'failed',
            'stage' => 'failed',
            'last_message' => 'Analisis gagal.',
            'error_message' => $message,
            'finished_at' => now(),
            'last_heartbeat_at' => now(),
            'updated_at' => now(),
        ]);
        app(HeavyEquipmentVendorLoadingImportHistoryService::class)->syncFromDomainBatch($id);
    }

    private function clearStagingFile(int $id): void
    {
        $batch = DB::table('heavy_equipment_vendor_loading_import_batches')->find($id);
        if (! $batch) {
            return;
        }

        try {
            if (! empty($batch->file_path)) {
                $disk = Storage::disk('local');
                if ($disk->exists($batch->file_path) && ! $disk->delete($batch->file_path)) {
                    throw new \RuntimeException('File staging Vendor Loading tidak dapat dihapus.');
                }
            }

            DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $id)->update([
                'file_path' => null,
                'updated_at' => now(),
            ]);
            if ($batch->user_import_batch_id) {
                DB::table('user_import_batches')->where('id', $batch->user_import_batch_id)->update([
                    'staging_path' => null,
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
