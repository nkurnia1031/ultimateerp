<?php

namespace Modules\AssetManagement\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\AssetManagement\Services\HeavyEquipmentRateImportHistoryService;

class AnalyzeHeavyEquipmentRateImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 1800;

    public function __construct(private int $batchId)
    {
        $this->onQueue('imports');
    }

    public function handle(HeavyEquipmentRateImportHistoryService $historyService): void
    {
        $historyService->processPreview($this->batchId);
    }

    public function failed(\Throwable $exception): void
    {
        app(HeavyEquipmentRateImportHistoryService::class)->markPreviewFailed($this->batchId, $exception->getMessage());
    }
}
