<?php

namespace Modules\Crm\Jobs;

use App\UserImportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Crm\Services\SuratKontrakImportBatchService;

class ProcessSuratKontrakImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(private int $batchId, private int $businessId)
    {
        $this->onQueue('imports');
    }

    public function handle(SuratKontrakImportBatchService $service): void
    {
        $batch = UserImportBatch::where('business_id', $this->businessId)
            ->where('importer_key', SuratKontrakImportBatchService::IMPORTER_KEY)
            ->where('id', $this->batchId)
            ->where('status', 'queued_preview')
            ->first();
        if (! $batch) {
            return;
        }

        $claimed = UserImportBatch::where('business_id', $this->businessId)
            ->where('importer_key', SuratKontrakImportBatchService::IMPORTER_KEY)
            ->where('id', $this->batchId)
            ->where('status', 'queued_preview')
            ->update(['status' => 'analyzing', 'stage' => 'analyzing', 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }

        try {
            $service->process($batch->fresh());
        } catch (\Throwable $exception) {
            $service->fail($batch->fresh(), $exception);
            report($exception);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $batch = UserImportBatch::where('business_id', $this->businessId)
            ->where('importer_key', SuratKontrakImportBatchService::IMPORTER_KEY)
            ->where('id', $this->batchId)
            ->whereIn('status', ['queued_preview', 'analyzing', 'importing'])
            ->first();
        if ($batch) {
            app(SuratKontrakImportBatchService::class)->fail($batch, $exception);
        }
    }
}
