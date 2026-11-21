<?php

namespace App\Jobs;

use App\Services\BonusAyakImportHistoryService;
use App\Services\UserImportBatchService;
use App\User;
use App\UserImportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProcessBonusAyakImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(private int $batchId, private int $businessId)
    {
        $this->onQueue('imports');
    }

    public function handle(BonusAyakImportHistoryService $history, UserImportBatchService $batches): void
    {
        $batch = UserImportBatch::where('business_id', $this->businessId)
            ->where('importer_key', 'bonus_ayak')
            ->where('id', $this->batchId)
            ->where('status', 'queued_import')
            ->first();
        if (! $batch) {
            return;
        }

        $claimed = UserImportBatch::where('business_id', $this->businessId)
            ->where('importer_key', 'bonus_ayak')
            ->where('id', $this->batchId)
            ->where('status', 'queued_import')
            ->update([
                'status' => 'importing',
                'stage' => 'saving',
                'progress_current' => 0,
                'progress_percent' => 1,
                'last_message' => 'Baris Bonus Ayak sedang disimpan.',
                'started_at' => now(),
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
        if (! $claimed) {
            return;
        }

        try {
            $batch = $batch->fresh();
            $user = User::where('business_id', $this->businessId)->findOrFail((int) $batch->created_by);
            Auth::setUser($user);

            DB::transaction(function () use ($batch, $history, $batches) {
                $locked = UserImportBatch::where('business_id', $this->businessId)
                    ->where('importer_key', 'bonus_ayak')
                    ->lockForUpdate()->findOrFail($this->batchId);
                if ($locked->status !== 'importing') {
                    return;
                }

                $results = $history->importSelected($locked);
                $batches->storePayload($locked, 'results', $results);
                $history->completeImport($locked, count($results['row_outcomes'] ?? []));
            });
        } catch (\Throwable $exception) {
            $current = UserImportBatch::where('business_id', $this->businessId)
                ->where('importer_key', 'bonus_ayak')->find($this->batchId);
            if ($current && in_array($current->status, ['queued_import', 'importing'], true)) {
                $history->fail($current, $exception->getMessage());
            }
            report($exception);
        } finally {
            Auth::logout();
        }
    }

    public function failed(\Throwable $exception): void
    {
        $batch = UserImportBatch::where('business_id', $this->businessId)
            ->where('importer_key', 'bonus_ayak')
            ->where('id', $this->batchId)
            ->whereIn('status', ['queued_import', 'importing'])
            ->first();
        if ($batch) {
            app(BonusAyakImportHistoryService::class)->fail($batch, $exception->getMessage());
        }
    }
}
