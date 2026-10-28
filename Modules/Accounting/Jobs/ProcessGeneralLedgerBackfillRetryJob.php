<?php

namespace Modules\Accounting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\GeneralLedgerBackfillService;
use Throwable;

class ProcessGeneralLedgerBackfillRetryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CHUNK_SIZE = 25;

    private const CHUNK_DELAY_SECONDS = 2;

    public $timeout = 180;

    public $tries = 1;

    private $batchId;

    public function __construct(int $batchId)
    {
        $this->batchId = $batchId;
        $this->onQueue('imports');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('general-ledger-backfill-retry-'.$this->batchId))
                ->dontRelease()
                ->expireAfter($this->timeout + 30),
        ];
    }

    public function handle(GeneralLedgerBackfillService $backfillService): void
    {
        $batch = DB::table('accounting_general_ledger_backfill_retry_batches')
            ->where('id', $this->batchId)
            ->first();
        if (! $batch || ! in_array($batch->status, ['queued', 'running'], true)) {
            return;
        }

        DB::table('accounting_general_ledger_backfill_retry_batches')->where('id', $this->batchId)->update([
            'status' => 'running',
            'started_at' => $batch->started_at ?: now(),
            'last_message' => 'Memproses sumber review per chunk, maksimal '.self::CHUNK_SIZE.' item.',
            'updated_at' => now(),
        ]);

        $items = DB::table('accounting_general_ledger_backfill_retry_items')
            ->where('batch_id', $this->batchId)
            ->whereIn('status', ['queued', 'processing'])
            ->orderBy('id')
            ->limit(self::CHUNK_SIZE)
            ->get();

        foreach ($items as $item) {
            $claimed = DB::transaction(function () use ($item) {
                $locked = DB::table('accounting_general_ledger_backfill_retry_items')
                    ->where('id', $item->id)
                    ->where('batch_id', $this->batchId)
                    ->whereIn('status', ['queued', 'processing'])
                    ->lockForUpdate()
                    ->first();
                if (! $locked) {
                    return false;
                }
                DB::table('accounting_general_ledger_backfill_retry_items')->where('id', $item->id)->update([
                    'status' => 'processing',
                    'updated_at' => now(),
                ]);

                return true;
            });
            if (! $claimed) {
                continue;
            }

            try {
                $result = $backfillService->retryReviewItem(
                    (int) $batch->business_id,
                    (int) $item->review_id,
                    (string) ($item->plan_hash ?? ''),
                    (int) ($batch->confirmed_by ?? 0)
                );
                $resolved = (bool) ($result['resolved'] ?? false);
                $message = trim((string) ($result['reason'] ?? ''));
                if (! $resolved) {
                    DB::table('accounting_general_ledger_backfill_reviews')
                        ->where('business_id', $batch->business_id)
                        ->where('id', $item->review_id)
                        ->where('status', 'retrying')
                        ->update(['status' => 'pending', 'updated_at' => now()]);
                }

                $this->finishItem((int) $item->id, $resolved ? 'resolved' : 'failed', $message);
            } catch (Throwable $exception) {
                DB::table('accounting_general_ledger_backfill_reviews')
                    ->where('business_id', $batch->business_id)
                    ->where('id', $item->review_id)
                    ->where('status', 'retrying')
                    ->update(['status' => 'pending', 'updated_at' => now()]);
                $this->finishItem((int) $item->id, 'failed', mb_substr($exception->getMessage(), 0, 1000));
            }
        }

        $remaining = DB::table('accounting_general_ledger_backfill_retry_items')
            ->where('batch_id', $this->batchId)
            ->whereIn('status', ['queued', 'processing'])
            ->exists();
        if ($remaining) {
            self::dispatch($this->batchId)->onQueue('imports')->delay(now()->addSeconds(self::CHUNK_DELAY_SECONDS));

            return;
        }

        $batch = DB::table('accounting_general_ledger_backfill_retry_batches')->where('id', $this->batchId)->first();
        $failed = (int) ($batch->failed_items ?? 0);
        DB::table('accounting_general_ledger_backfill_retry_batches')->where('id', $this->batchId)->update([
            'status' => $failed > 0 ? 'completed_with_errors' : 'completed',
            'last_message' => $failed > 0
                ? $failed.' item masih menunggu mapping atau perlu jurnal manual.'
                : 'Semua sumber dalam batch berhasil ditautkan ke jurnal.',
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function finishItem(int $itemId, string $result, string $message): void
    {
        DB::transaction(function () use ($itemId, $result, $message) {
            $item = DB::table('accounting_general_ledger_backfill_retry_items')
                ->where('id', $itemId)
                ->where('batch_id', $this->batchId)
                ->lockForUpdate()
                ->first();
            if (! $item || ! in_array($item->status, ['queued', 'processing'], true)) {
                return;
            }

            DB::table('accounting_general_ledger_backfill_retry_items')->where('id', $itemId)->update([
                'status' => $result,
                'message' => $message,
                'updated_at' => now(),
            ]);
            DB::table('accounting_general_ledger_backfill_retry_batches')->where('id', $this->batchId)->increment('processed_items');
            DB::table('accounting_general_ledger_backfill_retry_batches')->where('id', $this->batchId)->increment(
                $result === 'resolved' ? 'resolved_items' : 'failed_items'
            );
            DB::table('accounting_general_ledger_backfill_retry_batches')->where('id', $this->batchId)->update([
                'last_message' => 'Selesai '.((int) DB::table('accounting_general_ledger_backfill_retry_batches')->where('id', $this->batchId)->value('processed_items')).' dari '.((int) DB::table('accounting_general_ledger_backfill_retry_batches')->where('id', $this->batchId)->value('total_items')).' item.',
                'updated_at' => now(),
            ]);
        });
    }
}
