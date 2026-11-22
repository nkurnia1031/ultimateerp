<?php

namespace App\Jobs;

use App\Services\PurchaseProductLineImportBatchService;
use App\Services\UserImportBatchService;
use App\User;
use App\UserImportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ProcessPurchaseProductLineImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(private int $batchId, private int $businessId)
    {
        $this->onQueue('imports');
    }

    public function handle(PurchaseProductLineImportBatchService $service, UserImportBatchService $batches): void
    {
        $query = UserImportBatch::where('business_id', $this->businessId)
            ->where('importer_key', PurchaseProductLineImportBatchService::IMPORTER_KEY)
            ->where('id', $this->batchId)->where('status', 'queued_preview');
        $batch = $query->first();
        if (! $batch || ! $query->update([
            'status' => 'analyzing', 'stage' => 'analyzing', 'progress_percent' => 1,
            'last_message' => 'Workbook baris Purchase sedang dianalisis.', 'started_at' => now(), 'heartbeat_at' => now(), 'updated_at' => now(),
        ])) {
            return;
        }

        $temporaryPath = null;
        try {
            $batch = $batch->fresh();
            $user = User::where('business_id', $this->businessId)->findOrFail((int) $batch->created_by);
            Auth::setUser($user);
            if (filled($batch->staging_path) && Storage::disk('user_imports')->exists($batch->staging_path)) {
                $workbookPath = Storage::disk('user_imports')->path($batch->staging_path);
            } else {
                abort_unless($batch->hasSourceSnapshot(), 422, 'Workbook sumber Purchase tidak tersedia.');
                $temporaryPath = $batches->restoredWorkbookPath($batch);
                $workbookPath = $temporaryPath;
            }
            if (! $batch->hasSourceSnapshot()) {
                $batches->captureWorkbook($batch, $workbookPath);
            }
            $service->process($batch->fresh(), $workbookPath);
        } catch (\Throwable $exception) {
            $current = UserImportBatch::where('business_id', $this->businessId)
                ->where('importer_key', PurchaseProductLineImportBatchService::IMPORTER_KEY)->find($this->batchId);
            if ($current && in_array($current->status, ['queued_preview', 'analyzing'], true)) {
                $service->fail($current, $exception);
            }
            report($exception);
        } finally {
            if ($batch && filled($batch->staging_path) && $batch->fresh()->hasSourceSnapshot()) {
                Storage::disk('user_imports')->delete($batch->staging_path);
                UserImportBatch::whereKey($batch->id)->update(['staging_path' => null]);
            }
            if ($temporaryPath && is_file($temporaryPath)) {
                @unlink($temporaryPath);
                @rmdir(dirname($temporaryPath));
            }
            Auth::logout();
        }
    }

    public function failed(\Throwable $exception): void
    {
        $batch = UserImportBatch::where('business_id', $this->businessId)
            ->where('importer_key', PurchaseProductLineImportBatchService::IMPORTER_KEY)
            ->where('id', $this->batchId)->whereIn('status', ['queued_preview', 'analyzing'])->first();
        if ($batch) {
            app(PurchaseProductLineImportBatchService::class)->fail($batch, $exception);
        }
    }
}
