<?php

namespace App\Jobs;

use App\Services\ExpenseImportProcessService;
use App\Services\ExpenseImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessExpensePreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(public string $batchId, public int $businessId)
    {
        $this->onQueue('imports');
    }

    public function handle(ExpenseImportService $importService, ExpenseImportProcessService $processService): void
    {
        $batch = DB::table('expense_import_batches')->where('id', $this->batchId)->where('business_id', $this->businessId)
            ->where('operation', 'preview')->where('process_state', 'queued')->first();
        if (! $batch) {
            return;
        }
        if (! $processService->start($this->batchId, $this->businessId, 'reading', 'Membaca workbook Excel.', 5)) {
            return;
        }
        if (! $batch->file_path || ! Storage::disk('local')->exists($batch->file_path)) {
            throw new \RuntimeException('File import Expense sudah tidak tersedia.');
        }
        $overrides = json_decode((string) ($batch->date_overrides ?? '[]'), true) ?: [];
        $summary = $importService->preview(
            $this->batchId,
            $this->businessId,
            Storage::disk('local')->path($batch->file_path),
            $overrides,
            fn (string $stage, string $message, int $percent, int $current = 0, int $total = 0) => $processService->update($this->batchId, $this->businessId, $stage, $message, $percent, $current, $total)
        );
        $processService->complete($this->batchId, $this->businessId, sprintf('Preview selesai: %d valid, %d perlu diperbaiki.', $summary['valid'], $summary['errors']));
    }

    public function failed(Throwable $exception): void
    {
        app(ExpenseImportProcessService::class)->fail($this->batchId, $this->businessId, $exception->getMessage());
    }
}
