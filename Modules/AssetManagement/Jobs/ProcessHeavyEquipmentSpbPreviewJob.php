<?php

namespace Modules\AssetManagement\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\AssetManagement\Services\HeavyEquipmentSpbImportBatchService;

class ProcessHeavyEquipmentSpbPreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 1800;

    public function __construct(private int $batchId)
    {
        $this->onQueue('imports');
    }

    public function handle(HeavyEquipmentSpbImportBatchService $service): void
    {
        $service->processPreview($this->batchId);
    }

    public function failed(\Throwable $exception): void
    {
        app(HeavyEquipmentSpbImportBatchService::class)->markFailed($this->batchId, $exception->getMessage());
    }
}
