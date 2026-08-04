<?php

namespace App\Jobs;

use App\Services\SolarImportProcessService;
use App\Services\SolarImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class ProcessSolarImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(public string $batchId, public int $businessId)
    {
        $this->onQueue('solar');
    }

    public function handle(SolarImportService $importService, SolarImportProcessService $processService): void
    {
        if ($processService->isCancelled($this->batchId, $this->businessId)) {
            return;
        }

        try {
            $inserted = $importService->run($this->batchId, $this->businessId);
            $processService->complete(
                $this->batchId,
                $this->businessId,
                sprintf('Import selesai: %d Stock, %d Pool, %d Mess.', $inserted['stock'], $inserted['pool'], $inserted['mess'])
            );
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'solar_import_cancelled') {
                return;
            }
            if ($processService->isCancelled($this->batchId, $this->businessId)) {
                return;
            }
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        app(SolarImportProcessService::class)->fail($this->batchId, $this->businessId, $exception->getMessage());
    }
}
