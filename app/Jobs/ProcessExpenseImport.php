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
use Throwable;

class ProcessExpenseImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(public string $batchId, public int $businessId, public int $userId)
    {
        $this->onQueue('imports');
    }

    public function handle(ExpenseImportService $importService, ExpenseImportProcessService $processService): void
    {
        $batch = DB::table('expense_import_batches')->where('id', $this->batchId)->where('business_id', $this->businessId)
            ->where('operation', 'import')->where('process_state', 'queued')->first();
        if (! $batch || ! $processService->start($this->batchId, $this->businessId, 'saving', 'Menyiapkan import transaksi.', 5)) {
            return;
        }
        $result = $importService->import(
            $this->batchId,
            $this->businessId,
            $this->userId,
            fn (string $stage, string $message, int $percent, int $current = 0, int $total = 0) => $processService->update($this->batchId, $this->businessId, $stage, $message, $percent, $current, $total)
        );
        $processService->complete($this->batchId, $this->businessId, sprintf('Import selesai: %d transaksi.', $result['transactions']));
    }

    public function failed(Throwable $exception): void
    {
        app(ExpenseImportProcessService::class)->fail($this->batchId, $this->businessId, $exception->getMessage());
    }
}
